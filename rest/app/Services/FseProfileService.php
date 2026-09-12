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
        $this->db = $this->profiles->db;
    }

    public function getDefaultProfileForTenant(int $tenantId): ?array
    {
        return $this->profiles->findDefaultProfileForTenant($tenantId);
    }

    /** @return array<string,mixed> */
    public function getProfileForTenant(int $tenantId, int $profileId): ?array
    {
        return $this->profiles->where('id_tenant', $tenantId)->where('id_fse_profile', $profileId)->first();
    }

    public function listForTenant(int $tenantId): array
    {
        return array_map(fn($row) => $this->viewProfile($row), $this->profiles->where('id_tenant', $tenantId)
            ->orderBy('is_default', 'DESC')->orderBy('profile_name', 'ASC')->findAll());
    }

    public function resolveTenantSettings(int $tenantId, int $profileId = 0, bool $create = false): array
    {
        $profile = $create ? null : ($profileId > 0 ? $this->getProfileForTenant($tenantId, $profileId) : $this->getDefaultProfileForTenant($tenantId));
        if ($profileId > 0 && !$profile) throw new \RuntimeException('Profilo FSE non disponibile per questo spazio.');
        $view = $this->viewProfile($profile);
        return [
            'profile' => $view,
            'profiles' => $this->listForTenant($tenantId),
            'onboarding' => (new FseOnboardingService())->check($view),
            'environments' => array_keys($this->config->environments),
            'facility_types' => $this->config->facilityTypes,
            'document_types' => $this->config->documentTypes,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveDefaultProfile(int $tenantId, array $payload, int $platformUserId = 0): array
    {
        $current = $this->getDefaultProfileForTenant($tenantId);
        return $this->saveProfile($tenantId, $payload, (int) ($current['id_fse_profile'] ?? 0), $platformUserId, true);
    }

    /** Edits are tenant-scoped and optimistic; a profile does not grant an accreditation. */
    public function saveProfile(int $tenantId, array $payload, int $profileId = 0, int $platformUserId = 0, bool $makeDefault = false): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Spazio FSE non valido.');
        }
        $current = $profileId > 0 ? $this->getProfileForTenant($tenantId, $profileId) : null;
        if ($profileId > 0 && !$current) throw new \RuntimeException('Profilo FSE non disponibile per questo spazio.');
        $metadata = json_decode((string) ($current['metadata_json'] ?? ''), true) ?: [];
        if ($current && !hash_equals((string) ($metadata['edit_token'] ?? ''), (string) ($payload['profile_edit_token'] ?? ''))) {
            throw new \RuntimeException('Profilo modificato da un’altra richiesta: ricaricare la pagina.');
        }
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
            'jwt_audience' => $read('jwt_audience', 500),
        ];
        $regime = $read('care_regime', 12);
        if ($regime !== '' && !array_key_exists($regime, $this->config->administrativeRequests)) throw new \RuntimeException('Regime FSE non riconosciuto.');
        $errors = $this->validate($state, $enabled === 1);
        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors));
        }

        $record = $state;
        unset($record['author_cf'], $record['jwt_audience']);
        $record['id_tenant'] = $tenantId;
        $record['author_cf_enc'] = $authorCf !== '' ? $this->secrets->encrypt($authorCf) : null;
        $record['author_cf_hash'] = $authorCf !== '' ? hash('sha256', $authorCf) : null;
        $record['auth_private_key_passphrase_enc'] = $this->secretValue($payload, 'auth_private_key_passphrase', $current, 'auth_private_key_passphrase_enc');
        $record['signature_private_key_passphrase_enc'] = $this->secretValue($payload, 'signature_private_key_passphrase', $current, 'signature_private_key_passphrase_enc');
        $record['is_default'] = $makeDefault || !empty($current['is_default']) || !$this->getDefaultProfileForTenant($tenantId) ? 1 : 0;
        $record['metadata_json'] = json_encode(['document_type' => 'RSA', 'site_code' => $read('site_code', 80),
            'care_regime' => $regime, 'jwt_audience' => $state['jwt_audience'], 'edit_token' => bin2hex(random_bytes(16))], JSON_UNESCAPED_SLASHES);
        $record['updated_by_platform_user'] = $platformUserId > 0 ? $platformUserId : null;
        if (!$current) {
            $record['created_by_platform_user'] = $platformUserId > 0 ? $platformUserId : null;
        }

        $this->db->transBegin();
        try {
            // Serialize default changes per tenant on MySQL, including concurrent new profiles.
            if ($this->db->DBDriver === 'MySQLi') {
                $this->db->query('SELECT id_tenant FROM platform_tenants WHERE id_tenant = ? FOR UPDATE', [$tenantId]);
                if (!$current && !$makeDefault) $record['is_default'] = $this->getDefaultProfileForTenant($tenantId) ? 0 : 1;
            }
            if ($current) {
                $profileId = (int) $current['id_fse_profile'];
                $record['updated_at'] = date('Y-m-d H:i:s');
                $this->db->table('platform_tenant_fse_profiles')->where('id_tenant', $tenantId)->where('id_fse_profile', $profileId)
                    ->where('metadata_json', $current['metadata_json'])->update($record);
                if ($this->db->affectedRows() !== 1) throw new \RuntimeException('Profilo modificato: ricaricare la pagina.');
            } else {
                $profileId = (int) $this->profiles->insert($record);
            }
            if ($profileId <= 0) {
                throw new \RuntimeException('Salvataggio profilo FSE non riuscito.');
            }
            if ($record['is_default']) {
                $previousDefaults = $this->db->table('platform_tenant_fse_profiles')->where('id_tenant', $tenantId)
                    ->where('id_fse_profile <>', $profileId)->where('is_default', 1)->get()->getResultArray();
                foreach ($previousDefaults as $previousDefault) {
                    // Demoting a profile also invalidates forms opened before the
                    // default change, so an old checked checkbox cannot undo it.
                    $previousMetadata = json_decode((string) ($previousDefault['metadata_json'] ?? ''), true) ?: [];
                    $previousMetadata['edit_token'] = bin2hex(random_bytes(16));
                    $this->db->table('platform_tenant_fse_profiles')->where('id_tenant', $tenantId)
                        ->where('id_fse_profile', $previousDefault['id_fse_profile'])->update(['is_default' => 0,
                            'metadata_json' => json_encode($previousMetadata, JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')]);
                }
            }
            if (!$this->db->transStatus()) {
                throw new \RuntimeException('Transazione profilo FSE non riuscita.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
        return $this->getProfileForTenant($tenantId, $profileId) ?? throw new \RuntimeException('Profilo FSE non reperibile.');
    }

    /** @return array<string,mixed> */
    public function runtimeProfileForTenant(int $tenantId, int $profileId = 0): array
    {
        $profile = $profileId > 0 ? $this->getProfileForTenant($tenantId, $profileId) : $this->getDefaultProfileForTenant($tenantId);
        if (!is_array($profile)) {
            throw new \RuntimeException('Configura prima il profilo FSE 2.0 dello spazio.');
        }
        $profile['author_cf'] = $this->safeDecrypt((string) ($profile['author_cf_enc'] ?? ''));
        $profile['auth_private_key_passphrase'] = $this->safeDecrypt((string) ($profile['auth_private_key_passphrase_enc'] ?? ''));
        $profile['signature_private_key_passphrase'] = $this->safeDecrypt((string) ($profile['signature_private_key_passphrase_enc'] ?? ''));
        $metadata = json_decode((string) ($profile['metadata_json'] ?? ''), true) ?: [];
        foreach (['site_code', 'care_regime', 'jwt_audience'] as $key) $profile[$key] = (string) ($metadata[$key] ?? '');
        $profile['gateway_base_url'] = $this->config->gatewayUrlForProfile($profile);
        return $profile;
    }

    public function runtimeProfileForDocument(int $tenantId, array $document): array
    {
        $id = (int) ($document['id_fse_profile'] ?? 0);
        if ($id <= 0) throw new \RuntimeException('Referto senza profilo associato: nessun profilo predefinito applicato automaticamente.');
        $profile = $this->runtimeProfileForTenant($tenantId, $id);
        $snapshot = json_decode((string) ($document['profile_snapshot_json'] ?? ''), true);
        if (!empty($document['profile_snapshot_json']) && !is_array($snapshot)) throw new \RuntimeException('Configurazione storica del referto non leggibile.');
        if (is_array($snapshot)) {
            if ((int) ($snapshot['id_fse_profile'] ?? 0) !== $id) throw new \RuntimeException('Associazione profilo del referto incoerente.');
            // Routing and organisation frozen at draft creation; credentials/disable flag remain current.
            $profile = array_replace($profile, array_intersect_key($snapshot, self::snapshot($profile)));
        }
        return $profile;
    }

    public static function snapshot(array $profile): array
    {
        return array_intersect_key($profile, array_flip(['id_fse_profile', 'profile_name', 'access_mode', 'environment',
            'gateway_base_url', 'region_code', 'organization_id', 'organization_name', 'facility_name', 'facility_code',
            'facility_oid', 'locality', 'facility_type', 'organizational_setting', 'clinical_activity', 'repository_id',
            'document_oid_root', 'submission_oid_root', 'subject_role', 'app_vendor', 'app_id', 'app_version',
            'site_code', 'care_regime', 'jwt_audience']));
    }

    /** @param array<string,mixed> $state @return list<string> */
    public function validate(array $state, bool $gatewayReady): array
    {
        $errors = [];
        try {
            $this->config->gatewayUrlForProfile($state);
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
        if ($gatewayReady && ($state['access_mode'] ?? '') === 'toscana_privati') {
            $errors[] = 'Toscana Privati: disponibile solo il trasporto di collaudo; workflow operatore e abilitazione reale non ancora disponibili.';
        }
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
        $metadata = json_decode((string) ($profile['metadata_json'] ?? ''), true) ?: [];
        foreach (['site_code', 'care_regime', 'jwt_audience'] as $key) $profile[$key] = (string) ($metadata[$key] ?? '');
        $profile['profile_edit_token'] = (string) ($metadata['edit_token'] ?? '');
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
