<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Tenant-local booking metadata. Patient details are encrypted with the tenant key. */
final class PatientBookingRecords
{
    public function __construct(private BaseConnection $db) {}
    public function fingerprint(string $value): ?string
    {
        if($value==='') return null;
        return $this->db->query('SELECT SHA2(CONCAT(@key_str, ?),256) AS digest',[$value])->getRowArray()['digest'];
    }
    public function details(string $ref): array
    {
        $row=$this->db->query('SELECT *,CONVERT(AES_DECRYPT(UNHEX(data_encrypted),@key_str,vector_id) USING utf8mb4) AS plain FROM patient_booking_details WHERE booking_ref=?',[$ref])->getRowArray();
        if(!$row)return [];
        $row['data']=json_decode($row['plain']??'{}',true)?:[];unset($row['plain'],$row['data_encrypted'],$row['vector_id']);return $row;
    }
    public function validateData(array $body,array $workflow): array
    {
        $birth=trim((string)($body['paziente']['nascita']??''));
        $cf=strtoupper(trim((string)($body['anagrafica']['cf']??'')));
        if($workflow['dati']['nascita']==='nascosto')$birth='';
        if($workflow['dati']['codice_fiscale']==='nascosto')$cf='';
        if($workflow['dati']['nascita']==='obbligatorio' && $birth==='')throw new PatientBookingException('dati_non_validi');
        if($birth!=='') {
            $format=str_contains($birth,'/')?'!d/m/Y':'!Y-m-d';$date=\DateTimeImmutable::createFromFormat($format,$birth);
            if(!$date || $date->format(substr($format,1))!==$birth || $date>new \DateTimeImmutable('today') || $date<new \DateTimeImmutable('-130 years'))throw new PatientBookingException('dati_non_validi');
            $birth=$date->format('Y-m-d');
        }
        if(($workflow['dati']['codice_fiscale']==='obbligatorio' && $cf==='') || ($cf!=='' && !preg_match('/^(?:[A-Z0-9]{16}|[0-9]{11})$/D',$cf)))throw new PatientBookingException('dati_non_validi');
        foreach(['cellulare','email'] as $field)if($workflow['dati'][$field]==='obbligatorio' && trim((string)($body['contatto'][$field]??''))==='')throw new PatientBookingException('dati_non_validi');
        return ['nascita'=>$birth,'codice_fiscale'=>$cf,'prenotante'=>$body['prenotante']??null];
    }
    public function enforceLimits(string $phone,string $email,int $visit,array $workflow,?string $start=null,?string $end=null,string $except=''): void
    {
        $hashes=array_values(array_filter([$this->fingerprint($phone),$this->fingerprint(strtolower($email))]));
        if(!$hashes)return;
        if($this->db->table('patient_booking_blocks')->whereIn('contact_hash',$hashes)->countAllResults())throw new PatientBookingException('prenotazioni_sospese',403);
        $now=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s');
        $rows=$this->db->table('patient_booking_details d')->select('a.id_tipo_visita,COALESCE(a.ora_inizio_appuntamento,s.ora_inizio) starts_at,COALESCE(a.ora_fine_appuntamento,s.ora_fine) ends_at',false)
            ->join('patient_online_bookings b','b.booking_ref=d.booking_ref')->join('dap12_agenda_appuntamenti a','a.id_appuntamento=b.id_appuntamento')->join('dap11_agenda_slot s','s.id_slot=a.id_slot')
            ->whereIn('d.status',['confermata','in_attesa'])->where('a.stato !=','ANNULLATO')->where('s.ora_inizio >',$now)->where('d.booking_ref !=',$except)
            ->groupStart()->whereIn('d.phone_hash',$hashes)->orWhereIn('d.email_hash',$hashes)->groupEnd()->get()->getResultArray();
        if(count($rows)>=$workflow['limiti']['attive_per_recapito'])throw new PatientBookingException('limite_prenotazioni');
        if(count(array_filter($rows,fn($r)=>(int)$r['id_tipo_visita']===$visit))>=$workflow['limiti']['stessa_prestazione'])throw new PatientBookingException('prestazione_gia_prenotata');
        if($start && $end)foreach($rows as $row)if($row['starts_at']<$end && $row['ends_at']>$start)throw new PatientBookingException('appuntamento_sovrapposto');
    }
    public function create(string $ref,string $phone,string $email,array $data,array $workflow): string
    {
        $hashes=array_values(array_filter([$this->fingerprint($phone),$this->fingerprint(strtolower($email))]));
        $absences=$hashes?$this->db->table('patient_booking_absences a')->join('patient_booking_details d','d.booking_ref=a.booking_ref')->groupStart()->whereIn('d.phone_hash',$hashes)->orWhereIn('d.email_hash',$hashes)->groupEnd()->countAllResults():0;
        $state=$workflow['accesso']['modalita']==='approvazione'||$absences>=$workflow['limiti']['assenze_per_approvazione']?'in_attesa':'confermata';
        $expiry=$state==='in_attesa'?gmdate('Y-m-d H:i:s',time()+3600*$workflow['accesso']['ore_scadenza_richieste']):null;
        $iv=bin2hex(random_bytes(8));
        $this->db->query('INSERT INTO patient_booking_details (booking_ref,status,expires_at,phone_hash,email_hash,data_encrypted,vector_id,updated_at) VALUES (?,?,?,?,?,HEX(AES_ENCRYPT(?,@key_str,?)),?,UTC_TIMESTAMP())',[$ref,$state,$expiry,$this->fingerprint($phone),$this->fingerprint(strtolower($email)),json_encode($data,JSON_THROW_ON_ERROR),$iv,$iv]);
        $this->event($ref,$state==='in_attesa'?'requested':'confirmed');return $state;
    }
    public function event(string $ref,string $event): void
    {
        $sequence=(int)($this->details($ref)['sequence']??0);
        foreach($event==='reminder'?['patient']:['patient','studio'] as $recipient)$this->db->query('INSERT IGNORE INTO patient_booking_outbox (booking_ref,event,sequence,created_at) VALUES (?,?,?,UTC_TIMESTAMP())',[$ref,$event.'.'.$recipient,$sequence]);
    }
    public function change(string $ref,string $state,string $event): void
    {
        $this->db->query('UPDATE patient_booking_details SET status=?,sequence=sequence+1,updated_at=UTC_TIMESTAMP() WHERE booking_ref=?',[$state,$ref]);
        $this->event($ref,$event);
    }
    public function mergeData(string $ref,array $values): void
    {
        $data=array_replace($this->details($ref)['data']??[],$values);
        $this->db->query('UPDATE patient_booking_details SET data_encrypted=HEX(AES_ENCRYPT(?,@key_str,vector_id)) WHERE booking_ref=?',[json_encode($data,JSON_THROW_ON_ERROR),$ref]);
    }
    public function enforceRequestLimits(array $context,array $workflow): void
    {
        if(!$context)return;
        foreach(['device'=>'senza_verifica_per_dispositivo_giorno','ip'=>'senza_verifica_per_ip_giorno'] as $field=>$key){
            $hash=$this->fingerprint((string)($context[$field]??''));if(!$hash)throw new PatientBookingException('sessione_assente',401);
            $n=$this->db->table('patient_booking_events')->where($field.'_hash',$hash)->where('created_at >=',gmdate('Y-m-d').' 00:00:00')->countAllResults();
            if($n>=$workflow['protezioni'][$key])throw new PatientBookingException('troppe_prenotazioni',429);
        }
    }
    public function recordRequest(string $ref,array $context,array $workflow): void
    {
        if(!$context)return;
        $this->db->table('patient_booking_events')->insert(['booking_ref'=>$ref,'device_hash'=>$this->fingerprint($context['device']),'ip_hash'=>$this->fingerprint($context['ip']),'created_at'=>gmdate('Y-m-d H:i:s')]);
        if($this->counters()['ultima_ora']===$workflow['protezioni']['soglia_anomalia_ora']+1)$this->event($ref,'anomaly');
    }
    public function counters(): array
    {
        return ['oggi'=>$this->db->table('patient_booking_events')->where('created_at >=',gmdate('Y-m-d').' 00:00:00')->countAllResults(),
            'ultima_ora'=>$this->db->table('patient_booking_events')->where('created_at >=',gmdate('Y-m-d H:i:s',time()-3600))->countAllResults(),
            'da_confermare'=>$this->db->table('patient_booking_details')->where('status','in_attesa')->countAllResults(),
            'recapiti_ultima_ora'=>(int)$this->db->query('SELECT COUNT(DISTINCT COALESCE(d.phone_hash,d.email_hash)) AS total FROM patient_booking_events e JOIN patient_booking_details d ON d.booking_ref=e.booking_ref WHERE e.created_at>=?',[gmdate('Y-m-d H:i:s',time()-3600)])->getRowArray()['total'],
            'email_inviate'=>$this->db->table('patient_booking_outbox')->where('state','sent')->where('sent_at >=',gmdate('Y-m-d').' 00:00:00')->countAllResults()];
    }
    public function contact(array $data,int $actor): void
    {
        $name=trim((string)($data['nome']??''));$phone=preg_replace('/[\s()-]/','',(string)($data['cellulare']??''));$message=trim((string)($data['testo']??''));
        if($name==='' || mb_strlen($name)>160 || !preg_match('/^(?:\+?39)?3\d{8,9}$/D',$phone) || mb_strlen($message)>1000 || empty($data['privacy_letta']))throw new PatientBookingException('dati_non_validi');
        if($this->db->table('patient_booking_contacts')->where('author_id',$actor)->where('created_at >=',gmdate('Y-m-d H:i:s',time()-3600))->countAllResults()>=3)throw new PatientBookingException('troppe_richieste',429);
        $iv=bin2hex(random_bytes(8));$payload=json_encode(['nome'=>$name,'cellulare'=>$phone,'testo'=>$message],JSON_THROW_ON_ERROR);
        $this->db->query('INSERT INTO patient_booking_contacts (author_id,data_encrypted,vector_id,created_at) VALUES (?,HEX(AES_ENCRYPT(?,@key_str,?)),?,UTC_TIMESTAMP())',[$actor,$payload,$iv,$iv]);
    }
    public function contacts(): array
    {
        $rows=$this->db->query("SELECT id,created_at,CONVERT(AES_DECRYPT(UNHEX(data_encrypted),@key_str,vector_id) USING utf8mb4) plain FROM patient_booking_contacts WHERE state='pending' ORDER BY id DESC LIMIT 100")->getResultArray();
        return array_map(fn($r)=>['id'=>$r['id'],'created_at'=>$r['created_at']]+(json_decode($r['plain'],true)?:[]),$rows);
    }
    public function closeContact(int $id): void
    {
        $this->db->table('patient_booking_contacts')->where('id',$id)->update(['state'=>'closed']);
    }
    public function absence(int $appointment,int $actor,bool $absent): void
    {
        $row=$this->db->query("SELECT b.booking_ref,COALESCE(a.ora_fine_appuntamento,s.ora_fine) ends_at,a.stato FROM patient_online_bookings b JOIN dap12_agenda_appuntamenti a ON a.id_appuntamento=b.id_appuntamento JOIN dap11_agenda_slot s ON s.id_slot=a.id_slot WHERE b.id_appuntamento=?",[$appointment])->getRowArray();
        if(!$row)throw new PatientBookingException('non_trovata',404);
        $now=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s');
        if($row['ends_at']>$now || $row['stato']==='ANNULLATO')throw new PatientBookingException('appuntamento_non_concluso',409);
        if($absent)$this->db->query('INSERT IGNORE INTO patient_booking_absences (booking_ref,created_by,created_at) VALUES (?,?,UTC_TIMESTAMP())',[$row['booking_ref'],$actor]);
        else $this->db->table('patient_booking_absences')->where('booking_ref',$row['booking_ref'])->delete();
    }
}
