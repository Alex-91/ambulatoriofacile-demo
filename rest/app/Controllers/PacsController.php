<?php
namespace App\Controllers;

use App\Services\{TenantCatalogService,TenantContextService,TenantDatabaseConnector,TenantPatientLookupService};
use App\Services\Pacs\{PacsService,PacsFeatureService,PacsException};

class PacsController extends BaseController
{
    protected function context(): array
    {
        helper(['session_auth','form','url']);
        if (!session_access_is_confirmed()) throw new PacsException('Accesso non confermato.');
        $catalog=new TenantCatalogService();
        $context=(new TenantContextService($catalog))->getCurrentTenant();
        $tenant=$context ? $catalog->getTenantById($context->tenantId) : $catalog->resolveCurrentRuntimeTenant();
        if (!$tenant || empty($tenant['is_active'])) throw new PacsException('Spazio non disponibile.');
        $id=(int)$tenant['id_tenant'];
        (new PacsFeatureService())->assertEnabled($id);
        $me=session()->get('utente_sess');
        $user=is_object($me) ? (int)($me->id_user ?? 0) : (int)session()->get('id_user');
        if ($context && $context->appUserId>0 && $context->appUserId!==$user) throw new PacsException('Identità dello spazio non coerente.');
        $db=(new TenantDatabaseConnector())->connect($tenant);
        return [new PacsService($db,$id,$user),$tenant];
    }
    public function patient(int $patientId)
    {
        try { [$service,$tenant]=$this->context(); return $this->page($service,$tenant,$patientId); }
        catch (\Throwable $e) { return $this->failure($e); }
    }
    public function search(int $patientId)
    {
        try {
            $this->postOnly(); [$service,$tenant]=$this->context();
            $search=$service->search($patientId,(string)$this->request->getPost('binding_id'),(int)($this->request->getPost('page') ?: 1));
            return $this->page($service,$tenant,$patientId,['search'=>$search]);
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function bind(int $patientId)
    {
        return $this->mutate($patientId,function ($s) use ($patientId) {
            $s->bind($patientId,(string)$this->request->getPost('profile_id'),(string)$this->request->getPost('patient_id'),
                (string)$this->request->getPost('issuer'),(int)$this->request->getPost('revision'),$this->request->getPost('confirmed')==='1');
        },'Identità PACS registrata. Ora puoi cercare gli esami.');
    }
    public function unbind(int $patientId)
    {
        return $this->mutate($patientId,fn($s)=>$s->unbind($patientId,(string)$this->request->getPost('binding_id'),(int)$this->request->getPost('revision')),'Collegamento disabilitato. Gli esami restano archiviati nel PACS.');
    }
    public function link(int $patientId)
    {
        return $this->mutate($patientId,fn($s)=>$s->link($patientId,(string)$this->request->getPost('binding_id'),(string)$this->request->getPost('study_uid'),(int)$this->request->getPost('revision'),(int)$this->request->getPost('entry_id')),'Esame collegato alla cartella.');
    }
    public function unlink(int $patientId)
    {
        return $this->mutate($patientId,fn($s)=>$s->unlink($patientId,(string)$this->request->getPost('link_id')),'Collegamento rimosso dalla cartella. Lo studio è conservato nel PACS.');
    }
    public function study(int $patientId,string $id)
    {
        try {
            [$service,$tenant]=$this->context();
            $details=$service->details($patientId,$id);
            $series=(string)$this->request->getGet('series');
            if ($series!=='') $details['instances']=$service->instances($patientId,$id,$series,(int)($this->request->getGet('page') ?: 1));
            return $this->page($service,$tenant,$patientId,['details'=>$details,'selectedSeries'=>$series]);
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function viewer(int $patientId,string $id)
    {
        try {
            $this->postOnly(); [$service]=$this->context();
            $url=$service->viewer($patientId,$id);
            return $this->privateResponse()->setStatusCode(303)->setHeader('Location',$url)->setBody('');
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function download(int $patientId,string $id)
    {
        try {
            $this->postOnly(); [$service]=$this->context();
            $file=$service->download($patientId,$id,(string)$this->request->getPost('series_uid'),(string)$this->request->getPost('instance_uid'));
            return $this->privateResponse()->setHeader('Content-Type',$file['mime'])->setHeader('Content-Disposition','attachment; filename="'.$file['name'].'"')->setBody($file['bytes']);
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    private function page(PacsService $service,array $tenant,int $patientId,array $extra=[])
    {
        $overview=$service->overview($patientId);
        $patient=$this->patientIdentity((int)$tenant['id_tenant'],$patientId);
        return $this->privateResponse()->setBody(view('clinical/pacs',$extra+compact('overview','patient','tenant','patientId'),['saveData'=>false]));
    }
    protected function patientIdentity(int $tenantId,int $patientId): array
    { return (new TenantPatientLookupService())->getPatientByIdForTenant($tenantId,$patientId) ?? []; }
    private function mutate(int $patientId,callable $action,string $message)
    {
        try {
            $this->postOnly(); [$service]=$this->context(); $action($service);
            return redirect()->to(site_url('cartella-clinica/pazienti/'.$patientId.'/pacs'))->with('success',$message)
                ->setHeader('Cache-Control','no-store, private')->setHeader('Referrer-Policy','no-referrer');
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    protected function postOnly(): void
    { if (strtoupper($this->request->getMethod())!=='POST') throw new PacsException('Metodo non consentito.'); }
    protected function privateResponse()
    {
        return $this->response->setHeader('Cache-Control','no-store, private')->setHeader('Pragma','no-cache')
            ->setHeader('Referrer-Policy','no-referrer')->setHeader('X-Content-Type-Options','nosniff')->setHeader('X-Frame-Options','DENY');
    }
    protected function failure(\Throwable $e)
    {
        $message=$e instanceof PacsException ? $e->getMessage() : 'Operazione PACS non disponibile. Verificare i permessi o contattare il responsabile.';
        return $this->privateResponse()->setStatusCode(400)->setBody(view('clinical/error',['message'=>$message]));
    }
}
