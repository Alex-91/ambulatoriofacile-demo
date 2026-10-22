<?php
namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\{PatientBookingSettings, PatientBookingWorkflow, PatientBookingAgenda, PatientBookingException, TenantCatalogService, TenantContextService, TenantDatabaseConnector};

/** Internal entry point only: there are deliberately no public patient routes. */
class PatientBooking extends BaseController
{
    private array $tenant=[];
    private array $settings=[];
    private int $actor=0;
    public function __construct() { helper(['portal','session_auth','form']); }
    private function guard()
    {
        $this->response->setHeader('Cache-Control','no-store')->setHeader('X-Robots-Tag','noindex, nofollow');
        if (!session_access_is_confirmed()) return $this->response->setStatusCode(401)->setJSON(['errore'=>'sessione_assente']);
        $context=(new TenantContextService())->getCurrentTenant();
        $this->actor=(int)session()->get('platform_user_id');
        if (!$context || $this->actor<=0 || !session_has_tenant_management_access()) return $this->response->setStatusCode(403)->setJSON(['errore'=>'non_autorizzato']);
        $catalog=new TenantCatalogService();
        $membership=$catalog->getTenantMembership($this->actor,$context->tenantId);
        if (!$membership || ($membership['invitation_status']??'')!=='accepted' || (int)($membership['tenant_is_active']??0)!==1) return $this->response->setStatusCode(403)->setJSON(['errore'=>'non_autorizzato']);
        if (!in_array($membership['tenant_role']??'',['tenant_master','tenant_admin'],true) && (int)($membership['is_app_admin']??0)!==1) return $this->response->setStatusCode(403)->setJSON(['errore'=>'non_autorizzato']);
        $this->tenant=$catalog->getTenantById($context->tenantId)??[];
        $this->settings=(new PatientBookingSettings())->get($context->tenantId);
        if (!$this->settings['enabled'] || empty($this->tenant['is_active'])) return $this->response->setStatusCode(403)->setJSON(['errore'=>'prenotazioni_sospese']);
        return null;
    }
    private function agenda(): PatientBookingAgenda
    {
        return new PatientBookingAgenda((new TenantDatabaseConnector())->connect($this->tenant),$this->settings,$this->actor);
    }
    private function catalog(): array
    {
        $cat=$this->agenda()->catalog(); $c=$this->settings['config'];
        $config=PatientBookingWorkflow::resolve($c);
        $config['verifica']=['richiesta'=>false,'metodi'=>[]];
        foreach($config['passi'] as &$step) if ($step['id']==='verifica') $step['attivo']=false;
        unset($step);
        $config['accesso']['modalita']='aperta';
        $config['scelte']['primo_disponibile']=$c['first_available'];
        $config['dati']['cellulare']='facoltativo';
        $config['regole']['preavviso_modifica_ore']=$c['cancel_hours'];
        $config['comunicazioni']['privacy_url']=$c['privacy_url'];
        $config['comunicazioni']['contatti']=['telefono'=>$c['telefono'],'email'=>$c['email'],'whatsapp'=>''];
        $config['catalogo']=['prestazioni'=>[],'professionisti'=>[]];
        return $cat+['config'=>$config,'prenotazioni_attive'=>empty($c['suspended']) && (bool)($cat['professionisti'] && $cat['prestazioni'] && $cat['sedi']),
            'modalita'=>'aperta','invito'=>null,'revisione'=>$this->settings['revision'],
            'regole'=>['max_attive'=>2,'finestra_giorni'=>$c['days'],'preavviso_sposta_ore'=>$c['cancel_hours'],'riserva_minuti'=>0,'scadenza_richiesta_ore'=>24]];
    }
    public function index(string $board='A01-regole')
    {
        if ($guard=$this->guard()) return $guard;
        try {
            return \App\Services\PatientBookingDesign::render($board,[
                'tenant'=>$this->tenant['tenant_name'],'state'=>\App\Services\PatientBookingEditor::state($this->settings),'catalog'=>$this->agenda()->catalog(true),
                'editorUrl'=>portal_tenant_space_url('prenotazione-online/editor'),
                'adminBase'=>portal_tenant_space_url('prenotazione-online/admin'),
                'csrf'=>csrf_hash(),'csrfHeader'=>csrf_header(),
                'patientUrl'=>portal_tenant_space_url('prenotazione-online/paziente'),
            ]);
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function editor(string $action='')
    {
        if($guard=$this->guard())return $guard;
        try {
            $payload=(array)$this->request->getJSON(true);
            if((int)$this->request->getHeaderLine('If-Match')!==$this->settings['revision'])throw new PatientBookingException('configurazione_cambiata',409);
            $c=\App\Services\PatientBookingEditor::transition($this->settings['config'],$action,$payload,$this->actor);
            $store=new PatientBookingSettings();$store->save((int)$this->tenant['id_tenant'],$c,true,$this->actor,$this->settings['revision']);
            return $this->response->setJSON(['state'=>\App\Services\PatientBookingEditor::state($store->get((int)$this->tenant['id_tenant'])),'csrf'=>csrf_hash()]);
        } catch(\InvalidArgumentException $e) {return $this->response->setStatusCode(422)->setJSON(['messaggio'=>$e->getMessage(),'csrf'=>csrf_hash()]);}
        catch(\Throwable $e) {return $this->failure($e);}
    }
    public function save()
    {
        if ($guard=$this->guard()) return $guard;
        try {
            $c=(array)$this->request->getPost('booking');
            $w=(array)$this->request->getPost('workflow');
            $order=explode(',',(string)($w['order']??''));
            $active=(array)($w['active']??[]);
            $w['passi']=array_map(fn($id)=>['id'=>$id,'attivo'=>in_array($id,$active,true)],$order);
            unset($w['order'],$w['active']);
            $w['aspetto']['mostra_nome_con_logo']=!empty($w['aspetto']['mostra_nome_con_logo']);
            $w['aspetto']['logo_url']=$this->settings['config']['workflow']['aspetto']['logo_url']??null;
            if($this->request->getPost('remove_logo')) $w['aspetto']['logo_url']=null;
            $logo=$this->request->getFile('logo');
            if($logo && $logo->getError()!==UPLOAD_ERR_NO_FILE) {
                if(!$logo->isValid() || $logo->getSize()>1048576 || !in_array($logo->getMimeType(),['image/png','image/jpeg'],true)) throw new \InvalidArgumentException('Carica un logo PNG o JPEG di massimo 1 MB.');
                $w['aspetto']['logo_url']='data:'.$logo->getMimeType().';base64,'.base64_encode(file_get_contents($logo->getTempName()));
            }
            foreach(['salta_professionista_se_unico','salta_sede_se_unica'] as $key) $w['scelte'][$key]=!empty($w['scelte'][$key]);
            $w['regole']['spostamento_consentito']=!empty($w['regole']['spostamento_consentito']);
            $c['workflow']=$w;
            $c['editor']=$this->settings['config']['editor'];
            $c['suspended']=$this->settings['config']['suspended'];
            $cat=$this->agenda()->catalog(true);
            foreach(['doctors'=>'professionisti','visits'=>'prestazioni','locations'=>'sedi'] as $key=>$bucket) {
                if(array_diff(array_map('intval',(array)($c[$key]??[])),array_map('intval',array_column($cat[$bucket],'id')))) throw new \InvalidArgumentException('Seleziona solo elementi del catalogo di questo spazio.');
            }
            // Only the platform master can change enabled. Revision CAS also protects against a concurrent revocation.
            if((int)$this->request->getPost('revision')!==$this->settings['revision']) throw new PatientBookingException('configurazione_cambiata',409);
            (new PatientBookingSettings())->save((int)$this->tenant['id_tenant'],$c,true,$this->actor,$this->settings['revision']);
            return redirect()->to(portal_tenant_space_url('prenotazione-online'))->with('booking_success','Configurazione salvata. Il percorso paziente è aggiornato.');
        } catch (\Throwable $e) {
            $message=$e instanceof \InvalidArgumentException?$e->getMessage():($e instanceof PatientBookingException && $e->reason==='configurazione_cambiata'?'La configurazione è cambiata. Ricarica prima di salvare.':'Salvataggio non riuscito. Controlla catalogo, contatti e informativa privacy.');
            return redirect()->to(portal_tenant_space_url('prenotazione-online'))->with('booking_error',$message);
        }
    }
    public function patient()
    {
        if ($guard=$this->guard()) return $guard;
        try {
            $cat=$this->catalog(); $c=$this->settings['config']; $name=(string)$this->tenant['tenant_name'];
            $studio=['tenantKey'=>(string)$this->tenant['id_tenant'],'nome'=>$name,'monogramma'=>mb_strtoupper(mb_substr($name,0,2)),
                'telefono'=>$c['telefono'],'telHref'=>'tel:'.preg_replace('/[^+0-9]/','',$c['telefono']),'email'=>$c['email'],'whatsapp'=>'','waHref'=>'#','ragioneSociale'=>$name,'piva'=>''];
            $bootstrap=['studio'=>$studio,'catalog'=>$cat,'api'=>portal_tenant_space_url('prenotazione-online/api'),
                'csrf'=>csrf_hash(),'csrfHeader'=>csrf_header(),'today'=>(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Rome')))->format('Y-m-d\TH:i:s'),
                'privacy'=>$c['privacy_url'],'back'=>portal_tenant_space_url('prenotazione-online')];
            return view('patient_booking/index',['bookingBootstrap'=>$bootstrap]);
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function api(string $action='',string $ref='',string $operation='')
    {
        if ($guard=$this->guard()) return $guard;
        try {
            $method=strtoupper($this->request->getMethod());
            if (!service('throttler')->check('patient-booking-'.$this->tenant['id_tenant'].'-'.$this->actor,60,60)) throw new PatientBookingException('troppe_richieste',429);
            $agenda=$this->agenda();
            if ($method==='GET' && $action==='catalogo') $data=$this->catalog();
            elseif ($method==='GET' && $action==='disponibilita') $data=$agenda->availability((array)$this->request->getGet());
            elseif ($method==='POST' && $action==='prenotazioni' && $ref==='') $data=['prenotazione'=>$agenda->book((array)$this->request->getJSON(true),$this->request->getHeaderLine('Idempotency-Key'))];
            elseif ($method==='GET' && $action==='prenotazioni' && $ref==='mie') $data=['prenotazioni'=>$agenda->mine()];
            elseif ($method==='GET' && $action==='prenotazioni' && $ref!=='') $data=['prenotazione'=>$agenda->detail($ref)];
            elseif ($method==='POST' && $action==='prenotazioni' && $operation==='annulla') $data=['prenotazione'=>$agenda->cancel($ref)];
            elseif ($method==='POST' && $action==='prenotazioni' && $operation==='sposta') $data=['prenotazione'=>$agenda->move($ref,(array)$this->request->getJSON(true))];
            else throw new PatientBookingException('non_trovata',404);
            return $this->response->setJSON($data+['csrf'=>csrf_hash()]);
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    private function failure(\Throwable $e)
    {
        if (!$e instanceof PatientBookingException) log_message('error','Patient booking failed: {class}',['class'=>get_class($e)]);
        return $this->response->setStatusCode($e instanceof PatientBookingException?$e->status:503)->setJSON([
            'errore'=>$e instanceof PatientBookingException?$e->reason:'riprova',
            'messaggio'=>'Qualcosa non ha funzionato. Riprova tra qualche secondo.','csrf'=>csrf_hash()]);
    }
}
