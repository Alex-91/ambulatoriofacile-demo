<?php

namespace App\Controllers\Login;

use App\Controllers\BaseController;
use App\Models\PlatformTenantsModel;
use App\Services\OtpDeviceManagementService;
use App\Services\PlatformAdminAccessService;

class PlatformOtpDevicesController extends BaseController
{
    private PlatformAdminAccessService $platformAdminAccess;

    public function __construct()
    {
        helper('portal');
        $this->platformAdminAccess = new PlatformAdminAccessService();
    }

    public function index()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        if (!portal_current_path_matches('login/piattaforma/dispositivi-otp')) {
            return redirect()->to(portal_platform_url('dispositivi-otp'));
        }

        $tenantRows = (new PlatformTenantsModel())
            ->select('id_tenant, tenant_name, tenant_key, status, is_active')
            ->orderBy('is_active', 'DESC')
            ->orderBy('tenant_name', 'ASC')
            ->findAll();

        $selectedTenantId = (int) ($this->request->getGet('id_tenant') ?? 0);
        if ($selectedTenantId <= 0 && $tenantRows !== []) {
            $selectedTenantId = (int) ($tenantRows[0]['id_tenant'] ?? 0);
        }

        $dashboard = [
            'tenant' => [],
            'accounts' => [],
            'summary' => [
                'total_accounts' => 0,
                'mapped_accounts' => 0,
                'active_devices' => 0,
            ],
            'runtime_warning' => null,
        ];
        $loadErrors = [];

        if ($selectedTenantId > 0) {
            try {
                $dashboard = (new OtpDeviceManagementService())->buildTenantDashboard($selectedTenantId);
            } catch (\Throwable $e) {
                log_message('error', 'PlatformOtpDevicesController::index failed: ' . $e->getMessage(), [
                    'tenant_id' => $selectedTenantId,
                ]);
                $loadErrors = ['generic' => $e->getMessage()];
            }
        }

        $legacyBootstrapMode = $this->legacyBootstrapMode();

        return view('admin/platform_otp_devices', [
            'menu_items' => [],
            'tenantRows' => $tenantRows,
            'selectedTenantId' => $selectedTenantId,
            'selectedTenant' => $dashboard['tenant'] ?? [],
            'accounts' => $dashboard['accounts'] ?? [],
            'summary' => $dashboard['summary'] ?? [],
            'runtimeWarning' => $dashboard['runtime_warning'] ?? null,
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? $loadErrors,
            'platformUser' => $this->platformAdminAccess->currentPlatformUser(),
            'platformMasterEmails' => $this->platformAdminAccess->configuredMasterEmails(),
            'legacyBootstrapMode' => $legacyBootstrapMode,
            'platformBootstrapWarnings' => $this->platformBootstrapWarnings($legacyBootstrapMode),
        ]);
    }

    public function disconnect()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        $tenantId = (int) ($this->request->getPost('id_tenant') ?? 0);
        $membershipId = (int) ($this->request->getPost('membership_id_platform_user_tenant') ?? 0);

        try {
            $result = (new OtpDeviceManagementService())->disconnectTenantMemberDevice($tenantId, $membershipId);

            return redirect()
                ->to(portal_platform_url('dispositivi-otp') . '?id_tenant=' . $tenantId)
                ->with('success', (string) ($result['message'] ?? 'Dispositivo disassociato.'));
        } catch (\Throwable $e) {
            log_message('error', 'PlatformOtpDevicesController::disconnect failed: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
            ]);

            return redirect()
                ->to(portal_platform_url('dispositivi-otp') . '?id_tenant=' . max(0, $tenantId))
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    private function ensurePlatformAdminPage()
    {
        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return redirect()->to(portal_public_access_url('login'));
        }

        if ($this->legacyBootstrapMode()) {
            return null;
        }

        if (!$this->platformAdminAccess->canAccessPlatformConsole()) {
            return redirect()->to(portal_public_access_url('login'))->with('login_error', 'Area piattaforma riservata agli account master.');
        }

        return null;
    }

    private function legacyBootstrapMode(): bool
    {
        if ($this->platformAdminAccess->canAccessPlatformConsole()) {
            return false;
        }

        if (!$this->isLegacyAdminAuthorized()) {
            return false;
        }

        return !$this->platformAdminAccess->hasPersistentPlatformAdmins();
    }

    private function isLegacyAdminAuthorized(): bool
    {
        $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) {
            return false;
        }

        if (session()->get(\App\Services\TenantContextService::SESSION_KEY)) {
            return false;
        }

        return session()->get('is_admin') === true
            || (int) (session()->get('admin') ?? 0) === 1
            || (int) ($me->tipo ?? 0) === 1;
    }

    /**
     * @return array<int, string>
     */
    private function platformBootstrapWarnings(bool $legacyBootstrapMode): array
    {
        if (!$legacyBootstrapMode) {
            return [];
        }

        $warnings = [
            'Accesso bootstrap attivo: stai entrando con un account admin legacy per inizializzare la nuova console sotto /login.',
        ];

        if (!$this->platformAdminAccess->hasPersistentPlatformAdmins()) {
            $warnings[] = 'Non esiste ancora un account master piattaforma persistente. Creane almeno uno dal pannello degli spazi cliente prima di uscire dalla modalita bootstrap.';
        }

        if ($this->platformAdminAccess->configuredMasterEmails() === []) {
            $warnings[] = 'PLATFORM_MASTER_EMAILS non e configurata in Coolify. Va bene: da ora i master possono essere gestiti dal pannello. Usa la env solo se vuoi tenere una scorciatoia bootstrap tecnica.';
        }

        return $warnings;
    }
}
