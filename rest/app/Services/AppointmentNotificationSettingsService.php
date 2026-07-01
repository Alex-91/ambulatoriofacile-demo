<?php

namespace App\Services;

use App\Models\PlatformFeaturesModel;
use App\Models\PlatformTenantFeaturePreferencesModel;
use App\Models\PlatformTenantFeaturesModel;
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
    private PlatformTenantFeaturesModel $tenantFeaturesModel;
    private PlatformFeaturesModel $featuresModel;

    public function __construct()
    {
        $this->platformDb = Database::connect('platform');
        $this->tenantFeatureService = new TenantFeatureService();
        $this->preferencesModel = new PlatformTenantFeaturePreferencesModel();
        $this->tenantFeaturesModel = new PlatformTenantFeaturesModel();
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
        $moduleOverrideRow = $this->findTenantFeatureOverrideRow($tenantId, $moduleFeatureId);
        $platformFeatureConfig = $this->decodeConfig(trim((string) ($moduleOverrideRow['config_json'] ?? '')));
        $platformMessageTypeControls = $this->buildPlatformMessageTypeControls($platformFeatureConfig);
        $platformChannelControls = $this->buildPlatformChannelControls($platformFeatureConfig);
        $availableChannels = [
            self::CHANNEL_SMS => $moduleAvailable
                && (bool) ($smsState['effective_enabled'] ?? false)
                && (bool) ($platformChannelControls[self::CHANNEL_SMS]['enabled'] ?? true),
            self::CHANNEL_WHATSAPP => $moduleAvailable
                && (bool) ($waState['effective_enabled'] ?? false)
                && (bool) ($platformChannelControls[self::CHANNEL_WHATSAPP]['enabled'] ?? true),
            self::CHANNEL_EMAIL => $moduleAvailable
                && (bool) ($platformChannelControls[self::CHANNEL_EMAIL]['enabled'] ?? true),
            self::CHANNEL_OTP => $moduleAvailable
                && (bool) ($platformChannelControls[self::CHANNEL_OTP]['enabled'] ?? true),
        ];

        $preferenceRow = $moduleFeatureId > 0
            ? $this->preferencesModel
                ->where('id_tenant', $tenantId)
                ->where('id_feature', $moduleFeatureId)
                ->first()
            : null;

        $rawPreferenceConfig = trim((string) ($preferenceRow['config_json'] ?? ''));
        $usingDefaultPreferences = $rawPreferenceConfig === '';

        $config = $this->sanitizeConfig(
            $this->decodeConfig($rawPreferenceConfig),
            array_keys(array_filter($availableChannels, static fn(bool $enabled): bool => $enabled))
        );

        $messageTypeRows = [];
        foreach ($this->messageTypeDefinitions() as $messageType => $meta) {
            $messageConfig = (array) ($config['message_types'][$messageType] ?? []);
            $configuredChannels = $this->normalizeChannels((array) ($messageConfig['channels'] ?? []));
            $platformEnabled = (bool) ($platformMessageTypeControls[$messageType]['enabled'] ?? true);
            $tenantCanManage = $moduleAvailable && $platformEnabled;
            $effectiveChannels = $tenantCanManage
                ? array_values(array_intersect($configuredChannels, array_keys(array_filter($availableChannels))))
                : [];

            $row = [
                'key' => $messageType,
                'label' => (string) ($meta['label'] ?? $messageType),
                'description' => (string) ($meta['description'] ?? ''),
                'enabled' => $tenantCanManage ? (bool) ($messageConfig['enabled'] ?? false) : false,
                'tenant_enabled' => (bool) ($messageConfig['enabled'] ?? false),
                'platform_enabled' => $platformEnabled,
                'tenant_can_manage' => $tenantCanManage,
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
            'platform_message_type_controls' => $platformMessageTypeControls,
            'platform_channel_controls' => $platformChannelControls,
            'raw_config' => $config,
            'preference_row' => is_array($preferenceRow) ? $preferenceRow : null,
            'feature_override_row' => is_array($moduleOverrideRow) ? $moduleOverrideRow : null,
            'using_default_preferences' => $usingDefaultPreferences,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function resolvePlatformMessageTypeControls(int $tenantId): array
    {
        $states = $this->indexStatesByFeatureKey($tenantId);
        $moduleState = $states[self::FEATURE_NOTIFICATIONS] ?? [];
        $moduleFeatureId = (int) ($moduleState['id_feature'] ?? 0);
        $moduleOverrideRow = $this->findTenantFeatureOverrideRow($tenantId, $moduleFeatureId);

        return $this->buildPlatformMessageTypeControls(
            $this->decodeConfig(trim((string) ($moduleOverrideRow['config_json'] ?? '')))
        );
    }

    /**
     * @param array<int, string> $enabledMessageTypes
     * @return array<string, mixed>
     */
    public function mergePlatformMessageTypeControlsIntoConfig(array $existingFeatureConfig, array $enabledMessageTypes): array
    {
        $config = is_array($existingFeatureConfig) ? $existingFeatureConfig : [];
        $enabledMessageTypes = $this->normalizeMessageTypes($enabledMessageTypes);
        $controls = [];

        foreach ($this->messageTypeDefinitions() as $messageType => $meta) {
            $controls[$messageType] = [
                'enabled' => in_array($messageType, $enabledMessageTypes, true),
            ];
        }

        $config['message_type_controls'] = $controls;

        return $config;
    }

    /**
     * @param array<int, string> $enabledChannels
     * @return array<string, mixed>
     */
    public function mergePlatformChannelControlsIntoConfig(array $existingFeatureConfig, array $enabledChannels): array
    {
        $config = is_array($existingFeatureConfig) ? $existingFeatureConfig : [];
        $enabledChannels = $this->normalizeChannels($enabledChannels);
        $controls = [];

        foreach ($this->channelDefinitions() as $channelKey => $meta) {
            $controls[$channelKey] = [
                'enabled' => in_array($channelKey, $enabledChannels, true),
            ];
        }

        $config['channel_controls'] = $controls;

        return $config;
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
        $config = $this->preserveLockedMessageTypePreferences(
            $config,
            (array) ($current['raw_config'] ?? []),
            (array) ($current['message_types'] ?? [])
        );
        $this->assertEnabledMessageTypesHaveChannels($config, (array) ($current['message_types'] ?? []));

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
                'label' => 'Presa appuntamento da un medico a un altro medico',
                'description' => 'Parte quando un medico prenota per un altro medico dello stesso spazio.',
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
                'platform_default_enabled' => false,
            ],
            self::CHANNEL_WHATSAPP => [
                'label' => 'WhatsApp',
                'icon' => 'fa-whatsapp',
                'description' => 'Disponibilita commerciale e tecnica del canale WhatsApp per questo spazio.',
                'platform_managed' => true,
                'platform_default_enabled' => false,
            ],
            self::CHANNEL_EMAIL => [
                'label' => 'Email',
                'icon' => 'fa-envelope',
                'description' => 'Canale nativo dell applicazione basato sui recapiti email presenti in agenda e anagrafica.',
                'platform_managed' => false,
                'platform_default_enabled' => true,
            ],
            self::CHANNEL_OTP => [
                'label' => 'OTP',
                'icon' => 'fa-key',
                'description' => 'Genera un codice OTP e lo recapita usando il contatto disponibile del destinatario.',
                'platform_managed' => false,
                'platform_default_enabled' => true,
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
                    // Keep the first-run default focused on the internal workflow
                    // that triggered the feature request, without enabling patient
                    // communications until the tenant master makes an explicit choice.
                    'enabled' => true,
                    'channels' => [self::CHANNEL_OTP],
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
     * @param array<string, mixed> $config
     */
    private function assertEnabledMessageTypesHaveChannels(array $config, array $currentMessageTypes = []): void
    {
        $messageTypes = (array) ($config['message_types'] ?? []);

        foreach ($this->messageTypeDefinitions() as $messageType => $meta) {
            if ($currentMessageTypes !== [] && empty($currentMessageTypes[$messageType]['tenant_can_manage'])) {
                continue;
            }

            $row = (array) ($messageTypes[$messageType] ?? []);
            $enabled = (bool) ($row['enabled'] ?? false);
            $channels = array_values((array) ($row['channels'] ?? []));
            if (!$enabled || $channels !== []) {
                continue;
            }

            $label = trim((string) ($meta['label'] ?? $messageType));
            throw new \RuntimeException('Per "' . $label . '" devi selezionare almeno un canale disponibile.');
        }
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
     * @param array<string, mixed> $featureConfig
     * @return array<string, array<string, mixed>>
     */
    private function buildPlatformMessageTypeControls(array $featureConfig): array
    {
        $controls = (array) ($featureConfig['message_type_controls'] ?? []);
        $rows = [];

        foreach ($this->messageTypeDefinitions() as $messageType => $meta) {
            $row = (array) ($controls[$messageType] ?? []);
            $rows[$messageType] = [
                'key' => $messageType,
                'label' => (string) ($meta['label'] ?? $messageType),
                'description' => (string) ($meta['description'] ?? ''),
                'enabled' => !array_key_exists('enabled', $row) || (bool) ($row['enabled'] ?? false),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $featureConfig
     * @return array<string, array<string, mixed>>
     */
    private function buildPlatformChannelControls(array $featureConfig): array
    {
        $controls = (array) ($featureConfig['channel_controls'] ?? []);
        $rows = [];

        foreach ($this->channelDefinitions() as $channelKey => $meta) {
            $row = (array) ($controls[$channelKey] ?? []);
            $defaultEnabled = (bool) ($meta['platform_default_enabled'] ?? false);
            $rows[$channelKey] = [
                'key' => $channelKey,
                'label' => (string) ($meta['label'] ?? $channelKey),
                'description' => (string) ($meta['description'] ?? ''),
                'enabled' => array_key_exists('enabled', $row)
                    ? (bool) ($row['enabled'] ?? false)
                    : $defaultEnabled,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $currentConfig
     * @param array<string, array<string, mixed>> $currentMessageTypes
     * @return array<string, mixed>
     */
    private function preserveLockedMessageTypePreferences(array $config, array $currentConfig, array $currentMessageTypes): array
    {
        $currentRows = (array) ($currentConfig['message_types'] ?? []);

        foreach ($this->messageTypeDefinitions() as $messageType => $meta) {
            if (!empty($currentMessageTypes[$messageType]['tenant_can_manage'])) {
                continue;
            }

            if (isset($currentRows[$messageType]) && is_array($currentRows[$messageType])) {
                $config['message_types'][$messageType] = $currentRows[$messageType];
            }
        }

        return $config;
    }

    /**
     * @param array<int, mixed> $messageTypes
     * @return array<int, string>
     */
    private function normalizeMessageTypes(array $messageTypes): array
    {
        $supportedTypes = array_keys($this->messageTypeDefinitions());
        $normalized = [];

        foreach ($messageTypes as $messageType) {
            $messageType = strtolower(trim((string) $messageType));
            if (!in_array($messageType, $supportedTypes, true)) {
                continue;
            }

            if (!in_array($messageType, $normalized, true)) {
                $normalized[] = $messageType;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTenantFeatureOverrideRow(int $tenantId, int $featureId): ?array
    {
        if ($tenantId <= 0 || $featureId <= 0) {
            return null;
        }

        $row = $this->tenantFeaturesModel
            ->where('id_tenant', $tenantId)
            ->where('id_feature', $featureId)
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int, mixed> $channels
     * @return array<int, string>
     */
    public function normalizeChannels(array $channels): array
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
