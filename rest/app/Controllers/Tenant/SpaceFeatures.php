<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\TenantContextService;
use App\Services\TenantFeatureService;

class SpaceFeatures extends BaseController
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

        if (!portal_current_path_matches('login/spazio/funzioni')) {
            return redirect()->to(portal_tenant_space_url('funzioni'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        return view('tenant/space_features', [
            'tenantContext' => $context,
            'featureStates' => (new TenantFeatureService())->listFeatureStatesForTenant($context->tenantId),
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
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
            $enabledFeatures = array_values(array_filter(array_map(
                static fn($value): string => trim(strtolower((string) $value)),
                (array) $this->request->getPost('enabled_features')
            )));

            $platformUserId = (int) (session()->get('platform_user_id') ?? 0);
            (new TenantFeatureService())->saveTenantManagedFeatures($context->tenantId, $enabledFeatures, $platformUserId);
            $this->tenantContext->activateTenantForPlatformUser($platformUserId, $context->tenantId);
            return redirect()
                ->to(portal_tenant_space_url('funzioni'))
                ->with('success', 'Funzioni dello spazio aggiornate con successo.');
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\SpaceFeatures::save failed: ' . $e->getMessage());

            return redirect()
                ->to(portal_tenant_space_url('funzioni'))
                ->withInput()
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

        if ($context->tenantRole !== 'tenant_master') {
            return redirect()->to(site_url('/'))->with('error', 'Solo il responsabile dello studio può gestire le funzioni dello studio.');
        }

        if ((int) (session()->get('platform_user_id') ?? 0) <= 0) {
            return $this->sessionExpiredRedirect();
        }

        return null;
    }
}
