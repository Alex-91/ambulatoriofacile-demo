<?php

namespace App\Controllers\Admin;

use App\Services\FseDispatchService;
use App\Services\FseDocumentService;
use App\Services\FseRevisionService;
use App\Services\TenantPatientLookupService;

class FseDocumentsController extends FseAdminBaseController
{
    private FseDocumentService $documents;
    private FseDispatchService $dispatch;
    private ?\App\Services\FseSupportBundleService $support=null;
    private ?\App\Services\FseToscanaDocumentLab $toscanaLab=null;

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

    public function revise(int $id = 0)
    {
        if ($guard = $this->ensureAccess()) return $guard;
        try {
            $newId = (new FseRevisionService())->create((int) $this->resolveTenantScope()['tenant_id'], $id,
                (string) $this->request->getPost('revision_reason'), $this->currentAdminUserId());
            return redirect()->to(site_url('admin/fse2/documenti/modifica/' . $newId))
                ->with('success', 'Correzione locale aperta. L’originale è conservato; nessun documento è stato sostituito sul FSE.');
        } catch (\Throwable $e) {
            return redirect()->to(site_url('admin/fse2/documenti/modifica/' . $id))->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    private function form(int $id)
    {
        if ($guard = $this->ensureAccess()) return $guard;
        $scope = $this->resolveTenantScope();
        $form = $this->documents->buildFormContext((int) $scope['tenant_id'], max(0, $id));
        if ($id > 0 && (int) ($form['document']['id_fse_document'] ?? 0) <= 0) return redirect()->to(site_url('admin/fse2/documenti'))->with('error', 'Referto non trovato.');
        return view('admin/fse/document_form', ['menu_items' => $this->adminMenuItems(), 'tenantScope' => $scope, 'formContext' => $form,
            'syntheticAppLab' => (new \App\Services\FseSyntheticAppBoundary())->isActive(),
            'success' => session()->getFlashdata('success'), 'warning' => session()->getFlashdata('warning'), 'errors' => session()->getFlashdata('errors') ?? []]);
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
            $message = isset($result) ? (string) ($result['message'] ?? 'Operazione Gateway accettata.') : 'CDA e PDF pronti. Segui le azioni disponibili per validazione e firma.';
            if (isset($result)) {
                $severity = $result['feedback']['severity'] ?? (empty($result['ok']) ? 'error' : 'success');
                if ($severity === 'warning') return redirect()->to($target)->with('warning', $message);
                if ($severity !== 'success' || empty($result['ok'])) return redirect()->to($target)->with('errors', ['generic' => $message]);
            }
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
            return redirect()->to($target)->with('success', 'PDF firmato acquisito dopo i controlli locali. Nessun invio al FSE eseguito.');
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

    public function supportBundle(int $id=0)
    {
        if ($guard=$this->ensureAccess()) return $guard;
        try {
            $this->support ??= new \App\Services\FseSupportBundleService();
            $bundle=$this->support->forDocument((int)$this->resolveTenantScope()['tenant_id'],$id);
            return $this->response->download('fse-assistenza-tecnica-'.$id.'.json',
                json_encode($bundle,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))->setHeader('Cache-Control','no-store');
        } catch (\Throwable $e) { return $this->response->setStatusCode(404)->setBody('Rapporto tecnico non disponibile per questo spazio.'); }
    }

    public function importToscanaLab(int $id=0)
    {
        if ($guard=$this->ensureAccess()) return $guard;
        try {
            $this->toscanaLab ??= new \App\Services\FseToscanaDocumentLab();
            $this->toscanaLab->import((int)$this->resolveTenantScope()['tenant_id'], $id, $this->currentAdminUserId());
            return redirect()->to(site_url('admin/fse2/laboratorio-toscana'))
                ->with('success','Snapshot sintetico verificato e collegato al laboratorio. Nessun invio e nessuna modifica al referto del gestionale.');
        } catch (\Throwable $e) {
            $message = match ($e->getMessage()) {
                'LAB_BOUNDARY' => 'Funzione disponibile esclusivamente nel laboratorio applicativo isolato.',
                'LAB_PARENT' => 'Prima completa la pubblicazione simulata della versione precedente, con lo stesso profilo. Nessuna sostituzione reale.',
                'LAB_SOURCE_CHANGED' => 'Il documento non coincide con lo snapshot. Nessun aggiornamento o reinvio automatico.',
                default => 'Snapshot non importato. Verificare firma, integrità, stato e profilo Toscana di test nel laboratorio isolato.',
            };
            return redirect()->to(site_url('admin/fse2/documenti/modifica/'.$id))->with('errors',['generic'=>$message]);
        }
    }
}
