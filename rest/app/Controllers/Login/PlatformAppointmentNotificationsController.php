<?php

namespace App\Controllers\Login;

use App\Controllers\BaseController;
use App\Services\AppointmentNotificationDashboardService;
use App\Services\AppointmentReminderDispatchService;
use App\Services\PlatformAdminAccessService;

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

        $days = max(7, min(180, (int) ($this->request->getGet('days') ?? 30)));
        $dashboard = (new AppointmentNotificationDashboardService())->buildPlatformDashboard($days, 80);

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
        ]);
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
}
