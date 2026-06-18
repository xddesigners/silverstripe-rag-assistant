<?php

namespace XD\RAGAssistant\Controllers;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\i18n\i18n;
use XD\RAGAssistant\Models\RAGContentChunk;

class AssistantController extends Controller
{
    private static $url_segment = 'api/assistant';

    private static $allowed_actions = ['ask'];

    private static $url_handlers = [
        'ask' => 'ask',
    ];

    private static $openai_api_key = '';

    private static $chat_model = 'gpt-4o-mini';

    private static $embedding_model = 'text-embedding-3-small';

    private static $embedding_dimensions = 512;

    private static $top_k = 5;

    /** Max requests per IP within the rate-limit window (0 = disabled). */
    private static $rate_limit_max = 10;

    /** Rate-limit window in seconds. */
    private static $rate_limit_window = 60;

    /** Max allowed question length in characters. Also enforced via maxlength on the frontend input. */
    private static $max_question_length = 300;

    /** Max number of previous conversation turns (question+answer pairs) to include as context. */
    private static $max_history_turns = 10;

    /** Email address for quota-exhaustion alerts. Falls back to Email.admin_email from YAML. */
    private static $admin_email = '';

    /**
     * Page classes whose chunks are excluded from query results, regardless of what is in the index.
     * Subclasses of listed classes are also excluded. Override via project YAML.
     */
    private static $excluded_page_classes = [];

    /** Enable textual request logging to a dedicated log file. */
    private static $enable_logging = false;

    /** Log filename, written to BASE_PATH. Override via YAML. */
    private static $log_file = 'silverstripe-rag-assistant.log';

    /**
     * System prompt sent to the chat model.
     * Override per project via YAML to customise tone and context.
     */
    private static $system_prompt = <<<'PROMPT'
You are a referral assistant for a website. Your only task is to direct visitors to the correct page.
Rules:
- Do NOT answer questions with your own knowledge. Do not invent facts, rules, amounts or dates.
- Use only the provided page excerpts as your source.
- Keep your answer to 2-3 sentences.
- Always include the full URL of the relevant page(s).
- If the question cannot be answered from the provided pages, say so honestly.
- Use the conversation history to understand follow-up questions and pronouns (e.g. "it", "that", "this"). Do not repeat information already given.
PROMPT;

    public function ask(HTTPRequest $request): HTTPResponse
    {
        $response = $this->getResponse();
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');

        if ($request->httpMethod() === 'OPTIONS') {
            return $response->setStatusCode(204);
        }

        if ($request->httpMethod() !== 'POST') {
            return $response->setStatusCode(405)->setBody(json_encode(['error' => 'Method not allowed']));
        }

        $ipHash = substr(md5($request->getIP()), 0, 8);

        if (!$this->checkRateLimit($request)) {
            $this->ragLog(sprintf('Rate limit hit — ip:%s', $ipHash), 'warning');
            return $response->setStatusCode(429)->setBody(json_encode(['error' => 'Too many requests, please wait a moment.']));
        }

        $body     = json_decode((string) $request->getBody(), true);
        $question = trim((string) ($body['question'] ?? $body['vraag'] ?? ''));
        $history  = $this->sanitizeHistory($body['history'] ?? []);

        if (strlen($question) < 5) {
            return $response->setStatusCode(400)->setBody(json_encode(['error' => 'Question is too short']));
        }

        $maxLen = (int) $this->config()->get('max_question_length');
        if ($maxLen > 0 && strlen($question) > $maxLen) {
            return $response->setStatusCode(400)->setBody(json_encode(['error' => 'Question is too long']));
        }

        $this->ragLog(sprintf(
            'Question — ip:%s history:%d "%s"',
            $ipHash,
            intdiv(count($history), 2),
            mb_substr($question, 0, 100)
        ));

        $apiKey = $this->resolveApiKey();
        if (!$apiKey) {
            return $response->setStatusCode(500)->setBody(json_encode(['error' => 'API key not configured']));
        }

        $questionEmbedding = $this->getEmbedding($question, $apiKey);
        if (!$questionEmbedding) {
            return $response->setStatusCode(502)->setBody(json_encode(['error' => 'Embedding failed, please try again']));
        }

        $chunks = $this->loadChunks();
        $top    = $this->findTopK($questionEmbedding, $chunks, (int) $this->config()->get('top_k'));

        if (!$top) {
            return $response->setBody(json_encode([
                'answer'  => 'No pages have been indexed yet. Please run the RAGIndexTask first.',
                'sources' => [],
            ]));
        }

        $answer  = $this->generateAnswer($question, $top, $apiKey, $history);
        $sources = $this->uniqueSources($top);

        $this->ragLog(sprintf(
            'Answer — sources:%d "%s"',
            count($sources),
            mb_substr($answer, 0, 120)
        ));

        return $response->setBody(json_encode([
            'answer'  => $answer,
            'sources' => $sources,
        ]));
    }

