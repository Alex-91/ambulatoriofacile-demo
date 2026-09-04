<?php

namespace App\Services;

use App\Config\Fse2;
use App\Models\PlatformTenantFseProfilesModel;
use Config\Database;

class FseProfileService
{
    private PlatformTenantFseProfilesModel $profiles;
    private FseSecretsService $secrets;
    private Fse2 $config;
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct(?PlatformTenantFseProfilesModel $profiles = null, ?FseSecretsService $secrets = null, ?Fse2 $config = null)
    {
        $this->profiles = $profiles ?? new PlatformTenantFseProfilesModel();
        $this->secrets = $secrets ?? new FseSecretsService();
        $this->config = $config ?? config(Fse2::class);
        $this->db = Database::connect('platform');
    }

    public function getDefaultProfileForTenant(int $tenantId): ?array
    {
        return $this->profiles->findDefaultProfileForTenant($tenantId);
    }

    /** @return array<string,mixed> */
    public function resolveTenantSettings(int $tenantId): array
    {
        return [
            'profile' => $this->viewProfile($this->getDefaultProfileForTenant($tenantId)),
            'environments' => array_keys($this->config->environments),
            'facility_types' => $this->config->facilityTypes,
            'document_types' => $this->config->documentTypes,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveDefaultProfile(int $tenantId, array $payload, int $platformUserId = 0): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Spazio FSE non valido.');
        }
        $current = $this->getDefaultProfileForTenant($tenantId);
        $read = static fn(string $key, int $max = 255): string => substr(trim((string) ($payload[$key] ?? '')), 0, $max);
        $environment = strtolower($read('environment', 16));
        if (!isset($this->config->environments[$environment])) {
            $environment = 'test';
        }
        $enabled = !empty($payload['is_enabled']) ? 1 : 0;
        $authorCf = strtoupper(preg_replace('/\s+/', '', $read('author_cf', 16)) ?? '');
        if ($authorCf === '' && is_array($current)) {
            $authorCf = $this->safeDecrypt((string) ($current['author_cf_enc'] ?? ''));
        }
        $state = [
            'profile_name' => $read('profile_name', 120) ?: 'Profilo FSE 2.0',
            'access_mode' => $read('access_mode', 24) ?: 'gateway',
            'environment' => $environment,
            'gateway_base_url' => rtrim($read('gateway_base_url'), '/'),
            'region_code' => strtoupper($read('region_code', 3)),
            'organization_id' => $read('organization_id', 10),
            'organization_name' => $read('organization_name', 160),
            'facility_name' => $read('facility_name', 180),
            'facility_code' => $read('facility_code', 30),
            'facility_oid' => $read('facility_oid', 120),
            'locality' => $read('locality', 500),
            'facility_type' => $read('facility_type', 30) ?: 'Territorio',
            'organizational_setting' => $read('organizational_setting', 40),
            'clinical_activity' => strtoupper($read('clinical_activity', 12)) ?: 'ERP',
            'repository_id' => $read('repository_id', 120),
            'document_oid_root' => $read('document_oid_root', 120),
            'submission_oid_root' => $read('submission_oid_root', 120),
            'subject_role' => strtoupper($read('subject_role', 12)) ?: 'DRS',
            'author_cf' => $authorCf,
            'author_first_name' => $read('author_first_name', 100),
            'author_last_name' => $read('author_last_name', 100),
            'app_vendor' => $read('app_vendor', 120) ?: 'AmbulatorioFacile',
            'app_id' => $read('app_id', 120) ?: 'AMBULATORIOFACILE',
            'app_version' => $read('app_version', 40) ?: '1.0.0',
            'auth_certificate_path' => $read('auth_certificate_path'),
            'auth_private_key_path' => $read('auth_private_key_path'),
            'signature_certificate_path' => $read('signature_certificate_path'),
            'signature_private_key_path' => $read('signature_private_key_path'),
            'is_enabled' => $enabled,
        ];
        $errors = $this->validate($state, $enabled === 1);
        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors));
        }

        $record = $state;
        unset($record['author_cf']);
        $record['id_tenant'] = $tenantId;
        $record['author_cf_enc'] = $authorCf !== '' ? $this->secrets->encrypt($authorCf) : null;
        $record['author_cf_hash'] = $authorCf !== '' ? hash('sha256', $authorCf) : null;
        $record['auth_private_key_passphrase_enc'] = $this->secretValue($payload, 'auth_private_key_passphrase', $current, 'auth_private_key_passphrase_enc');
        $record['signature_private_key_passphrase_enc'] = $this->secretValue($payload, 'signature_private_key_passphrase', $current, 'signature_private_key_passphrase_enc');
        $record['is_default'] = 1;
        $record['metadata_json'] = json_encode(['document_type' => 'RSA'], JSON_UNESCAPED_SLASHES);
        $record['updated_by_platform_user'] = $platformUserId > 0 ? $platformUserId : null;
        if (!$current) {
            $record['created_by_platform_user'] = $platformUserId > 0 ? $platformUserId : null;
        }

        $this->db->transBegin();
        try {
            if ($current) {
                $profileId = (int) $current['id_fse_profile'];
                $this->profiles->update($profileId, $record);
            } else {
                $profileId = (int) $this->profiles->insert($record);
            }
            if ($profileId <= 0) {
                throw new \RuntimeException('Salvataggio profilo FSE non riuscito.');
            }
            $this->db->table('platform_tenant_fse_profiles')->where('id_tenant', $tenantId)
                ->where('id_fse_profile <>', $profileId)->update(['is_default' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
            if (!$this->db->transStatus()) {
                throw new \RuntimeException('Transazione profilo FSE non riuscita.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
        return $this->getDefaultProfileForTenant($tenantId) ?? throw new \RuntimeException('Profilo FSE non reperibile.');
    }

    /** @return array<string,mixed> */
    public function runtimeProfileForTenant(int $tenantId): array
    {
        $profile = $this->getDefaultProfileForTenant($tenantId);
        if (!is_array($profile)) {
            throw new \RuntimeException('Configura prima il profilo FSE 2.0 dello spazio.');
        }
        $profile['author_cf'] = $this->safeDecrypt((string) ($profile['author_cf_enc'] ?? ''));
        $profile['auth_private_key_passphrase'] = $this->safeDecrypt((string) ($profile['auth_private_key_passphrase_enc'] ?? ''));
        $profile['signature_private_key_passphrase'] = $this->safeDecrypt((string) ($profile['signature_private_key_passphrase_enc'] ?? ''));
        $profile['gateway_base_url'] = $this->config->gatewayUrl((string) $profile['environment'], (string) ($profile['gateway_base_url'] ?? ''));
        return $profile;
    }

    /** @param array<string,mixed> $state @return list<string> */
    public function validate(array $state, bool $gatewayReady): array
    {
        $errors = [];
        if (($state['environment'] ?? '') === 'production' && !$this->config->allowProduction) {
            $errors[] = 'La produzione richiede FSE2_ALLOW_PRODUCTION=true.';
        }
        if (($state['author_cf'] ?? '') !== '' && !preg_match('/^[A-Z0-9]{11,16}$/', (string) $state['author_cf'])) {
            $errors[] = 'Codice fiscale autore non valido.';
        }
        if ($gatewayReady) {
            foreach (['region_code', 'organization_id', 'organization_name', 'facility_name', 'facility_code', 'facility_oid', 'locality', 'organizational_setting', 'repository_id', 'document_oid_root', 'submission_oid_root', 'author_cf', 'author_first_name', 'author_last_name', 'app_vendor', 'app_id', 'app_version', 'auth_certificate_path', 'auth_private_key_path', 'signature_certificate_path', 'signature_private_key_path'] as $field) {
                if (trim((string) ($state[$field] ?? '')) === '') {
                    $errors[] = 'Campo FSE obbligatorio mancante: ' . $field . '.';
                }
            }
        }
        return $errors;
    }

    /** @param array<string,mixed>|null $profile @return array<string,mixed> */
    private function viewProfile(?array $profile): array
    {
        $profile = $profile ?? [];
        $profile['author_cf'] = $this->safeDecrypt((string) ($profile['author_cf_enc'] ?? ''));
        $profile['has_auth_passphrase'] = trim((string) ($profile['auth_private_key_passphrase_enc'] ?? '')) !== '';
        $profile['has_signature_passphrase'] = trim((string) ($profile['signature_private_key_passphrase_enc'] ?? '')) !== '';
        unset($profile['author_cf_enc'], $profile['auth_private_key_passphrase_enc'], $profile['signature_private_key_passphrase_enc']);
        return $profile;
    }

    /** @param array<string,mixed> $payload @param array<string,mixed>|null $current */
    private function secretValue(array $payload, string $input, ?array $current, string $column): ?string
    {
        $value = (string) ($payload[$input] ?? '');
        return $value !== '' ? $this->secrets->encrypt($value) : (is_array($current) ? ($current[$column] ?? null) : null);
    }

    private function safeDecrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            return $this->secrets->decrypt($value);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
