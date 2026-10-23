<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Tenant/device-bound SMS proof. No phone numbers or usable codes are stored in this table. */
final class PatientBookingVerification
{
    public function __construct(private BaseConnection $db,private int $tenant,private int $actor,private array $context,private ?\Closure $transport=null) {}

    public static function smsConfigured(int $tenant): bool
    {
        try{return !empty((new SmsProviderConfigurationService())->resolveRuntime($tenant)['configured']);}
        catch(\Throwable $e){return false;}
    }
    public static function phone(string $phone): string
    {
        $phone=preg_replace('/[\s()-]/','',$phone);
        if(!preg_match('/^(?:\+?39)?3\d{8,9}$/D',$phone))throw new PatientBookingException('dati_non_validi');
        return '+39'.preg_replace('/^(?:\+?39)?(?=3\d{8,9}$)/','',$phone);
    }
    private function ready(): void
    {
        if(!$this->db->tableExists('patient_booking_otp'))throw new PatientBookingException('schema_non_installato',503);
        if($this->tenant<=0 || $this->actor<=0 || empty($this->context['device']) || empty($this->context['ip']))throw new PatientBookingException('verifica_richiesta',401);
    }
    private function hash(string $value): string{return (new PatientBookingRecords($this->db))->fingerprint($value);}
    private static function midnight(): string
    {
        return (new \DateTimeImmutable('today',new \DateTimeZone('Europe/Rome')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    public function available(array $workflow): bool
    {
        return $this->db->tableExists('patient_booking_otp') && ($this->transport || self::smsConfigured($this->tenant))
            && $this->db->table('patient_booking_otp')->where('tenant_id',$this->tenant)->where('created_at >=',self::midnight())->countAllResults()<(int)$workflow['verifica']['sms_tetto_giornaliero'];
    }
    private function lock(): string
    {
        $key='booking-otp-'.substr(hash('sha256',$this->db->database.'|'.$this->tenant),0,48);
        if((int)($this->db->query('SELECT GET_LOCK(?,5) acquired',[$key])->getRowArray()['acquired']??0)!==1)throw new PatientBookingException('riprova',503);
        return $key;
    }
    public function send(array $body,array $workflow): array
    {
        $this->ready();
        if(($body['metodo']??'')!=='sms' || !in_array('sms',$workflow['verifica']['metodi'],true))throw new PatientBookingException('metodo_non_attivo',422);
        if(!empty($body['trappola']))throw new PatientBookingException('dati_non_validi');
        $phone=self::phone((string)($body['cellulare']??''));$hash=$this->hash($phone);
        if(!$this->transport && !self::smsConfigured($this->tenant))throw new PatientBookingException('sms_non_configurato',503);
        $lock=$this->lock();$id=bin2hex(random_bytes(32));$code=(string)random_int(100000,999999);$now=time();
        try{
            if($this->db->table('patient_booking_blocks')->where('contact_hash',$hash)->countAllResults())throw new PatientBookingException('prenotazioni_sospese',403);
            // Attempts (including failed deliveries) consume the local rate limits.
            $last=$this->db->table('patient_booking_otp')->where('tenant_id',$this->tenant)->where('phone_hash',$hash)->orderBy('created_at','DESC')->get(1)->getRowArray();
            if($last && $last['state']==='locked' && strtotime($last['expires_at'].' UTC')>$now)throw new PatientBookingException('tentativi_bloccati',423,['bloccato_fino'=>strtotime($last['expires_at'].' UTC')*1000]);
            foreach(['phone_hash'=>[$hash,3],'device_hash'=>[$this->hash($this->context['device']),5],'ip_hash'=>[$this->hash($this->context['ip']),20]] as $field=>[$digest,$limit]){
                $n=$this->db->table('patient_booking_otp')->where('tenant_id',$this->tenant)->where($field,$digest)->where('created_at >=',gmdate('Y-m-d H:i:s',$now-3600))->countAllResults();
                if($n>=$limit || ($field==='phone_hash' && $last && strtotime($last['created_at'].' UTC')>$now-60))throw new PatientBookingException('troppi_codici',429);
            }
            if($this->db->table('patient_booking_otp')->where('tenant_id',$this->tenant)->where('created_at >=',self::midnight())->countAllResults()>=(int)$workflow['verifica']['sms_tetto_giornaliero'])throw new PatientBookingException('tetto_sms',422);
            $this->db->table('patient_booking_otp')->where('tenant_id',$this->tenant)->where('author_id',$this->actor)->where('device_hash',$this->hash($this->context['device']))->whereIn('state',['sent','verified'])->update(['state'=>'replaced','verified_until'=>null]);
            $this->db->table('patient_booking_otp')->insert(['id'=>$id,'tenant_id'=>$this->tenant,'author_id'=>$this->actor,'phone_hash'=>$hash,'device_hash'=>$this->hash($this->context['device']),'ip_hash'=>$this->hash($this->context['ip']),'code_hash'=>$this->hash($id.'|'.$code),'state'=>'sending','created_at'=>gmdate('Y-m-d H:i:s',$now),'expires_at'=>gmdate('Y-m-d H:i:s',$now+600)]);
        }finally{$this->db->query('SELECT RELEASE_LOCK(?)',[$lock]);}
        // Reuse the tenant's configured provider and its monthly SMS quota.
        $message='AmbulatorioFacile: il codice per verificare il tuo numero e '.$code.'. Scade tra 10 minuti. Non condividerlo.';
        try{$result=$this->transport?($this->transport)($phone,$message):(new AppointmentNotificationChannelService())->send('sms',$phone,$message,['tenant_id'=>$this->tenant,'client_message_id'=>'booking-otp-'.$id]);}
        catch(\Throwable $e){$result=['ok'=>false];}
        $ok=!empty($result['ok']);
        $this->db->table('patient_booking_otp')->where('id',$id)->update(['state'=>$ok?'sent':'failed']);
        if(!$ok)throw new PatientBookingException('invio_sms_non_riuscito',503);
        return ['ok'=>true,'scade_il'=>($now+600)*1000,'rimanda_da'=>($now+60)*1000];
    }
    public function verify(array $body,array $workflow): array
    {
        $this->ready();
        if(!in_array('sms',$workflow['verifica']['metodi'],true))throw new PatientBookingException('metodo_non_attivo',422);
        $phone=self::phone((string)($body['cellulare']??''));$code=(string)($body['codice']??'');
        $lock=$this->lock();
        try{
            $row=$this->db->table('patient_booking_otp')->where('tenant_id',$this->tenant)->where('author_id',$this->actor)->where('phone_hash',$this->hash($phone))->where('device_hash',$this->hash($this->context['device']))->orderBy('created_at','DESC')->get(1)->getRowArray();
            if($row && $row['state']==='locked' && strtotime($row['expires_at'].' UTC')>time())throw new PatientBookingException('tentativi_bloccati',423,['bloccato_fino'=>strtotime($row['expires_at'].' UTC')*1000]);
            if(!$row || $row['state']!=='sent' || strtotime($row['expires_at'].' UTC')<=time())throw new PatientBookingException('codice_scaduto',410);
            if(!preg_match('/^\d{6}$/D',$code) || !hash_equals($row['code_hash'],$this->hash($row['id'].'|'.$code))){
                $attempts=(int)$row['attempts']+1;$update=['attempts'=>$attempts];
                if($attempts>=5)$update+=['state'=>'locked','expires_at'=>gmdate('Y-m-d H:i:s',time()+900)];
                $this->db->table('patient_booking_otp')->where('id',$row['id'])->update($update);
                throw new PatientBookingException($attempts>=5?'tentativi_bloccati':'codice_errato',$attempts>=5?423:422,['tentativi_rimasti'=>max(0,5-$attempts),'bloccato_fino'=>(time()+900)*1000]);
            }
            $this->db->table('patient_booking_otp')->where('id',$row['id'])->update(['state'=>'verified','code_hash'=>str_repeat('0',64),'verified_until'=>gmdate('Y-m-d H:i:s',time()+1800)]);
            return ['proof'=>$row['id'],'cellulare'=>substr($phone,3),'paziente'=>null,'metodo'=>'sms'];
        }finally{$this->db->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public function proof(string $phone=''): array
    {
        $this->ready();$id=(string)($this->context['verification']??'');
        $row=preg_match('/^[a-f0-9]{64}$/D',$id)?$this->db->table('patient_booking_otp')->where('id',$id)->where('tenant_id',$this->tenant)->where('author_id',$this->actor)->where('device_hash',$this->hash($this->context['device']))->where('state','verified')->where('verified_until >',gmdate('Y-m-d H:i:s'))->get()->getRowArray():null;
        if(!$row || ($phone!=='' && !hash_equals($row['phone_hash'],$this->hash(self::phone($phone)))))throw new PatientBookingException('verifica_richiesta',401);
        return $row;
    }
    public function revoke(): void
    {
        if(!$this->db->tableExists('patient_booking_otp'))return;
        $this->db->table('patient_booking_otp')->where('id',(string)($this->context['verification']??''))->where('tenant_id',$this->tenant)->where('author_id',$this->actor)->update(['state'=>'revoked','verified_until'=>null]);
    }
}
