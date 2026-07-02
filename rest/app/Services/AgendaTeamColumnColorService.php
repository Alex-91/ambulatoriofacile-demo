<?php

namespace App\Services;

use App\Models\PlatformTenantFeaturePreferencesModel;
use App\Models\PlatformTenantFeaturesModel;

class AgendaTeamColumnColorService
{
    public const FEATURE_KEY = 'agenda_team_day_view';

    private const CONFIG_KEY = 'team_day_column_colors';

    /** @var array<int, string> */
    private const SUGGESTED_COLORS = [
        '#2F7DAA',
        '#1F9D72',
        '#C96A32',
        '#7A5BC2',
        '#D04864',
        '#B58A11',
        '#0086A6',
        '#5D6D7E',
        '#4F8A3C',
        '#A6543F',
    ];

    private TenantFeatureService $tenantFeatureService;
    private PlatformTenantFeaturePreferencesModel $preferencesModel;
    private PlatformTenantFeaturesModel $tenantFeaturesModel;

    public function __construct()
    {
        $this->tenantFeatureService = new TenantFeatureService();
        $this->preferencesModel = new PlatformTenantFeaturePreferencesModel();
        $this->tenantFeaturesModel = new PlatformTenantFeaturesModel();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePlatformControls(int $tenantId): array
    {
        $state = $this->resolveFeatureState($tenantId);
        $featureId = (int) ($state['id_feature'] ?? 0);
        $overrideRow = $this->findTenantFeatureOverrideRow($tenantId, $featureId);
        $featureConfig = $this->decodeConfig((string) ($overrideRow['config_json'] ?? ''));
        $controlConfig = (array) ($featureConfig[self::CONFIG_KEY] ?? []);

        return [
            'feature_id' => $featureId,
            'feature_state' => $state,
            'enabled' => (bool) ($controlConfig['enabled'] ?? false),
            'feature_override_row' => $overrideRow,
            'raw_feature_config' => $featureConfig,
        ];
    }

    /**
     * @param array<string, mixed> $existingFeatureConfig
     * @return array<string, mixed>
     */
    public function mergePlatformControlsIntoConfig(array $existingFeatureConfig, bool $enabled): array
    {
        $config = is_array($existingFeatureConfig) ? $existingFeatureConfig : [];
        $section = (array) ($config[self::CONFIG_KEY] ?? []);
        $section['enabled'] = $enabled;
        $config[self::CONFIG_KEY] = $section;

        return $config;
    }

    /**
     * @param array<int, mixed> $professionals
     * @return array<string, mixed>
     */
    public function resolveTenantSettings(int $tenantId, array $professionals): array
    {
        $state = $this->resolveFeatureState($tenantId);
        $platformControls = $this->resolvePlatformControls($tenantId);
        $featureId = (int) ($state['id_feature'] ?? 0);
        $preferenceRow = $this->findTenantPreferenceRow($tenantId, $featureId);
        $preferenceConfig = $this->decodeConfig((string) ($preferenceRow['config_json'] ?? ''));
        $section = (array) ($preferenceConfig[self::CONFIG_KEY] ?? []);
        $normalizedProfessionals = $this->normalizeProfessionals($professionals);
        $customColorMap = $this->sanitizeDoctorColorMap(
            (array) ($section['doctor_colors'] ?? []),
            array_keys($normalizedProfessionals)
        );

        $doctorRows = [];
        foreach ($normalizedProfessionals as $doctorId => $doctor) {
            $suggestedColor = $this->resolveSuggestedColorForDoctor($doctorId);
            $customColor = $customColorMap[$doctorId] ?? '';

            $doctorRows[] = [
                'id_dot' => $doctorId,
                'label' => (string) ($doctor['label'] ?? ('Professionista ' . $doctorId)),
                'suggested_color' => $suggestedColor,
                'custom_color' => $customColor,
                'has_custom_color' => $customColor !== '',
                'effective_color' => $customColor !== '' ? $customColor : $suggestedColor,
            ];
        }

        return [
            'feature_id' => $featureId,
            'feature_state' => $state,
            'feature_available' => (bool) ($state['entitlement_enabled'] ?? false),
            'feature_enabled' => (bool) ($state['effective_enabled'] ?? false),
            'platform_controls_enabled' => (bool) ($platformControls['enabled'] ?? false),
            'color_management_available' => (bool) ($state['entitlement_enabled'] ?? false)
                && (bool) ($platformControls['enabled'] ?? false),
            'tenant_colors_enabled' => (bool) ($section['enabled'] ?? false),
            'colors_active' => (bool) ($section['enabled'] ?? false)
                && (bool) ($state['entitlement_enabled'] ?? false)
                && (bool) ($platformControls['enabled'] ?? false),
            'doctor_rows' => $doctorRows,
            'custom_color_map' => $customColorMap,
            'preference_row' => $preferenceRow,
            'feature_override_row' => $platformControls['feature_override_row'] ?? null,
            'raw_preference_config' => $preferenceConfig,
        ];
    }

    /**
     * @param array<int|string, mixed> $rawCustomColors
     * @param array<int, mixed> $professionals
     * @return array<string, mixed>
     */
    public function saveTenantPreferences(
        int $tenantId,
        bool $colorsEnabled,
        array $rawCustomColors,
        array $professionals,
        int $updatedByPlatformUserId = 0
    ): array {
        $settings = $this->resolveTenantSettings($tenantId, $professionals);
        if (empty($settings['color_management_available'])) {
            throw new \RuntimeException('I colori delle colonne Giorno Team non sono disponibili per questo spazio.');
        }

        $featureId = (int) ($settings['feature_id'] ?? 0);
        if ($featureId <= 0) {
            throw new \RuntimeException('Feature Giorno Team non trovata per questo spazio.');
        }

        $normalizedProfessionals = $this->normalizeProfessionals($professionals);
        $customColorMap = $this->sanitizeDoctorColorMap($rawCustomColors, array_keys($normalizedProfessionals));
        $config = is_array($settings['raw_preference_config'] ?? null)
            ? $settings['raw_preference_config']
            : [];

        $config[self::CONFIG_KEY] = [
            'enabled' => $colorsEnabled,
            'doctor_colors' => $customColorMap,
        ];

        $preferenceRow = is_array($settings['preference_row'] ?? null) ? $settings['preference_row'] : null;
        $storedEnabled = $preferenceRow !== null
            ? ((int) ($preferenceRow['is_enabled'] ?? 0) === 1)
            : (bool) ($settings['feature_enabled'] ?? false);

        $ok = $this->preferencesModel->setPreference(
            $tenantId,
            $featureId,
            $storedEnabled,
            $updatedByPlatformUserId > 0 ? $updatedByPlatformUserId : null,
            'tenant_master',
            $config,
            false
        );

        if (!$ok) {
            throw new \RuntimeException('Salvataggio colori colonne Giorno Team non riuscito.');
        }

        return $this->resolveTenantSettings($tenantId, $professionals);
    }

    /**
     * @param array<int, mixed> $professionals
     * @return array<int, array<string, mixed>>
     */
    public function resolveColumnThemes(int $tenantId, array $professionals): array
    {
        $settings = $this->resolveTenantSettings($tenantId, $professionals);
        if (empty($settings['colors_active'])) {
            return [];
        }

        $themes = [];
        foreach ((array) ($settings['doctor_rows'] ?? []) as $doctorRow) {
            $doctorId = (int) ($doctorRow['id_dot'] ?? 0);
            $color = (string) ($doctorRow['effective_color'] ?? '');
            if ($doctorId <= 0 || $color === '') {
                continue;
            }

            $themes[$doctorId] = $this->buildTheme($color);
        }

        return $themes;
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
     * @param array<int, mixed> $professionals
     * @return array<int, array<string, mixed>>
     */
    private function normalizeProfessionals(array $professionals): array
    {
        $rows = [];

        foreach ($professionals as $professional) {
            $row = is_object($professional) ? get_object_vars($professional) : (array) $professional;
            $doctorId = (int) ($row['id_dot'] ?? 0);
            if ($doctorId <= 0) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($row['cognome'] ?? '') . ' ' . (string) ($row['nome'] ?? ''));
            }

            $rows[$doctorId] = [
                'id_dot' => $doctorId,
                'label' => $label !== '' ? $label : ('Professionista ' . $doctorId),
            ];
        }

        ksort($rows);

        return $rows;
    }

