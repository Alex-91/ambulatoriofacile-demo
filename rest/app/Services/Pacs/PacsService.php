<?php
namespace App\Services\Pacs;

use App\Services\{ClinicalAccessPolicy,ClinicalRecordService,ClinicalVault};
use CodeIgniter\Database\BaseConnection;

/** Patient-level authorization is repeated before every remote operation. No name-only matching. */
class PacsService
{
    private ClinicalAccessPolicy $access;
    private ClinicalVault $vault;
    public function __construct(
        private BaseConnection $db, private int $tenantId, private int $userId,
        private ?PacsProfiles $profiles=null, private ?PacsFeatureService $features=null,
        private ?PacsTransport $transport=null
    ) {
        $this->access=new ClinicalAccessPolicy($db,$userId,$tenantId);
        $this->vault=new ClinicalVault($tenantId);
        $this->profiles ??=new PacsProfiles(null,$db);
        $this->features ??=new PacsFeatureService();
    }
    public function orders(): PacsOrderService
    { return new PacsOrderService($this->db,$this->tenantId,$this->userId,$this,$this->features); }
    public function orderContext(int $patientId,string $bindingId): array
    {
        $this->guard($patientId);
        $binding=$this->binding($patientId,$bindingId);
        $this->client($binding);
        return ['binding'=>$binding,'identity'=>$this->identity($binding)];
    }
    private function guard(int $patientId,bool $doctor=false): void
    {
        $this->features->assertEnabled($this->tenantId);
        $doctor ? $this->access->assertDoctor($patientId) : $this->access->assertPatient($patientId,true);
        foreach (['pacs_patient_bindings','pacs_study_links','pacs_audit'] as $table) {
            if (!$this->db->tableExists($table)) throw new PacsException('Modulo PACS da inizializzare per questo spazio.');
        }
    }
    public function overview(int $patientId): array
    {
        $this->guard($patientId);
        $bindings=$this->db->table('pacs_patient_bindings')->where('tenant_id',$this->tenantId)->where('patient_id',$patientId)->get()->getResultArray();
        $visible=[];
        foreach ($bindings as $b) if ($this->canRead($patientId,$b)) {
            $b['identity']=$this->identity($b);
            $visible[$b['id']]=$b;
        }
        $links=$this->db->table('pacs_study_links')->where('tenant_id',$this->tenantId)->where('patient_id',$patientId)->where('active',1)->orderBy('created_at','DESC')->get(200)->getResultArray();
        $valid=[];
        foreach ($links as $link) {
            $b=$visible[$link['binding_id']] ?? null;
            if (!$b || !(int)$b['active'] || (int)$b['revision']!==(int)$link['binding_revision'] || !$this->canRead($patientId,$link)) continue;
            if (!$this->entryReadable($patientId,$link)) continue;
            $link['study']=json_decode($this->vault->open('pacs-link:'.$link['id'],$link['metadata_enc']),true,32,JSON_THROW_ON_ERROR);
            $valid[]=$link;
        }
        $profiles=[];
        foreach ($this->profiles->forTenant($this->tenantId) as $p) if ($p['enabled']) $profiles[$p['id']]=['id'=>$p['id'],'label'=>$p['label']];
        $this->audit($patientId,'pacs_overview','');
        return ['bindings'=>array_values($visible),'links'=>$valid,'profiles'=>$profiles,'doctor'=>$this->access->actor()['role']===1,'user_id'=>$this->userId,'recent_limit'=>count($links)>=200];
    }
    public function bind(int $patientId,string $profileId,string $externalId,string $issuer,int $revision,bool $confirmed): string
    {
        $this->guard($patientId,true);
        $profile=$this->profiles->get($this->tenantId,$profileId);
        if (!$confirmed) throw new PacsException('Confermare la verifica dell’identità del paziente sul PACS.');
        $identity=['patient_id'=>DicomWebClient::identifier($externalId),'issuer'=>DicomWebClient::identifier($issuer)];
        $hash=PacsIntegrity::hash(json_encode([$this->tenantId,$profileId,$identity],JSON_THROW_ON_ERROR));
        $existing=$this->db->table('pacs_patient_bindings')->where('tenant_id',$this->tenantId)->where('patient_id',$patientId)->where('profile_id',$profileId)->get()->getRowArray();
        if ($existing && ((int)$existing['owner_user_id']!==$this->userId || (int)$existing['revision']!==$revision)) throw new PacsException('Collegamento modificato o gestito da un altro medico. Riaprire la pagina.');
        if (!$existing && $revision!==0) throw new PacsException('Collegamento non più disponibile.');
        $id=$existing['id'] ?? bin2hex(random_bytes(16));
        $data=['profile_hash'=>PacsProfiles::fingerprint($profile),'identity_hash'=>$hash,'identity_enc'=>$this->vault->seal('pacs-binding:'.$id,json_encode($identity,JSON_THROW_ON_ERROR)),
            'revision'=>$revision+1,'active'=>1,'updated_at'=>gmdate('Y-m-d H:i:s')];
        $this->transaction(function () use ($existing,$data,$patientId,$profileId,$id,$revision) {
            if ($existing) {
                $this->db->table('pacs_patient_bindings')->where('id',$id)->where('tenant_id',$this->tenantId)->where('revision',$revision)->update($data);
                if ($this->db->affectedRows()!==1) throw new PacsException('Collegamento aggiornato da un altro operatore.');
            } else {
                $this->db->table('pacs_patient_bindings')->insert($data+['id'=>$id,'tenant_id'=>$this->tenantId,'patient_id'=>$patientId,'profile_id'=>$profileId,'owner_user_id'=>$this->userId,'created_at'=>gmdate('Y-m-d H:i:s')]);
            }
            $this->audit($patientId,'pacs_identity_confirmed',$id);
        });
        return $id;
    }
    public function unbind(int $patientId,string $id,int $revision): void
    {
        $this->guard($patientId,true);
        $b=$this->binding($patientId,$id);
        if ((int)$b['owner_user_id']!==$this->userId) throw new PacsException('Modifica riservata al medico che ha creato il collegamento.');
        $this->transaction(function () use ($id,$revision,$patientId) {
            $this->db->table('pacs_patient_bindings')->where('id',$id)->where('tenant_id',$this->tenantId)->where('revision',$revision)->update(['active'=>0,'revision'=>$revision+1,'updated_at'=>gmdate('Y-m-d H:i:s')]);
            if ($this->db->affectedRows()!==1) throw new PacsException('Collegamento aggiornato da un altro operatore.');
            $this->audit($patientId,'pacs_identity_disabled',$id);
        });
    }
    public function search(int $patientId,string $bindingId,int $page=1): array
    {
        $this->guard($patientId);
        $b=$this->binding($patientId,$bindingId);
        $identity=$this->identity($b);
        $this->audit($patientId,'pacs_search_requested',$bindingId);
        $result=$this->client($b)->studies($identity['patient_id'],$identity['issuer'],$page);
        $this->currentBinding($patientId,$b);
        return $result+['binding'=>$b];
    }
    public function link(int $patientId,string $bindingId,string $uid,int $revision,int $entryId=0,?string $expectedAccession=null,?callable $onLinked=null): string
    {
        $this->guard($patientId,true);
        $b=$this->binding($patientId,$bindingId);
        if ((int)$b['revision']!==$revision) throw new PacsException('Identità PACS aggiornata. Ripetere la ricerca.');
        if ($entryId>0) (new ClinicalRecordService($this->db,$this->tenantId,$this->userId))->entry($patientId,$entryId);
        $identity=$this->identity($b);
        $this->audit($patientId,'pacs_link_requested',$bindingId);
        $study=$this->client($b)->verifiedStudy($uid,$identity['patient_id'],$identity['issuer']);
        if ($expectedAccession!==null && !hash_equals($expectedAccession,(string)$study['accession'])) throw new PacsException('Numero richiesta dello studio non corrispondente. Nessun collegamento eseguito.');
        $existing=$this->db->table('pacs_study_links')->where('tenant_id',$this->tenantId)->where('binding_id',$bindingId)
            ->where('binding_revision',$revision)->where('study_uid',$uid)->where('owner_user_id',$this->userId)->get()->getRowArray();
        if ($existing) {
            if ((int)$existing['entry_id']!==$entryId) throw new PacsException('Studio già associato a un documento diverso. Rimuovere il collegamento e creare una nuova verifica dell’identità per modificarne l’associazione.');
            $this->transaction(function () use ($existing,$b,$patientId,$onLinked) {
                $this->currentBinding($patientId,$b,true);
                $this->db->table('pacs_study_links')->where('id',$existing['id'])->where('tenant_id',$this->tenantId)->update(['active'=>1]);
                $this->audit($patientId,'pacs_study_reconfirmed',$existing['id']);
                if ($onLinked) $onLinked($existing['id']);
            });
            return $existing['id'];
        }
        $id=bin2hex(random_bytes(16));
        $this->transaction(function () use ($b,$patientId,$uid,$study,$id,$entryId,$onLinked) {
            $this->currentBinding($patientId,$b,true);
            $this->db->table('pacs_study_links')->insert([
                'id'=>$id,'tenant_id'=>$this->tenantId,'patient_id'=>$patientId,'binding_id'=>$b['id'],'binding_revision'=>$b['revision'],
                'study_uid'=>$uid,'metadata_enc'=>$this->vault->seal('pacs-link:'.$id,json_encode($study,JSON_THROW_ON_ERROR)),
                'entry_id'=>$entryId>0 ? $entryId : null,'owner_user_id'=>$this->userId,'active'=>1,'created_at'=>gmdate('Y-m-d H:i:s'),
            ]);
            $this->audit($patientId,'pacs_study_linked',$id);
            if ($onLinked) $onLinked($id);
        });
        return $id;
    }
    public function unlink(int $patientId,string $id): void
    {
        $this->guard($patientId,true);
        $link=$this->linked($patientId,$id);
        if ((int)$link['owner_user_id']!==$this->userId) throw new PacsException('Rimozione riservata al medico che ha collegato lo studio.');
        $this->transaction(function () use ($id,$patientId) {
            $this->db->table('pacs_study_links')->where('id',$id)->where('tenant_id',$this->tenantId)->update(['active'=>0]);
            $this->audit($patientId,'pacs_study_unlinked',$id);
        });
    }
    public function details(int $patientId,string $id): array
    {
        [$link,$b,$client,$study]=$this->remote($patientId,$id,'pacs_study_viewed');
        $series=$client->series($link['study_uid']);
        $this->currentBinding($patientId,$b);
        $this->linked($patientId,$id);
        $profile=$this->profiles->get($this->tenantId,$b['profile_id']);
        return ['link'=>$link,'study'=>$study,'series'=>$series,'viewer'=>$profile['viewer_url']!=='','download'=>$profile['download_enabled']];
    }
    public function instances(int $patientId,string $id,string $series,int $page=1): array
    {
        [$link,$b,$client]=$this->remote($patientId,$id,'pacs_instances_viewed');
        $result=$client->instances($link['study_uid'],$series,$page);
        $this->currentBinding($patientId,$b);
        $this->linked($patientId,$id);
        return $result;
    }
    public function viewer(int $patientId,string $id): string
    {
        [$link,$b,$client]=$this->remote($patientId,$id,'pacs_viewer_opened');
        $this->currentBinding($patientId,$b);
        $this->linked($patientId,$id);
        return $client->viewer($link['study_uid']);
    }
    public function download(int $patientId,string $id,string $series,string $instance): array
    {
        [$link,$b,$client]=$this->remote($patientId,$id,'pacs_download_requested');
        $identity=$this->identity($b);
        $result=$client->download($link['study_uid'],$series,$instance,$identity['patient_id'],$identity['issuer']);
        $this->currentBinding($patientId,$b);
        $this->linked($patientId,$id);
        $this->audit($patientId,'pacs_download_completed',$id);
        return $result;
    }
    private function remote(int $patientId,string $id,string $event): array
    {
        $this->guard($patientId);
        $link=$this->linked($patientId,$id);
        $b=$this->binding($patientId,$link['binding_id']);
        if ((int)$b['revision']!==(int)$link['binding_revision']) throw new PacsException('Identità PACS aggiornata: ricollegare lo studio.');
        $client=$this->client($b); $identity=$this->identity($b);
        $this->audit($patientId,$event,$id);
        $study=$client->verifiedStudy($link['study_uid'],$identity['patient_id'],$identity['issuer']);
        if ($this->db->tableExists('pacs_orders') && $this->db->fieldExists('study_link_id','pacs_orders')) {
            $orders=$this->db->table('pacs_orders')->select('accession')->where('tenant_id',$this->tenantId)->where('patient_id',$patientId)
                ->where('study_link_id',$id)->get()->getResultArray();
            foreach ($orders as $order) {
                if (!hash_equals($order['accession'],(string)$study['accession'])) throw new PacsException('Numero richiesta dello studio modificato sul PACS. Verificare il collegamento.');
            }
        }
        $this->currentBinding($patientId,$b);
        return [$link,$b,$client,$study];
    }
    private function linked(int $patientId,string $id): array
    {
        $row=$this->db->table('pacs_study_links')->where('id',$id)->where('tenant_id',$this->tenantId)->where('patient_id',$patientId)->where('active',1)->get()->getRowArray();
        if (!$row || !$this->canRead($patientId,$row) || !$this->entryReadable($patientId,$row)) throw new PacsException('Studio non disponibile per questo paziente e profilo.');
        return $row;
    }
    private function entryReadable(int $patientId,array $row): bool
    {
        if (empty($row['entry_id'])) return true;
        try { (new ClinicalRecordService($this->db,$this->tenantId,$this->userId))->entry($patientId,(int)$row['entry_id']); return true; }
        catch (\RuntimeException) { return false; }
    }
    private function binding(int $patientId,string $id): array
    {
        $row=$this->db->table('pacs_patient_bindings')->where('id',$id)->where('tenant_id',$this->tenantId)->where('patient_id',$patientId)->where('active',1)->get()->getRowArray();
        if (!$row || !$this->canRead($patientId,$row)) throw new PacsException('Identità PACS non disponibile per questo paziente e profilo.');
        return $row;
    }
    private function currentBinding(int $patientId,array $expected,bool $lock=false): void
    {
        $this->guard($patientId);
        // Lock binding only after network I/O; updates and link creation serialize on the same row.
        if ($lock && $this->db->DBDriver==='MySQLi') $this->db->query('SELECT id FROM pacs_patient_bindings WHERE id = ? AND tenant_id = ? FOR UPDATE',[$expected['id'],$this->tenantId]);
        $now=$this->binding($patientId,$expected['id']);
        if ((int)$now['revision']!==(int)$expected['revision']) throw new PacsException('Identità PACS modificata durante l’operazione. Riprovare.');
        $this->client($now);
    }
    private function canRead(int $patientId,array $row): bool
    {
        if ((int)$row['owner_user_id']===$this->userId) return true;
        if (!$this->db->tableExists('clinical_consents')) return false;
        $last=$this->db->table('clinical_consents')->where('id_client',$patientId)->where('kind','dossier')->orderBy('id','DESC')->get(1)->getRowArray();
        return $last && $last['decision']==='granted' && !empty($last['evidence_object_id']);
    }
    private function identity(array $b): array
    { return json_decode($this->vault->open('pacs-binding:'.$b['id'],$b['identity_enc']),true,16,JSON_THROW_ON_ERROR); }
    private function client(array $b): DicomWebClient
    {
        $profile=$this->profiles->get($this->tenantId,$b['profile_id']);
        if (!hash_equals($b['profile_hash'],PacsProfiles::fingerprint($profile))) throw new PacsException('Configurazione PACS modificata. Riconfermare l’identità del paziente prima di proseguire.');
        return new DicomWebClient($profile,$this->transport);
    }
    private function audit(int $patientId,string $event,string $id): void
    {
        if (!$this->db->table('pacs_audit')->insert(['id'=>bin2hex(random_bytes(16)),'tenant_id'=>$this->tenantId,'patient_id'=>$patientId,'actor_user_id'=>$this->userId,'event'=>$event,'entity_id'=>$id,'recorded_at'=>gmdate('Y-m-d H:i:s')])) throw new PacsException('Registro PACS non disponibile.');
    }
    private function transaction(callable $action): void
    {
        if (!$this->db->transBegin()) throw new PacsException('Operazione PACS non avviata.');
        try {
            $action();
            if (!$this->db->transStatus() || !$this->db->transCommit()) throw new PacsException('Operazione PACS non completata.');
        } catch (\Throwable $e) {
            $this->db->transRollback();
            if ($e instanceof PacsException) throw $e;
            throw new PacsException('Operazione PACS non completata: collegamento già presente o dati aggiornati. Riaprire la pagina.');
        }
    }
}
