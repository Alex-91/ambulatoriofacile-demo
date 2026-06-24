<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Models\PlatformTenantsModel;
use App\Services\TenantContextService;

class Onboarding extends BaseController
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

        return redirect()->to(site_url('admin'));
    }

    public function complete()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        $tenantModel = new PlatformTenantsModel();
        $tenant = $tenantModel->find($context->tenantId);
        if (!$tenant) {
            return $this->redirectToLogin('Spazio cliente non trovato. Effettua di nuovo il login.');
        }

        $currentStatus = trim((string) ($tenant['onboarding_status'] ?? 'draft'));
        $tenantModel->update($context->tenantId, [
            'onboarding_status' => $currentStatus === 'live' ? 'live' : 'ready',
        ]);

        return redirect()
            ->to(site_url('admin'))
            ->with('success', 'Onboarding iniziale completato. Da ora puoi gestire lo studio in autonomia.');
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
            return redirect()->to(site_url('/'));
        }

        if ((int) (session()->get('platform_user_id') ?? 0) <= 0) {
            return $this->sessionExpiredRedirect();
        }

        return null;
    }
}
