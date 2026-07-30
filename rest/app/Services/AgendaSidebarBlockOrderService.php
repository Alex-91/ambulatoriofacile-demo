<?php

namespace App\Services;

use App\Models\PlatformTenantFeaturePreferencesModel;

class AgendaSidebarBlockOrderService
{
    public const FEATURE_KEY = 'agenda_sidebar_block_order';

    private const CONFIG_KEY = 'sidebar_block_order';

    /**
     * @var array<int, array<string, mixed>>
     */
    private const BLOCKS = [
        [
            'key' => 'sidebar_menu',
            'label' => 'Menu',
            'description' => 'Menu laterale dell agenda con le voci operative dello spazio.',
            'left_origin' => 'fixed',
        ],
        [
            'key' => 'sidebar_calendar',
            'label' => 'Calendario laterale',
            'description' => 'Blocco laterale con data, mini calendario, vista agenda e comandi rapidi.',
            'left_origin' => 'fixed',
        ],
        [
            'key' => 'doctor_selector',
            'label' => 'Scelta professionista',
            'description' => 'Entra nell ordine sinistro solo quando il blocco viene spostato a sinistra dalla configurazione della home agenda.',
            'left_origin' => 'home',
        ],
        [
            'key' => 'patient_search',
            'label' => 'Ricerca visite paziente',
            'description' => 'Entra nell ordine sinistro solo quando il blocco viene spostato a sinistra dalla configurazione della home agenda.',
            'left_origin' => 'home',
        ],
        [
            'key' => 'day_note',
            'label' => 'Note del giorno',
            'description' => 'Entra nell ordine sinistro solo quando il blocco viene spostato a sinistra dalla configurazione della home agenda.',
            'left_origin' => 'home',
        ],
        [
            'key' => 'calendar',
            'label' => 'Agenda',
            'description' => 'Entra nell ordine sinistro solo quando il blocco viene spostato a sinistra dalla configurazione della home agenda.',
            'left_origin' => 'home',
        ],
        [
            'key' => 'memo',
            'label' => 'Memo',
            'description' => 'Entra nell ordine sinistro solo quando il blocco viene spostato a sinistra dalla configurazione della home agenda.',
            'left_origin' => 'home',
        ],
    ];

    private TenantFeatureService $tenantFeatureService;
    private PlatformTenantFeaturePreferencesModel $preferencesModel;

    /** @var array<int, array<string, mixed>> */
    private array $baseSettingsCache = [];

