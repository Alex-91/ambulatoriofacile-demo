<?php
namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\{TenantContextService,TenantCatalogService,WhatsAppBookingService,WhatsAppBookingConfig,WhatsAppBookingAgenda,WhatsAppBookingEngine,WhatsAppBookingPreview};

class WhatsAppBooking extends BaseController
{
    private int $tenantId=0;
    public function __construct(){helper(['portal','session_auth','form']);}
    private function guard()
    {
        if(!session_access_is_confirmed())return $this->response->setStatusCode(401)->setBody('Sessione scaduta.');
        $context=(new TenantContextService())->getCurrentTenant();
        if(!$context || !session_has_tenant_management_access() || (int)session()->get('platform_user_id')<=0)return $this->response->setStatusCode(403)->setBody('Accesso riservato al responsabile dello spazio.');
        $membership=(new TenantCatalogService())->getTenantMembership((int)session()->get('platform_user_id'),$context->tenantId);
        if(!\App\Services\WhatsAppBookingAccess::canManage($membership))return $this->response->setStatusCode(403)->setBody('Accesso allo spazio non autorizzato.');
        $this->tenantId=$context->tenantId;
        return null;
    }
    public function index()
    {
        if($guard=$this->guard())return $guard;
        $service=new WhatsAppBookingService();$errors=(array)(session()->getFlashdata('errors')??[]);$catalog=['doctors'=>[],'visits'=>[],'locations'=>[]];
        try{$catalog=WhatsAppBookingAgenda::forTenant($this->tenantId)->catalog();}catch(\Throwable $e){$errors[]='Catalogo agende non disponibile. Verifica la configurazione dello spazio.';}
        return view('tenant/whatsapp_booking',[
            'tenant'=>(new TenantCatalogService())->getTenantById($this->tenantId),'config'=>$service->config($this->tenantId),
            'enabled'=>$service->enabled($this->tenantId),'ready'=>$service->ready(),'catalog'=>$catalog,'requests'=>$service->requests($this->tenantId),
            'errors'=>$errors,'success'=>session()->getFlashdata('success'),
        ]);
    }
    public function save()
    {
        if($guard=$this->guard())return $guard;
        try{(new WhatsAppBookingService())->save($this->tenantId,(array)$this->request->getPost('booking'));
            return redirect()->to(portal_tenant_space_url('chatbot-whatsapp'))->with('success','Configurazione prenotazioni aggiornata.');
        }catch(\Throwable $e){return redirect()->to(portal_tenant_space_url('chatbot-whatsapp'))->withInput()->with('errors',[$e->getMessage()]);}
    }
    public function resolve()
    {
        if($guard=$this->guard())return $guard;
        try{(new WhatsAppBookingService())->resolve($this->tenantId,(int)$this->request->getPost('request_id'),(string)$this->request->getPost('action'),(int)session()->get('platform_user_id'));
            return redirect()->to(portal_tenant_space_url('chatbot-whatsapp').'#requests')->with('success','Richiesta aggiornata.');
        }catch(\Throwable $e){return redirect()->to(portal_tenant_space_url('chatbot-whatsapp').'#requests')->with('errors',[$e->getMessage()]);}
    }
    public function preview()
    {
        if($guard=$this->guard())return $guard;
        if(!(new WhatsAppBookingService())->enabled($this->tenantId))return $this->response->setStatusCode(403)->setJSON(['error'=>'Chatbot non abilitato.']);
        try{
            $c=WhatsAppBookingConfig::normalize((array)$this->request->getPost('booking'));
            $catalog=WhatsAppBookingAgenda::forTenant($this->tenantId)->catalog();WhatsAppBookingConfig::validate($c,$catalog);
            $key='wa_preview_'.$this->tenantId;$stored=session()->get($key)??[];$hash=hash('sha256',json_encode($c));
            $state=($stored['hash']??'')===$hash?($stored['state']??[]):[];
            if($this->request->getPost('reset')==='1')$state=[];
            $message=mb_substr((string)$this->request->getPost('message'),0,1000);
            $result=(new WhatsAppBookingEngine())->advance($c,$state,$message,new WhatsAppBookingPreview($catalog,$this->request->getPost('patient_found')!=='0'));
            session()->set($key,['hash'=>$hash,'state'=>$result['state']??$state]);
            return $this->response->setJSON(['reply'=>$result['reply']??'Scrivi una parola chiave di avvio.','csrf'=>csrf_hash()]);
        }catch(\Throwable $e){return $this->response->setStatusCode(422)->setJSON(['error'=>$e->getMessage(),'csrf'=>csrf_hash()]);}
    }
}
