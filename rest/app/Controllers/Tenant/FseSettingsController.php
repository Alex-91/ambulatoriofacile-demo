<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\FseFeatureService;
use App\Services\FseHealthcheckService;
use App\Services\FseProfileService;
use App\Services\TenantContextService;

class FseSettingsController extends BaseController
{
    private TenantContextService $contexts;
    private FseFeatureService $features;
    private FseProfileService $profiles;

    public function __construct()
    {
        helper(['portal', 'session_auth']);
        $this->contexts = new TenantContextService();
        $this->features = new FseFeatureService();
        $this->profiles = new FseProfileService();
    }

    public function index()
    {
        if ($guard = $this->ensureAllowed()) return $guard;
        $context = $this->contexts->getCurrentTenant();
        return view('tenant/fse_settings', ['tenantContext' => $context, 'settings' => $this->profiles->resolveTenantSettings($context->tenantId),
            'success' => session()->getFlashdata('success'), 'errors' => session()->getFlashdata('errors') ?? [],
            'healthcheckResult' => session()->getFlashdata('healthcheck_result')]);
    }

    public function save()
    {
        if ($guard = $this->ensureAllowed()) return $guard;
        $context = $this->contexts->getCurrentTenant();
        try {
            $this->profiles->saveDefaultProfile($context->tenantId, $this->request->getPost(), (int) (session()->get('platform_user_id') ?? 0));
            return redirect()->to(portal_tenant_space_url('fse2'))->with('success', 'Profilo FSE 2.0 salvato.');
        } catch (\Throwable $e) {
            return redirect()->to(portal_tenant_space_url('fse2'))->withInput()->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function healthcheck()
    {
        if ($guard = $this->ensureAllowed()) return $guard;
        $context = $this->contexts->getCurrentTenant();
        try {
            $result = (new FseHealthcheckService())->runForTenant($context->tenantId);
            return redirect()->to(portal_tenant_space_url('fse2'))->with('healthcheck_result', $result)
                ->with($result['status'] === 'error' ? 'errors' : 'success', $result['status'] === 'error' ? ['generic' => $result['message']] : $result['message']);
        } catch (\Throwable $e) {
            return redirect()->to(portal_tenant_space_url('fse2'))->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    private function ensureAllowed()
    {
        if (!session_access_is_confirmed()) return $this->redirectToLogin();
        $context = $this->contexts->getCurrentTenant();
        if ($context === null || !$context->isValid()) return redirect()->to(site_url('/'))->with('error', 'Sessione spazio non disponibile.');
        if (!in_array(strtolower($context->tenantRole), ['tenant_master', 'tenant_admin'], true)) return redirect()->to(site_url('/'))->with('error', 'Configurazione FSE riservata ai responsabili dello spazio.');
        if (!$this->features->isEnabledForContext($context) && !$this->features->allowsLocalTestingBypass($context)) return redirect()->to(portal_tenant_space_url('funzioni'))->with('error', 'Modulo FSE 2.0 non attivo.');
        return null;
    }
}
