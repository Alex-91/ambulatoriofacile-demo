<?php
namespace App\Services\Pacs;
use App\Services\{ClinicalAccessPolicy,ClinicalVault};
use CodeIgniter\Database\BaseConnection;

/** The complete configuration is encrypted; secrets are never sent back to the form. */
final class PacsManagedProfiles
{
    public function __construct(private BaseConnection $db,private int $tenantId,private ?PacsFeatureService $features=null) {}
    public function all(): array
    {
        if (!$this->db->tableExists('pacs_managed_profiles')) return [];
        $profiles=[];
        foreach ($this->db->table('pacs_managed_profiles')->where('tenant_id',$this->tenantId)->get()->getResultArray() as $row) {
            $id=$row['profile_id'];
            $p=json_decode((new ClinicalVault($this->tenantId))->open('pacs-profile:'.$id,$row['config_enc']),true,16,JSON_THROW_ON_ERROR);
            if (!is_array($p) || ($p['id'] ?? '')!==$id) throw new PacsException('Collegamento salvato non leggibile.');
            $p['revision']=(int)$row['revision']; $p['managed']=true;
            $profiles[$id]=$p;
        }
        return $profiles;
    }
    public function editable(): array
    {
        $rows=$this->all();
        foreach ($rows as &$p) { $p['credentials_present']=PacsProfiles::credentialsReady($p); unset($p['credentials']); }
        return $rows;
    }
    public function save(int $actor,array $input,array $reservedIds=[]): void
    {
        ($this->features ?? new PacsFeatureService())->assertEnabled($this->tenantId);
        if ((new ClinicalAccessPolicy($this->db,$actor,$this->tenantId))->actor()['role']!==4) throw new PacsException('Configurazione riservata al responsabile dello spazio.');
        if (!$this->db->tableExists('pacs_managed_profiles') || !$this->db->tableExists('clinical_setup_audit')) throw new PacsException('Preparare prima lo spazio.');
        $id=trim((string)($input['id'] ?? ''));
        if (in_array($id,$reservedIds,true)) throw new PacsException('Questo identificativo è già gestito nella configurazione del server.');
        $current=$this->all(); $old=$current[$id] ?? null; $revision=(int)($input['revision'] ?? -1);
        if ($revision!==($old['revision'] ?? 0)) throw new PacsException('Configurazione aggiornata da un altro operatore. Ricaricare la pagina.');
        if (!$old && count($current)+count($reservedIds)>=20) throw new PacsException('Sono consentiti al massimo 20 collegamenti per spazio.');
        $p=[];
        foreach (['id','label','qido_url','wado_url','viewer_url','auth','identity_namespace'] as $key) $p[$key]=trim((string)($input[$key] ?? ''));
        $p['enabled']=($input['enabled'] ?? '')==='1';
        $p['download_enabled']=($input['download_enabled'] ?? '')==='1';
        if (strlen($p['identity_namespace'])>100 || preg_match('/[\x00-\x1f]/',$p['identity_namespace'])) throw new PacsException('Autorità identificativi non valida.');
        foreach (['qido_url','wado_url','viewer_url'] as $key) if (strlen($p[$key])>2048) throw new PacsException('Indirizzo troppo lungo.');
        // Reuse the canonical URL/viewer/auth validation, without accepting filesystem or environment references from the browser.
        $validation=$p+['username_env'=>'PACS_MANAGED_USER','password_env'=>'PACS_MANAGED_PASSWORD','token_env'=>'PACS_MANAGED_TOKEN'];
        (new PacsProfiles(['tenants'=>[(string)$this->tenantId=>[$validation]]]))->forTenant($this->tenantId);
        $sameDestination=$old && $old['auth']===$p['auth'] && $old['qido_url']===$p['qido_url'] && $old['wado_url']===$p['wado_url'];
        $credentials=[];
        foreach ($p['auth']==='basic' ? ['username','password'] : ($p['auth']==='bearer' ? ['token'] : []) as $key) {
            $value=(string)($input[$key] ?? '');
            if ($value==='' && $sameDestination) $value=(string)($old['credentials'][$key] ?? '');
            if ($value==='' || strlen($value)>4096 || preg_match('/[\r\n\x00]/',$value) || ($key==='username' && str_contains($value,':'))) throw new PacsException('Compilare le credenziali. Se cambia la destinazione occorre reinserirle.');
            $credentials[$key]=$value;
        }
        $p['credentials']=$credentials;
        $cipher=(new ClinicalVault($this->tenantId))->seal('pacs-profile:'.$id,json_encode($p,JSON_THROW_ON_ERROR));
        $this->db->transBegin();
        try {
            $values=['config_enc'=>$cipher,'revision'=>$revision+1,'updated_by'=>$actor,'updated_at'=>gmdate('Y-m-d H:i:s')];
            $table=$this->db->table('pacs_managed_profiles');
            $ok=$old ? $table->where('tenant_id',$this->tenantId)->where('profile_id',$id)->where('revision',$revision)->update($values)
                : $table->insert($values+['tenant_id'=>$this->tenantId,'profile_id'=>$id]);
            if (!$ok || $this->db->affectedRows()!==1) throw new PacsException('Salvataggio concorrente. Ricaricare la pagina.');
            $this->db->table('clinical_setup_audit')->insert(['tenant_id'=>$this->tenantId,'actor_id'=>$actor,'event'=>'pacs_profile_saved','entity_id'=>$id,'revision'=>$revision+1,'created_at'=>gmdate('Y-m-d H:i:s')]);
            if (!$this->db->transStatus()) throw new PacsException('Configurazione non salvata.');
            $this->db->transCommit();
        } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
    }
}
