<?php
/** CLI-only supplemental harness. Executes real FSE services on marked synthetic MySQL. */
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || !is_file('/opt/fse/linux-lab-image')) {
    throw new RuntimeException('Synthetic Linux image required.');
}
if (($argv[1] ?? '') === 'ui-audit') {
    require '/var/www/html/ops/fse-validation/app-lab-ui-audit.php';
    exit;
}
require '/var/www/html/ops/fse-validation/app-lab-common.php';
$lab = fse_lab_config();
if (($lab['runtime'] ?? '') !== 'linux-container' || !is_file($lab['root'].'/seeded.json')) throw new RuntimeException('Seeded Linux lab required.');
fse_lab_boot();
$contexts = new \App\Services\FseTenantDatabaseContextService();
foreach ([42,43] as $tenant) (new \App\Services\FseSyntheticAppBoundary())->assertDatabase($tenant,$contexts->resolveTenantContext($tenant)['db']);
$service = new \App\Services\FseDocumentService();
$action = $argv[1] ?? '';
$id = (int)($argv[2] ?? 0);

function resilience_dir(string $token): string {
    global $lab;
    if (!preg_match('/^[a-f0-9]{32}$/D',$token)) throw new RuntimeException('Exact run token required.');
    $dir=$lab['root'].'/resilience-'.$token;
    if (is_link($dir)) throw new RuntimeException('No linked checkpoint directory.');
    if (!is_dir($dir) && !mkdir($dir,0700) && !is_dir($dir)) throw new RuntimeException('Checkpoint directory failed.');
    return $dir;
}
function resilience_write(string $path, array $value): void {
    $f=fopen($path,'x');
    if (!$f) throw new RuntimeException('Never overwrite evidence.');
    fwrite($f,json_encode($value,JSON_THROW_ON_ERROR)); fclose($f);
}
function resilience_barrier(string $token, int $index): void {
    if ($index<0 || $index>3) throw new RuntimeException('Bounded worker index required.');
    $dir=resilience_dir($token);
    resilience_write($dir.'/ready-'.$index.'.json',['pid'=>getmypid(),'at'=>microtime(true)]);
    $deadline=microtime(true)+60;
    while (!is_file($dir.'/release.json')) {
        if (microtime(true)>$deadline) throw new RuntimeException('Barrier timed out.');
        usleep(50000); clearstatcache();
    }
}
function resilience_payload(int $tenant,string $label): array {
    $profile=(new \App\Services\FseProfileService())->runtimeProfileForTenant($tenant);
    $payload=require SUPPORTPATH.'fse_synthetic.php';
    $payload['id_fse_profile']=$profile['id_fse_profile'];
    $payload['administrative_request']='NOSSN';
    $payload['document_title']='SYNTHETIC RESILIENCE '.$label;
    return $payload;
}
function resilience_proof(int $tenant,int $id): array {
    global $contexts;
    if (!in_array($tenant,[42,43],true) || $id<=0) throw new RuntimeException('Synthetic document boundary.');
    $c=$contexts->resolveTenantContext($tenant); $row=$c['documents']->find($id);
    if (!$row) return ['exists'=>false];
    $clinical=array_filter($row,static fn($k)=>str_ends_with($k,'_enc'),ARRAY_FILTER_USE_KEY);
    $events=$c['events']->listForDocument($id);
    return ['exists'=>true,'state'=>$row['local_state'],'row_sha256'=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)),
        'clinical_sha256'=>hash('sha256',json_encode($clinical,JSON_THROW_ON_ERROR)),
        'event_count'=>count($events),'events_sha256'=>hash('sha256',json_encode($events,JSON_THROW_ON_ERROR)),
        'previous_document_id'=>$row['previous_document_id'],'version_number'=>$row['version_number'],
        'diagnosis'=>(new \App\Services\FseReconciliationService())->inspect($row)];
}

class ResilienceCheckpointPdf extends \App\Services\FsePdfEnvelopeService {
    public function __construct(private string $checkpoint) {}
    public function build(string $cdaXml,array $data): string {
        // Test dependency only: real service has already acquired its persisted preparing lock.
        resilience_write($this->checkpoint,['phase'=>'after_prepare_lock_before_artifact_write','pid'=>getmypid(),'at'=>microtime(true)]);
        sleep(300);
        throw new RuntimeException('Expected process interruption did not arrive.');
    }
}
class ResilienceTransactionContexts extends \App\Services\FseTenantDatabaseContextService {
    private array $cache=[];
    public function resolveTenantContext(int $tenantId): array {
        return $this->cache[$tenantId] ??= parent::resolveTenantContext($tenantId);
    }
}

function resilience_snapshot(): array {
    global $contexts;
    $data=[];
    foreach ([42,43] as $tenant) {
        $db=$contexts->resolveTenantContext($tenant)['db'];
        foreach (['fse_documents'=>'id_fse_document','fse_document_events'=>'id_fse_event'] as $table=>$key) {
            $rows=$db->table($table)->orderBy($key)->get()->getResultArray();
            $data[$tenant][$table]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
            if ($table==='fse_documents') foreach ($rows as $row) {
                foreach (['cda','unsigned_pdf','signed_pdf'] as $kind) if (!empty($row[$kind.'_path'])) {
                    $bytes=(new \App\Services\FseArtifactValidationService())->storedArtifact($tenant,(int)$row['id_fse_document'],$row,$kind);
                    $data[$tenant]['artifacts'][$row['id_fse_document']][$kind]=hash('sha256',$bytes);
                }
            }
        }
    }
    return $data;
}

