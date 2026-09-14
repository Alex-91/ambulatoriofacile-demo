<?php
namespace App\Services\Pacs;

use App\Services\{ClinicalAccessPolicy,ClinicalVault,TenantPatientLookupService};
use CodeIgniter\Database\BaseConnection;

/** Local requests and deliberate exports. An export is never a delivery/execution acknowledgment. */
class PacsOrderService
{
    use PacsOrderWorkflow;

    private ClinicalAccessPolicy $access;
    private ClinicalVault $vault;
    public function __construct(
        private BaseConnection $db,private int $tenantId,private int $userId,
        private ?PacsService $pacs=null,private ?PacsFeatureService $features=null,
        private ?TenantPatientLookupService $patients=null
    ) {
        $this->access=new ClinicalAccessPolicy($db,$userId,$tenantId);
        $this->vault=new ClinicalVault($tenantId);
        $this->features ??=new PacsFeatureService();
        $this->pacs ??=new PacsService($db,$tenantId,$userId);
        $this->patients ??=new TenantPatientLookupService();
    }
    private function guard(int $patientId,bool $doctor=false): void
    {
        $this->features->assertEnabled($this->tenantId);
        $doctor ? $this->access->assertDoctor($patientId) : $this->access->assertPatient($patientId,true);
        if (!self::schemaReady($this->db)) throw new PacsException('Richieste diagnostiche da inizializzare per questo spazio.');
    }
    public static function schemaReady(BaseConnection $db): bool
    {
        if (!$db->tableExists('pacs_orders') || !$db->tableExists('pacs_audit')) return false;
        foreach (['appointment_id','appointment_hash','scheduled_date','workflow_stage','report_entry_id','study_link_id'] as $field) {
            if (!$db->fieldExists($field,'pacs_orders')) return false;
        }
        return $db->fieldExists('order_revision','pacs_audit');
    }
    private function readable(array $row): bool
    {
        if ((int)$row['owner_user_id']===$this->userId) return true;
        if (!$this->db->tableExists('clinical_consents')) return false;
        $last=$this->db->table('clinical_consents')->where('id_client',$row['patient_id'])->where('kind','dossier')->orderBy('id','DESC')->get(1)->getRowArray();
        return $last && $last['decision']==='granted' && !empty($last['evidence_object_id']);
    }
    public function listing(int $patientId,int $page=1): array
    {
        $this->guard($patientId);
        $page=max(1,min(10000,$page));
        $query=$this->db->table('pacs_orders')->where('tenant_id',$this->tenantId)->where('patient_id',$patientId);
        if (!$this->readable(['owner_user_id'=>0,'patient_id'=>$patientId])) $query->where('owner_user_id',$this->userId);
        $rows=$query->orderBy('created_at','DESC')->orderBy('id','DESC')->get(26,($page-1)*25)->getResultArray();
        $more=count($rows)>25; $rows=array_slice($rows,0,25);
        foreach ($rows as &$row) $row=$this->open($row);
        $this->audit($patientId,'pacs_orders_read','');
        return ['rows'=>$rows,'page'=>$page,'more'=>$more,'doctor'=>$this->access->actor()['role']===1,'user_id'=>$this->userId];
    }
    public function read(int $patientId,string $id): array
    {
        $row=$this->row($patientId,$id);
        $this->audit($patientId,'pacs_order_read',$id);
        return $this->open($row);
    }
    private function row(int $patientId,string $id,bool $owner=false): array
    {
        $this->guard($patientId,$owner);
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new PacsException('Richiesta non disponibile.');
        $row=$this->db->table('pacs_orders')->where('tenant_id',$this->tenantId)->where('patient_id',$patientId)->where('id',$id)->get()->getRowArray();
        if (!$row || !$this->readable($row) || ($owner && (int)$row['owner_user_id']!==$this->userId)) throw new PacsException('Richiesta non disponibile per il profilo corrente.');
        return $row;
    }
    private function open(array $row): array
    {
        $row['payload']=json_decode($this->vault->open('pacs-order:'.$row['id'],$row['payload_enc']),true,32,JSON_THROW_ON_ERROR);
        unset($row['payload_enc'],$row['request_hash'],$row['request_key']);
        return $row;
    }
    private function payload(int $patientId,array $input): array
    {
        $patient=$this->patients->getPatientByIdForTenant($this->tenantId,$patientId);
        if (!$patient) throw new PacsException('Anagrafica del paziente non disponibile.');
        return ModalityWorklist::payload($input,$patient);
    }
    private function context(array $row,bool $lock=false): array
    {
        if ($lock && $this->db->DBDriver==='MySQLi') $this->db->query('SELECT id FROM pacs_patient_bindings WHERE id = ? AND tenant_id = ? FOR UPDATE',[$row['binding_id'],$this->tenantId]);
        $context=$this->pacs->orderContext((int)$row['patient_id'],$row['binding_id']);
        if ((int)$context['binding']['revision']!==(int)$row['binding_revision']) throw new PacsException('Identità PACS cambiata: annullare questa richiesta e crearne una nuova.');
        return $context;
    }
    public function create(int $patientId,string $bindingId,array $input,string $requestKey): string
    {
        $this->guard($patientId,true);
        if (!preg_match('/^[a-f0-9]{32}$/D',$requestKey)) throw new PacsException('Riaprire il modulo di richiesta.');
        $appointment=(int)($input['appointment_id'] ?? 0)>0 ? $this->appointmentDraft($patientId,(int)$input['appointment_id']) : [];
        $input=array_replace($input,$appointment);
        $context=$this->pacs->orderContext($patientId,$bindingId);
        $payload=$this->payload($patientId,$input)+['pacs_patient_id'=>$context['identity']['patient_id'],'pacs_issuer'=>$context['identity']['issuer']];
        $hashParts=[$patientId,$bindingId,$context['binding']['revision'],$payload];
        if ($appointment) $hashParts[]=$appointment;
        $hash=PacsIntegrity::hash(json_encode($hashParts,JSON_THROW_ON_ERROR));
        $existing=$this->db->table('pacs_orders')->where('tenant_id',$this->tenantId)->where('owner_user_id',$this->userId)->where('request_key',$requestKey)->get()->getRowArray();
        if ($existing) {
            if (!hash_equals($existing['request_hash'],$hash)) throw new PacsException('Questo modulo è già stato utilizzato con dati differenti. Aprire una nuova richiesta.');
            return $existing['id'];
        }
        $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6]) & 15)|64); $bytes[8]=chr((ord($bytes[8]) & 63)|128);
        $id=bin2hex($bytes);
        $row=['id'=>$id,'tenant_id'=>$this->tenantId,'patient_id'=>$patientId,'owner_user_id'=>$this->userId,
            'binding_id'=>$bindingId,'binding_revision'=>(int)$context['binding']['revision'],
            'appointment_id'=>$appointment['appointment_id'] ?? null,'appointment_hash'=>$appointment['appointment_hash'] ?? null,
            'scheduled_date'=>substr($payload['scheduled_at'],0,10),'workflow_stage'=>'awaiting',
            'request_key'=>$requestKey,'request_hash'=>$hash,'accession'=>'AF'.strtoupper(bin2hex(random_bytes(7))),
            'study_uid'=>ModalityWorklist::uid($id),'state'=>'draft','revision'=>1,
            'payload_enc'=>$this->vault->seal('pacs-order:'.$id,json_encode($payload,JSON_THROW_ON_ERROR)),
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')];
        $this->transaction(function () use ($row,$patientId,$id,$payload) {
            $this->lockContext($row,$payload);
            if (!$this->db->table('pacs_orders')->insert($row)) throw new PacsException('Richiesta non salvata.');
            $this->audit($patientId,'pacs_order_created',$id,1);
        });
        return $id;
    }
    public function update(int $patientId,string $id,array $input,int $revision): void
    {
        $row=$this->row($patientId,$id,true);
        if ($row['state']!=='draft') throw new PacsException('Solo le bozze possono essere modificate.');
        $appointment=!empty($row['appointment_id']) ? $this->appointmentDraft($patientId,(int)$row['appointment_id']) : [];
        $input=array_replace($input,$appointment);
        $saved=$this->open($row)['payload'];
        $payload=$this->payload($patientId,$input)+['pacs_patient_id'=>$saved['pacs_patient_id'],'pacs_issuer'=>$saved['pacs_issuer']];
        $this->transaction(function () use ($row,$payload,$revision,$appointment) {
            $this->lockContext(array_replace($row,$appointment),$payload);
            $this->change($row,$revision,['appointment_hash'=>$appointment['appointment_hash'] ?? null,'scheduled_date'=>substr($payload['scheduled_at'],0,10),'payload_enc'=>$this->vault->seal('pacs-order:'.$row['id'],json_encode($payload,JSON_THROW_ON_ERROR))],'pacs_order_updated');
        });
    }
    public function approve(int $patientId,string $id,int $revision,bool $confirmed): void
    {
        $row=$this->row($patientId,$id,true);
        if (!$confirmed || $row['state']!=='draft') throw new PacsException('Verificare i dati e confermare una richiesta in bozza.');
        $payload=$this->open($row)['payload'];
        if (!$this->canonicalMatches($patientId,$payload)) throw new PacsException('Anagrafica modificata: aggiornare e verificare la bozza prima della conferma.');
        $this->transaction(function () use ($row,$revision,$payload) {
            $this->lockContext($row,$payload);
            $this->change($row,$revision,['state'=>'ready'],'pacs_order_approved');
        });
    }
    public function cancel(int $patientId,string $id,int $revision): void
    {
        $row=$this->row($patientId,$id,true);
        if ($row['state']==='cancelled') throw new PacsException('Richiesta già annullata.');
        // Cancellation must remain possible after the PACS binding is revoked or changed.
        $this->transaction(fn()=>$this->change($row,$revision,['state'=>'cancelled'],'pacs_order_cancelled'));
    }
    public function export(int $patientId,string $id,int $revision,string $format): array
    {
        $row=$this->row($patientId,$id,true);
        if ($row['state']!=='ready' || !in_array($row['workflow_stage'],['awaiting','accepted'],true) || (int)$row['revision']!==$revision) throw new PacsException('Esportazione riservata a richieste confermate e aggiornate.');
        if (!in_array($format,['dicom','json'],true)) throw new PacsException('Formato non valido.');
        $payload=$this->open($row)['payload'];
        if (!$this->canonicalMatches($patientId,$payload)) throw new PacsException('Anagrafica modificata dopo la conferma: annullare la richiesta e ricrearla con i dati corretti.');
        $this->checkAppointment($row);
        $context=$this->context($row);
        $dataset=ModalityWorklist::dataset($row,$payload,$context['identity']);
        $bytes=$format==='dicom' ? ModalityWorklist::file($dataset,$row['study_uid']) : json_encode($dataset,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        $this->transaction(function () use ($row,$revision,$bytes,$payload) {
            $this->lockContext($row,$payload);
            // Increment the revision so parallel export/cancel operations cannot both consume a stale form.
            $this->change($row,$revision,['last_exported_at'=>gmdate('Y-m-d H:i:s'),'last_export_sha256'=>hash('sha256',$bytes)],'pacs_order_exported');
        });
        return ['bytes'=>$bytes,'mime'=>$format==='dicom' ? 'application/dicom' : 'application/dicom+json',
            'name'=>'worklist-'.$row['accession'].($format==='dicom' ? '.wl' : '.json')];
    }
    private function canonicalMatches(int $patientId,array $payload): bool
    {
        $current=$this->payload($patientId,$payload);
        return array_intersect_key($payload,$current)===$current;
    }
    private function lockContext(array $row,array $payload): void
    {
        $this->context($row,true);
        $this->checkAppointment($row,true);
        if ($this->db->DBDriver==='MySQLi') $this->db->query('SELECT id_client FROM dap02_clients WHERE id_client = ? FOR UPDATE',[$row['patient_id']]);
        if (!$this->canonicalMatches((int)$row['patient_id'],$payload)) throw new PacsException('Anagrafica modificata durante l’operazione. Aggiornare la richiesta.');
    }
    private function change(array $row,int $revision,array $data,string $event): void
    {
        $this->guard((int)$row['patient_id'],true);
        $this->db->table('pacs_orders')->where('tenant_id',$this->tenantId)->where('id',$row['id'])->where('owner_user_id',$this->userId)
            ->where('revision',$revision)->where('state',$row['state'])->update($data+['revision'=>$revision+1,'updated_at'=>gmdate('Y-m-d H:i:s')]);
        if ($this->db->affectedRows()!==1) throw new PacsException('Richiesta aggiornata da un’altra sessione. Riaprire la pagina.');
        $this->audit((int)$row['patient_id'],$event,$row['id'],$revision+1);
    }
    private function audit(int $patientId,string $event,string $id,?int $revision=null): void
    {
        if (!$this->db->table('pacs_audit')->insert(['id'=>bin2hex(random_bytes(16)),'tenant_id'=>$this->tenantId,'patient_id'=>$patientId,
            'actor_user_id'=>$this->userId,'event'=>$event,'entity_id'=>$id,'order_revision'=>$revision,'recorded_at'=>gmdate('Y-m-d H:i:s')])) throw new PacsException('Registro richieste non disponibile.');
    }
    private function transaction(callable $action): void
    {
        if (!$this->db->transBegin()) throw new PacsException('Richiesta non salvata.');
        try {
            $action();
            if (!$this->db->transStatus() || !$this->db->transCommit()) throw new PacsException('Richiesta non salvata.');
        } catch (\Throwable $e) {
            $this->db->transRollback();
            if ($e instanceof PacsException) throw $e;
            throw new PacsException('Richiesta non salvata o già aggiornata. Riaprire la pagina.');
        }
    }
}
