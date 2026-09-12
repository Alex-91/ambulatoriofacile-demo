<?php
// Fixed CLI-only synthetic scenarios. No credential input, arbitrary SQL or real Gateway actions.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/app-lab-common.php';
fse_lab_boot();
$lab=fse_lab_config();
$contexts=new \App\Services\FseTenantDatabaseContextService();
$context=$contexts->resolveTenantContext(42);
(new \App\Services\FseSyntheticAppBoundary())->assertDatabase(42,$context['db']);
$documents=new \App\Services\FseDocumentService();
$action=$argv[1] ?? ''; $id=(int)($argv[2] ?? 0);
if ($action==='prepare') {
    $profile=(new \App\Services\FseProfileService())->runtimeProfileForTenant(42);
    $payload=require SUPPORTPATH.'fse_synthetic.php';
    $payload['id_fse_profile']=$profile['id_fse_profile']; $payload['administrative_request']='NOSSN';
    $doc=$documents->saveDraftForTenant(42,$payload,1); $id=(int)$doc['id_fse_document'];
    $documents->prepareForSignature(42,$id,1);
    $result=['document_id'=>$id,'unsigned_pdf'=>$documents->downloadArtifact(42,$id,'unsigned')['path']];
} elseif ($action==='revise') {
    $id=(new \App\Services\FseRevisionService())->create(42,$id,'Correzione esclusivamente sintetica per precollaudo Toscana',1);
    $documents->prepareForSignature(42,$id,1);
    $result=['document_id'=>$id,'unsigned_pdf'=>$documents->downloadArtifact(42,$id,'unsigned')['path']];
} elseif ($action==='accept') {
    $path=$lab['root'].'/signing/rehearsal-signed-'.$id.'.pdf';
    if ($id<=0 || !is_file($path) || is_link($path)) throw new RuntimeException('Missing synthetic fixture');
    $documents->acceptSignedPdf(42,$id,(string)file_get_contents($path),1);
    $result=['document_id'=>$id,'state'=>$documents->runtimeDocument(42,$id)['local_state']];
} elseif ($action==='http-baseline') {
    // Technical hashes only: no clinical text, passwords, file paths or configuration export.
    $proof=[]; $sources=[];
    foreach ([42,43] as $tenant) {
        $scope=$contexts->resolveTenantContext($tenant);
        (new \App\Services\FseSyntheticAppBoundary())->assertDatabase($tenant,$scope['db']);
        foreach (['fse_documents'=>'id_fse_document','fse_document_events'=>'id_fse_event'] as $table=>$key) {
            $rows=$scope['db']->table($table)->orderBy($key)->get()->getResultArray();
            $proof[$tenant][$table]=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
            if ($tenant===42 && $table==='fse_documents') foreach ($rows as $row) {
                $sources[]=['id'=>(int)$row['id_fse_document'],'profile'=>(int)$row['id_fse_profile'],
                    'state'=>$row['local_state'],'pdf_sha256'=>$row['signed_pdf_sha256']];
            }
        }
    }
    $store=new \App\Services\FseToscanaLabStore();
    $result=['database_sha256'=>$proof,'documents'=>$sources,'lab_state_sha256'=>hash('sha256',json_encode([$store->read(42),$store->read(43)],JSON_THROW_ON_ERROR))];
} elseif ($action==='exercise') {
    $child=(int)($argv[3] ?? 0); $uncertain=(int)($argv[4] ?? 0);
    $sourceIds=[$id,$child,$uncertain];
    if (min($sourceIds)<=0 || count(array_unique($sourceIds))!==3) throw new RuntimeException('Three distinct synthetic sources required');
    $before=$context['db']->table('fse_documents')->whereIn('id_fse_document',$sourceIds)->orderBy('id_fse_document')->get()->getResultArray();
    $store=new \App\Services\FseToscanaLabStore();
    if ($store->read(42)['documents']) throw new RuntimeException('Rehearsal already populated; never reset implicitly');
    $bridge=new \App\Services\FseToscanaDocumentLab();
    $state=$bridge->import(42,$id,1);
    $step=static function(string $command,string $document) use($store): array {
        $state=$store->read(42); $doc=$state['documents'][$document]; $op=$state['operations'][$doc['pending'] ?? ''] ?? [];
        return $store->command(42,1,['command'=>$command,'document'=>$document,'revision'=>$doc['revision'],
            'profile'=>$doc['profile'],'operation'=>$op['id'] ?? '', 'workflow'=>$op['workflow'] ?? '']);
    };
    foreach (['begin_create','accepted','confirm_ok'] as $command) $state=$step($command,'SIM.1');
    $bridge->import(42,$child,1);
    foreach (['begin_replace','accepted','confirm_ok','begin_metadata','accepted','confirm_ok',
        'begin_delete','rejected','begin_delete','accepted','confirm_ok'] as $command) $state=$step($command,'SIM.2');
    $bridge->import(42,$uncertain,1);
    foreach (['begin_create','timeout'] as $command) $state=$step($command,'SIM.3');
    $pending=$state['documents']['SIM.3']['pending'];
    if ($bridge->import(42,$uncertain,1)!==$state) throw new RuntimeException('Reimport reset an uncertain operation');
    $after=$context['db']->table('fse_documents')->whereIn('id_fse_document',$sourceIds)->orderBy('id_fse_document')->get()->getResultArray();
    if ($before!==$after || count($before)!==3) throw new RuntimeException('Source changed');
    if ($state['documents']['SIM.1']['state']!=='superseded' || $state['documents']['SIM.2']['state']!=='deleted'
        || $state['documents']['SIM.2']['metadata_version']!==2 || $state['operations'][$pending]['state']!=='uncertain') throw new RuntimeException('Rehearsal failed');
    $denied=false;
    try { $bridge->import(43,$id,1); } catch (RuntimeException $e) { $denied=$e->getMessage()==='LAB_DOCUMENT'; }
    if (!$denied || $store->read(43)['documents']) throw new RuntimeException('Tenant isolation failed');
    $result=['mode'=>'SYNTHETIC_APP_DOCUMENT_REHEARSAL','status'=>'passed','at'=>gmdate('c'),
        'official_accreditation_evidence'=>false,'external_gateway_calls'=>0,'source_database_unchanged'=>true,
        'source_states'=>array_column($after,'local_state'),'source_document_ids'=>$sourceIds,
        'operations'=>['create'=>'confirmed_simulated','replace'=>'confirmed_simulated','metadata'=>'confirmed_simulated',
            'delete'=>'rejection_then_confirmation_simulated','timeout'=>'blocked_no_retry'],
        'tenant_isolation'=>'passed','qualified_signature'=>'not_assessed','browser_flow'=>'NOT_EXECUTED_BY_THIS_SCRIPT'];
    file_put_contents($lab['root'].'/rehearsal-report.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
} else throw new RuntimeException('Unknown fixed rehearsal action');
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
