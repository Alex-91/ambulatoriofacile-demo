<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Tenant-scoped encounter workflow. Simulations use separate tables and an isolated test runtime. */
final class ClinicalJourneyService
{
    public const STAGES=['booked'=>'Prenotato','accepted'=>'Accettato','in_progress'=>'In esecuzione','performed'=>'Eseguito'];
    public function __construct(private BaseConnection $db,private int $tenantId,private int $userId) {}
    public static function isTest(): bool
    {
        try { self::assertTest(); return true; } catch (\RuntimeException) { return false; }
    }
    private function table(string $suffix=''): string
    {
        return (self::isTest()?'clinical_journey_test':'clinical_journeys').$suffix;
    }
    public function assertReady(): void
    {
        (new ClinicalFeatureService())->assertEnabledForTenant($this->tenantId);
        foreach ([$this->table(),$this->table('_events')] as $table) {
            if (!$this->db->tableExists($table)) throw new \RuntimeException('Percorso esame da inizializzare. Il responsabile può preparare lo spazio dalla configurazione clinica.');
        }
    }
    public static function assertTest(): void
    {
        $host=(string)env('AF_TEST_DB_HOST');
        if ((string)env('AF_CLINICAL_JOURNEY_TEST')!=='1' || (string)env('AF_NAVIGATION_TEST')!=='1'
            || $host!=='uapmyovmgml4ov24y4d94tao' || (string)env('DB_HOST')!==$host || (string)env('PLATFORM_DB_HOST')!==$host) {
            throw new \RuntimeException('Percorso disponibile soltanto nell’ambiente di collaudo isolato.');
        }
    }
    public function read(int $id): array
    {
        $this->assertReady();
        $a=$this->db->table('dap12_agenda_appuntamenti a')->select('a.*,s.data_slot,s.ora_inizio')
            ->join('dap11_agenda_slot s','s.id_slot=a.id_slot')->where('a.id_appuntamento',$id)->get()->getRowArray();
        if (!$a || !$a['id_client'] || strtoupper((string)$a['stato'])==='ANNULLATO') throw new \RuntimeException('Appuntamento non disponibile o annullato.');
        $access=new ClinicalAccessPolicy($this->db,$this->userId,$this->tenantId);
        $actor=$access->assertPatient((int)$a['id_client'],false);
        $allowed=[$actor['staff_id']];
        if ($actor['role']===3 || $actor['role']===2) {
            $table=$actor['role']===3?'dap14_seg_dot':'dap15_inf_dot';
            $field=$actor['role']===3?'id_seg':'id_inf';
            $allowed=array_column($this->db->table($table)->where($field,$actor['staff_id'])->get()->getResultArray(),'id_dot');
        }
        if ($actor['role']!==4 && !in_array((int)$a['id_dot'],$access->agendaDoctorIds($allowed),true)) throw new \RuntimeException('Appuntamento non assegnato al tuo profilo.');
        $doctor=$actor['role']===1 && in_array((int)$a['id_dot'],$access->agendaDoctorIds([$actor['staff_id']]),true);
        $row=$this->db->table($this->table())->where('tenant_id',$this->tenantId)->where('appointment_id',$id)->get()->getRowArray();
        if ($row && !self::isTest() && ((int)$row['patient_id']!==(int)$a['id_client'] || (int)$row['doctor_id']!==(int)$a['id_dot'])) throw new \RuntimeException('Paziente o medico dell’appuntamento sono cambiati dopo l’accettazione. Consultare il referto originale dalla cartella e verificare l’appuntamento con il responsabile.');
        $row ??=['tenant_id'=>$this->tenantId,'appointment_id'=>$id,'stage'=>'booked','revision'=>0,'report_id'=>null,'signature_simulated_at'=>null,'delivery_simulated_at'=>null];
        $row+=['signature_simulated_at'=>null,'delivery_simulated_at'=>null];
        $report=$doctor && $row['report_id'] ? (new ClinicalRecordService($this->db,$this->tenantId,$this->userId))->entry((int)$a['id_client'],(int)$row['report_id']) : null;
        if ($report && ((int)$report['appointment_id']!==$id || (int)$report['author_user_id']!==$this->userId)) throw new \RuntimeException('Referto non coerente con l’appuntamento.');
        $patient=(new TenantPatientLookupService())->getPatientByIdForTenant($this->tenantId,(int)$a['id_client']);
        (new \App\Libraries\DatabaseConfig())->setEncryptionConfig($this->db);
        $staff=$this->db->query('SELECT CAST(AES_DECRYPT(UNHEX(nome),@key_str,vector_id) AS CHAR) AS nome, CAST(AES_DECRYPT(UNHEX(cognome),@key_str,vector_id) AS CHAR) AS cognome FROM dap03_personale WHERE legacy_id_dot = ?',[$a['id_dot']])->getRowArray();
        $doctorName=trim(($staff['nome']??'').' '.($staff['cognome']??''));
        return compact('a','row','actor','doctor','report','patient','doctorName');
    }
    public function act(int $id,array $input): void
    {
        $state=$this->read($id); $r=$state['row']; $a=$state['a']; $action=(string)($input['action']??'');
        if (str_starts_with($action,'simulate_')) self::assertTest();
        if ((int)($input['revision']??-1)!==(int)$r['revision']) throw new \RuntimeException('La scheda è cambiata. Ricaricala prima di continuare.');
        if (!$state['doctor'] && $action!=='accept') throw new \RuntimeException('Questa operazione è riservata al medico dell’appuntamento.');
        $this->db->transBegin();
        try {
            if (!$r['revision']) {
                $new=['tenant_id'=>$this->tenantId,'appointment_id'=>$id,'stage'=>'booked','revision'=>0];
                if (!self::isTest()) $new+=['patient_id'=>(int)$a['id_client'],'doctor_id'=>(int)$a['id_dot']];
                $this->db->table($this->table())->insert($new);
            }
            // Claim the row before writing a report or a simulated result.
            $this->db->table($this->table())->where('tenant_id',$this->tenantId)->where('appointment_id',$id)->where('revision',$r['revision'])->update(['revision'=>(int)$r['revision']+1]);
            if ($this->db->affectedRows()!==1) throw new \RuntimeException('Operazione concorrente. Ricaricare la scheda.');
            $changes=[]; $service=new ClinicalRecordService($this->db,$this->tenantId,$this->userId);
            $steps=['accept'=>['booked','accepted'],'start'=>['accepted','in_progress'],'finish'=>['in_progress','performed']];
            if (isset($steps[$action])) {
                if ($r['stage']!==$steps[$action][0]) throw new \RuntimeException('Passaggio non disponibile in questo stato.');
                $changes['stage']=$steps[$action][1];
            } elseif ($action==='save') {
                if ($r['stage']!=='performed' || ($state['report'] && $state['report']['state']!=='draft')) throw new \RuntimeException('Concludere l’esame prima di compilare la bozza.');
                $changes['report_id']=$service->saveEntry((int)$a['id_client'],[
                    'id'=>(int)$r['report_id'],'revision'=>(int)($input['entry_revision']??0),'kind'=>'report',
                    'title'=>(string)($a['tipo_visita_label']?:'Referto esame'),'body'=>(string)($input['body']??''),
                    'occurred_at'=>(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Rome')))->format('Y-m-d\TH:i'), 'appointment_id'=>$id]);
            } elseif ($action==='finalize') {
                if (!$state['report'] || $state['report']['state']!=='draft') throw new \RuntimeException('Salvare prima la bozza.');
                $service->finalize((int)$a['id_client'],(int)$r['report_id'],(int)($input['entry_revision']??0),$state['patient']);
            } elseif ($action==='simulate_signature') {
                if (!$state['report'] || $state['report']['state']!=='final' || $r['signature_simulated_at']) throw new \RuntimeException('Generare prima il PDF definitivo.');
                $changes['signature_simulated_at']=gmdate('Y-m-d H:i:s');
            } elseif ($action==='simulate_delivery') {
                if (!$r['signature_simulated_at'] || $r['delivery_simulated_at']) throw new \RuntimeException('Completare prima la prova di firma.');
                $changes['delivery_simulated_at']=gmdate('Y-m-d H:i:s');
            } else { throw new \RuntimeException('Operazione non valida.'); }
            $changes['updated_at']=gmdate('Y-m-d H:i:s');
            $this->db->table($this->table())->where('tenant_id',$this->tenantId)->where('appointment_id',$id)->update($changes);
            $this->db->table($this->table('_events'))->insert(['tenant_id'=>$this->tenantId,'appointment_id'=>$id,'actor_user_id'=>$this->userId,'action'=>$action,'recorded_at'=>gmdate('Y-m-d H:i:s')]);
            if (!$this->db->transStatus()) throw new \RuntimeException('Salvataggio non riuscito.');
            $this->db->transCommit();
        } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
    }
    public function events(int $id): array
    {
        $this->read($id);
        return $this->db->table($this->table('_events'))->where('tenant_id',$this->tenantId)->where('appointment_id',$id)->orderBy('id','DESC')->get(30)->getResultArray();
    }
}

