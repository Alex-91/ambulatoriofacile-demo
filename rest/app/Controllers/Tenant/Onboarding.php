<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Models\PlatformTenantsModel;
use App\Services\TenantCatalogService;
use App\Services\TenantContextService;
use App\Services\TenantProvisioningService;

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

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return redirect()->to(site_url('/'));
        }

        $tenant = (new TenantCatalogService())->getTenantById($context->tenantId);
        $capacity = (new TenantProvisioningService())->getTenantUserCapacity($context->tenantId);

        return view('tenant/onboarding', [
            'tenantContext' => $context,
            'tenant' => $tenant,
            'capacity' => $capacity,
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function complete()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return redirect()->to(site_url('/'));
        }

        $tenantModel = new PlatformTenantsModel();
        $tenant = $tenantModel->find($context->tenantId);
        if (!$tenant) {
            return redirect()->to(site_url('/'))->with('error', 'Spazio cliente non trovato.');
        }

        $currentStatus = trim((string) ($tenant['onboarding_status'] ?? 'draft'));
        $tenantModel->update($context->tenantId, [
            'onboarding_status' => $currentStatus === 'live' ? 'live' : 'ready',
        ]);

        return redirect()
            ->to(site_url('/'))
            ->with('success', 'Onboarding iniziale completato. Da ora puoi gestire lo spazio in autonomia.');
    }

    private function ensureAllowed()
    {
        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return redirect()->to(portal_public_access_url('login'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return redirect()->to(site_url('/'));
        }

        if ($context->tenantRole !== 'tenant_master') {
            return redirect()->to(site_url('/'));
        }

        if ((int) (session()->get('platform_user_id') ?? 0) <= 0) {
            return redirect()->to(site_url('/'));
        }

        return null;
    }
}
