<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class TenantNotificationPolicyService
{
    public const TABLE = 'platform_tenant_notification_policies';
    public const FROM_DOMAIN = 'ambulatoriofacile.it';
    public const NO_REPLY_ADDRESS = 'noreply@ambulatoriofacile.it';

    private BaseConnection $platformDb;

    public function __construct(?BaseConnection $platformDb = null)
    {
        $this->platformDb = $platformDb ?? Database::connect('platform');
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(int $tenantId, string $tenantName = ''): array
    {
        if ($tenantName === '' && $tenantId > 0 && $this->platformDb->tableExists('platform_tenants')) {
            $tenant = $this->platformDb->table('platform_tenants')
                ->select('tenant_name')
                ->where('id_tenant', $tenantId)
                ->get(1)
                ->getRowArray();
            $tenantName = trim((string) ($tenant['tenant_name'] ?? ''));
        }
        $stored = [];
        $schemaReady = $this->platformDb->tableExists(self::TABLE);
        if ($schemaReady && $tenantId > 0) {
            $row = $this->platformDb->table(self::TABLE)
                ->where('id_tenant', $tenantId)
                ->get(1)
                ->getRowArray();
            $stored = $this->decode((string) ($row['config_json'] ?? ''));
        }

        $policy = $this->sanitize($stored, $tenantName);
        $policy['tenant_id'] = $tenantId;
        $policy['schema_ready'] = $schemaReady;
        $policy['using_defaults'] = $stored === [];

        return $policy;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public function save(int $tenantId, array $raw, int $updatedByPlatformUserId = 0, string $tenantName = ''): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Spazio cliente non valido.');
        }
        if (!$this->platformDb->tableExists(self::TABLE)) {
            throw new \RuntimeException('Il database non contiene ancora lo schema delle politiche di invio.');
        }

        $policy = $this->sanitize($raw, $tenantName, true);
        $now = date('Y-m-d H:i:s');
        $row = $this->platformDb->table(self::TABLE)
            ->where('id_tenant', $tenantId)
            ->get(1)
            ->getRowArray();
        $payload = [
            'id_tenant' => $tenantId,
            'config_json' => json_encode($policy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_by_platform_user_id' => $updatedByPlatformUserId > 0 ? $updatedByPlatformUserId : null,
            'updated_at' => $now,
        ];

        if ($row) {
            $ok = $this->platformDb->table(self::TABLE)
                ->where('id_tenant_notification_policy', (int) ($row['id_tenant_notification_policy'] ?? 0))
                ->update($payload);
        } else {
            $payload['created_at'] = $now;
            $ok = $this->platformDb->table(self::TABLE)->insert($payload);
        }
        if (!$ok) {
            throw new \RuntimeException('Salvataggio dei parametri di invio non riuscito.');
        }

        return $this->resolve($tenantId, $tenantName);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public function sanitize(array $raw, string $tenantName = '', bool $strict = false): array
    {
        $defaults = $this->defaults($tenantName);
        $email = (array) ($raw['email'] ?? []);
        $whatsApp = (array) ($raw['whatsapp'] ?? []);
        $sms = (array) ($raw['sms'] ?? []);

        $fromAddress = strtolower(trim((string) ($email['from_address'] ?? $defaults['email']['from_address'])));
        if (!$this->isAllowedFromAddress($fromAddress)) {
            if ($strict) {
                throw new \InvalidArgumentException('Il mittente email deve essere un indirizzo valido @' . self::FROM_DOMAIN . '.');
            }
            $fromAddress = self::NO_REPLY_ADDRESS;
        }

        $fromName = $this->cleanText((string) ($email['from_name'] ?? $defaults['email']['from_name']), 120);
        if ($fromName === '') {
            $fromName = (string) $defaults['email']['from_name'];
        }
        $subjectPrefix = $this->cleanText((string) ($email['subject_prefix'] ?? $defaults['email']['subject_prefix']), 60);

        $smsSender = preg_replace('/[^A-Za-z0-9]/', '', trim((string) ($sms['sender'] ?? $defaults['sms']['sender']))) ?? '';
        if ($smsSender === '' || strlen($smsSender) > 11) {
            if ($strict) {
                throw new \InvalidArgumentException('Il mittente SMS deve contenere da 1 a 11 caratteri alfanumerici.');
            }
            $smsSender = (string) $defaults['sms']['sender'];
        }

        return [
            'version' => 1,
            'email' => [
                'from_address' => $fromAddress,
                'from_name' => $fromName,
                'reply_to' => self::NO_REPLY_ADDRESS,
                'subject_prefix' => $subjectPrefix,
                'messages_per_interval' => $this->integer(
                    $email['messages_per_interval'] ?? $defaults['email']['messages_per_interval'],
                    1,
                    100,
                    $strict,
                    'Email per intervallo'
                ),
                'interval_minutes' => $this->integer(
                    $email['interval_minutes'] ?? $defaults['email']['interval_minutes'],
                    1,
                    1440,
                    $strict,
                    'Intervallo email'
                ),
                'daily_limit' => $this->integer(
                    $email['daily_limit'] ?? $defaults['email']['daily_limit'],
                    1,
                    5000,
                    $strict,
                    'Limite email giornaliero'
                ),
            ],
            'whatsapp' => [
                'messages_per_interval' => $this->integer(
                    $whatsApp['messages_per_interval'] ?? $defaults['whatsapp']['messages_per_interval'],
                    1,
                    30,
                    $strict,
                    'Messaggi WhatsApp per intervallo'
                ),
                'interval_minutes' => $this->integer(
                    $whatsApp['interval_minutes'] ?? $defaults['whatsapp']['interval_minutes'],
                    1,
                    1440,
                    $strict,
                    'Intervallo WhatsApp'
                ),
                'daily_limit' => $this->integer(
                    $whatsApp['daily_limit'] ?? $defaults['whatsapp']['daily_limit'],
                    1,
                    2000,
                    $strict,
                    'Limite WhatsApp giornaliero'
                ),
                'sms_fallback_enabled' => array_key_exists('sms_fallback_enabled', $whatsApp)
                    ? !empty($whatsApp['sms_fallback_enabled'])
                    : (bool) $defaults['whatsapp']['sms_fallback_enabled'],
                'fallback_after_minutes' => $this->integer(
                    $whatsApp['fallback_after_minutes'] ?? $defaults['whatsapp']['fallback_after_minutes'],
                    5,
                    1440,
                    $strict,
                    'Attesa fallback SMS'
                ),
            ],
            'sms' => [
                'sender' => $smsSender,
                'messages_per_interval' => $this->integer(
                    $sms['messages_per_interval'] ?? $defaults['sms']['messages_per_interval'],
                    1,
                    100,
                    $strict,
                    'SMS per intervallo'
                ),
                'interval_minutes' => $this->integer(
                    $sms['interval_minutes'] ?? $defaults['sms']['interval_minutes'],
                    1,
                    1440,
                    $strict,
                    'Intervallo SMS'
                ),
                'daily_limit' => $this->integer(
                    $sms['daily_limit'] ?? $defaults['sms']['daily_limit'],
                    1,
                    5000,
                    $strict,
                    'Limite SMS giornaliero'
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(string $tenantName = ''): array
    {
        $tenantName = $this->cleanText($tenantName, 90);
        $fromName = $tenantName !== '' ? ($tenantName . ' tramite AmbulatorioFacile') : 'AmbulatorioFacile';
        $subjectPrefix = $tenantName !== '' ? ('[' . $tenantName . ']') : '[AmbulatorioFacile]';

        return [
            'version' => 1,
            'email' => [
                'from_address' => self::NO_REPLY_ADDRESS,
                'from_name' => $fromName,
                'reply_to' => self::NO_REPLY_ADDRESS,
                'subject_prefix' => $this->cleanText($subjectPrefix, 60),
                'messages_per_interval' => 10,
                'interval_minutes' => 5,
                'daily_limit' => 500,
            ],
            'whatsapp' => [
                'messages_per_interval' => 1,
                'interval_minutes' => 5,
                'daily_limit' => 250,
                'sms_fallback_enabled' => true,
                'fallback_after_minutes' => 30,
            ],
            'sms' => [
                'sender' => 'AmbFacile',
                'messages_per_interval' => 10,
                'interval_minutes' => 5,
                'daily_limit' => 500,
            ],
        ];
    }

    public function minimumSpacingSeconds(array $policy, string $channel): int
    {
        $bucket = match (strtolower(trim($channel))) {
            AppointmentNotificationSettingsService::CHANNEL_WHATSAPP => (array) ($policy['whatsapp'] ?? []),
            AppointmentNotificationSettingsService::CHANNEL_SMS => (array) ($policy['sms'] ?? []),
            AppointmentNotificationSettingsService::CHANNEL_EMAIL => (array) ($policy['email'] ?? []),
            default => [],
        };
        $messages = max(1, (int) ($bucket['messages_per_interval'] ?? 1));
        $minutes = max(1, (int) ($bucket['interval_minutes'] ?? 1));

        return max(1, (int) ceil(($minutes * 60) / $messages));
    }

    public function dailyLimit(array $policy, string $channel): int
    {
        $bucket = match (strtolower(trim($channel))) {
            AppointmentNotificationSettingsService::CHANNEL_WHATSAPP => (array) ($policy['whatsapp'] ?? []),
            AppointmentNotificationSettingsService::CHANNEL_SMS => (array) ($policy['sms'] ?? []),
            AppointmentNotificationSettingsService::CHANNEL_EMAIL => (array) ($policy['email'] ?? []),
            default => [],
        };

        return max(1, (int) ($bucket['daily_limit'] ?? 1));
    }

    private function isAllowedFromAddress(string $email): bool
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        return $domain === self::FROM_DOMAIN;
    }

    private function cleanText(string $value, int $maxLength): string
    {
        $value = trim(strip_tags(str_replace(["\r", "\n"], ' ', $value)));
        $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
        return mb_substr($value, 0, $maxLength);
    }

    private function integer($value, int $minimum, int $maximum, bool $strict, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < $minimum || $integer > $maximum) {
            if ($strict) {
                throw new \InvalidArgumentException($label . ' deve essere compreso tra ' . $minimum . ' e ' . $maximum . '.');
            }
            return max($minimum, min($maximum, (int) $value));
        }
        return (int) $integer;
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
