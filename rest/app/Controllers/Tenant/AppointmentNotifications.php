<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\AppointmentNotificationDashboardService;
use App\Services\AppointmentNotificationSettingsService;
use App\Services\TenantCatalogService;
use App\Services\TenantContextService;
use App\Services\WhatsAppGatewayClient;
use App\Services\WhatsAppTenantConsoleService;

class AppointmentNotifications extends BaseController
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
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        if (!portal_current_path_matches('login/spazio/notifiche-appuntamenti')) {
            return redirect()->to(portal_tenant_space_url('notifiche-appuntamenti'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        $tenant = $this->tenantCatalog->getTenantById($context->tenantId);
        if (!$tenant) {
            return $this->redirectToLogin('Spazio cliente non trovato. Effettua di nuovo il login.');
        }

        $settings = (new AppointmentNotificationSettingsService())->resolveTenantSettings($context->tenantId);
        $dashboard = (new AppointmentNotificationDashboardService())->buildTenantDashboard($tenant, $settings, 30, 60);
        $whatsAppConsole = (new WhatsAppTenantConsoleService())->emptyPayload($context->tenantId);
        if (!empty($settings['available_channels'][AppointmentNotificationSettingsService::CHANNEL_WHATSAPP])) {
            try {
                $whatsAppConsole = (new WhatsAppTenantConsoleService())->build($context->tenantId);
            } catch (\Throwable $e) {
                log_message('error', 'Tenant\\AppointmentNotifications::index WhatsApp console failed: ' . $e->getMessage(), [
                    'tenant_id' => $context->tenantId,
                ]);
                $whatsAppConsole['gateway_configured'] = WhatsAppGatewayClient::isConfigured();
                $whatsAppConsole['tenant_routed'] = WhatsAppGatewayClient::isRoutedToGateway($context->tenantId);
                $whatsAppConsole['gateway_available'] = WhatsAppGatewayClient::isAvailableForTenant($context->tenantId);
                $whatsAppConsole['load_error'] = 'In questo momento non è possibile leggere lo stato del collegamento WhatsApp. Riprova tra poco.';
            }
        }

        return view('tenant/appointment_notifications', [
            'tenantContext' => $context,
            'tenant' => $tenant,
            'settings' => $settings,
            'dashboard' => $dashboard,
            'whatsAppConsole' => $whatsAppConsole,
            'whatsAppUrls' => [
                'refresh' => portal_tenant_space_url('notifiche-appuntamenti/whatsapp/refresh'),
                'pair' => portal_tenant_space_url('notifiche-appuntamenti/whatsapp/pair'),
                'reconnect' => portal_tenant_space_url('notifiche-appuntamenti/whatsapp/reconnect'),
                'disconnect' => portal_tenant_space_url('notifiche-appuntamenti/whatsapp/disconnect'),
                'change_device' => portal_tenant_space_url('notifiche-appuntamenti/whatsapp/change-device'),
            ],
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
            $payload = [
                'message_types' => [
                    AppointmentNotificationSettingsService::TYPE_PATIENT_BOOKING => [
                        'enabled' => (int) ($this->request->getPost('patient_booking_enabled') ?? 0) === 1,
                        'channels' => (array) $this->request->getPost('patient_booking_channels'),
                        'template' => (string) ($this->request->getPost('patient_booking_template') ?? ''),
                    ],
                    AppointmentNotificationSettingsService::TYPE_DOCTOR_CROSS_BOOKING => [
                        'enabled' => (int) ($this->request->getPost('doctor_cross_booking_enabled') ?? 0) === 1,
                        'channels' => (array) $this->request->getPost('doctor_cross_booking_channels'),
                    ],
                    AppointmentNotificationSettingsService::TYPE_REMINDER => [
                        'enabled' => (int) ($this->request->getPost('appointment_reminder_enabled') ?? 0) === 1,
                        'channels' => (array) $this->request->getPost('appointment_reminder_channels'),
                        'lead_days' => (int) ($this->request->getPost('appointment_reminder_lead_days') ?? 2),
                        'template' => (string) ($this->request->getPost('appointment_reminder_template') ?? ''),
                    ],
                ],
            ];

            (new AppointmentNotificationSettingsService())->saveTenantPreferences(
                $context->tenantId,
                $payload,
                (int) (session()->get('platform_user_id') ?? 0)
            );

            return redirect()
                ->to(portal_tenant_space_url('notifiche-appuntamenti'))
                ->with('success', 'Configurazione notifiche appuntamenti aggiornata con successo.');
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\AppointmentNotifications::save failed: ' . $e->getMessage());

            return redirect()
                ->to(portal_tenant_space_url('notifiche-appuntamenti'))
                ->withInput()
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function whatsappPair()
    {
        if ($guard = $this->ensureWhatsAppAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        try {
            (new WhatsAppTenantConsoleService())->startPairing($context->tenantId, $context->tenantName);
            $this->logWhatsAppAction('pair_started', $context->tenantId);

            return $this->whatsAppRedirect()
                ->with('success', 'Collegamento WhatsApp avviato. Inquadra il QR con il telefono da collegare.');
        } catch (\Throwable $e) {
            return $this->whatsAppActionError('whatsappPair', $context->tenantId, $e);
        }
    }

    public function whatsappReconnect()
    {
        if ($guard = $this->ensureWhatsAppAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        try {
            (new WhatsAppTenantConsoleService())->reconnect($context->tenantId);
            $this->logWhatsAppAction('reconnect_requested', $context->tenantId);

            return $this->whatsAppRedirect()
                ->with('success', 'Riconnessione WhatsApp richiesta correttamente.');
        } catch (\Throwable $e) {
            return $this->whatsAppActionError('whatsappReconnect', $context->tenantId, $e);
        }
    }

    public function whatsappDisconnect()
    {
        if ($guard = $this->ensureWhatsAppAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        try {
            (new WhatsAppTenantConsoleService())->disconnect($context->tenantId);
            $this->logWhatsAppAction('device_disconnected', $context->tenantId);

            return $this->whatsAppRedirect()
                ->with('success', 'Dispositivo WhatsApp scollegato dallo spazio.');
        } catch (\Throwable $e) {
            return $this->whatsAppActionError('whatsappDisconnect', $context->tenantId, $e);
        }
    }

    public function whatsappChangeDevice()
    {
        if ($guard = $this->ensureWhatsAppAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        try {
            (new WhatsAppTenantConsoleService())->changeDevice($context->tenantId, $context->tenantName);
            $this->logWhatsAppAction('device_change_started', $context->tenantId);

            return $this->whatsAppRedirect()
                ->with('success', 'Il vecchio dispositivo è stato scollegato. Inquadra il nuovo QR per completare il cambio.');
        } catch (\Throwable $e) {
            return $this->whatsAppActionError('whatsappChangeDevice', $context->tenantId, $e);
        }
    }

    public function whatsappRefresh()
    {
        if ($guard = $this->ensureWhatsAppAllowed(true)) {
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
            return $this->response
                ->setHeader('Cache-Control', 'no-store')
                ->setJSON([
                    'ok' => true,
                    'console' => (new WhatsAppTenantConsoleService())->build($context->tenantId),
                ]);
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\AppointmentNotifications::whatsappRefresh failed: ' . $e->getMessage(), [
                'tenant_id' => $context->tenantId,
            ]);
            return $this->response
                ->setHeader('Cache-Control', 'no-store')
                ->setStatusCode(502)
                ->setJSON([
                    'ok' => false,
                    'message' => 'Stato WhatsApp temporaneamente non disponibile.',
                ]);
        }
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
            return $json ? $deny(401, 'Sessione non autenticata.') : $this->redirectToLogin();
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $json ? $deny(401, 'Sessione scaduta.') : $this->sessionExpiredRedirect();
        }

        if (!session_has_tenant_master_access()) {
            return $deny(403, 'Solo il responsabile dello studio può gestire le notifiche appuntamenti.');
        }

        if ((int) (session()->get('platform_user_id') ?? 0) <= 0) {
            return $json ? $deny(401, 'Sessione scaduta.') : $this->sessionExpiredRedirect();
        }

        return null;
    }

    private function ensureWhatsAppAllowed(bool $json = false)
    {
        if ($guard = $this->ensureAllowed($json)) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $json
                ? $this->response->setStatusCode(401)->setJSON(['ok' => false, 'message' => 'Sessione scaduta.'])
                : $this->sessionExpiredRedirect();
        }

        $settings = (new AppointmentNotificationSettingsService())->resolveTenantSettings($context->tenantId);
        if (empty($settings['available_channels'][AppointmentNotificationSettingsService::CHANNEL_WHATSAPP])) {
            $message = 'La gestione del dispositivo WhatsApp non è attiva per questo spazio.';
            return $json
                ? $this->response->setStatusCode(404)->setJSON(['ok' => false, 'message' => $message])
                : redirect()->to(portal_tenant_space_url('notifiche-appuntamenti'))->with('error', $message);
        }

        if (!WhatsAppGatewayClient::isAvailableForTenant($context->tenantId)) {
            $message = 'Il collegamento WhatsApp non è ancora stato predisposto dalla piattaforma per questo spazio.';
            return $json
                ? $this->response->setStatusCode(503)->setJSON(['ok' => false, 'message' => $message])
                : $this->whatsAppRedirect()->with('errors', ['generic' => $message]);
        }

        return null;
    }

    private function whatsAppRedirect()
    {
        return redirect()->to(portal_tenant_space_url('notifiche-appuntamenti') . '#whatsapp-device');
    }

    private function whatsAppActionError(string $method, int $tenantId, \Throwable $e)
    {
        log_message('error', 'Tenant\\AppointmentNotifications::' . $method . ' failed: ' . $e->getMessage(), [
            'tenant_id' => $tenantId,
        ]);

        return $this->whatsAppRedirect()
            ->with('errors', ['generic' => 'Operazione WhatsApp non riuscita. Riprova tra poco oppure contatta l’assistenza.']);
    }

    private function logWhatsAppAction(string $action, int $tenantId): void
    {
        log_message('info', 'Tenant WhatsApp device action', [
            'action' => $action,
            'tenant_id' => $tenantId,
            'platform_user_id' => (int) (session()->get('platform_user_id') ?? 0),
        ]);
    }
}
