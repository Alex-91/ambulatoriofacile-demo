<?php

namespace App\Services;

use App\Models\PlatformTenantFeaturePreferencesModel;

class AgendaMemoFieldVisibilityService
{
    public const FEATURE_KEY = 'agenda_memo_field_visibility';

    private const CONFIG_KEY = 'memo_field_visibility';

    /**
     * Cliente and assigned doctor are deliberately not configurable: they are
     * structural fields required by the current memo workflow.
     *
     * @var array<int, array<string, string>>
     */
    private const FIELDS = [
        [
            'key' => 'validity_date',
            'label' => 'Data inizio validità',
            'description' => 'Se nascosta, per le nuove memo viene usata automaticamente la data odierna.',
        ],
        [
            'key' => 'phone',
            'label' => 'Telefono',
            'description' => 'Recapito telefonico fisso del cliente.',
        ],
        [
            'key' => 'mobile',
            'label' => 'Cellulare',
            'description' => 'Recapito mobile del cliente.',
        ],
        [
            'key' => 'address',
            'label' => 'Indirizzo',
            'description' => 'Indirizzo associato alla memo.',
        ],
        [
            'key' => 'city',
            'label' => 'Città',
            'description' => 'Comune associato alla memo.',
        ],
        [
            'key' => 'patient_registry',
            'label' => 'Salva anche nell’anagrafica pazienti',
            'description' => 'Disponibile quando nello spazio è attiva anche la funzione Visibilità pazienti in anagrafica.',
        ],
        [
            'key' => 'notes',
            'label' => 'Note',
            'description' => 'Testo libero della memo.',
        ],
        [
            'key' => 'completed',
            'label' => 'Segna come fatta',
            'description' => 'Stato di completamento impostabile direttamente dal popup.',
        ],
    ];

    private TenantFeatureService $tenantFeatureService;
    private PlatformTenantFeaturePreferencesModel $preferencesModel;

    /** @var array<int, array<string, mixed>> */
    private array $settingsCache = [];

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
        if ($tenantId <= 0) {
            return [];
        }

        if (isset($this->settingsCache[$tenantId])) {
            return $this->settingsCache[$tenantId];
        }

        $featureState = [];
        $featureMap = $this->tenantFeatureService->resolveEffectiveFeatureMapForTenant($tenantId);
        foreach ($this->tenantFeatureService->listFeatureStatesForTenant($tenantId) as $state) {
            if (trim((string) ($state['feature_key'] ?? '')) === self::FEATURE_KEY) {
                $featureState = $state;
                break;
            }
        }

        $featureId = (int) ($featureState['id_feature'] ?? 0);
        $preferenceRow = $this->findTenantPreferenceRow($tenantId, $featureId);
        $rawConfig = $this->decodeConfig((string) ($preferenceRow['config_json'] ?? ''));
        $section = (array) ($rawConfig[self::CONFIG_KEY] ?? []);
        $defaultVisibility = $this->defaultVisibilityMap();
        $savedVisibility = $this->mergeVisibilityMap(
            $defaultVisibility,
            $this->sanitizeVisibilityMap((array) ($section['field_visibility'] ?? []))
        );
        $featureEnabled = (bool) ($featureState['effective_enabled'] ?? false);
        $tenantConfigurationEnabled = (bool) ($section['enabled'] ?? ((int) ($preferenceRow['is_enabled'] ?? 0) === 1));
        $configurationActive = $featureEnabled && $tenantConfigurationEnabled;
        $effectiveVisibility = $configurationActive ? $savedVisibility : $defaultVisibility;
        $patientRegistryAvailable = !empty($featureMap['agenda_patient_registry_visibility']);
        if (!$patientRegistryAvailable) {
            $effectiveVisibility['patient_registry'] = false;
        }

        $fieldRows = [];
        foreach (self::FIELDS as $field) {
            $fieldKey = (string) ($field['key'] ?? '');
            if ($fieldKey === '') {
                continue;
            }

            $runtimeAvailable = $fieldKey !== 'patient_registry' || $patientRegistryAvailable;
            $fieldRows[] = [
                'key' => $fieldKey,
                'label' => (string) ($field['label'] ?? $fieldKey),
                'description' => (string) ($field['description'] ?? ''),
                'default_visible' => true,
                'saved_visible' => (bool) ($savedVisibility[$fieldKey] ?? true),
                'effective_visible' => $runtimeAvailable && (bool) ($effectiveVisibility[$fieldKey] ?? true),
                'runtime_available' => $runtimeAvailable,
            ];
        }

