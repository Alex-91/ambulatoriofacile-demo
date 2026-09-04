<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class NotificationRateLimiterService
{
    public const TABLE = 'platform_notification_rate_limits';

    private BaseConnection $platformDb;
    private TenantNotificationPolicyService $policies;

    public function __construct(?BaseConnection $platformDb = null, ?TenantNotificationPolicyService $policies = null)
    {
        $this->platformDb = $platformDb ?? Database::connect('platform');
        $this->policies = $policies ?? new TenantNotificationPolicyService($this->platformDb);
    }

    /**
     * Reserves one sending slot. A failed provider attempt still consumes the slot,
     * which prevents retry storms from bypassing the configured limits.
     *
     * @param array<string, mixed> $policy
     * @return array{allowed:bool,reason:string,next_allowed_at:?string,sent_today:int,tracked:bool}
     */
    public function claim(int $tenantId, string $channel, array $policy, bool $manageTransaction = true): array
    {
        $channel = strtolower(trim($channel));
        if ($tenantId <= 0 || !in_array($channel, ['email', 'wa', 'sms'], true)) {
            return ['allowed' => false, 'reason' => 'invalid_context', 'next_allowed_at' => null, 'sent_today' => 0, 'tracked' => false];
        }
        if (!$this->platformDb->tableExists(self::TABLE)) {
            return ['allowed' => true, 'reason' => 'schema_missing', 'next_allowed_at' => null, 'sent_today' => 0, 'tracked' => false];
        }

        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $spacing = $this->policies->minimumSpacingSeconds($policy, $channel);
        $dailyLimit = $this->policies->dailyLimit($policy, $channel);
        $nextAt = date('Y-m-d H:i:s', time() + $spacing);

        if ($manageTransaction) {
            $this->platformDb->transBegin();
        }
        try {
            $row = $this->platformDb->query(
                'SELECT * FROM ' . self::TABLE . ' WHERE id_tenant = ? AND channel = ? LIMIT 1 FOR UPDATE',
                [$tenantId, $channel]
            )->getRowArray();
            $sentToday = (string) ($row['counter_date'] ?? '') === $today
                ? (int) ($row['sent_today'] ?? 0)
                : 0;
            $currentNext = trim((string) ($row['next_allowed_at'] ?? ''));

            if ($sentToday >= $dailyLimit) {
                $tomorrow = date('Y-m-d 00:00:00', strtotime('+1 day'));
                if ($manageTransaction) {
                    $this->platformDb->transCommit();
                }
                return ['allowed' => false, 'reason' => 'daily_limit', 'next_allowed_at' => $tomorrow, 'sent_today' => $sentToday, 'tracked' => true];
            }
            if ($currentNext !== '' && $currentNext > $now) {
                if ($manageTransaction) {
                    $this->platformDb->transCommit();
                }
                return ['allowed' => false, 'reason' => 'interval', 'next_allowed_at' => $currentNext, 'sent_today' => $sentToday, 'tracked' => true];
            }

            $payload = [
                'next_allowed_at' => $nextAt,
                'counter_date' => $today,
                'sent_today' => $sentToday + 1,
                'updated_at' => $now,
            ];
            if ($row) {
                $this->platformDb->table(self::TABLE)
                    ->where('id_notification_rate_limit', (int) ($row['id_notification_rate_limit'] ?? 0))
                    ->update($payload);
            } else {
                $this->platformDb->table(self::TABLE)->insert([
                    'id_tenant' => $tenantId,
                    'channel' => $channel,
                ] + $payload);
            }
            if (!$this->platformDb->transStatus()) {
                throw new \RuntimeException('Aggiornamento del limite di invio non riuscito.');
            }
            if ($manageTransaction) {
                $this->platformDb->transCommit();
            }

            return ['allowed' => true, 'reason' => 'claimed', 'next_allowed_at' => $nextAt, 'sent_today' => $sentToday + 1, 'tracked' => true];
        } catch (\Throwable $e) {
            if ($manageTransaction) {
                $this->platformDb->transRollback();
            }
            throw $e;
        }
    }
}
