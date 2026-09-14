<?php
namespace App\Services;
use App\Services\Pacs\{PacsFeatureService,PacsProfiles,PacsDiagnostics,PacsOrderService,PacsException};
use CodeIgniter\Database\BaseConnection;

/** Preparation never changes platform entitlements or creates patient/doctor records. */
final class ClinicalSetupService
{
    public function __construct(private BaseConnection $db,private int $tenantId,
        private ?ClinicalFeatureService $clinical=null,private ?PacsFeatureService $pacs=null)
    { $this->clinical ??=new ClinicalFeatureService(); $this->pacs ??=new PacsFeatureService(); }
    public function assertMaster(int $actor): void
    {
        $this->clinical->assertEnabledForTenant($this->tenantId);
        if ((new ClinicalAccessPolicy($this->db,$actor,$this->tenantId))->actor()['role']!==4) throw new PacsException('Configurazione riservata al responsabile dello spazio.');
    }
    public function initialize(int $actor): void
    {
        $this->assertMaster($actor);
        foreach (['dap02_clients','dap01_users','dap03_personale'] as $table) if (!$this->db->tableExists($table)) throw new PacsException('Anagrafiche di base non disponibili. Contattare l’assistenza.');
        $vault=new ClinicalVault($this->tenantId); $sample=bin2hex(random_bytes(16));
        if (!hash_equals($sample,$vault->open('setup',$vault->seal('setup',$sample)))) throw new PacsException('Cifratura non disponibile.');
        $migrations=[
            '2026-09-12-160001_CreateClinicalRecords'=>'CreateClinicalRecords',
            '2026-09-14-090001_CreatePersonnelAccessBlocks'=>'CreatePersonnelAccessBlocks',
            '2026-09-14-120001_CreatePacsManagedProfiles'=>'CreatePacsManagedProfiles',
        ];
        if ($this->pacs->isEnabledForTenant($this->tenantId)) $migrations += [
            '2026-09-13-100001_CreatePacsIntegration'=>'CreatePacsIntegration',
            '2026-09-13-110001_CreatePacsOrders'=>'CreatePacsOrders',
            '2026-09-13-120001_AddPacsOrderWorkflow'=>'AddPacsOrderWorkflow',
        ];
        // DDL can auto-commit in MySQL. Every step is idempotent so interrupted preparation can be resumed.
        foreach ($migrations as $file=>$class) {
            require_once APPPATH.'Database/Migrations/'.$file.'.php';
            $class='App\\Database\\Migrations\\'.$class;
            (new $class(\Config\Database::forge($this->db)))->up();
            unset($this->db->dataCache['table_names']);
        }
        $this->db->table('clinical_setup_audit')->insert(['tenant_id'=>$this->tenantId,'actor_id'=>$actor,'event'=>'space_prepared','entity_id'=>'','revision'=>1,'created_at'=>gmdate('Y-m-d H:i:s')]);
    }
    public function inspect(bool $writeProbe=false): array
    {
        $this->clinical->assertEnabledForTenant($this->tenantId);
        $pacs=$this->pacs->isEnabledForTenant($this->tenantId); $checks=[];
        $add=static function(string $id,string $label,bool $ok,string $detail) use (&$checks) { $checks[]=compact('id','label','ok','detail'); };
        $has=function(array $names): bool { foreach ($names as $name) if (!$this->db->tableExists($name)) return false; return true; };
        $columns=function(string $table,array $fields) use ($has): bool {
            if (!$has([$table])) return false;
            foreach ($fields as $field) if (!$this->db->fieldExists($field,$table)) return false;
            return true;
        };
        $clinicalSchema=$has(['clinical_entries','clinical_objects','clinical_consents','clinical_consent_templates','clinical_patient_state','clinical_audit'])
            && $columns('clinical_entries',['payload_enc','revision','state','pdf_object_id','signed_object_id','signature_evidence_json','appointment_id'])
            && $columns('clinical_consents',['kind','decision','evidence_object_id','previous_id'])
            && $columns('clinical_objects',['sha256','name_enc','category']);
        $add('clinical_schema','Archivio clinico',$clinicalSchema,'Cartella, documenti, consensi e storico.');
        $add('personnel_schema','Blocco accessi personale',$columns('personnel_access_blocks',['tenant_id','user_id','blocked_by','blocked_at']),'Disattivazione persistente degli account.');
        $add('settings_schema','Configurazione dello spazio',$columns('pacs_managed_profiles',['tenant_id','profile_id','config_enc','revision','updated_by','updated_at']) && $columns('clinical_setup_audit',['tenant_id','actor_id','event','entity_id','revision','created_at']),'Collegamenti cifrati e registrazione delle modifiche.');
        $crypto=false; $storage=false;
        try {
            $vault=new ClinicalVault($this->tenantId); $sample='AF-SETUP-'.bin2hex(random_bytes(24));
            $cipher=$vault->seal('setup-check',$sample);
            $crypto=hash_equals($sample,$vault->open('setup-check',$cipher));
            \App\Services\Pacs\PacsIntegrity::hash($sample);
            $dir=rtrim(WRITEPATH,'/\\').'/clinical-private/'.$this->tenantId;
            $storage=is_dir($dir) && !is_link($dir) && is_writable($dir);
            if ($writeProbe) {
                if (is_link(dirname($dir)) || is_link($dir)) throw new \RuntimeException('Unsafe storage');
                if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new \RuntimeException('Storage unavailable');
                $file=$dir.'/.setup-'.bin2hex(random_bytes(16)).'.tmp';
                try {
                    $handle=fopen($file,'xb');
                    if (!$handle) throw new \RuntimeException('Storage unavailable');
                    try { $written=fwrite($handle,$cipher); fflush($handle); } finally { fclose($handle); }
                    @chmod($file,0600);
                    $storage=$written===strlen($cipher) && hash_equals($sample,$vault->open('setup-check',(string)file_get_contents($file)));
                } finally { if (is_file($file)) unlink($file); }
            }
        } catch (\Throwable) { $storage=false; }
        $add('encryption','Cifratura',$crypto,'Verifica con contenuto sintetico casuale.');
        $add('storage','Archivio privato',$storage,$writeProbe ? 'Scrittura e rilettura cifrate con file temporaneo rimosso.' : 'Eseguire il collaudo per provare scrittura e rilettura.');
        if ($pacs) $add('pacs_schema','Richieste e immagini PACS',$has(['pacs_patient_bindings','pacs_study_links','pacs_audit']) && PacsOrderService::schemaReady($this->db),'Richieste, esecuzione e collegamenti DICOM.');
        return ['tenant_id'=>$this->tenantId,'checked_at'=>gmdate('c'),'pacs_enabled'=>$pacs,'checks'=>$checks,
            'ready'=>!in_array(false,array_column($checks,'ok'),true),'storage_tested'=>$writeProbe,
            'external_validation'=>'not_assessed'];
    }
    public function acceptance(?string $profileId=null): array
    {
        $result=$this->inspect(true);
        if ($profileId!==null && $profileId!=='') {
            $this->pacs->assertEnabled($this->tenantId);
            $result['connection']=(new PacsDiagnostics(new PacsProfiles(null,$this->db)))->probe($this->tenantId,$profileId);
            $result['ready']=$result['ready'] && $result['connection']['ok'];
        }
        $result['scope']='Preparazione tecnica e, se selezionato, ricerca QIDO sintetica. Esecuzione clinica, WADO, worklist, MPPS e firma qualificata richiedono collaudi distinti.';
        return $result;
    }
}
