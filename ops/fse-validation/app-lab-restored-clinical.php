<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/app-lab-common.php';$lab=fse_lab_config();fse_lab_boot();
$root=$lab['root'].'/full-backup-drill';$report=json_decode(file_get_contents($root.'/report.json'),true);
if(($report['status'] ?? '')!=='passed') throw new RuntimeException('Verified full restore required.');
$db=\Config\Database::connect(array_replace((new \Config\Database())->default,['hostname'=>'127.0.0.1','port'=>33089,'database'=>'fselab_a','username'=>'root','password'=>'']),false);
if(realpath($db->query('SELECT @@datadir p')->getRowArray()['p'])!==realpath($lab['root'].'/restore-mysql')) throw new RuntimeException('Wrong restored instance.');
$vault=new \App\Services\ClinicalVault(42,null,$root.'/restored/writable/clinical-private');
$clinical=new \App\Services\ClinicalRecordService($db,42,1,$vault,true);
$patient=$clinical->patient(100);$signed=0;
foreach($patient['entries'] as $entry){
    if($entry['state']!=='signed') continue;
    $file=$clinical->download(100,$entry['signed_object_id']);
    $evidence=json_decode($entry['signature_evidence_json'],true);
    if(!hash_equals($evidence['signed_sha256'],hash('sha256',$file['bytes']))) throw new RuntimeException('Restored signature hash mismatch.');
    $signed++;
}
if($signed!==2 || count($patient['consents'])!==2 || $patient['shared']) throw new RuntimeException('Restored clinical history mismatch.');
$report['checks'][]='restored_chart_decrypts_and_preserves_both_signatures_and_revocation';
file_put_contents($root.'/report.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "Restored clinical chart readable; two verified signatures and consent revocation preserved.\n";
