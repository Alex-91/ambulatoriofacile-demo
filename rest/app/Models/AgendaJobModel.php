<?php

namespace App\Models;

use CodeIgniter\Model;

class AgendaJobModel extends Model
{
    public const TYPE_RIGENERA_SLOT_CONFIG = 'RIGENERA_SLOT_CONFIG';

    public const STATUS_QUEUED = 'QUEUED';
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';

    protected $table = 'dap25_agenda_job';
    protected $primaryKey = 'id_job';
    protected $returnType = 'array';
    protected $allowedFields = [
        'token',
        'job_type',
        'status',
        'requested_by',
        'id_dot',
        'id_config',
        'payload_json',
        'progress_percent',
        'progress_message',
        'backup_file_name',
        'backup_file_path',
        'backup_file_format',
        'inserted_slots',
        'result_json',
        'error_message',
        'notify_push_sent',
        'started_at',
        'finished_at',
        'heartbeat_at',
        'created_at',
        'updated_at',
    ];

    public function createRigeneraSlotConfigJob(int $userId, int $idDot, int $idConfig, array $payload = []): array
    {
        $token = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');

        $this->insert([
            'token'            => $token,
            'job_type'         => self::TYPE_RIGENERA_SLOT_CONFIG,
            'status'           => self::STATUS_QUEUED,
            'requested_by'     => $userId,
            'id_dot'           => $idDot,
            'id_config'        => $idConfig > 0 ? $idConfig : null,
            'payload_json'     => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            'progress_percent' => 0,
            'progress_message' => 'Job in coda.',
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return $this->find((int)$this->getInsertID()) ?? [];
    }

    public function findActiveRigeneraJobByDoctor(int $idDot): ?array
    {
        return $this->where('job_type', self::TYPE_RIGENERA_SLOT_CONFIG)
            ->where('id_dot', $idDot)
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])
            ->orderBy('id_job', 'DESC')
            ->first();
    }

    public function claimQueuedJob(int $idJob, string $token): ?array
    {
        $job = $this->where('id_job', $idJob)
            ->where('token', $token)
            ->first();

        if (!$job) {
            return null;
        }

        if (($job['status'] ?? '') !== self::STATUS_QUEUED) {
            $job['_claim_granted'] = false;
            return $job;
        }

        $now = date('Y-m-d H:i:s');
        $this->builder()
            ->where('id_job', $idJob)
            ->where('token', $token)
            ->where('status', self::STATUS_QUEUED)
            ->update([
                'status'           => self::STATUS_RUNNING,
                'progress_percent' => 1,
                'progress_message' => 'Elaborazione avviata.',
                'started_at'       => $now,
                'heartbeat_at'     => $now,
                'updated_at'       => $now,
            ]);

        $fresh = $this->find($idJob);
        if ($fresh !== null) {
            $fresh['_claim_granted'] = $this->db->affectedRows() > 0;
        }

        return $fresh;
    }

    public function updateProgress(int $idJob, int $percent, string $message, array $extra = []): void
    {
        $payload = [
            'progress_percent' => max(0, min(100, $percent)),
            'progress_message' => trim($message) !== '' ? trim($message) : null,
            'heartbeat_at'     => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ] + $extra;

        $this->update($idJob, $payload);
    }

    public function markCompleted(int $idJob, array $result): void
    {
        $now = date('Y-m-d H:i:s');

        $this->update($idJob, [
            'status'             => self::STATUS_COMPLETED,
            'progress_percent'   => 100,
            'progress_message'   => trim((string)($result['message'] ?? 'Operazione completata.')),
            'backup_file_name'   => $result['backup_file'] ?? null,
            'backup_file_path'   => $result['backup_path'] ?? null,
            'backup_file_format' => $result['backup_format'] ?? null,
            'inserted_slots'     => (int)($result['inserted'] ?? 0),
            'result_json'        => json_encode($result, JSON_UNESCAPED_UNICODE),
            'error_message'      => null,
            'finished_at'        => $now,
            'heartbeat_at'       => $now,
            'updated_at'         => $now,
        ]);
    }

    public function markFailed(int $idJob, string $errorMessage): void
    {
        $now = date('Y-m-d H:i:s');

        $this->update($idJob, [
            'status'           => self::STATUS_FAILED,
            'progress_message' => 'Operazione non completata.',
            'error_message'    => trim($errorMessage) !== '' ? trim($errorMessage) : 'Errore sconosciuto.',
            'finished_at'      => $now,
            'heartbeat_at'     => $now,
            'updated_at'       => $now,
        ]);
    }

    public function markNotificationSent(int $idJob): void
    {
        $this->update($idJob, [
            'notify_push_sent' => 1,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    public function decodeResult(array $job): array
    {
        $json = (string)($job['result_json'] ?? '');
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
