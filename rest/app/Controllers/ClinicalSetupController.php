<?php
namespace App\Controllers;
use App\Services\{ClinicalSetupService,TenantCatalogService,TenantContextService,TenantDatabaseConnector};
use App\Services\Pacs\{PacsFeatureService,PacsProfiles,PacsManagedProfiles,PacsException};

class ClinicalSetupController extends PacsController
{
    protected function setupContext(): array
    {
        helper(['session_auth','form','url']);
        if (!session_access_is_confirmed()) throw new PacsException('Accesso non confermato.');
        $catalog=new TenantCatalogService(); $context=(new TenantContextService($catalog))->getCurrentTenant();
        $tenant=$context ? $catalog->getTenantById($context->tenantId) : $catalog->resolveCurrentRuntimeTenant();
        if (!$tenant || empty($tenant['is_active'])) throw new PacsException('Spazio non disponibile.');
        $me=session()->get('utente_sess'); $actor=is_object($me) ? (int)($me->id_user ?? 0) : (int)session()->get('id_user');
        if ($context && $context->appUserId>0 && $context->appUserId!==$actor) throw new PacsException('Identità dello spazio non coerente.');
        $db=(new TenantDatabaseConnector())->connect($tenant); $service=new ClinicalSetupService($db,(int)$tenant['id_tenant']);
        $service->assertMaster($actor);
        return [$tenant,$db,$actor,$service];
    }
    public function index()
    {
        try {
            [$tenant,$db,$actor,$service]=$this->setupContext(); $status=$service->inspect(); $profiles=[]; $serverProfiles=[]; $error=null;
            if ($status['pacs_enabled']) {
                try {
                    $profiles=(new PacsManagedProfiles($db,(int)$tenant['id_tenant']))->editable();
                    foreach ((new PacsProfiles(null,$db))->serverForTenant((int)$tenant['id_tenant']) as $p) $serverProfiles[]=['id'=>$p['id'],'label'=>$p['label']];
                } catch (\Throwable) { $error='Configurazione dei collegamenti non leggibile. Contattare l’assistenza.'; }
            }
            $report=session()->getFlashdata('clinical_setup_report');
            return $this->privateResponse()->setBody(view('clinical/setup',compact('tenant','status','profiles','serverProfiles','report','error'),['saveData'=>false]));
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function prepare()
    {
        try { $this->postOnly(); [,, $actor,$service]=$this->setupContext(); $service->initialize($actor); return $this->backToSetup('Preparazione completata. Puoi eseguire il collaudo e configurare i collegamenti.'); }
        catch (\Throwable $e) { return $this->failure($e); }
    }
    public function saveProfile()
    {
        try {
            $this->postOnly(); [$tenant,$db,$actor]=$this->setupContext(); $id=(int)$tenant['id_tenant'];
            (new PacsFeatureService())->assertEnabled($id);
            $reserved=array_keys((new PacsProfiles(null,$db))->serverForTenant($id));
            (new PacsManagedProfiles($db,$id))->save($actor,(array)$this->request->getPost(),$reserved);
            return $this->backToSetup('Collegamento salvato. Le credenziali sono cifrate. Esegui la verifica prima di usarlo.');
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function check()
    {
        try {
            $this->postOnly(); [$tenant,,,$service]=$this->setupContext();
            $key='clinical_setup_check_at_'.(int)$tenant['id_tenant'];
            if (time()-(int)session()->get($key)<15) throw new PacsException('Attendere qualche secondo prima di ripetere il collaudo.');
            session()->set($key,time());
            $report=$service->acceptance((string)$this->request->getPost('profile_id'));
            if ($this->request->getPost('download')==='1') return $this->privateResponse()->setHeader('Content-Type','application/json')->setHeader('Content-Disposition','attachment; filename="collaudo-spazio.json"')->setBody(json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            session()->setFlashdata('clinical_setup_report',$report);
            return $this->backToSetup('Collaudo completato: consulta gli esiti sotto.');
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    private function backToSetup(string $message)
    { return redirect()->to(site_url('cartella-clinica/configurazione'))->with('success',$message)->setHeader('Cache-Control','no-store, private'); }
}
