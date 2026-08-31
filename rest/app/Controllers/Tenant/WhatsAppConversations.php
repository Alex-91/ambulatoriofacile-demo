<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\TenantContextService;
use App\Services\WhatsAppConversationService;
use App\Services\WhatsAppGatewayClient;

class WhatsAppConversations extends BaseController
{
    private TenantContextService $tenantContext;
    private WhatsAppConversationService $conversations;

    public function __construct()
    {
        helper('portal');
        $this->tenantContext = new TenantContextService();
        $this->conversations = new WhatsAppConversationService();
    }

    public function index()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        if (!portal_current_path_matches('login/spazio/whatsapp')) {
            return redirect()->to(portal_tenant_space_url('whatsapp'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        $loadErrors = [];
        try {
            $payload = $this->loadDashboard($context->tenantId);
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\WhatsAppConversations::index failed: ' . $e->getMessage(), [
                'tenant_id' => $context->tenantId,
            ]);
            $payload = $this->emptyDashboard();
            $loadErrors = ['generic' => $e->getMessage()];
        }

        $menuDataAdmin = session()->get('menuDataAdmin');
        $sidebarMenuItems = is_array($menuDataAdmin['result'] ?? null) ? $menuDataAdmin['result'] : [];
        $headerMenuItems = $sidebarMenuItems !== [] ? $sidebarMenuItems : (session()->get('header_menu_items') ?? []);

        return view('tenant/whatsapp_conversations', [
            'tenantContext' => $context,
            'accountStatus' => $payload['account_status'],
            'conversationDashboard' => $payload['conversation_dashboard'],
            'menu_items' => $headerMenuItems,
            'sidebarMenuItems' => $sidebarMenuItems,
            'refreshUrl' => portal_tenant_space_url('whatsapp/refresh'),
            'sendUrl' => portal_tenant_space_url('whatsapp/send'),
            'selectedPeer' => (string) (session()->getFlashdata('selected_peer') ?? old('to') ?? ''),
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? $loadErrors,
        ]);
    }

    public function refresh()
    {
        if ($guard = $this->ensureAllowed(true)) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'message' => 'Sessione scaduta.',
            ]);
        }

        try {
            return $this->response->setJSON(array_merge(
                ['ok' => true],
                $this->loadDashboard($context->tenantId)
            ));
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\WhatsAppConversations::refresh failed: ' . $e->getMessage(), [
                'tenant_id' => $context->tenantId,
            ]);
            return $this->response->setStatusCode(502)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function send()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        $recipient = $this->normalizeRecipient((string) $this->request->getPost('to'));
        $text = trim((string) $this->request->getPost('text'));
        $textLength = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

        if ($recipient === '') {
            return redirect()
                ->to(portal_tenant_space_url('whatsapp'))
                ->withInput()
                ->with('errors', ['generic' => 'Inserisci un numero WhatsApp internazionale valido.']);
        }
        if ($textLength < 1 || $textLength > 4096) {
            return redirect()
                ->to(portal_tenant_space_url('whatsapp'))
                ->withInput()
                ->with('errors', ['generic' => 'Il messaggio deve contenere da 1 a 4096 caratteri.']);
        }

        try {
            $result = (new WhatsAppGatewayClient())->sendText($context->tenantId, $recipient, $text);
            if (empty($result['ok'])) {
                throw new \RuntimeException((string) ($result['error'] ?? 'Invio WhatsApp non riuscito.'));
            }

            return redirect()
                ->to(portal_tenant_space_url('whatsapp'))
                ->with('selected_peer', $recipient)
                ->with('success', 'Messaggio WhatsApp inviato correttamente.');
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\WhatsAppConversations::send failed: ' . $e->getMessage(), [
                'tenant_id' => $context->tenantId,
            ]);
            return redirect()
                ->to(portal_tenant_space_url('whatsapp'))
                ->withInput()
                ->with('selected_peer', $recipient)
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    /**
     * @return array{account_status:array<string, mixed>, conversation_dashboard:array<string, mixed>}
     */
    private function loadDashboard(int $tenantId): array
    {
        $client = new WhatsAppGatewayClient();
        $status = $client->accountStatus($tenantId);
        $timeline = $client->messages($tenantId, 100);
        if (empty($timeline['ok'])) {
            throw new \RuntimeException((string) ($timeline['error'] ?? 'Conversazioni WhatsApp non disponibili.'));
        }

        return [
            'account_status' => [
                'ok' => !empty($status['ok']),
                'connected' => !empty($status['account']['connected']),
                'logged_in' => !empty($status['account']['logged_in']),
                'state' => trim((string) ($status['account']['state'] ?? 'unknown')),
                'error' => $status['error'] ?? null,
            ],
            'conversation_dashboard' => $this->conversations->buildDashboard(
                is_array($timeline['messages'] ?? null) ? $timeline['messages'] : []
            ),
        ];
    }

    /**
     * @return array{account_status:array<string, mixed>, conversation_dashboard:array<string, mixed>}
     */
    private function emptyDashboard(): array
    {
        return [
            'account_status' => [
                'ok' => false,
                'connected' => false,
                'logged_in' => false,
                'state' => 'unavailable',
                'error' => null,
            ],
            'conversation_dashboard' => $this->conversations->buildDashboard([]),
        ];
    }

    private function normalizeRecipient(string $recipient): string
    {
        $recipient = str_replace([' ', '-', '(', ')'], '', trim($recipient));
        if (str_starts_with($recipient, '00')) {
            $recipient = '+' . substr($recipient, 2);
        } elseif (!str_starts_with($recipient, '+') && preg_match('/^3\d{9}$/', $recipient) === 1) {
            $recipient = '+39' . $recipient;
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $recipient) === 1 ? $recipient : '';
    }

    private function ensureAllowed(bool $json = false)
    {
        $deny = function (int $status, string $message) use ($json) {
            if ($json) {
                return $this->response->setStatusCode($status)->setJSON([
                    'ok' => false,
                    'message' => $message,
                ]);
            }
            return redirect()->to(site_url('/'))->with('error', $message);
        };

        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return $json
                ? $deny(401, 'Sessione non autenticata.')
                : $this->redirectToLogin();
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $json
                ? $deny(401, 'Sessione scaduta.')
                : $this->sessionExpiredRedirect();
        }

        if (!in_array($context->tenantRole, ['tenant_master', 'tenant_admin'], true)) {
            return $deny(403, 'Non sei autorizzato a gestire le conversazioni WhatsApp di questo studio.');
        }
        if ((int) (session()->get('platform_user_id') ?? 0) <= 0) {
            return $json
                ? $deny(401, 'Sessione scaduta.')
                : $this->sessionExpiredRedirect();
        }
        if (!WhatsAppGatewayClient::isAvailableForTenant($context->tenantId)) {
            return $deny(404, 'Il nuovo canale WhatsApp non è attivo per questo studio.');
        }

        return null;
    }
}
