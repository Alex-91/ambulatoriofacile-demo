<?php

namespace App\Controllers\Admin;

use App\Services\FseDispatchService;
use App\Services\FseDocumentService;
use App\Services\TenantPatientLookupService;

class FseDocumentsController extends FseAdminBaseController
{
    private FseDocumentService $documents;
    private FseDispatchService $dispatch;

    public function __construct()
    {
        parent::__construct();
        $this->documents = new FseDocumentService();
        $this->dispatch = new FseDispatchService();
    }

    public function index()
    {
        if ($guard = $this->ensureAccess()) return $guard;
        $scope = $this->resolveTenantScope();
        return view('admin/fse/documents', ['menu_items' => $this->adminMenuItems(), 'tenantScope' => $scope,
            'listing' => $this->documents->listForTenant((int) $scope['tenant_id']), 'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? []]);
    }

    public function create() { return $this->form(0); }
    public function edit(int $id = 0) { return $this->form($id); }

    private function form(int $id)
    {
        if ($guard = $this->ensureAccess()) return $guard;
        $scope = $this->resolveTenantScope();
        $form = $this->documents->buildFormContext((int) $scope['tenant_id'], max(0, $id));
        if ($id > 0 && (int) ($form['document']['id_fse_document'] ?? 0) <= 0) return redirect()->to(site_url('admin/fse2/documenti'))->with('error', 'Referto non trovato.');
        return view('admin/fse/document_form', ['menu_items' => $this->adminMenuItems(), 'tenantScope' => $scope, 'formContext' => $form,
            'success' => session()->getFlashdata('success'), 'errors' => session()->getFlashdata('errors') ?? []]);
    }

    public function save()
    {
        if ($guard = $this->ensureAccess()) return $guard;
        $scope = $this->resolveTenantScope();
        $id = (int) ($this->request->getPost('id_fse_document') ?? 0);
        try {
            $document = $this->documents->saveDraftForTenant((int) $scope['tenant_id'], $this->request->getPost(), $this->currentAdminUserId());
            return redirect()->to(site_url('admin/fse2/documenti/modifica/' . (int) $document['id_fse_document']))->with('success', 'Bozza clinica salvata.');
        } catch (\Throwable $e) {
            return redirect()->to($id > 0 ? site_url('admin/fse2/documenti/modifica/' . $id) : site_url('admin/fse2/documenti/nuovo'))->withInput()->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function prepare(int $id = 0) { return $this->action($id, 'prepare'); }
    public function validateDocument(int $id = 0) { return $this->action($id, 'validate'); }
    public function publish(int $id = 0) { return $this->action($id, 'publish'); }
    public function status(int $id = 0) { return $this->action($id, 'status'); }
    public function deleteDocument(int $id = 0) { return $this->action($id, 'delete'); }

    private function action(int $id, string $action)
    {
        if ($guard = $this->ensureAccess()) return $guard;
        $tenantId = (int) $this->resolveTenantScope()['tenant_id'];
        $target = site_url('admin/fse2/documenti/modifica/' . $id);
        try {
            if ($action === 'prepare') $this->documents->prepareForSignature($tenantId, $id, $this->currentAdminUserId());
            elseif ($action === 'validate') $result = $this->dispatch->validate($tenantId, $id, $this->currentAdminUserId());
            elseif ($action === 'publish') $result = $this->dispatch->publish($tenantId, $id, $this->currentAdminUserId());
            elseif ($action === 'status') $result = $this->dispatch->refreshStatus($tenantId, $id, $this->currentAdminUserId());
            else $result = $this->dispatch->delete($tenantId, $id, $this->currentAdminUserId());
            $message = isset($result) ? (string) ($result['message'] ?? 'Operazione Gateway accettata.') : 'CDA e PDF pronti: firma il PDF in PAdES e ricaricalo.';
            return redirect()->to($target)->with('success', $message);
        } catch (\Throwable $e) {
            return redirect()->to($target)->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function uploadSigned(int $id = 0)
    {
        if ($guard = $this->ensureAccess()) return $guard;
        $target = site_url('admin/fse2/documenti/modifica/' . $id);
        try {
            $file = $this->request->getFile('signed_pdf');
            if ($file === null || !$file->isValid()) throw new \RuntimeException('Seleziona il PDF firmato PAdES.');
            $contents = file_get_contents($file->getTempName());
            if (!is_string($contents)) throw new \RuntimeException('Lettura PDF firmato non riuscita.');
            $this->documents->acceptSignedPdf((int) $this->resolveTenantScope()['tenant_id'], $id, $contents, $this->currentAdminUserId());
            return redirect()->to($target)->with('success', 'PDF firmato acquisito e pronto per la validazione Gateway.');
        } catch (\Throwable $e) { return redirect()->to($target)->with('errors', ['generic' => $e->getMessage()]); }
    }

    public function download(int $id = 0, string $kind = '')
    {
        if ($guard = $this->ensureAccess()) return $guard;
        try {
            $artifact = $this->documents->downloadArtifact((int) $this->resolveTenantScope()['tenant_id'], $id, $kind);
            return $this->response->download($artifact['path'], null)->setFileName($artifact['name']);
        } catch (\Throwable $e) { return redirect()->to(site_url('admin/fse2/documenti/modifica/' . $id))->with('errors', ['generic' => $e->getMessage()]); }
    }

    public function searchPatients()
    {
        if ($this->ensureAccess() !== null) return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'results' => []]);
        $term = trim((string) $this->request->getGet('term'));
        try { return $this->response->setJSON(['ok' => true, 'results' => mb_strlen($term) >= 2 ? (new TenantPatientLookupService())->searchPatientsForTenant((int) $this->resolveTenantScope()['tenant_id'], $term) : []]); }
        catch (\Throwable $e) { return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'results' => []]); }
    }
}
