<?php

namespace App\Services;

use App\Models\PlatformTenantFeaturePreferencesModel;

class AgendaTextColorThemeService
{
    public const FEATURE_KEY = 'agenda_text_color_theme';

    private const CONFIG_KEY = 'agenda_text_color_theme';

    /**
     * @var array<string, array<string, mixed>>
     */
    private const STYLE_DEFINITIONS = [
        'appointment_text' => [
            'label' => 'Testo slot colorati e appuntamenti',
            'description' => 'Prenotati, slot con colore del tipo visita e blocchi su sfondo pieno.',
            'default_color' => '#FFFFFF',
            'css_vars' => ['--agenda-appointment-text'],
        ],
        'free_slot_text' => [
            'label' => 'Testo slot liberi',
            'description' => 'Etichette e orari degli slot disponibili nelle viste agenda.',
            'default_color' => '#2E7EB0',
            'css_vars' => ['--agenda-free-slot-text'],
        ],
        'warning_text' => [
            'label' => 'Testo avvisi e chiusure',
            'description' => 'Card chiare di blocco, chiusura e stati di attenzione in Giorno Team.',
            'default_color' => '#B03A34',
            'css_vars' => ['--agenda-warning-text'],
        ],
        'team_header_text' => [
            'label' => 'Titoli intestazioni Giorno Team',
            'description' => 'Nome del professionista nelle testate delle colonne.',
            'default_color' => '#1F2D3D',
            'css_vars' => ['--agenda-team-header-text'],
        ],
        'team_secondary_text' => [
            'label' => 'Badge e testi secondari Giorno Team',
            'description' => 'Badge, guide orarie, testi vuoti e altri dettagli di supporto.',
            'default_color' => '#406173',
            'css_vars' => ['--agenda-team-secondary-text'],
        ],
        'location_text' => [
            'label' => 'Testo sede e stanza',
            'description' => 'Etichette laterali di ambulatorio e stanza accanto agli slot.',
            'default_color' => '#405463',
            'css_vars' => ['--agenda-location-text', '--agenda-location-empty-text'],
        ],
    ];

    private TenantFeatureService $tenantFeatureService;
    private PlatformTenantFeaturePreferencesModel $preferencesModel;

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
        $state = $this->resolveFeatureState($tenantId);
        $featureId = (int) ($state['id_feature'] ?? 0);
        $preferenceRow = $this->findTenantPreferenceRow($tenantId, $featureId);
        $preferenceConfig = $this->decodeConfig((string) ($preferenceRow['config_json'] ?? ''));
        $section = (array) ($preferenceConfig[self::CONFIG_KEY] ?? []);
        $customColorMap = $this->sanitizeColorMap((array) ($section['colors'] ?? []));
        $styleRows = [];

        foreach (self::STYLE_DEFINITIONS as $styleKey => $definition) {
            $defaultColor = (string) ($definition['default_color'] ?? '#1F2D3D');
            $customColor = (string) ($customColorMap[$styleKey] ?? '');

            $styleRows[] = [
                'key' => $styleKey,
                'label' => (string) ($definition['label'] ?? $styleKey),
                'description' => (string) ($definition['description'] ?? ''),
                'default_color' => $defaultColor,
                'custom_color' => $customColor,
                'has_custom_color' => $customColor !== '',
                'effective_color' => $customColor !== '' ? $customColor : $defaultColor,
            ];
        }