    private function loadChunks(): array
    {
        $cachePath = BASE_PATH . '/silverstripe-cache/rag_chunks.bin';
        if (file_exists($cachePath)) {
            $data = file_get_contents($cachePath);
            if ($data !== false) {
                return unserialize($data) ?: [];
            }
        }

        // Fallback: load directly from DB (slower for large datasets)
        $chunks = [];
        foreach (RAGContentChunk::get() as $chunk) {
            $chunks[] = [
                'title'      => $chunk->Title,
                'url'        => $chunk->SourceURL,
                'text'       => $chunk->ChunkText,
                'embedding'  => $chunk->getEmbeddingArray(),
                'page_class' => $chunk->PageClassName,
            ];
        }
        return $chunks;
    }

    private function findTopK(array $query, array $chunks, int $k): array
    {
        $excluded = (array) $this->config()->get('excluded_page_classes');
        $scored   = [];

        foreach ($chunks as $chunk) {
            if (empty($chunk['embedding'])) {
                continue;
            }

            if (!empty($excluded) && !empty($chunk['page_class'])) {
                $skip = false;
                foreach ($excluded as $class) {
                    if ($chunk['page_class'] === $class || is_a($chunk['page_class'], $class, true)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) continue;
            }

            $scored[] = [
                'score' => $this->cosineSimilarity($query, $chunk['embedding']),
                'chunk' => $chunk,
            ];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $k);
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $dot  = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        $n    = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++) {
            $dot  += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }

        $mag = sqrt($magA) * sqrt($magB);
        return $mag > 0.0 ? $dot / $mag : 0.0;
    }

    private function getEmbedding(string $text, string $apiKey): ?array
    {
        $result = $this->openaiRequest('https://api.openai.com/v1/embeddings', [
            'model'      => $this->config()->get('embedding_model'),
            'input'      => mb_substr($text, 0, 8000),
            'dimensions' => (int) $this->config()->get('embedding_dimensions'),
        ], $apiKey, 'Embedding');

        return $result['data'][0]['embedding'] ?? null;
    }

    private function generateAnswer(string $question, array $top, string $apiKey, array $history = []): string
    {
        $context = '';
        foreach ($top as $item) {
            $context .= "Page: {$item['chunk']['title']}\nURL: {$item['chunk']['url']}\nContent: {$item['chunk']['text']}\n\n";
        }

        $system = trim((string) $this->config()->get('system_prompt'));
        $system .= "\n\nAlways respond in the following language: " . $this->resolveLanguageName();

        $messages = [['role' => 'system', 'content' => $system]];

        // Previous turns give the model context for follow-up questions
        foreach ($history as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }

        $messages[] = ['role' => 'user', 'content' => "Available pages:\n{$context}\nVisitor question: {$question}"];

        $result = $this->openaiRequest('https://api.openai.com/v1/chat/completions', [
            'model'       => $this->config()->get('chat_model'),
            'messages'    => $messages,
            'max_tokens'  => 350,
            'temperature' => 0.2,
        ], $apiKey, 'Chat');

        return $result['choices'][0]['message']['content'] ?? 'Something went wrong, please try again.';
    }

    private function sanitizeHistory(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $maxTurns = (int) $this->config()->get('max_history_turns');
        $messages = [];

        foreach ($raw as $msg) {
            if (!is_array($msg)) continue;
            $role    = $msg['role']    ?? '';
            $content = $msg['content'] ?? '';
            if (!in_array($role, ['user', 'assistant'], true)) continue;
            if (!is_string($content) || trim($content) === '') continue;
            // Truncate individual messages to prevent prompt injection via long history
            $messages[] = ['role' => $role, 'content' => mb_substr(trim($content), 0, 600)];
        }

        // Keep only the most recent turns
        return array_slice($messages, -($maxTurns * 2));
    }

    private function uniqueSources(array $top): array
    {
        $seen    = [];
        $sources = [];
        foreach ($top as $item) {
            $url = $item['chunk']['url'];
            if (!isset($seen[$url])) {
                $seen[$url] = true;
                $sources[]  = ['title' => $item['chunk']['title'], 'url' => $url];
            }
        }
        return $sources;
    }

    private function openaiRequest(string $url, array $data, string $apiKey, string $label = 'OpenAI'): array
    {
        $model = $data['model'] ?? '?';
        $this->ragLog(sprintf('%s request — model:%s', $label, $model));

        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$apiKey}",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ms = round((microtime(true) - $t0) * 1000);

        if ($status !== 200 || !$result) {
            $errorCode = '';
            if ($result && ($status === 429 || $status === 402)) {
                $body      = json_decode($result, true) ?? [];
                $errorCode = $body['error']['code'] ?? $body['error']['type'] ?? '';
                if (str_contains((string) $errorCode, 'quota') || str_contains((string) $errorCode, 'billing')) {
                    $this->handleQuotaExhausted();
                }
            }
            $this->ragLog(sprintf(
                '%s error — HTTP:%d code:%s %dms',
                $label, $status, $errorCode ?: '?', $ms
            ), 'error');
            return [];
        }

