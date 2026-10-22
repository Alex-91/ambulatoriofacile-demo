<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use App\Libraries\DatabaseConfig;
use App\Models\AgendaAppointmentModel;

/** Internal booking adapter. Never uses the default/ambient tenant connection. */
final class PatientBookingAgenda
{
    public function __construct(private BaseConnection $db, private array $settings, private int $actor)
    {
        (new DatabaseConfig())->setEncryptionConfig($db);
    }

    public function catalog(bool $all = false): array
    {
        $decrypt=static fn($field)=>"CONVERT(CAST(AES_DECRYPT(UNHEX($field), @key_str, vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
        $doctors=$this->db->table('dap03_personale')->select('legacy_id_dot AS id, '.$decrypt('nome').' AS nome, '.$decrypt('cognome').' AS cognome, '.$decrypt('qualifica').' AS titolo',false)
            ->whereIn('tipo',[1,2])->where('legacy_id_dot >',0)->get()->getResultArray();
        $visits=$this->db->table('dap44_agenda_tipi_visita')->where('attivo',1)->get()->getResultArray();
        $locations=$this->db->table('dap42_ambulatori')->get()->getResultArray();
        $config=$this->settings['config'];
        $doctors=array_values(array_filter($doctors,static fn($r)=>$all || in_array((int)$r['id'],$config['doctors'],true)));
        $locations=array_values(array_filter($locations,static fn($r)=>$all || in_array((int)$r['id_amb_legacy'],$config['locations'],true)));
        $visits=array_values(array_filter($visits,static fn($r)=>(int)$r['durata_minuti']>0 && ($all || in_array((int)$r['id_tipo_visita'],$config['visits'],true))));
        // A doctor/site pair must exist in this tenant's agenda; never invent a relation.
        $pairs=$this->db->table('dap11_agenda_slot')->select('id_dot,id_amb_legacy')->distinct()->get()->getResultArray();
        $locationIds=array_map(static fn($r)=>(string)$r['id_amb_legacy'],$locations);
        foreach ($doctors as &$doctor) {
            $doctor['id']=(string)$doctor['id']; $doctor['qualifica']='';
            $doctor['sedi']=array_values(array_unique(array_map(static fn($r)=>(string)$r['id_amb_legacy'],array_filter($pairs,static fn($r)=>(string)$r['id_dot']===$doctor['id'] && in_array((string)$r['id_amb_legacy'],$locationIds,true)))));
        } unset($doctor);
        $services=[];
        foreach ($visits as $v) $services[]=['id'=>(string)$v['id_tipo_visita'],'nome'=>$v['nome'],'specialita'=>'Prestazioni','durata'=>(int)$v['durata_minuti'],'prezzo'=>null,'professionisti'=>array_column($doctors,'id'),'sedi'=>$locationIds,'parole'=>$v['nome']];
        $sites=[];
        foreach ($locations as $l) $sites[]=['id'=>(string)$l['id_amb_legacy'],'nome'=>$l['nome'],'indirizzo'=>$l['indirizzo']??'','cap'=>$l['cap']??'','citta'=>$l['citta']??'','prov'=>$l['provincia']??''];
        return ['professionisti'=>$doctors,'prestazioni'=>$services,'sedi'=>$sites];
    }

    /** Pure planning function: same doctor, room and site, contiguous slots, >= duration. */
    public static function spans(array $rows, int $minutes): array
    {
        if ($minutes<=0) return [];
        $groups=[];
        foreach ($rows as $row) $groups[implode('|',[$row['id_dot'],$row['data_slot'],$row['id_amb_legacy']??0,$row['id_stanza']??0])][]=$row;
        $result=[];
        foreach ($groups as $slots) {
            usort($slots,static fn($a,$b)=>[$a['ora_inizio'],$a['id_slot']]<=>[$b['ora_inizio'],$b['id_slot']]);
            foreach ($slots as $i=>$first) {
                $cursor=$first['ora_inizio']; $ids=[]; $total=0;
                for ($j=$i;$j<count($slots);$j++) {
                    $s=$slots[$j];
                    if ($s['ora_inizio']!==$cursor || $s['ora_fine']<=$cursor) break;
                    $delta=(new \DateTimeImmutable($s['ora_fine'],new \DateTimeZone('Europe/Rome')))->getTimestamp()-(new \DateTimeImmutable($cursor,new \DateTimeZone('Europe/Rome')))->getTimestamp();
                    if ($delta<=0) break;
                    $total+=$delta; $cursor=$s['ora_fine']; $ids[]=(int)$s['id_slot'];
                    if ($total>=$minutes*60) { $result[]=$first+['ids'=>$ids,'end'=>(new \DateTimeImmutable($first['ora_inizio'],new \DateTimeZone('Europe/Rome')))->modify('+'.$minutes.' minutes')->format('Y-m-d H:i:s')]; break; }
                }
            }
        }
        usort($result,static fn($a,$b)=>[$a['ora_inizio'],$a['id_dot'],$a['id_slot']]<=>[$b['ora_inizio'],$b['id_dot'],$b['id_slot']]);
        return $result;
    }

