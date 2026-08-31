<?php

namespace App\Controllers\Login;

use App\Controllers\BaseController;
use App\Services\PlatformAdminAccessService;
use App\Services\WhatsAppGatewayClient;
use App\Services\WhatsAppSupportLogService;

class PlatformWhatsAppConversationsController extends BaseController
{
    private PlatformAdminAccessService $platformAdminAccess;
    private WhatsAppSupportLogService $supportLog;

    public function __construct()
    {
        helper('portal');
        $this->platformAdminAccess = new PlatformAdminAccessService();
        $this->supportLog = new WhatsAppSupportLogService();
    }

    public function index()
    {
        if ($guard = $this->ensurePlatformAdmin()) {
            return $guard;
        }

        if (!portal_current_path_matches('login/piattaforma/whatsapp')) {
            $url = portal_platform_url('whatsapp');
            $tenantId = (int) ($this->request->getGet('tenant_id') ?? 0);
            if ($tenantId > 0) {
                $url .= '?tenant_id=' . $tenantId;
            }
            return redirect()->to($url);
        }

        $errors = [];
        $tenants = [];
        $selectedTenant = null;
        $payload = $this->supportLog->emptyDashboard();

        try {
            $tenants = $this->supportLog->listTenants();
            $requestedTenantId = (int) ($this->request->getGet('tenant_id') ?? 0);
            if ($requestedTenantId <= 0 && $tenants !== []) {
                $requestedTenantId = (int) ($tenants[0]['id_tenant'] ?? 0);
            }
            $selectedTenant = $this->supportLog->findTenant($tenants, $requestedTenantId);
            if ($requestedTenantId > 0 && $selectedTenant === null) {
                throw new \RuntimeException('Lo spazio richiesto non è abilitato sul gateway WhatsApp.');
            }
            if ($selectedTenant !== null) {
                $payload = $this->supportLog->loadTenantDashboard((int) $selectedTenant['id_tenant'], 100);
                log_message('info', 'Platform WhatsApp support log accessed', [
                    'platform_user_id' => (int) (session()->get('platform_user_id') ?? 0),
                    'tenant_id' => (int) $selectedTenant['id_tenant'],
                ]);
            }
        } catch (\Throwable $e) {
            log_message('error', 'PlatformWhatsAppConversationsController::index failed: ' . $e->getMessage());
            $errors['generic'] = $e->getMessage();
        }

        $selectedTenantId = (int) ($selectedTenant['id_tenant'] ?? 0);

        return view('admin/platform_whatsapp_conversations', [
            'menu_items' => [],
            'tenantCatalog' => $tenants,
            'selectedTenant' => $selectedTenant,
            'accountStatus' => $payload['account_status'],
            'conversationDashboard' => $payload['conversation_dashboard'],
            'gatewayConfigured' => WhatsAppGatewayClient::isConfigured(),
            'refreshUrl' => $selectedTenantId > 0
                ? portal_platform_url('whatsapp/refresh') . '?tenant_id=' . $selectedTenantId
                : '',
            'errors' => $errors,
            'platformMasterEmails' => $this->platformAdminAccess->configuredMasterEmails(),
            'platformUser' => $this->platformAdminAccess->currentPlatformUser(),
        ]);
    }

    public function refresh()
    {
        if ($guard = $this->ensurePlatformAdmin(true)) {
            return $guard;
        }

        try {
            $tenantId = (int) ($this->request->getGet('tenant_id') ?? 0);
            $tenants = $this->supportLog->listTenants();
            $selectedTenant = $this->supportLog->findTenant($tenants, $tenantId);
            if ($selectedTenant === null) {
                return $this->response->setStatusCode(404)->setJSON([
                    'ok' => false,
                    'message' => 'Spazio WhatsApp non disponibile.',
                ]);
            }

            return $this->response->setJSON(array_merge(
                [
                    'ok' => true,
                    'tenant' => $selectedTenant,
                ],
                $this->supportLog->loadTenantDashboard($tenantId, 100)
            ));
        } catch (\Throwable $e) {
            log_message('error', 'PlatformWhatsAppConversationsController::refresh failed: ' . $e->getMessage());
            return $this->response->setStatusCode(502)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function ensurePlatformAdmin(bool $json = false)
    {
        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return $json
                ? $this->response->setStatusCode(401)->setJSON(['ok' => false, 'message' => 'Sessione non autenticata.'])
                : redirect()->to(portal_public_access_url('login'));
        }

        if (!$this->platformAdminAccess->canAccessPlatformConsole()) {
            return $json
                ? $this->response->setStatusCode(403)->setJSON(['ok' => false, 'message' => 'Area riservata agli account master.'])
                : redirect()->to(portal_public_access_url('login'))
                    ->with('login_error', 'Area piattaforma riservata agli account master.');
        }

        return null;
    }
}
