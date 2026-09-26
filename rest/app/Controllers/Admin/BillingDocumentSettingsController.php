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

        return view('admin/billing/document_designer', [
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
            $currentSettings = $this->settingsService->resolveTenantSettings($tenantId);
            $this->settingsService->saveTenantSettings(
                $tenantId,
                $this->requestConfigPayload((array) ($currentSettings['config'] ?? [])),
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
    private function requestConfigPayload(array $currentConfig = []): array
    {
        $posted = json_encode($this->request->getPost(), JSON_THROW_ON_ERROR);
        if (strlen($posted) > 250000) throw new \InvalidArgumentException('Il modello supera la dimensione complessiva consentita.');
        if ($this->request->getPost('designer_json') !== null) {
            $raw = (string) $this->request->getPost('designer_json');
            if (strlen($raw)>150000) throw new \InvalidArgumentException('Il modello supera la dimensione consentita.');
            $currentConfig['designer'] = \App\Services\BillingDocumentDesigner::validate(json_decode($raw,true,64,JSON_THROW_ON_ERROR));
            $currentConfig['document_title'] = $this->requestString('document_title','Fattura',0);
            $currentConfig['document_code_prefix'] = $this->requestString('document_code_prefix','FT',12);
            foreach (['header_title'=>0,'header_subtitle'=>0,'header_extra'=>0,'terms_text'=>0,'footer_note'=>0,'logo_url'=>255] as $key=>$length) {
                $currentConfig['branding'][$key] = $this->requestString('branding_'.$key,'',$length);
            }
            $logo = $currentConfig['branding']['logo_url'];
            if ($logo!=='' && !preg_match('#^(https?://|/)[^<>"\x00-\x20]+$#i',$logo)) throw new \InvalidArgumentException('Inserisci un URL HTTP/HTTPS o un percorso logo che inizia con /.');
            $currentConfig['branding']['logo_mode'] = $this->requestBool('branding_logo_enabled')?'path':'none';
            foreach (['enabled_when_available','show_ts_reference','require_expense_type','require_opposition_flag'] as $key) $currentConfig['integration_ts'][$key]=$this->requestBool('ts_'.$key);
            return $currentConfig;
        }
        $currentDefaults = is_array($currentConfig['defaults'] ?? null) ? $currentConfig['defaults'] : [];

        return [
            'document_title' => $this->requestString('document_title', 'Documento fatturazione', 0),
            'document_code_prefix' => $this->requestString('document_code_prefix', 'FT', 12),
            'defaults' => [
                'stamp_duty_amount' => (string) ($currentDefaults['stamp_duty_amount'] ?? '0.00'),
                'document_type' => $this->requestString(
                    'default_document_type',
                    (string) ($currentDefaults['document_type'] ?? 'invoice'),
                    20
                ),
                'payment_method' => $this->requestString(
                    'default_payment_method',
                    (string) ($currentDefaults['payment_method'] ?? 'bank_transfer'),
                    30
                ),
                'ts_expense_type_code' => $this->requestString(
                    'default_ts_expense_type_code',
                    (string) ($currentDefaults['ts_expense_type_code'] ?? 'SP'),
                    4
                ),
                'ts_opposition_flag' => $this->request->getPost('default_ts_opposition_flag') === null
                    ? !empty($currentDefaults['ts_opposition_flag'])
                    : $this->requestBool('default_ts_opposition_flag'),
            ],
            'branding' => [
                'logo_mode' => $this->requestString('branding_logo_mode', 'none', 20),
                'logo_url' => $this->requestString('branding_logo_url', '', 255),
                'accent_color' => $this->requestString('branding_accent_color', '#2c8895', 7),
                'header_title' => $this->requestString('branding_header_title', '', 0),
                'header_subtitle' => $this->requestString('branding_header_subtitle', '', 0),
                'header_style' => $this->requestString('branding_header_style', 'colored', 20),
                'header_extra' => $this->requestString('branding_header_extra', '', 0),
                'show_issuer_extra' => $this->requestBool('branding_show_issuer_extra'),
                'show_issuer_name' => $this->requestBool('branding_show_issuer_name'),
                'show_issuer_address' => $this->requestBool('branding_show_issuer_address'),
                'show_issuer_tax_code' => $this->requestBool('branding_show_issuer_tax_code'),
                'show_issuer_vat_number' => $this->requestBool('branding_show_issuer_vat_number'),
                'show_issuer_pec' => $this->requestBool('branding_show_issuer_pec'),
                'show_issuer_pension' => $this->requestBool('branding_show_issuer_pension'),
                'header_document_number' => $this->requestBool('branding_header_document_number'),
                'header_issue_date' => $this->requestBool('branding_header_issue_date'),
                'header_payment_method' => $this->requestBool('branding_header_payment_method'),
                'header_payment_date' => $this->requestBool('branding_header_payment_date'),
                'header_patient_name' => $this->requestBool('branding_header_patient_name'),
                'header_patient_tax_code' => $this->requestBool('branding_header_patient_tax_code'),
                'header_patient_address' => $this->requestBool('branding_header_patient_address'),
                'header_patient_city' => $this->requestBool('branding_header_patient_city'),
                'header_patient_email' => $this->requestBool('branding_header_patient_email'),
                'header_patient_phone' => $this->requestBool('branding_header_patient_phone'),
                'header_patient_mobile' => $this->requestBool('branding_header_patient_mobile'),
                'show_header_metadata' => false,
                'show_header_opposition' => $this->requestBool('branding_show_header_opposition'),
                'footer_note' => $this->requestString('branding_footer_note', '', 0),
                'terms_text' => $this->requestString('branding_terms_text', '', 0),
            ],
            'layout' => [
                'show_logo' => $this->requestBool('layout_show_logo'),
                'show_header' => $this->requestBool('layout_show_header'),
                'show_footer' => $this->requestBool('layout_show_footer'),
                'show_document_box' => $this->requestBool('layout_show_document_box'),
                'show_patient_box' => $this->requestBool('layout_show_patient_box'),
                'show_payment_box' => $this->requestBool('layout_show_payment_box'),
                'show_signature_box' => $this->requestBool('layout_show_signature_box'),
                'show_terms_box' => $this->requestBool('layout_show_terms_box'),
            ],
            'fields' => [
                'show_issuer_name' => $this->requestBool('field_show_issuer_name'),
                'show_issuer_address' => $this->requestBool('field_show_issuer_address'),
                'show_issuer_tax_code' => $this->requestBool('field_show_issuer_tax_code'),
                'show_issuer_vat_number' => $this->requestBool('field_show_issuer_vat_number'),
                'show_issuer_pec' => $this->requestBool('field_show_issuer_pec'),
                'show_issuer_pension' => $this->requestBool('field_show_issuer_pension'),
                'show_issuer_extra' => $this->requestBool('field_show_issuer_extra'),
                'show_document_number' => $this->requestBool('field_show_document_number'),
                'show_issue_date' => $this->requestBool('field_show_issue_date'),
                'show_patient_name' => $this->requestBool('field_show_patient_name'),
                'show_patient_tax_code' => $this->requestBool('field_show_patient_tax_code'),
                'show_patient_address' => $this->requestBool('field_show_patient_address'),
                'show_patient_city' => $this->requestBool('field_show_patient_city'),
                'show_patient_email' => $this->requestBool('field_show_patient_email'),
                'show_patient_phone' => $this->requestBool('field_show_patient_phone'),
                'show_patient_mobile' => $this->requestBool('field_show_patient_mobile'),
                'show_payment_date' => $this->requestBool('field_show_payment_date'),
                'show_payment_method' => $this->requestBool('field_show_payment_method'),
                'show_line_items' => $this->requestBool('field_show_line_items'),
                'show_vat_summary' => $this->requestBool('field_show_vat_summary'),
                'show_stamp_duty' => $this->requestBool('field_show_stamp_duty'),
                'show_notes' => $this->requestBool('field_show_notes'),
            ],
            'labels' => [
                'patient_section_title' => $this->requestString('label_patient_section_title', 'Dati paziente', 0),
                'payment_section_title' => $this->requestString('label_payment_section_title', 'Pagamento', 0),
                'notes_label' => $this->requestString('label_notes_label', 'Note', 0),
                'signature_label' => $this->requestString('label_signature_label', 'Firma', 0),
                'terms_label' => $this->requestString('label_terms_label', 'Informativa', 0),
            ],
            'integration_ts' => [
                'enabled_when_available' => $this->requestBool('ts_enabled_when_available'),
                'show_ts_reference' => $this->requestBool('ts_show_ts_reference'),
                'require_expense_type' => $this->requestBool('ts_require_expense_type'),
                'require_opposition_flag' => $this->requestBool('ts_require_opposition_flag'),
            ],
            // The client-document catalog and fiscal defaults are managed from Configura Fatturazione.
            'service_catalog' => is_array($currentConfig['service_catalog'] ?? null)
                ? $currentConfig['service_catalog']
                : [],
            'vat' => is_array($currentConfig['vat'] ?? null)
                ? $currentConfig['vat']
                : [],
            'pension_fund' => is_array($currentConfig['pension_fund'] ?? null)
                ? $currentConfig['pension_fund']
                : [],
            'fiscal_data' => is_array($currentConfig['fiscal_data'] ?? null)
                ? $currentConfig['fiscal_data']
                : [],
            'email_delivery' => is_array($currentConfig['email_delivery'] ?? null)
                ? $currentConfig['email_delivery']
                : [],
        ];
    }

    public function previewDesigner()
    {
        if ($guard=$this->ensureAccess()) return $guard;
        try {
            $scope=$this->resolveTenantScope();
            $settings=$this->settingsService->resolveTenantSettings((int)($scope['tenant_id']??0));
            $config=$this->requestConfigPayload($settings['config']);
            $preview=\App\Services\BillingDocumentDesigner::sample($config);
            $html=view('admin/billing/designer_document',['preview'=>$preview,'pdfMode'=>$this->request->getPost('preview_mode')==='pdf']);
            $result=['html'=>$html];
            if ($this->request->getPost('preview_mode')==='pdf') {
                $options=(new \App\Services\BillingPdfOptionsFactory())->create((int) $scope['tenant_id']);
                $pdf=new \Dompdf\Dompdf($options);
                $pdf->loadHtml($html,'UTF-8'); $pdf->setPaper('A4','portrait'); $pdf->render();
                $result=['pdf'=>base64_encode($pdf->output()),'pages'=>$pdf->getCanvas()->get_page_count()];
            }
            return $this->response->setJSON($result+['csrf'=>csrf_hash()]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(422)->setJSON(['error'=>$e->getMessage(),'csrf'=>csrf_hash()]);
        }
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
