<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\BillingDocumentService;
use App\Services\BillingDocumentSettingsService;
use App\Services\BillingFeatureService;
use App\Services\BillingTsModuleStatusService;
use App\Services\TenantContextService;

class BillingSettingsController extends BaseController
{
    private TenantContextService $tenantContext;
    private BillingFeatureService $featureService;
    private BillingTsModuleStatusService $moduleStatus;
    private BillingDocumentSettingsService $settingsService;
    private BillingDocumentService $documents;

    public function __construct()
    {
        helper('portal');
        $this->tenantContext = new TenantContextService();
        $this->featureService = new BillingFeatureService();
        $this->moduleStatus = new BillingTsModuleStatusService();
        $this->settingsService = new BillingDocumentSettingsService();
        $this->documents = new BillingDocumentService();
    }

    public function index()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        if (!portal_current_path_matches('login/spazio/fatturazione')) {
            return redirect()->to(portal_tenant_space_url('fatturazione'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        $billingSettings = $this->settingsService->resolveTenantSettings($context->tenantId);
        $tsConfig = config(\App\Config\TsBilling::class);

        return view('tenant/billing_settings', [
            'tenantContext' => $context,
            'moduleStatus' => $this->moduleStatus->describe($context, $context->tenantId),
            'billingSettings' => $billingSettings,
            'documentTypes' => $this->documents->documentTypeLabels(),
            'paymentMethods' => $this->documents->paymentMethodLabels(),
            'supportedExpenseTypes' => $tsConfig->supportedExpenseTypes,
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function save()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        try {
            $currentSettings = $this->settingsService->resolveTenantSettings($context->tenantId);
            $this->settingsService->saveTenantSettings(
                $context->tenantId,
                $this->requestConfigPayload((array) ($currentSettings['config'] ?? [])),
                (int) (session()->get('platform_user_id') ?? 0)
            );

            return redirect()
                ->to(portal_tenant_space_url('fatturazione'))
                ->with('success', 'Configurazione Fatturazione e preferenze predefinite salvate.');
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\BillingSettingsController::save failed: ' . $e->getMessage());

            return redirect()
                ->to(portal_tenant_space_url('fatturazione'))
                ->withInput()
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function requestConfigPayload(array $current): array
    {
        $branding = is_array($current['branding'] ?? null) ? $current['branding'] : [];
        $layout = is_array($current['layout'] ?? null) ? $current['layout'] : [];
        $fields = is_array($current['fields'] ?? null) ? $current['fields'] : [];
        $labels = is_array($current['labels'] ?? null) ? $current['labels'] : [];
        $integrationTs = is_array($current['integration_ts'] ?? null) ? $current['integration_ts'] : [];
        $defaults = is_array($current['defaults'] ?? null) ? $current['defaults'] : [];
        $vat = is_array($current['vat'] ?? null) ? $current['vat'] : [];
        $pensionFund = is_array($current['pension_fund'] ?? null) ? $current['pension_fund'] : [];
        $fiscalData = is_array($current['fiscal_data'] ?? null) ? $current['fiscal_data'] : [];
        $emailDelivery = is_array($current['email_delivery'] ?? null) ? $current['email_delivery'] : [];

        return [
            'document_title' => $this->requestString('document_title', (string) ($current['document_title'] ?? 'Documento fatturazione'), 120),
            'document_code_prefix' => $this->requestString('document_code_prefix', (string) ($current['document_code_prefix'] ?? 'FT'), 12),
            'defaults' => [
                'document_type' => $this->requestString('default_document_type', (string) ($defaults['document_type'] ?? 'invoice'), 20),
                'payment_method' => $this->requestString('default_payment_method', (string) ($defaults['payment_method'] ?? 'bank_transfer'), 30),
                'ts_expense_type_code' => (string) ($defaults['ts_expense_type_code'] ?? 'SP'),
                'ts_opposition_flag' => !empty($defaults['ts_opposition_flag']),
            ],
            // Queste preferenze restano nella schermata Documento fatturazione.
            'branding' => $branding,
            'layout' => $layout,
            'fields' => $fields,
            'labels' => $labels,
            // Sistema TS è configurato nella voce dedicata e non viene alterato qui.
            'integration_ts' => $integrationTs,
            'service_catalog' => $this->requestServiceCatalog(),
            'vat' => [
                'default_rate' => $this->requestString('default_vat_rate', (string) ($vat['default_rate'] ?? '0.00'), 10),
                'default_nature' => $this->requestString('default_vat_nature', (string) ($vat['default_nature'] ?? ''), 16),
            ],
            'pension_fund' => [
                'enabled' => $this->requestBool('pension_fund_enabled'),
                'name' => $this->requestString('pension_fund_name', (string) ($pensionFund['name'] ?? ''), 120),
                'registration_number' => $this->requestString('pension_fund_registration_number', (string) ($pensionFund['registration_number'] ?? ''), 80),
                'contribution_rate' => $this->requestString('pension_fund_contribution_rate', (string) ($pensionFund['contribution_rate'] ?? '0.00'), 10),
            ],
            'fiscal_data' => [
                'business_name' => $this->requestString('fiscal_business_name', (string) ($fiscalData['business_name'] ?? ''), 140),
                'tax_code' => $this->requestString('fiscal_tax_code', (string) ($fiscalData['tax_code'] ?? ''), 32),
                'vat_number' => $this->requestString('fiscal_vat_number', (string) ($fiscalData['vat_number'] ?? ''), 32),
                'address' => $this->requestString('fiscal_address', (string) ($fiscalData['address'] ?? ''), 160),
                'postal_code' => $this->requestString('fiscal_postal_code', (string) ($fiscalData['postal_code'] ?? ''), 12),
                'city' => $this->requestString('fiscal_city', (string) ($fiscalData['city'] ?? ''), 100),
                'province' => $this->requestString('fiscal_province', (string) ($fiscalData['province'] ?? ''), 4),
                'pec' => $this->requestString('fiscal_pec', (string) ($fiscalData['pec'] ?? ''), 190),
                'recipient_code' => $this->requestString('fiscal_recipient_code', (string) ($fiscalData['recipient_code'] ?? ''), 16),
            ],
            'email_delivery' => [
                'default_due_days' => $this->requestString(
                    'email_default_due_days',
                    (string) ($emailDelivery['default_due_days'] ?? '30'),
                    3
                ),
                'attach_pdf' => $this->requestBool('email_attach_pdf'),
                'invoice_subject' => $this->requestString(
                    'email_invoice_subject',
                    (string) ($emailDelivery['invoice_subject'] ?? ''),
                    255
                ),
                'invoice_body' => $this->requestString(
                    'email_invoice_body',
                    (string) ($emailDelivery['invoice_body'] ?? ''),
                    5000
                ),
                'reminder_subject' => $this->requestString(
                    'email_reminder_subject',
                    (string) ($emailDelivery['reminder_subject'] ?? ''),
                    255
                ),
                'reminder_body' => $this->requestString(
                    'email_reminder_body',
                    (string) ($emailDelivery['reminder_body'] ?? ''),
                    5000
                ),
            ],
        ];
    }

    /**
     * @return list<array{description: string, unit_amount: string}>
     */
    private function requestServiceCatalog(): array
    {
        $descriptions = $this->request->getPost('service_description');
        $amounts = $this->request->getPost('service_amount');
        $descriptions = is_array($descriptions) ? $descriptions : [];
        $amounts = is_array($amounts) ? $amounts : [];
        $catalog = [];

        foreach ($descriptions as $index => $description) {
            $description = trim((string) $description);
            if ($description === '') {
                continue;
            }

            $catalog[] = [
                'description' => substr($description, 0, 190),
                'unit_amount' => substr(trim((string) ($amounts[$index] ?? '0')), 0, 16),
            ];

            if (count($catalog) >= 30) {
                break;
            }
        }

        return $catalog;
    }

    private function requestBool(string $key): bool
    {
        return in_array(
            strtolower(trim((string) ($this->request->getPost($key) ?? ''))),
            ['1', 'true', 'on', 'yes'],
            true
        );
    }

    private function requestString(string $key, string $fallback, int $maxLength): string
    {
        $value = trim((string) ($this->request->getPost($key) ?? $fallback));
        return $maxLength > 0 ? substr($value, 0, $maxLength) : $value;
    }

    private function ensureAllowed()
    {
        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return $this->redirectToLogin();
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return $this->sessionExpiredRedirect();
        }

        if ($context->tenantRole !== 'tenant_master') {
            return redirect()->to(site_url('/'))->with('error', 'Solo il responsabile dello studio può aprire il modulo Fatturazione.');
        }

        if ((int) (session()->get('platform_user_id') ?? 0) <= 0) {
            return $this->sessionExpiredRedirect();
        }

        if (!$this->featureService->isEnabledForContext($context)) {
            return redirect()->to(portal_tenant_space_url('funzioni'))
                ->with('error', 'La Fatturazione non è attiva per questo spazio cliente. Deve essere abilitata dal master piattaforma nella scheda dello spazio.');
        }

        return null;
    }
}
