<?php

namespace App\Services;

class WhatsAppCampaignReadinessService
{
    private AppointmentNotificationSettingsService $settings;
    private TenantFeatureService $features;
    private WhatsAppTenantConsoleService $console;

    public function __construct(
        ?AppointmentNotificationSettingsService $settings = null,
        ?TenantFeatureService $features = null,
        ?WhatsAppTenantConsoleService $console = null
    ) {
        $this->settings = $settings ?? new AppointmentNotificationSettingsService();
        $this->features = $features ?? new TenantFeatureService();
        $this->console = $console ?? new WhatsAppTenantConsoleService();
    }

    /** @return array<string, mixed> */
    public function resolve(int $tenantId): array
    {
        $result = [
            'ready' => false,
            'channel_active' => false,
            'gateway_configured' => false,
            'tenant_routed' => false,
            'gateway_available' => false,
            'account_connected' => false,
            'account_logged_in' => false,
            'reason' => 'WhatsApp non è disponibile per questo spazio.',
        ];

        if ($tenantId <= 0) {
            return $result;
        }

        try {
            $settings = $this->settings->resolveTenantSettings($tenantId);
            $featureMap = $this->features->resolveEffectiveFeatureMapForTenant($tenantId);
            $platformChannelControls = (array) ($settings['platform_channel_controls'] ?? []);
            $whatsAppControl = (array) ($platformChannelControls[AppointmentNotificationSettingsService::CHANNEL_WHATSAPP] ?? []);

            $result['channel_active'] = !empty($settings['module']['available'])
                && !empty($featureMap[AppointmentNotificationSettingsService::FEATURE_WHATSAPP])
                && (bool) ($whatsAppControl['enabled'] ?? true);

            if (!$result['channel_active']) {
                $result['reason'] = 'Il canale WhatsApp non è attivo per questo spazio. Attivalo nelle notifiche appuntamenti.';
                return $result;
            }

            $console = $this->console->build($tenantId);
            $account = (array) ($console['account'] ?? []);
            $result['gateway_configured'] = !empty($console['gateway_configured']);
            $result['tenant_routed'] = !empty($console['tenant_routed']);
            $result['gateway_available'] = !empty($console['gateway_available']);
            $result['account_connected'] = !empty($account['connected']);
            $result['account_logged_in'] = !empty($account['logged_in']);

            if (!$result['gateway_available']) {
                $result['reason'] = trim((string) ($console['setup_message'] ?? ''))
                    ?: 'Il gateway WhatsApp non è ancora predisposto per questo spazio.';
                return $result;
            }

            if (!$result['account_connected'] || !$result['account_logged_in']) {
                $result['reason'] = 'Collega il dispositivo WhatsApp dello studio prima di accodare una campagna.';
                return $result;
            }

            $result['ready'] = true;
            $result['reason'] = '';
        } catch (\Throwable $e) {
            log_message('error', 'WhatsApp campaign readiness check failed: ' . $e->getMessage(), ['tenant_id' => $tenantId]);
            $result['reason'] = 'Non è possibile verificare lo stato di WhatsApp in questo momento. Riprova tra poco.';
        }

        return $result;
    }
}
