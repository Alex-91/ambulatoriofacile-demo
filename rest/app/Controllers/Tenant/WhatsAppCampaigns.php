<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\TenantCatalogService;
use App\Services\TenantContextService;
use App\Services\TenantNotificationPolicyService;
use App\Services\WhatsAppCampaignReadinessService;
use App\Services\WhatsAppCampaignService;

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
        if (!portal_current_path_matches('login/spazio/invii-whatsapp')) { return redirect()->to(portal_tenant_space_url('invii-whatsapp')); }
        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) { return $this->sessionExpiredRedirect(); }
        $dashboard = (new WhatsAppCampaignService())->dashboard($context->tenantId, (int) ($this->request->getGet('campaign') ?? 0));
        return view('tenant/whatsapp_campaigns', [
            'tenantContext' => $context,
            'tenant' => $this->tenantCatalog->getTenantById($context->tenantId),
            'dashboard' => $dashboard,
            'deliveryPolicy' => (new TenantNotificationPolicyService())->resolve($context->tenantId, $context->tenantName),
            'whatsAppReadiness' => (new WhatsAppCampaignReadinessService())->resolve($context->tenantId),
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function create()
    {
        if ($guard = $this->ensureTenantMasterAccess()) { return $guard; }
        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) { return $this->sessionExpiredRedirect(); }
        $readiness = (new WhatsAppCampaignReadinessService())->resolve($context->tenantId);
        if (empty($readiness['ready'])) {
            return redirect()->to(portal_tenant_space_url('invii-whatsapp'))
                ->withInput()
                ->with('errors', ['whatsapp' => (string) ($readiness['reason'] ?? 'WhatsApp non è pronto per l’invio.')]);
        }
        try {
            $campaign = (new WhatsAppCampaignService())->createCampaign($context->tenantId, [
                'audience_type' => (string) $this->request->getPost('audience_type'),
                'appointment_date' => (string) $this->request->getPost('appointment_date'),
                'message_text' => (string) $this->request->getPost('message_text'),
            ], (int) (session()->get('platform_user_id') ?? 0));
            $policy = (new TenantNotificationPolicyService())->resolve($context->tenantId, $context->tenantName);
            return redirect()->to(portal_tenant_space_url('invii-whatsapp') . '?campaign=' . (int) ($campaign['id_whatsapp_campaign'] ?? 0))
                ->with(
                    'success',
                    'Campagna accodata: il backend rispetterà il limite di '
                    . (int) ($policy['whatsapp']['messages_per_interval'] ?? 1)
                    . ' messaggi ogni ' . (int) ($policy['whatsapp']['interval_minutes'] ?? 5) . ' minuti.'
                );
        } catch (\Throwable $e) {
            log_message('error', 'Tenant WhatsApp campaign create failed: ' . $e->getMessage(), ['tenant_id' => $context->tenantId]);
            return redirect()->to(portal_tenant_space_url('invii-whatsapp'))->withInput()->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    private function ensureTenantMasterAccess()
    {
        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) { return $this->redirectToLogin(); }
        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null || (int) (session()->get('platform_user_id') ?? 0) <= 0) { return $this->sessionExpiredRedirect(); }
        if (!session_has_tenant_master_access()) { return redirect()->to(site_url('/'))->with('error', 'Solo il responsabile dello studio può inviare campagne WhatsApp.'); }
        return null;
    }
}
