<?php

namespace App\Services;

use App\Models\PlatformTenantFeaturePreferencesModel;

class AgendaHomeBlockOrderService
{
    public const FEATURE_KEY = 'agenda_home_block_order';

    private const CONFIG_KEY = 'home_block_order';
    private const COLUMN_MAIN = 'main';
    private const COLUMN_LEFT = 'left';
    private const COLUMN_HIDDEN = 'hidden';

    /**
     * @var array<int, array<string, mixed>>
     */
    private const BLOCKS = [
        [
            'key' => 'doctor_selector',
            'label' => 'Scelta professionista',
            'description' => 'Blocco iniziale con il selettore del professionista visibile in agenda.',
            'default_column' => self::COLUMN_MAIN,
        ],
        [
            'key' => 'patient_search',
            'label' => 'Ricerca visite paziente',
            'description' => 'Ricerca rapida del paziente con storico appuntamenti passati e futuri.',
            'default_column' => self::COLUMN_MAIN,
        ],
        [
            'key' => 'day_note',
            'label' => 'Note del giorno',
            'description' => 'Area rapida per annotare note libere legate alla data selezionata in agenda.',
            'default_column' => self::COLUMN_MAIN,
        ],
        [
            'key' => 'calendar',
            'label' => 'Agenda',
            'description' => 'Calendario principale dello studio con eventuali visite domiciliari affiancate.',
            'default_column' => self::COLUMN_MAIN,
        ],
        [
            'key' => 'memo',
            'label' => 'Memo',
            'description' => 'Lista memo del professionista o memo condivise dello spazio.',
            'default_column' => self::COLUMN_MAIN,
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
        $defaultBlockColumns = $this->defaultBlockColumns();
        $savedOrderKeys = $this->sanitizeBlockKeyList(
            (array) ($baseSettings['saved_order_keys'] ?? []),
            $defaultOrderKeys
        );
        $savedBlockColumns = $this->sanitizeBlockColumnMap(
            (array) ($baseSettings['saved_block_columns'] ?? []),
            $defaultBlockColumns
        );
        $managementOrderKeys = $this->mergeOrderedBlockKeys($defaultOrderKeys, $savedOrderKeys);
        $managementBlockColumns = $this->mergeBlockColumnMap($defaultBlockColumns, $savedBlockColumns);
        $effectiveOrderKeys = !empty($baseSettings['tenant_order_enabled'])
            ? $managementOrderKeys
            : $defaultOrderKeys;
        $effectiveBlockColumns = !empty($baseSettings['tenant_order_enabled'])
            ? $managementBlockColumns
            : $defaultBlockColumns;
        $defaultLayoutItems = $this->buildLayoutItems($defaultOrderKeys, $defaultBlockColumns);
        $managementLayoutItems = $this->buildLayoutItems($managementOrderKeys, $managementBlockColumns);
        $effectiveLayoutItems = $this->buildLayoutItems($effectiveOrderKeys, $effectiveBlockColumns);

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
                'default_position' => (int) ($defaultPositions[$blockKey] ?? 0),
                'saved_position' => (int) ($savedPositions[$blockKey] ?? 0),
                'effective_position' => (int) ($effectivePositions[$blockKey] ?? 0),
                'default_column' => (string) ($defaultBlockColumns[$blockKey] ?? self::COLUMN_MAIN),
                'saved_column' => (string) ($managementBlockColumns[$blockKey] ?? self::COLUMN_MAIN),
                'effective_column' => (string) ($effectiveBlockColumns[$blockKey] ?? self::COLUMN_MAIN),
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
            'has_saved_order' => $savedOrderKeys !== [] || $savedBlockColumns !== [],
            'block_rows' => $blockRows,
            'default_order_keys' => $defaultOrderKeys,
            'saved_order_keys' => $managementOrderKeys,
            'effective_order_keys' => $effectiveOrderKeys,
            'default_block_columns' => $defaultBlockColumns,
            'saved_block_columns' => $managementBlockColumns,
            'effective_block_columns' => $effectiveBlockColumns,
            'default_layout_items' => $defaultLayoutItems,
            'saved_layout_items' => $managementLayoutItems,
            'effective_layout_items' => $effectiveLayoutItems,
            'preference_row' => $baseSettings['preference_row'] ?? null,
            'raw_preference_config' => (array) ($baseSettings['raw_preference_config'] ?? []),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function resolveOrderedBlockKeys(int $tenantId, bool $respectTenantToggle = true): array
    {
        $settings = $this->resolveTenantSettings($tenantId);
        if (empty($settings['feature_enabled'])) {
            return $this->defaultBlockKeys();
        }

        if ($respectTenantToggle && empty($settings['tenant_order_enabled'])) {
            return $this->defaultBlockKeys();
        }

        $ordered = $this->sanitizeBlockKeyList(
            (array) ($settings['effective_order_keys'] ?? []),
            $this->defaultBlockKeys()
        );

        return $ordered !== [] ? $ordered : $this->defaultBlockKeys();
    }

    /**
     * @param array<int|string, mixed> $rawOrderedBlockKeys
     * @return array<string, mixed>
     */
    public function saveTenantPreferences(
        int $tenantId,
        bool $orderEnabled,
        array $rawOrderedBlockKeys,
        int $updatedByPlatformUserId = 0,
        array $rawBlockColumns = []
    ): array {
        $settings = $this->resolveTenantSettings($tenantId);
        if (empty($settings['order_management_available'])) {
            throw new \RuntimeException('L’ordinamento blocchi home agenda non è disponibile per questo spazio.');
        }

        $featureId = (int) ($settings['feature_id'] ?? 0);
        if ($featureId <= 0) {
            throw new \RuntimeException('Feature ordinamento blocchi home agenda non trovata per questo spazio.');
        }

        $defaultOrderKeys = $this->defaultBlockKeys();
        $defaultBlockColumns = $this->defaultBlockColumns();
        $savedOrderKeys = $this->sanitizeBlockKeyList($rawOrderedBlockKeys, $defaultOrderKeys);
        $savedBlockColumns = $this->sanitizeBlockColumnMap($rawBlockColumns, $defaultBlockColumns);
        $config = is_array($settings['raw_preference_config'] ?? null)
            ? $settings['raw_preference_config']
            : [];

        $config[self::CONFIG_KEY] = [
            'enabled' => $orderEnabled,
            'ordered_block_keys' => $savedOrderKeys,
            'block_columns' => $savedBlockColumns,
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
            throw new \RuntimeException('Salvataggio ordinamento blocchi home agenda non riuscito.');
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
            'saved_block_columns' => $this->sanitizeBlockColumnMap(
                (array) ($section['block_columns'] ?? []),
                $this->defaultBlockColumns()
            ),
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
     * @return array<string, string>
     */
    private function defaultBlockColumns(): array
    {
        $columns = [];

        foreach (self::BLOCKS as $block) {
            $blockKey = trim((string) ($block['key'] ?? ''));
            if ($blockKey === '') {
                continue;
            }

            $defaultColumn = strtolower(trim((string) ($block['default_column'] ?? self::COLUMN_MAIN)));
            if (!$this->isAllowedColumn($defaultColumn)) {
                $defaultColumn = self::COLUMN_MAIN;
            }

            $columns[$blockKey] = $defaultColumn;
        }

        return $columns;
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
     * @param array<string, mixed> $rawBlockColumns
     * @param array<string, string> $defaultBlockColumns
     * @return array<string, string>
     */
    private function sanitizeBlockColumnMap(array $rawBlockColumns, array $defaultBlockColumns): array
    {
        $sanitized = [];

        foreach ($defaultBlockColumns as $blockKey => $defaultColumn) {
            $rawColumn = strtolower(trim((string) ($rawBlockColumns[$blockKey] ?? '')));
            if ($rawColumn === '' || !$this->isAllowedColumn($rawColumn)) {
                continue;
            }

            $sanitized[$blockKey] = $rawColumn;
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
     * @param array<string, string> $defaultBlockColumns
     * @param array<string, string> $savedBlockColumns
     * @return array<string, string>
     */
    private function mergeBlockColumnMap(array $defaultBlockColumns, array $savedBlockColumns): array
    {
        $resolved = [];

        foreach ($defaultBlockColumns as $blockKey => $defaultColumn) {
            $savedColumn = strtolower(trim((string) ($savedBlockColumns[$blockKey] ?? '')));
            $resolved[$blockKey] = $this->isAllowedColumn($savedColumn)
                ? $savedColumn
                : $defaultColumn;
        }

        return $resolved;
    }

    /**
     * @param array<int, string> $orderedBlockKeys
     * @param array<string, string> $resolvedBlockColumns
     * @return array<int, array<string, string>>
     */
    private function buildLayoutItems(array $orderedBlockKeys, array $resolvedBlockColumns): array
    {
        $items = [];

        foreach ($orderedBlockKeys as $blockKey) {
            $blockKey = trim((string) $blockKey);
            if ($blockKey === '' || !isset($resolvedBlockColumns[$blockKey])) {
                continue;
            }

            $items[] = [
                'key' => $blockKey,
                'column' => $resolvedBlockColumns[$blockKey],
            ];
        }

        return $items;
    }

    /**
     * @return array<string, string>|null
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

    private function isAllowedColumn(string $column): bool
    {
        return in_array($column, [self::COLUMN_MAIN, self::COLUMN_LEFT, self::COLUMN_HIDDEN], true);
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
