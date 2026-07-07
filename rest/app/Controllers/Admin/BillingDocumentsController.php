<?php

namespace App\Controllers\Admin;

use App\Services\BillingDocumentService;
use App\Services\BillingTsBridgeService;
use Dompdf\Dompdf;
use Dompdf\Options;

class BillingDocumentsController extends BillingAdminBaseController
{
    private BillingDocumentService $documents;
    private BillingTsBridgeService $billingTsBridge;

    public function __construct()
    {
        parent::__construct();
        $this->documents = new BillingDocumentService();
        $this->billingTsBridge = new BillingTsBridgeService();
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
        $formContext = $this->documents->buildFormContext((int) ($tenantScope['tenant_id'] ?? 0));
        $formContext['edit_locked'] = false;
        $formContext['edit_lock_reason'] = '';
        $formContext['action_state'] = $this->billingTsBridge->describeBillingDocumentAction((int) ($tenantScope['tenant_id'] ?? 0), 0);

        return view('admin/billing/document_form', [
            'menu_items' => $this->adminMenuItems(),
            'tenantScope' => $tenantScope,
            'formContext' => $formContext,
            'pageTitle' => 'Nuovo documento fatturazione',
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
                    'document_number' => $this->request->getPost('document_number'),
                    'document_type' => $this->request->getPost('document_type'),
                    'issue_date' => $this->request->getPost('issue_date'),
                    'payment_date' => $this->request->getPost('payment_date'),
                    'patient_name' => $this->request->getPost('patient_name'),
                    'patient_tax_code' => $this->request->getPost('patient_tax_code'),
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