    public function __construct()
    {
        $this->tenantFeatureService = new TenantFeatureService();
        $this->preferencesModel = new PlatformTenantFeaturePreferencesModel();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveTenantSettings(int $tenantId): array
    {
        $baseSettings = $this->resolveBaseSettings($tenantId);
        $defaultOrderKeys = $this->defaultBlockKeys();
        $savedOrderKeys = $this->sanitizeBlockKeyList(
            (array) ($baseSettings['saved_order_keys'] ?? []),
            $defaultOrderKeys
        );
        $managementOrderKeys = $this->mergeOrderedBlockKeys($defaultOrderKeys, $savedOrderKeys);
        $effectiveOrderKeys = !empty($baseSettings['tenant_order_enabled'])
            ? $managementOrderKeys
            : $defaultOrderKeys;

        $defaultPositions = [];
        foreach ($defaultOrderKeys as $index => $blockKey) {
            $defaultPositions[$blockKey] = $index + 1;
        }

        $savedPositions = [];
        foreach ($managementOrderKeys as $index => $blockKey) {
            $savedPositions[$blockKey] = $index + 1;
        }

        $effectivePositions = [];
        foreach ($effectiveOrderKeys as $index => $blockKey) {
            $effectivePositions[$blockKey] = $index + 1;
        }

        $blockRows = [];
        foreach ($managementOrderKeys as $blockKey) {
            $definition = $this->findBlockDefinition($blockKey);
            if ($definition === null) {
                continue;
            }

            $blockRows[] = [
                'key' => $blockKey,
                'label' => (string) ($definition['label'] ?? $blockKey),
                'description' => (string) ($definition['description'] ?? ''),
                'left_origin' => (string) ($definition['left_origin'] ?? 'fixed'),
                'default_position' => (int) ($defaultPositions[$blockKey] ?? 0),
                'saved_position' => (int) ($savedPositions[$blockKey] ?? 0),
                'effective_position' => (int) ($effectivePositions[$blockKey] ?? 0),
            ];
        }

        return [
            'feature_id' => (int) ($baseSettings['feature_id'] ?? 0),
            'feature_state' => (array) ($baseSettings['feature_state'] ?? []),
            'feature_available' => !empty($baseSettings['feature_enabled']),
            'feature_enabled' => !empty($baseSettings['feature_enabled']),
            'order_management_available' => !empty($baseSettings['feature_enabled']),
            'tenant_order_enabled' => !empty($baseSettings['tenant_order_enabled']),
            'order_active' => !empty($baseSettings['feature_enabled']) && !empty($baseSettings['tenant_order_enabled']),
            'has_saved_order' => $savedOrderKeys !== [],
            'block_rows' => $blockRows,
            'default_order_keys' => $defaultOrderKeys,
            'saved_order_keys' => $managementOrderKeys,
            'effective_order_keys' => $effectiveOrderKeys,
            'preference_row' => $baseSettings['preference_row'] ?? null,
            'raw_preference_config' => (array) ($baseSettings['raw_preference_config'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function saveTenantPreferences(
        int $tenantId,
        bool $orderEnabled,
        array $rawOrderedBlockKeys,
        int $updatedByPlatformUserId = 0
    ): array {
        $settings = $this->resolveTenantSettings($tenantId);
        if (empty($settings['order_management_available'])) {
            throw new \RuntimeException('L ordinamento blocchi laterali agenda non e disponibile per questo spazio.');
        }

        $featureId = (int) ($settings['feature_id'] ?? 0);
        if ($featureId <= 0) {
            throw new \RuntimeException('Feature ordinamento blocchi laterali agenda non trovata per questo spazio.');
        }

        $defaultOrderKeys = $this->defaultBlockKeys();
        $savedOrderKeys = $this->sanitizeBlockKeyList($rawOrderedBlockKeys, $defaultOrderKeys);
        $config = is_array($settings['raw_preference_config'] ?? null)
            ? $settings['raw_preference_config']
            : [];

        $config[self::CONFIG_KEY] = [
            'enabled' => $orderEnabled,
            'ordered_block_keys' => $savedOrderKeys,
        ];

        $ok = $this->preferencesModel->setPreference(
            $tenantId,
            $featureId,
            $orderEnabled,
            $updatedByPlatformUserId > 0 ? $updatedByPlatformUserId : null,
            'tenant_master',
            $config,
            false
        );

        if (!$ok) {
            throw new \RuntimeException('Salvataggio ordinamento blocchi laterali agenda non riuscito.');
        }

        unset($this->baseSettingsCache[$tenantId]);

        return $this->resolveTenantSettings($tenantId);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveBaseSettings(int $tenantId): array
    {
        if ($tenantId <= 0) {
            return [];
        }

        if (isset($this->baseSettingsCache[$tenantId])) {
            return $this->baseSettingsCache[$tenantId];
        }

        $featureState = [];
        foreach ($this->tenantFeatureService->listFeatureStatesForTenant($tenantId) as $state) {
            if (trim((string) ($state['feature_key'] ?? '')) === self::FEATURE_KEY) {
                $featureState = $state;
                break;
            }
        }

        $featureId = (int) ($featureState['id_feature'] ?? 0);
        $preferenceRow = $this->findTenantPreferenceRow($tenantId, $featureId);
        $preferenceConfig = $this->decodeConfig((string) ($preferenceRow['config_json'] ?? ''));
        $section = (array) ($preferenceConfig[self::CONFIG_KEY] ?? []);

        $settings = [
            'feature_id' => $featureId,
            'feature_state' => $featureState,
            'feature_enabled' => (bool) ($featureState['effective_enabled'] ?? false),
            'tenant_order_enabled' => (bool) ($section['enabled'] ?? ((int) ($preferenceRow['is_enabled'] ?? 0) === 1)),
            'saved_order_keys' => array_values(array_filter(array_map(
                static fn($value): string => trim((string) $value),
                (array) ($section['ordered_block_keys'] ?? [])
            ), static fn(string $value): bool => $value !== '')),
            'preference_row' => $preferenceRow,
            'raw_preference_config' => $preferenceConfig,
        ];

        $this->baseSettingsCache[$tenantId] = $settings;

        return $settings;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTenantPreferenceRow(int $tenantId, int $featureId): ?array
    {
        if ($tenantId <= 0 || $featureId <= 0) {
            return null;
        }

        $row = $this->preferencesModel
            ->where('id_tenant', $tenantId)
            ->where('id_feature', $featureId)
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, string>
     */
    private function defaultBlockKeys(): array
    {
        $keys = [];

        foreach (self::BLOCKS as $block) {
            $blockKey = trim((string) ($block['key'] ?? ''));
            if ($blockKey !== '') {
                $keys[] = $blockKey;
            }
        }

        return $keys;
    }

    /**
     * @param array<int|string, mixed> $rawBlockKeys
     * @param array<int, string> $allowedBlockKeys
     * @return array<int, string>
     */
    private function sanitizeBlockKeyList(array $rawBlockKeys, array $allowedBlockKeys): array
    {
        $allowedMap = [];
        foreach ($allowedBlockKeys as $blockKey) {
            $blockKey = trim((string) $blockKey);
            if ($blockKey !== '') {
                $allowedMap[$blockKey] = true;
            }
        }

        $sanitized = [];
        foreach ($rawBlockKeys as $blockKey) {
            $blockKey = trim((string) $blockKey);
            if ($blockKey === '' || !isset($allowedMap[$blockKey]) || in_array($blockKey, $sanitized, true)) {
                continue;
            }

            $sanitized[] = $blockKey;
        }

        return $sanitized;
    }

    /**
     * @param array<int, string> $defaultOrderKeys
     * @param array<int, string> $savedOrderKeys
     * @return array<int, string>
     */
    private function mergeOrderedBlockKeys(array $defaultOrderKeys, array $savedOrderKeys): array
    {
        $ordered = [];

        foreach ($savedOrderKeys as $blockKey) {
            if (!in_array($blockKey, $ordered, true)) {
                $ordered[] = $blockKey;
            }
        }

        foreach ($defaultOrderKeys as $blockKey) {
            if (!in_array($blockKey, $ordered, true)) {
                $ordered[] = $blockKey;
            }
        }

        return $ordered;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findBlockDefinition(string $blockKey): ?array
    {
        $blockKey = trim($blockKey);
        if ($blockKey === '') {
            return null;
        }

        foreach (self::BLOCKS as $block) {
            if (trim((string) ($block['key'] ?? '')) === $blockKey) {
                return $block;
            }
        }

        return null;
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
}
