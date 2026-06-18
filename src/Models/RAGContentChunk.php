<?php

namespace XD\RAGAssistant\Models;

use SilverStripe\ORM\DataObject;

class RAGContentChunk extends DataObject
{
    private static $table_name = 'RAGContentChunk';

    private static $db = [
        'SourceURL'     => 'Varchar(500)',
        'Title'         => 'Varchar(500)',
        'ChunkText'     => 'Text',
        'Embedding'     => 'Text',
        'PageClassName' => 'Varchar(255)',
        'PageID'        => 'Int',
    ];

    private static $indexes = [
        'PageID' => ['type' => 'index', 'columns' => ['PageID']],
    ];

    public function getEmbeddingArray(): array
    {
        if (!$this->Embedding) {
            return [];
        }
        return array_map('floatval', explode(',', $this->Embedding));
    }
}
