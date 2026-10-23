<?php
namespace App\Controllers\Login;

use App\Controllers\BaseController;
use App\Models\PlatformTenantsModel;
use App\Services\{PlatformAdminAccessService, PatientBookingSettings, PatientBookingException, TenantCatalogService, TenantDatabaseConnector, AgendaVisitTypeSchemaService};

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
        $ready=false;
        if ($tenant) {
            try {
                $db=(new TenantDatabaseConnector())->connect($tenant);
                $ready=$settings->ready() && \App\Services\PatientBookingSchema::ready($db) && (new AgendaVisitTypeSchemaService($db))->isReady();
            } catch (\Throwable $e) { log_message('error','Patient booking schema unavailable for tenant {id}',['id'=>$id]); }
        }
        return view('admin/patient_booking',['tenants'=>$tenants,'tenant'=>$tenant,'state'=>$state,'ready'=>$ready,
            'error'=>session()->getFlashdata('booking_error'),'success'=>session()->getFlashdata('booking_success')]);
    }
    public function save()
    {
        if ($guard=$this->guard()) return $guard;
        $id=(int)$this->request->getPost('tenant_id');
        try {
            $tenant=(new TenantCatalogService())->getTenantById($id);
            if (!$tenant || empty($tenant['is_active'])) throw new PatientBookingException('spazio_non_valido');
            $service=new PatientBookingSettings();
            $db=(new TenantDatabaseConnector())->connect($tenant);
            $enabled=$this->request->getPost('enabled')==='1';
            if ($enabled) {
                if (!\App\Services\PatientBookingSchema::ready($db) || !(new AgendaVisitTypeSchemaService($db))->isReady()) throw new PatientBookingException('schema_non_installato');
            }
            $service->setEnabled($id,$enabled,(int)session()->get('platform_user_id'),(int)$this->request->getPost('revision'));
            return redirect()->to(portal_platform_url('prenotazione-online').'?tenant_id='.$id)->with('booking_success','Abilitazione del modulo aggiornata. La configurazione è gestita nello spazio.');
        } catch (\Throwable $e) {
            $message=$e instanceof PatientBookingException && $e->reason==='configurazione_cambiata'?'La configurazione è cambiata. Ricarica la pagina prima di salvare.':'Abilitazione non aggiornata. Verifica l’installazione del modulo nello spazio.';
            return redirect()->to(portal_platform_url('prenotazione-online').'?tenant_id='.$id)->with('booking_error',$message);
        }
    }
}
