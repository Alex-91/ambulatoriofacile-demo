<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Read-only presentation of the exact generated dataset, never a master clinical-role override. */
final class ClinicalFixturePresentation
{
    public function __construct(private BaseConnection $db, private int $tenantId, private int $userId,
        private ?ClinicalVault $vault = null, private ?string $manifestPath = null)
    {
        $this->vault ??= new ClinicalVault($tenantId);
        $this->manifestPath ??= WRITEPATH.'studio-fixture-20261007/manifest.json';
    }
    public function read(int $patientId): ?array
    {
        if ($this->tenantId !== 4 || $this->db->getDatabase() !== 'af_ambiente_di_test' || !is_file($this->manifestPath) || is_link($this->manifestPath)) return null;
        $actor=(new ClinicalAccessPolicy($this->db,$this->userId,$this->tenantId))->assertPatient($patientId,false);
        if ($actor['role'] !== 4) return null;
        try { $m=json_decode(file_get_contents($this->manifestPath),true,32,JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
        if (empty($m['complete']) || !in_array($patientId,$m['patients'] ?? [],true)) return null;
        $ids=[];
        foreach ($m['entries'] ?? [] as $key=>$id) if (str_starts_with((string)$key,$patientId.':') && is_int($id) && $id>0) $ids[]=$id;
        $authors=array_column($m['doctors'] ?? [],'user');
        if (!$ids || !$authors) return null;
        $entries=$this->db->table('clinical_entries')->where('id_client',$patientId)->whereIn('id',$ids)
            ->whereIn('author_user_id',$authors)->whereIn('state',['draft','final'])->orderBy('occurred_at','DESC')->get()->getResultArray();
        $objects=[];
        foreach ($entries as &$e) {
            $e['content']=json_decode($this->vault->open('entry:'.$patientId,$e['payload_enc']),true,512,JSON_THROW_ON_ERROR);
            unset($e['payload_enc']);
            if (!$e['pdf_object_id']) continue;
            $o=$this->db->table('clinical_objects')->where('id_client',$patientId)->where('entry_id',$e['id'])
                ->where('id',$e['pdf_object_id'])->where('category','original')->where('created_by',$e['author_user_id'])->get()->getRowArray();
            if ($o) { $o['name']=$this->vault->open('name:'.$o['id'],$o['name_enc']); $objects[]=$o; }
        }
        unset($e);
        $appointments=[];
        if (!empty($m['appointments'])) $appointments=$this->db->table('dap12_agenda_appuntamenti a')->select('a.*,s.data_slot,s.ora_inizio,s.ora_fine')
            ->join('dap11_agenda_slot s','s.id_slot=a.id_slot')->where('a.id_client',$patientId)->whereIn('a.id_appuntamento',$m['appointments'])
            ->orderBy('s.ora_inizio','DESC')->get()->getResultArray();
        $this->db->table('clinical_audit')->insert(['id_client'=>$patientId,'actor_user_id'=>$this->userId,'event'=>'fixture_chart_viewed','entity_id'=>(string)$patientId,'recorded_at'=>date('Y-m-d H:i:s')]);
        return ['clinical'=>true,'read_only'=>true,'entries'=>$entries,'total'=>count($entries),'page'=>1,
            'shared'=>false,'objects'=>$objects,'appointments'=>$appointments,'fse_reports'=>[],'fse_enabled'=>false];
    }
    public function download(int $patientId,string $objectId): ?array
    {
        $chart=$this->read($patientId);
        foreach ($chart['objects'] ?? [] as $o) if (hash_equals($o['id'],$objectId)) {
            return ['bytes'=>$this->vault->get($o['id'],$o['sha256']),'mime'=>$o['mime'],'name'=>$o['name']];
        }
        return null;
    }
}