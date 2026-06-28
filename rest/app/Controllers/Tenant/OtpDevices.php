<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\OtpDeviceManagementService;
use App\Services\TenantContextService;

class OtpDevices extends BaseController
{
    private TenantContextService $tenantContext;

    public function __construct()
    {
        helper('portal');
        $this->tenantContext = new TenantContextService();
    }

    public function index()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        if (!portal_current_path_matches('login/spazio/dispositivi-otp')) {
            return redirect()->to(portal_tenant_space_url('dispositivi-otp'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        try {
            $dashboard = (new OtpDeviceManagementService())->buildTenantDashboard($context->tenantId);
            $loadErrors = [];
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\OtpDevices::index failed: ' . $e->getMessage());
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
            $loadErrors = ['generic' => $e->getMessage()];
        }

        $menuDataAdmin = session()->get('menuDataAdmin');
        $sidebarMenuItems = is_array($menuDataAdmin['result'] ?? null) ? $menuDataAdmin['result'] : [];
        $headerMenuItems = $sidebarMenuItems !== [] ? $sidebarMenuItems : (session()->get('header_menu_items') ?? []);

        return view('tenant/otp_devices', [
            'tenantContext' => $context,
            'tenant' => $dashboard['tenant'] ?? [],
            'accounts' => $dashboard['accounts'] ?? [],
            'summary' => $dashboard['summary'] ?? [],
            'runtimeWarning' => $dashboard['runtime_warning'] ?? null,
            'menu_items' => $headerMenuItems,
            'sidebarMenuItems' => $sidebarMenuItems,
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? $loadErrors,
        ]);
    }

    public function disconnect()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        $membershipId = (int) ($this->request->getPost('membership_id_platform_user_tenant') ?? 0);

        try {
            $result = (new OtpDeviceManagementService())->disconnectTenantMemberDevice($context->tenantId, $membershipId);

            return redirect()
                ->to(portal_tenant_space_url('dispositivi-otp'))
                ->with('success', (string) ($result['message'] ?? 'Dispositivo disassociato.'));
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\OtpDevices::disconnect failed: ' . $e->getMessage(), [
                'tenant_id' => $context->tenantId,
                'membership_id' => $membershipId,
            ]);

            return redirect()
                ->to(portal_tenant_space_url('dispositivi-otp'))
                ->with('errors', ['generic' => $e->getMessage()]);
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

        if (!in_array($context->tenantRole, ['tenant_master', 'tenant_admin'], true)) {
            return redirect()->to(site_url('/'))->with('error', 'Non sei autorizzato a gestire i dispositivi OTP di questo studio.');
        }

        if ((int) (session()->get('platform_user_id') ?? 0) <= 0) {
            return $this->sessionExpiredRedirect();
        }

        return null;
    }
}
