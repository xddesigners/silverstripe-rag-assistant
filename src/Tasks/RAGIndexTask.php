<?php

namespace XD\RAGAssistant\Tasks;

use Page;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use XD\RAGAssistant\Models\RAGContentChunk;

/**
 * Indexes filtered SilverStripe pages as embeddings for the AI referral assistant.
 *
 * Configure which classes to index via project YAML (see README for full options).
 * Classes listed in indexed_classes that are not installed are silently skipped —
 * the module has no hard dependency on Blog, Events, or any other optional module.
 *
 *   XD\RAGAssistant\Tasks\RAGIndexTask:
 *     excluded_page_classes:
 *       - App\Pages\ProfilePage
 *     indexed_classes:
 *       - class: Page
 *         exclude_classes:
 *           - SilverStripe\CMS\Model\RedirectorPage
 */
class RAGIndexTask extends BuildTask
{
    private static $segment = 'RAGIndexTask';

    private static $url_segment = 'RAGIndexTask';

    protected $title = 'RAG: Index pages for AI assistant';

    protected $description = 'Generates embeddings for filtered SilverStripe pages and stores them for the AI referral assistant.';

    private static $embedding_model = 'text-embedding-3-small';

    private static $embedding_dimensions = 512;

    private static $chunk_size = 800;

    /**
     * Classes and filters to index. Override completely via YAML.
     * Each entry supports:
     *   - class (required)
     *   - exclude_classes: skip these subclasses
     *   - date_field + date_offset: filter by date (e.g. PublishDate, '-12 months')
     *   - upcoming_via + upcoming_via_relation + upcoming_date_field: filter via a related date table
     *   - extra_fields: additional DB fields to include besides Title, MetaDescription, Content
     */
    private static $indexed_classes = [];

    /**
     * Page classes to exclude globally from indexing, regardless of which indexed_classes entry they
     * appear in. Subclasses of listed classes are also excluded. Override via project YAML.
     */
    private static $excluded_page_classes = [];

    public function run($request)
    {
        // Indexing loads many large embedding arrays — raise the limit for this CLI task
        ini_set('memory_limit', '512M');

        $apiKey = $this->resolveApiKey();
        if (!$apiKey) {
            $this->log('ERROR: No OpenAI API key configured. Set OPENAI_API_KEY in .env.');
            return;
        }

        $this->log('Starting RAG indexing...');

        DB::query('TRUNCATE TABLE RAGContentChunk');
        $this->log('Existing chunks cleared.');

        $pages = $this->getConfiguredPages();

        if (empty($pages)) {
            $this->log('No pages found. Check your indexed_classes configuration.');
            return;
        }

        $this->log(sprintf('%d pages found.', count($pages)));

        // Build the cache inline during indexing to avoid a separate DB reload at the end
        $cacheChunks = [];
        $totalChunks = 0;

        foreach ($pages as $page) {
            $text = $this->extractText($page);
            if (strlen($text) < 50) {
                continue;
            }

            $chunks    = $this->splitIntoChunks($text);
            $url       = Director::absoluteURL($page->Link());
            $title     = $page->Title;
            $pageClass = get_class($page);
            $count     = 0;

            foreach ($chunks as $chunk) {
                $embedding = $this->getEmbedding($chunk, $apiKey);
                if (!$embedding) {
                    $this->log("  ! Embedding failed for: {$title}");
                    continue;
                }

                $record = RAGContentChunk::create([
                    'SourceURL'     => $url,
                    'Title'         => $title,
                    'ChunkText'     => $chunk,
                    'Embedding'     => implode(',', $embedding),
                    'PageClassName' => $pageClass,
                    'PageID'        => $page->ID,
                ]);
                $record->write();

                $cacheChunks[] = [
                    'title'      => $title,
                    'url'        => $url,
                    'text'       => $chunk,
                    'embedding'  => $embedding,
                    'page_class' => $pageClass,
                ];

                $count++;
                $totalChunks++;

                usleep(60000); // 60 ms pause to stay within provider rate limits
            }

            $this->log("  OK [{$count} chunks] {$title}");
        }

        $this->writeCache($cacheChunks);
        $this->log(sprintf('Done. %d chunks indexed and cached.', $totalChunks));
    }