        // Successful response: clear offline flag if it was set
        $flagFile = BASE_PATH . '/silverstripe-cache/rag_offline.flag';
        if (file_exists($flagFile)) {
            @unlink($flagFile);
        }

        $this->ragLog(sprintf('%s response — HTTP:%d %dms', $label, $status, $ms));

        return json_decode($result, true) ?? [];
    }

    private function handleQuotaExhausted(): void
    {
        $flagFile = BASE_PATH . '/silverstripe-cache/rag_offline.flag';

        if (file_exists($flagFile)) {
            return; // already notified
        }

        @file_put_contents($flagFile, date('Y-m-d H:i:s'));
        $this->ragLog('Quota exhausted — widget set to offline, sending alert email', 'critical');
        $this->sendQuotaEmail();
    }

    private function sendQuotaEmail(): void
    {
        $to = (string) $this->config()->get('admin_email');
        if (!$to) {
            $to = (string) Email::config()->get('admin_email');
        }
        if (!$to) {
            return;
        }

        $siteUrl  = Director::absoluteBaseURL();
        $flagPath = BASE_PATH . '/silverstripe-cache/rag_offline.flag';
        $time     = date('d-m-Y H:i');

        $html = "
<p>De AI-assistent op <a href=\"{$siteUrl}\">{$siteUrl}</a> is offline gegaan omdat het OpenAI-budget is opgebruikt.</p>
<p><strong>Tijdstip:</strong> {$time}</p>
<h3>Wat te doen</h3>
<ol>
    <li>Vul het tegoed aan in het <a href=\"https://platform.openai.com/settings/organization/billing\">OpenAI-dashboard</a>.</li>
    <li>Verwijder daarna het vlagbestand op de server:<br><code>{$flagPath}</code></li>
    <li>De widget gaat automatisch weer online zodra het vlagbestand is verwijderd.</li>
</ol>
        ";

        Email::create()
            ->setTo($to)
            ->setSubject('[AI-assistent] Tokens op – widget offline')
            ->setHTMLBody($html)
            ->send();
    }

    private function resolveLocale(): string
    {
        // Fluent support — check without hard dependency
        if (class_exists('TractorCow\Fluent\State\FluentState')) {
            $locale = \TractorCow\Fluent\State\FluentState::singleton()->getLocale();
            if ($locale) {
                return $locale;
            }
        }

        return i18n::get_locale();
    }

    private function resolveLanguageName(): string
    {
        $locale = $this->resolveLocale();

        // Use PHP intl extension when available for a proper language name
        if (function_exists('locale_get_display_language')) {
            $name = locale_get_display_language($locale, 'en');
            if ($name && $name !== $locale) {
                return $name;
            }
        }

        // Fallback: map common locales manually
        $map = [
            'nl' => 'Dutch',   'nl_NL' => 'Dutch',   'nl_BE' => 'Dutch',
            'en' => 'English', 'en_US' => 'English',  'en_GB' => 'English',
            'de' => 'German',  'de_DE' => 'German',   'de_AT' => 'German',
            'fr' => 'French',  'fr_FR' => 'French',   'fr_BE' => 'French',
            'es' => 'Spanish', 'es_ES' => 'Spanish',
            'it' => 'Italian', 'it_IT' => 'Italian',
            'pt' => 'Portuguese', 'pt_PT' => 'Portuguese', 'pt_BR' => 'Portuguese',
        ];

        return $map[$locale] ?? $map[substr($locale, 0, 2)] ?? $locale;
    }

    private function ragLog(string $message, string $level = 'info'): void
    {
        if (!$this->config()->get('enable_logging')) {
            return;
        }
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
        @file_put_contents(BASE_PATH . '/' . $this->config()->get('log_file'), $line, FILE_APPEND | LOCK_EX);
    }

    private function checkRateLimit(HTTPRequest $request): bool
    {
        $max    = (int) $this->config()->get('rate_limit_max');
        $window = (int) $this->config()->get('rate_limit_window');

        if ($max <= 0) {
            return true;
        }

        $ip   = $request->getIP();
        $file = BASE_PATH . '/silverstripe-cache/rag_rl_' . md5($ip) . '.json';
        $now  = time();

        $timestamps = [];
        if (file_exists($file)) {
            $data = @file_get_contents($file);
            if ($data !== false) {
                $timestamps = json_decode($data, true) ?? [];
            }
        }

        $timestamps = array_values(array_filter($timestamps, fn($t) => ($now - $t) < $window));

        if (count($timestamps) >= $max) {
            return false;
        }

        $timestamps[] = $now;
        @file_put_contents($file, json_encode($timestamps), LOCK_EX);

        return true;
    }

    private function resolveApiKey(): string
    {
        return Environment::getEnv('OPENAI_API_KEY')
            ?: Environment::getEnv('AI_API_KEY')
            ?: (string) $this->config()->get('openai_api_key');
    }
}
