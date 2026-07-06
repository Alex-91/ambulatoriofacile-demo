<?php

namespace App\Models;

use CodeIgniter\Model;

class TsDocumentReceiptModel extends Model
{
    protected $table = 'ts_document_receipts';
    protected $primaryKey = 'id_ts_receipt';
    protected $returnType = 'array';
    protected $allowedFields = [
        'id_ts_document',
        'receipt_type',
        'ts_protocol',
        'storage_path',
        'mime_type',
        'file_size',
        'checksum_sha256',
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
            ->orderBy('created_at', 'DESC')
            ->orderBy('id_ts_receipt', 'DESC')
            ->findAll();
    }

    public function findLatestForDocumentAndType(int $documentId, string $receiptType, string $protocol = ''): ?array
    {
        if ($documentId <= 0 || trim($receiptType) === '') {
            return null;
        }

        $builder = $this->where('id_ts_document', $documentId)
            ->where('receipt_type', trim($receiptType));

        $protocol = trim($protocol);
        if ($protocol !== '') {
            $builder = $builder->where('ts_protocol', $protocol);
        }

        return $builder
            ->orderBy('created_at', 'DESC')
            ->orderBy('id_ts_receipt', 'DESC')
            ->first();
    }
}