        $settings = [
            'feature_id' => $featureId,
            'feature_state' => $featureState,
            'feature_available' => $featureEnabled,
            'visibility_management_available' => $featureEnabled,
            'tenant_configuration_enabled' => $tenantConfigurationEnabled,
            'configuration_active' => $configurationActive,
            'field_rows' => $fieldRows,
            'default_field_visibility' => $defaultVisibility,
            'saved_field_visibility' => $savedVisibility,
            'effective_field_visibility' => $effectiveVisibility,
            'preference_row' => $preferenceRow,
            'raw_preference_config' => $rawConfig,
        ];

        $this->settingsCache[$tenantId] = $settings;

        return $settings;
    }

    /**
     * @param array<int|string, mixed> $visibleFieldKeys
     * @return array<string, mixed>
     */
    public function saveTenantPreferences(
        int $tenantId,
        bool $configurationEnabled,
        array $visibleFieldKeys,
        int $updatedByPlatformUserId = 0
    ): array {
        $settings = $this->resolveTenantSettings($tenantId);
        if (empty($settings['visibility_management_available'])) {
            throw new \RuntimeException('La configurazione dei campi memo non è disponibile per questo spazio.');
        }

        $featureId = (int) ($settings['feature_id'] ?? 0);
        if ($featureId <= 0) {
            throw new \RuntimeException('Feature configurazione campi memo non trovata per questo spazio.');
        }

        $normalizedVisibleKeys = [];
        foreach ($visibleFieldKeys as $fieldKey) {
            $fieldKey = strtolower(trim((string) $fieldKey));
            if ($fieldKey !== '' && !in_array($fieldKey, $normalizedVisibleKeys, true)) {
                $normalizedVisibleKeys[] = $fieldKey;
            }
        }

        $visibility = [];
        foreach ($this->defaultVisibilityMap() as $fieldKey => $defaultVisible) {
            $visibility[$fieldKey] = in_array($fieldKey, $normalizedVisibleKeys, true);
        }

        $config = is_array($settings['raw_preference_config'] ?? null)
            ? $settings['raw_preference_config']
            : [];
        $config[self::CONFIG_KEY] = [
            'enabled' => $configurationEnabled,
            'field_visibility' => $visibility,
        ];

        $ok = $this->preferencesModel->setPreference(
            $tenantId,
            $featureId,
            $configurationEnabled,
            $updatedByPlatformUserId > 0 ? $updatedByPlatformUserId : null,
            'tenant_master',
            $config,
            false
        );

        if (!$ok) {
            throw new \RuntimeException('Salvataggio configurazione campi memo non riuscito.');
        }

        unset($this->settingsCache[$tenantId]);

        return $this->resolveTenantSettings($tenantId);
    }

    /**
     * @return array<string, bool>
     */
    private function defaultVisibilityMap(): array
    {
        $visibility = [];
        foreach (self::FIELDS as $field) {
            $fieldKey = trim((string) ($field['key'] ?? ''));
            if ($fieldKey !== '') {
                $visibility[$fieldKey] = true;
            }
        }

        return $visibility;
    }

    /**
     * @param array<string, mixed> $rawVisibility
     * @return array<string, bool>
     */
    private function sanitizeVisibilityMap(array $rawVisibility): array
    {
        $allowed = $this->defaultVisibilityMap();
        $visibility = [];
        foreach ($rawVisibility as $fieldKey => $visible) {
            $fieldKey = strtolower(trim((string) $fieldKey));
            if (array_key_exists($fieldKey, $allowed)) {
                $visibility[$fieldKey] = filter_var($visible, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $visibility;
    }

    /**
     * @param array<string, bool> $defaults
     * @param array<string, bool> $saved
     * @return array<string, bool>
     */
    private function mergeVisibilityMap(array $defaults, array $saved): array
    {
        foreach ($saved as $fieldKey => $visible) {
            if (array_key_exists($fieldKey, $defaults)) {
                $defaults[$fieldKey] = $visible;
            }
        }

        return $defaults;
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
