<?php

namespace App\Controllers\Admin;

use App\Services\BillingTsBridgeService;
use App\Services\TenantPatientLookupService;
use App\Services\TsDispatchService;
use App\Services\TsDocumentService;
use App\Services\TsReceiptService;
use App\Services\TsTenantDatabaseContextService;

class TsDocumentsController extends TsAdminBaseController
{
    private TsDispatchService $dispatch;
    private BillingTsBridgeService $billingTsBridge;
    private TsDocumentService $documents;
    private TsReceiptService $receipts;
    private TsTenantDatabaseContextService $tenantDbContext;
    private TenantPatientLookupService $patientLookup;

    public function __construct()
    {
        parent::__construct();
        $this->dispatch = new TsDispatchService();
        $this->billingTsBridge = new BillingTsBridgeService();
        $this->documents = new TsDocumentService();
        $this->receipts = new TsReceiptService();
        $this->tenantDbContext = new TsTenantDatabaseContextService();
        $this->patientLookup = new TenantPatientLookupService();
    }

    public function index()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);

        return view('admin/ts/documents', [
            'menu_items' => $this->adminMenuItems(),
            'tenantScope' => $tenantScope,
            'listing' => $this->documents->listDocumentsForTenant($tenantId),
            'billingQueue' => $this->billingTsBridge->buildQueueForTenant($tenantId),
            'success' => session()->getFlashdata('success'),
            'warning' => session()->getFlashdata('warning'),
            'error' => session()->getFlashdata('error'),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function sendBillingBulk()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $billingDocumentIds = (array) $this->request->getPost('billing_document_ids');
        $targetUrl = site_url('admin/sistema-ts/documenti');

        try {
            $report = $this->billingTsBridge->sendBillingDocumentsBulk($tenantId, $billingDocumentIds, $this->currentAdminUserId());
            $sentCount = (int) ($report['sent_count'] ?? 0);
            $blockedCount = (int) ($report['blocked_count'] ?? 0);
            $errorCount = (int) ($report['error_count'] ?? 0);
            $results = is_array($report['results'] ?? null) ? $report['results'] : [];

            $summaryParts = [];
            if ($sentCount > 0) {
                $summaryParts[] = $sentCount . ' fatture inviate a TS';
            }
            if ($blockedCount > 0) {
                $summaryParts[] = $blockedCount . ' da correggere prima dell invio';
            }
            if ($errorCount > 0) {
                $summaryParts[] = $errorCount . ' con errore tecnico';
            }

            $summary = $summaryParts !== []
                ? ucfirst(implode(', ', $summaryParts)) . '.'
                : 'Nessuna fattura elaborata.';

            $detailMessages = [];
            foreach ($results as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $status = trim((string) ($row['status'] ?? ''));
                if (in_array($status, ['ok', 'sent'], true)) {
                    continue;
                }

                $detailMessages[] = 'Fattura #' . (int) ($row['billing_document_id'] ?? 0) . ': ' . trim((string) ($row['message'] ?? ''));
                if (count($detailMessages) >= 3) {
                    break;
                }
            }

            if ($sentCount > 0 && $blockedCount === 0 && $errorCount === 0) {
                return redirect()->to($targetUrl)->with('success', $summary);
            }

            if ($sentCount > 0) {
                return redirect()->to($targetUrl)
                    ->with('success', $summary)
                    ->with('warning', implode(' ', $detailMessages));
            }

            return redirect()->to($targetUrl)->with('errors', [
                'generic' => trim($summary . ' ' . implode(' ', $detailMessages)),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Admin\\TsDocumentsController::sendBillingBulk failed: ' . $e->getMessage());

            return redirect()->to($targetUrl)->with('errors', [
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
            log_message('error', 'Admin\\TsDocumentsController::searchPatients failed: ' . $e->getMessage(), [
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

    public function create()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $formContext = $this->documents->buildFormContext((int) ($tenantScope['tenant_id'] ?? 0));
        $appointmentPrefill = $this->consumeAppointmentPrefill();
        if ($appointmentPrefill !== []) {
            $formContext = $this->applyAppointmentPrefill($formContext, $appointmentPrefill);
        }

        return view('admin/ts/document_form', [
            'menu_items' => $this->adminMenuItems(),
            'tenantScope' => $tenantScope,
            'formContext' => $formContext,
            'pageTitle' => trim((string) ($appointmentPrefill['page_title'] ?? '')) !== ''
                ? trim((string) ($appointmentPrefill['page_title'] ?? ''))
                : 'Nuovo documento TS',
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
            return redirect()->to(site_url('admin/sistema-ts/documenti'))->with('error', 'Documento TS non valido.');
        }

        $tenantScope = $this->resolveTenantScope();
        $formContext = $this->documents->buildFormContext((int) ($tenantScope['tenant_id'] ?? 0), $documentId);

        if ((int) ($formContext['document']['id_ts_document'] ?? 0) <= 0) {
            return redirect()->to(site_url('admin/sistema-ts/documenti'))->with('error', 'Documento TS non trovato.');
        }

        return view('admin/ts/document_form', [
            'menu_items' => $this->adminMenuItems(),
            'tenantScope' => $tenantScope,
            'formContext' => $formContext,
            'pageTitle' => 'Modifica documento TS',
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
        $documentId = (int) ($this->request->getPost('id_ts_document') ?? 0);
        $redirectUrl = $documentId > 0
            ? site_url('admin/sistema-ts/documenti/modifica/' . $documentId)
            : site_url('admin/sistema-ts/documenti/nuovo');

        try {
            $saveMode = trim((string) ($this->request->getPost('save_mode') ?? 'draft'));
            $result = $this->documents->saveDraftForTenant(
                $tenantId,
                [
                    'id_ts_document' => $documentId,
                    'id_client' => $this->request->getPost('id_client'),
                    'patient_cf_plain' => $this->request->getPost('patient_cf_plain'),
                    'patient_label_plain' => $this->request->getPost('patient_label_plain'),
                    'document_number' => $this->request->getPost('document_number'),
                    'document_device' => $this->request->getPost('document_device'),
                    'issue_date' => $this->request->getPost('issue_date'),
                    'payment_date' => $this->request->getPost('payment_date'),
                    'document_type' => $this->request->getPost('document_type'),
                    'expense_type_code' => $this->request->getPost('expense_type_code'),
                    'payment_mode' => $this->request->getPost('payment_mode'),
                    'amount_total' => $this->request->getPost('amount_total'),
                    'vat_rate' => $this->request->getPost('vat_rate'),
                    'vat_nature' => $this->request->getPost('vat_nature'),
                    'opposition_flag' => $this->request->getPost('opposition_flag'),
                    'notes' => $this->request->getPost('notes'),
                ],
                $this->currentAdminUserId(),
                $saveMode
            );

            if (!empty($result['blocked'])) {
                $validationResult = is_array($result['validation'] ?? null) ? $result['validation'] : [];
                $validationErrors = is_array($validationResult['errors'] ?? null) ? $validationResult['errors'] : [];
                $validationResult['errors'] = array_values(array_filter($validationErrors, static function ($message): bool {
                    return stripos(trim((string) $message), 'Esiste gia un documento TS con lo stesso identificativo logico') === false;
                }));

                return redirect()
                    ->to($redirectUrl)
                    ->withInput()
                    ->with('errors', [
                        'generic' => trim((string) ($result['message'] ?? 'Salvataggio documento TS bloccato.')),
                    ])
                    ->with('validation_result', $validationResult);
            }

            $savedDocumentId = (int) ($result['document']['id_ts_document'] ?? 0);
            $targetUrl = $savedDocumentId > 0
                ? site_url('admin/sistema-ts/documenti/modifica/' . $savedDocumentId)
                : $redirectUrl;

            if ($saveMode === 'send') {
                $validationResult = is_array($result['validation'] ?? null) ? $result['validation'] : [];
                if (empty($validationResult['valid'])) {
                    return redirect()
                        ->to($targetUrl)
                        ->with('validation_result', $validationResult)
                        ->with('errors', [
                            'generic' => 'Documento TS salvato ma non inviato: correggi gli errori indicati e riprova.',
                        ]);
                }

                $dispatchResult = $this->dispatch->dispatchDocument($tenantId, $savedDocumentId, $this->currentAdminUserId());
                $dispatchTargetUrl = $this->buildDocumentTargetUrl(
                    (int) ($dispatchResult['document']['id_ts_document'] ?? 0),
                    $targetUrl
                );

                return $this->buildDispatchRedirect($dispatchTargetUrl, $dispatchResult);
            }

            $message = $saveMode === 'validate'
                ? (!empty($result['validation']['valid'])
                    ? 'Documento TS salvato e validato localmente.'
                    : 'Documento TS salvato, ma la validazione locale ha trovato errori.')
                : 'Bozza documento TS salvata correttamente.';

            $redirect = redirect()->to($targetUrl)->with('success', $message);
            if ($saveMode === 'validate') {
                $redirect = $redirect->with('validation_result', $result['validation']);
            }

            return $redirect;
        } catch (\Throwable $e) {
            log_message('error', 'Admin\\TsDocumentsController::save failed: ' . $e->getMessage());

            return redirect()
                ->to($redirectUrl)
                ->withInput()
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function send()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $documentId = (int) ($this->request->getPost('id_ts_document') ?? 0);
        $targetUrl = $documentId > 0
            ? site_url('admin/sistema-ts/documenti/modifica/' . $documentId)
            : site_url('admin/sistema-ts/documenti');

        try {
            $result = $this->dispatch->dispatchDocument($tenantId, $documentId, $this->currentAdminUserId());
            $dispatchTargetUrl = $this->buildDocumentTargetUrl(
                (int) ($result['document']['id_ts_document'] ?? 0),
                $targetUrl
            );

            return $this->buildDispatchRedirect($dispatchTargetUrl, $result);
        } catch (\Throwable $e) {
            log_message('error', 'Admin\\TsDocumentsController::send failed: ' . $e->getMessage());

            return redirect()
                ->to($targetUrl)
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function createVariation(int $documentId = 0)
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $documentId = max(0, $documentId);
        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $fallbackUrl = $documentId > 0
            ? site_url('admin/sistema-ts/documenti/modifica/' . $documentId)
            : site_url('admin/sistema-ts/documenti');

        try {
            $result = $this->documents->createVariationDraftFromDocument($tenantId, $documentId, $this->currentAdminUserId());
            $createdId = (int) ($result['document']['id_ts_document'] ?? 0);
            $targetUrl = $this->buildDocumentTargetUrl($createdId, $fallbackUrl);

            return redirect()
                ->to($targetUrl)
                ->with('success', 'Bozza di variazione TS pronta: aggiorna i dati e poi usa Salva e invia.');
        } catch (\Throwable $e) {
            log_message('error', 'Admin\\TsDocumentsController::createVariation failed: ' . $e->getMessage());

            return redirect()
                ->to($fallbackUrl)
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function createCancellation(int $documentId = 0)
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $documentId = max(0, $documentId);
        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $fallbackUrl = $documentId > 0
            ? site_url('admin/sistema-ts/documenti/modifica/' . $documentId)
            : site_url('admin/sistema-ts/documenti');

        try {
            $prepared = $this->documents->createCancellationOperationFromDocument($tenantId, $documentId, $this->currentAdminUserId());
            $operationId = (int) ($prepared['document']['id_ts_document'] ?? 0);
            $targetUrl = $this->buildDocumentTargetUrl($operationId, $fallbackUrl);
            $dispatchResult = $this->dispatch->dispatchDocument($tenantId, $operationId, $this->currentAdminUserId());

            return $this->buildDispatchRedirect($targetUrl, $dispatchResult);
        } catch (\Throwable $e) {
            log_message('error', 'Admin\\TsDocumentsController::createCancellation failed: ' . $e->getMessage());

            return redirect()
                ->to($fallbackUrl)
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function fetchReceipt(int $documentId = 0)
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $documentId = max(0, $documentId > 0 ? $documentId : (int) ($this->request->getPost('id_ts_document') ?? 0));
        $targetUrl = $documentId > 0
            ? site_url('admin/sistema-ts/documenti/modifica/' . $documentId)
            : site_url('admin/sistema-ts/documenti');

        try {
            $result = $this->receipts->fetchReceiptPdfForDocument($tenantId, $documentId, $this->currentAdminUserId());
            $supportLog = is_array($result['support_log'] ?? null) ? $result['support_log'] : [];
            $traceId = trim((string) ($supportLog['trace_id'] ?? ''));
            $message = ($result['status'] ?? '') === 'cached'
                ? 'Ricevuta TS gia presente in archivio locale.'
                : 'Ricevuta TS recuperata e archiviata con successo.';
            if ($traceId !== '') {
                $message .= ' Rif. supporto TS: ' . $traceId . '.';
            }

            return redirect()->to($targetUrl)->with('success', $message);
        } catch (\Throwable $e) {
            log_message('error', 'Admin\\TsDocumentsController::fetchReceipt failed: ' . $e->getMessage());

            return redirect()
                ->to($targetUrl)
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function downloadReceipt(int $receiptId = 0)
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $receiptId = max(0, $receiptId);
        if ($receiptId <= 0) {
            return redirect()->to(site_url('admin/sistema-ts/documenti'))->with('error', 'Ricevuta TS non valida.');
        }

        $tenantScope = $this->resolveTenantScope();
        $context = $this->tenantDbContext->resolveTenantContext((int) ($tenantScope['tenant_id'] ?? 0));
        $model = $context['receipts'];
        $receipt = $model->find($receiptId);
        if (!is_array($receipt)) {
            return redirect()->to(site_url('admin/sistema-ts/documenti'))->with('error', 'Ricevuta TS non trovata.');
        }

        $path = trim((string) ($receipt['storage_path'] ?? ''));
        if ($path === '' || !is_file($path)) {
            return redirect()->to(site_url('admin/sistema-ts/documenti'))->with('error', 'File ricevuta TS non disponibile.');
        }

        $downloadName = 'ricevuta-ts-' . trim((string) ($receipt['ts_protocol'] ?? $receiptId)) . '.pdf';

        return $this->response->download($path, null)->setFileName($downloadName);
    }

    public function downloadLatestReceipt(int $documentId = 0)
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $documentId = max(0, $documentId > 0 ? $documentId : (int) ($this->request->getPost('id_ts_document') ?? 0));
        $targetUrl = $this->buildDocumentTargetUrl($documentId, site_url('admin/sistema-ts/documenti'));

        try {
            $result = $this->receipts->fetchReceiptPdfForDocument($tenantId, $documentId, $this->currentAdminUserId());
            $receipt = is_array($result['receipt'] ?? null) ? $result['receipt'] : null;
            if (!is_array($receipt)) {
                throw new \RuntimeException('Ricevuta TS non disponibile dopo il recupero.');
            }

            $path = trim((string) ($receipt['storage_path'] ?? ''));
            if ($path === '' || !is_file($path)) {
                throw new \RuntimeException('File ricevuta TS non disponibile.');
            }

            $downloadName = 'ricevuta-ts-' . trim((string) ($receipt['ts_protocol'] ?? $documentId)) . '.pdf';

            return $this->response->download($path, null)->setFileName($downloadName);
        } catch (\Throwable $e) {
            log_message('error', 'Admin\\TsDocumentsController::downloadLatestReceipt failed: ' . $e->getMessage());

            return redirect()
                ->to($targetUrl)
                ->with('errors', ['generic' => 'Scaricamento ricevuta TS non riuscito: ' . $e->getMessage()]);
        }
    }

    private function buildDispatchRedirect(string $targetUrl, array $result)
    {
        $redirect = redirect()->to($targetUrl);
        if (!empty($result['validation']) && is_array($result['validation'])) {
            $redirect = $redirect->with('validation_result', $result['validation']);
        }

        $supportLog = is_array($result['support_log'] ?? null) ? $result['support_log'] : [];
        $traceId = trim((string) ($supportLog['trace_id'] ?? ''));
        if (($result['status'] ?? '') === 'ok') {
            $message = trim((string) ($result['message'] ?? 'Documento TS inviato correttamente.'));
            if ($traceId !== '') {
                $message .= ' Rif. supporto TS: ' . $traceId . '.';
            }

            return $redirect->with('success', $message);
        }

        $message = trim((string) ($result['message'] ?? 'Tentativo invio TS non completato.'));
        if ($traceId !== '') {
            $message .= ' Rif. supporto TS: ' . $traceId . '.';
        }

        return $redirect->with('errors', [
            'generic' => $message,
        ]);
    }

    private function buildDocumentTargetUrl(int $documentId, string $fallback): string
    {
        return $documentId > 0
            ? site_url('admin/sistema-ts/documenti/modifica/' . $documentId)
            : $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function consumeAppointmentPrefill(): array
    {
        $prefillKey = trim((string) ($this->request->getGet('prefill_key') ?? ''));
        if ($prefillKey !== '') {
            $sessionPrefill = $this->loadSessionAppointmentPrefill($prefillKey, 'ts');
            if ($sessionPrefill !== []) {
                return $sessionPrefill;
            }
        }

        $prefill = session()->getFlashdata('ts_document_prefill');

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
        $sourceContext = is_array($prefill['source_context'] ?? null) ? $prefill['source_context'] : [];

        if ($prefillDocument !== []) {
            $formContext['document'] = array_merge($document, $prefillDocument);
        }

        if ($sourceContext !== []) {
            $formContext['source_context'] = $sourceContext;
        }

        return $formContext;
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
}
