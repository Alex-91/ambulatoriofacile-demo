<?php

namespace App\Models;

use CodeIgniter\Model;

class OtpDeliveryLogModel extends Model
{
    protected $table      = 'otp_delivery_logs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'purpose',
        'channel',
        'status',
        'user_id',
        'user_type',
        'error_message',
        'meta_json',
        'created_at',
    ];
    protected $useTimestamps = false;

    public function tableExists(): bool
    {
        try {
            return $this->db->tableExists($this->table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getTrackingStartAt(): ?string
    {
        if (!$this->tableExists()) {
            return null;
        }

        $row = $this->select('MIN(created_at) AS first_at')->first();
        $firstAt = trim((string)($row['first_at'] ?? ''));

        return $firstAt !== '' ? $firstAt : null;
    }

    public function getChannelSummary(string $fromAt, string $toAt): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        return $this->db->query("
            SELECT
              channel,
              SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_count,
              SUM(CASE WHEN status <> 'success' THEN 1 ELSE 0 END) AS failed_count,
              COUNT(*) AS total_count
            FROM {$this->table}
            WHERE created_at >= ?
              AND created_at <= ?
            GROUP BY channel
            ORDER BY
              CASE channel
                WHEN 'push' THEN 1
                WHEN 'email' THEN 2
                WHEN 'sms' THEN 3
                WHEN 'wa' THEN 4
                ELSE 99
              END,
              channel ASC
        ", [$fromAt, $toAt])->getResultArray();
    }

    public function getPurposeChannelSummary(string $fromAt, string $toAt): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        return $this->db->query("
            SELECT
              purpose,
              channel,
              SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_count,
              SUM(CASE WHEN status <> 'success' THEN 1 ELSE 0 END) AS failed_count,
              COUNT(*) AS total_count
            FROM {$this->table}
            WHERE created_at >= ?
              AND created_at <= ?
            GROUP BY purpose, channel
            ORDER BY purpose ASC,
              CASE channel
                WHEN 'push' THEN 1
                WHEN 'email' THEN 2
                WHEN 'sms' THEN 3
                WHEN 'wa' THEN 4
                ELSE 99
              END,
              channel ASC
        ", [$fromAt, $toAt])->getResultArray();
    }

    public function getDailySummary(string $fromAt, string $toAt): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        return $this->db->query("
            SELECT
              DATE(created_at) AS day_key,
              channel,
              SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_count,
              SUM(CASE WHEN status <> 'success' THEN 1 ELSE 0 END) AS failed_count,
              COUNT(*) AS total_count
            FROM {$this->table}
            WHERE created_at >= ?
              AND created_at <= ?
            GROUP BY DATE(created_at), channel
            ORDER BY day_key DESC,
              CASE channel
                WHEN 'push' THEN 1
                WHEN 'email' THEN 2
                WHEN 'sms' THEN 3
                WHEN 'wa' THEN 4
                ELSE 99
              END,
              channel ASC
        ", [$fromAt, $toAt])->getResultArray();
    }

    public function getSuccessfulLoginEmailRowsUntil(string $toAt): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        return $this->db->query("
            SELECT
              id,
              created_at,
              user_id,
              user_type,
              meta_json
            FROM {$this->table}
            WHERE purpose = ?
              AND channel = 'email'
              AND status = 'success'
              AND created_at <= ?
            ORDER BY created_at ASC, id ASC
        ", ['login_mfa', $toAt])->getResultArray();
    }

    public function getLatestSuccessfulLoginEmailDay(string $fromAt, string $toAt): ?string
    {
        if (!$this->tableExists()) {
            return null;
        }

        $row = $this->db->query("
            SELECT DATE(MAX(created_at)) AS latest_day
            FROM {$this->table}
            WHERE purpose = ?
              AND channel = 'email'
              AND status = 'success'
              AND created_at >= ?
              AND created_at <= ?
        ", ['login_mfa', $fromAt, $toAt])->getRowArray();

        $latestDay = trim((string)($row['latest_day'] ?? ''));

        return $latestDay !== '' ? $latestDay : null;
    }
}