        return [
            'feature_id' => $featureId,
            'feature_state' => $state,
            'theme_management_available' => $featureId > 0 && (bool) ($state['entitlement_enabled'] ?? false),
            'tenant_theme_enabled' => (bool) ($section['enabled'] ?? false),
            'theme_active' => $featureId > 0
                && (bool) ($state['entitlement_enabled'] ?? false)
                && (bool) ($section['enabled'] ?? false),
            'style_rows' => $styleRows,
            'custom_color_map' => $customColorMap,
            'preference_row' => $preferenceRow,
            'raw_preference_config' => $preferenceConfig,
        ];
    }

    /**
     * @param array<int|string, mixed> $rawCustomColors
     * @return array<string, mixed>
     */
    public function saveTenantPreferences(
        int $tenantId,
        bool $themeEnabled,
        array $rawCustomColors,
        int $updatedByPlatformUserId = 0
    ): array {
        $settings = $this->resolveTenantSettings($tenantId);
        if (empty($settings['theme_management_available'])) {
            throw new \RuntimeException('La palette testo agenda non è disponibile per questo spazio.');
        }

        $featureId = (int) ($settings['feature_id'] ?? 0);
        if ($featureId <= 0) {
            throw new \RuntimeException('Feature palette testo agenda non trovata per questo spazio.');
        }

        $customColorMap = $this->sanitizeColorMap($rawCustomColors);
        $config = is_array($settings['raw_preference_config'] ?? null)
            ? $settings['raw_preference_config']
            : [];

        $config[self::CONFIG_KEY] = [
            'enabled' => $themeEnabled,
            'colors' => $customColorMap,
        ];

        $ok = $this->preferencesModel->setPreference(
            $tenantId,
            $featureId,
            $themeEnabled,
            $updatedByPlatformUserId > 0 ? $updatedByPlatformUserId : null,
            'tenant_master',
            $config,
            false
        );

        if (!$ok) {
            throw new \RuntimeException('Salvataggio palette testo agenda non riuscito.');
        }

        return $this->resolveTenantSettings($tenantId);
    }

    /**
     * @return array<string, string>
     */
    public function resolveCssVariables(int $tenantId): array
    {
        $settings = $this->resolveTenantSettings($tenantId);
        if (empty($settings['theme_active'])) {
            return [];
        }

        $vars = [];
        foreach ((array) ($settings['style_rows'] ?? []) as $styleRow) {
            if (empty($styleRow['has_custom_color'])) {
                continue;
            }

            $styleKey = trim((string) ($styleRow['key'] ?? ''));
            $color = $this->normalizeHexColor((string) ($styleRow['effective_color'] ?? ''));
            $definition = self::STYLE_DEFINITIONS[$styleKey] ?? null;
            if ($styleKey === '' || $color === '' || !is_array($definition)) {
                continue;
            }

            foreach ((array) ($definition['css_vars'] ?? []) as $cssVarName) {
                $cssVarName = trim((string) $cssVarName);
                if ($cssVarName !== '') {
                    $vars[$cssVarName] = $color;
                }
            }
        }

        ksort($vars);

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFeatureState(int $tenantId): array
    {
        foreach ($this->tenantFeatureService->listFeatureStatesForTenant($tenantId) as $state) {
            $featureKey = trim((string) ($state['feature_key'] ?? ''));
            if ($featureKey === self::FEATURE_KEY) {
                return $state;
            }
        }

        return [];
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
     * @param array<int|string, mixed> $rawMap
     * @return array<string, string>
     */
    private function sanitizeColorMap(array $rawMap): array
    {
        $allowedKeys = array_keys(self::STYLE_DEFINITIONS);
        $map = [];

        foreach ($rawMap as $styleKey => $color) {
            $normalizedStyleKey = trim((string) $styleKey);
            if ($normalizedStyleKey === '' || !in_array($normalizedStyleKey, $allowedKeys, true)) {
                continue;
            }

            $normalizedColor = $this->normalizeHexColor((string) $color);
            if ($normalizedColor === '') {
                continue;
            }

            $map[$normalizedStyleKey] = $normalizedColor;
        }

        ksort($map);

        return $map;
    }

    private function normalizeHexColor(string $value): string
    {
        $normalized = strtoupper(trim($value));
        if (preg_match('/^#[0-9A-F]{6}$/', $normalized) === 1) {
            return $normalized;
        }

        return '';
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
