<?php

namespace App\Services;

use App\Config\BillingModule;
use App\Models\PlatformFeaturesModel;
use App\Models\PlatformTenantFeaturePreferencesModel;

class BillingDocumentSettingsService
{
    private BillingFeatureService $billingFeatures;
    private PlatformFeaturesModel $featuresModel;
    private PlatformTenantFeaturePreferencesModel $preferencesModel;

    public function __construct(
        ?BillingFeatureService $billingFeatures = null,
        ?PlatformFeaturesModel $featuresModel = null,
        ?PlatformTenantFeaturePreferencesModel $preferencesModel = null
    ) {
        $this->billingFeatures = $billingFeatures ?? new BillingFeatureService();
        $this->featuresModel = $featuresModel ?? new PlatformFeaturesModel();
        $this->preferencesModel = $preferencesModel ?? new PlatformTenantFeaturePreferencesModel();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveTenantSettings(int $tenantId): array
    {
        $feature = $this->featuresModel->findByKey(BillingModule::FEATURE_KEY);
        $featureId = (int) ($feature['id_feature'] ?? 0);
        $preferenceRow = $featureId > 0 && $tenantId > 0
            ? $this->preferencesModel
                ->where('id_tenant', $tenantId)
                ->where('id_feature', $featureId)
                ->first()
            : null;

        $rawPreferenceConfig = trim((string) ($preferenceRow['config_json'] ?? ''));

        return [
            'tenant_id' => $tenantId,
            'feature' => [
                'feature_id' => $featureId,
                'available' => $tenantId > 0 ? $this->billingFeatures->isEnabledForTenant($tenantId) : false,
                'feature_name' => (string) ($feature['feature_name'] ?? 'Fatturazione'),
                'description' => (string) ($feature['description'] ?? ''),
            ],
            'config' => $this->sanitizeConfig($this->decodeConfig($rawPreferenceConfig)),
            'preference_row' => is_array($preferenceRow) ? $preferenceRow : null,
            'using_default_preferences' => $rawPreferenceConfig === '',
        ];
    }

    /**
     * @param array<string, mixed> $rawConfig
     * @return array<string, mixed>
     */
    public function saveTenantSettings(int $tenantId, array $rawConfig, int $updatedByPlatformUserId = 0): array
    {
        $current = $this->resolveTenantSettings($tenantId);
        if (empty($current['feature']['available'])) {
            throw new \RuntimeException('Il modulo Fatturazione non e disponibile per questo spazio.');
        }

        $featureId = (int) ($current['feature']['feature_id'] ?? 0);
        if ($featureId <= 0) {
            throw new \RuntimeException('Feature Fatturazione non trovata nel catalogo piattaforma.');
        }

        $config = $this->sanitizeConfig($rawConfig);
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
            throw new \RuntimeException('Salvataggio configurazione documento fatturazione non riuscito.');
        }

        return $this->resolveTenantSettings($tenantId);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultConfig(): array
    {
        return [
            'version' => 1,
            'document_title' => 'Documento fatturazione',
            'document_code_prefix' => 'FT',
            'branding' => [
                'logo_mode' => 'none',
                'logo_url' => '',
                'accent_color' => '#2c8895',
                'header_title' => '',
                'header_subtitle' => '',
                'footer_note' => '',
            ],
            'layout' => [
                'show_logo' => true,
                'show_header' => true,
                'show_footer' => true,
                'show_patient_box' => true,
                'show_payment_box' => true,
                'show_signature_box' => false,
                'show_terms_box' => false,
            ],
            'fields' => [
                'show_document_number' => true,
                'show_issue_date' => true,
                'show_patient_name' => true,
                'show_patient_tax_code' => true,
                'show_payment_date' => true,
                'show_payment_method' => true,
                'show_line_items' => true,
                'show_vat_summary' => true,
                'show_stamp_duty' => true,
                'show_notes' => true,
            ],
            'labels' => [
                'patient_section_title' => 'Dati paziente',
                'payment_section_title' => 'Pagamento',
                'notes_label' => 'Note',
                'signature_label' => 'Firma',
                'terms_label' => 'Informativa',
            ],
            'integration_ts' => [
                'enabled_when_available' => true,
                'show_ts_reference' => true,
                'require_expense_type' => false,
                'require_opposition_flag' => false,
            ],
        ];
    }

    /**
     * @param mixed $rawConfig
     * @return array<string, mixed>
     */
    private function sanitizeConfig($rawConfig): array
    {
        $defaults = $this->defaultConfig();
        $rawConfig = is_array($rawConfig) ? $rawConfig : [];
        $branding = is_array($rawConfig['branding'] ?? null) ? $rawConfig['branding'] : [];
        $layout = is_array($rawConfig['layout'] ?? null) ? $rawConfig['layout'] : [];
        $fields = is_array($rawConfig['fields'] ?? null) ? $rawConfig['fields'] : [];
        $labels = is_array($rawConfig['labels'] ?? null) ? $rawConfig['labels'] : [];
        $integrationTs = is_array($rawConfig['integration_ts'] ?? null) ? $rawConfig['integration_ts'] : [];

        $logoMode = trim(strtolower((string) ($branding['logo_mode'] ?? $defaults['branding']['logo_mode'])));
        if (!in_array($logoMode, ['none', 'path'], true)) {
            $logoMode = (string) $defaults['branding']['logo_mode'];
        }

        $accentColor = trim((string) ($branding['accent_color'] ?? $defaults['branding']['accent_color']));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accentColor)) {
            $accentColor = (string) $defaults['branding']['accent_color'];
        }

