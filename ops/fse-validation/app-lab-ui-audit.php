<?php
/** Read-only corroboration AFTER the separately recorded browser exercise; not a UI test runner. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/app-lab-common.php';
require __DIR__.'/lab-recovery-pair.php';
$lab = fse_lab_config();
if (!is_file($lab['root'].'/seeded.json')) throw new RuntimeException('Seeded synthetic lab required.');
$server = new mysqli('127.0.0.1','root',$lab['password'],'',33079);
if (realpath($server->query('SELECT @@datadir AS path')->fetch_assoc()['path']) !== realpath($lab['root'].'/mysql')) throw new RuntimeException('Wrong MySQL instance.');
fse_lab_boot();
$report = ['mode'=>'SYNTHETIC_BROWSER_STATE_CORROBORATION', 'status'=>'incomplete',
    'browser_actions_automated_by_this_script'=>false, 'official_accreditation_evidence'=>false,
    'external_gateway_tests'=>'NOT_EXECUTED', 'started_at'=>gmdate('c'), 'checks'=>[]];
function ui_lab_check(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException('Synthetic state audit failed: '.$name);
    $GLOBALS['report']['checks'][$name]='passed';
}
$reportPath=$lab['root'].'/browser-state-'.bin2hex(random_bytes(8)).'.json';
$server->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
try {
    $rows=$server->query('SELECT * FROM fselab_a.fse_documents ORDER BY id_fse_document')->fetch_all(MYSQLI_ASSOC);
    [$firstId,$secondId]=fse_lab_revision_pair($rows);
    $byId=array_column($rows,null,'id_fse_document'); $first=$byId[$firstId]; $second=$byId[$secondId];
    ui_lab_check(count($rows)===2 && $second['local_state']==='ready_to_validate', 'one_signed_original_and_one_prepared_revision');
    ui_lab_check(empty($second['signed_pdf_path']) && empty($second['signed_pdf_sha256']), 'old_signed_pdf_not_attached_to_revision');
    ui_lab_check((int)$server->query('SELECT COUNT(*) AS n FROM fselab_b.fse_documents')->fetch_assoc()['n']===0, 'studio_b_has_no_documents');
    $validator=new \App\Services\FseArtifactValidationService();
    foreach ([$firstId=>$first,$secondId=>$second] as $id=>$row) {
        foreach (['cda','unsigned_pdf','signed_pdf'] as $kind) {
            if (empty($row[$kind.'_path'])) continue;
            $contents=$validator->storedArtifact(42,$id,$row,$kind);
            ui_lab_check(hash_equals($row[$kind.'_sha256'],hash('sha256',$contents)), 'artifact_'.$id.'_'.$kind.'_integrity');
        }
        ui_lab_check(empty($row['workflow_instance_id']) && empty($row['published_at']), 'document_'.$id.'_has_no_real_publication');
    }
    $statePath=WRITEPATH.'fse2/toscana-lab/tenant-42.json';
    ui_lab_check(is_file($statePath) && !is_link($statePath), 'separate_simulation_state_exists');
    $state=json_decode(file_get_contents($statePath),true,32,JSON_THROW_ON_ERROR);
    $sim=$state['documents']['SIM.'.$firstId]??[];
    ui_lab_check(($state['mode']??'')==='TOSCANA_SYNTHETIC_WORKFLOW_V1' && ($state['tenant_id']??0)===42
        && ($state['network_calls']??-1)===0 && ($state['official_accreditation_evidence']??true)===false, 'simulation_is_synthetic_and_has_zero_network_calls');
    ui_lab_check(($sim['state']??'')==='published' && ($sim['sealed_hash']??'')===$first['signed_pdf_sha256']
        && $first['local_state']==='signed', 'simulated_publication_did_not_publish_original');
    $xml=new DOMDocument();
    ui_lab_check($xml->loadXML($validator->storedArtifact(42,$secondId,$second,'cda'),LIBXML_NONET), 'revision_cda_parseable');
    $xp=new DOMXPath($xml); $xp->registerNamespace('h','urn:hl7-org:v3');
    ui_lab_check($xp->evaluate('string(/h:ClinicalDocument/h:relatedDocument/@typeCode)')==='RPLC'
        && $xp->evaluate('string(/h:ClinicalDocument/h:relatedDocument/h:parentDocument/h:id/@extension)')===$first['document_unique_id'], 'cda_revision_references_original');
    $report['version_pair']=[$firstId,$secondId];
    $report['signed_pdf_sha256']=$first['signed_pdf_sha256'];
    $report['source_rows_sha256']=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
    $report['status']='passed_state_corroboration';
} finally {
    $server->rollback(); $server->close();
    $report['finished_at']=gmdate('c');
    file_put_contents($reportPath,json_encode($report,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    echo 'Synthetic browser state audit: '.$reportPath.PHP_EOL;
}
