<?php

namespace App\Controllers\Login;

use App\Controllers\BaseController;
use App\Services\AppointmentNotificationDashboardService;
use App\Services\AppointmentReminderDispatchService;
use App\Services\PlatformAdminAccessService;
use App\Services\SmsProviderConfigurationService;
use App\Services\TenantNotificationPolicyService;

class PlatformAppointmentNotificationsController extends BaseController
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

        if (!portal_current_path_matches('login/piattaforma/notifiche-appuntamenti')) {
            return redirect()->to(portal_platform_url('notifiche-appuntamenti'));
        }

        $days = max(1, min(365, (int) ($this->request->getGet('days') ?? 30)));
        $logLimit = (int) ($this->request->getGet('log_limit') ?? 100);
        if (!in_array($logLimit, [50, 100, 250, 500], true)) {
            $logLimit = 100;
        }
        $logFilters = [
            'tenant_id' => max(0, (int) ($this->request->getGet('log_tenant_id') ?? 0)),
            'channel' => trim((string) ($this->request->getGet('log_channel') ?? '')),
            'status' => trim((string) ($this->request->getGet('log_status') ?? '')),
            'message_type' => trim((string) ($this->request->getGet('log_message_type') ?? '')),
            'query' => trim((string) ($this->request->getGet('log_query') ?? '')),
            'limit' => $logLimit,
        ];
        $dashboard = (new AppointmentNotificationDashboardService())->buildPlatformDashboard($days, $logLimit, $logFilters);
        $policyTenantId = max(0, (int) ($this->request->getGet('tenant_id') ?? 0));
        $policyTenant = null;
        foreach ((array) ($dashboard['tenant_rows'] ?? []) as $tenantRow) {
            $candidate = (array) ($tenantRow['tenant'] ?? []);
            if ($policyTenantId <= 0 || (int) ($candidate['id_tenant'] ?? 0) === $policyTenantId) {
                $policyTenant = $candidate;
                $policyTenantId = (int) ($candidate['id_tenant'] ?? 0);
                break;
            }
        }
        if ($policyTenant === null && !empty($dashboard['tenant_rows'][0]['tenant'])) {
            $policyTenant = (array) $dashboard['tenant_rows'][0]['tenant'];
            $policyTenantId = (int) ($policyTenant['id_tenant'] ?? 0);
        }
        $policy = (new TenantNotificationPolicyService())->resolve(
            $policyTenantId,
            (string) ($policyTenant['tenant_name'] ?? '')
        );
        $smsProviderService = new SmsProviderConfigurationService();
        $globalSmsProvider = $smsProviderService->globalForDisplay();
        $tenantSmsProvider = $policyTenantId > 0
            ? $smsProviderService->tenantForDisplay($policyTenantId)
            : [];
        if (
            $tenantSmsProvider !== []
            && empty($tenantSmsProvider['has_tenant_record'])
            && empty($policy['using_defaults'])
        ) {
            $tenantSmsProvider['sender'] = (string) ($policy['sms']['sender'] ?? $tenantSmsProvider['sender'] ?? 'AmbFacile');
        }
        $smsProviderLabel = (string) ($globalSmsProvider['provider_label'] ?? 'SMS');
        $smsProviderConfigured = !empty($globalSmsProvider['configured']);

        return view('admin/platform_appointment_notifications', [
            'menu_items' => [],
            'dashboard' => $dashboard,
            'days' => $days,
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
            'launchFeedback' => session()->getFlashdata('launch_feedback'),
            'platformMasterEmails' => $this->platformAdminAccess->configuredMasterEmails(),
            'platformUser' => $this->platformAdminAccess->currentPlatformUser(),
            'cronConfigured' => trim((string) (env('CRON_ACCESS_TOKEN') ?: '')) !== '',
            'policyTenant' => $policyTenant,
            'policy' => $policy,
            'smsProviderLabel' => $smsProviderLabel,
            'smsProviderConfigured' => $smsProviderConfigured,
            'globalSmsProvider' => $globalSmsProvider,
            'tenantSmsProvider' => $tenantSmsProvider,
        ]);
    }

    public function saveGlobalSmsProvider()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        $tenantId = max(0, (int) ($this->request->getPost('return_tenant_id') ?? 0));
        try {
            (new SmsProviderConfigurationService())->saveGlobal(
                $this->smsProviderInput(),
                (int) (session()->get('platform_user_id') ?? 0)
            );

            return redirect()
                ->to($this->notificationsUrl($tenantId))
                ->with('success', 'Configurazione globale del provider SMS aggiornata.');
        } catch (\Throwable $e) {
            log_message('error', 'Salvataggio provider SMS globale fallito: {message}', ['message' => $e->getMessage()]);
            return redirect()
                ->to($this->notificationsUrl($tenantId))
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function saveTenantSmsProvider()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        $tenantId = max(0, (int) ($this->request->getPost('tenant_id') ?? 0));
        try {
            (new SmsProviderConfigurationService())->saveTenant(
                $tenantId,
                $this->smsProviderInput(),
                (int) (session()->get('platform_user_id') ?? 0)
            );

            return redirect()
                ->to($this->notificationsUrl($tenantId))
                ->with('success', 'Configurazione SMS dello spazio aggiornata.');
        } catch (\Throwable $e) {
            log_message('error', 'Salvataggio provider SMS tenant fallito: {message}', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            return redirect()
                ->to($this->notificationsUrl($tenantId))
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function savePolicy()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        $tenantId = max(0, (int) ($this->request->getPost('tenant_id') ?? 0));
        try {
            $tenant = \Config\Database::connect('platform')
                ->table('platform_tenants')
                ->where('id_tenant', $tenantId)
                ->where('is_active', 1)
                ->get(1)
                ->getRowArray();
            if (!$tenant) {
                throw new \RuntimeException('Spazio cliente non trovato o non attivo.');
            }

            $raw = [
                'email' => (array) ($this->request->getPost('email') ?? []),
                'whatsapp' => (array) ($this->request->getPost('whatsapp') ?? []),
                'sms' => (array) ($this->request->getPost('sms') ?? []),
            ];
            (new TenantNotificationPolicyService())->save(
                $tenantId,
                $raw,
                (int) (session()->get('platform_user_id') ?? 0),
                (string) ($tenant['tenant_name'] ?? '')
            );

            return redirect()
                ->to(portal_platform_url('notifiche-appuntamenti') . '?tenant_id=' . $tenantId)
                ->with('success', 'Parametri di consegna aggiornati per ' . (string) ($tenant['tenant_name'] ?? 'lo spazio') . '.');
        } catch (\Throwable $e) {
            log_message('error', 'PlatformAppointmentNotificationsController::savePolicy failed: ' . $e->getMessage());
            return redirect()
                ->to(portal_platform_url('notifiche-appuntamenti') . ($tenantId > 0 ? ('?tenant_id=' . $tenantId) : ''))
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function launch()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        try {
            $summary = (new AppointmentReminderDispatchService())->run([
                'send' => strtolower(trim((string) ($this->request->getPost('mode') ?? 'dry-run'))) === 'send',
                'tenant_id' => (int) ($this->request->getPost('tenant_id') ?? 0),
                'target_date' => trim((string) ($this->request->getPost('target_date') ?? '')),
                'channel' => trim((string) ($this->request->getPost('channel') ?? 'auto')),
                'force_recipient' => trim((string) ($this->request->getPost('force_recipient') ?? '')),
                'delay_ms' => (int) ($this->request->getPost('delay_ms') ?? 0),
                'limit' => (int) ($this->request->getPost('limit') ?? 0),
                'doctor' => trim((string) ($this->request->getPost('doctor') ?? '')),
            ]);

            return redirect()
                ->to(portal_platform_url('notifiche-appuntamenti') . '?days=' . max(7, min(180, (int) ($this->request->getGet('days') ?? 30))))
                ->with('success', 'Batch notifiche appuntamenti eseguito.')
                ->with('launch_feedback', $summary);
        } catch (\Throwable $e) {
            log_message('error', 'PlatformAppointmentNotificationsController::launch failed: ' . $e->getMessage());

            return redirect()
                ->to(portal_platform_url('notifiche-appuntamenti'))
                ->withInput()
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function run()
    {
        $token = trim((string) (env('CRON_ACCESS_TOKEN') ?: ''));
        $providedToken = trim((string) ($this->request->getGet('token') ?: $this->request->getHeaderLine('X-Cron-Token')));

        if ($token === '' || $providedToken === '' || !hash_equals($token, $providedToken)) {
            return $this->response->setStatusCode(403)->setJSON([
                'ok' => false,
                'message' => 'Forbidden',
            ]);
        }

        try {
            $summary = (new AppointmentReminderDispatchService())->run([
                'send' => strtolower(trim((string) ($this->request->getGet('mode') ?? 'send'))) === 'send',
                'tenant_id' => (int) ($this->request->getGet('tenant_id') ?? 0),
                'target_date' => trim((string) ($this->request->getGet('target_date') ?? '')),
                'channel' => trim((string) ($this->request->getGet('channel') ?? 'auto')),
                'force_recipient' => trim((string) ($this->request->getGet('force_recipient') ?? '')),
                'delay_ms' => (int) ($this->request->getGet('delay_ms') ?? 0),
                'limit' => (int) ($this->request->getGet('limit') ?? 0),
                'doctor' => trim((string) ($this->request->getGet('doctor') ?? '')),
            ]);

            return $this->response->setJSON([
                'ok' => true,
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'PlatformAppointmentNotificationsController::run failed: ' . $e->getMessage());

            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function ensurePlatformAdminPage()
    {
        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return redirect()->to(portal_public_access_url('login'));
        }

        if (!$this->platformAdminAccess->canAccessPlatformConsole()) {
            return redirect()->to(portal_public_access_url('login'))->with('login_error', 'Area piattaforma riservata agli account master.');
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function smsProviderInput(): array
    {
        return [
            'mode' => trim((string) ($this->request->getPost('mode') ?? 'inherit')),
            'provider' => trim((string) ($this->request->getPost('provider') ?? 'smsfactor')),
            'sender' => trim((string) ($this->request->getPost('sender') ?? 'AmbFacile')),
            'smsfactor_base_url' => trim((string) ($this->request->getPost('smsfactor_base_url') ?? '')),
            'smsfactor_timeout_seconds' => $this->request->getPost('smsfactor_timeout_seconds'),
            'smsfactor_push_type' => trim((string) ($this->request->getPost('smsfactor_push_type') ?? 'alert')),
            'smsfactor_api_token' => (string) ($this->request->getPost('smsfactor_api_token') ?? ''),
            'smsfactor_webhook_signature' => (string) ($this->request->getPost('smsfactor_webhook_signature') ?? ''),
            'aruba_username' => (string) ($this->request->getPost('aruba_username') ?? ''),
            'aruba_password' => (string) ($this->request->getPost('aruba_password') ?? ''),
            'clear_smsfactor_api_token' => (bool) $this->request->getPost('clear_smsfactor_api_token'),
            'clear_smsfactor_webhook_signature' => (bool) $this->request->getPost('clear_smsfactor_webhook_signature'),
            'clear_aruba_username' => (bool) $this->request->getPost('clear_aruba_username'),
            'clear_aruba_password' => (bool) $this->request->getPost('clear_aruba_password'),
        ];
    }

    private function notificationsUrl(int $tenantId = 0): string
    {
        return portal_platform_url('notifiche-appuntamenti') . ($tenantId > 0 ? ('?tenant_id=' . $tenantId) : '');
    }
}
