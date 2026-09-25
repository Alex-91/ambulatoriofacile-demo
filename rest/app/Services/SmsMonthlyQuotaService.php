<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class SmsMonthlyQuotaService
{
    public const TABLE = 'platform_sms_monthly_usage';
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        // Independent connection: rolling back a campaign must not refund an SMS already submitted.
        $this->db = $db ?? Database::connect('platform', false);
    }

    public static function periodKey(array $policy, ?\DateTimeImmutable $now = null): string
    {
        if (($policy['sms']['monthly_auto_renew'] ?? true) === false) {
            $period = (string) ($policy['sms']['monthly_period'] ?? '');
            if (!preg_match('/^(?:\d{4}-(?:0[1-9]|1[0-2])|R[a-f0-9]{6})$/D', $period)) {
                throw new \RuntimeException('Periodo del plafond SMS non configurato.');
            }
            return $period;
        }
        return ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('Europe/Rome'))->format('Y-m');
    }

    public function usage(int $tenantId, array $policy = []): ?int
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return null;
        }
        $month = self::periodKey($policy);
        $row = $this->db->table(self::TABLE)->where('id_tenant', $tenantId)->where('month_key', $month)->get()->getRowArray();
        return (int) ($row['attempts'] ?? 0);
    }

    /** Atomic reservation before contacting any provider, including failed/uncertain attempts. */
    public function claim(int $tenantId, array $policy): bool
    {
        if ($tenantId <= 0) {
            return true; // Platform messages have no tenant quota.
        }
        $enabled = !empty($policy['sms']['monthly_limit_enabled']);
        if (!$this->db->tableExists(self::TABLE)) {
            if ($enabled) {
                throw new \RuntimeException('Contatore SMS mensile non disponibile: invio bloccato.');
            }
            return true;
        }
        $month = self::periodKey($policy);
        $sql = $this->db->DBDriver === 'SQLite3'
            ? 'INSERT OR IGNORE INTO ' . self::TABLE . ' (id_tenant, month_key, attempts) VALUES (?, ?, 0)'
            : 'INSERT INTO ' . self::TABLE . ' (id_tenant, month_key, attempts) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE attempts = attempts';
        if (!$this->db->query($sql, [$tenantId, $month])) {
            throw new \RuntimeException('Contatore SMS non disponibile.');
        }
        $builder = $this->db->table(self::TABLE)->where('id_tenant', $tenantId)->where('month_key', $month);
        if ($enabled) {
            $builder->where('attempts <', max(0, (int) ($policy['sms']['monthly_limit'] ?? 0)));
        }
        if (!$builder->set('attempts', 'attempts + 1', false)->update()) {
            throw new \RuntimeException('Prenotazione SMS non riuscita.');
        }
        return $this->db->affectedRows() === 1;
    }
}
