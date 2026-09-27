<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use DomainException;

/** Personnel is the source; catalog IDs remain stable for financial history. No schema changes. */
final class PolyclinicPersonnelDirectory
{
    public function __construct(private BaseConnection $db) {}

    public function available(): bool
    {
        return $this->db->tableExists('dap03_personale') && $this->db->tableExists('pc_catalog') && $this->db->tableExists('pc_settings') && $this->db->tableExists('pc_audit');
    }

    public static function enabledInCurrentSpace(): bool
    {
        try {
            $tenant=(new TenantCatalogService())->resolveCurrentRuntimeTenant();
            return (bool)array_filter(BillingCapabilities::resolve((int)($tenant['id_tenant']??0)));
        } catch (\Throwable $e) { return false; }
    }

    private function staff(): array
    {
        if (!$this->available()) return [];
        $select='p.id_personale,p.tipo,p.is_active';
        foreach (['legacy_id_dot','show_in_agenda'] as $field) $select.=$this->db->fieldExists($field,'dap03_personale')?',p.'.$field:($field==='show_in_agenda'?',1 AS show_in_agenda':',0 AS legacy_id_dot');
        if ($this->db->DBDriver!=='MySQLi') {
            return array_column($this->db->table('dap03_personale p')->select($select.',p.nome,p.cognome',false)->get()->getResultArray(),null,'id_personale');
        }
        // Reading legacy encrypted names must not change the caller's encoding or encryption session.
        $previous=$this->db->query('SELECT @@character_set_client AS cs_client, @@character_set_connection AS cs_connection, @@character_set_results AS cs_results, @@collation_connection AS collation_name, @@block_encryption_mode AS encryption_mode, @key_str AS encryption_key, @init_vector AS encryption_iv')->getRowArray();
        try {
            $charset=$previous['cs_client']==='utf8mb3'?'utf8':$previous['cs_client'];
            (new \App\Libraries\DatabaseConfig())->setEncryptionConfig($this->db,$charset);
            $crypto=new \App\Libraries\Crypto_helper();
            $select.=','.$crypto->decrypt('p.nome').','.$crypto->decrypt('p.cognome');
            return array_column($this->db->table('dap03_personale p')->select($select,false)->get()->getResultArray(),null,'id_personale');
        } finally {
            $this->db->query('SET character_set_client=?, character_set_connection=?, character_set_results=?, collation_connection=?, block_encryption_mode=?, @key_str=?, @init_vector=?',[
                $previous['cs_client'],$previous['cs_connection'],$previous['cs_results'],$previous['collation_name'],$previous['encryption_mode'],$previous['encryption_key'],$previous['encryption_iv'],
            ]);
        }
    }

