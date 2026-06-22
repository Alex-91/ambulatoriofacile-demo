<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\AppointmentNotificationDashboardService;
use App\Services\AppointmentNotificationSettingsService;
use App\Services\TenantCatalogService;
use App\Services\TenantContextService;

class AppointmentNotifications extends BaseController
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

        if (!portal_current_path_matches('login/spazio/notifiche-appuntamenti')) {
            return redirect()->to(portal_tenant_space_url('notifiche-appuntamenti'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return redirect()->to(site_url('/'));
        }

        $tenant = $this->tenantCatalog->getTenantById($context->tenantId);
        if (!$tenant) {
            return redirect()->to(site_url('/'))->with('error', 'Spazio cliente non trovato.');
        }

        $settings = (new AppointmentNotificationSettingsService())->resolveTenantSettings($context->tenantId);
        $dashboard = (new AppointmentNotificationDashboardService())->buildTenantDashboard($tenant, $settings, 30, 60);

        return view('tenant/appointment_notifications', [
            'tenantContext' => $context,
            'tenant' => $tenant,
            'settings' => $settings,
            'dashboard' => $dashboard,
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
            return redirect()->to(site_url('/'));
        }

        try {
            $payload = [
                'message_types' => [
                    AppointmentNotificationSettingsService::TYPE_PATIENT_BOOKING => [
                        'enabled' => (int) ($this->request->getPost('patient_booking_enabled') ?? 0) === 1,
                        'channels' => (array) $this->request->getPost('patient_booking_channels'),
                    ],
                    AppointmentNotificationSettingsService::TYPE_DOCTOR_CROSS_BOOKING => [
                        'enabled' => (int) ($this->request->getPost('doctor_cross_booking_enabled') ?? 0) === 1,
                        'channels' => (array) $this->request->getPost('doctor_cross_booking_channels'),
                    ],
                    AppointmentNotificationSettingsService::TYPE_REMINDER => [
                        'enabled' => (int) ($this->request->getPost('appointment_reminder_enabled') ?? 0) === 1,
                        'channels' => (array) $this->request->getPost('appointment_reminder_channels'),
                        'lead_days' => (int) ($this->request->getPost('appointment_reminder_lead_days') ?? 2),
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

    private function ensureAllowed()
    {
        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return redirect()->to(portal_public_access_url('login'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return redirect()->to(site_url('/'));
        }

        if ($context->tenantRole !== 'tenant_master') {
            return redirect()->to(site_url('/'))->with('error', 'Solo il tenant master puo gestire le notifiche appuntamenti.');
        }

        if ((int) (session()->get('platform_user_id') ?? 0) <= 0) {
            return redirect()->to(site_url('/'))->with('error', 'Funzione disponibile solo per accessi piattaforma.');
        }

        return null;
    }
}
