<?php

namespace XD\RAGAssistant\Extensions;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Security\SecurityToken;
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

    /**
     * CSRF token embedded in the widget and sent back on each request. Empty (and no session started)
     * when require_security_token is disabled.
     */
    public function AssistantSecurityID(): string
    {
        if (!Config::inst()->get(AssistantController::class, 'require_security_token')) {
            return '';
        }
        return (string) SecurityToken::inst()->getValue();
    }
}
