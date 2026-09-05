<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class SmsProviderConfigurationService
{
    public const TABLE = 'platform_sms_provider_settings';
    public const PROVIDER_SMSFACTOR = 'smsfactor';
    public const PROVIDER_ARUBA = 'aruba';
    private const GLOBAL_SCOPE = 'global';

    private BaseConnection $db;
    private SmsProviderSecretsService $secrets;

    public function __construct(?BaseConnection $db = null, ?SmsProviderSecretsService $secrets = null)
    {
        $this->db = $db ?? Database::connect('platform');
        $this->secrets = $secrets ?? new SmsProviderSecretsService();
    }

    public function schemaReady(): bool
    {
        return $this->db->tableExists(self::TABLE);
    }

    /** @return array<string, mixed> */
    public static function environmentRuntime(): array
    {
        $provider = self::normalizeProvider((string) env('SMS_PROVIDER', self::PROVIDER_ARUBA));
        $runtime = [
            'provider' => $provider,
            'provider_label' => self::providerLabel($provider),
            'sender' => trim((string) env('SMS_SENDER', $provider === self::PROVIDER_SMSFACTOR ? 'AmbFacile' : 'AmbRIMAGGIO')),
            'smsfactor' => [
                'api_token' => trim((string) env('SMSFACTOR_API_TOKEN', '')),
                'base_url' => trim((string) env('SMSFACTOR_BASE_URL', SmsFactorClient::DEFAULT_BASE_URL)),
                'timeout_seconds' => max(5, min(120, (int) env('SMSFACTOR_TIMEOUT_SECONDS', 30))),
                'push_type' => self::normalizePushType((string) env('SMSFACTOR_PUSH_TYPE', 'alert')),
                'webhook_signature' => trim((string) env('SMSFACTOR_WEBHOOK_SIGNATURE', '')),
            ],
            'aruba' => [
                'username' => trim((string) env('SMS_USERNAME', '')),
                'password' => trim((string) env('SMS_PASSWORD', '')),
            ],
            'source' => 'environment',
            'tenant_id' => 0,
            'inherited' => false,
            'tenant_sender_override' => '',
        ];
        $runtime['configured'] = self::runtimeIsConfigured($runtime);

        return $runtime;
    }

    /** @return array<string, mixed> */
    public function resolveRuntime(int $tenantId = 0): array
    {
        $global = self::environmentRuntime();
        $globalRow = null;
        if ($this->schemaReady()) {
            $globalRow = $this->findRow(self::GLOBAL_SCOPE);
            if ($globalRow) {
                $global = $this->overlayRow($global, $globalRow, true);
                $global['source'] = 'database';
            }
        }

        if ($tenantId <= 0 || !$this->schemaReady()) {
            $global['configured'] = self::runtimeIsConfigured($global);
            return $global;
        }

        $tenantRow = $this->findRow($this->tenantScope($tenantId));
        if (!$tenantRow || !empty($tenantRow['inherit_global'])) {
            $global['tenant_sender_override'] = '';
            if ($tenantRow && trim((string) ($tenantRow['default_sender'] ?? '')) !== '') {
                $global['sender'] = (string) $tenantRow['default_sender'];
                $global['tenant_sender_override'] = (string) $tenantRow['default_sender'];
            }
            $global['tenant_id'] = $tenantId;
            $global['inherited'] = true;
            $global['source'] = $globalRow ? 'global_database' : 'global_environment';
            $global['configured'] = self::runtimeIsConfigured($global);
            return $global;
        }

        $runtime = [
            'provider' => self::normalizeProvider((string) ($tenantRow['provider'] ?? self::PROVIDER_SMSFACTOR)),
            'sender' => trim((string) ($tenantRow['default_sender'] ?? 'AmbFacile')),
            'smsfactor' => [
                'api_token' => $this->decryptColumn($tenantRow, 'smsfactor_api_token_encrypted'),
                'base_url' => trim((string) ($tenantRow['smsfactor_base_url'] ?? SmsFactorClient::DEFAULT_BASE_URL)),
                'timeout_seconds' => max(5, min(120, (int) ($tenantRow['smsfactor_timeout_seconds'] ?? 30))),
                'push_type' => self::normalizePushType((string) ($tenantRow['smsfactor_push_type'] ?? 'alert')),
                'webhook_signature' => $this->decryptColumn($tenantRow, 'smsfactor_webhook_signature_encrypted'),
            ],
            'aruba' => [
                'username' => $this->decryptColumn($tenantRow, 'aruba_username_encrypted'),
                'password' => $this->decryptColumn($tenantRow, 'aruba_password_encrypted'),
            ],
            'source' => 'tenant_database',
            'tenant_id' => $tenantId,
            'inherited' => false,
            'tenant_sender_override' => trim((string) ($tenantRow['default_sender'] ?? '')),
        ];
        $runtime['provider_label'] = self::providerLabel((string) $runtime['provider']);
        $runtime['configured'] = self::runtimeIsConfigured($runtime);

        return $runtime;
    }

    /** @return array<string, mixed> */
    public function globalForDisplay(): array
    {
        $runtime = $this->resolveRuntime();
        $row = $this->schemaReady() ? $this->findRow(self::GLOBAL_SCOPE) : null;

        return $this->displayPayload($runtime, $row, 'global');
    }

    /** @return array<string, mixed> */
    public function tenantForDisplay(int $tenantId): array
    {
        $runtime = $this->resolveRuntime($tenantId);
        $row = $this->schemaReady() ? $this->findRow($this->tenantScope($tenantId)) : null;
        $display = $this->displayPayload($runtime, $row, !empty($runtime['inherited']) ? 'inherit' : 'custom');
        $display['tenant_id'] = $tenantId;
        $display['has_tenant_record'] = $row !== null;

        return $display;
    }

    /** @param array<string, mixed> $raw */
    public function saveGlobal(array $raw, int $updatedByPlatformUserId = 0): array
    {
        $this->saveRow(self::GLOBAL_SCOPE, 0, false, $raw, $updatedByPlatformUserId);
        return $this->globalForDisplay();
    }

    /** @param array<string, mixed> $raw */
    public function saveTenant(int $tenantId, array $raw, int $updatedByPlatformUserId = 0): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Spazio cliente non valido.');
        }
        if (!$this->db->tableExists('platform_tenants') || !$this->db->table('platform_tenants')->where('id_tenant', $tenantId)->countAllResults()) {
            throw new \InvalidArgumentException('Spazio cliente non trovato.');
        }

        $inherit = strtolower(trim((string) ($raw['mode'] ?? 'inherit'))) !== 'custom';
        $this->saveRow($this->tenantScope($tenantId), $tenantId, $inherit, $raw, $updatedByPlatformUserId);
        return $this->tenantForDisplay($tenantId);
    }

    /** @param array<string, mixed> $raw */
    private function saveRow(
        string $scopeKey,
        int $tenantId,
        bool $inheritGlobal,
        array $raw,
        int $updatedByPlatformUserId
    ): void {
        if (!$this->schemaReady()) {
            throw new \RuntimeException('Esegui la migration delle configurazioni provider SMS prima di salvare.');
        }

        $existing = $this->findRow($scopeKey) ?? [];
        $provider = self::normalizeProvider((string) ($raw['provider'] ?? ($existing['provider'] ?? self::PROVIDER_SMSFACTOR)));
        $sender = trim((string) ($raw['sender'] ?? ($existing['default_sender'] ?? 'AmbFacile')));
        if (preg_match('/^[A-Za-z0-9]{1,11}$/', $sender) !== 1) {
            throw new \InvalidArgumentException('Il mittente SMS deve contenere da 1 a 11 caratteri alfanumerici.');
        }

        $baseUrl = rtrim(trim((string) ($raw['smsfactor_base_url'] ?? ($existing['smsfactor_base_url'] ?? SmsFactorClient::DEFAULT_BASE_URL))), '/');
        $parts = parse_url($baseUrl);
        if (
            filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || !empty($parts['user'])
            || !empty($parts['pass'])
            || !empty($parts['query'])
            || !empty($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('L’endpoint SMSFactor deve essere un URL HTTPS senza credenziali o query string.');
        }
        $timeout = filter_var($raw['smsfactor_timeout_seconds'] ?? ($existing['smsfactor_timeout_seconds'] ?? 30), FILTER_VALIDATE_INT);
        if ($timeout === false || $timeout < 5 || $timeout > 120) {
            throw new \InvalidArgumentException('Il timeout SMSFactor deve essere compreso tra 5 e 120 secondi.');
        }
        $pushType = self::normalizePushType((string) ($raw['smsfactor_push_type'] ?? ($existing['smsfactor_push_type'] ?? 'alert')));

        $secretMap = [
            'smsfactor_api_token' => 'smsfactor_api_token_encrypted',
            'smsfactor_webhook_signature' => 'smsfactor_webhook_signature_encrypted',
            'aruba_username' => 'aruba_username_encrypted',
            'aruba_password' => 'aruba_password_encrypted',
        ];
        $encrypted = [];
        foreach ($secretMap as $input => $column) {
            $submitted = (string) ($raw[$input] ?? '');
            if (strlen($submitted) > 4096) {
                throw new \InvalidArgumentException('La credenziale ' . $input . ' è troppo lunga.');
            }
            $encrypted[$column] = !empty($raw['clear_' . $input])
                ? null
                : (trim($submitted) !== '' ? $this->secrets->encrypt($submitted) : ($existing[$column] ?? null));
        }

        $candidate = [
            'provider' => $provider,
            'sender' => $sender,
            'smsfactor' => [
                'api_token' => $this->candidateSecret($encrypted['smsfactor_api_token_encrypted'], $tenantId === 0 ? (string) env('SMSFACTOR_API_TOKEN', '') : ''),
                'base_url' => $baseUrl,
                'timeout_seconds' => (int) $timeout,
                'push_type' => $pushType,
                'webhook_signature' => $this->candidateSecret($encrypted['smsfactor_webhook_signature_encrypted'], $tenantId === 0 ? (string) env('SMSFACTOR_WEBHOOK_SIGNATURE', '') : ''),
            ],
            'aruba' => [
                'username' => $this->candidateSecret($encrypted['aruba_username_encrypted'], $tenantId === 0 ? (string) env('SMS_USERNAME', '') : ''),
                'password' => $this->candidateSecret($encrypted['aruba_password_encrypted'], $tenantId === 0 ? (string) env('SMS_PASSWORD', '') : ''),
            ],
        ];
        if (!$inheritGlobal && !self::runtimeIsConfigured($candidate)) {
            throw new \InvalidArgumentException(
                $provider === self::PROVIDER_SMSFACTOR
                    ? 'Inserisci il token API SMSFactor per attivare questa configurazione.'
                    : 'Inserisci username e password Aruba SMS per attivare questa configurazione.'
            );
        }

        $now = date('Y-m-d H:i:s');
        $payload = array_merge([
            'scope_key' => $scopeKey,
            'id_tenant' => $tenantId > 0 ? $tenantId : null,
            'inherit_global' => $inheritGlobal ? 1 : 0,
            'provider' => $provider,
            'default_sender' => $sender,
            'smsfactor_base_url' => $baseUrl,
            'smsfactor_timeout_seconds' => (int) $timeout,
            'smsfactor_push_type' => $pushType,
            'updated_by_platform_user_id' => $updatedByPlatformUserId > 0 ? $updatedByPlatformUserId : null,
            'updated_at' => $now,
        ], $encrypted);

        if ($existing) {
            $ok = $this->db->table(self::TABLE)
                ->where('id_sms_provider_setting', (int) $existing['id_sms_provider_setting'])
                ->update($payload);
        } else {
            $payload['created_at'] = $now;
            $ok = $this->db->table(self::TABLE)->insert($payload);
        }
        if (!$ok) {
            throw new \RuntimeException('Salvataggio della configurazione provider SMS non riuscito.');
        }
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $row @return array<string, mixed> */
    private function overlayRow(array $base, array $row, bool $allowEnvironmentSecrets): array
    {
        $base['provider'] = self::normalizeProvider((string) ($row['provider'] ?? $base['provider']));
        $base['provider_label'] = self::providerLabel((string) $base['provider']);
        $base['sender'] = trim((string) ($row['default_sender'] ?? $base['sender']));
        $base['smsfactor']['base_url'] = trim((string) ($row['smsfactor_base_url'] ?? $base['smsfactor']['base_url']));
        $base['smsfactor']['timeout_seconds'] = max(5, min(120, (int) ($row['smsfactor_timeout_seconds'] ?? $base['smsfactor']['timeout_seconds'])));
        $base['smsfactor']['push_type'] = self::normalizePushType((string) ($row['smsfactor_push_type'] ?? $base['smsfactor']['push_type']));

        foreach ([
            ['smsfactor', 'api_token', 'smsfactor_api_token_encrypted'],
            ['smsfactor', 'webhook_signature', 'smsfactor_webhook_signature_encrypted'],
            ['aruba', 'username', 'aruba_username_encrypted'],
            ['aruba', 'password', 'aruba_password_encrypted'],
        ] as [$bucket, $key, $column]) {
            $decrypted = $this->decryptColumn($row, $column);
            if ($decrypted !== '' || !$allowEnvironmentSecrets) {
                $base[$bucket][$key] = $decrypted;
            }
        }

        $base['configured'] = self::runtimeIsConfigured($base);
        return $base;
    }

    /** @param array<string, mixed> $runtime @param array<string, mixed>|null $row @return array<string, mixed> */
    private function displayPayload(array $runtime, ?array $row, string $mode): array
    {
        $stored = static function (?array $settingsRow, string $column): bool {
            return trim((string) ($settingsRow[$column] ?? '')) !== '';
        };

        return [
            'schema_ready' => $this->schemaReady(),
            'mode' => $mode,
            'provider' => (string) ($runtime['provider'] ?? self::PROVIDER_ARUBA),
            'provider_label' => (string) ($runtime['provider_label'] ?? self::providerLabel((string) ($runtime['provider'] ?? ''))),
            'sender' => (string) ($runtime['sender'] ?? 'AmbFacile'),
            'smsfactor_base_url' => (string) ($runtime['smsfactor']['base_url'] ?? SmsFactorClient::DEFAULT_BASE_URL),
            'smsfactor_timeout_seconds' => (int) ($runtime['smsfactor']['timeout_seconds'] ?? 30),
            'smsfactor_push_type' => (string) ($runtime['smsfactor']['push_type'] ?? 'alert'),
            'smsfactor_api_token_configured' => trim((string) ($runtime['smsfactor']['api_token'] ?? '')) !== '',
            'smsfactor_webhook_signature_configured' => trim((string) ($runtime['smsfactor']['webhook_signature'] ?? '')) !== '',
            'aruba_username_configured' => trim((string) ($runtime['aruba']['username'] ?? '')) !== '',
            'aruba_password_configured' => trim((string) ($runtime['aruba']['password'] ?? '')) !== '',
            'smsfactor_api_token_stored' => $stored($row, 'smsfactor_api_token_encrypted'),
            'smsfactor_webhook_signature_stored' => $stored($row, 'smsfactor_webhook_signature_encrypted'),
            'aruba_username_stored' => $stored($row, 'aruba_username_encrypted'),
            'aruba_password_stored' => $stored($row, 'aruba_password_encrypted'),
            'configured' => !empty($runtime['configured']),
            'source' => (string) ($runtime['source'] ?? 'environment'),
            'stored_in_database' => $row !== null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function findRow(string $scopeKey): ?array
    {
        $row = $this->db->table(self::TABLE)->where('scope_key', $scopeKey)->get(1)->getRowArray();
        return $row ?: null;
    }

    /** @param array<string, mixed> $row */
    private function decryptColumn(array $row, string $column): string
    {
        return trim((string) ($this->secrets->decrypt((string) ($row[$column] ?? '')) ?? ''));
    }

    private function candidateSecret(?string $encrypted, string $fallback): string
    {
        $decrypted = trim((string) ($this->secrets->decrypt($encrypted) ?? ''));
        return $decrypted !== '' ? $decrypted : trim($fallback);
    }

    private function tenantScope(int $tenantId): string
    {
        return 'tenant:' . $tenantId;
    }

    /** @param array<string, mixed> $runtime */
    private static function runtimeIsConfigured(array $runtime): bool
    {
        $provider = self::normalizeProvider((string) ($runtime['provider'] ?? ''));
        if ($provider === self::PROVIDER_SMSFACTOR) {
            return trim((string) ($runtime['smsfactor']['api_token'] ?? '')) !== '';
        }

        return trim((string) ($runtime['aruba']['username'] ?? '')) !== ''
            && trim((string) ($runtime['aruba']['password'] ?? '')) !== '';
    }

    private static function normalizeProvider(string $provider): string
    {
        return strtolower(trim($provider)) === self::PROVIDER_SMSFACTOR
            ? self::PROVIDER_SMSFACTOR
            : self::PROVIDER_ARUBA;
    }

    private static function normalizePushType(string $pushType): string
    {
        return strtolower(trim($pushType)) === 'marketing' ? 'marketing' : 'alert';
    }

    public static function providerLabel(string $provider): string
    {
        return self::normalizeProvider($provider) === self::PROVIDER_SMSFACTOR ? 'SMSFactor' : 'Aruba SMS';
    }
}
