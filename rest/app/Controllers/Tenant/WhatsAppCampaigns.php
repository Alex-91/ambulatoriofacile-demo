<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\TenantCatalogService;
use App\Services\TenantContextService;
use App\Services\MassCampaignDeliveryService;
use App\Services\MassCampaignService;

class WhatsAppCampaigns extends BaseController
{
    private TenantContextService $tenantContext;
    private TenantCatalogService $tenantCatalog;

    public function __construct()
    {
        helper(['portal', 'session_auth']);
        $this->tenantContext = new TenantContextService();
        $this->tenantCatalog = new TenantCatalogService();
    }

    public function index()
    {
        if ($guard = $this->ensureTenantMasterAccess()) { return $guard; }
        if (!portal_current_path_matches('login/spazio/invii-massivi')) { return redirect()->to(portal_tenant_space_url('invii-massivi')); }
        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) { return $this->sessionExpiredRedirect(); }
        $dashboard = (new MassCampaignService())->dashboard($context->tenantId, (int) ($this->request->getGet('campaign') ?? 0));
        return view('tenant/whatsapp_campaigns', [
            'tenantContext' => $context,
            'tenant' => $this->tenantCatalog->getTenantById($context->tenantId),
            'dashboard' => $dashboard,
            'smsEnabled' => (new MassCampaignDeliveryService())->smsEnabled($context->tenantId),
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function create()
    {
        if ($guard = $this->ensureTenantMasterAccess()) { return $guard; }
        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) { return $this->sessionExpiredRedirect(); }
        try {
            $campaign = (new MassCampaignService())->createCampaign($context->tenantId, [
                'audience_type' => (string) $this->request->getPost('audience_type'),
                'appointment_date' => (string) $this->request->getPost('appointment_date'),
                'message_text' => (string) $this->request->getPost('message_text'),
                'email_enabled' => $this->request->getPost('email_enabled') === '1',
                'sms_enabled' => $this->request->getPost('sms_enabled') === '1',
                'first_channel' => (string) $this->request->getPost('first_channel'),
            ], (int) (session()->get('platform_user_id') ?? 0));
            return redirect()->to(portal_tenant_space_url('invii-massivi') . '?campaign=' . (int) ($campaign['id_mass_campaign'] ?? 0))
                ->with(
                    'success',
                    'Campagna accodata: un invio al minuto per spazio, compresi gli eventuali tentativi sul secondo canale.'
                );
        } catch (\Throwable $e) {
            log_message('error', 'Tenant mass campaign create failed: ' . $e->getMessage(), ['tenant_id' => $context->tenantId]);
            return redirect()->to(portal_tenant_space_url('invii-massivi'))->withInput()->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function pause()
    {
        return $this->changePausedState(true);
    }

    public function resume()
    {
        return $this->changePausedState(false);
    }

    private function changePausedState(bool $paused)
    {
        if ($guard = $this->ensureTenantMasterAccess()) { return $guard; }
        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) { return $this->sessionExpiredRedirect(); }
        $campaignId = (int) $this->request->getPost('campaign_id');
        $url = portal_tenant_space_url('invii-massivi') . '?campaign=' . $campaignId;
        try {
            (new MassCampaignService())->setPaused($context->tenantId, $campaignId, $paused);
            return redirect()->to($url)->with('success', $paused
                ? 'Campagna in pausa. Un eventuale invio già in corso potrà terminare.'
                : 'Campagna ripresa dai destinatari rimasti in attesa, rispettando il limite di un invio al minuto.');
        } catch (\Throwable $e) {
            return redirect()->to($url)->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    private function ensureTenantMasterAccess()
    {
        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) { return $this->redirectToLogin(); }
        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null || (int) (session()->get('platform_user_id') ?? 0) <= 0) { return $this->sessionExpiredRedirect(); }
        if (!session_has_tenant_master_access()) { return redirect()->to(site_url('/'))->with('error', 'Solo il responsabile dello studio può inviare campagne.'); }
        return null;
    }
}
