<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

final class PatientBookingNotifications
{
    public function __construct(private BaseConnection $db,private array $tenant,private array $settings,private ?\Closure $sender=null) {}
    public function run(string $patientUrl,int $limit=20): array
    {
        $result=['sent'=>0,'failed'=>0,'skipped'=>0];
        $lock='patient-mail-'.substr(hash('sha256',$this->db->database),0,40);
        if(!(int)$this->db->query('SELECT GET_LOCK(?,0) AS acquired',[$lock])->getRowArray()['acquired'])return $result;
        try {
            $agenda=new PatientBookingAgenda($this->db,$this->settings,0);$records=new PatientBookingRecords($this->db);$cat=$agenda->catalog();
            $rows=$this->db->table('patient_booking_outbox')->where('state','pending')->orderBy('id')->get($limit)->getResultArray();
            foreach($rows as $row){
                $workflow=PatientBookingWorkflow::resolve($this->settings['config']);
                if($records->counters()['email_inviate']>=$workflow['protezioni']['email_tetto_giornaliero'])break;
                [$event,$target]=explode('.',$row['event']);$p=$agenda->detail($row['booking_ref'],true);$c=$this->settings['config'];
                $type=($target==='patient'?['requested'=>'P1','confirmed'=>'P2','rejected'=>'P3','reminder'=>'P4','moved'=>'P5','cancelled'=>'P6']:['requested'=>'S2','confirmed'=>'S1','moved'=>'S3','cancelled'=>'S4','anomaly'=>'S5'])[$event]??null;
                $to=$target==='patient'?$p['email']:$c['email'];
                $metadata=$records->details($p['ref']);$hashes=array_values(array_filter([$metadata['phone_hash']??null,$metadata['email_hash']??null]));
                $p['metadata']=$metadata['data']??[];
                $p['stats']=$records->counters();
                $blocked=$hashes && $this->db->table('patient_booking_blocks')->whereIn('contact_hash',$hashes)->countAllResults()>0;
                // Do not deliver an obsolete confirmation after a later cancellation/move.
                if(!$type || !$to || $blocked || (int)$row['sequence']!==(int)$p['ics_sequence']){$state='skipped';$result['skipped']++;}
                else {
                    $this->db->table('patient_booking_outbox')->where('id',$row['id'])->update(['state'=>'sending','attempts'=>(int)$row['attempts']+1]);
                    try {
                        $message=PatientBookingEmail::render($type,$p,$this->tenant,$c,$cat,$patientUrl.'?ref='.rawurlencode($p['ref']));
                        $ics=in_array($type,['P2','P4','P5','P6'],true)?PatientBookingEmail::calendar($p,$cat):null;
                        $ok=$this->sender?($this->sender)($to,$message,$ics):PatientBookingEmail::deliver((int)$this->tenant['id_tenant'],$to,$message,$ics);
                        $state=$ok?'sent':'failed';$result[$state]++;
                    }catch(\Throwable $e){$state='failed';$result['failed']++;}
                }
                $this->db->table('patient_booking_outbox')->where('id',$row['id'])->update(['state'=>$state,'sent_at'=>$state==='sent'?gmdate('Y-m-d H:i:s'):null]);
            }
            return $result;
        }finally{$this->db->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public function reminders(): void
    {
        $now=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Rome'));
        $rows=$this->db->query("SELECT b.booking_ref FROM patient_online_bookings b JOIN patient_booking_details d ON d.booking_ref=b.booking_ref JOIN dap12_agenda_appuntamenti a ON a.id_appuntamento=b.id_appuntamento JOIN dap11_agenda_slot s ON s.id_slot=a.id_slot WHERE d.status='confermata' AND a.stato<>'ANNULLATO' AND s.ora_inizio BETWEEN ? AND ?",[$now->format('Y-m-d H:i:s'),$now->modify('+24 hours')->format('Y-m-d H:i:s')])->getResultArray();
        foreach($rows as $r)(new PatientBookingRecords($this->db))->event($r['booking_ref'],'reminder');
    }
}
