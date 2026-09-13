<?php
/** Fixed test-only pause inside the actual application transaction, before audit. */
if (PHP_SAPI!=='cli' || PHP_OS_FAMILY!=='Linux' || !is_file('/opt/fse/linux-lab-image')) throw new RuntimeException('Synthetic Linux only.');
require '/var/www/html/ops/fse-validation/app-lab-common.php';
$lab=fse_lab_config();
if (($lab['runtime']??'')!=='linux-container' || !is_file($lab['root'].'/seeded.json')) throw new RuntimeException('Seeded Linux lab required.');
fse_lab_boot();

class AtomicCheckpointAudit extends \App\Services\FseAuditService
{
    public function __construct(\App\Models\FseDocumentEventModel $events,private string $checkpoint) { parent::__construct($events); }
    public function record(int $documentId,string $type,string $message,array $context=[],int $userId=0,string $level='info',bool $required=false): void
    {
        if (!$required || !in_array($type,['draft_saved','artifacts_prepared'],true)) throw new RuntimeException('Wrong application audit boundary.');
        $f=fopen($this->checkpoint,'x');
        if (!$f) throw new RuntimeException('No checkpoint overwrite.');
        fwrite($f,json_encode(['phase'=>'application_write_before_required_audit','id'=>$documentId,'event'=>$type,'pid'=>getmypid(),'at'=>microtime(true)],JSON_THROW_ON_ERROR));
        fclose($f);
        sleep(240);
        throw new RuntimeException('Expected controlled interruption did not occur.');
    }
}
class AtomicContexts extends \App\Services\FseTenantDatabaseContextService
{
    private array $cache=[];
    public function __construct(private ?string $checkpoint=null) { parent::__construct(); }
    public function resolveTenantContext(int $tenantId): array
    {
        if (!in_array($tenantId,[42,43],true)) throw new RuntimeException('Only synthetic tenants.');
        if (!isset($this->cache[$tenantId])) {
            $c=parent::resolveTenantContext($tenantId);
            (new \App\Services\FseSyntheticAppBoundary())->assertDatabase($tenantId,$c['db']);
            if ($this->checkpoint!==null) $c['audit']=new AtomicCheckpointAudit($c['events'],$this->checkpoint);
            $this->cache[$tenantId]=$c;
        }
        return $this->cache[$tenantId];
    }
}
$action=$argv[1]??'';
if ($action==='checkpoint' || $action==='fault') {
    $token=$argv[2]??'';
    if (!preg_match('/^[a-f0-9]{32}$/D',$token)) throw new RuntimeException('Exact token required.');
    $checkpoint=$lab['root'].'/atomic-checkpoint-'.$token.'.json';
    if ($action==='checkpoint') { echo is_file($checkpoint)?file_get_contents($checkpoint):'null'; exit; }
    $mode=$argv[3]??''; $id=(int)($argv[4]??0);
    if (!in_array($mode,['new','edit','prepare'],true) || ($mode!=='new' && $id<=0)) throw new RuntimeException('Fixed scenario required.');
    $contexts=new AtomicContexts($checkpoint);
    $service=new \App\Services\FseDocumentService(contexts:$contexts);
    if ($mode==='prepare') $service->prepareForSignature(42,$id,1);
    else {
        $payload=$mode==='new'?require SUPPORTPATH.'fse_synthetic.php':$service->runtimeDocument(42,$id);
        $payload['administrative_request']='NOSSN';
        $payload['report_text']='SYNTHETIC uncommitted operation '.$token;
        $service->saveDraftForTenant(42,$payload,1);
    }
    throw new RuntimeException('Interruption absent.');
}
if ($action==='proof') {
    $id=(int)($argv[2]??0); $contexts=new AtomicContexts(); $c=$contexts->resolveTenantContext(42);
    $row=$c['documents']->find($id);
    if (!$row) { echo '{"exists":false}'; exit; }
    $stable=$row; unset($stable['local_state'],$stable['updated_at']);
    $artifacts=[];
    foreach (['cda','unsigned_pdf','signed_pdf'] as $kind) if (!empty($row[$kind.'_path'])) {
        $artifacts[$kind]=hash('sha256',(new \App\Services\FseArtifactValidationService())->storedArtifact(42,$id,$row,$kind));
    }
    echo json_encode(['exists'=>true,'state'=>$row['local_state'],'stable_row_sha256'=>hash('sha256',json_encode($stable,JSON_THROW_ON_ERROR)),
        'artifacts'=>$artifacts,'events_sha256'=>hash('sha256',json_encode($c['events']->listForDocument($id),JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR);
    exit;
}
throw new RuntimeException('Unsupported fixed action.');