if ($action==='snapshot') {
    echo json_encode(['mode'=>'SYNTHETIC_FSE_STATE_AND_ARTIFACT_HASHES','data'=>resilience_snapshot()],JSON_THROW_ON_ERROR);
} elseif ($action==='proof') {
    echo json_encode(resilience_proof((int)($argv[3]??42),$id),JSON_THROW_ON_ERROR);
} elseif ($action==='new-draft') {
    $row=$service->saveDraftForTenant(42,resilience_payload(42,'single-'.bin2hex(random_bytes(4))),1);
    echo json_encode(['id'=>(int)$row['id_fse_document']]);
} elseif ($action==='prepare') {
    $row=$service->prepareForSignature(42,$id,1);
    echo json_encode(['id'=>$id,'state'=>$row['local_state']]);
} elseif ($action==='accept') {
    $path=$lab['root'].'/signing/synthetic-signed.pdf';
    if ($id<=0 || !is_file($path) || is_link($path)) throw new RuntimeException('Fixed synthetic signature required.');
    $row=$service->acceptSignedPdf(42,$id,(string)file_get_contents($path),1);
    echo json_encode(['id'=>$id,'state'=>$row['local_state']]);
} elseif (in_array($action,['barrier-status','release','checkpoint'],true)) {
    $dir=resilience_dir($argv[2]??'');
    if ($action==='release') resilience_write($dir.'/release.json',['at'=>microtime(true)]);
    if ($action==='checkpoint') {
        $path=$dir.'/checkpoint.json';
        echo is_file($path)?file_get_contents($path):'null';
    } else echo json_encode(['ready'=>count(glob($dir.'/ready-*.json')?:[])]);
} elseif (in_array($action,['race-save','race-prepare','race-revision','load-create'],true)) {
    $token=$argv[3]??''; $index=(int)($argv[4]??-1);
    $payload=$action==='race-save'?$service->buildFormContext(42,$id)['document']:[];
    resilience_barrier($token,$index); $started=microtime(true);
    try {
        if ($action==='race-save') {
            $payload['report_text']='SYNTHETIC concurrent edit '.$index;
            $row=$service->saveDraftForTenant(42,$payload,1); $result=['id'=>(int)$row['id_fse_document']];
        } elseif ($action==='race-prepare') {
            $row=$service->prepareForSignature(42,$id,1); $result=['id'=>$id,'state'=>$row['local_state']];
        } elseif ($action==='race-revision') {
            $result=['id'=>(new \App\Services\FseRevisionService())->create(42,$id,'SYNTHETIC concurrent revision',1)];
        } else {
            $tenant=$index%2===0?42:43; $ids=[];
            for ($n=0;$n<10;$n++) $ids[]=(int)$service->saveDraftForTenant($tenant,resilience_payload($tenant,$token.'-'.$index.'-'.$n),1)['id_fse_document'];
            $result=['tenant'=>$tenant,'ids'=>$ids];
        }
        $result=['outcome'=>'accepted']+$result;
    } catch (RuntimeException $e) {
        $result=['outcome'=>'blocked','message'=>$e->getMessage()];
    }
    echo json_encode($result+['started_at'=>$started,'finished_at'=>microtime(true),'worker'=>$index],JSON_THROW_ON_ERROR);
} elseif ($action==='crash-prepare') {
    $dir=resilience_dir($argv[3]??'');
    (new \App\Services\FseDocumentService(pdf:new ResilienceCheckpointPdf($dir.'/checkpoint.json')))->prepareForSignature(42,$id,1);
    throw new RuntimeException('Expected interruption absent.');
} elseif ($action==='crash-transaction') {
    $dir=resilience_dir($argv[2]??'');
    $tx=new ResilienceTransactionContexts(); $c=$tx->resolveTenantContext(42);
    (new \App\Services\FseSyntheticAppBoundary())->assertDatabase(42,$c['db']);
    $c['db']->transBegin();
    try {
        $row=(new \App\Services\FseDocumentService(contexts:$tx))->saveDraftForTenant(42,resilience_payload(42,'UNCOMMITTED-'.$argv[2]),1);
        $id=(int)$row['id_fse_document'];
        if (!$c['db']->transStatus() || !$c['documents']->find($id)) throw new RuntimeException('Transaction fixture failed.');
        resilience_write($dir.'/checkpoint.json',['phase'=>'uncommitted_draft_and_audit','id'=>$id,'pid'=>getmypid(),'at'=>microtime(true)]);
        sleep(300);
        throw new RuntimeException('Expected database interruption absent.');
    } finally { $c['db']->transRollback(); }
} elseif ($action==='assert-locked') {
    $before=resilience_proof(42,$id); $blocked=0;
    try { $service->saveDraftForTenant(42,$service->buildFormContext(42,$id)['document'],1); } catch (RuntimeException $e) { $blocked++; }
    try { $service->prepareForSignature(42,$id,1); } catch (RuntimeException $e) { $blocked++; }
    $after=resilience_proof(42,$id);
    if ($blocked!==2 || $before!==$after || $after['state']!=='preparing') throw new RuntimeException('Interrupted document was not protected.');
    echo json_encode(['blocked_actions'=>$blocked,'proof'=>$after],JSON_THROW_ON_ERROR);
} elseif ($action==='unsigned-copy') {
    if ($id<=0) throw new RuntimeException('Document required.');
    $file=$service->downloadArtifact(42,$id,'unsigned');
    $target=WRITEPATH.'ui-original-'.$id.'.pdf';
    if (file_exists($target)) throw new RuntimeException('Do not overwrite existing fixture.');
    if (file_put_contents($target,file_get_contents($file['path']))===false) throw new RuntimeException('Copy failed.');
    echo json_encode(['unsigned_pdf'=>$target,'document_id'=>$id]);
} else {
    throw new RuntimeException('Unsupported fixed test action.');
}
