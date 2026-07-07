<?php

namespace App\Controllers\Admin;

use App\Services\BillingDocumentSettingsService;
use App\Services\BillingTsModuleStatusService;

class BillingDocumentSettingsController extends BillingAdminBaseController
{
    private BillingDocumentSettingsService $settingsService;
    private BillingTsModuleStatusService $moduleStatus;

    public function __construct()
    {
        parent::__construct();
        $this->settingsService = new BillingDocumentSettingsService();
        $this->moduleStatus = new BillingTsModuleStatusService();
    }

    public function index()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);

        return view('admin/billing/document_settings', [
            'menu_items' => $this->adminMenuItems(),
            'tenantScope' => $tenantScope,
            'moduleStatus' => $this->moduleStatus->describe($this->currentTenantContext(), $tenantId),
            'settings' => $this->settingsService->resolveTenantSettings($tenantId),
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function save()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $targetUrl = site_url('admin/fatturazione-documento');

        if ($tenantId <= 0) {
            return redirect()->to($targetUrl)->with('errors', [
                'generic' => 'Spazio fatturazione non risolto per questa sessione.',
            ]);
        }

        try {
            $this->settingsService->saveTenantSettings(
                $tenantId,
                $this->requestConfigPayload(),
                $this->resolveUpdaterUserId()
            );

            return redirect()->to($targetUrl)->with(
                'success',
                'Template documento fatturazione salvato con successo.'
            );
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('errors', [
                'generic' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requestConfigPayload(): array
    {
        return [
            'document_title' => $this->requestString('document_title', 'Documento fatturazione', 120),
            'document_code_prefix' => $this->requestString('document_code_prefix', 'FT', 12),
            'branding' => [
                'logo_mode' => $this->requestString('branding_logo_mode', 'none', 20),
                'logo_url' => $this->requestString('branding_logo_url', '', 255),
                'accent_color' => $this->requestString('branding_accent_color', '#2c8895', 7),
                'header_title' => $this->requestString('branding_header_title', '', 120),
                'header_subtitle' => $this->requestString('branding_header_subtitle', '', 180),
                'footer_note' => $this->requestString('branding_footer_note', '', 255),
            ],
            'layout' => [
                'show_logo' => $this->requestBool('layout_show_logo'),
                'show_header' => $this->requestBool('layout_show_header'),
                'show_footer' => $this->requestBool('layout_show_footer'),
                'show_patient_box' => $this->requestBool('layout_show_patient_box'),
                'show_payment_box' => $this->requestBool('layout_show_payment_box'),
                'show_signature_box' => $this->requestBool('layout_show_signature_box'),
                'show_terms_box' => $this->requestBool('layout_show_terms_box'),
            ],
            'fields' => [
                'show_document_number' => $this->requestBool('field_show_document_number'),
                'show_issue_date' => $this->requestBool('field_show_issue_date'),
                'show_patient_name' => $this->requestBool('field_show_patient_name'),
                'show_patient_tax_code' => $this->requestBool('field_show_patient_tax_code'),
                'show_payment_date' => $this->requestBool('field_show_payment_date'),
                'show_payment_method' => $this->requestBool('field_show_payment_method'),
                'show_line_items' => $this->requestBool('field_show_line_items'),
                'show_vat_summary' => $this->requestBool('field_show_vat_summary'),
                'show_stamp_duty' => $this->requestBool('field_show_stamp_duty'),
                'show_notes' => $this->requestBool('field_show_notes'),
            ],
            'labels' => [
                'patient_section_title' => $this->requestString('label_patient_section_title', 'Dati paziente', 80),
                'payment_section_title' => $this->requestString('label_payment_section_title', 'Pagamento', 80),
                'notes_label' => $this->requestString('label_notes_label', 'Note', 80),
                'signature_label' => $this->requestString('label_signature_label', 'Firma', 80),
                'terms_label' => $this->requestString('label_terms_label', 'Informativa', 80),
            ],
            'integration_ts' => [
                'enabled_when_available' => $this->requestBool('ts_enabled_when_available'),
                'show_ts_reference' => $this->requestBool('ts_show_ts_reference'),
                'require_expense_type' => $this->requestBool('ts_require_expense_type'),
                'require_opposition_flag' => $this->requestBool('ts_require_opposition_flag'),
            ],
        ];
    }

    private function resolveUpdaterUserId(): int
    {
        $platformUserId = (int) (session()->get('platform_user_id') ?? 0);
        if ($platformUserId > 0) {
            return $platformUserId;
        }

        $sessionUser = session()->get('utente_sess');
        if (is_object($sessionUser) && !empty($sessionUser->id_user)) {
            return (int) $sessionUser->id_user;
        }

        return 0;
    }

    private function requestBool(string $key, bool $default = false): bool
    {
        $value = $this->request->getPost($key);
        if ($value === null) {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }

    private function requestString(string $key, string $default = '', int $maxLen = 0): string
    {
        $value = trim((string) ($this->request->getPost($key) ?? $default));
        if ($maxLen > 0 && strlen($value) > $maxLen) {
            $value = substr($value, 0, $maxLen);
        }

        return $value;
    }
}
