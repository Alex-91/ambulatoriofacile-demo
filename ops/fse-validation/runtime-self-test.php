<?php
// CLI-only, synthetic and offline. Never bootstrap the application, .env or a DB.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$private = sys_get_temp_dir() . '/af-fse-selftest-' . bin2hex(random_bytes(12));
$report = ['mode'=>'PRODUCTION_IMAGE_OFFLINE_SELF_TEST', 'status'=>'failed',
    'clinical_data_used'=>false, 'database_access'=>false, 'gateway_calls'=>0,
    'official_accreditation_evidence'=>false, 'php'=>PHP_VERSION];
try {
    if (!mkdir($private, 0700)) throw new RuntimeException('SELF_TEST_STORAGE');
    define('WRITEPATH', $private . '/');
    define('APPPATH', $root . '/rest/app/');
    function env(string $key, $default = null) {
        // Only the technical FSE settings explicitly listed here are read.
        if (!in_array($key, ['FSE2_VALIDATOR_PYTHON','FSE2_VALIDATOR_SETTINGS',
            'FSE2_VALIDATOR_MAX_CONCURRENT','FSE2_VALIDATOR_MIN_AVAILABLE_MIB',
            'FSE2_ALLOW_PRODUCTION','FSE2_ALLOW_TOSCANA_STAGE'], true)) return $default;
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
    function log_message($level, $message): void { /* No private worker output. */ }
    require $root . '/rest/system/Config/BaseConfig.php';
    require APPPATH . 'Config/Fse2.php';
    foreach (['FseValidationJobs','FseValidatorEnvironment','FseArtifactValidationService',
        'FseCdaRsaBuilderService','FseSyntheticDocument'] as $class) require APPPATH . 'Services/' . $class . '.php';
    $config = new \App\Config\Fse2();
    if ($config->allowProduction || $config->allowToscanaStage) throw new RuntimeException('SELF_TEST_LIVE_FLAGS');
    $validator = new \App\Services\FseArtifactValidationService($config);
    $report['readiness'] = $validator->readiness(true);
    if (($report['readiness']['artifacts'] ?? '') !== 'passed') throw new RuntimeException('SELF_TEST_RUNTIME');
    if (($report['readiness']['trust_material'] ?? '') !== 'missing') throw new RuntimeException('SELF_TEST_UNEXPECTED_TRUST');
    $cda = (new \App\Services\FseCdaRsaBuilderService())->build(\App\Services\FseSyntheticDocument::data());
    $pdf = $validator->buildPdf($cda);
    $report['artifact_check'] = $validator->check($cda, $pdf);
    try { $validator->check($cda . ' ', $pdf); throw new RuntimeException('SELF_TEST_ALTERATION_ACCEPTED'); }
    catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'PDF_CDA_MISMATCH')) throw new RuntimeException('SELF_TEST_NEGATIVE_FAILED');
    }
    $report['alteration_rejected'] = true;
    $report['production_sends_enabled'] = false;
    $report['toscana_stage_enabled'] = false;
    $report['status'] = 'passed_offline_runtime';
} catch (Throwable $e) {
    $report['error_code'] = preg_match('/^SELF_TEST_[A-Z_]+$/D', $e->getMessage()) ? $e->getMessage() : 'SELF_TEST_FAILED';
}
$report['completed_at'] = gmdate('c');
// Clean only recognized empty self-test directories and semaphore files, never recurse.
foreach (['slot-0.lock','maintenance.lock'] as $name) {
    $path = $private . '/fse2/validation-jobs/' . $name;
    if (is_file($path)) @unlink($path);
}
foreach ([$private . '/fse2/validation-jobs', $private . '/fse2', $private] as $path) if (is_dir($path)) @rmdir($path);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($report['status'] === 'passed_offline_runtime' ? 0 : 2);
