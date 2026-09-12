<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/app-lab-common.php';$lab=fse_lab_config();fse_lab_boot();
$server=new mysqli('127.0.0.1','root',$lab['password'],'',33079);
if(realpath($server->query('SELECT @@datadir p')->fetch_assoc()['p'])!==realpath($lab['root'].'/mysql')) throw new RuntimeException('Wrong synthetic MySQL.');
$action=$argv[1] ?? 'run';$name=$action==='run' ? 'fselab_ts_'.bin2hex(random_bytes(4)) : ($argv[2] ?? '');
if(!preg_match('/^fselab_ts_[a-f0-9]{8}$/D',$name)) throw new RuntimeException('Invalid synthetic database.');
if($action==='run') $server->query('CREATE DATABASE '.$name);
$db=\Config\Database::connect(array_replace((new \Config\Database())->default,['database'=>$name]),false);
$context=new class($db) extends \App\Services\TsTenantDatabaseContextService {
    public function __construct(private $db) {}
    public function resolveTenantContext(int $tenantId=0): array {
        if($tenantId!==42) throw new RuntimeException('Only synthetic tenant accepted.');
        $events=new \App\Models\TsDocumentEventModel($this->db);
        return ['db'=>$this->db,'documents'=>new \App\Models\TsDocumentModel($this->db),'events'=>$events,'audit'=>new \App\Services\TsAuditService($events)];
    }
};
$service=new \App\Services\TsDocumentService(tenantDbContext:$context);$documents=new \App\Models\TsDocumentModel($db);
if($action==='variation') {
    usleep(250000);$result=$service->createVariationDraftFromDocument(42,1,7);echo $result['document']['id_ts_document'];exit;
}
if($action==='claim') {
    $id=(int)($argv[3] ?? 0);$snapshot=$documents->find($id);usleep(250000);
    try { echo $documents->updateEditableSnapshot($id,$snapshot,['local_state'=>'sending']) ? 'claimed' : 'blocked'; }
    catch(RuntimeException $e) { echo 'blocked'; }exit;
}
if($action!=='run') throw new RuntimeException('Unsupported lab action.');
$fields=(new ReflectionClass(\App\Models\TsDocumentModel::class))->getDefaultProperties()['allowedFields'];
$db->query('CREATE TABLE ts_documents (id_ts_document INT AUTO_INCREMENT PRIMARY KEY,'.implode(',',array_map(static fn($f)=>'`'.$f.'` LONGTEXT NULL',$fields)).') ENGINE=InnoDB');
$db->query('CREATE TABLE ts_document_events (id_ts_event INT AUTO_INCREMENT PRIMARY KEY,id_ts_document INT,event_type TEXT,event_level TEXT,message TEXT,context_json LONGTEXT,created_by INT,created_at DATETIME) ENGINE=InnoDB');
$documents->insert(['source_type'=>'manual','local_state'=>'sent','ts_state'=>'accepted','ts_protocol'=>'SYNTHETIC-1']);
$parallel=static function(array $calls) use($name):array {
    $children=[];
    foreach($calls as $call){$args=[PHP_BINARY,__FILE__,$call[0],$name];if(isset($call[1]))$args[]=(string)$call[1];
        $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$p,$pipes];}
    $results=[];foreach($children as [$p,$pipes]){$out=trim(stream_get_contents($pipes[1]));$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0 || $error!=='')throw new RuntimeException('Synthetic child failed.');$results[]=$out;}sort($results);return $results;
};
$created=$parallel([['variation'],['variation']]);
if($created!==['2','2'] || $documents->countAllResults()!==2) throw new RuntimeException('Concurrent variation created duplicate.');
$documents->update(2,['local_state'=>'ready']);
$other=$documents->insert(['source_ref_id'=>1,'source_type'=>'ts_cancellation','local_state'=>'ready']);
$claimed=$parallel([['claim',2],['claim',$other]]);
if($claimed!==['blocked','claimed']) throw new RuntimeException('Concurrent sibling dispatch not serialized.');
$report=['status'=>'passed','mode'=>'SYNTHETIC_TS_MYSQL_CONCURRENCY','database'=>$name,'checks'=>['concurrent_variation_reuses_one_child','concurrent_sibling_dispatch_has_one_winner']];
file_put_contents($lab['root'].'/ts-concurrency-report.json',json_encode($report,JSON_PRETTY_PRINT));echo json_encode($report);
