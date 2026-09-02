<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\AppointmentNotificationSettingsService;
use App\Services\TenantCatalogService;
use App\Services\TenantContextService;
use App\Services\WhatsAppChatbotService;
use App\Services\WhatsAppGatewayClient;

class WhatsAppChatbotController extends BaseController
{
    private TenantContextService $tenantContext;
    private TenantCatalogService $tenantCatalog;

    public function __construct()
    {
        helper('portal');
        $this->tenantContext = new TenantContextService();
        $this->tenantCatalog = new TenantCatalogService();
    }

    public function index()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        if (!portal_current_path_matches('login/spazio/chatbot-whatsapp')) {
            return redirect()->to(portal_tenant_space_url('chatbot-whatsapp'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }
        $tenant = $this->tenantCatalog->getTenantById($context->tenantId);
        if (!$tenant) {
            return $this->redirectToLogin('Spazio cliente non trovato. Effettua di nuovo il login.');
        }

        $service = new WhatsAppChatbotService();
        return view('tenant/whatsapp_chatbot', [
            'tenantContext' => $context,
            'tenant' => $tenant,
            'config' => $service->configForTenant($context->tenantId),
            'dashboard' => $service->dashboardForTenant($context->tenantId, 50),
            'gatewayAvailable' => WhatsAppGatewayClient::isAvailableForTenant($context->tenantId),
            'saveUrl' => portal_tenant_space_url('chatbot-whatsapp/save'),
            'notificationsUrl' => portal_tenant_space_url('notifiche-appuntamenti'),
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
            $rules = [];
            foreach ((array) $this->request->getPost('rules') as $index => $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $rule['enabled'] = (int) ($rule['enabled'] ?? 0) === 1;
                $rule['id'] = (string) ($rule['id'] ?? ('rule-' . ((int) $index + 1)));
                $rules[] = $rule;
            }

            (new WhatsAppChatbotService())->saveConfig($context->tenantId, [
                'enabled' => (int) ($this->request->getPost('enabled') ?? 0) === 1,
                'response_window_hours' => (int) ($this->request->getPost('response_window_hours') ?? 168),
                'prompt_text' => (string) ($this->request->getPost('prompt_text') ?? ''),
                'fallback_reply' => (string) ($this->request->getPost('fallback_reply') ?? ''),
                'open_on' => (array) $this->request->getPost('open_on'),
                'rules' => $rules,
            ], (int) (session()->get('platform_user_id') ?? 0));

            return redirect()->to(portal_tenant_space_url('chatbot-whatsapp'))
                ->with('success', 'Chatbot WhatsApp aggiornato per questo spazio.');
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\WhatsAppChatbotController::save failed: ' . $e->getMessage(), [
                'tenant_id' => $context->tenantId,
            ]);
            return redirect()->to(portal_tenant_space_url('chatbot-whatsapp'))
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
        if ($context === null || (int) (session()->get('platform_user_id') ?? 0) <= 0) {
            return $this->sessionExpiredRedirect();
        }
        if ($context->tenantRole !== 'tenant_master') {
            return redirect()->to(site_url('/'))->with('error', 'Solo il responsabile dello studio può gestire il chatbot WhatsApp.');
        }

        $settings = (new AppointmentNotificationSettingsService())->resolveTenantSettings($context->tenantId);
        if (empty($settings['available_channels'][AppointmentNotificationSettingsService::CHANNEL_WHATSAPP])) {
            return redirect()->to(portal_tenant_space_url('notifiche-appuntamenti'))
                ->with('error', 'Il canale WhatsApp non è attivo per questo spazio.');
        }

        return null;
    }
}
