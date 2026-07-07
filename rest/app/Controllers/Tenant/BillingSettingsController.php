<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\BillingFeatureService;
use App\Services\BillingTsModuleStatusService;
use App\Services\TenantContextService;

class BillingSettingsController extends BaseController
{
    private TenantContextService $tenantContext;
    private BillingFeatureService $featureService;
    private BillingTsModuleStatusService $moduleStatus;

    public function __construct()
    {
        helper('portal');
        $this->tenantContext = new TenantContextService();
        $this->featureService = new BillingFeatureService();
        $this->moduleStatus = new BillingTsModuleStatusService();
    }

    public function index()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        if (!portal_current_path_matches('login/spazio/fatturazione')) {
            return redirect()->to(portal_tenant_space_url('fatturazione'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        return view('tenant/billing_settings', [
            'tenantContext' => $context,
            'moduleStatus' => $this->moduleStatus->describe($context, $context->tenantId),
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
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
            return redirect()->to(site_url('/'))->with('error', 'Solo il responsabile dello studio puo aprire il modulo Fatturazione.');
        }

        if ((int) (session()->get('platform_user_id') ?? 0) <= 0) {
            return $this->sessionExpiredRedirect();
        }

        if (!$this->featureService->isEnabledForContext($context)) {
            return redirect()->to(portal_tenant_space_url('funzioni'))
                ->with('error', 'La Fatturazione non e attiva per questo spazio cliente. Deve essere abilitata dal master piattaforma nella scheda dello spazio.');
        }

        return null;
    }
}
