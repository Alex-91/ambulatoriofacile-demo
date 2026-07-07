<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\BillingTsModuleStatusService;
use App\Services\TenantContextService;
use App\Services\TsFeatureService;
use App\Services\TsHealthcheckService;
use App\Services\TsMigrationSafetyService;
use App\Services\TsProfileService;

class TsSettingsController extends BaseController
{
    private TenantContextService $tenantContext;
    private TsFeatureService $featureService;
    private TsProfileService $profileService;
    private TsHealthcheckService $healthcheckService;
    private TsMigrationSafetyService $migrationSafety;
    private BillingTsModuleStatusService $moduleStatus;

    public function __construct()
    {
        helper('portal');
        $this->tenantContext = new TenantContextService();
        $this->featureService = new TsFeatureService();
        $this->profileService = new TsProfileService();
        $this->healthcheckService = new TsHealthcheckService();
        $this->migrationSafety = new TsMigrationSafetyService();
        $this->moduleStatus = new BillingTsModuleStatusService();
    }

    public function index()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        if (!portal_current_path_matches('login/spazio/sistema-ts')) {
            return redirect()->to(portal_tenant_space_url('sistema-ts'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        return view('tenant/ts_settings', [
            'tenantContext' => $context,
            'settings' => $this->profileService->resolveTenantSettings($context->tenantId),
            'moduleStatus' => $this->moduleStatus->describe($context, $context->tenantId),
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
            'healthcheckResult' => session()->getFlashdata('healthcheck_result') ?? null,
            'schemaSyncResult' => session()->getFlashdata('schema_sync_result') ?? null,
        ]);
    }

    public function save()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        try {
            $this->profileService->saveDefaultProfile(
                $context->tenantId,
                [
                    'test_preset_key' => $this->request->getPost('test_preset_key'),
                    'profile_name' => $this->request->getPost('profile_name'),
                    'sender_type' => $this->request->getPost('sender_type'),
                    'owner_piva' => $this->request->getPost('owner_piva'),
                    'owner_cf' => $this->request->getPost('owner_cf'),
                    'region_code' => $this->request->getPost('region_code'),
                    'asl_code' => $this->request->getPost('asl_code'),
                    'ssa_code' => $this->request->getPost('ssa_code'),
                    'auth_username' => $this->request->getPost('auth_username'),
                    'auth_password' => $this->request->getPost('auth_password'),
                    'pincode' => $this->request->getPost('pincode'),
                    'environment' => $this->request->getPost('environment'),
                    'is_enabled' => $this->request->getPost('is_enabled'),
                ],
                (int) (session()->get('platform_user_id') ?? 0)
            );

            $triggerHealthcheck = trim((string) ($this->request->getPost('after_save') ?? '')) === 'healthcheck';
            if ($triggerHealthcheck) {
                $result = $this->healthcheckService->runForTenant($context->tenantId);
                $redirect = redirect()
                    ->to(portal_tenant_space_url('sistema-ts'))
                    ->with('healthcheck_result', $result);

                $status = trim((string) ($result['status'] ?? 'error'));
                if ($status === 'ok') {
                    return $redirect->with('success', 'Profilo TS salvato e validato localmente con esito positivo.');
                }

                if ($status === 'warning') {
                    return $redirect->with('success', 'Profilo TS salvato. Validazione locale completata con avvisi non bloccanti.');
                }

                return $redirect->with('errors', [
                    'generic' => 'Profilo TS salvato, ma la validazione locale ha trovato blocchi da correggere.',
                ]);
            }

            return redirect()
                ->to(portal_tenant_space_url('sistema-ts'))
                ->with('success', 'Profilo TS salvato. Prossimo step: verifica la configurazione Sistema TS dello spazio.');
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\TsSettingsController::save failed: ' . $e->getMessage());

            return redirect()
                ->to(portal_tenant_space_url('sistema-ts'))
                ->withInput()
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function healthcheck()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        try {
            $result = $this->healthcheckService->runForTenant($context->tenantId);
            $redirect = redirect()
                ->to(portal_tenant_space_url('sistema-ts'))
                ->with('healthcheck_result', $result);

            if (($result['status'] ?? '') === 'ok') {
                $redirect = $redirect->with('success', $result['message']);
            }

            return $redirect;
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\TsSettingsController::healthcheck failed: ' . $e->getMessage());

            return redirect()
                ->to(portal_tenant_space_url('sistema-ts'))
                ->with('errors', ['generic' => 'Healthcheck TS non riuscito: ' . $e->getMessage()]);
        }
    }

    public function repairSchema()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            return redirect()
                ->to(portal_tenant_space_url('sistema-ts'))
                ->with('errors', ['generic' => 'Lo strumento di riallineamento schema locale e disponibile solo in ambienti non production.']);
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        try {
            $report = $this->migrationSafety->migrateSafe('tenant', $context->tenantId, true);
            $schemaSyncResult = $this->extractTenantMigrationResult($report);
            $schemaStatus = trim((string) ($schemaSyncResult['status'] ?? 'error'));

            $redirect = redirect()
                ->to(portal_tenant_space_url('sistema-ts'))
                ->with('schema_sync_result', $schemaSyncResult);

            if (in_array($schemaStatus, ['ok', 'warning'], true)) {
                $healthcheckResult = $this->healthcheckService->runForTenant($context->tenantId);
                $healthcheckStatus = trim((string) ($healthcheckResult['status'] ?? 'error'));

                $redirect = $redirect->with('healthcheck_result', $healthcheckResult);

                if ($healthcheckStatus === 'ok') {
                    return $redirect->with(
                        'success',
                        'Schema locale TS riallineato per questo spazio e healthcheck rieseguito con esito positivo.'
                    );
                }

                if ($healthcheckStatus === 'warning') {
                    return $redirect->with(
                        'success',
                        'Schema locale TS riallineato. Healthcheck rieseguito: restano solo avvisi non bloccanti.'
                    );
                }

                return $redirect->with('errors', [
                    'generic' => 'Schema locale TS aggiornato, ma l healthcheck resta bloccato: controlla i dettagli sotto.',
                ]);
            }

            return $redirect->with('errors', [
                'generic' => 'Allineamento schema locale TS non riuscito: controlla il dettaglio tecnico riportato sotto.',
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\TsSettingsController::repairSchema failed: ' . $e->getMessage());

            return redirect()
                ->to(portal_tenant_space_url('sistema-ts'))
                ->with('errors', ['generic' => 'Allineamento schema locale TS non riuscito: ' . $e->getMessage()]);
        }
    }

    private function ensureAllowed()
    {
        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return $this->redirectToLogin();
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        if ($context->tenantRole !== 'tenant_master') {
            return redirect()->to(site_url('/'))->with('error', 'Solo il responsabile dello studio puo configurare il Sistema TS.');
        }

        if ((int) (session()->get('platform_user_id') ?? 0) <= 0) {
            return $this->sessionExpiredRedirect();
        }

        if (!$this->featureService->isEnabledForContext($context)
            && !$this->featureService->allowsLocalTestingBypass($context)) {
            return redirect()->to(portal_tenant_space_url('funzioni'))
                ->with('error', 'Il Sistema TS non e attivo per questo spazio cliente. Deve essere abilitato dal master piattaforma nella scheda dello spazio.');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function extractTenantMigrationResult(array $report): array
    {
        $results = (array) ($report['results'] ?? []);
        $tenantResult = $results['tenant'] ?? null;
        if (is_array($tenantResult)) {
            return $tenantResult;
        }

        foreach ($results as $result) {
            if (is_array($result)) {
                return $result;
            }
        }

        return [
            'status' => trim((string) ($report['status'] ?? 'error')) ?: 'error',
            'message' => 'Risultato allineamento schema TS non disponibile.',
            'before' => [],
            'after' => [],
            'cli_messages' => [],
        ];
    }
}
