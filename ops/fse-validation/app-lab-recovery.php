<?php
/** Synthetic-only recovery drill. Never points an application at the restored DBs. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/app-lab-common.php';
require __DIR__.'/lab-recovery-pair.php';
$lab = fse_lab_config();
if (!is_file($lab['root'].'/seeded.json')) throw new RuntimeException('Seed the isolated lab first.');
$server = new mysqli('127.0.0.1','root',$lab['password'],'',33079);
if (realpath($server->query('SELECT @@datadir AS path')->fetch_assoc()['path']) !== realpath($lab['root'].'/mysql')) throw new RuntimeException('Wrong MySQL instance.');
$listener = @fsockopen('127.0.0.1',8088,$errno,$error,1);
if ($listener) { fclose($listener); throw new RuntimeException('Stop the lab web server before the recovery snapshot.'); }
fse_lab_boot();

function lab_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function lab_json($value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); }
function lab_export(mysqli $server, array $tables): array {
    $result=[];
    $server->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    try {
        foreach ($tables as $database=>$names) foreach ($names as $name) {
            $ddl=$server->query('SHOW CREATE TABLE `'.$database.'`.`'.$name.'`')->fetch_row()[1];
            $rows=$server->query('SELECT * FROM `'.$database.'`.`'.$name.'` ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
            $result[$database][$name]=['ddl'=>$ddl,'rows'=>$rows];
        }
    } finally { $server->rollback(); }
    return $result;
}
function lab_verify_bundle(string $backup, array $manifest, string $key): void {
    lab_assert(hash_equals(hash_hmac('sha256',lab_json($manifest['files']),$key),$manifest['hmac']),'Backup manifest authentication failed.');
    foreach ($manifest['files'] as $relative=>$hash) {
        lab_assert(preg_match('#^(database\.json|artifacts/[0-9]+/[0-9]+/[a-z0-9._-]+)$#D',$relative)===1,'Unsafe backup path.');
        $path=$backup.'/'.$relative;
        lab_assert(is_file($path) && !is_link($path) && hash_equals($hash,hash_file('sha256',$path)),'Backup file missing or changed.');
    }
}
function lab_expect_failure(callable $operation, string $message): void {
    try { $operation(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException($message);
}

$run=bin2hex(random_bytes(8));
$target=$lab['root'].'/recovery/'.$run;
$backup=$target.'/backup';
mkdir($backup,0700,true);
$report=['mode'=>'FSE_SYNTHETIC_APP_LAB','official_accreditation_evidence'=>false,'status'=>'incomplete','started_at'=>gmdate('c'),
    'scope'=>'FSE tables, profiles, version chains and artifacts; not an entire application/disaster-recovery certification',
    'external_gateway_tests'=>'NOT_EXECUTED','checks'=>[]];
$tables=['fselab_platform'=>['platform_tenants','platform_tenant_fse_profiles'],
    'fselab_a'=>['fse_documents','fse_document_events'], 'fselab_b'=>['fse_documents','fse_document_events']];
try {
    $snapshot=lab_export($server,$tables);
    $documents=$snapshot['fselab_a']['fse_documents']['rows'];
    [$originalId,$revisionId]=fse_lab_revision_pair($documents);
    $sourceById=array_column($documents,null,'id_fse_document');
    $sourceDefault=(new \App\Services\FseProfileService())->getDefaultProfileForTenant(42);
    lab_assert(is_array($sourceDefault),'Synthetic default profile required.');
    lab_assert($snapshot['fselab_b']['fse_documents']['rows']===[],'Studio B must have no documents for this isolation drill.');
    file_put_contents($backup.'/database.json',lab_json($snapshot));
    $files=['database.json'=>hash_file('sha256',$backup.'/database.json')];
    $artifactMappings=[];
    $validator=new \App\Services\FseArtifactValidationService();
    foreach ([42=>'fselab_a',43=>'fselab_b'] as $tenant=>$database) foreach ($snapshot[$database]['fse_documents']['rows'] as $document) {
        $id=(int)$document['id_fse_document'];
        foreach (['cda','unsigned_pdf','signed_pdf'] as $kind) {
            if (empty($document[$kind.'_path'])) continue;
            $contents=$validator->storedArtifact($tenant,$id,$document,$kind);
            $relative='artifacts/'.$tenant.'/'.$id.'/'.basename($document[$kind.'_path']);
            if (!is_dir(dirname($backup.'/'.$relative))) mkdir(dirname($backup.'/'.$relative),0700,true);
            file_put_contents($backup.'/'.$relative,$contents);
            $files[$relative]=hash('sha256',$contents);
            $artifactMappings[$database][$id][$kind]=$relative;
        }
    }
    $manifest=['files'=>$files,'hmac'=>hash_hmac('sha256',lab_json($files),$lab['secret_key'])];
    file_put_contents($target.'/manifest.json',lab_json($manifest));
    lab_verify_bundle($backup,$manifest,$lab['secret_key']);
    $report['checks']['authenticated_backup']='passed';
    lab_expect_failure(fn()=>lab_verify_bundle($backup,$manifest,'wrong-synthetic-key'),'Wrong manifest key accepted.');
    $tampered=$manifest; $tampered['files']['database.json']=str_repeat('0',64);
    lab_expect_failure(fn()=>lab_verify_bundle($backup,$tampered,$lab['secret_key']),'Altered manifest accepted.');
    $negative=$target.'/negative'; mkdir($negative,0700);
    file_put_contents($negative.'/database.json','synthetic altered backup');
    lab_expect_failure(fn()=>lab_verify_bundle($negative,$manifest,$lab['secret_key']),'Altered backup accepted.');
    $report['checks']['wrong_key_and_tampered_backup_rejected']='passed';

    // Always new schemas. No DROP, TRUNCATE, or overwriting an existing database.
    $restored=[];
    foreach ($snapshot as $source=>$data) {
        $destination='fselab_restore_'.$run.'_'.substr($source,7);
        lab_assert(preg_match('/^fselab_restore_[a-f0-9]{16}_(platform|a|b)$/D',$destination)===1,'Invalid restore DB.');
        $server->query('CREATE DATABASE `'.$destination.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $server->select_db($destination);
        $server->query('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($data as $name=>$table) {
                $server->query($table['ddl']);
                foreach ($table['rows'] as $row) {
                    $columns=implode(',',array_map(static fn($key)=>'`'.$key.'`',array_keys($row)));
                    $statement=$server->prepare('INSERT INTO `'.$name.'` ('.$columns.') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
                    $statement->execute(array_values($row)); $statement->close();
                }
            }
        } finally { $server->query('SET FOREIGN_KEY_CHECKS=1'); }
        $restored[$source]=$destination;
    }
    $restoredTables=[];
    foreach ($tables as $source=>$names) $restoredTables[$restored[$source]]=$names;
    $readBack=lab_export($server,$restoredTables);
    foreach ($snapshot as $source=>$data) foreach ($data as $name=>$table) {
        lab_assert($readBack[$restored[$source]][$name]['rows']===$table['rows'],'Restored rows differ.');
    }
    $report['checks']['mysql_roundtrip_all_rows']='passed';
    $config=clone config(\App\Config\Fse2::class); $config->tenantStorageRoot=$target.'/restored-artifacts';
    $storage=new \App\Services\FseStorageService($config);
    $restoredValidator=new \App\Services\FseArtifactValidationService($config);
    $restoredDocuments=[];
    foreach ($artifactMappings as $database=>$byId) foreach ($byId as $id=>$byKind) {
        $db=\Config\Database::connect(array_replace((new \Config\Database())->default,['database'=>$restored[$database]]),false);
        $document=$db->table('fse_documents')->where('id_fse_document',$id)->get()->getRowArray();
        foreach ($byKind as $kind=>$relative) {
            $tenant=$database==='fselab_a'?42:43;
            $path=$storage->store($tenant,$id,basename($relative),file_get_contents($backup.'/'.$relative));
            $db->table('fse_documents')->where('id_fse_document',$id)->update([$kind.'_path'=>$path]);
            $document[$kind.'_path']=$path;
            lab_assert(hash_equals($files[$relative],hash('sha256',$restoredValidator->storedArtifact($tenant,$id,$document,$kind))),'Restored artifact differs.');
        }
        $restoredDocuments[$id]=$document; $db->close();
    }
    $secrets=new \App\Services\FseSecretsService();
    $original=$restoredDocuments[$originalId]; $revision=$restoredDocuments[$revisionId];
    lab_assert($original['set_id']===$revision['set_id'] && (int)$revision['previous_document_id']===$originalId && (int)$revision['version_number']===2,'Broken version chain.');
    lab_assert($original['profile_snapshot_json']===$revision['profile_snapshot_json'],'Revision lost profile snapshot.');
    foreach ([$originalId=>$original,$revisionId=>$revision] as $id=>$restoredDocument) {
        $expected=(string)$secrets->decrypt($sourceById[$id]['report_text_enc']);
        lab_assert($expected!=='' && $expected===(string)$secrets->decrypt($restoredDocument['report_text_enc']),'Restored text differs from source.');
    }
    lab_assert(\App\Services\FseDocumentLifecycle::isSealed($original),'Restored original lost immutability.');
    $report['checks']['artifacts_decryption_versions_and_snapshot']='passed';
    $restoredProfileDb=\Config\Database::connect(array_replace((new \Config\Database())->platform,['database'=>$restored['fselab_platform']]),false);
    $profiles=new \App\Services\FseProfileService(new \App\Models\PlatformTenantFseProfilesModel($restoredProfileDb));
    $profile=$profiles->runtimeProfileForDocument(42,$original);
    lab_assert($profile['profile_name']==='Sede A — privato' && !$profile['is_enabled'],'Restored profile binding/disable flag invalid.');
    lab_assert($profiles->getDefaultProfileForTenant(42)===$sourceDefault,'Default profile was not restored exactly.');
    lab_expect_failure(fn()=>$profiles->runtimeProfileForDocument(43,$original),'Cross-tenant profile accepted after restore.');
    $report['checks']['profiles_and_tenant_isolation']='passed';
    foreach (['FSE2_SECRET_KEY'] as $key) { putenv($key.'=wrong-synthetic-key'); $_ENV[$key]=$_SERVER[$key]='wrong-synthetic-key'; }
    try { lab_expect_failure(fn()=>(new \App\Services\FseSecretsService())->decrypt($original['report_text_enc']),'Wrong decryption key accepted.'); }
    finally { putenv('FSE2_SECRET_KEY='.$lab['secret_key']); $_ENV['FSE2_SECRET_KEY']=$_SERVER['FSE2_SECRET_KEY']=$lab['secret_key']; }
    $report['checks']['wrong_decryption_key_rejected']='passed';
    $missing=$original; $missing['cda_path']=$target.'/does-not-exist.xml';
    lab_expect_failure(fn()=>$restoredValidator->storedArtifact(42,$originalId,$missing,'cda'),'Missing restored artifact accepted.');
    $altered=$original; $altered['cda_sha256']=str_repeat('0',64);
    lab_expect_failure(fn()=>$restoredValidator->storedArtifact(42,$originalId,$altered,'cda'),'Wrong restored hash accepted.');
    $report['checks']['missing_artifact_and_wrong_hash_rejected']='passed';

    // Simulate interrupted states only on restored rows, rolling back each drill.
    $db=\Config\Database::connect(array_replace((new \Config\Database())->default,['database'=>$restored['fselab_a']]),false);
    foreach (['preparing'=>'LOCAL_CHECK_STALE','checking_signature'=>'LOCAL_CHECK_STALE','publishing'=>'REMOTE_OUTCOME_UNCERTAIN'] as $state=>$expected) {
        $db->transBegin();
        try {
            $db->table('fse_documents')->where('id_fse_document',$revisionId)->update(['local_state'=>$state,'updated_at'=>'2026-01-01 00:00:00',
                'last_response_json'=>'{"transport":{"outcome_uncertain":true}}']);
            $before=$db->table('fse_documents')->where('id_fse_document',$revisionId)->get()->getRowArray();
            $diagnosis=(new \App\Services\FseReconciliationService())->inspect($before);
            lab_assert($diagnosis['code']===$expected && !$diagnosis['retry_allowed'],'Unsafe interrupted-state diagnosis.');
            lab_assert($before===$db->table('fse_documents')->where('id_fse_document',$revisionId)->get()->getRowArray(),'Inspection changed document state.');
        } finally { $db->transRollback(); }
    }
    $report['checks']['interrupted_states_no_blind_retries']='passed';
    $jobs=new \App\Services\FseValidationJobs(realpath(WRITEPATH).'/recovery-jobs/'.$run);
    $abandoned=$jobs->create(); $active=$jobs->create();
    foreach ([$abandoned,$active] as $job) {
        file_put_contents($job['directory'].'/input.json','{"synthetic":true}');
        foreach (['input.json','.active.lock'] as $name) touch($job['directory'].'/'.$name,time()-90000);
        touch($job['directory'],time()-90000);
    }
    \App\Services\FseValidationJobs::release($abandoned['lease']);
    try {
        $dry=$jobs->cleanup(false); $applied=$jobs->cleanup(true);
        lab_assert($dry['eligible']===1 && $dry['removed']===0 && $applied['removed']===1 && $applied['errors']===0,'Abandoned job cleanup failed.');
        lab_assert(is_file($active['directory'].'/input.json'),'Active job was removed.');
    } finally { \App\Services\FseValidationJobs::release($active['lease']); }
    $report['checks']['abandoned_job_removed_active_job_preserved']='passed';
    lab_assert($snapshot===lab_export($server,$tables),'Source data changed during recovery.');
    foreach ($artifactMappings as $database=>$byId) foreach ($byId as $id=>$byKind) foreach ($byKind as $kind=>$relative) {
        $sourceRow=array_values(array_filter($snapshot[$database]['fse_documents']['rows'],fn($row)=>(int)$row['id_fse_document']===$id))[0];
        lab_assert(hash_equals($files[$relative],hash_file('sha256',$sourceRow[$kind.'_path'])),'Original artifact changed during recovery.');
    }
    $report['checks']['source_databases_and_files_unchanged']='passed';
    $report['restored_databases']=$restored;
    $report['source_version_pair']=[$originalId,$revisionId];
    $report['artifact_count']=count($files)-1;
    $report['status']='passed_synthetic_recovery';
} finally {
    $report['finished_at']=gmdate('c');
    file_put_contents($target.'/result.json',json_encode($report,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    echo 'Recovery evidence: '.$target.'/result.json'.PHP_EOL;
}
echo 'Recovery drill passed; original data unchanged, no external calls.'.PHP_EOL;
