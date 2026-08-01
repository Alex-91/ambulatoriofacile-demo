<?php

namespace App\Services;

use App\Config\TsBilling;
use App\Models\PlatformTenantTsProfilesModel;
use Config\Database;

class TsProfileService
{
    private PlatformTenantTsProfilesModel $profiles;
    private TsSecretsService $secrets;
    private TsBilling $config;
    private \CodeIgniter\Database\BaseConnection $db;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $cachedTestPresets = null;
    private ?string $cachedTestPresetsSourceLabel = null;

    public function __construct(
        ?PlatformTenantTsProfilesModel $profiles = null,
        ?TsSecretsService $secrets = null,
        ?TsBilling $config = null
    ) {
        $this->profiles = $profiles ?? new PlatformTenantTsProfilesModel();
        $this->secrets = $secrets ?? new TsSecretsService();
        $this->config = $config ?? config(TsBilling::class);
        $this->db = Database::connect('platform');
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveTenantSettings(int $tenantId): array
    {
        $profile = $this->profiles->findDefaultProfileForTenant($tenantId);
        $testPresets = $this->resolveTestPresets();

        return [
            'profile' => $this->buildViewProfile($profile),
            'sender_types' => $this->config->senderTypes,
            'supported_expense_types' => $this->config->supportedExpenseTypes,
            'supported_expense_details' => $this->config->resolveExpenseTypeDetails(),
            'supported_document_types' => $this->config->supportedDocumentTypes,
            'payment_modes' => $this->config->paymentModes,
            'supported_environments' => array_keys($this->config->environments),
            'test_presets' => $testPresets,
            'test_presets_path' => $this->config->testPresetsPath,
            'test_presets_source_label' => $this->cachedTestPresetsSourceLabel ?? $this->config->testPresetsPath,
            'asset_checks' => $this->resolveAssetChecks(),
        ];
    }

    public function getDefaultProfileForTenant(int $tenantId): ?array
    {
        return $this->profiles->findDefaultProfileForTenant($tenantId);
    }

    /**
     * @return array<string, string>
     */
    public function resolveServiceExpenseTypeMapForTenant(int $tenantId): array
    {
        $profile = $this->profiles->findDefaultProfileForTenant($tenantId);

        return is_array($profile) ? $this->resolveServiceExpenseTypeMap($profile) : [];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, string>
     */
    public function resolveServiceExpenseTypeMap(array $profile): array
    {
        $metadata = $this->decodeMetadata((string) ($profile['metadata_json'] ?? ''));
        $items = $this->normalizeServiceExpenseTypes(
            is_array($metadata['service_expense_types'] ?? null) ? $metadata['service_expense_types'] : []
        );
        $map = [];

        foreach ($items as $item) {
            $key = $this->serviceCatalogKey((string) ($item['description'] ?? ''));
            if ($key !== '') {
                $map[$key] = (string) ($item['expense_type_code'] ?? '');
            }
        }

        return $map;
    }

    /**
     * Restituisce un codice automatico solo quando tutte le prestazioni della fattura
     * sono configurate con lo stesso tipo di spesa TS.
     *
     * @param array<int, array<string, mixed>> $lineItems
     */
    public function resolveExpenseTypeForLineItems(int $tenantId, array $lineItems): ?string
    {
        $map = $this->resolveServiceExpenseTypeMapForTenant($tenantId);
        if ($map === [] || $lineItems === []) {
            return null;
        }

        $resolvedCodes = [];
        foreach ($lineItems as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }

            $key = $this->serviceCatalogKey((string) ($lineItem['description'] ?? ''));
            if ($key === '' || !isset($map[$key])) {
                return null;
            }

            $resolvedCodes[$map[$key]] = true;
        }

        return count($resolvedCodes) === 1 ? (string) array_key_first($resolvedCodes) : null;
    }

    public function findProfileById(int $profileId, ?int $tenantId = null): ?array
    {
        if ($profileId <= 0) {
            return null;
        }

        $profile = $this->profiles->find($profileId);
        if (!is_array($profile)) {
            return null;
        }

        if ($tenantId !== null && $tenantId > 0 && (int) ($profile['id_tenant'] ?? 0) !== $tenantId) {
            return null;
        }

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    public function saveDefaultProfile(int $tenantId, array $payload, int $platformUserId = 0): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Tenant TS non valido.');
        }

        $current = $this->profiles->findDefaultProfileForTenant($tenantId);
        $hasCurrentPassword = trim((string) ($current['auth_password_enc'] ?? '')) !== '';
        $hasCurrentPincode = trim((string) ($current['pincode_enc'] ?? '')) !== '';

        $normalized = $this->normalizePayloadForSave($payload, $current);
        $errors = $this->validateNormalizedState($normalized, !$hasCurrentPassword, !$hasCurrentPincode);
        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors));
        }

        $record = [
            'id_tenant' => $tenantId,
            'profile_name' => $normalized['profile_name'],
            'sender_type' => $normalized['sender_type'],
            'owner_piva' => $normalized['owner_piva'],
            'owner_cf_enc' => $normalized['owner_cf_enc'],
            'owner_cf_hash' => $normalized['owner_cf_hash'],
            'region_code' => $normalized['region_code'],
            'asl_code' => $normalized['asl_code'],
            'ssa_code' => $normalized['ssa_code'],
            'auth_username' => $normalized['auth_username'],
            'auth_password_enc' => $normalized['auth_password_enc'],
            'pincode_enc' => $normalized['pincode_enc'],
            'environment' => $normalized['environment'],
            'is_default' => 1,
            'is_enabled' => $normalized['is_enabled'],
            'metadata_json' => $normalized['metadata_json'],
            'updated_by_platform_user' => $platformUserId > 0 ? $platformUserId : null,
        ];

        if (!$current) {
            $record['created_by_platform_user'] = $platformUserId > 0 ? $platformUserId : null;
        }

        $this->db->transBegin();

        try {
            if ($current) {
                $profileId = (int) ($current['id_ts_profile'] ?? 0);
                if ($profileId <= 0) {
                    throw new \RuntimeException('Profilo TS corrente non valido.');
                }

                $this->profiles->update($profileId, $record);
            } else {
                $profileId = (int) $this->profiles->insert($record);
                if ($profileId <= 0) {
                    throw new \RuntimeException('Creazione profilo TS non riuscita.');
                }
            }

            $this->db->table('platform_tenant_ts_profiles')
                ->where('id_tenant', $tenantId)
                ->where('id_ts_profile <>', $profileId)
                ->update([
                    'is_default' => 0,
                    'updated_by_platform_user' => $platformUserId > 0 ? $platformUserId : null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            if (!$this->db->transStatus()) {
                throw new \RuntimeException('Salvataggio profilo TS non riuscito.');
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        $saved = $this->profiles->findDefaultProfileForTenant($tenantId);
        if ($saved === null) {
            throw new \RuntimeException('Profilo TS salvato ma non più reperibile.');
        }

        return $saved;
    }

    /**
     * @return array<int, string>
     */
    public function validateStoredProfile(array $profile): array
    {
        $metadata = $this->decodeMetadata((string) ($profile['metadata_json'] ?? ''));

        $state = [
            'profile_name' => trim((string) ($profile['profile_name'] ?? '')),
            'sender_type' => trim((string) ($profile['sender_type'] ?? '')),
            'owner_piva' => trim((string) ($profile['owner_piva'] ?? '')),
            'owner_cf' => $this->safeDecrypt((string) ($profile['owner_cf_enc'] ?? '')),
            'region_code' => trim((string) ($profile['region_code'] ?? '')),
            'asl_code' => trim((string) ($profile['asl_code'] ?? '')),
            'ssa_code' => trim((string) ($profile['ssa_code'] ?? '')),
            'auth_username' => trim((string) ($profile['auth_username'] ?? '')),
            'auth_password_enc' => trim((string) ($profile['auth_password_enc'] ?? '')),
            'pincode_enc' => trim((string) ($profile['pincode_enc'] ?? '')),
            'environment' => trim((string) ($profile['environment'] ?? '')),
            'is_enabled' => (int) ($profile['is_enabled'] ?? 0) === 1 ? 1 : 0,
            'credential_mode' => trim((string) ($metadata['credential_mode'] ?? 'manual')) ?: 'manual',
        ];

        return $this->validateNormalizedState($state, true, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resolveAssetChecks(): array
    {
        $checks = [];

        $items = [
            'document_sync_wsdl' => $this->config->documentSyncWsdl,
            'receipts_wsdl' => $this->config->receiptsWsdl,
            'public_cert' => $this->config->publicCertPath,
        ];

        foreach ($items as $key => $path) {
            $checks[] = [
                'key' => $key,
                'path' => $path,
                'exists' => is_string($path) && $path !== '' && is_file($path),
            ];
        }

        return $checks;
    }

    /**
     * @param array<string, mixed>|null $profile
     * @return array<string, mixed>
     */
    private function buildViewProfile(?array $profile): array
    {
        if (!is_array($profile)) {
            return [
                'id_ts_profile' => 0,
                'profile_name' => 'Profilo TS principale',
                'sender_type' => '',
                'owner_piva' => '',
                'owner_cf' => '',
                'region_code' => '',
                'asl_code' => '',
                'ssa_code' => '',
                'auth_username' => '',
                'environment' => 'production',
                'is_enabled' => 1,
                'has_owner_cf' => false,
                'has_auth_password' => false,
                'has_pincode' => false,
                'last_check_status' => '',
                'last_check_message' => '',
                'last_check_at' => '',
                'credential_mode' => 'manual',
                'test_preset_key' => '',
                'test_preset_label' => '',
                'document_defaults' => $this->defaultDocumentDefaults(),
                'service_expense_types' => [],
            ];
        }

        $metadata = $this->decodeMetadata((string) ($profile['metadata_json'] ?? ''));

        return [
            'id_ts_profile' => (int) ($profile['id_ts_profile'] ?? 0),
            'profile_name' => trim((string) ($profile['profile_name'] ?? 'Profilo TS principale')),
            'sender_type' => trim((string) ($profile['sender_type'] ?? '')),
            'owner_piva' => trim((string) ($profile['owner_piva'] ?? '')),
            'owner_cf' => '',
            'region_code' => trim((string) ($profile['region_code'] ?? '')),
            'asl_code' => trim((string) ($profile['asl_code'] ?? '')),
            'ssa_code' => trim((string) ($profile['ssa_code'] ?? '')),
            'auth_username' => trim((string) ($profile['auth_username'] ?? '')),
            'environment' => trim((string) ($profile['environment'] ?? 'test')),
            'is_enabled' => (int) ($profile['is_enabled'] ?? 0) === 1 ? 1 : 0,
            'has_owner_cf' => trim((string) ($profile['owner_cf_enc'] ?? '')) !== '',
            'has_auth_password' => trim((string) ($profile['auth_password_enc'] ?? '')) !== '',
            'has_pincode' => trim((string) ($profile['pincode_enc'] ?? '')) !== '',
            'last_check_status' => trim((string) ($profile['last_check_status'] ?? '')),
            'last_check_message' => trim((string) ($profile['last_check_message'] ?? '')),
            'last_check_at' => trim((string) ($profile['last_check_at'] ?? '')),
            'credential_mode' => trim((string) ($metadata['credential_mode'] ?? 'manual')) ?: 'manual',
            'test_preset_key' => trim((string) ($metadata['test_preset_key'] ?? '')),
            'test_preset_label' => trim((string) ($metadata['test_preset_label'] ?? '')),
            'document_defaults' => $this->normalizeDocumentDefaults([], (array) ($metadata['document_defaults'] ?? [])),
            'service_expense_types' => $this->normalizeServiceExpenseTypes(
                is_array($metadata['service_expense_types'] ?? null) ? $metadata['service_expense_types'] : []
            ),
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return list<array{description: string, expense_type_code: string}>
     */
    private function normalizeServiceExpenseTypes(array $items): array
    {
        $normalized = [];
        $known = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $description = $this->normalizeString($item['description'] ?? '', 190);
            $expenseType = strtoupper($this->normalizeString($item['expense_type_code'] ?? '', 4));
            $key = $this->serviceCatalogKey($description);
            if ($key === '' || isset($known[$key]) || !array_key_exists($expenseType, $this->config->supportedExpenseTypes)) {
                continue;
            }

            $normalized[] = [
                'description' => $description,
                'expense_type_code' => $expenseType,
            ];
            $known[$key] = true;

            if (count($normalized) >= 30) {
                break;
            }
        }

        return $normalized;
    }

    private function serviceCatalogKey(string $description): string
    {
        $description = preg_replace('/\s+/', ' ', trim($description)) ?? '';

        return mb_strtolower($description);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function normalizeDocumentDefaults(array $payload, array $existing = []): array
    {
        $defaults = $this->defaultDocumentDefaults();
        $documentType = strtoupper($this->normalizeString(
            $payload['default_document_type'] ?? ($existing['document_type'] ?? $defaults['document_type']),
            4
        ));
        if (!array_key_exists($documentType, $this->config->supportedDocumentTypes)) {
            $documentType = (string) $defaults['document_type'];
        }

        $expenseType = strtoupper($this->normalizeString(
            $payload['default_expense_type_code'] ?? ($existing['expense_type_code'] ?? $defaults['expense_type_code']),
            4
        ));
        if (!array_key_exists($expenseType, $this->config->supportedExpenseTypes)) {
            $expenseType = (string) $defaults['expense_type_code'];
        }

        $paymentMode = strtolower($this->normalizeString(
            $payload['default_payment_mode'] ?? ($existing['payment_mode'] ?? $defaults['payment_mode']),
            20
        ));
        if (!array_key_exists($paymentMode, $this->config->paymentModes)) {
            $paymentMode = (string) $defaults['payment_mode'];
        }

        $oppositionValue = array_key_exists('default_opposition_flag', $payload)
            ? $payload['default_opposition_flag']
            : ($existing['opposition_flag'] ?? $defaults['opposition_flag']);

        return [
            'document_type' => $documentType,
            'expense_type_code' => $expenseType,
            'payment_mode' => $paymentMode,
            'opposition_flag' => in_array(strtolower(trim((string) $oppositionValue)), ['1', 'true', 'on', 'yes'], true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultDocumentDefaults(): array
    {
        return [
            'document_type' => 'F',
            'expense_type_code' => 'SP',
            'payment_mode' => 'tracciato',
            'opposition_flag' => false,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function normalizePayloadForSave(array $payload, ?array $current): array
    {
        $currentMetadata = $this->decodeMetadata((string) ($current['metadata_json'] ?? ''));
        $profileName = $this->normalizeString($payload['profile_name'] ?? 'Profilo TS principale', 120);
        $senderType = $this->normalizeString($payload['sender_type'] ?? '', 40);
        $ownerPiva = preg_replace('/\D+/', '', (string) ($payload['owner_piva'] ?? '')) ?? '';
        $ownerCf = $this->normalizeUpperString($payload['owner_cf'] ?? '');
        $regionCode = $this->normalizeUpperString($payload['region_code'] ?? '', 3);
        $aslCode = $this->normalizeUpperString($payload['asl_code'] ?? '', 3);
        $ssaCode = $this->normalizeUpperString($payload['ssa_code'] ?? '', 6);
        $authUsername = $this->normalizeString($payload['auth_username'] ?? '', 120);
        $authPassword = (string) ($payload['auth_password'] ?? '');
        $pincode = trim((string) ($payload['pincode'] ?? ''));
        // Il profilo cliente usa sempre l’endpoint operativo: nessuna scelta ambiente in interfaccia.
        $environment = 'production';

        $metadata = $currentMetadata;
        $metadata['credential_mode'] = 'manual';
        $metadata['test_preset_key'] = '';
        $metadata['test_preset_label'] = '';
        $metadata['test_preset_source'] = '';
        $metadata['document_defaults'] = $this->normalizeDocumentDefaults(
            $payload,
            is_array($currentMetadata['document_defaults'] ?? null) ? $currentMetadata['document_defaults'] : []
        );
        $metadata['service_expense_types'] = $this->normalizeServiceExpenseTypes(
            is_array($payload['service_expense_types'] ?? null)
                ? $payload['service_expense_types']
                : (is_array($currentMetadata['service_expense_types'] ?? null) ? $currentMetadata['service_expense_types'] : [])
        );

        foreach (['test_preset_key', 'test_preset_label', 'test_preset_source'] as $metadataKey) {
            if (trim((string) ($metadata[$metadataKey] ?? '')) === '') {
                unset($metadata[$metadataKey]);
            }
        }

        return [
            'profile_name' => $profileName !== '' ? $profileName : 'Profilo TS principale',
            'sender_type' => $senderType,
            'owner_piva' => $ownerPiva,
            'owner_cf' => $ownerCf,
            'owner_cf_enc' => $ownerCf !== ''
                ? $this->secrets->encrypt($ownerCf)
                : (trim((string) ($current['owner_cf_enc'] ?? '')) !== '' ? (string) ($current['owner_cf_enc'] ?? '') : null),
            'owner_cf_hash' => $ownerCf !== ''
                ? $this->secrets->hashForExactMatch($ownerCf)
                : (trim((string) ($current['owner_cf_hash'] ?? '')) !== '' ? (string) ($current['owner_cf_hash'] ?? '') : null),
            'region_code' => $regionCode,
            'asl_code' => $aslCode,
            'ssa_code' => $ssaCode,
            'auth_username' => $authUsername,
            'auth_password_enc' => $authPassword !== ''
                ? $this->secrets->encrypt($authPassword)
                : (trim((string) ($current['auth_password_enc'] ?? '')) !== '' ? (string) ($current['auth_password_enc'] ?? '') : null),
            'pincode_enc' => $pincode !== ''
                ? $this->secrets->encrypt($pincode)
                : (trim((string) ($current['pincode_enc'] ?? '')) !== '' ? (string) ($current['pincode_enc'] ?? '') : null),
            'environment' => $environment,
            'is_enabled' => (int) ($payload['is_enabled'] ?? 0) === 1 ? 1 : 0,
            'credential_mode' => trim((string) ($metadata['credential_mode'] ?? 'manual')) ?: 'manual',
            'metadata_json' => $this->encodeMetadata($metadata),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<int, string>
     */
    private function validateNormalizedState(array $state, bool $requirePassword, bool $requirePincode): array
    {
        $errors = [];
        $environment = trim((string) ($state['environment'] ?? ''));
        $credentialMode = trim((string) ($state['credential_mode'] ?? 'manual')) ?: 'manual';
        $isOfficialTestPreset = $environment === 'test' && $credentialMode === 'official_test_preset';

        if (trim((string) ($state['profile_name'] ?? '')) === '') {
            $errors[] = 'Inserisci un nome profilo TS.';
        }

        if (trim((string) ($state['sender_type'] ?? '')) === '') {
            $errors[] = 'Seleziona il tipo soggetto TS.';
        } elseif (!array_key_exists((string) ($state['sender_type'] ?? ''), $this->config->senderTypes)) {
            $errors[] = 'Il tipo soggetto TS selezionato non è supportato.';
        }

        $ownerPiva = trim((string) ($state['owner_piva'] ?? ''));
        if ($ownerPiva === '') {
            $errors[] = 'Inserisci la Partita IVA dell’erogatore.';
        } elseif (!$this->isValidPartitaIva($ownerPiva) && !$isOfficialTestPreset) {
            $errors[] = 'La Partita IVA inserita non è formalmente valida.';
        }

        $ownerCf = trim((string) ($state['owner_cf'] ?? ''));
        if ($ownerCf !== '' && !$this->isPlausibleCodiceFiscale($ownerCf) && !$isOfficialTestPreset) {
            $errors[] = 'Il Codice Fiscale del titolare non è formalmente valido.';
        }

        if (trim((string) ($state['auth_username'] ?? '')) === '') {
            $errors[] = 'Inserisci lo username TS.';
        }

        if ($requirePassword && trim((string) ($state['auth_password_enc'] ?? '')) === '') {
            $errors[] = 'Inserisci la password TS.';
        }

        if ($requirePincode && trim((string) ($state['pincode_enc'] ?? '')) === '') {
            $errors[] = 'Inserisci il PINCODE TS.';
        }

        if (!in_array($environment, ['test', 'production'], true)) {
            $errors[] = 'Seleziona un ambiente TS valido.';
        }

        return $errors;
    }

    private function isValidPartitaIva(string $value): bool
    {
        if (!preg_match('/^\d{11}$/', $value)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $value[$i];
            if (($i % 2) === 0) {
                $sum += $digit;
                continue;
            }

            $double = $digit * 2;
            $sum += $double > 9 ? $double - 9 : $double;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        return $checkDigit === (int) $value[10];
    }

    private function isPlausibleCodiceFiscale(string $value): bool
    {
        return (bool) preg_match('/^(?:[A-Z0-9]{16}|\d{11})$/', strtoupper(trim($value)));
    }

    private function normalizeEnvironment($value): string
    {
        $value = trim(strtolower((string) $value));

        return in_array($value, ['test', 'production'], true) ? $value : 'test';
    }

    private function normalizeString($value, int $maxLength): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function normalizeUpperString($value, int $maxLength = 64): string
    {
        $value = strtoupper($this->normalizeString($value, $maxLength));

        return preg_replace('/\s+/', '', $value) ?? $value;
    }

    private function safeDecrypt(string $payload): string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return '';
        }

        try {
            return trim((string) $this->secrets->decrypt($payload));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveTestPresets(): array
    {
        if (is_array($this->cachedTestPresets)) {
            return $this->cachedTestPresets;
        }

        $raw = $this->resolveTestPresetsRaw();
        if (trim($raw) === '') {
            $this->cachedTestPresets = [];

            return $this->cachedTestPresets;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->cachedTestPresets = [];

            return $this->cachedTestPresets;
        }

        $globalSource = $this->normalizeString($decoded['source'] ?? '', 255);
        $presets = is_array($decoded['presets'] ?? null) ? $decoded['presets'] : [];
        $resolved = [];

        foreach ($presets as $preset) {
            if (!is_array($preset)) {
                continue;
            }

            $sanitized = $this->sanitizeTestPreset($preset, $globalSource);
            if ($sanitized !== null) {
                $resolved[] = $sanitized;
            }
        }

        $this->cachedTestPresets = $resolved;

        return $this->cachedTestPresets;
    }

    private function resolveTestPresetsRaw(): string
    {
        $inlineBase64 = trim((string) env('TS_BILLING_TEST_PRESETS_INLINE_B64', ''));
        if ($inlineBase64 !== '') {
            $decoded = base64_decode($inlineBase64, true);
            if (is_string($decoded) && trim($decoded) !== '') {
                $this->cachedTestPresetsSourceLabel = 'env:TS_BILLING_TEST_PRESETS_INLINE_B64';

                return $decoded;
            }
        }

        $path = trim($this->config->testPresetsPath);
        $this->cachedTestPresetsSourceLabel = $path;
        if ($path === '' || !is_file($path)) {
            return '';
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return '';
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTestPresetByKey(string $key): ?array
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        foreach ($this->resolveTestPresets() as $preset) {
            if (trim((string) ($preset['key'] ?? '')) === $key) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $preset
     * @return array<string, mixed>|null
     */
    private function sanitizeTestPreset(array $preset, string $globalSource = ''): ?array
    {
        $key = $this->normalizeString($preset['key'] ?? '', 120);
        if ($key === '') {
            return null;
        }

        $label = $this->normalizeString($preset['label'] ?? '', 160);
        $senderType = $this->normalizeString($preset['sender_type'] ?? '', 40);
        if ($label === '' || $senderType === '') {
            return null;
        }
        if (!array_key_exists($senderType, $this->config->senderTypes)) {
            return null;
        }

        $officeCode = $this->normalizeString($preset['office_code'] ?? '', 32);
        $regionCode = $this->normalizeUpperString($preset['region_code'] ?? '', 3);
        $aslCode = $this->normalizeUpperString($preset['asl_code'] ?? '', 3);
        $ssaCode = $this->normalizeUpperString($preset['ssa_code'] ?? '', 6);

        if ($officeCode !== '' && ($regionCode === '' || $aslCode === '' || $ssaCode === '')) {
            $parsedOfficeCode = $this->parseOfficeCode($officeCode);
            if ($regionCode === '') {
                $regionCode = $parsedOfficeCode['region_code'];
            }
            if ($aslCode === '') {
                $aslCode = $parsedOfficeCode['asl_code'];
            }
            if ($ssaCode === '') {
                $ssaCode = $parsedOfficeCode['ssa_code'];
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'profile_name' => $this->normalizeString($preset['profile_name'] ?? $label, 120),
            'sender_type' => $senderType,
            'environment' => $this->normalizeEnvironment($preset['environment'] ?? 'test'),
            'owner_piva' => preg_replace('/\D+/', '', (string) ($preset['owner_piva'] ?? '')) ?? '',
            'owner_cf' => $this->normalizeUpperString($preset['owner_cf'] ?? ''),
            'region_code' => $regionCode,
            'asl_code' => $aslCode,
            'ssa_code' => $ssaCode,
            'auth_username' => $this->normalizeString($preset['auth_username'] ?? '', 120),
            'auth_password' => trim((string) ($preset['auth_password'] ?? '')),
            'pincode' => trim((string) ($preset['pincode'] ?? '')),
            'office_code' => $officeCode,
            'notes' => $this->normalizeString($preset['notes'] ?? '', 255),
            'source' => $this->normalizeString($preset['source'] ?? $globalSource, 255),
        ];
    }

    /**
     * @return array{region_code:string, asl_code:string, ssa_code:string}
     */
    private function parseOfficeCode(string $officeCode): array
    {
        $officeCode = trim($officeCode);
        if (!preg_match('/^([A-Z0-9]{3})-([A-Z0-9]{3})-([A-Z0-9]{5,6})$/', $officeCode, $matches)) {
            return [
                'region_code' => '',
                'asl_code' => '',
                'ssa_code' => '',
            ];
        }

        return [
            'region_code' => $matches[1],
            'asl_code' => $matches[2],
            'ssa_code' => $matches[3],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(string $payload): array
    {
        $payload = trim($payload);
        if ($payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function encodeMetadata(array $metadata): ?string
    {
        $filtered = [];
        foreach ($metadata as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $filtered[$key] = $value;
        }

        if ($filtered === []) {
            return null;
        }

        $encoded = json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }
}
