<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use App\Services\{ClinicalJourneyService,ClinicalRecordService,TenantCatalogService,TenantDatabaseConnector};
final class ClinicalJourneyCheck extends BaseCommand
{
    protected $group='Testing';protected $name='clinical:check-journey-test';protected $description='Exercise one synthetic appointment, permissions and immutable PDF in isolated test.';
    protected $options=['--production-path'=>'Exercise production tables and reject simulations, always on the isolated test database.'];
    public function run(array $params)
    {
        ClinicalJourneyService::assertTest();
        require_once dirname(rtrim(ROOTPATH,'/\\')).'/vendor/autoload.php';
        $tenant=(new TenantCatalogService())->getTenantById(4);$db=(new TenantDatabaseConnector())->connect($tenant);
        $productionPath=CLI::getOption('production-path')!==null;
        if ($productionPath) {
            require_once APPPATH.'Database/Migrations/2026-10-06-190001_CreateClinicalJourneys.php';
            $migration=new \App\Database\Migrations\CreateClinicalJourneys(\Config\Database::forge($db));
            $migration->up();$migration->up(); // Repeated deploys must preserve data.
            unset($db->dataCache['table_names']);
            putenv('AF_CLINICAL_JOURNEY_TEST=0');$_ENV['AF_CLINICAL_JOURNEY_TEST']='0';$_SERVER['AF_CLINICAL_JOURNEY_TEST']='0';
            if (ClinicalJourneyService::isTest()) throw new \RuntimeException('Production-path test did not disable simulation mode.');
        }
        $actors=[];foreach(['giuseppe','anna','segreteria'] as $slug) {$actors[$slug]=(int)$db->table('dap01_users')->where('username',$slug.'.percorso@example.test')->get()->getRowArray()['id_user'];}
        $a=$db->table('dap12_agenda_appuntamenti')->where('created_by',$actors['segreteria'])->where('note','Dati fittizi per collaudo percorso esame')->orderBy('id_appuntamento')->get()->getRowArray();
        if (!$a) throw new \RuntimeException('Seed first.');
        $id=(int)$a['id_appuntamento'];$doc=new ClinicalJourneyService($db,4,$actors['giuseppe']);$sec=new ClinicalJourneyService($db,4,$actors['segreteria']);
        $denied=static function(callable $f): void {try{$f();}catch(\RuntimeException){return;}throw new \RuntimeException('Expected permission/state rejection.');};
        $denied(fn()=>(new ClinicalJourneyService($db,4,$actors['anna']))->read($id));
        $r=$doc->read($id)['row'];
        if ($r['stage']==='booked') $sec->act($id,['action'=>'accept','revision'=>$r['revision']]);
        $denied(fn()=>$sec->act($id,['action'=>'start','revision'=>$doc->read($id)['row']['revision']]));
        $denied(fn()=>$doc->act($id,['action'=>'start','revision'=>-1]));
        if ($doc->read($id)['row']['stage']==='accepted') $doc->act($id,['action'=>'start','revision'=>$doc->read($id)['row']['revision']]);
        if ($doc->read($id)['row']['stage']==='in_progress') $doc->act($id,['action'=>'finish','revision'=>$doc->read($id)['row']['revision']]);
        $state=$doc->read($id);
        if (!$state['report']) $doc->act($id,['action'=>'save','revision'=>$state['row']['revision'],'entry_revision'=>0,'body'=>'REFERTO DI PROVA — SENZA VALORE CLINICO. Esame sintetico per verificare il percorso.']);
        $state=$doc->read($id);
        if ($state['report']['state']==='draft') $doc->act($id,['action'=>'finalize','revision'=>$state['row']['revision'],'entry_revision'=>$state['report']['revision']]);
        $state=$doc->read($id);$file=(new ClinicalRecordService($db,4,$actors['giuseppe']))->download((int)$a['id_client'],$state['report']['pdf_object_id']);
        if (!str_starts_with($file['bytes'],'%PDF-')) throw new \RuntimeException('PDF unavailable.');
        $denied(fn()=>$doc->act($id,['action'=>'save','revision'=>$state['row']['revision'],'body'=>'overwrite']));
        if ($productionPath) {
            $denied(fn()=>$doc->act($id,['action'=>'simulate_signature','revision'=>$state['row']['revision']]));
            $denied(fn()=>$doc->act($id,['action'=>'simulate_delivery','revision'=>$state['row']['revision']]));
            if ($sec->read($id)['report']!==null) throw new \RuntimeException('Secretary read a report.');
            // Change only the synthetic workflow binding, in a rolled-back transaction.
            $db->transBegin();
            try {
                $db->table('clinical_journeys')->where('tenant_id',4)->where('appointment_id',$id)->update(['patient_id'=>0]);
                $denied(fn()=>$doc->read($id));
            } finally { $db->transRollback(); }
            $state=$doc->read($id);
            if ($state['report']['state']!=='final' || $state['row']['signature_simulated_at']!==null) throw new \RuntimeException('Unexpected signature state.');
            CLI::write(json_encode(['result'=>'PASS','mode'=>'production path on isolated test DB','appointment'=>$id,'pdf_bytes'=>strlen($file['bytes']),'checks'=>['idempotent migration','doctor isolation','secretary permissions','stale revision','full journey and PDF','immutable final','simulation rejected','patient binding']],JSON_PRETTY_PRINT));
            return EXIT_SUCCESS;
        }
        if (!$state['row']['signature_simulated_at']) $doc->act($id,['action'=>'simulate_signature','revision'=>$state['row']['revision']]);
        $state=$doc->read($id);
        if (!$state['row']['delivery_simulated_at']) $doc->act($id,['action'=>'simulate_delivery','revision'=>$state['row']['revision']]);
        $state=$doc->read($id);
        if ($state['report']['state']!=='final' || !empty($state['report']['signed_object_id']) || $sec->read($id)['report']!==null) throw new \RuntimeException('Signature simulation or role boundary failed.');
        CLI::write(json_encode(['result'=>'PASS','appointment'=>$id,'pdf_bytes'=>strlen($file['bytes']),'report_state'=>$state['report']['state'],'signature_and_delivery'=>'simulated_only','checks'=>['doctor isolation','secretary cannot start or read report','stale revision rejected','full journey','PDF download','immutable final','simulation does not sign archive']],JSON_PRETTY_PRINT));
    }
}
