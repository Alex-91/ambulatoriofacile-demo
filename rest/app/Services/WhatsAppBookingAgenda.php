<?php
namespace App\Services;

use App\Libraries\DatabaseConfig;
use App\Models\AgendaAppointmentModel;
use CodeIgniter\Database\BaseConnection;

/** All reads and writes use the explicitly resolved tenant connection. */
class WhatsAppBookingAgenda
{
    public function __construct(private BaseConnection $db, private string $phone='')
    {
        (new DatabaseConfig())->setEncryptionConfig($db);
        if(empty($db->query('SELECT @key_str AS crypto_key')->getRowArray()['crypto_key']))throw new \RuntimeException('Cifratura anagrafica non configurata.');
    }

    public static function forTenant(int $tenantId,string $phone=''): self
    {
        $tenant=(new TenantCatalogService())->getTenantById($tenantId);
        if (!$tenant || empty($tenant['is_active'])) throw new \RuntimeException('Spazio non disponibile.');
        return new self((new TenantDatabaseConnector())->connect($tenant),$phone);
    }

    private function decrypt(string $field): string
    {
        return "CONVERT(CAST(AES_DECRYPT(UNHEX($field), @key_str, vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    }

    public function catalog(): array
    {
        $doctors=$this->db->table('dap03_personale')->select('legacy_id_dot AS id, id_personale, CONCAT('.$this->decrypt('cognome').", ' ', ".$this->decrypt('nome').') AS label',false)
            ->whereIn('tipo',[1,2])->where('legacy_id_dot >',0)->get()->getResultArray();
        $visits=$this->db->tableExists('dap44_agenda_tipi_visita')?$this->db->table('dap44_agenda_tipi_visita')->select('id_tipo_visita AS id, nome AS label, durata_minuti AS duration')->where('attivo',1)->orderBy('nome')->get()->getResultArray():[];
        $locations=$this->db->tableExists('dap42_ambulatori')?$this->db->table('dap42_ambulatori')->select('*, id_amb_legacy AS id, nome AS label')->get()->getResultArray():[];
        return ['doctors'=>$doctors,'visits'=>$visits,'locations'=>$locations];
    }

    public function assertBookingReady(): void
    {
        if(!(new AgendaVisitTypeSchemaService($this->db))->isReady())throw new \RuntimeException('Aggiornare lo schema delle prestazioni prima di abilitare le prenotazioni.');
        if($this->db->tableExists('dap26_doctor_patient_search')&&!$this->db->fieldExists('paz_spec_norm','dap26_doctor_patient_search'))throw new \RuntimeException('Aggiornare lo schema di ricerca pazienti prima di abilitare le prenotazioni.');
    }

    public function patient(string $cf): ?array
    {
        $rows=$this->db->table('dap02_clients')->select('id_client, '.$this->decrypt('nome').' AS nome, '.$this->decrypt('cognome').' AS cognome',false)
            ->where('UPPER('.$this->decrypt('codice_fiscale').')',$cf)->get(2)->getResultArray();
        if(count($rows)>1)return ['ambiguous'=>true];
        return $rows[0]??null;
    }

    public function choices(array $c,array $s,string $step): array
    {
        $catalog=$this->catalog();$visits=array_values(array_filter($catalog['visits'],static fn($v)=>in_array((int)$v['id'],$c['single_visit']?[$c['default_visit_id']]:$c['visit_ids'],true)));
        // Offer only visits that fit at least one free span in this doctor’s agenda.
        $slots=$this->availableSlots($c);
        $result=[];
        foreach($visits as $visit){
            if(isset($s['visit_id']) && (int)$visit['id']!==(int)$s['visit_id'])continue;
            foreach($slots as $index=>$slot){
                if(isset($s['day']) && $s['day']!==$slot['data_slot'])continue;
                if(!$this->fits($slots,$index,(int)$visit['duration']))continue;
                if($step==='visit'){$result[(int)$visit['id']]=$visit;break;}
                if($step==='day')$result[$slot['data_slot']]=['id'=>$slot['data_slot'],'label'=>(new \DateTimeImmutable($slot['data_slot']))->format('d/m/Y')];
                if($step==='time')$result[(int)$slot['id_slot']]=['id'=>(int)$slot['id_slot'],'label'=>(new \DateTimeImmutable($slot['ora_inizio']))->format('H:i').' · '.$this->locationLabel($slot,$catalog),'start'=>$slot['ora_inizio']];
            }
        }
        if($step==='day')ksort($result);
        if($step==='time')uasort($result,static fn($a,$b)=>strcmp($a['start'],$b['start']) ?: ($a['id']<=>$b['id']));
        return array_values($result);
    }

    private function availableSlots(array $c): array
    {
        $now=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Rome'));
        $from=$now->format('Y-m-d H:i:s');
        // Availability belongs to the agenda, never to a second chatbot calendar.
        $rows=$this->db->table('dap11_agenda_slot')->where('id_dot',$c['doctor_id'])
            ->where('ora_inizio >',$from)->orderBy('data_slot')->orderBy('id_amb_legacy')
            ->orderBy('id_stanza')->orderBy('ora_inizio')->orderBy('id_slot')->get()->getResultArray();
        $locks=$this->db->table('dap14_agenda_lock')->select('id_slot')->where('stato','ATTIVO')->where('expires_at >=',date('Y-m-d H:i:s'))->get()->getResultArray();
        $locked=array_fill_keys(array_column($locks,'id_slot'),true);
        $blocked=$this->db->tableExists('dap21_agenda_giorni_bloccati')?$this->db->table('dap21_agenda_giorni_bloccati')->where('id_dot',$c['doctor_id'])->get()->getResultArray():[];
        $days=array_fill_keys(array_column($blocked,'data_agenda'),true);
        $appointments=$this->db->table('dap12_agenda_appuntamenti a')->select('a.*, s.ora_inizio AS slot_start, s.ora_fine AS slot_end')->join('dap11_agenda_slot s','s.id_slot=a.id_slot')->where('a.id_dot',$c['doctor_id'])->where('a.stato !=','ANNULLATO')->where('s.data_slot >=',substr($from,0,10))->get()->getResultArray();
        return array_values(array_filter($rows,static function($slot)use($locked,$days,$appointments){
            if($slot['stato']!=='LIBERO'||isset($locked[$slot['id_slot']])||isset($days[$slot['data_slot']]))return false;
            foreach($appointments as $a){$start=$a['ora_inizio_appuntamento']??$a['slot_start'];$end=$a['ora_fine_appuntamento']??$a['slot_end'];if($start<$slot['ora_fine']&&$end>$slot['ora_inizio'])return false;}
            return true;
        }));
    }

    private function fits(array $slots,int $index,int $duration): bool
    {
        $first=$slots[$index];$expected=$first['ora_inizio'];$total=0;
        for($i=$index;$i<count($slots);$i++){
            $row=$slots[$i];
            if($row['data_slot']!==$first['data_slot']||$row['ora_inizio']!==$expected||(int)($row['id_stanza']??0)!==(int)($first['id_stanza']??0)||(int)($row['id_amb_legacy']??0)!==(int)($first['id_amb_legacy']??0))break;
            $total+=(int)((strtotime($row['ora_fine'])-strtotime($row['ora_inizio']))/60);$expected=$row['ora_fine'];
            if($total===$duration)return true;if($total>$duration)break;
        }
        return false;
    }

    private function locationLabel(array $slot,array $catalog): string
    {
        $locations=array_column($catalog['locations'],null,'id');
        $location=$locations[(int)($slot['id_amb_legacy']??0)]??[];
        return implode(', ',array_filter(array_map('trim',[
            (string)($location['label']??'Sede indicata in agenda'),
            (string)($location['indirizzo']??''),(string)($location['citta']??'')
        ])));
    }

    public function summary(array $c,array $s): string
    {
        $catalog=$this->catalog();$visits=array_column($catalog['visits'],null,'id');$doctors=array_column($catalog['doctors'],null,'id');
        $v=$visits[$s['visit_id']]??null;if(!$v)throw new WhatsAppBookingUnavailable('Prestazione non disponibile.');
        $slot=$this->db->table('dap11_agenda_slot')->where('id_slot',(int)$s['slot']['id'])->where('id_dot',$c['doctor_id'])->where('data_slot',$s['day'])->get(1)->getRowArray();
        if(!$slot)throw new WhatsAppBookingUnavailable('Orario non disponibile.');
        // Never disclose registry details based only on possession of a tax code.
        $patient=empty($s['patient']['id_client'])||!empty($s['patient']['name_supplied'])?trim(($s['patient']['nome']??'').' '.($s['patient']['cognome']??'')):'Anagrafica associata tramite codice fiscale';
        return "Riepilogo prenotazione\n".$v['label'].' · '.$v['duration']." minuti\n".($doctors[$c['doctor_id']]['label']??'')."\n".$this->locationLabel($slot,$catalog)."\n".date('d/m/Y',strtotime($s['day'])).' alle '.date('H:i',strtotime($slot['ora_inizio']))."\nPaziente: ".$patient;
    }

    public function book(array $c,array $s): int
    {
        $key='wa-patient-'.substr(hash('sha256',$this->db->database.'|'.($s['patient']['codice_fiscale']??$s['booking_key'])),0,48);
        if((int)($this->db->query('SELECT GET_LOCK(?,10) AS acquired',[$key])->getRowArray()['acquired']??0)!==1)throw new \RuntimeException('Prenotazione in elaborazione.');
        try{return $this->bookLocked($c,$s);}finally{$this->db->query('SELECT RELEASE_LOCK(?)',[$key]);}
    }

    private function bookLocked(array $c,array $s): int
    {
        // Webhooks must never trigger the legacy model's automatic schema repair.
        $this->assertBookingReady();
        $marker='WhatsApp booking '.$s['booking_key'];
        // This marker makes recovery safe if the tenant commit succeeds before the platform receipt.
        $existing=$this->db->table('dap12_agenda_appuntamenti')->where('note',$marker)->get(1)->getRowArray();
        if($existing)return (int)$existing['id_appuntamento'];
        if(!in_array((int)$s['slot']['id'],array_map('intval',array_column($this->choices($c,$s,'time'),'id')),true))throw new WhatsAppBookingUnavailable('Orario non più disponibile.');
        $patient=$s['patient'];
        $this->db->transBegin();
        try {
            // Lock all doctor slots for this day, also serializing other agenda writers.
            $this->db->query('SELECT id_slot FROM dap11_agenda_slot WHERE id_dot=? AND data_slot=? ORDER BY id_slot FOR UPDATE',[$c['doctor_id'],$s['day']])->getResultArray();
            $existing=$this->db->table('dap12_agenda_appuntamenti')->where('note',$marker)->get(1)->getRowArray();
            if($existing){$this->db->transCommit();return (int)$existing['id_appuntamento'];}
            if(empty($patient['id_client'])) {
                if(!empty($patient['codice_fiscale'])){
                    $found=$this->patient($patient['codice_fiscale']);
                    if(!empty($found['ambiguous']))throw new \RuntimeException('Anagrafica da verificare in segreteria.');
                    if($found)$patient['id_client']=$found['id_client'];
                }
                if(empty($patient['id_client']))$patient['id_client']=$this->createPatient($patient,$c['doctor_id']);
            }
            $id=(new AgendaAppointmentModel($this->db))->saveAppointment([
                'id_slot'=>(int)$s['slot']['id'],'id_dot'=>$c['doctor_id'],'id_tipo_visita'=>(int)$s['visit_id'],
                'visit_types_feature_enabled'=>true,'slot_lock_required'=>false,'check_active_locks'=>true,
                'id_client'=>(int)$patient['id_client'],'id_paziente'=>(int)$patient['id_client'],
                'nome'=>$patient['nome'],'cognome'=>$patient['cognome'],'cellulare'=>$this->phone,'note'=>$marker,
            ]);
            (new \App\Models\DoctorPatientSearchModel($this->db))->syncClient((int)$patient['id_client'],false);
            if(!$this->db->transStatus())throw new \RuntimeException('Prenotazione non salvata.');
            $this->db->transCommit();return $id;
        }catch(\Throwable $e){$this->db->transRollback();
            if(preg_match('/slot|occupat|bloccata|disponibil|prenotato/i',$e->getMessage()))throw new WhatsAppBookingUnavailable('Orario non più disponibile.',0,$e);
            throw $e;
        }
    }

    private function createPatient(array $p,int $doctor): int
    {
        $person=$this->db->table('dap03_personale')->where('legacy_id_dot',$doctor)->get(1)->getRowArray();
        $this->db->query('SET @wa_vector = RANDOM_BYTES(16)');
        $fields=['nome','cognome','codice_fiscale','data_nascita','cellulare','telefono','email','indirizzo','citta','provincia','cap','comune_nascita','provincia_nascita','residenza_indirizzo','residenza_comune','residenza_cap','residenza_provincia','paz_spec'];
        $known=$this->db->getFieldNames('dap02_clients');$columns=[];$values=[];$params=[];
        foreach($fields as $field){if(!in_array($field,$known,true))continue;$columns[]=$field;$values[]='HEX(AES_ENCRYPT(?, @key_str, @wa_vector))';$params[]=$field==='cellulare'?$this->phone:(string)($p[$field]??'');}
        $columns=array_merge($columns,['vector_id','id_personale','bloccato','avviso_mail']);$values=array_merge($values,['@wa_vector','?','0','0']);$params[]=(int)($person['legacy_dot_tipo_id']??0)===1?(int)$person['id_personale']:null;
        $this->db->query('INSERT INTO dap02_clients ('.implode(',',$columns).') VALUES ('.implode(',',$values).')',$params);
        $id=(int)$this->db->insertID();
        if($this->db->tableExists('dap09_client_doctor'))$this->db->table('dap09_client_doctor')->insert(['id_client'=>$id,'id_dot'=>(int)$person['id_personale']]);
        return $id;
    }
}