    public function availability(array $q): array
    {
        $now=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Rome'));
        $from=(string)($q['dal']??$now->format('Y-m-d'));
        $first=!empty($q['prima_disponibilita']);
        $to=$first?$now->modify('+'.$this->settings['config']['days'].' days')->format('Y-m-d'):(string)($q['al']??$from);
        if (!self::validDay($from) || !self::validDay($to) || $to<$from || (!$first && (strtotime($to)-strtotime($from))/86400>13)) throw new PatientBookingException('intervallo_non_valido');
        $slots=$this->slots($q,$from,$to);
        if ($first) return ['primo'=>$slots?self::publicSlot($slots[0]):null];
        $days=[];
        for ($d=new \DateTimeImmutable($from);$d->format('Y-m-d')<=$to;$d=$d->modify('+1 day')) {
            $day=$d->format('Y-m-d');
            $options=array_values(array_filter($slots,static fn($s)=>$s['data_slot']===$day));
            // Same displayed time has one deterministic professional, matching "Il primo disponibile".
            $times=[]; foreach ($options as $s) $times[substr($s['ora_inizio'],11,5)]??=self::publicSlot($s);
            $days[$day]=['stato'=>$times?(count($times)<=3?'pochi':'posti'):($d->format('w')==='0'?'chiuso':'nessuno'),'orari'=>array_values($times)];
        }
        return ['giorni'=>$days];
    }

    private function slots(array $q,string $from,string $to): array
    {
        if(!empty($this->settings['config']['suspended'])) throw new PatientBookingException('prenotazioni_sospese',403);
        $cat=$this->catalog(); $services=array_column($cat['prestazioni'],null,'id');
        $visit=$services[(string)($q['prestazione']??'')]??null;
        if (!$visit) throw new PatientBookingException('prestazione_non_valida');
        $config=$this->settings['config'];
        $doctor=(string)($q['professionista']??'primo'); $site=(string)($q['sede']??'');
        if ($doctor==='primo' && !$config['first_available'] && count($cat['professionisti'])>1) throw new PatientBookingException('professionista_non_valido');
        $doctors=$doctor==='primo'?array_column($cat['professionisti'],'id'):[$doctor];
        if (array_diff($doctors,array_column($cat['professionisti'],'id')) || ($site!=='' && !in_array($site,array_column($cat['sedi'],'id'),true))) throw new PatientBookingException('configurazione_cambiata',409);
        if (!$doctors || !$config['locations']) return [];
        $now=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Rome'));
        $query=$this->db->table('dap11_agenda_slot')->whereIn('id_dot',$doctors)->whereIn('id_amb_legacy',$site!==''?[(int)$site]:$config['locations'])
            ->where('data_slot >=',$from)->where('data_slot <=',$to)->where('stato','LIBERO')
            ->where('ora_inizio >=',$now->modify('+'.$config['min_notice'].' minutes')->format('Y-m-d H:i:s'))
            ->where('ora_inizio <=',$now->modify('+'.$config['days'].' days')->format('Y-m-d H:i:s'));
        $rows=$query->get()->getResultArray();
        $locks=$this->db->table('dap14_agenda_lock')->where('stato','ATTIVO')->where('expires_at >=',$now->format('Y-m-d H:i:s'))->get()->getResultArray();
        $locked=array_fill_keys(array_column($locks,'id_slot'),true);
        $blocked=$this->db->table('dap21_agenda_giorni_bloccati')->whereIn('id_dot',$doctors)->where('data_agenda >=',$from)->where('data_agenda <=',$to)->get()->getResultArray();
        $days=[]; foreach ($blocked as $b) $days[$b['id_dot'].'|'.$b['data_agenda']]=true;
        $holidays=$this->db->tableExists('dap48_gio_ros')?array_column($this->db->table('dap48_gio_ros')->get()->getResultArray(),'gio_ros'):[];
        $appointments=$this->db->query("SELECT a.id_dot,COALESCE(a.ora_inizio_appuntamento,s.ora_inizio) AS start_at,COALESCE(a.ora_fine_appuntamento,s.ora_fine) AS end_at FROM dap12_agenda_appuntamenti a JOIN dap11_agenda_slot s ON s.id_slot=a.id_slot WHERE a.stato <> 'ANNULLATO' AND s.data_slot >= ? AND s.data_slot <= ?",[$from,$to])->getResultArray();
        $rows=array_values(array_filter($rows,static function($s)use($locked,$days,$holidays,$appointments){
            $d=new \DateTimeImmutable($s['data_slot']);
            if (isset($locked[$s['id_slot']]) || isset($days[$s['id_dot'].'|'.$s['data_slot']]) || $d->format('w')==='0') return false;
            foreach (['d/m','d/m/Y','j/n','j/n/Y'] as $format) if (in_array($d->format($format),$holidays,true)) return false;
            foreach ($appointments as $a) if ((int)$a['id_dot']===(int)$s['id_dot'] && $a['start_at']<$s['ora_fine'] && $a['end_at']>$s['ora_inizio']) return false;
            return true;
        }));
        return self::spans($rows,$visit['durata']);
    }