    /** Read-only projection, including virtual IDs for personnel never used in a prestation. */
    public function doctors(array $rows): array
    {
        if (!$this->available()) return $rows;
        $staff=$this->staff(); $used=[]; $out=[];
        $agendaCounts=[];
        foreach ($staff as $p) if ((int)$p['legacy_id_dot']>0) $agendaCounts[(int)$p['legacy_id_dot']]=($agendaCounts[(int)$p['legacy_id_dot']]??0)+1;
        $legacyCounts=[];
        foreach ($rows as $r) { $d=PolyclinicAdministrationService::data($r); if (empty($d['personnel_id']) && !empty($d['agenda_id'])) $legacyCounts[(int)$d['agenda_id']]=($legacyCounts[(int)$d['agenda_id']]??0)+1; }
        // Explicit links take precedence over any agenda-based compatibility mapping.
        foreach ($rows as $r) { $d=PolyclinicAdministrationService::data($r); if (!empty($d['personnel_id'])) $used[(int)$d['personnel_id']]=true; }
        foreach ($rows as $r) {
            $d=PolyclinicAdministrationService::data($r); $pid=(int)($d['personnel_id']??0);
            if (!$pid && !empty($d['agenda_id']) && ($agendaCounts[(int)$d['agenda_id']]??0)===1 && ($legacyCounts[(int)$d['agenda_id']]??0)===1) {
                foreach ($staff as $p) if ((int)$p['legacy_id_dot']===(int)$d['agenda_id'] && !isset($used[(int)$p['id_personale']])) { $pid=(int)$p['id_personale'];break; }
            }
            if ($pid) {
                $used[$pid]=true; $p=$staff[$pid]??null;
                $d['personnel_id']=$pid;
                if ($p) { $r['name']=trim($p['cognome'].' '.$p['nome']);$d['agenda_id']=(int)$p['legacy_id_dot']; }
                $r['active']=$p && !empty($p['is_active']) && (bool)($d['professional_enabled']??$r['active']) ? 1:0;
            } else $r['name'].=' (da collegare al personale)';
            $r['data']=$d;$r['data_json']=json_encode($d,JSON_THROW_ON_ERROR);$out[]=$r;
        }
        foreach ($staff as $pid=>$p) {
            if (isset($used[$pid]) || !in_array((int)$p['tipo'],[1,2],true) || empty($p['is_active'])) continue;
            $d=['personnel_id'=>(int)$pid,'agenda_id'=>(int)$p['legacy_id_dot'],'professional_enabled'=>true,'branch_ids'=>[]];
            $out[]=['id'=>'staff:'.$pid,'kind'=>'doctor','code'=>'STAFF-'.$pid,'name'=>trim($p['cognome'].' '.$p['nome']),'active'=>1,'version'=>0,'data'=>$d,'data_json'=>json_encode($d,JSON_THROW_ON_ERROR)];
        }
        usort($out,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));return $out;
    }

    /** Called inside the administration transaction; resolve/create only on an explicit write. */
    public function materialize($value): int
    {
        if (str_starts_with((string)$value,'staff:') && !preg_match('/^staff:[1-9][0-9]{0,8}$/D',(string)$value)) throw new DomainException('Identificativo personale non valido.');
        $rows=$this->db->table('pc_catalog')->where('kind','doctor')->get()->getResultArray();
        foreach ($this->doctors($rows) as $r) {
            if ((string)$r['id']!==(string)$value && !(str_starts_with((string)$value,'staff:') && (int)substr((string)$value,6)===(int)($r['data']['personnel_id']??0))) continue;
            if (!$r['active']) throw new DomainException('Professionista non disponibile nel personale.');
            if (!str_starts_with((string)$r['id'],'staff:')) {
                foreach ($rows as $old) if ((int)$old['id']===(int)$r['id'] && !empty($r['data']['personnel_id']) && empty(PolyclinicAdministrationService::data($old)['personnel_id'])) {
                    $this->db->table('pc_catalog')->where('id',$r['id'])->update(['data_json'=>$r['data_json'],'name'=>$r['name'],'version'=>(int)$old['version']+1]);
                }
                return (int)$r['id'];
            }
            $record=['kind'=>'doctor','code'=>$r['code'],'name'=>$r['name'],'data_json'=>$r['data_json'],'active'=>1];
            $this->db->table('pc_catalog')->insert($record);return (int)$this->db->insertID();
        }
        throw new DomainException('Professionista non trovato nello spazio.');
    }

    public function profile(int $pid): array
    {
        if (!$this->available()) return ['available'=>false];
        $staff=$this->staff();if (!isset($staff[$pid])) throw new DomainException('Personale non trovato.');
        $rows=$this->db->table('pc_catalog')->where('kind','doctor')->get()->getResultArray();
        $doctors=$this->doctors($rows);$current=null;$unlinked=[];
        foreach ($doctors as $r) {
            if ((int)($r['data']['personnel_id']??0)===$pid) $current=$r;
            elseif (empty($r['data']['personnel_id'])) $unlinked[]=['id'=>(int)$r['id'],'name'=>$r['name']];
        }
        $names=[];
        foreach ($this->db->table('pc_catalog')->where('kind','branch')->orderBy('name')->get()->getResultArray() as $b) if (in_array((int)$b['id'],array_map('intval',$current['data']['branch_ids']??[]),true)) $names[]=$b['name'];
        return ['available'=>true,'enabled'=>(bool)($current['data']['professional_enabled']??$current['active']??false),'specialties'=>implode(', ',$names),'specialty_ids'=>array_map('intval',$current['data']['branch_ids']??[]),'specialty_options'=>$this->specialtyOptions(),'catalog_id'=>is_numeric($current['id']??null)?(int)$current['id']:0,'legacy_options'=>$unlinked,'agenda_id'=>(int)$staff[$pid]['legacy_id_dot'],'version'=>(int)($current['version']??0)];
    }

    public function specialtyOptions(): array
    {
        if (!$this->available()) return [];
        return array_map(static fn(array $row): array => [
            'id'=>(int)$row['id'], 'name'=>$row['name'], 'active'=>(bool)$row['active'],
        ], $this->db->table('pc_catalog')->select('id,name,active')->where('kind','branch')->orderBy('name')->get()->getResultArray());
    }

    /** IDs belong to this space's catalog. Archived assignments may be retained, never newly added. */
    public function selectedSpecialtyIds(array $input, array $existing=[]): array
    {
        $values=$input['professional_specialty_ids']??[];
        if (!is_array($values) || count($values)>15) throw new DomainException('Seleziona al massimo 15 specialità dall’elenco.');
        $options=array_column($this->specialtyOptions(),null,'id');
        $ids=[];
        foreach ($values as $value) {
            if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value)) throw new DomainException('Seleziona una specialità valida dall’elenco.');
            $id=(int)$value;
            if (!isset($options[$id]) || (!$options[$id]['active'] && !in_array($id,$existing,true))) throw new DomainException('Una specialità non è più disponibile. Aggiorna l’elenco e riprova.');
            $ids[]=$id;
        }
        return array_values(array_unique($ids));
    }

    public static function specialties(string $input): array
    {
        if (mb_strlen($input)>1000) throw new DomainException('Specialità troppo lunghe (massimo 1000 caratteri).');
        $names=array_values(array_unique(array_filter(array_map('trim',explode(',',$input)))));
        if (count($names)>15) throw new DomainException('Indicare al massimo 15 specialità.');
        foreach ($names as $name) if (mb_strlen($name)>190 || preg_match('/[\x00-\x1f]/',$name)) throw new DomainException('Specialità non valida.');
        return $names;
    }

    /** Optional personnel fields: preserve catalog IDs, orders and immutable financial snapshots. */
    public function saveProfile(int $pid,array $input,int $actor=0): void
    {
        if (!$this->available()) throw new DomainException('Configurazione prestazioni non disponibile.');
        $useCatalog=($input['professional_specialties_catalog']??'')==='1';
        // Compatibility for forms opened before the selector was introduced.
        $names=$useCatalog?[]:self::specialties((string)($input['professional_specialties']??''));
        $this->db->transBegin();
        try {
            $this->db->table('pc_settings')->where('name','write_lock')->set('version','version + 1',false)->update();
            if ($this->db->affectedRows()!==1) throw new DomainException('Blocco amministrativo non disponibile.');
            $profile=$this->profile($pid);
            if ((int)($input['professional_version']??0)!==$profile['version']) throw new DomainException('Profilo professionista aggiornato: ricaricare il personale.');
            $id=(int)$profile['catalog_id'];$link=(int)($input['professional_legacy_id']??0);
            if ($link) {
                if ($id && $link!==$id) throw new DomainException('Il personale è già collegato a un professionista.');
                if (!$id && !in_array($link,array_column($profile['legacy_options'],'id'),true)) throw new DomainException('Professionista precedente già collegato o non disponibile.');
                $id=$link;
            }
            $p=$this->staff()[$pid];
            $old=$id?$this->db->table('pc_catalog')->where('id',$id)->get()->getRowArray():null;
            $d=$old?PolyclinicAdministrationService::data($old):[];
            $branchIds=$useCatalog?$this->selectedSpecialtyIds($input,array_map('intval',$d['branch_ids']??[])):[];
            foreach ($names as $name) {
                $b=$this->db->table('pc_catalog')->where('kind','branch')->where('name',$name)->get()->getRowArray();
                if (!$b) {$this->db->table('pc_catalog')->insert(['kind'=>'branch','code'=>'SPEC-'.substr(hash('sha256',mb_strtolower($name)),0,30),'name'=>$name,'data_json'=>'{}','active'=>1]);$branchIds[]=(int)$this->db->insertID();}
                else $branchIds[]=(int)$b['id'];
            }
            $d['personnel_id']=$pid;$d['agenda_id']=(int)$p['legacy_id_dot'];$d['branch_ids']=$branchIds;$d['professional_enabled']=($input['professional_enabled']??'')===''?in_array((int)$p['tipo'],[1,2],true):!empty($input['professional_enabled']);
            $record=['kind'=>'doctor','code'=>$old['code']??'STAFF-'.$pid,'name'=>trim($p['cognome'].' '.$p['nome']),'active'=>$d['professional_enabled'] && !empty($p['is_active'])?1:0,'data_json'=>json_encode($d,JSON_THROW_ON_ERROR),'version'=>(int)($old['version']??0)+1];
            if ($id) $this->db->table('pc_catalog')->where('id',$id)->update($record);
            else {$this->db->table('pc_catalog')->insert($record);$id=(int)$this->db->insertID();}
            $this->db->table('pc_audit')->insert(['actor_id'=>$actor,'action'=>'personnel_link','entity_id'=>$id,'detail_json'=>json_encode(['personnel_id'=>$pid,'linked_previous'=>$link],JSON_THROW_ON_ERROR),'created_at'=>date('Y-m-d H:i:s')]);
            if (!$this->db->transStatus()) throw new DomainException('Profilo professionista non salvato.');
            $this->db->transCommit();
        } catch (\Throwable $e) {$this->db->transRollback();throw $e;}
    }
}
