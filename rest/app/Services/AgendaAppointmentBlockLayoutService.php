<?php

namespace App\Services;

use App\Config\BillingModule;
use App\Config\TsBilling;
use App\Models\PlatformTenantFeaturePreferencesModel;

class AgendaAppointmentBlockLayoutService
{
    public const FEATURE_KEY = 'agenda_appointment_block_layout';

    private const CONFIG_KEY = 'appointment_block_layout';
    private const VISIBILITY_VISIBLE = 'visible';
    private const VISIBILITY_HIDDEN = 'hidden';
    private const BLOCK_TIME = 'time';
    private const BLOCK_VISIT_TYPE = 'visit_type';
    private const BLOCK_PATIENT_ENTRY = 'patient_entry';
    private const BLOCK_DOCUMENT_WORKFLOW = 'document_workflow';
    private const CUSTOM_APPOINTMENT_TIME_FEATURE = 'agenda_custom_appointment_time';

    /**
     * @var array<int, array<string, mixed>>
     */
    private const BLOCKS = [
        [
            'key' => self::BLOCK_TIME,
            'label' => 'Orario appuntamento',
            'description' => 'Mostra ora di inizio e ora di fine dello slot selezionato nel popup appuntamento.',
            'default_visibility' => self::VISIBILITY_VISIBLE,
        ],
        [
            'key' => self::BLOCK_VISIT_TYPE,
            'label' => 'Tipo visita',
            'description' => 'Sezione con tipo visita, durata e copertura effettiva dello slot.',
            'default_visibility' => self::VISIBILITY_VISIBLE,
        ],
        [
            'key' => self::BLOCK_PATIENT_ENTRY,
            'label' => 'Inserimento paziente',
            'description' => 'Ricerca paziente, anagrafica rapida, recapiti, promemoria SMS e note appuntamento.',
            'default_visibility' => self::VISIBILITY_VISIBLE,
        ],
        [
            'key' => self::BLOCK_DOCUMENT_WORKFLOW,
            'label' => 'TS e fatturazione',
            'description' => 'Azioni documento per aprire Fatturazione o Sistema TS partendo dall’appuntamento.',
            'default_visibility' => self::VISIBILITY_VISIBLE,
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
        $runtimeCapabilities = $this->resolveRuntimeCapabilities(
            (array) ($baseSettings['feature_map'] ?? [])
        );
        $defaultOrderKeys = $this->defaultBlockKeys();
        $defaultBlockVisibility = $this->defaultBlockVisibilityMap();
        $savedOrderKeys = $this->sanitizeBlockKeyList(
            (array) ($baseSettings['saved_order_keys'] ?? []),
            $defaultOrderKeys
        );
        $savedBlockVisibility = $this->sanitizeBlockVisibilityMap(
            (array) ($baseSettings['saved_block_visibility'] ?? []),
            $defaultBlockVisibility
        );
        $managementOrderKeys = $this->mergeOrderedBlockKeys($defaultOrderKeys, $savedOrderKeys);
        $managementBlockVisibility = $this->mergeBlockVisibilityMap($defaultBlockVisibility, $savedBlockVisibility);
        $effectiveOrderKeys = !empty($baseSettings['tenant_layout_enabled'])
            ? $managementOrderKeys
            : $defaultOrderKeys;
        $effectiveRawVisibility = !empty($baseSettings['tenant_layout_enabled'])
            ? $managementBlockVisibility
            : $defaultBlockVisibility;

        $effectiveResolvedVisibility = [];
        foreach ($defaultOrderKeys as $blockKey) {
            $effectiveResolvedVisibility[$blockKey] = $this->resolveRuntimeVisibility(
                $blockKey,
                (string) ($effectiveRawVisibility[$blockKey] ?? self::VISIBILITY_VISIBLE),
                $runtimeCapabilities
            );
        }

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

            $runtimeRow = (array) ($runtimeCapabilities['blocks'][$blockKey] ?? []);
            $defaultVisibility = (string) ($defaultBlockVisibility[$blockKey] ?? self::VISIBILITY_VISIBLE);
            $storedVisibility = (string) ($managementBlockVisibility[$blockKey] ?? $defaultVisibility);
            $storedEffectiveVisibility = $this->resolveRuntimeVisibility(
                $blockKey,
                $storedVisibility,
                $runtimeCapabilities
            );

            $blockRows[] = [
                'key' => $blockKey,
                'label' => (string) ($definition['label'] ?? $blockKey),
                'description' => (string) ($definition['description'] ?? ''),
                'default_position' => (int) ($defaultPositions[$blockKey] ?? 0),
                'saved_position' => (int) ($savedPositions[$blockKey] ?? 0),
                'effective_position' => (int) ($effectivePositions[$blockKey] ?? 0),
                'default_visibility' => $defaultVisibility,
                'saved_visibility' => $storedVisibility,
                'saved_effective_visibility' => $storedEffectiveVisibility,
                'effective_visibility' => (string) ($effectiveResolvedVisibility[$blockKey] ?? self::VISIBILITY_VISIBLE),
                'runtime_available' => !empty($runtimeRow['available']),
                'hideable' => !empty($runtimeRow['hideable']),
                'force_visible' => !empty($runtimeRow['force_visible']),
                'runtime_note' => (string) ($runtimeRow['note'] ?? ''),
            ];
        }

        $hasSavedLayout = $savedOrderKeys !== [] || $savedBlockVisibility !== [];

        return [
            'feature_id' => (int) ($baseSettings['feature_id'] ?? 0),
            'feature_state' => (array) ($baseSettings['feature_state'] ?? []),
            'feature_available' => !empty($baseSettings['feature_enabled']),
            'feature_enabled' => !empty($baseSettings['feature_enabled']),
            'layout_management_available' => !empty($baseSettings['feature_enabled']),
            'tenant_layout_enabled' => !empty($baseSettings['tenant_layout_enabled']),
            'layout_active' => !empty($baseSettings['feature_enabled']) && !empty($baseSettings['tenant_layout_enabled']),
            'has_saved_layout' => $hasSavedLayout,
            'block_rows' => $blockRows,
            'default_order_keys' => $defaultOrderKeys,
            'saved_order_keys' => $managementOrderKeys,
            'effective_order_keys' => $effectiveOrderKeys,
            'default_block_visibility' => $defaultBlockVisibility,
            'saved_block_visibility' => $managementBlockVisibility,
            'effective_block_visibility' => $effectiveResolvedVisibility,
            'effective_render_items' => $this->buildRenderItems(
                $effectiveOrderKeys,
                $effectiveResolvedVisibility,
                $runtimeCapabilities
            ),
            'runtime_capabilities' => $runtimeCapabilities,
            'preference_row' => $baseSettings['preference_row'] ?? null,
            'raw_preference_config' => (array) ($baseSettings['raw_preference_config'] ?? []),
        ];
    }

