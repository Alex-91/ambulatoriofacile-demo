<?php

namespace App\Controllers\Login;

use App\Controllers\BaseController;
use App\Models\PlatformTenantsModel;
use App\Services\AppointmentNotificationSettingsService;
use App\Services\PlatformAdminAccessService;
use App\Services\WhatsAppChatbotService;
use App\Services\WhatsAppGatewayClient;

class PlatformWhatsAppChatbotController extends BaseController
{
    private PlatformAdminAccessService $platformAdminAccess;

    public function __construct()
    {
        helper('portal');
        $this->platformAdminAccess = new PlatformAdminAccessService();
    }

    public function index()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        $requestedTenantId = (int) ($this->request->getGet('tenant_id') ?? 0);
        if (!portal_current_path_matches('login/piattaforma/chatbot-whatsapp')) {
            return redirect()->to($this->chatbotUrl($requestedTenantId));
        }

        $errors = [];
        $tenantCatalog = [];
        $tenant = null;
        try {
            $tenantCatalog = $this->listChatbotTenants();
            if ($requestedTenantId <= 0 && $tenantCatalog !== []) {
                $requestedTenantId = (int) ($tenantCatalog[0]['id_tenant'] ?? 0);
            }
            $tenant = $this->findTenant($tenantCatalog, $requestedTenantId);
            if ($requestedTenantId > 0 && $tenant === null) {
                throw new \RuntimeException('Lo spazio selezionato non ha il canale WhatsApp attivo.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'PlatformWhatsAppChatbotController::index failed: ' . $e->getMessage());
            $errors[] = $e->getMessage();
        }

        $tenantId = (int) ($tenant['id_tenant'] ?? 0);
        $service = new WhatsAppChatbotService();

        return view('tenant/whatsapp_chatbot', [
            'platformConsole' => true,
            'platformMasterEmails' => $this->platformAdminAccess->configuredMasterEmails(),
            'tenantCatalog' => $tenantCatalog,
            'tenantSelectionUrl' => portal_platform_url('chatbot-whatsapp'),
            'tenant' => $tenant ?? [],
            'config' => $tenantId > 0 ? $service->configForTenant($tenantId) : [],
            'dashboard' => $tenantId > 0 ? $service->dashboardForTenant($tenantId, 50) : [],
            'gatewayAvailable' => $tenantId > 0 && WhatsAppGatewayClient::isAvailableForTenant($tenantId),
            'saveUrl' => $this->chatbotUrl($tenantId, true),
            'notificationsUrl' => $tenantId > 0 ? portal_platform_url('whatsapp') . '?tenant_id=' . $tenantId : portal_platform_url('whatsapp'),
            'success' => session()->getFlashdata('success'),
            'errors' => array_merge($errors, (array) (session()->getFlashdata('errors') ?? [])),
        ]);
    }

    public function save()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        $tenantId = (int) ($this->request->getPost('tenant_id') ?? 0);
        try {
            if ($this->findTenant($this->listChatbotTenants(), $tenantId) === null) {
                throw new \RuntimeException('Lo spazio selezionato non ha il canale WhatsApp attivo.');
            }

            $rules = [];
            foreach ((array) $this->request->getPost('rules') as $index => $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $rule['enabled'] = (int) ($rule['enabled'] ?? 0) === 1;
                $rule['id'] = (string) ($rule['id'] ?? ('rule-' . ((int) $index + 1)));
                $rules[] = $rule;
            }

            (new WhatsAppChatbotService())->saveConfig($tenantId, [
                'enabled' => (int) ($this->request->getPost('enabled') ?? 0) === 1,
                'response_window_hours' => (int) ($this->request->getPost('response_window_hours') ?? 168),
                'prompt_text' => (string) ($this->request->getPost('prompt_text') ?? ''),
                'fallback_reply' => (string) ($this->request->getPost('fallback_reply') ?? ''),
                'open_on' => (array) $this->request->getPost('open_on'),
                'rules' => $rules,
            ], (int) (session()->get('platform_user_id') ?? 0));

            return redirect()->to($this->chatbotUrl($tenantId))
                ->with('success', 'Chatbot WhatsApp aggiornato per lo spazio selezionato.');
        } catch (\Throwable $e) {
            log_message('error', 'PlatformWhatsAppChatbotController::save failed: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'platform_user_id' => (int) (session()->get('platform_user_id') ?? 0),
            ]);
            return redirect()->to($this->chatbotUrl($tenantId))
                ->withInput()
                ->with('errors', [$e->getMessage()]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function listChatbotTenants(): array
    {
        $rows = (new PlatformTenantsModel())
            ->where('is_active', 1)
            ->orderBy('tenant_name', 'ASC')
            ->findAll();

        $settingsService = new AppointmentNotificationSettingsService();
        $catalog = [];
        foreach ($rows as $tenant) {
            $tenantId = (int) ($tenant['id_tenant'] ?? 0);
            if ($tenantId <= 0) {
                continue;
            }

            $settings = $settingsService->resolveTenantSettings($tenantId);
            if (empty($settings['available_channels'][AppointmentNotificationSettingsService::CHANNEL_WHATSAPP])) {
                continue;
            }

            $catalog[] = $tenant;
        }

        return $catalog;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @return array<string, mixed>|null
     */
    private function findTenant(array $catalog, int $tenantId): ?array
    {
        foreach ($catalog as $tenant) {
            if ((int) ($tenant['id_tenant'] ?? 0) === $tenantId) {
                return $tenant;
            }
        }

        return null;
    }

    private function chatbotUrl(int $tenantId = 0, bool $save = false): string
    {
        $url = portal_platform_url('chatbot-whatsapp' . ($save ? '/save' : ''));
        return !$save && $tenantId > 0 ? $url . '?tenant_id=' . $tenantId : $url;
    }

    private function ensurePlatformAdminPage()
    {
        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return redirect()->to(portal_public_access_url('login'));
        }

        if (!$this->platformAdminAccess->canAccessPlatformConsole()) {
            return redirect()->to(portal_public_access_url('login'))
                ->with('login_error', 'Area piattaforma riservata agli account master.');
        }

        return null;
    }
}
