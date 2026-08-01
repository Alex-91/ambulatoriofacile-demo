<?php

namespace App\Services;

use App\Config\TsBilling;
use App\Models\PlatformTenantTsProfilesModel;

class TsHealthcheckService
{
    private PlatformTenantTsProfilesModel $profiles;
    private TsProfileService $profileService;
    private TsFeatureService $featureService;
    private TsSecretsService $secrets;
    private TsMigrationSafetyService $migrationSafety;
    private TsSupportLogService $supportLogs;
    private TsBilling $config;

    public function __construct(
        ?PlatformTenantTsProfilesModel $profiles = null,
        ?TsProfileService $profileService = null,
        ?TsFeatureService $featureService = null,
        ?TsSecretsService $secrets = null,
        ?TsMigrationSafetyService $migrationSafety = null,
        ?TsSupportLogService $supportLogs = null,
        ?TsBilling $config = null
    ) {
        $this->profiles = $profiles ?? new PlatformTenantTsProfilesModel();
        $this->profileService = $profileService ?? new TsProfileService($this->profiles);
        $this->featureService = $featureService ?? new TsFeatureService();
        $this->secrets = $secrets ?? new TsSecretsService();
        $this->migrationSafety = $migrationSafety ?? new TsMigrationSafetyService();
        $this->supportLogs = $supportLogs ?? new TsSupportLogService();
        $this->config = $config ?? config(TsBilling::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function runForTenant(int $tenantId): array
    {
        $supportLog = $this->supportLogs->startOperation($tenantId, 'tenant_healthcheck', [
            'tenant_id' => $tenantId,
        ]);
        $profile = $this->profiles->findDefaultProfileForTenant($tenantId);
        if ($profile === null) {
            $supportLog->step('profile_missing', 'Nessun profilo TS salvato per questo studio.', [], 'error');
            $supportReference = $supportLog->finish('error', 'Healthcheck TS fallito: profilo assente.', []);
            return [
                'status' => 'error',
                'message' => 'Nessun profilo TS salvato per questo studio.',
                'errors' => ['Salva prima il profilo TS dello studio.'],
                'warnings' => [],
                'checks' => [],
                'support_log' => $supportReference,
            ];
        }

        $supportLog->step('profile_loaded', 'Profilo TS default caricato per l’healthcheck.', [
            'profile_id' => (int) ($profile['id_ts_profile'] ?? 0),
            'environment' => trim((string) ($profile['environment'] ?? '')),
            'sender_type' => trim((string) ($profile['sender_type'] ?? '')),
            'is_enabled' => (int) ($profile['is_enabled'] ?? 0),
        ]);

        $errors = [];
        $warnings = [];
        $checks = [];

        $featureEnabled = $this->featureService->isEnabledForTenant($tenantId);
        $checks[] = [
            'label' => 'Feature Sistema TS attiva per lo studio',
            'status' => $featureEnabled ? 'ok' : 'error',
            'message' => $featureEnabled
                ? 'Lo studio ha il modulo TS attivo.'
                : 'Lo studio non ha la feature Sistema TS attiva.',
        ];
        if (!$featureEnabled) {
            $errors[] = 'Lo studio non ha la feature Sistema TS attiva.';
        }
        $supportLog->step('feature_checked', 'Controllo feature Sistema TS completato.', [
            'feature_enabled' => $featureEnabled,
        ], $featureEnabled ? 'info' : 'warning');

        $profileErrors = $this->profileService->validateStoredProfile($profile);
        foreach ($profileErrors as $errorMessage) {
            $errors[] = $errorMessage;
        }
        $checks[] = [
            'label' => 'Profilo TS compilato',
            'status' => $profileErrors === [] ? 'ok' : 'error',
            'message' => $profileErrors === []
                ? 'I campi minimi del profilo risultano compilati.'
                : implode(' ', $profileErrors),
        ];
        $supportLog->step('profile_validated', 'Validazione profilo TS completata.', [
            'profile_errors' => $profileErrors,
        ], $profileErrors === [] ? 'info' : 'warning');

        $secretChecks = [
            'password TS' => (string) ($profile['auth_password_enc'] ?? ''),
            'PINCODE TS' => (string) ($profile['pincode_enc'] ?? ''),
        ];

        if (trim((string) ($profile['owner_cf_enc'] ?? '')) !== '') {
            $secretChecks['Codice Fiscale titolare'] = (string) ($profile['owner_cf_enc'] ?? '');
        }

        foreach ($secretChecks as $label => $payload) {
            if (trim($payload) === '') {
                continue;
            }

            $decryptable = $this->isSecretDecryptable($payload);
            $checks[] = [
                'label' => $label,
                'status' => $decryptable ? 'ok' : 'error',
                'message' => $decryptable
                    ? 'Valore cifrato integro e decifrabile.'
                    : 'Valore cifrato non decifrabile con la chiave locale attuale.',
            ];

            if (!$decryptable) {
                $errors[] = 'Il segreto "' . $label . '" non è decifrabile con la chiave locale attuale.';
            }
        }
        $supportLog->step('secrets_checked', 'Controllo segreti cifrati completato.', [
            'checked_secrets' => array_keys($secretChecks),
        ]);

        $soapLoaded = extension_loaded('soap');
        $checks[] = [
            'label' => 'Estensione PHP SOAP',
            'status' => $soapLoaded ? 'ok' : 'error',
            'message' => $soapLoaded
                ? 'SOAP disponibile nel runtime PHP attuale.'
                : 'L’estensione SOAP non risulta caricata in questo runtime.',
        ];
        if (!$soapLoaded) {
            $errors[] = 'L’estensione SOAP non risulta caricata nel runtime.';
        }
        $supportLog->step('soap_extension_checked', 'Verifica runtime SOAP completata.', [
            'soap_loaded' => $soapLoaded,
        ], $soapLoaded ? 'info' : 'error');

        foreach ($this->profileService->resolveAssetChecks() as $assetCheck) {
            $isRequired = in_array((string) ($assetCheck['key'] ?? ''), ['document_sync_wsdl', 'public_cert'], true);
            $exists = !empty($assetCheck['exists']);
            $checks[] = [
                'label' => $this->assetLabel((string) ($assetCheck['key'] ?? 'asset')),
                'status' => $exists ? 'ok' : ($isRequired ? 'error' : 'warning'),
                'message' => $exists
                    ? 'Asset locale trovato: ' . (string) ($assetCheck['path'] ?? '')
                    : 'Asset locale non trovato: ' . (string) ($assetCheck['path'] ?? ''),
            ];

            if (!$exists && $isRequired) {
                $errors[] = 'Manca l’asset tecnico richiesto: ' . $this->assetLabel((string) ($assetCheck['key'] ?? 'asset')) . '.';
            } elseif (!$exists) {
                $warnings[] = 'Manca l’asset opzionale: ' . $this->assetLabel((string) ($assetCheck['key'] ?? 'asset')) . '.';
            }
        }
        $supportLog->step('assets_checked', 'Controllo asset locali TS completato.', [
            'asset_checks' => $this->profileService->resolveAssetChecks(),
        ]);

        $environmentConfig = $this->config->resolveEnvironmentConfig((string) ($profile['environment'] ?? 'test'));
        if (trim((string) ($environmentConfig['document_endpoint'] ?? '')) === '') {
            $warnings[] = 'Endpoint documento TS non ancora configurato via env per l’ambiente selezionato.';
        }
        if (trim((string) ($environmentConfig['receipts_endpoint'] ?? '')) === '') {
            $warnings[] = 'Endpoint ricevute TS non ancora configurato via env per l’ambiente selezionato.';
        }

        $operationConfigured = trim($this->config->documentOperationName) !== '';
        $checks[] = [
            'label' => 'Operazione SOAP documento',
            'status' => $operationConfigured ? 'ok' : 'warning',
            'message' => $operationConfigured
                ? 'Operazione SOAP documento configurata: ' . $this->config->documentOperationName
                : 'Nome operazione SOAP documento non ancora configurato via env.',
        ];
        if (!$operationConfigured) {
            $warnings[] = 'Nome operazione SOAP documento non ancora configurato via env.';
        }
        $supportLog->step('environment_checked', 'Controlli endpoint e contratto SOAP completati.', [
            'environment' => trim((string) ($profile['environment'] ?? 'test')),
            'document_endpoint' => trim((string) ($environmentConfig['document_endpoint'] ?? '')),
            'receipts_endpoint' => trim((string) ($environmentConfig['receipts_endpoint'] ?? '')),
            'document_operation' => $this->config->documentOperationName,
        ], $warnings === [] ? 'info' : 'warning');

        if ($warnings !== []) {
            $checks[] = [
                'label' => 'Endpoint ambiente',
                'status' => 'warning',
                'message' => implode(' ', $warnings),
            ];
        } else {
            $checks[] = [
                'label' => 'Endpoint ambiente',
                'status' => 'ok',
                'message' => 'Gli endpoint dell’ambiente risultano valorizzati.',
            ];
        }

        $rawPlatformInspection = $this->migrationSafety->inspectPlatform();
        $rawTenantInspection = $this->migrationSafety->inspectTenant($tenantId);
        $platformInspection = $this->normalizeInspectionForTenantHealthcheck(
            $rawPlatformInspection,
            true
        );
        $tenantInspection = $this->normalizeInspectionForTenantHealthcheck(
            $rawTenantInspection,
            true
        );

        $supportLog->step('platform_inspection', 'Diagnostica schema TS platform acquisita.', $rawPlatformInspection);
        $supportLog->step('tenant_inspection', 'Diagnostica schema TS tenant acquisita.', $rawTenantInspection);

        $this->appendInspectionChecks(
            'Schema TS platform',
            $platformInspection,
            $checks,
            $errors,
            $warnings
        );
        $this->appendInspectionChecks(
            'Schema TS tenant',
            $tenantInspection,
            $checks,
            $errors,
            $warnings
        );

        $errors = array_values(array_unique($errors));
        $warnings = array_values(array_unique($warnings));

        $status = $errors !== [] ? 'error' : ($warnings !== [] ? 'warning' : 'ok');
        $message = match ($status) {
            'ok' => 'Healthcheck locale TS completato: configurazione pronta per lo step tecnico successivo.',
            'warning' => 'Healthcheck locale TS completato con avvisi: la base c e, ma restano elementi da completare.',
            default => 'Healthcheck locale TS fallito: correggi i blocchi indicati prima di procedere con l’integrazione.',
        };
        $supportReference = $supportLog->finish($status, $message, [
            'checks' => $checks,
            'errors' => $errors,
            'warnings' => $warnings,
        ]);

        $this->profiles->update((int) ($profile['id_ts_profile'] ?? 0), [
            'last_check_status' => $status,
            'last_check_message' => $this->buildStoredMessage($message, $errors, $warnings),
            'last_check_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'status' => $status,
            'message' => $message,
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => $checks,
            'support_log' => $supportReference,
        ];
    }

    private function assetLabel(string $key): string
    {
        return match ($key) {
            'document_sync_wsdl' => 'WSDL invio documento TS',
            'receipts_wsdl' => 'WSDL ricevute TS',
            'public_cert' => 'Certificato pubblico TS',
            default => $key,
        };
    }

    /**
     * Per l’healthcheck tenant ignoriamo il drift di migration App non TS
     * quando lo schema TS del target e già allineato.
     *
     * @param array<string, mixed> $inspection
     * @return array<string, mixed>
     */
    private function normalizeInspectionForTenantHealthcheck(array $inspection, bool $ignoreNonTsDriftWarnings): array
    {
        if (!$ignoreNonTsDriftWarnings) {
            return $inspection;
        }

        $originalWarnings = array_values(array_filter(
            (array) ($inspection['warnings'] ?? []),
            static fn($message): bool => trim((string) $message) !== ''
        ));
        $filteredWarnings = array_values(array_filter(
            $originalWarnings,
            fn($message): bool => !$this->isNonTsDriftWarningMessage((string) $message)
        ));

        $inspection['warnings'] = $filteredWarnings;

        $hasErrors = (array) ($inspection['errors'] ?? []) !== [];
        $pendingTsMigrations = (array) ($inspection['pending_ts_migrations'] ?? []);
        $status = trim((string) ($inspection['status'] ?? 'error'));
        $onlyIgnoredWarnings = $originalWarnings !== [] && $filteredWarnings === [];

        if (!$hasErrors && $pendingTsMigrations === [] && $status === 'warning' && $onlyIgnoredWarnings) {
            $inspection['status'] = 'ok';
            $inspection['message'] = 'Schema TS platform allineato per il modulo. Restano migration App non TS pendenti nello stesso gruppo, ma non bloccano questo healthcheck.';
        }

        return $inspection;
    }

    private function isNonTsDriftWarningMessage(string $message): bool
    {
        $message = trim($message);
        if ($message === '') {
            return false;
        }

        return preg_match('/^Sono presenti \d+ migration App non TS pendenti per /', $message) === 1;
    }

    private function isSecretDecryptable(string $payload): bool
    {
        $payload = trim($payload);
        if ($payload === '') {
            return false;
        }

        try {
            $plainText = $this->secrets->decrypt($payload);
            return is_string($plainText) && $plainText !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<int, string> $errors
     * @param array<int, string> $warnings
     */
    private function buildStoredMessage(string $summary, array $errors, array $warnings): string
    {
        $lines = [$summary];

        foreach ($errors as $errorMessage) {
            $lines[] = 'ERRORE: ' . $errorMessage;
        }

        foreach ($warnings as $warningMessage) {
            $lines[] = 'AVVISO: ' . $warningMessage;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $inspection
     * @param array<int, array<string, string>> $checks
     * @param array<int, string> $errors
     * @param array<int, string> $warnings
     */
    private function appendInspectionChecks(string $summaryLabel, array $inspection, array &$checks, array &$errors, array &$warnings): void
    {
        $checks[] = [
            'label' => $summaryLabel,
            'status' => trim((string) ($inspection['status'] ?? 'error')),
            'message' => trim((string) ($inspection['message'] ?? 'Controllo TS non disponibile.')),
        ];

        foreach ((array) ($inspection['schema_checks'] ?? []) as $check) {
            $checks[] = [
                'label' => trim((string) ($check['label'] ?? $summaryLabel)),
                'status' => trim((string) ($check['status'] ?? 'warning')),
                'message' => trim((string) ($check['message'] ?? '')),
            ];
        }

        foreach ((array) ($inspection['extra_checks'] ?? []) as $check) {
            $checks[] = [
                'label' => trim((string) ($check['label'] ?? $summaryLabel)),
                'status' => trim((string) ($check['status'] ?? 'warning')),
                'message' => trim((string) ($check['message'] ?? '')),
            ];
        }

        foreach ((array) ($inspection['errors'] ?? []) as $message) {
            $message = trim((string) $message);
            if ($message !== '') {
                $errors[] = $message;
            }
        }

        foreach ((array) ($inspection['warnings'] ?? []) as $message) {
            $message = trim((string) $message);
            if ($message !== '') {
                $warnings[] = $message;
            }
        }
    }
}