    /**
     * @param array<int|string, mixed> $rawOrderedBlockKeys
     * @param array<string, mixed> $rawBlockVisibility
     * @return array<string, mixed>
     */
    public function saveTenantPreferences(
        int $tenantId,
        bool $layoutEnabled,
        array $rawOrderedBlockKeys,
        array $rawBlockVisibility,
        int $updatedByPlatformUserId = 0
    ): array {
        $settings = $this->resolveTenantSettings($tenantId);
        if (empty($settings['layout_management_available'])) {
            throw new \RuntimeException('Il layout del popup appuntamento non è disponibile per questo spazio.');
        }

        $featureId = (int) ($settings['feature_id'] ?? 0);
        if ($featureId <= 0) {
            throw new \RuntimeException('Feature layout popup appuntamento non trovata per questo spazio.');
        }

        $defaultOrderKeys = $this->defaultBlockKeys();
        $defaultBlockVisibility = $this->defaultBlockVisibilityMap();
        $savedOrderKeys = $this->sanitizeBlockKeyList($rawOrderedBlockKeys, $defaultOrderKeys);
        $savedBlockVisibility = $this->sanitizeBlockVisibilityMap($rawBlockVisibility, $defaultBlockVisibility);
        $config = is_array($settings['raw_preference_config'] ?? null)
            ? $settings['raw_preference_config']
            : [];

        $config[self::CONFIG_KEY] = [
            'enabled' => $layoutEnabled,
            'ordered_block_keys' => $savedOrderKeys,
            'block_visibility' => $savedBlockVisibility,
        ];

        $ok = $this->preferencesModel->setPreference(
            $tenantId,
            $featureId,
            $layoutEnabled,
            $updatedByPlatformUserId > 0 ? $updatedByPlatformUserId : null,
            'tenant_master',
            $config,
            false
        );

        if (!$ok) {
            throw new \RuntimeException('Salvataggio layout popup appuntamento non riuscito.');
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
            'tenant_layout_enabled' => (bool) ($section['enabled'] ?? ((int) ($preferenceRow['is_enabled'] ?? 0) === 1)),
            'saved_order_keys' => array_values(array_filter(array_map(
                static fn($value): string => trim((string) $value),
                (array) ($section['ordered_block_keys'] ?? [])
            ), static fn(string $value): bool => $value !== '')),
            'saved_block_visibility' => $this->sanitizeBlockVisibilityMap(
                (array) ($section['block_visibility'] ?? []),
                $this->defaultBlockVisibilityMap()
            ),
            'feature_map' => $this->tenantFeatureService->resolveEffectiveFeatureMapForTenant($tenantId),
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
    private function defaultBlockVisibilityMap(): array
    {
        $visibilityMap = [];

        foreach (self::BLOCKS as $block) {
            $blockKey = trim((string) ($block['key'] ?? ''));
            if ($blockKey === '') {
                continue;
            }

            $visibility = strtolower(trim((string) ($block['default_visibility'] ?? self::VISIBILITY_VISIBLE)));
            if (!$this->isAllowedVisibility($visibility)) {
                $visibility = self::VISIBILITY_VISIBLE;
            }

            $visibilityMap[$blockKey] = $visibility;
        }

        return $visibilityMap;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRuntimeCapabilities(array $featureMap): array
    {
        $visitTypesEnabled = !empty($featureMap['agenda_visit_types']);
        $visitTypeOptional = $visitTypesEnabled && !empty($featureMap['agenda_visit_type_optional']);
        $customAppointmentTimeEnabled = !empty($featureMap[self::CUSTOM_APPOINTMENT_TIME_FEATURE]);
        $billingEnabled = !empty($featureMap[BillingModule::FEATURE_KEY]);
        $tsEnabled = !empty($featureMap[TsBilling::FEATURE_KEY]);
        $documentWorkflowAvailable = $billingEnabled || $tsEnabled;

        return [
            'visit_types_enabled' => $visitTypesEnabled,
            'visit_type_optional' => $visitTypeOptional,
            'custom_appointment_time_enabled' => $customAppointmentTimeEnabled,
            'document_workflow_available' => $documentWorkflowAvailable,
            'blocks' => [
                self::BLOCK_TIME => [
                    'available' => true,
                    'hideable' => !$customAppointmentTimeEnabled,
                    'force_visible' => $customAppointmentTimeEnabled,
                    'note' => $customAppointmentTimeEnabled
                        ? 'Resta visibile perché contiene i controlli necessari agli orari personalizzati.'
                        : 'Mostra solo il riepilogo orario dello slot selezionato e può quindi essere nascosto se preferisci un popup più compatto.',
                ],
                self::BLOCK_VISIT_TYPE => [
                    'available' => $visitTypesEnabled,
                    'hideable' => $visitTypeOptional,
                    'force_visible' => $visitTypesEnabled && !$visitTypeOptional,
                    'note' => $visitTypesEnabled
                        ? (
                            $visitTypeOptional
                                ? 'In questo spazio il tipo visita e facoltativo, quindi il blocco può anche essere nascosto.'
                                : 'In questo spazio il tipo visita è obbligatorio, quindi il blocco resta sempre visibile.'
                        )
                        : 'Questo blocco compare appena il modulo tipi visita viene attivato nello spazio.',
                ],
                self::BLOCK_PATIENT_ENTRY => [
                    'available' => true,
                    'hideable' => false,
                    'force_visible' => true,
                    'note' => 'Contiene i campi minimi richiesti per salvare o aggiornare un appuntamento, quindi resta sempre visibile.',
                ],
                self::BLOCK_DOCUMENT_WORKFLOW => [
                    'available' => $documentWorkflowAvailable,
                    'hideable' => true,
                    'force_visible' => false,
                    'note' => $documentWorkflowAvailable
                        ? 'Qui trovi le azioni documento già agganciate all’appuntamento per Fatturazione e Sistema TS.'
                        : 'Questo blocco compare quando nello spazio è attivo almeno uno tra Fatturazione e Sistema TS.',
                ],
            ],
        ];
    }

    /**
     * @param array<int, string> $orderedBlockKeys
     * @param array<string, string> $resolvedBlockVisibility
     * @return array<int, array<string, string>>
     */
    private function buildRenderItems(
        array $orderedBlockKeys,
        array $resolvedBlockVisibility,
        array $runtimeCapabilities
    ): array {
        $items = [];

        foreach ($orderedBlockKeys as $blockKey) {
            $blockKey = trim((string) $blockKey);
            if ($blockKey === '') {
                continue;
            }

            $runtimeRow = (array) ($runtimeCapabilities['blocks'][$blockKey] ?? []);
            if (empty($runtimeRow['available'])) {
                continue;
            }

            $visibility = (string) ($resolvedBlockVisibility[$blockKey] ?? self::VISIBILITY_VISIBLE);
            if ($visibility === self::VISIBILITY_HIDDEN) {
                continue;
            }

            $items[] = [
                'key' => $blockKey,
                'visibility' => $visibility,
            ];
        }

        return $items;
    }

    private function resolveRuntimeVisibility(string $blockKey, string $storedVisibility, array $runtimeCapabilities): string
    {
        $runtimeRow = (array) ($runtimeCapabilities['blocks'][$blockKey] ?? []);
        if (!empty($runtimeRow['force_visible'])) {
            return self::VISIBILITY_VISIBLE;
        }

        return $this->isAllowedVisibility($storedVisibility)
            ? $storedVisibility
            : self::VISIBILITY_VISIBLE;
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
     * @param array<string, mixed> $rawBlockVisibility
     * @param array<string, string> $defaultBlockVisibility
     * @return array<string, string>
     */
    private function sanitizeBlockVisibilityMap(array $rawBlockVisibility, array $defaultBlockVisibility): array
    {
        $sanitized = [];

        foreach ($defaultBlockVisibility as $blockKey => $defaultVisibility) {
            $rawVisibility = strtolower(trim((string) ($rawBlockVisibility[$blockKey] ?? '')));
            if ($rawVisibility === '' || !$this->isAllowedVisibility($rawVisibility)) {
                continue;
            }

            $sanitized[$blockKey] = $rawVisibility;
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
     * @param array<string, string> $defaultBlockVisibility
     * @param array<string, string> $savedBlockVisibility
     * @return array<string, string>
     */
    private function mergeBlockVisibilityMap(array $defaultBlockVisibility, array $savedBlockVisibility): array
    {
        $resolved = [];

        foreach ($defaultBlockVisibility as $blockKey => $defaultVisibility) {
            $savedVisibility = strtolower(trim((string) ($savedBlockVisibility[$blockKey] ?? '')));
            $resolved[$blockKey] = $this->isAllowedVisibility($savedVisibility)
                ? $savedVisibility
                : $defaultVisibility;
        }

        return $resolved;
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

    private function isAllowedVisibility(string $visibility): bool
    {
        return in_array($visibility, [self::VISIBILITY_VISIBLE, self::VISIBILITY_HIDDEN], true);
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
