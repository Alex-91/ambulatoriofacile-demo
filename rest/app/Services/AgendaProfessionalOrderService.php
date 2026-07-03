<?php

namespace App\Services;

use App\Models\PlatformTenantFeaturePreferencesModel;

class AgendaProfessionalOrderService
{
    public const FEATURE_KEY = 'agenda_professional_order';

    private const CONFIG_KEY = 'professional_order';

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
     * @param array<int, mixed> $professionals
     * @return array<string, mixed>
     */
    public function resolveTenantSettings(int $tenantId, array $professionals): array
    {
        $baseSettings = $this->resolveBaseSettings($tenantId);
        $normalized = $this->normalizeProfessionals($professionals);
        $baseOrderIds = $normalized['base_order_ids'];
        $savedOrderIds = $this->sanitizeDoctorIdList(
            (array) ($baseSettings['saved_order_ids'] ?? []),
            $baseOrderIds
        );
        $managementOrderIds = $this->mergeOrderedDoctorIds($baseOrderIds, $savedOrderIds);
        $effectiveOrderIds = !empty($baseSettings['tenant_order_enabled'])
            ? $managementOrderIds
            : $baseOrderIds;

        $defaultPositions = [];
        foreach ($baseOrderIds as $index => $doctorId) {
            $defaultPositions[$doctorId] = $index + 1;
        }

        $savedPositions = [];
        foreach ($managementOrderIds as $index => $doctorId) {
            $savedPositions[$doctorId] = $index + 1;
        }

        $effectivePositions = [];
        foreach ($effectiveOrderIds as $index => $doctorId) {
            $effectivePositions[$doctorId] = $index + 1;
        }

        $doctorRows = [];
        foreach ($managementOrderIds as $doctorId) {
            $doctor = $normalized['doctor_map'][$doctorId] ?? null;
            if (!is_array($doctor)) {
                continue;
            }

            $doctorRows[] = [
                'id_dot' => $doctorId,
                'label' => (string) ($doctor['label'] ?? ('Professionista ' . $doctorId)),
                'default_position' => (int) ($defaultPositions[$doctorId] ?? 0),
                'saved_position' => (int) ($savedPositions[$doctorId] ?? 0),
                'effective_position' => (int) ($effectivePositions[$doctorId] ?? 0),
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
            'has_saved_order' => $savedOrderIds !== [],
            'doctor_rows' => $doctorRows,
            'default_order_ids' => $baseOrderIds,
            'saved_order_ids' => $managementOrderIds,
            'effective_order_ids' => $effectiveOrderIds,
            'preference_row' => $baseSettings['preference_row'] ?? null,
            'raw_preference_config' => (array) ($baseSettings['raw_preference_config'] ?? []),
        ];
    }

    /**
     * @param array<int, mixed> $professionals
     * @return array<int, mixed>
     */
    public function sortProfessionals(int $tenantId, array $professionals, bool $respectTenantToggle = true): array
    {
        if ($tenantId <= 0 || $professionals === []) {
            return $professionals;
        }

        $baseSettings = $this->resolveBaseSettings($tenantId);
        if (empty($baseSettings['feature_enabled'])) {
            return $professionals;
        }

        if ($respectTenantToggle && empty($baseSettings['tenant_order_enabled'])) {
            return $professionals;
        }

        $normalized = $this->normalizeProfessionals($professionals);
        $baseOrderIds = $normalized['base_order_ids'];
        if (count($baseOrderIds) <= 1) {
            return $professionals;
        }

        $savedOrderIds = $this->sanitizeDoctorIdList(
            (array) ($baseSettings['saved_order_ids'] ?? []),
            $baseOrderIds
        );
        if ($savedOrderIds === []) {
            return $professionals;
        }

        $orderedIds = $this->mergeOrderedDoctorIds($baseOrderIds, $savedOrderIds);
        $rowsByDoctorId = $normalized['rows_by_doctor_id'];
        $sorted = [];

        foreach ($orderedIds as $doctorId) {
            if (!array_key_exists($doctorId, $rowsByDoctorId)) {
                continue;
            }

            $sorted[] = $rowsByDoctorId[$doctorId];
        }

        return $sorted === [] ? $professionals : $sorted;
    }

    /**
     * @param array<int|string, mixed> $rawOrderedDoctorIds
     * @param array<int, mixed> $professionals
     * @return array<string, mixed>
     */
    public function saveTenantPreferences(
        int $tenantId,
        bool $orderEnabled,
        array $rawOrderedDoctorIds,
        array $professionals,
        int $updatedByPlatformUserId = 0
    ): array {
        $settings = $this->resolveTenantSettings($tenantId, $professionals);
        if (empty($settings['order_management_available'])) {
            throw new \RuntimeException('L ordinamento professionisti agenda non e disponibile per questo spazio.');
        }

        $featureId = (int) ($settings['feature_id'] ?? 0);
        if ($featureId <= 0) {
            throw new \RuntimeException('Feature ordinamento professionisti agenda non trovata per questo spazio.');
        }

        $normalized = $this->normalizeProfessionals($professionals);
        $baseOrderIds = $normalized['base_order_ids'];
        $savedOrderIds = $this->sanitizeDoctorIdList($rawOrderedDoctorIds, $baseOrderIds);
        $config = is_array($settings['raw_preference_config'] ?? null)
            ? $settings['raw_preference_config']
            : [];

        $config[self::CONFIG_KEY] = [
            'enabled' => $orderEnabled,
            'ordered_doctor_ids' => $savedOrderIds,
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
            throw new \RuntimeException('Salvataggio ordinamento professionisti agenda non riuscito.');
        }

        unset($this->baseSettingsCache[$tenantId]);

        return $this->resolveTenantSettings($tenantId, $professionals);
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
            'saved_order_ids' => array_values(array_filter(array_map(
                static fn($value): int => (int) $value,
                (array) ($section['ordered_doctor_ids'] ?? [])
            ))),
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
     * @param array<int, mixed> $professionals
     * @return array<string, mixed>
     */
    private function normalizeProfessionals(array $professionals): array
    {
        $doctorMap = [];
        $baseOrderIds = [];
        $rowsByDoctorId = [];

        foreach ($professionals as $professional) {
            $row = is_object($professional) ? get_object_vars($professional) : (array) $professional;
            $doctorId = (int) ($row['id_dot'] ?? 0);
            if ($doctorId <= 0 || array_key_exists($doctorId, $rowsByDoctorId)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($row['cognome'] ?? '') . ' ' . (string) ($row['nome'] ?? ''));
            }

            $doctorMap[$doctorId] = [
                'id_dot' => $doctorId,
                'label' => $label !== '' ? $label : ('Professionista ' . $doctorId),
            ];
            $rowsByDoctorId[$doctorId] = $professional;
            $baseOrderIds[] = $doctorId;
        }

        return [
            'doctor_map' => $doctorMap,
            'base_order_ids' => $baseOrderIds,
            'rows_by_doctor_id' => $rowsByDoctorId,
        ];
    }

    /**
     * @param array<int|string, mixed> $rawDoctorIds
     * @param array<int, int> $allowedDoctorIds
     * @return array<int, int>
     */
    private function sanitizeDoctorIdList(array $rawDoctorIds, array $allowedDoctorIds): array
    {
        $allowedMap = [];
        foreach ($allowedDoctorIds as $doctorId) {
            $doctorId = (int) $doctorId;
            if ($doctorId > 0) {
                $allowedMap[$doctorId] = true;
            }
        }

        $sanitized = [];
        foreach ($rawDoctorIds as $doctorId) {
            $doctorId = (int) $doctorId;
            if ($doctorId <= 0 || !isset($allowedMap[$doctorId]) || in_array($doctorId, $sanitized, true)) {
                continue;
            }

            $sanitized[] = $doctorId;
        }

        return $sanitized;
    }

    /**
     * @param array<int, int> $baseOrderIds
     * @param array<int, int> $savedOrderIds
     * @return array<int, int>
     */
    private function mergeOrderedDoctorIds(array $baseOrderIds, array $savedOrderIds): array
    {
        $ordered = [];

        foreach ($savedOrderIds as $doctorId) {
            if (!in_array($doctorId, $ordered, true)) {
                $ordered[] = $doctorId;
            }
        }

        foreach ($baseOrderIds as $doctorId) {
            if (!in_array($doctorId, $ordered, true)) {
                $ordered[] = $doctorId;
            }
        }

        return $ordered;
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
