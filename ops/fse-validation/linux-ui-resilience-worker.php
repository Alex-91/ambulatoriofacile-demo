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
