<?php

namespace App\Services;

use App\Models\PlatformFeaturesModel;
use App\Models\PlatformTenantFeaturePreferencesModel;
use Config\Database;

class AppointmentNotificationSettingsService
{
    public const FEATURE_NOTIFICATIONS = 'appointment_notifications';
    public const FEATURE_SMS = 'appointment_notifications_sms';
    public const FEATURE_WHATSAPP = 'appointment_notifications_whatsapp';

    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_WHATSAPP = 'wa';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_OTP = 'otp';

    public const TYPE_PATIENT_BOOKING = 'patient_booking';
    public const TYPE_DOCTOR_CROSS_BOOKING = 'doctor_cross_booking';
    public const TYPE_REMINDER = 'appointment_reminder';

    private \CodeIgniter\Database\BaseConnection $platformDb;
    private TenantFeatureService $tenantFeatureService;
    private PlatformTenantFeaturePreferencesModel $preferencesModel;
    private PlatformFeaturesModel $featuresModel;

    public function __construct()
    {
        $this->platformDb = Database::connect('platform');
        $this->tenantFeatureService = new TenantFeatureService();
        $this->preferencesModel = new PlatformTenantFeaturePreferencesModel();
        $this->featuresModel = new PlatformFeaturesModel();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveTenantSettings(int $tenantId): array
    {
        $states = $this->indexStatesByFeatureKey($tenantId);

        $moduleState = $states[self::FEATURE_NOTIFICATIONS] ?? [];
        $smsState = $states[self::FEATURE_SMS] ?? [];
        $waState = $states[self::FEATURE_WHATSAPP] ?? [];

        $moduleFeatureId = (int) ($moduleState['id_feature'] ?? 0);
        $moduleAvailable = (bool) ($moduleState['effective_enabled'] ?? false);
        $availableChannels = [
            self::CHANNEL_SMS => $moduleAvailable && (bool) ($smsState['effective_enabled'] ?? false),
            self::CHANNEL_WHATSAPP => $moduleAvailable && (bool) ($waState['effective_enabled'] ?? false),
            self::CHANNEL_EMAIL => $moduleAvailable,
            self::CHANNEL_OTP => $moduleAvailable,
        ];

        $preferenceRow = $moduleFeatureId > 0
            ? $this->preferencesModel
                ->where('id_tenant', $tenantId)
                ->where('id_feature', $moduleFeatureId)
                ->first()
            : null;

        $config = $this->sanitizeConfig(
            $this->decodeConfig((string) ($preferenceRow['config_json'] ?? '')),
            array_keys(array_filter($availableChannels, static fn(bool $enabled): bool => $enabled))
        );

        $messageTypeRows = [];
        foreach ($this->messageTypeDefinitions() as $messageType => $meta) {
            $messageConfig = (array) ($config['message_types'][$messageType] ?? []);
            $configuredChannels = $this->normalizeChannels((array) ($messageConfig['channels'] ?? []));
            $effectiveChannels = $moduleAvailable
                ? array_values(array_intersect($configuredChannels, array_keys(array_filter($availableChannels))))
                : [];

            $row = [
                'key' => $messageType,
                'label' => (string) ($meta['label'] ?? $messageType),
                'description' => (string) ($meta['description'] ?? ''),
                'enabled' => $moduleAvailable ? (bool) ($messageConfig['enabled'] ?? false) : false,
                'configured_channels' => $configuredChannels,
                'effective_channels' => $effectiveChannels,
                'sms_selected' => in_array(self::CHANNEL_SMS, $configuredChannels, true),
                'wa_selected' => in_array(self::CHANNEL_WHATSAPP, $configuredChannels, true),
                'email_selected' => in_array(self::CHANNEL_EMAIL, $configuredChannels, true),
                'otp_selected' => in_array(self::CHANNEL_OTP, $configuredChannels, true),
            ];

            if ($messageType === self::TYPE_REMINDER) {
                $row['lead_days'] = max(0, min(30, (int) ($messageConfig['lead_days'] ?? 2)));
            }

            $messageTypeRows[$messageType] = $row;
        }

        return [
            'tenant_id' => $tenantId,
            'module' => [
                'feature_id' => $moduleFeatureId,
                'available' => $moduleAvailable,
                'feature_name' => (string) ($moduleState['feature_name'] ?? 'Centro notifiche appuntamenti'),
                'description' => (string) ($moduleState['description'] ?? ''),
            ],
            'available_channels' => $availableChannels,
            'message_types' => $messageTypeRows,
            'raw_config' => $config,
            'preference_row' => is_array($preferenceRow) ? $preferenceRow : null,
        ];
    }

    /**
     * @param array<string, mixed> $rawConfig
     * @return array<string, mixed>
     */
    public function saveTenantPreferences(int $tenantId, array $rawConfig, int $updatedByPlatformUserId = 0): array
    {
        $current = $this->resolveTenantSettings($tenantId);
        if (empty($current['module']['available'])) {
            throw new \RuntimeException('Il centro notifiche appuntamenti non e disponibile per questo spazio.');
        }

        $featureId = (int) ($current['module']['feature_id'] ?? 0);
        if ($featureId <= 0) {
            throw new \RuntimeException('Feature notifiche appuntamenti non trovata nel catalogo piattaforma.');
        }

        $availableChannels = array_keys(array_filter(
            (array) ($current['available_channels'] ?? []),
            static fn(bool $enabled): bool => $enabled
        ));

        $config = $this->sanitizeConfig($rawConfig, $availableChannels);

        $ok = $this->preferencesModel->setPreference(
            $tenantId,
            $featureId,
            true,
            $updatedByPlatformUserId > 0 ? $updatedByPlatformUserId : null,
            'tenant_master',
            $config,
            false
        );

        if (!$ok) {
            throw new \RuntimeException('Salvataggio configurazione notifiche appuntamenti non riuscito.');
        }

        return $this->resolveTenantSettings($tenantId);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveDispatchPlan(int $tenantId, string $messageType): array
    {
        $settings = $this->resolveTenantSettings($tenantId);
        $messageType = trim(strtolower($messageType));
        $row = (array) ($settings['message_types'][$messageType] ?? []);

        return [
            'module_available' => (bool) ($settings['module']['available'] ?? false),
            'enabled' => (bool) ($row['enabled'] ?? false),
            'channels' => array_values((array) ($row['effective_channels'] ?? [])),
            'lead_days' => max(0, min(30, (int) ($row['lead_days'] ?? 2))),
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function messageTypeDefinitions(): array
    {
        return [
            self::TYPE_PATIENT_BOOKING => [
                'label' => 'Conferma appuntamento',
                'description' => 'Parte appena l appuntamento viene salvato per il paziente.',
            ],
            self::TYPE_DOCTOR_CROSS_BOOKING => [
                'label' => 'Presa appuntamento da un professionista all altro',
                'description' => 'Parte quando un professionista prenota per un altro professionista dello stesso spazio.',
            ],
            self::TYPE_REMINDER => [
                'label' => 'Reminder appuntamento',
                'description' => 'Parte alcuni giorni prima della visita secondo la regola decisa dal tenant master.',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function channelDefinitions(): array
    {
        return [
            self::CHANNEL_SMS => [
                'label' => 'SMS',
                'icon' => 'fa-comment',
                'description' => 'Disponibilita commerciale e tecnica del canale SMS per questo spazio.',
                'platform_managed' => true,
            ],
            self::CHANNEL_WHATSAPP => [
                'label' => 'WhatsApp',
                'icon' => 'fa-whatsapp',
                'description' => 'Disponibilita commerciale e tecnica del canale WhatsApp per questo spazio.',
                'platform_managed' => true,
            ],
            self::CHANNEL_EMAIL => [
                'label' => 'Email',
                'icon' => 'fa-envelope',
                'description' => 'Canale nativo dell applicazione basato sui recapiti email presenti in agenda e anagrafica.',
                'platform_managed' => false,
            ],
            self::CHANNEL_OTP => [
                'label' => 'OTP',
                'icon' => 'fa-key',
                'description' => 'Genera un codice OTP e lo recapita usando il contatto disponibile del destinatario.',
                'platform_managed' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultConfig(): array
    {
        return [
            'version' => 1,
            'message_types' => [
                self::TYPE_PATIENT_BOOKING => [
                    'enabled' => false,
                    'channels' => [],
                ],
                self::TYPE_DOCTOR_CROSS_BOOKING => [
                    'enabled' => false,
                    'channels' => [],
                ],
                self::TYPE_REMINDER => [
                    'enabled' => false,
                    'channels' => [],
                    'lead_days' => 2,
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexStatesByFeatureKey(int $tenantId): array
    {
        $rows = $this->tenantFeatureService->listFeatureStatesForTenant($tenantId);
        $index = [];

        foreach ($rows as $row) {
            $featureKey = trim((string) ($row['feature_key'] ?? ''));
            if ($featureKey !== '') {
                $index[$featureKey] = $row;
            }
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $availableChannels
     * @return array<string, mixed>
     */
    private function sanitizeConfig(array $config, array $availableChannels): array
    {
        $defaults = $this->defaultConfig();
        $sanitized = $defaults;

        $messageTypes = (array) ($config['message_types'] ?? []);
        foreach ($this->messageTypeDefinitions() as $messageType => $meta) {
            $source = (array) ($messageTypes[$messageType] ?? []);
            $channels = $this->normalizeChannels((array) ($source['channels'] ?? []));
            if ($availableChannels !== []) {
                $channels = array_values(array_intersect($channels, $availableChannels));
            } else {
                $channels = [];
            }

            $sanitized['message_types'][$messageType]['enabled'] = (bool) ($source['enabled'] ?? false);
            $sanitized['message_types'][$messageType]['channels'] = $channels;

            if ($messageType === self::TYPE_REMINDER) {
                $sanitized['message_types'][$messageType]['lead_days'] = max(0, min(30, (int) ($source['lead_days'] ?? 2)));
            }
        }

        return $sanitized;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, mixed> $channels
     * @return array<int, string>
     */
    private function normalizeChannels(array $channels): array
    {
        $normalized = [];
        $supportedChannels = array_keys($this->channelDefinitions());

        foreach ($channels as $channel) {
            $channel = strtolower(trim((string) $channel));
            if (!in_array($channel, $supportedChannels, true)) {
                continue;
            }

            if (!in_array($channel, $normalized, true)) {
                $normalized[] = $channel;
            }
        }

        return $normalized;
    }
}