    /**
     * @param array<int|string, mixed> $rawMap
     * @param array<int, int> $allowedDoctorIds
     * @return array<int, string>
     */
    private function sanitizeDoctorColorMap(array $rawMap, array $allowedDoctorIds): array
    {
        $allowed = array_values(array_unique(array_map('intval', $allowedDoctorIds)));
        $map = [];

        foreach ($rawMap as $doctorId => $color) {
            $normalizedDoctorId = (int) $doctorId;
            if ($normalizedDoctorId <= 0 || !in_array($normalizedDoctorId, $allowed, true)) {
                continue;
            }

            $normalizedColor = $this->normalizeHexColor((string) $color);
            if ($normalizedColor === '') {
                continue;
            }

            $map[$normalizedDoctorId] = $normalizedColor;
        }

        ksort($map);

        return $map;
    }

    private function resolveSuggestedColorForDoctor(int $doctorId): string
    {
        $palette = self::SUGGESTED_COLORS;
        $paletteSize = count($palette);
        if ($doctorId <= 0 || $paletteSize === 0) {
            return '#3C8DBC';
        }

        $hash = (int) sprintf('%u', crc32((string) $doctorId));
        return $palette[$hash % $paletteSize];
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

    /**
     * @return array<string, mixed>
     */
    private function buildTheme(string $color): array
    {
        $baseColor = $this->normalizeHexColor($color);
        $rgb = $this->hexToRgb($baseColor);
        if ($baseColor === '' || $rgb === null) {
            return [];
        }

        $dark = ['r' => 31, 'g' => 45, 'b' => 61];
        $white = ['r' => 255, 'g' => 255, 'b' => 255];
        $contrastText = $this->contrastColor($rgb);

        $headerBg = $this->mixRgb($rgb, $white, 0.88);
        $headerBorder = $this->mixRgb($rgb, $dark, 0.20);
        $headerText = $this->mixRgb($rgb, $dark, 0.58);
        $chipBg = $this->mixRgb($rgb, $white, 0.74);
        $chipText = $this->mixRgb($rgb, $dark, 0.46);
        $columnBg = $this->mixRgb($rgb, $white, 0.97);
        $freeBg = $this->mixRgb($rgb, $white, 0.89);
        $freeText = $this->mixRgb($rgb, $dark, 0.52);
        $emptyBg = $this->mixRgb($rgb, $white, 0.93);
        $emptyBorder = $this->mixRgb($rgb, $white, 0.66);
        $emptyText = $this->mixRgb($rgb, $dark, 0.54);
        $entryBorder = $this->mixRgb($rgb, $dark, 0.18);
        $shadow = $this->mixRgb($rgb, $dark, 0.46);
        $guideText = $this->mixRgb($rgb, $dark, 0.60);

        return [
            'base_color' => $baseColor,
            'entry_text' => $contrastText,
            'css_vars' => [
                '--agenda-team-column-soft-bg' => $this->rgbToCss($headerBg),
                '--agenda-team-column-soft-border' => $this->rgbToCss($headerBorder),
                '--agenda-team-column-soft-text' => $this->rgbToCss($headerText),
                '--agenda-team-column-chip-bg' => $this->rgbToCss($chipBg),
                '--agenda-team-column-chip-text' => $this->rgbToCss($chipText),
                '--agenda-team-column-bg' => $this->rgbToCss($columnBg),
                '--agenda-team-column-guide-line' => $this->rgbToCss($rgb, 0.12),
                '--agenda-team-column-guide-text' => $this->rgbToCss($guideText, 0.96),
                '--agenda-team-column-free-bg' => $this->rgbToCss($freeBg),
                '--agenda-team-column-free-border' => $this->rgbToCss($entryBorder, 0.90),
                '--agenda-team-column-free-text' => $this->rgbToCss($freeText),
                '--agenda-team-column-empty-bg' => $this->rgbToCss($emptyBg),
                '--agenda-team-column-empty-border' => $this->rgbToCss($emptyBorder),
                '--agenda-team-column-empty-text' => $this->rgbToCss($emptyText),
                '--agenda-team-column-entry-bg' => $baseColor,
                '--agenda-team-column-entry-border' => $this->rgbToCss($entryBorder),
                '--agenda-team-column-entry-text' => $contrastText,
                '--agenda-team-column-selected-ring' => $this->rgbToCss($rgb, 0.20),
                '--agenda-team-column-shadow' => $this->rgbToCss($shadow, 0.16),
            ],
            'pdf_header_bg' => $this->rgbToHex($headerBg),
            'pdf_header_border' => $this->rgbToHex($headerBorder),
            'pdf_header_text' => $this->rgbToHex($headerText),
        ];
    }

    /**
     * @return array{r:int,g:int,b:int}|null
     */
    private function hexToRgb(string $hex): ?array
    {
        $hex = $this->normalizeHexColor($hex);
        if ($hex === '') {
            return null;
        }

        return [
            'r' => hexdec(substr($hex, 1, 2)),
            'g' => hexdec(substr($hex, 3, 2)),
            'b' => hexdec(substr($hex, 5, 2)),
        ];
    }

    /**
     * @param array{r:int,g:int,b:int} $base
     * @param array{r:int,g:int,b:int} $mix
     * @return array{r:int,g:int,b:int}
     */
    private function mixRgb(array $base, array $mix, float $ratio): array
    {
        $ratio = max(0.0, min(1.0, $ratio));

        return [
            'r' => (int) round($base['r'] + (($mix['r'] - $base['r']) * $ratio)),
            'g' => (int) round($base['g'] + (($mix['g'] - $base['g']) * $ratio)),
            'b' => (int) round($base['b'] + (($mix['b'] - $base['b']) * $ratio)),
        ];
    }

    /**
     * @param array{r:int,g:int,b:int} $rgb
     */
    private function contrastColor(array $rgb): string
    {
        $yiq = (($rgb['r'] * 299) + ($rgb['g'] * 587) + ($rgb['b'] * 114)) / 1000;
        return $yiq >= 166 ? '#1F2D3D' : '#FFFFFF';
    }

    /**
     * @param array{r:int,g:int,b:int} $rgb
     */
    private function rgbToCss(array $rgb, ?float $alpha = null): string
    {
        if ($alpha !== null) {
            $safeAlpha = max(0.0, min(1.0, $alpha));
            return 'rgba(' . $rgb['r'] . ', ' . $rgb['g'] . ', ' . $rgb['b'] . ', ' . $safeAlpha . ')';
        }

        return 'rgb(' . $rgb['r'] . ', ' . $rgb['g'] . ', ' . $rgb['b'] . ')';
    }

    /**
     * @param array{r:int,g:int,b:int} $rgb
     */
    private function rgbToHex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', $rgb['r'], $rgb['g'], $rgb['b']);
    }
}
