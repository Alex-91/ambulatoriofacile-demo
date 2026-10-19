<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class WhatsAppBookingService
{
    private BaseConnection $db;
    public function __construct(?BaseConnection $db=null,private ?\Closure $agendaFactory=null,private ?WhatsAppGatewayClient $gateway=null){$this->db=$db??Database::connect('platform');}
    private function agenda(int $tenant,string $phone=''): WhatsAppBookingAgenda {return $this->agendaFactory?($this->agendaFactory)($tenant,$phone):WhatsAppBookingAgenda::forTenant($tenant,$phone);}
    private function table(string $suffix){return $this->db->table('platform_whatsapp_booking_'.$suffix);}
    public function ready(): bool
    {
        foreach(['configs','sessions','receipts','requests'] as $suffix)if(!$this->db->tableExists('platform_whatsapp_booking_'.$suffix))return false;
        return true;
    }
    public function enabled(int $tenant): bool
    {
        if(!$this->db->tableExists('platform_whatsapp_chatbots'))return false;
        $row=$this->db->table('platform_whatsapp_chatbots')->select('is_enabled')->where('id_tenant',$tenant)->get(1)->getRowArray();
        return (int)($row['is_enabled']??0)===1;
    }
    public function config(int $tenant): array
    {
        if(!$this->ready())return WhatsAppBookingConfig::defaults();
        $row=$this->table('configs')->where('id_tenant',$tenant)->get(1)->getRowArray();
        return WhatsAppBookingConfig::normalize(json_decode($row['config_json']??'{}',true)?:[]);
    }
    public function clearSessions(int $tenant): void
    {
        if($this->ready())$this->table('sessions')->where('id_tenant',$tenant)->delete();
    }
    public function save(int $tenant,array $input): void
    {
        if(!$this->ready())throw new \RuntimeException('Applicare prima la migrazione delle prenotazioni WhatsApp.');
        if(!$this->enabled($tenant))throw new \RuntimeException('Chatbot non abilitato per questo spazio.');
        $c=WhatsAppBookingConfig::normalize($input);
        $agenda=$this->agenda($tenant);$agenda->assertBookingReady();
        WhatsAppBookingConfig::validate($c,$agenda->catalog());$c['configured']=true;
        $this->upsert('configs',['id_tenant'=>$tenant],['config_json'=>$this->json($c)]);
    }
    private function json(array $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private function upsert(string $table,array $key,array $data): void
    {
        $data['updated_at']=gmdate('Y-m-d H:i:s');
        if($this->table($table)->where($key)->countAllResults())$ok=$this->table($table)->where($key)->update($data);
        else $ok=$this->table($table)->insert($key+$data+['created_at'=>$data['updated_at']]);
        if(!$ok)throw new \RuntimeException('Salvataggio della conversazione non riuscito.');
    }

    /** Serialized per tenant/phone, with durable reply receipts and a 30-minute session. */
    public function incoming(int $tenant,string $phone,string $messageKey,string $text): ?array
    {
        if(!$this->ready()||!$this->enabled($tenant))return null;
        $lock='wa-book-'.substr(hash('sha256',$tenant.'|'.$phone),0,48);
        $this->lock($lock);
        try {
            $receipt=$this->table('receipts')->where(['id_tenant'=>$tenant,'message_key'=>$messageKey])->get(1)->getRowArray();
            if($receipt)return json_decode($receipt['result_json'],true);
            $c=$this->config($tenant);if(!$c['configured'])return null;
            $row=$this->table('sessions')->where(['id_tenant'=>$tenant,'phone_key'=>$phone])->get(1)->getRowArray();
            $stored=json_decode($row['state_json']??'{}',true)?:[];
            $fingerprint=hash('sha256',$this->json($c));
            $valid=$row && $row['expires_at']>gmdate('Y-m-d H:i:s') && ($stored['config_hash']??'')===$fingerprint;
            $state=$valid?($stored['state']??[]):[];
            $starts=in_array(mb_strtolower(trim($text)),$c['keywords'],true);
            if(!$starts && in_array($state['step']??'',['done','pending'],true))return null;
            if(!$valid && !empty($stored['state']) && !$starts){
                $result=['state'=>[],'reply'=>'La sessione è scaduta o la configurazione è cambiata. Per ricominciare scrivi '.$c['keywords'][0].'.'];
            }else{
                if(!$state && !$starts)return null;
                $result=(new WhatsAppBookingEngine())->advance($c,$state,$text,$this->agenda($tenant,$phone));
            }
            if($result===null)return null;
            $this->db->transBegin();
            try {
                if(!empty($result['request']))$this->upsert('requests',['id_tenant'=>$tenant,'booking_key'=>$result['state']['booking_key']],[
                    'phone_key'=>$phone,'kind'=>$result['request'],'status'=>'pending','state_json'=>$this->json($result['state']),'config_json'=>$this->json($c),
                ]);
                $this->upsert('sessions',['id_tenant'=>$tenant,'phone_key'=>$phone],[
                    'state_json'=>$this->json(['state'=>$result['state'],'config_hash'=>$fingerprint]),'expires_at'=>gmdate('Y-m-d H:i:s',time()+1800),
                ]);
                $result=['reply'=>$result['reply'],'appointment_id'=>$result['appointment_id']??0];
                $this->upsert('receipts',['id_tenant'=>$tenant,'message_key'=>$messageKey],['result_json'=>$this->json($result)]);
                if(!$this->db->transStatus())throw new \RuntimeException('Conversazione non salvata.');
                $this->db->transCommit();
            }catch(\Throwable $e){$this->db->transRollback();throw $e;}
            return $result;
        }finally{$this->unlock($lock);}
    }

    public function requests(int $tenant): array
    {
        if(!$this->ready())return [];
        $rows=$this->table('requests')->where('id_tenant',$tenant)->orderBy('id','DESC')->get(100)->getResultArray();
        foreach($rows as &$r)$r['state']=json_decode($r['state_json'],true)?:[];
        return $rows;
    }

    public function resolve(int $tenant,int $id,string $action,int $user): void
    {
        if(!$this->enabled($tenant))throw new \RuntimeException('Chatbot non abilitato.');
        if(!in_array($action,['approve','reject','close','retry'],true))throw new \InvalidArgumentException('Azione non valida.');
        $lock='wa-request-'.$tenant.'-'.$id;$this->lock($lock);
        try{
            $r=$this->table('requests')->where(['id_tenant'=>$tenant,'id'=>$id])->get(1)->getRowArray();
            if(!$r)throw new \RuntimeException('Richiesta non trovata.');
            if($action==='retry'){
                if(!$r['reply_text']||$r['reply_sent_at'])return;
            }else{
                if($r['status']!=='pending')throw new \RuntimeException('Richiesta già gestita.');
                if($action==='approve'&&$r['kind']!=='approval')throw new \RuntimeException('Questa richiesta richiede assistenza della segreteria.');
                $data=['handled_by'=>$user,'updated_at'=>gmdate('Y-m-d H:i:s')];
                if($action==='approve'){
                    $c=json_decode($r['config_json'],true);$s=json_decode($r['state_json'],true);
                    $data['appointment_id']=$this->agenda($tenant,$r['phone_key'])->book($c,$s);
                    $data['status']='approved';$data['reply_text']='La segreteria ha confermato l’appuntamento del '.date('d/m/Y',strtotime($s['day'])).' alle '.$s['slot']['label'].'.';
                }elseif($action==='reject'){$data['status']='rejected';$data['reply_text']='La segreteria non ha potuto accogliere la richiesta. Contatta lo studio per concordare un altro appuntamento.';}
                else{$data['status']='closed';$data['reply_text']=null;}
                if(!$this->table('requests')->where(['id_tenant'=>$tenant,'id'=>$id])->update($data))throw new \RuntimeException('Aggiornamento richiesta non riuscito.');
                $r=array_merge($r,$data);
            }
            if($r['reply_text']){
                $this->gateway??=new WhatsAppGatewayClient();
                $sent=$this->gateway->sendText($tenant,$r['phone_key'],$r['reply_text']);
                if(empty($sent['ok']))throw new \RuntimeException('Richiesta aggiornata, ma messaggio non inviato. Usa “Riprova notifica”.');
                $this->table('requests')->where(['id_tenant'=>$tenant,'id'=>$id])->update(['reply_sent_at'=>gmdate('Y-m-d H:i:s')]);
            }
        }finally{$this->unlock($lock);}
    }

    private function lock(string $name): void
    {
        $row=$this->db->query('SELECT GET_LOCK(?, 10) AS acquired',[$name])->getRowArray();
        if((int)($row['acquired']??0)!==1)throw new \RuntimeException('Conversazione in elaborazione. Riprova tra poco.');
    }
    private function unlock(string $name): void {$this->db->query('SELECT RELEASE_LOCK(?)',[$name]);}
}
