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
        $device=session()->get('patient_booking_device');if(!$device){$device=bin2hex(random_bytes(32));session()->set('patient_booking_device',$device);}
        return new PatientBookingAgenda((new TenantDatabaseConnector())->connect($this->tenant),$this->settings,$this->actor,['device'=>$device,'ip'=>$this->request->getIPAddress()]);
    }
    private function catalog(): array
    {
        $cat=$this->agenda()->catalog(); $c=$this->settings['config'];
        $config=PatientBookingWorkflow::resolve($c);
        $config['verifica']=['richiesta'=>false,'metodi'=>[]];
        foreach($config['passi'] as &$step) if ($step['id']==='verifica') $step['attivo']=false;
        unset($step);
        $config['scelte']['primo_disponibile']=$c['first_available'];
        $config['regole']['preavviso_modifica_ore']=$c['cancel_hours'];
        $config['comunicazioni']['privacy_url']=$c['privacy_url'];
        $config['comunicazioni']['contatti']=['telefono'=>$c['telefono'],'email'=>$c['email'],'whatsapp'=>''];
        return $cat+['config'=>$config,'prenotazioni_attive'=>empty($c['suspended']) && (bool)($cat['professionisti'] && $cat['prestazioni'] && $cat['sedi']),
            'modalita'=>$config['accesso']['modalita'],'invito'=>$this->request->getGet('invito')?$this->agenda()->validateInvite((string)$this->request->getGet('invito')):null,'revisione'=>$this->settings['revision'],
            'regole'=>['max_attive'=>$config['limiti']['attive_per_recapito'],'finestra_giorni'=>$c['days'],'preavviso_sposta_ore'=>$c['cancel_hours'],'riserva_minuti'=>0,'scadenza_richiesta_ore'=>$config['accesso']['ore_scadenza_richieste']]];
    }
    public function index(string $board='A01-regole')
    {
        if ($guard=$this->guard()) return $guard;
        try {
            return \App\Services\PatientBookingDesign::render($board,[
                'tenant'=>$this->tenant['tenant_name'],'operator'=>((new \App\Models\PlatformUsersModel())->find($this->actor)['username']??'Amministrazione'),'state'=>\App\Services\PatientBookingEditor::state($this->settings),'catalog'=>$this->agenda()->catalog(true),
                'editorUrl'=>portal_tenant_space_url('prenotazione-online/editor'),
                'adminBase'=>portal_tenant_space_url('prenotazione-online/admin'),
                'csrf'=>csrf_hash(),'csrfHeader'=>csrf_header(),
                'patientUrl'=>portal_tenant_space_url('prenotazione-online/paziente'),
                'apiUrl'=>portal_tenant_space_url('prenotazione-online/api'),
                'assetUrl'=>portal_tenant_space_url('prenotazione-online/asset'),
                'selection'=>array_intersect_key($this->settings['config'],array_flip(['doctors','visits','locations'])),
                'stats'=>(new \App\Services\PatientBookingRecords((new TenantDatabaseConnector())->connect($this->tenant)))->counters(),
                'navigation'=>['Agenda'=>portal_tenant_space_url('agenda'),'Pazienti'=>portal_tenant_space_url('pazienti'),'Impostazioni'=>portal_tenant_space_url('profilo-operativo'),'Modifica i contatti'=>portal_tenant_space_url('profilo-operativo')],
                'requests'=>$board==='A02-richieste'?$this->agenda()->requests():[],
            ]);
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function editor(string $action='')
    {
        if($guard=$this->guard())return $guard;
        try {
            $payload=(array)$this->request->getJSON(true);
            if((int)$this->request->getHeaderLine('If-Match')!==$this->settings['revision'])throw new PatientBookingException('configurazione_cambiata',409);
            if($action==='catalog'){
                $c=$this->settings['config'];$catalog=$this->agenda()->catalog(true);
                foreach(['doctors'=>'professionisti','visits'=>'prestazioni','locations'=>'sedi'] as $key=>$bucket){
                    $ids=array_values(array_unique(array_map('intval',(array)($payload[$key]??[]))));
                    if(!$ids || array_diff($ids,array_map('intval',array_column($catalog[$bucket],'id'))))throw new \InvalidArgumentException('Seleziona almeno un elemento valido per ogni elenco.');
                    $c[$key]=$ids;
                }
                if(isset($payload['catalogo'])){
                    $c['workflow']['catalogo']=$payload['catalogo'];
                    if(isset($c['editor']['draft']))$c['editor']['draft']['catalogo']=$payload['catalogo'];
                }
            }else $c=\App\Services\PatientBookingEditor::transition($this->settings['config'],$action,$payload,$this->actor);
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
            if($this->request->getGet('calendario')){
                $agenda=$this->agenda();$p=$agenda->detail((string)$this->request->getGet('ref'));
                return $this->response->download('appuntamento.ics',\App\Services\PatientBookingEmail::calendar($p,$agenda->catalog()));
            }
            if($this->request->getGet('bozza'))$this->applyDraft();
            $cat=$this->catalog(); $c=$this->settings['config']; $name=(string)$this->tenant['tenant_name'];
            $studio=['tenantKey'=>(string)$this->tenant['id_tenant'],'nome'=>$name,'monogramma'=>mb_strtoupper(mb_substr($name,0,2)),
                'telefono'=>$c['telefono'],'telHref'=>'tel:'.preg_replace('/[^+0-9]/','',$c['telefono']),'email'=>$c['email'],'whatsapp'=>'','waHref'=>'#','ragioneSociale'=>$name,'piva'=>''];
            $bootstrap=['studio'=>$studio,'catalog'=>$cat,'api'=>portal_tenant_space_url('prenotazione-online/api'),
                'csrf'=>csrf_hash(),'csrfHeader'=>csrf_header(),'today'=>(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Rome')))->format('Y-m-d\TH:i:s'),
                'privacy'=>$c['privacy_url'],'back'=>portal_tenant_space_url('prenotazione-online'),'draftPreview'=>(bool)$this->request->getGet('bozza')];
            return view('patient_booking/index',['bookingBootstrap'=>$bootstrap]);
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function api(string $action='',string $ref='',string $operation='')
    {
        if ($guard=$this->guard()) return $guard;
        try {
            $method=strtoupper($this->request->getMethod());
            if($this->request->getGet('bozza')) {
                if($method!=='GET')throw new PatientBookingException('anteprima_sola_lettura',403);
                $this->applyDraft();
            }
            if (!service('throttler')->check('patient-booking-'.$this->tenant['id_tenant'].'-'.$this->actor,60,60)) throw new PatientBookingException('troppe_richieste',429);
            $agenda=$this->agenda();
            $records=new \App\Services\PatientBookingRecords((new TenantDatabaseConnector())->connect($this->tenant));
            if ($method==='GET' && $action==='catalogo') $data=$this->catalog();
            elseif($method==='GET' && $action==='richieste') $data=['richieste'=>$agenda->requests()];
            elseif($method==='GET' && $action==='contatti') $data=['contatti'=>$records->contacts()];
            elseif($method==='POST' && $action==='presenza') {$body=(array)$this->request->getJSON(true);$records->absence((int)($body['appuntamento']??0),$this->actor,!empty($body['assente']));$data=['ok'=>true];}
            elseif($method==='POST' && $action==='contatto') {$records->contact((array)$this->request->getJSON(true),$this->actor);$data=['ok'=>true];}
            elseif($method==='POST' && $action==='chiudi-contatto') {$records->closeContact((int)($this->request->getJSON(true)['id']??0));$data=['ok'=>true];}
            elseif($method==='GET' && $action==='agenda') $data=['slots'=>$agenda->daySchedule((string)$this->request->getGet('giorno'),(int)$this->request->getGet('professionista'))];
            elseif($method==='POST' && $action==='inviti') $data=['token'=>$agenda->invite((array)$this->request->getJSON(true))];
            elseif($method==='POST' && $action==='prova-email') {
                $user=(new \App\Models\PlatformUsersModel())->find($this->actor);
                $payload=(array)$this->request->getJSON(true);
                $message=$this->sampleEmail((string)($payload['tipo']??'P2'));
                if(!\App\Services\PatientBookingEmail::deliver((int)$this->tenant['id_tenant'],(string)($user['email']??''),$message))throw new PatientBookingException('invio_non_riuscito',503);
                $data=['ok'=>true];
            }
            elseif($method==='POST' && $action==='richieste' && in_array($operation,['conferma','rifiuta'],true)) $data=['prenotazione'=>$agenda->resolveRequest($ref,$operation,(array)$this->request->getJSON(true))];
            elseif($method==='POST' && $action==='richieste' && $operation==='collega') $data=['id_client'=>$agenda->linkPatient($ref,(array)$this->request->getJSON(true))];
            elseif($method==='POST' && $action==='richieste' && in_array($operation,['blocca','sblocca','rifiuta-numero'],true)) {$agenda->contactAction($ref,$operation);$data=['ok'=>true];}
            elseif ($method==='GET' && $action==='disponibilita') $data=$agenda->availability((array)$this->request->getGet());
            elseif ($method==='POST' && $action==='prenotazioni' && $ref==='') $data=['prenotazione'=>$agenda->book((array)$this->request->getJSON(true),$this->request->getHeaderLine('Idempotency-Key'))];
            elseif ($method==='GET' && $action==='prenotazioni' && $ref==='mie') $data=['prenotazioni'=>$agenda->mine()];
            elseif ($method==='GET' && $action==='prenotazioni' && $ref!=='') $data=['prenotazione'=>$agenda->detail($ref)];
            elseif ($method==='POST' && $action==='prenotazioni' && $operation==='annulla') $data=['prenotazione'=>$agenda->cancel($ref,(array)$this->request->getJSON(true))];
            elseif ($method==='POST' && $action==='prenotazioni' && $operation==='sposta') $data=['prenotazione'=>$agenda->move($ref,(array)$this->request->getJSON(true))];
            else throw new PatientBookingException('non_trovata',404);
            if($method==='POST' && in_array($action,['prenotazioni','richieste'],true)){
                try{(new \App\Services\PatientBookingNotifications((new TenantDatabaseConnector())->connect($this->tenant),$this->tenant,$this->settings))->run(portal_tenant_space_url('prenotazione-online/paziente'));}
                catch(\Throwable $e){log_message('error','Patient booking notification dispatch deferred.');}
            }
            return $this->response->setJSON($data+['csrf'=>csrf_hash()]);
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    private function failure(\Throwable $e)
    {
        if (!$e instanceof PatientBookingException) log_message('error','Patient booking failed: {class}',['class'=>get_class($e)]);
        return $this->response->setStatusCode($e instanceof PatientBookingException?$e->status:503)->setJSON([
            'errore'=>$e instanceof PatientBookingException?$e->reason:'riprova',
            'messaggio'=>'Qualcosa non ha funzionato. Riprova tra qualche secondo.','csrf'=>csrf_hash()]+($e instanceof PatientBookingException?$e->data:[]));
    }
    private function applyDraft(): void
    {
        $this->settings['config']=\App\Services\PatientBookingEditor::transition($this->settings['config'],'publish',[],$this->actor);
    }
    public function asset(string $kind)
    {
        if($guard=$this->guard())return $guard;
        try {
            if($this->request->getGet('bozza'))$this->applyDraft();
            if($kind==='email')return $this->response->setHeader('Content-Security-Policy',"default-src 'none'; img-src data: https:; style-src 'unsafe-inline'; sandbox")->setContentType('text/html')->setBody($this->sampleEmail((string)($this->request->getGet('tipo')??'P2'))['html']);
            if($kind!=='qr')throw new PatientBookingException('non_trovata',404);
            $url=portal_tenant_space_url('prenotazione-online/paziente');$query=[];$cat=$this->agenda()->catalog();
            foreach(['prestazione'=>'prestazioni','professionista'=>'professionisti','sede'=>'sedi'] as $key=>$bucket){$id=(string)($this->request->getGet($key)??'');if(in_array($id,array_column($cat[$bucket],'id'),true))$query[$key]=$id;}
            if($query)$url.='?'.http_build_query($query);
            if(!class_exists(\chillerlan\QRCode\QRCode::class))require dirname(rtrim(ROOTPATH,DIRECTORY_SEPARATOR)).'/vendor/autoload.php';
            $options=new \chillerlan\QRCode\QROptions(['outputType'=>\chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG,'outputBase64'=>false,'scale'=>10]);
            $png=(new \chillerlan\QRCode\QRCode($options))->render($url);
            return $this->response->setContentType('image/png')->setBody($png);
        }catch(\Throwable $e){return $this->failure($e);}
    }
    private function sampleEmail(string $type): array
    {
        $cat=$this->agenda()->catalog();$p=['ref'=>'K7P2QH','data'=>'2026-11-12','ora'=>'17:30','professionista'=>$cat['professionisti'][0]['id']??'','sede'=>$cat['sedi'][0]['id']??'','prestazione'=>$cat['prestazioni'][0]['id']??'','paziente'=>['nome'=>'Rosa','cognome'=>'Bianchi'],'cellulare'=>'3478126650','email'=>'rosa@example.test','ics_sequence'=>0];
        return \App\Services\PatientBookingEmail::render($type,$p,$this->tenant,$this->settings['config'],$cat,portal_tenant_space_url('prenotazione-online/paziente'));
    }
}
