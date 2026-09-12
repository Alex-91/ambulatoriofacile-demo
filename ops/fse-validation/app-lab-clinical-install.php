<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/app-lab-common.php';$lab=fse_lab_config();fse_lab_boot();
if(!is_file($lab['root'].'/clinical-seeded.json')) throw new RuntimeException('Synthetic clinical seed required.');
foreach([42,43] as $tenant) {
    $context=(new \App\Services\TsTenantDatabaseContextService())->resolveTenantContext($tenant);
    (new \App\Services\FseSyntheticAppBoundary())->assertDatabase($tenant,$context['db']);
}
$command=new \App\Commands\ClinicalInstall(service('logger'),service('commands'));
foreach([42,43] as $tenant) if($command->run([(string)$tenant])!==EXIT_SUCCESS) throw new RuntimeException('Clinical install verification failed.');
echo json_encode(['status'=>'passed','mode'=>\CodeIgniter\CLI\CLI::getOption('apply') ? 'SYNTHETIC_CLINICAL_INSTALL_APPLY' : 'SYNTHETIC_CLINICAL_INSTALL_CHECK']);
