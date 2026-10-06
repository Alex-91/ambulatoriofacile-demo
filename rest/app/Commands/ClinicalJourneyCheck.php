<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use App\Services\{ClinicalJourneyService,ClinicalRecordService,TenantCatalogService,TenantDatabaseConnector};
final class ClinicalJourneyCheck extends BaseCommand
{
    protected $group='Testing';protected $name='clinical:check-journey-test';protected $description='Exercise one synthetic appointment, permissions and immutable PDF in isolated test.';
    public function run(array $params)
    {
        ClinicalJourneyService::assertTest();
        $tenant=(new TenantCatalogService())->getTenantById(4);$db=(new TenantDatabaseConnector())->connect($tenant);
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
        if (!$state['row']['signature_simulated_at']) $doc->act($id,['action'=>'simulate_signature','revision'=>$state['row']['revision']]);
        $state=$doc->read($id);
        if (!$state['row']['delivery_simulated_at']) $doc->act($id,['action'=>'simulate_delivery','revision'=>$state['row']['revision']]);
        $state=$doc->read($id);
        if ($state['report']['state']!=='final' || !empty($state['report']['signed_object_id']) || $sec->read($id)['report']!==null) throw new \RuntimeException('Signature simulation or role boundary failed.');
        CLI::write(json_encode(['result'=>'PASS','appointment'=>$id,'pdf_bytes'=>strlen($file['bytes']),'report_state'=>$state['report']['state'],'signature_and_delivery'=>'simulated_only','checks'=>['doctor isolation','secretary cannot start or read report','stale revision rejected','full journey','PDF download','immutable final','simulation does not sign archive']],JSON_PRETTY_PRINT));
    }
}
