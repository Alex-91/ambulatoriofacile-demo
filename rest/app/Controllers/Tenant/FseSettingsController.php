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
        try { $settings = $this->profiles->resolveTenantSettings($context->tenantId, max(0, (int) $this->request->getGet('profile')), $this->request->getGet('new') === '1'); }
        catch (\RuntimeException $e) { return $this->response->setStatusCode(404)->setBody('Profilo FSE non disponibile.'); }
        return view('tenant/fse_settings', ['tenantContext' => $context, 'settings' => $settings,
            'success' => session()->getFlashdata('success'), 'errors' => session()->getFlashdata('errors') ?? [],
            'healthcheckResult' => session()->getFlashdata('healthcheck_result')]);
    }

    public function save()
    {
        if ($guard = $this->ensureAllowed()) return $guard;
        $context = $this->contexts->getCurrentTenant();
        try {
            $payload = $this->request->getPost();
            $payload['is_enabled'] = 0; // Preparation wizard cannot activate outbound traffic.
            $profile = $this->profiles->saveProfile($context->tenantId, $payload, max(0, (int) ($payload['id_fse_profile'] ?? 0)),
                (int) (session()->get('platform_user_id') ?? 0), !empty($payload['make_default']));
            return redirect()->to(portal_tenant_space_url('fse2').'?profile='.$profile['id_fse_profile'])->with('success', 'Configurazione salvata. Invii disattivati; nessuna abilitazione ufficiale attribuita.');
        } catch (\Throwable $e) {
            $safeInput = $this->request->getPost();
            unset($safeInput['auth_private_key_passphrase'], $safeInput['signature_private_key_passphrase']);
            return redirect()->to(portal_tenant_space_url('fse2').((int) $this->request->getPost('id_fse_profile') > 0 ? '?profile='.(int) $this->request->getPost('id_fse_profile') : '?new=1'))
                ->with('_ci_old_input', ['get'=>[], 'post'=>$safeInput])->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function healthcheck()
    {
        if ($guard = $this->ensureAllowed()) return $guard;
        $context = $this->contexts->getCurrentTenant();
        try {
            $profileId = max(0, (int) $this->request->getPost('id_fse_profile'));
            if ($profileId <= 0 || !$this->profiles->getProfileForTenant($context->tenantId, $profileId)) throw new \RuntimeException('Salvare prima un profilo valido di questo spazio.');
            $result = (new FseHealthcheckService())->runForTenant($context->tenantId, true, true, $profileId);
            return redirect()->to(portal_tenant_space_url('fse2').'?profile='.$profileId)->with('healthcheck_result', $result)
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
        if (!session_has_tenant_management_access()) return redirect()->to(site_url('/'))->with('error', 'Configurazione FSE riservata ai responsabili dello spazio.');
        if (!$this->features->isEnabledForContext($context) && !$this->features->allowsLocalTestingBypass($context)) return redirect()->to(portal_tenant_space_url('funzioni'))->with('error', 'Modulo FSE 2.0 non attivo.');
        return null;
    }
}
