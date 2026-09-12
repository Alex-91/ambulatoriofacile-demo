<?php

namespace App\Models;

use CodeIgniter\Model;

class TsDocumentModel extends Model
{
    protected $table = 'ts_documents';
    protected $primaryKey = 'id_ts_document';
    protected $returnType = 'array';
    protected $allowedFields = [
        'id_ts_profile',
        'id_client',
        'source_type',
        'source_ref_id',
        'document_identifier_hash',
        'sender_piva_snapshot',
        'sender_cf_snapshot_enc',
        'sender_type_snapshot',
        'patient_cf_enc',
        'patient_cf_hash',
        'patient_label_snapshot_enc',
        'document_number',
        'document_device',
        'issue_date',
        'payment_date',
        'document_type',
        'expense_type_code',
        'payment_mode',
        'amount_total',
        'vat_rate',
        'vat_nature',
        'opposition_flag',
        'notes',
        'local_state',
        'ts_state',
        'validation_json',
        'request_payload_json',
        'response_payload_json',
        'ts_protocol',
        'ts_sent_at',
        'last_error_code',
        'last_error_message',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /** Atomic optimistic update. A stale form must never overwrite an in-flight submission. */
    public function updateEditableSnapshot(int $id, array $expected, array $changes): bool
    {
        if (($changes['local_state'] ?? '') === 'sending' && in_array($expected['source_type'] ?? '', ['ts_variation','ts_cancellation'], true)) {
            $this->db->transBegin();
            try {
                $lock = $this->db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : '';
                $parentId = (int) ($expected['source_ref_id'] ?? 0);
                $parent = $this->db->query('SELECT * FROM ts_documents WHERE id_ts_document=?'.$lock,[$parentId])->getRowArray();
                if (!$parent || ($parent['ts_state'] ?? '') === 'cancelled' || ($parent['local_state'] ?? '') !== 'sent') {
                    throw new \RuntimeException('Il documento TS di origine non consente questa operazione.');
                }
                $pending = $this->db->query("SELECT id_ts_document FROM ts_documents WHERE source_ref_id=? AND source_type IN ('ts_variation','ts_cancellation') AND local_state='sending' AND id_ts_document<>?".$lock,[$parentId,$id])->getRowArray();
                if ($pending) throw new \RuntimeException('Un’altra operazione TS collegata è in corso o in attesa di verifica.');
                $claimed = $this->compareAndUpdate($id,$expected,$changes);
                if (!$this->db->transStatus() || !$this->db->transCommit()) throw new \RuntimeException('Operazione TS non acquisita.');
                return $claimed;
            } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
        }
        return $this->compareAndUpdate($id,$expected,$changes);
    }

    private function compareAndUpdate(int $id, array $expected, array $changes): bool
    {
        if ($id <= 0 || !in_array($expected['local_state'] ?? '', ['draft', 'to_validate', 'ready', 'rejected'], true)) {
            return false;
        }
        $builder = $this->db->table($this->table)->where($this->primaryKey, $id);
        foreach (array_intersect_key($expected, array_flip($this->allowedFields)) as $field => $value) {
            $builder->where($field, $value);
        }
        $changes = array_intersect_key($changes, array_flip($this->allowedFields));
        $changes['updated_at'] = date('Y-m-d H:i:s');
        if (!$builder->update($changes)) throw new \RuntimeException('Aggiornamento documento TS non riuscito.');
        return $this->db->affectedRows() === 1;
    }

    public function persistAccepted(int $id, array $changes, array $source, int $userId): bool
    {
        $child = in_array($source['source_type'] ?? '', ['ts_variation','ts_cancellation'], true);
        $this->db->transBegin();
        try {
            if ($child) {
                $parentId=(int)($source['source_ref_id'] ?? 0);
                $lock=$this->db->DBDriver==='MySQLi' ? ' FOR UPDATE' : '';
                $parent=$this->db->query('SELECT * FROM ts_documents WHERE id_ts_document=?'.$lock,[$parentId])->getRowArray();
                if (!$parent || !$this->update($parentId,['ts_state'=>$source['source_type']==='ts_cancellation' ? 'cancelled' : 'varied','updated_by'=>$userId ?: null])) throw new \RuntimeException('Esito TS del documento di origine non salvato.');
            }
            if (!$this->update($id,$changes) || !$this->db->transStatus() || !$this->db->transCommit()) throw new \RuntimeException('Esito TS non salvato.');
            return true;
        } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
    }

    public function findByIdentifierHash(string $identifierHash): ?array
    {
        $identifierHash = trim(strtolower($identifierHash));
        if ($identifierHash === '') {
            return null;
        }

        return $this->where('LOWER(document_identifier_hash)', $identifierHash)->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByLocalState(string $localState, int $limit = 100): array
    {
        $localState = trim(strtolower($localState));
        if ($localState === '') {
            return [];
        }

        return $this->where('LOWER(local_state)', $localState)
            ->orderBy('issue_date', 'DESC')
            ->orderBy('id_ts_document', 'DESC')
            ->findAll(max(1, $limit));
    }
}
