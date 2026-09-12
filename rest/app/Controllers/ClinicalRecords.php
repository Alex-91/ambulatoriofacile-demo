<?php
namespace App\Controllers;

use App\Services\{ClinicalRecordService,ClinicalAccessPolicy,ClinicalVault,TenantCatalogService,TenantContextService,TenantDatabaseConnector,TenantPatientLookupService};

class ClinicalRecords extends BaseController
{
    private function context(): array
    {
        helper(['session_auth','form','url']);
        if (!session_access_is_confirmed()) throw new \RuntimeException('Accesso non confermato.');
        $catalog = new TenantCatalogService();
        $context = (new TenantContextService($catalog))->getCurrentTenant();
        $tenant = $context ? $catalog->getTenantById($context->tenantId) : $catalog->resolveCurrentRuntimeTenant();
        if (!$tenant || !(int)($tenant['is_active'] ?? 0) || (int)($tenant['id_tenant'] ?? 0) <= 0) throw new \RuntimeException('Spazio non disponibile.');
        (new \App\Services\ClinicalFeatureService())->assertEnabledForTenant((int)$tenant['id_tenant']);
        $me = session()->get('utente_sess');
        $userId = is_object($me) ? (int)($me->id_user ?? 0) : (int)session()->get('id_user');
        if ($context && $context->appUserId > 0 && $context->appUserId !== $userId) throw new \RuntimeException('Identità dello spazio non coerente.');
        $db = (new TenantDatabaseConnector())->connect($tenant);
        $manager = $context && in_array($context->tenantRole,['tenant_master','tenant_admin'],true);
        $service = new ClinicalRecordService($db,(int)$tenant['id_tenant'],$userId,null,$manager);
        $service->actor();
        return [$service,$tenant,$db,$userId];
    }
    public function patient(int $patientId)
    {
        try {
            [$service,$tenant] = $this->context();
            $fseEnabled = (new \App\Services\FseFeatureService())->isEnabledForTenant((int)$tenant['id_tenant']);
            $chart = $service->patient($patientId,max(1,(int)$this->request->getGet('page')), $fseEnabled);
            $patient = (new TenantPatientLookupService())->getPatientByIdForTenant((int)$tenant['id_tenant'],$patientId);
            $editing = null; $revisionOf = null;
            if ((int)$this->request->getGet('edit') > 0) $editing = $service->entry($patientId,(int)$this->request->getGet('edit'));
            if ((int)$this->request->getGet('revise') > 0) $revisionOf = $service->entry($patientId,(int)$this->request->getGet('revise'));
            return $this->privateResponse()->setBody(view('clinical/patient',compact('chart','patient','patientId','tenant','editing','revisionOf')));
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function save(int $patientId)
    { return $this->mutate($patientId,fn($s)=>$s->saveEntry($patientId,(array)$this->request->getPost()),'Bozza salvata.'); }
    public function finalize(int $patientId,int $entryId)
    {
        return $this->mutate($patientId,function($s,$tenant) use($patientId,$entryId) {
            $patient=(new TenantPatientLookupService())->getPatientByIdForTenant((int)$tenant['id_tenant'],$patientId);
            $s->finalize($patientId,$entryId,(int)$this->request->getPost('revision'),$patient);
        },'Documento definitivo creato. Il PDF originale è pronto per la firma.');
    }
    public function attach(int $patientId)
    {
        return $this->mutate($patientId,function($s) use($patientId) {
            [$bytes,$name]=$this->upload();
            $s->attach($patientId,$bytes,$name,(string)$this->request->getPost('category'),(int)$this->request->getPost('entry_id'));
        },'Allegato archiviato.');
    }
    public function consent(int $patientId)
    { return $this->mutate($patientId,fn($s)=>$s->recordConsent($patientId,(array)$this->request->getPost()),'Scelta registrata nello storico dei consensi.'); }
    public function template(int $patientId)
    { return $this->mutate($patientId,fn($s)=>$s->createTemplate((array)$this->request->getPost()),'Nuova versione del modello pubblicata.'); }
    public function sign(int $patientId,int $entryId)
    {
        return $this->mutate($patientId,function($s,$tenant,$db,$userId) use($patientId,$entryId) {
            [$bytes]=$this->upload();
            $user=$db->table('dap01_users')->select('username')->where('id_user',$userId)->get()->getRowArray();
            $s->acceptSignature($patientId,$entryId,$bytes,(string)$this->request->getPost('format'),(string)($user['username'] ?? ''));
        },'Firma verificata e originale conservato.');
    }
    public function download(int $patientId,string $objectId)
    {
        try {
            [$s]=$this->context(); $file=$s->download($patientId,$objectId);
            return $this->privateResponse()->setHeader('Content-Type',$file['mime'])
                ->setHeader('Content-Disposition',"attachment; filename=\"documento\"; filename*=UTF-8''".rawurlencode($file['name']))->setBody($file['bytes']);
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function fseReport(int $patientId,int $documentId)
    {
        try {
            [$s,$tenant]=$this->context();
            if (!(new \App\Services\FseFeatureService())->isEnabledForTenant((int)$tenant['id_tenant'])) throw new \RuntimeException('Modulo FSE non attivo per questo spazio.');
            $file=$s->downloadFseReport($patientId,$documentId);
            return $this->privateResponse()->setHeader('Content-Type','application/pdf')
                ->setHeader('Content-Disposition','attachment; filename="'.$file['name'].'"')->setBody($file['bytes']);
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    private function mutate(int $patientId,callable $action,string $message)
    {
        try {
            if (strtoupper($this->request->getMethod()) !== 'POST') throw new \RuntimeException('Metodo non consentito.');
            $context=$this->context();
            // Also restrict template actions to an accessible patient context.
            (new ClinicalAccessPolicy($context[2],$context[3]))->assertPatient($patientId,false);
            $action(...$context);
            return redirect()->to(site_url('cartella-clinica/pazienti/'.$patientId))->with('success',$message);
        } catch (\Throwable $e) {
            // Never place clinical form contents in session flashdata or logs.
            return $this->failure($e);
        }
    }
    private function upload(): array
    {
        $file=$this->request->getFile('document');
        if (!$file || !$file->isValid() || $file->hasMoved() || $file->getSize()>ClinicalVault::MAX_BYTES) throw new \InvalidArgumentException('Caricare un documento valido fino a 15 MB.');
        $bytes=file_get_contents($file->getTempName(),false,null,0,ClinicalVault::MAX_BYTES+1);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes)>ClinicalVault::MAX_BYTES) throw new \InvalidArgumentException('Documento non leggibile.');
        return [$bytes,$file->getClientName()];
    }
    private function privateResponse()
    { return $this->response->setHeader('Cache-Control','no-store, private')->setHeader('Pragma','no-cache')->setHeader('X-Content-Type-Options','nosniff')->setHeader('Referrer-Policy','no-referrer'); }
    private function failure(\Throwable $e)
    {
        // Database/crypto exceptions can contain sensitive values. Do not expose them.
        $safe=$e instanceof \InvalidArgumentException || get_class($e) === \RuntimeException::class;
        $message=$safe ? $e->getMessage() : 'Operazione non completata. Riaprire la cartella o contattare il responsabile.';
        return $this->privateResponse()->setStatusCode(400)->setBody(view('clinical/error',['message'=>$message]));
    }
}
