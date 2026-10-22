<?php
namespace App\Controllers\Login;

use App\Controllers\BaseController;
use App\Models\PlatformTenantsModel;
use App\Services\{PlatformAdminAccessService, PatientBookingSettings, PatientBookingAgenda, PatientBookingException, TenantCatalogService, TenantDatabaseConnector, AgendaVisitTypeSchemaService};

class PlatformPatientBookingController extends BaseController
{
    public function __construct() { helper(['portal','form']); }
    private function guard()
    {
        if (!(new PlatformAdminAccessService())->canAccessPlatformConsole()) return $this->response->setStatusCode(403)->setBody('Area piattaforma riservata agli account master.');
        $this->response->setHeader('Cache-Control','no-store');
        return null;
    }
    public function index()
    {
        if ($guard=$this->guard()) return $guard;
        $tenants=(new PlatformTenantsModel())->where('is_active',1)->orderBy('tenant_name')->findAll();
        $id=(int)$this->request->getGet('tenant_id');
        $tenant=null; foreach($tenants as $row) if((int)$row['id_tenant']===$id) $tenant=$row;
        $settings=new PatientBookingSettings(); $state=$settings->get($id);
        $catalog=['professionisti'=>[],'prestazioni'=>[],'sedi'=>[]]; $ready=false;
        if ($tenant) {
            try {
                $db=(new TenantDatabaseConnector())->connect($tenant);
                $catalog=(new PatientBookingAgenda($db,$state,0))->catalog(true);
                $ready=$settings->ready() && $db->tableExists('patient_online_bookings') && (new AgendaVisitTypeSchemaService($db))->isReady();
            } catch (\Throwable $e) { log_message('error','Patient booking catalog unavailable for tenant {id}',['id'=>$id]); }
        }
        return view('admin/patient_booking',['tenants'=>$tenants,'tenant'=>$tenant,'state'=>$state,'catalog'=>$catalog,'ready'=>$ready,
            'error'=>session()->getFlashdata('booking_error'),'success'=>session()->getFlashdata('booking_success')]);
    }
    public function save()
    {
        if ($guard=$this->guard()) return $guard;
        $id=(int)$this->request->getPost('tenant_id');
        try {
            $tenant=(new TenantCatalogService())->getTenantById($id);
            if (!$tenant || empty($tenant['is_active'])) throw new PatientBookingException('spazio_non_valido');
            $service=new PatientBookingSettings(); $settings=$service->get($id);
            $db=(new TenantDatabaseConnector())->connect($tenant);
            $config=(array)$this->request->getPost('booking');
            $config['workflow']=$settings['config']['workflow'];
            $config['editor']=$settings['config']['editor'];
            $config['suspended']=$settings['config']['suspended'];
            $enabled=$this->request->getPost('enabled')==='1';
            if ($enabled) {
                if (!$db->tableExists('patient_online_bookings') || !(new AgendaVisitTypeSchemaService($db))->isReady()) throw new PatientBookingException('schema_non_installato');
                $cat=(new PatientBookingAgenda($db,$settings,0))->catalog(true);
                foreach (['doctors'=>'professionisti','visits'=>'prestazioni','locations'=>'sedi'] as $key=>$bucket) {
                    if (array_diff(array_map('intval',(array)($config[$key]??[])),array_map('intval',array_column($cat[$bucket],'id')))) throw new PatientBookingException('configurazione_non_valida');
                }
            }
            $service->save($id,$config,$enabled,(int)session()->get('platform_user_id'),(int)$this->request->getPost('revision'));
            return redirect()->to(portal_platform_url('prenotazione-online').'?tenant_id='.$id)->with('booking_success','Configurazione salvata.');
        } catch (\Throwable $e) {
            $message=$e instanceof PatientBookingException && $e->reason==='configurazione_cambiata'?'La configurazione è cambiata. Ricarica la pagina prima di salvare.':'Configurazione non salvata. Verifica catalogo, recapiti, informativa privacy e installazione del modulo.';
            return redirect()->to(portal_platform_url('prenotazione-online').'?tenant_id='.$id)->with('booking_error',$message);
        }
    }
}