    private function getConfiguredPages(): array
    {
        $pages  = [];
        $config = $this->config()->get('indexed_classes');

        if (empty($config)) {
            $this->log('WARNING: No indexed_classes configured in YAML.');
            return [];
        }

        foreach ($config as $entry) {
            $class = $entry['class'] ?? null;
            if (!$class || !class_exists($class)) {
                $this->log("  ! Class not found: {$class}");
                continue;
            }

            $query = Versioned::get_by_stage($class, Versioned::LIVE);

            // Per-entry exclusions
            $excludeClasses = $entry['exclude_classes'] ?? [];

            // Global exclusions merged in
            $globalExcluded = (array) $this->config()->get('excluded_page_classes');
            $excludeClasses = array_unique(array_merge($excludeClasses, $globalExcluded));

            if (!empty($excludeClasses)) {
                $query = $query->exclude('ClassName', $excludeClasses);
            }

            if (!empty($entry['date_field']) && !empty($entry['date_offset'])) {
                $cutoff = date('Y-m-d H:i:s', strtotime($entry['date_offset']));
                $query  = $query->filter($entry['date_field'] . ':GreaterThanOrEqual', $cutoff);
            }

            if (!empty($entry['upcoming_via'])) {
                $viaClass     = $entry['upcoming_via'];
                $viaRelation  = $entry['upcoming_via_relation'] ?? 'ParentID';
                $viaDateField = $entry['upcoming_date_field'] ?? 'StartDate';

                if (class_exists($viaClass)) {
                    $upcomingIds = $viaClass::get()
                        ->filter($viaDateField . ':GreaterThanOrEqual', date('Y-m-d'))
                        ->column($viaRelation);

                    if (empty($upcomingIds)) {
                        continue;
                    }
                    $query = $query->filter('ID', $upcomingIds);
                }
            }

            // Limit applied last so it acts on the fully filtered result set
            if (!empty($entry['limit'])) {
                $query = $query->limit((int) $entry['limit']);
            }

            foreach ($query as $page) {
                if (!empty($entry['extra_fields'])) {
                    $page->_ragExtraFields = $entry['extra_fields'];
                }
                $pages[] = $page;
            }
        }

        return $pages;
    }

    private function extractText($page): string
    {
        $parts = [];

        if ($page->Title) {
            $parts[] = $page->Title;
        }

        if ($page->MetaDescription) {
            $parts[] = $page->MetaDescription;
        }

        $content = strip_tags((string) ($page->Content ?? ''));
        if ($content) {
            $parts[] = $content;
        }

        $extraFields = $page->_ragExtraFields ?? [];
        foreach ($extraFields as $field) {
            if (!empty($page->$field)) {
                $parts[] = strip_tags((string) $page->$field);
            }
        }

        // Elemental blocks — optional, works if dnadesign/silverstripe-elemental is installed.
        // Suppress E_USER_WARNING: template rendering during CLI may call $Link on TaskRunner,
        // which has no url_segment. The content is still extracted correctly.
        $elementalExtension = 'DNADesign\\Elemental\\Extensions\\ElementalPageExtension';
        if (class_exists($elementalExtension) && $page->hasExtension($elementalExtension)) {
            set_error_handler(static fn() => true, E_USER_WARNING);
            $elementContent = $page->getElementsForSearch();
            restore_error_handler();
            if ($elementContent) {
                $parts[] = $elementContent;
            }
        }

        return $this->normalizeText(implode(' ', $parts));
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function splitIntoChunks(string $text): array
    {
        $size    = (int) $this->config()->get('chunk_size');
        $chunks  = [];
        $current = '';

        $sentences = preg_split('/(?<=[.!?»])\s+/u', $text);
        foreach ($sentences as $sentence) {
            if ($current !== '' && strlen($current) + strlen($sentence) + 1 > $size) {
                $chunks[] = trim($current);
                $current  = '';
            }
            $current .= ($current !== '' ? ' ' : '') . $sentence;
        }

        if ($current !== '') {
            $chunks[] = trim($current);
        }

        return array_values(array_filter($chunks, fn($c) => strlen($c) >= 30));
    }

    private function getEmbedding(string $text, string $apiKey): ?array
    {
        $ch = curl_init('https://api.openai.com/v1/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'      => $this->config()->get('embedding_model'),
                'input'      => mb_substr($text, 0, 8000),
                'dimensions' => (int) $this->config()->get('embedding_dimensions'),
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$apiKey}",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            $this->log('  ! OpenAI HTTP ' . $status . ': ' . substr($result ?? '', 0, 200));
            return null;
        }

        $data = json_decode($result, true);
        return $data['data'][0]['embedding'] ?? null;
    }

    private function writeCache(array $chunks): void
    {
        $cachePath = BASE_PATH . '/silverstripe-cache/rag_chunks.bin';
        file_put_contents($cachePath, serialize($chunks));
        $this->log(sprintf('  Cache saved: %d chunks → %s', count($chunks), $cachePath));
    }

    private function resolveApiKey(): string
    {
        return Environment::getEnv('OPENAI_API_KEY')
            ?: Environment::getEnv('AI_API_KEY')
            ?: (string) $this->config()->get('openai_api_key');
    }

    private function log(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
