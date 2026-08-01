<?php

namespace App\Services;

use App\Models\PlatformTenantFeaturePreferencesModel;

class AgendaDefaultViewService
{
    public const FEATURE_KEY = 'agenda_default_view';

    private const CONFIG_KEY = 'default_view';
    private const VIEW_DAY = 'day';
    private const VIEW_WEEK = 'week';
    private const VIEW_TEAM_DAY = 'team_day';

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
        $teamDayAvailable = !empty($baseSettings['team_day_available']);
        $storedDefaultView = $this->normalizeStoredView((string) ($baseSettings['stored_default_view'] ?? ''));
        $selectedDefaultView = $this->normalizeAvailableView($storedDefaultView, $teamDayAvailable);
        $viewRows = $this->buildViewRows($teamDayAvailable);

        return [
            'feature_id' => (int) ($baseSettings['feature_id'] ?? 0),
            'feature_state' => (array) ($baseSettings['feature_state'] ?? []),
            'feature_available' => !empty($baseSettings['feature_enabled']),
            'feature_enabled' => !empty($baseSettings['feature_enabled']),
            'default_view_management_available' => !empty($baseSettings['feature_enabled']),
            'tenant_default_enabled' => !empty($baseSettings['tenant_default_enabled']),
            'default_view_active' => !empty($baseSettings['feature_enabled']) && !empty($baseSettings['tenant_default_enabled']),
            'team_day_available' => $teamDayAvailable,
            'view_rows' => $viewRows,
            'available_view_keys' => array_values(array_map(
                static fn(array $row): string => (string) ($row['key'] ?? ''),
                $viewRows
            )),
            'stored_default_view' => $storedDefaultView,
            'selected_default_view' => $selectedDefaultView,
            'effective_default_view' => (!empty($baseSettings['feature_enabled']) && !empty($baseSettings['tenant_default_enabled']))
                ? $selectedDefaultView
                : self::VIEW_DAY,
            'saved_view_unavailable' => $storedDefaultView !== $selectedDefaultView,
            'preference_row' => $baseSettings['preference_row'] ?? null,
            'raw_preference_config' => (array) ($baseSettings['raw_preference_config'] ?? []),
        ];
    }

    public function resolveInitialView(int $tenantId, bool $teamDayAvailable): string
    {
        $settings = $this->resolveTenantSettings($tenantId);
        if (empty($settings['default_view_active'])) {
            return self::VIEW_DAY;
        }

        return $this->normalizeAvailableView(
            (string) ($settings['stored_default_view'] ?? self::VIEW_DAY),
            $teamDayAvailable
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function saveTenantPreferences(
        int $tenantId,
        bool $defaultViewEnabled,
        string $defaultView,
        int $updatedByPlatformUserId = 0
    ): array {
        $settings = $this->resolveTenantSettings($tenantId);
        if (empty($settings['default_view_management_available'])) {
            throw new \RuntimeException('La vista iniziale agenda non è disponibile per questo spazio.');
        }

        $featureId = (int) ($settings['feature_id'] ?? 0);
        if ($featureId <= 0) {
            throw new \RuntimeException('Feature vista iniziale agenda non trovata per questo spazio.');
        }

        $config = is_array($settings['raw_preference_config'] ?? null)
            ? $settings['raw_preference_config']
            : [];

        $config[self::CONFIG_KEY] = [
            'enabled' => $defaultViewEnabled,
            'view' => $this->normalizeAvailableView($defaultView, !empty($settings['team_day_available'])),
        ];

        $ok = $this->preferencesModel->setPreference(
            $tenantId,
            $featureId,
            $defaultViewEnabled,
            $updatedByPlatformUserId > 0 ? $updatedByPlatformUserId : null,
            'tenant_master',
            $config,
            false
        );

        if (!$ok) {
            throw new \RuntimeException('Salvataggio vista iniziale agenda non riuscito.');
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
        $featureMap = $this->tenantFeatureService->resolveEffectiveFeatureMapForTenant($tenantId);

        $settings = [
            'feature_id' => $featureId,
            'feature_state' => $featureState,
            'feature_enabled' => (bool) ($featureState['effective_enabled'] ?? false),
            'tenant_default_enabled' => (bool) ($section['enabled'] ?? ((int) ($preferenceRow['is_enabled'] ?? 0) === 1)),
            'team_day_available' => !empty($featureMap['agenda_team_day_view']),
            'stored_default_view' => $this->normalizeStoredView((string) ($section['view'] ?? '')),
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
     * @return array<int, array<string, string>>
     */
    private function buildViewRows(bool $teamDayAvailable): array
    {
        $rows = [
            [
                'key' => self::VIEW_DAY,
                'label' => 'Giorno',
                'description' => 'Apre l’agenda sulla giornata del professionista selezionato.',
            ],
            [
                'key' => self::VIEW_WEEK,
                'label' => 'Settimana',
                'description' => 'Mostra subito la settimana del professionista selezionato.',
            ],
        ];

        if ($teamDayAvailable) {
            $rows[] = [
                'key' => self::VIEW_TEAM_DAY,
                'label' => 'Giorno Team',
                'description' => 'Apre la vista giornaliera affiancata di tutti i professionisti visibili nello spazio.',
            ];
        }

        return $rows;
    }

    private function normalizeStoredView(string $view): string
    {
        $view = trim(strtolower($view));
        if (in_array($view, [self::VIEW_DAY, self::VIEW_WEEK, self::VIEW_TEAM_DAY], true)) {
            return $view;
        }

        return self::VIEW_DAY;
    }

    private function normalizeAvailableView(string $view, bool $teamDayAvailable): string
    {
        $normalized = $this->normalizeStoredView($view);
        if ($normalized === self::VIEW_TEAM_DAY && !$teamDayAvailable) {
            return self::VIEW_DAY;
        }

        return $normalized;
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