        $documentCodePrefix = strtoupper(preg_replace(
            '/[^A-Z0-9\\-\\/]/',
            '',
            trim((string) ($rawConfig['document_code_prefix'] ?? $defaults['document_code_prefix']))
        ));
        if ($documentCodePrefix === '') {
            $documentCodePrefix = (string) $defaults['document_code_prefix'];
        }

        return [
            'version' => 1,
            'document_title' => $this->sanitizeText(
                $rawConfig['document_title'] ?? $defaults['document_title'],
                120,
                (string) $defaults['document_title']
            ),
            'document_code_prefix' => substr($documentCodePrefix, 0, 12),
            'branding' => [
                'logo_mode' => $logoMode,
                'logo_url' => $this->sanitizeText($branding['logo_url'] ?? '', 255, ''),
                'accent_color' => $accentColor,
                'header_title' => $this->sanitizeText($branding['header_title'] ?? '', 120, ''),
                'header_subtitle' => $this->sanitizeText($branding['header_subtitle'] ?? '', 180, ''),
                'footer_note' => $this->sanitizeText($branding['footer_note'] ?? '', 255, ''),
            ],
            'layout' => [
                'show_logo' => $this->toBool($layout['show_logo'] ?? $defaults['layout']['show_logo']),
                'show_header' => $this->toBool($layout['show_header'] ?? $defaults['layout']['show_header']),
                'show_footer' => $this->toBool($layout['show_footer'] ?? $defaults['layout']['show_footer']),
                'show_patient_box' => $this->toBool($layout['show_patient_box'] ?? $defaults['layout']['show_patient_box']),
                'show_payment_box' => $this->toBool($layout['show_payment_box'] ?? $defaults['layout']['show_payment_box']),
                'show_signature_box' => $this->toBool($layout['show_signature_box'] ?? $defaults['layout']['show_signature_box']),
                'show_terms_box' => $this->toBool($layout['show_terms_box'] ?? $defaults['layout']['show_terms_box']),
            ],
            'fields' => [
                'show_document_number' => $this->toBool($fields['show_document_number'] ?? $defaults['fields']['show_document_number']),
                'show_issue_date' => $this->toBool($fields['show_issue_date'] ?? $defaults['fields']['show_issue_date']),
                'show_patient_name' => $this->toBool($fields['show_patient_name'] ?? $defaults['fields']['show_patient_name']),
                'show_patient_tax_code' => $this->toBool($fields['show_patient_tax_code'] ?? $defaults['fields']['show_patient_tax_code']),
                'show_payment_date' => $this->toBool($fields['show_payment_date'] ?? $defaults['fields']['show_payment_date']),
                'show_payment_method' => $this->toBool($fields['show_payment_method'] ?? $defaults['fields']['show_payment_method']),
                'show_line_items' => $this->toBool($fields['show_line_items'] ?? $defaults['fields']['show_line_items']),
                'show_vat_summary' => $this->toBool($fields['show_vat_summary'] ?? $defaults['fields']['show_vat_summary']),
                'show_stamp_duty' => $this->toBool($fields['show_stamp_duty'] ?? $defaults['fields']['show_stamp_duty']),
                'show_notes' => $this->toBool($fields['show_notes'] ?? $defaults['fields']['show_notes']),
            ],
            'labels' => [
                'patient_section_title' => $this->sanitizeText(
                    $labels['patient_section_title'] ?? $defaults['labels']['patient_section_title'],
                    80,
                    (string) $defaults['labels']['patient_section_title']
                ),
                'payment_section_title' => $this->sanitizeText(
                    $labels['payment_section_title'] ?? $defaults['labels']['payment_section_title'],
                    80,
                    (string) $defaults['labels']['payment_section_title']
                ),
                'notes_label' => $this->sanitizeText(
                    $labels['notes_label'] ?? $defaults['labels']['notes_label'],
                    80,
                    (string) $defaults['labels']['notes_label']
                ),
                'signature_label' => $this->sanitizeText(
                    $labels['signature_label'] ?? $defaults['labels']['signature_label'],
                    80,
                    (string) $defaults['labels']['signature_label']
                ),
                'terms_label' => $this->sanitizeText(
                    $labels['terms_label'] ?? $defaults['labels']['terms_label'],
                    80,
                    (string) $defaults['labels']['terms_label']
                ),
            ],
            'integration_ts' => [
                'enabled_when_available' => $this->toBool(
                    $integrationTs['enabled_when_available'] ?? $defaults['integration_ts']['enabled_when_available']
                ),
                'show_ts_reference' => $this->toBool(
                    $integrationTs['show_ts_reference'] ?? $defaults['integration_ts']['show_ts_reference']
                ),
                'require_expense_type' => $this->toBool(
                    $integrationTs['require_expense_type'] ?? $defaults['integration_ts']['require_expense_type']
                ),
                'require_opposition_flag' => $this->toBool(
                    $integrationTs['require_opposition_flag'] ?? $defaults['integration_ts']['require_opposition_flag']
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $value
     */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param mixed $value
     */
    private function sanitizeText($value, int $maxLen, string $fallback = ''): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            $value = $fallback;
        }

        if ($maxLen > 0 && strlen($value) > $maxLen) {
            $value = substr($value, 0, $maxLen);
        }

        return $value;
    }
}
