<?php
/** Explicit synthetic cloud check. No application database, session or patient records. */
require __DIR__.'/../../rest/app/Services/Pacs/PacsException.php';
require __DIR__.'/../../rest/app/Services/Pacs/PacsTransport.php';
require __DIR__.'/../../rest/app/Services/Pacs/PacsProfiles.php';
require __DIR__.'/../../rest/app/Services/Pacs/CurlPacsTransport.php';
require __DIR__.'/../../rest/app/Services/Pacs/DicomWebClient.php';
use App\Services\Pacs\{PacsProfiles,DicomWebClient,PacsException};
$dir=__DIR__.'/../../rest/writable/pacs-cloud-lab';
$state=json_decode(file_get_contents($dir.'/state.json'),true,32,JSON_THROW_ON_ERROR);
if (($state['marker']??'')!=='AF_PACS_CLOUD_SYNTHETIC_V1' || ($state['tenantId']??0)!==4) throw new RuntimeException('Unrecognized lab.');
$expected='https://orthanc-'.$state['serviceUuid'].'.178.104.113.107.sslip.io';
if (($state['baseUrl']??'')!==$expected) throw new RuntimeException('Unexpected lab address.');
putenv('PACS_SYNTHETIC_USER='.$state['username']);
putenv('PACS_SYNTHETIC_PASSWORD='.$state['password']);
$profile=['id'=>'synthetic-cloud','label'=>'PACS sintetico','enabled'=>true,'qido_url'=>$expected.'/dicom-web','wado_url'=>$expected.'/dicom-web','auth'=>'basic','username_env'=>'PACS_SYNTHETIC_USER','password_env'=>'PACS_SYNTHETIC_PASSWORD','download_enabled'=>true];
$p=(new PacsProfiles(['tenants'=>['4'=>[$profile]]]))->get(4,'synthetic-cloud');
$client=new DicomWebClient($p);
$manifest=json_decode(file_get_contents($dir.'/fixtures.json'),true,32,JSON_THROW_ON_ERROR);
$checks=[];
try {
    foreach ($manifest as $n=>$s) {
        $rows=$client->studies($s['patient'],$s['issuer'])['studies'];
        if (count($rows)!==1 || $rows[0]['uid']!==$s['study']) throw new RuntimeException('QIDO mismatch.');
        $client->verifiedStudy($s['study'],$s['patient'],$s['issuer']);
        if ($client->series($s['study'])['rows'][0]['uid']!==$s['series']) throw new RuntimeException('Series mismatch.');
        if ($client->instances($s['study'],$s['series'])['rows'][0]['uid']!==$s['instance']) throw new RuntimeException('Instance mismatch.');
        $file=$client->download($s['study'],$s['series'],$s['instance'],$s['patient'],$s['issuer']);
        if (!hash_equals(hash_file('sha256',$dir.'/synthetic-'.($n+1).'.dcm'),hash('sha256',$file['bytes']))) throw new RuntimeException('WADO bytes mismatch.');
        $checks[]='QIDO identity, series, instance and byte-perfect WADO: fixture '.($n+1);
    }
    try { $client->verifiedStudy($manifest[0]['study'],$manifest[1]['patient'],$manifest[1]['issuer']); throw new RuntimeException('Foreign identity accepted.'); }
    catch (PacsException) { $checks[]='Foreign patient identity rejected'; }
    putenv('PACS_SYNTHETIC_PASSWORD=deliberately-wrong');
    try { $client->studies($manifest[0]['patient'],$manifest[0]['issuer']); throw new RuntimeException('Wrong credentials accepted.'); }
    catch (PacsException) { $checks[]='Wrong credentials rejected'; }
    $result=['verified_at'=>gmdate('c'),'result'=>'passed','checks'=>$checks];
    file_put_contents($dir.'/interop-result.json',json_encode($result,JSON_PRETTY_PRINT));
    echo json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;
} finally { putenv('PACS_SYNTHETIC_USER'); putenv('PACS_SYNTHETIC_PASSWORD'); }
