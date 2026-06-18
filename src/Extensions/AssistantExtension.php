<?php

namespace XD\RAGAssistant\Extensions;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use XD\RAGAssistant\Controllers\AssistantController;

class AssistantExtension extends Extension
{
    public function AssistantOffline(): bool
    {
        return file_exists(BASE_PATH . '/silverstripe-cache/rag_offline.flag');
    }

    public function AssistantMaxLength(): int
    {
        return (int) Config::inst()->get(AssistantController::class, 'max_question_length');
    }
}
