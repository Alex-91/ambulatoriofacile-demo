<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\SessionNavigationService;
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

        if (trim(service('uri')->getPath(), '/') !== 'login/spazio/funzioni') {
            return redirect()->to(portal_tenant_space_url('funzioni'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return redirect()->to(site_url('/'));
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
            return redirect()->to(site_url('/'));
        }

        try {
            $enabledFeatures = array_values(array_filter(array_map(
                static fn($value): string => trim(strtolower((string) $value)),
                (array) $this->request->getPost('enabled_features')
            )));

            $platformUserId = (int) (session()->get('platform_user_id') ?? 0);
            (new TenantFeatureService())->saveTenantManagedFeatures($context->tenantId, $enabledFeatures, $platformUserId);
            $this->tenantContext->activateTenantForPlatformUser($platformUserId, $context->tenantId);
            (new SessionNavigationService())->refreshCurrentSession(true);

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
            return redirect()->to(portal_public_access_url('login'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return redirect()->to(site_url('/'));
        }

        if ($context->tenantRole !== 'tenant_master') {
            return redirect()->to(site_url('/'))->with('error', 'Solo il tenant master puo gestire le funzioni dello spazio.');
        }

        if ((int) (session()->get('platform_user_id') ?? 0) <= 0) {
            return redirect()->to(site_url('/'))->with('error', 'Funzione disponibile solo per accessi piattaforma.');
        }

        return null;
    }
}