    private static function publicSlot(array $slot): array
    {
        return ['data'=>$slot['data_slot'],'ora'=>ltrim(substr($slot['ora_inizio'],11,5),'0'),'professionista'=>(string)$slot['id_dot'],'sede'=>(string)$slot['id_amb_legacy']];
    }
    private static function validDay(string $day): bool
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$day);
        return $date && $date->format('Y-m-d')===$day;
    }

    public function book(array $body,string $key): array
    {
        if (!$this->db->tableExists('patient_online_bookings') || !(new AgendaVisitTypeSchemaService($this->db))->isReady()) throw new PatientBookingException('schema_non_installato',503);
        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/D',$key)) throw new PatientBookingException('richiesta_non_valida');
        $hash=hash('sha256',json_encode($body,JSON_THROW_ON_ERROR));
        $s=(array)($body['slot']??[]); $p=(array)($body['paziente']??[]); $contact=(array)($body['contatto']??[]);
        if (empty($body['privacy_letta']) || !empty($body['trappola']) || !self::validDay((string)($s['data']??'')) || !preg_match('/^\d{1,2}:\d{2}$/D',(string)($s['ora']??''))) throw new PatientBookingException('dati_non_validi');
        if (($body['per']??'me')==='altro' && empty($body['autorizzato'])) throw new PatientBookingException('dati_non_validi');
        foreach (['nome','cognome'] as $field) if (trim((string)($p[$field]??''))==='' || mb_strlen((string)$p[$field])>80) throw new PatientBookingException('dati_non_validi');
        $phone=preg_replace('/[\s()-]/','',(string)($contact['cellulare']??'')); $email=trim((string)($contact['email']??''));
        $workflow=PatientBookingWorkflow::resolve($this->settings['config']);
        $required=$workflow['senza_verifica']['recapito'];
        if (($phone!=='' && !preg_match('/^(?:\+?39)?3\d{8,9}$/D',$phone)) || ($email!=='' && (!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>120)) || ($required==='almeno_uno' && $phone==='' && $email==='') || ($required==='cellulare' && $phone==='') || ($required==='email' && $email==='') || mb_strlen((string)($body['nota']??''))>1000) throw new PatientBookingException('dati_non_validi');
        if($workflow['dati']['note']==='obbligatorio' && trim((string)($body['nota']??''))==='') throw new PatientBookingException('dati_non_validi');
        if($workflow['dati']['note']==='nascosto') $body['nota']='';
        $active=array_column(array_filter($workflow['passi'],fn($step)=>$step['attivo']),'id');
        if(!in_array('perchi',$active,true) && ($body['per']??'me')!=='me') throw new PatientBookingException('dati_non_validi');
        if(!in_array('prestazione',$active,true) && (string)$s['prestazione']!==(string)$workflow['scelte']['prestazione_predefinita']) throw new PatientBookingException('dati_non_validi');
        if(!in_array('professionista',$active,true) && $workflow['scelte']['professionista_predefinito'] && (string)$s['professionista']!==(string)$workflow['scelte']['professionista_predefinito']) throw new PatientBookingException('dati_non_validi');
        $doctor=(int)($s['professionista']??0);
        $lock='patient-web-'.substr(hash('sha256',$this->db->database.'|'.$doctor.'|'.$s['data']),0,48);
        if ((int)($this->db->query('SELECT GET_LOCK(?,10) AS acquired',[$lock])->getRowArray()['acquired']??0)!==1) throw new PatientBookingException('riprova',503);
        try {
            $this->db->transBegin();
            try {
                $this->db->query('SELECT id_slot FROM dap11_agenda_slot WHERE id_dot=? AND data_slot=? ORDER BY id_slot FOR UPDATE',[$doctor,$s['data']])->getResultArray();
                $old=$this->db->table('patient_online_bookings')->where('author_id',$this->actor)->where('idempotency_key',$key)->get()->getRowArray();
                if ($old) {
                    if (!hash_equals($old['request_hash'],$hash)) throw new PatientBookingException('richiesta_cambiata',409);
                    $this->db->transCommit(); return $this->detail($old['booking_ref']);
                }
                $matches=$this->slots($s,$s['data'],$s['data']); $chosen=null;
                foreach ($matches as $slot) if (self::publicSlot($slot)['ora']===ltrim($s['ora'],'0')) { $chosen=$slot; break; }
                if (!$chosen) throw new PatientBookingException('slot_non_disponibile',409);
                // Internal trial: operator identity authorizes the action; supplied patient data is never used to identify/link an existing registry record.
                $id=(new AgendaAppointmentModel($this->db))->saveAppointment([
                    'id_slot'=>(int)$chosen['id_slot'],'id_dot'=>$doctor,'id_tipo_visita'=>(int)$s['prestazione'],
                    'visit_types_feature_enabled'=>true,'visit_type_required'=>true,'slot_lock_required'=>false,'check_active_locks'=>true,
                    'custom_appointment_time_feature_enabled'=>true,'custom_time_enabled'=>true,
                    'custom_start_time'=>substr($chosen['ora_inizio'],11,5),'custom_end_time'=>substr($chosen['end'],11,5),
                    'nome'=>trim($p['nome']),'cognome'=>trim($p['cognome']),'cellulare'=>$phone,'email'=>$email,
                    'note'=>trim((string)($body['nota']??'')),
                ]);
                $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                do { $ref=''; for($i=0;$i<6;$i++) $ref.=$alphabet[random_int(0,strlen($alphabet)-1)]; }
                while ($this->db->table('patient_online_bookings')->where('booking_ref',$ref)->countAllResults()>0);
                $this->db->table('patient_online_bookings')->insert(['id_appuntamento'=>$id,'booking_ref'=>$ref,'author_id'=>$this->actor,'idempotency_key'=>$key,'request_hash'=>$hash,'revision'=>$this->settings['revision'],'created_at'=>gmdate('Y-m-d H:i:s')]);
                if (!$this->db->transStatus()) throw new \RuntimeException('Booking persistence failed');
                $this->db->transCommit();
                return $this->detail($ref);
            } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
        } finally { $this->db->query('SELECT RELEASE_LOCK(?)',[$lock]); }
    }

    public function detail(string $ref): array
    {
        $row=$this->db->table('patient_online_bookings b')->select('b.*,a.*,s.data_slot,s.ora_inizio,s.id_amb_legacy')
            ->join('dap12_agenda_appuntamenti a','a.id_appuntamento=b.id_appuntamento')->join('dap11_agenda_slot s','s.id_slot=a.id_slot')
            ->where('b.author_id',$this->actor)->where('b.booking_ref',$ref)->get()->getRowArray();
        if (!$row) throw new PatientBookingException('non_trovata',404);
        return ['ref'=>$ref,'stato'=>$row['stato']==='ANNULLATO'?'annullata':'confermata','data'=>$row['data_slot'],
            'ora'=>ltrim(substr($row['ora_inizio_appuntamento']?:$row['ora_inizio'],11,5),'0'),'prestazione'=>(string)$row['id_tipo_visita'],
            'professionista'=>(string)$row['id_dot'],'sede'=>(string)$row['id_amb_legacy'],'paziente'=>['nome'=>$row['nome'],'cognome'=>$row['cognome']],
            'cellulare'=>$row['cellulare'],'email'=>$row['email'],'canale'=>null,'verificata'=>false,'ics_sequence'=>0];
    }

    public function cancel(string $ref): array
    {
        $this->db->transBegin();
        try {
            $row=$this->db->query('SELECT b.id_appuntamento FROM patient_online_bookings b WHERE b.booking_ref=? AND b.author_id=? FOR UPDATE',[$ref,$this->actor])->getRowArray();
            if (!$row) throw new PatientBookingException('non_trovata',404);
            $p=$this->detail($ref);
            if ($p['stato']!=='annullata') {
                $now=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Rome'));
                $start=new \DateTimeImmutable($p['data'].' '.$p['ora'],new \DateTimeZone('Europe/Rome'));
                if ($start <= $now->modify('+'.$this->settings['config']['cancel_hours'].' hours')) throw new PatientBookingException('preavviso_insufficiente',409);
                (new AgendaAppointmentModel($this->db))->deleteAppointment((int)$row['id_appuntamento'],0);
            }
            if (!$this->db->transStatus()) throw new \RuntimeException('Cancellation failed');
            $this->db->transCommit();
            return $this->detail($ref);
        } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
    }
    public function move(string $ref,array $body): array
    {
        if(!PatientBookingWorkflow::resolve($this->settings['config'])['regole']['spostamento_consentito']) throw new PatientBookingException('spostamento_non_consentito',403);
        $p=$this->detail($ref); $day=(string)($body['data']??''); $time=(string)($body['ora']??'');
        if (!self::validDay($day) || !preg_match('/^\d{1,2}:\d{2}$/D',$time)) throw new PatientBookingException('dati_non_validi');
        $days=array_values(array_unique([$p['data'],$day])); sort($days); $locks=[];
        try {
            foreach ($days as $d) {
                $lock='patient-web-'.substr(hash('sha256',$this->db->database.'|'.$p['professionista'].'|'.$d),0,48);
                if ((int)($this->db->query('SELECT GET_LOCK(?,10) AS acquired',[$lock])->getRowArray()['acquired']??0)!==1) throw new PatientBookingException('riprova',503);
                $locks[]=$lock;
            }
            $this->db->transBegin();
            try {
                foreach ($days as $d) $this->db->query('SELECT id_slot FROM dap11_agenda_slot WHERE id_dot=? AND data_slot=? ORDER BY id_slot FOR UPDATE',[(int)$p['professionista'],$d])->getResultArray();
                $row=$this->db->query('SELECT a.* FROM patient_online_bookings b JOIN dap12_agenda_appuntamenti a ON a.id_appuntamento=b.id_appuntamento WHERE b.booking_ref=? AND b.author_id=? FOR UPDATE',[$ref,$this->actor])->getRowArray();
                $p=$this->detail($ref);
                $start=new \DateTimeImmutable($p['data'].' '.$p['ora'],new \DateTimeZone('Europe/Rome'));
                if (!$row || $p['stato']!=='confermata' || $start <= (new \DateTimeImmutable('now',new \DateTimeZone('Europe/Rome')))->modify('+'.$this->settings['config']['cancel_hours'].' hours')) throw new PatientBookingException('preavviso_insufficiente',409);
                // Retry to the already selected destination is a no-op.
                if ($p['data']===$day && $p['ora']===ltrim($time,'0')) { $this->db->transCommit(); return $p; }
                $matches=$this->slots($p,$day,$day); $chosen=null;
                foreach ($matches as $slot) if (self::publicSlot($slot)['ora']===ltrim($time,'0')) { $chosen=$slot; break; }
                if (!$chosen) throw new PatientBookingException('slot_non_disponibile',409);
                // The model retains/replaces dap45 links and restores the old slots in this outer transaction.
                $this->db->table('dap12_agenda_appuntamenti')->where('id_appuntamento',$row['id_appuntamento'])->update(['id_slot'=>(int)$chosen['id_slot']]);
                (new AgendaAppointmentModel($this->db))->updateAppointment(array_merge($row,[
                    'id_tipo_visita'=>(int)$p['prestazione'],'visit_types_feature_enabled'=>true,'visit_type_required'=>true,
                    'custom_appointment_time_feature_enabled'=>true,'custom_time_enabled'=>true,
                    'custom_start_time'=>substr($chosen['ora_inizio'],11,5),'custom_end_time'=>substr($chosen['end'],11,5),
                ]));
                if (!$this->db->transStatus()) throw new \RuntimeException('Move failed');
                $this->db->transCommit();return $this->detail($ref);
            } catch (\Throwable $e) { $this->db->transRollback();throw $e; }
        } finally { foreach(array_reverse($locks) as $lock) $this->db->query('SELECT RELEASE_LOCK(?)',[$lock]); }
    }
    public function mine(): array
    {
        if (!$this->db->tableExists('patient_online_bookings')) return [];
        $rows=$this->db->table('patient_online_bookings')->where('author_id',$this->actor)->orderBy('id','DESC')->get(100)->getResultArray();
        return array_map(fn($r)=>$this->detail($r['booking_ref']),$rows);
    }
}
