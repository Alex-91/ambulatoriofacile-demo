<?php
// Uses the real PHP->Python worker, but never bootstraps CI, .env, routes or databases.
if (PHP_SAPI!=='cli') exit(1);
$options=getopt('',['python:','settings:','manifest:']);
$repo=realpath(__DIR__.'/../..');
$run=$repo.'/rest/writable/fse-stage-smoke-'.bin2hex(random_bytes(8));
$report=['mode'=>'ISOLATED_APP_RUNTIME_SMOKE','started_at'=>gmdate('c'),'status'=>'incomplete','network_calls'=>0,
    'production_access'=>false,'clinical_documents_used'=>0,'official_accreditation_evidence'=>false,
    'php'=>PHP_VERSION,'system'=>PHP_OS_FAMILY,'full_application_e2e'=>'NOT_EXECUTED'];
try {
    if (!isset($options['python'],$options['settings']) || !is_file($options['python']) || !is_file($options['settings'])) throw new RuntimeException('STAGE_RUNTIME_ARGUMENTS');
    $report['source_sha256']=[];
    $sources=['rest/app/Config/Fse2.php','rest/app/Services/FseArtifactValidationService.php','rest/app/Services/FseValidatorEnvironment.php','rest/app/Services/FseValidationJobs.php',
        'rest/app/Services/FseCdaRsaBuilderService.php','rest/app/Services/FseSyntheticDocument.php','ops/fse-validation/validator.py'];
    foreach ($sources as $path) $report['source_sha256'][$path]=hash_file('sha256',$repo.'/'.$path);
    if (isset($options['manifest'])) {
        $manifest=json_decode(file_get_contents($options['manifest']),true,32,JSON_THROW_ON_ERROR);
        if (($manifest['mode'] ?? '')!=='APP_SOURCE_FOR_ISOLATED_STAGE' || ($manifest['files'] ?? [])!==$report['source_sha256']) throw new RuntimeException('STAGE_APP_SOURCE_MISMATCH');
    }
    if (version_compare(PHP_VERSION,'8.2','<')) throw new RuntimeException('STAGE_PHP_VERSION');
    foreach (['dom','openssl','curl','mbstring'] as $extension) if (!extension_loaded($extension)) throw new RuntimeException('STAGE_PHP_EXTENSION');
    if (!mkdir($run,0700)) throw new RuntimeException('STAGE_PRIVATE_STORAGE');
    define('WRITEPATH',$run.'/'); define('APPPATH',$repo.'/rest/app/');
    require __DIR__.'/gateway-bootstrap.php';
    foreach (['FseValidationJobs','FseValidatorEnvironment','FseArtifactValidationService','FseCdaRsaBuilderService','FseSyntheticDocument'] as $class) require_once APPPATH.'Services/'.$class.'.php';
    if (!function_exists('log_message')) { function log_message($level,$message): void { /* Never print worker/private paths. */ } }
    $config=new \App\Config\Fse2(); $config->validatorPython=realpath($options['python']); $config->validatorSettings=realpath($options['settings']);
    $validator=new \App\Services\FseArtifactValidationService($config);
    $report['readiness']=$validator->readiness(true);
    if (($report['readiness']['artifacts'] ?? '')!=='passed') throw new RuntimeException('STAGE_HEALTH_FAILED');
    $cda=(new \App\Services\FseCdaRsaBuilderService())->build(\App\Services\FseSyntheticDocument::data());
    $pdf=$validator->buildPdf($cda); $report['artifact_check']=$validator->check($cda,$pdf);
    try { $validator->check($cda.' ',$pdf); throw new RuntimeException('STAGE_ALTERATION_ACCEPTED'); }
    catch (RuntimeException $e) { if (!str_contains($e->getMessage(),'PDF_CDA_MISMATCH')) throw new RuntimeException('STAGE_NEGATIVE_CHECK_FAILED'); }
    $report['alteration_rejected']=true;
    $report['status']='passed_app_runtime_smoke';
} catch (Throwable $e) {
    $report['status']='failed_or_incomplete';
    $report['error_code']=preg_match('/^STAGE_[A-Z_]+$/D',$e->getMessage()) ? $e->getMessage() : 'STAGE_CHECK_FAILED';
}
$report['finished_at']=gmdate('c');
if (is_dir($run)) file_put_contents($run.'/report.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($report['status']==='passed_app_runtime_smoke' ? 0 : 2);
