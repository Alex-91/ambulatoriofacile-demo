<?php

namespace App\Models;

use CodeIgniter\Model;

class FseDocumentEventModel extends Model
{
    protected $table = 'fse_document_events';
    protected $primaryKey = 'id_fse_event';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $updatedField = '';
    protected $allowedFields = [
        'id_fse_document', 'event_type', 'event_level', 'message', 'context_json', 'created_by', 'created_at',
    ];

    /** @return array<int, array<string, mixed>> */
    public function listForDocument(int $documentId): array
    {
        return $documentId > 0
            ? $this->where('id_fse_document', $documentId)->orderBy('created_at', 'ASC')->findAll()
            : [];
    }
}
