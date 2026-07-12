<?php

namespace App\Controllers\Admin;

use App\Services\BillingDocumentService;
use App\Services\BillingTsBridgeService;
use App\Services\TenantPatientLookupService;
use Dompdf\Dompdf;
use Dompdf\Options;

class BillingDocumentsController extends BillingAdminBaseController
{
    private BillingDocumentService $documents;
    private BillingTsBridgeService $billingTsBridge;
    private TenantPatientLookupService $patientLookup;

    public function __construct()
    {
        parent::__construct();
        $this->documents = new BillingDocumentService();
        $this->billingTsBridge = new BillingTsBridgeService();
        $this->patientLookup = new TenantPatientLookupService();
    }

    public function index()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $listing = $this->documents->listDocumentsForTenant($tenantId);
        $documentIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id_billing_document'] ?? 0),
            is_array($listing['documents'] ?? null) ? $listing['documents'] : []
        )));
        $actionMap = $this->billingTsBridge->buildBillingDocumentActionMap($tenantId, $documentIds);

        if (!empty($listing['documents']) && is_array($listing['documents'])) {
            foreach ($listing['documents'] as &$row) {
                $documentId = (int) ($row['id_billing_document'] ?? 0);
                $actionState = $actionMap[$documentId] ?? [];
                if ($actionState !== []) {
                    $row = array_merge($row, $actionState);
                }
            }
            unset($row);
        }

        return view('admin/billing/documents', [
            'menu_items' => $this->adminMenuItems(),
            'tenantScope' => $tenantScope,
            'listing' => $listing,
            'success' => session()->getFlashdata('success'),
            'warning' => session()->getFlashdata('warning'),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function create()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $formContext = $this->documents->buildFormContext($tenantId);
        $appointmentPrefill = $this->consumeAppointmentPrefill();
        if ($appointmentPrefill !== []) {
            $formContext = $this->applyAppointmentPrefill($formContext, $appointmentPrefill);
        }
        $formContext = $this->populatePatientDocumentFields($tenantId, $formContext);
        $formContext['edit_locked'] = false;
        $formContext['edit_lock_reason'] = '';
        $formContext['action_state'] = $this->billingTsBridge->describeBillingDocumentAction($tenantId, 0);

        return view('admin/billing/document_form', [
            'menu_items' => $this->adminMenuItems(),
            'tenantScope' => $tenantScope,
            'formContext' => $formContext,
            'pageTitle' => trim((string) ($appointmentPrefill['page_title'] ?? '')) !== ''
                ? trim((string) ($appointmentPrefill['page_title'] ?? ''))
                : 'Nuovo documento fatturazione',
            'success' => session()->getFlashdata('success'),
            'warning' => session()->getFlashdata('warning'),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function edit(int $documentId = 0)
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $documentId = max(0, $documentId);
        if ($documentId <= 0) {
            return redirect()->to(site_url('admin/fatturazione-documenti'))->with('errors', [
                'generic' => 'Documento fatturazione non valido.',
            ]);
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $formContext = $this->documents->buildFormContext($tenantId, $documentId);
        if ((int) ($formContext['document']['id_billing_document'] ?? 0) <= 0) {
            return redirect()->to(site_url('admin/fatturazione-documenti'))->with('errors', [
                'generic' => 'Documento fatturazione non trovato.',
            ]);
        }

        $actionState = $this->billingTsBridge->describeBillingDocumentAction($tenantId, $documentId);
        $formContext = $this->populatePatientDocumentFields($tenantId, $formContext);
        $formContext['edit_locked'] = !empty($actionState['locked']);
        $formContext['edit_lock_reason'] = trim((string) ($actionState['locked_reason'] ?? ''));
        $formContext['action_state'] = $actionState;

        return view('admin/billing/document_form', [
            'menu_items' => $this->adminMenuItems(),
            'tenantScope' => $tenantScope,
            'formContext' => $formContext,
            'pageTitle' => 'Modifica documento fatturazione',
            'success' => session()->getFlashdata('success'),
            'warning' => session()->getFlashdata('warning'),
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
        $documentId = (int) ($this->request->getPost('id_billing_document') ?? 0);
        $editUrl = $documentId > 0
            ? site_url('admin/fatturazione-documenti/modifica/' . $documentId)
            : site_url('admin/fatturazione-documenti/nuovo');
        $redirectUrl = $editUrl;
        $listUrl = site_url('admin/fatturazione-documenti');
        $tsListUrl = site_url('admin/sistema-ts/documenti');

        try {
            if ($documentId > 0) {
                $actionState = $this->billingTsBridge->describeBillingDocumentAction($tenantId, $documentId);
                if (empty($actionState['can_edit'])) {
                    return redirect()->to($editUrl)->with(
                        'warning',
                        trim((string) ($actionState['locked_reason'] ?? 'Questa fattura non puo piu essere modificata.'))
                    );
                }
            }

            $saveMode = trim((string) ($this->request->getPost('save_mode') ?? 'draft'));
            $sendToTsNow = $saveMode === 'final_send_ts';
            $normalizedSaveMode = $sendToTsNow ? 'final' : $saveMode;
            $tsSyncEnabled = $sendToTsNow ? '1' : $this->request->getPost('ts_sync_enabled');
            $result = $this->documents->saveDraftForTenant(
                $tenantId,
                [
                    'id_billing_document' => $documentId,
                    'id_client' => $this->request->getPost('id_client'),
                    'document_number' => $this->request->getPost('document_number'),
                    'document_type' => $this->request->getPost('document_type'),
                    'issue_date' => $this->request->getPost('issue_date'),
                    'payment_date' => $this->request->getPost('payment_date'),
                    'patient_name' => $this->request->getPost('patient_name'),
                    'patient_last_name' => $this->request->getPost('patient_last_name'),
                    'patient_first_name' => $this->request->getPost('patient_first_name'),
                    'patient_tax_code' => $this->request->getPost('patient_tax_code'),
                    'patient_phone' => $this->request->getPost('patient_phone'),
                    'patient_mobile' => $this->request->getPost('patient_mobile'),
                    'patient_email' => $this->request->getPost('patient_email'),
                    'patient_address' => $this->request->getPost('patient_address'),
                    'patient_city' => $this->request->getPost('patient_city'),
                    'payment_method' => $this->request->getPost('payment_method'),
                    'item_description' => $this->request->getPost('item_description'),
                    'item_qty' => $this->request->getPost('item_qty'),
                    'item_unit_amount' => $this->request->getPost('item_unit_amount'),
                    'stamp_duty_amount' => $this->request->getPost('stamp_duty_amount'),
                    'vat_rate' => $this->request->getPost('vat_rate'),
                    'vat_nature' => $this->request->getPost('vat_nature'),
                    'notes' => $this->request->getPost('notes'),
                    'ts_sync_enabled' => $tsSyncEnabled,
                    'ts_expense_type_code' => $this->request->getPost('ts_expense_type_code'),
                    'ts_opposition_flag' => $this->request->getPost('ts_opposition_flag'),
                ],
                $this->currentAdminUserId(),
                $normalizedSaveMode
            );

            $savedId = (int) ($result['document']['id_billing_document'] ?? 0);
            $targetUrl = $savedId > 0
                ? site_url('admin/fatturazione-documenti/modifica/' . $savedId)
                : $redirectUrl;

            if ($sendToTsNow && $savedId > 0) {
                try {
                    $dispatchResult = $this->billingTsBridge->sendBillingDocument($tenantId, $savedId, $this->currentAdminUserId());
                    $status = trim((string) ($dispatchResult['status'] ?? 'ok'));
                    if ($status === 'ok' || $status === 'sent') {
                        return redirect()->to($tsListUrl)->with(
                            'success',
                            'Fattura salvata e inviata a TS correttamente. La trovi ora nella lista documenti TS e puoi scaricare la ricevuta.'
                        );
                    }

                    return redirect()->to($targetUrl)
                        ->with('success', 'Fattura salvata correttamente.')
                        ->with('warning', trim((string) ($dispatchResult['message'] ?? 'La fattura e stata salvata ma non e pronta per l invio TS.')));
                } catch (\Throwable $dispatchError) {
                    log_message('error', 'Admin\\BillingDocumentsController::save dispatch failed: ' . $dispatchError->getMessage());

                    return redirect()->to($targetUrl)
                        ->with('success', 'Fattura salvata correttamente.')
                        ->with('warning', 'Invio immediato a TS non riuscito: ' . $dispatchError->getMessage());
                }
            }

            $successMessage = $normalizedSaveMode === 'final'
                ? 'Fattura salvata correttamente. Puoi scaricare il PDF dalla lista fatture.'
                : 'Bozza documento fatturazione salvata correttamente.';

            if ($normalizedSaveMode === 'final' && (int) ($result['document']['ts_sync_enabled'] ?? 0) === 1) {
                $successMessage .= ' La fattura comparira nel modulo Sistema TS tra quelle da inviare.';
            }

            return redirect()->to($normalizedSaveMode === 'final' ? $listUrl : $targetUrl)->with('success', $successMessage);
        } catch (\Throwable $e) {
            log_message('error', 'Admin\\BillingDocumentsController::save failed: ' . $e->getMessage());

            return redirect()->to($redirectUrl)->withInput()->with('errors', [
                'generic' => $e->getMessage(),
            ]);
        }
    }

    public function searchPatients()
    {
        if ($this->ensureAccess() !== null) {
            return $this->response->setStatusCode(403)->setJSON([
                'ok' => false,
                'results' => [],
                'error' => 'Accesso non autorizzato.',
            ]);
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $term = trim((string) ($this->request->getGet('term') ?? ''));

        if (mb_strlen($term) < 2) {
            return $this->response->setJSON([
                'ok' => true,
                'results' => [],
            ]);
        }

        try {
            return $this->response->setJSON([
                'ok' => true,
                'results' => $this->patientLookup->searchPatientsForTenant($tenantId, $term, 12),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Admin\\BillingDocumentsController::searchPatients failed: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'term' => $term,
            ]);

            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'results' => [],
                'error' => 'Ricerca pazienti non disponibile al momento.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function consumeAppointmentPrefill(): array
    {
        $prefillKey = trim((string) ($this->request->getGet('prefill_key') ?? ''));
        if ($prefillKey !== '') {
            $sessionPrefill = $this->loadSessionAppointmentPrefill($prefillKey, 'billing');
            if ($sessionPrefill !== []) {
                return $sessionPrefill;
            }
        }

        $prefill = session()->getFlashdata('billing_document_prefill');

        return is_array($prefill) ? $prefill : [];
    }

    /**
     * @param array<string, mixed> $formContext
     * @param array<string, mixed> $prefill
     * @return array<string, mixed>
     */
    private function applyAppointmentPrefill(array $formContext, array $prefill): array
    {
        $document = is_array($formContext['document'] ?? null) ? $formContext['document'] : [];
        $prefillDocument = is_array($prefill['document'] ?? null) ? $prefill['document'] : [];
        $prefillLineItems = is_array($prefill['line_items'] ?? null) ? $prefill['line_items'] : [];
        $sourceContext = is_array($prefill['source_context'] ?? null) ? $prefill['source_context'] : [];

        if ($prefillDocument !== []) {
            $formContext['document'] = array_merge($document, $prefillDocument);
        }

        if ($prefillLineItems !== []) {
            $formContext['line_items'] = $prefillLineItems;
        }

        if ($sourceContext !== []) {
            $formContext['source_context'] = $sourceContext;
        }

        return $formContext;
    }

    /**
     * @param array<string, mixed> $formContext
     * @return array<string, mixed>
     */
    private function populatePatientDocumentFields(int $tenantId, array $formContext): array
    {
        $document = is_array($formContext['document'] ?? null) ? $formContext['document'] : [];
        $sourceContext = is_array($formContext['source_context'] ?? null) ? $formContext['source_context'] : [];
        $linkedPatient = [];
        $idClient = max(0, (int) ($document['id_client'] ?? 0));

        if ($idClient > 0) {
            try {
                $linkedPatient = $this->patientLookup->getPatientByIdForTenant($tenantId, $idClient);
            } catch (\Throwable $e) {
                log_message('warning', 'Admin\\BillingDocumentsController::populatePatientDocumentFields lookup failed: ' . $e->getMessage(), [
                    'tenant_id' => $tenantId,
                    'id_client' => $idClient,
                ]);
            }
        }

        [$parsedLastName, $parsedFirstName] = $this->splitPatientName((string) ($document['patient_name'] ?? ''));

        $document['patient_last_name'] = $this->firstNonEmptyValue([
            $document['patient_last_name'] ?? '',
            $linkedPatient['patient_last_name'] ?? '',
            $sourceContext['patient_last_name'] ?? '',
            $parsedLastName,
        ]);
        $document['patient_first_name'] = $this->firstNonEmptyValue([
            $document['patient_first_name'] ?? '',
            $linkedPatient['patient_first_name'] ?? '',
            $sourceContext['patient_first_name'] ?? '',
            $parsedFirstName,
        ]);
        $document['patient_tax_code'] = $this->firstNonEmptyValue([
            $document['patient_tax_code'] ?? '',
            $linkedPatient['patient_tax_code'] ?? '',
            $sourceContext['patient_tax_code'] ?? '',
        ]);
        $document['patient_phone'] = $this->firstNonEmptyValue([
            $document['patient_phone'] ?? '',
            $linkedPatient['patient_phone'] ?? '',
            $sourceContext['patient_phone'] ?? '',
        ]);
        $document['patient_mobile'] = $this->firstNonEmptyValue([
            $document['patient_mobile'] ?? '',
            $linkedPatient['patient_mobile'] ?? '',
            $sourceContext['patient_mobile'] ?? '',
        ]);
        $document['patient_email'] = $this->firstNonEmptyValue([
            $document['patient_email'] ?? '',
            $linkedPatient['patient_email'] ?? '',
            $sourceContext['patient_email'] ?? '',
        ]);
        $document['patient_address'] = $this->firstNonEmptyValue([
            $document['patient_address'] ?? '',
            $linkedPatient['patient_address'] ?? '',
            $sourceContext['patient_address'] ?? '',
        ]);
        $document['patient_city'] = $this->firstNonEmptyValue([
            $document['patient_city'] ?? '',
            $linkedPatient['patient_city'] ?? '',
            $sourceContext['patient_city'] ?? '',
        ]);

        $composedPatientName = trim((string) (preg_replace(
            '/\s+/',
            ' ',
            ((string) ($document['patient_last_name'] ?? '')) . ' ' . ((string) ($document['patient_first_name'] ?? ''))
        ) ?? ''));
        if ($composedPatientName !== '') {
            $document['patient_name'] = $composedPatientName;
        }

        $formContext['document'] = $document;
        $formContext['linked_patient'] = $linkedPatient;

        return $formContext;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstNonEmptyValue(array $values): string
    {
        foreach ($values as $value) {
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSessionAppointmentPrefill(string $prefillKey, string $workflow): array
    {
        $store = session()->get('appointment_document_prefills');
        $store = is_array($store) ? $store : [];
        if ($store === []) {
            return [];
        }

        $updatedStore = $this->cleanupStoredAppointmentDocumentPrefills($store);
        if ($updatedStore !== $store) {
            session()->set('appointment_document_prefills', $updatedStore);
        }

        $entry = $updatedStore[$prefillKey] ?? null;
        if (!is_array($entry)) {
            return [];
        }

        if (trim((string) ($entry['workflow'] ?? '')) !== $workflow) {
            return [];
        }

        $payload = $entry['payload'] ?? [];

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    private function cleanupStoredAppointmentDocumentPrefills(array $store): array
    {
        if ($store === []) {
            return [];
        }

        $now = time();
        foreach ($store as $key => $entry) {
            $createdAt = (int) ($entry['created_at'] ?? 0);
            if ($createdAt > 0 && ($now - $createdAt) <= 3600) {
                continue;
            }

            unset($store[$key]);
        }

        return $store;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitPatientName(string $patientName): array
    {
        $patientName = trim((string) (preg_replace('/\s+/', ' ', $patientName) ?? ''));
        if ($patientName === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $patientName, 2);
        if (!is_array($parts)) {
            return [$patientName, ''];
        }

        return [
            trim((string) ($parts[0] ?? '')),
            trim((string) ($parts[1] ?? '')),
        ];
    }

    public function delete(int $documentId = 0)
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $documentId = max(0, $documentId);
        $targetUrl = site_url('admin/fatturazione-documenti');
        if ($documentId <= 0) {
            return redirect()->to($targetUrl)->with('errors', [
                'generic' => 'Fattura non valida per la cancellazione.',
            ]);
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);

        try {
            $result = $this->billingTsBridge->deleteBillingDocument($tenantId, $documentId, $this->currentAdminUserId());
            $documentNumber = trim((string) ($result['document_number'] ?? ''));
            $deletedTsDocuments = is_array($result['deleted_ts_document_ids'] ?? null) ? $result['deleted_ts_document_ids'] : [];

            $message = $documentNumber !== ''
                ? 'Fattura ' . $documentNumber . ' eliminata correttamente.'
                : 'Fattura eliminata correttamente.';

            if ($deletedTsDocuments !== []) {
                $message .= ' Rimossa anche la bozza TS collegata.';
            }

            return redirect()->to($targetUrl)->with('success', $message);
        } catch (\Throwable $e) {
            log_message('error', 'Admin\\BillingDocumentsController::delete failed: ' . $e->getMessage());

            $flashKey = $e instanceof \RuntimeException ? 'warning' : 'errors';
            if ($flashKey === 'warning') {
                return redirect()->to($targetUrl)->with('warning', $e->getMessage());
            }

            return redirect()->to($targetUrl)->with('errors', [
                'generic' => $e->getMessage(),
            ]);
        }
    }

    public function preview(int $documentId = 0)
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $documentId = max(0, $documentId);
        if ($documentId <= 0) {
            return redirect()->to(site_url('admin/fatturazione-documenti'))->with('errors', [
                'generic' => 'Documento fatturazione non valido.',
            ]);
        }

        try {
            return view('admin/billing/document_preview', [
                'tenantScope' => $tenantScope,
                'preview' => $this->documents->buildPreviewContext($tenantId, $documentId),
            ]);
        } catch (\Throwable $e) {
            return redirect()->to(site_url('admin/fatturazione-documenti'))->with('errors', [
                'generic' => $e->getMessage(),
            ]);
        }
    }

    public function downloadPdf(int $documentId = 0)
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        if (!class_exists(Dompdf::class)) {
            return redirect()->to(site_url('admin/fatturazione-documenti'))->with('errors', [
                'generic' => 'Dompdf non disponibile: impossibile generare il PDF.',
            ]);
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $documentId = max(0, $documentId);
        if ($documentId <= 0) {
            return redirect()->to(site_url('admin/fatturazione-documenti'))->with('errors', [
                'generic' => 'Documento fatturazione non valido.',
            ]);
        }

        try {
            $preview = $this->documents->buildPreviewContext($tenantId, $documentId);
            $html = view('admin/billing/document_pdf', [
                'preview' => $preview,
            ]);

            $options = new Options();
            $options->set('isRemoteEnabled', true);

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $this->documents->markPdfGenerated($tenantId, $documentId);

            $filename = 'documento_fatturazione_' . (int) ($preview['document']['id_billing_document'] ?? 0) . '.pdf';

            return $this->response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
                ->setBody($dompdf->output());
        } catch (\Throwable $e) {
            log_message('error', 'Admin\\BillingDocumentsController::downloadPdf failed: ' . $e->getMessage());

            return redirect()->to(site_url('admin/fatturazione-documenti'))->with('errors', [
                'generic' => $e->getMessage(),
            ]);
        }
    }
}
