<?php

namespace App\Models;

use CodeIgniter\Model;

class TsDocumentEventModel extends Model
{
    protected $table = 'ts_document_events';
    protected $primaryKey = 'id_ts_event';
    protected $returnType = 'array';
    protected $allowedFields = [
        'id_ts_document',
        'event_type',
        'event_level',
        'event_code',
        'message',
        'context_json',
        'created_by',
        'created_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = '';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForDocument(int $documentId): array
    {
        if ($documentId <= 0) {
            return [];
        }

        return $this->where('id_ts_document', $documentId)
            ->orderBy('created_at', 'ASC')
            ->orderBy('id_ts_event', 'ASC')
            ->findAll();
    }
}
