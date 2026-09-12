<?php
// Fixed synthetic CLI-only entitlement changes; never selected by a public route.
if (PHP_SAPI!=='cli') exit(1);
require __DIR__.'/app-lab-common.php';
$lab=fse_lab_config();
if (!is_file($lab['root'].'/clinical-seeded.json')) throw new RuntimeException('Clinical synthetic seed required.');
fse_lab_boot();
$context=(new \App\Services\TsTenantDatabaseContextService())->resolveTenantContext(42);
(new \App\Services\FseSyntheticAppBoundary())->assertDatabase(42,$context['db']);
$platform=\Config\Database::connect('platform');
$action=$argv[1] ?? '';
$feature=$platform->table('platform_features')->where('feature_key','clinical_records')->get()->getRowArray();
if (in_array($action,['enable','disable'],true)) {
    $platform->table('platform_tenant_features')->where('id_tenant',42)->where('id_feature',$feature['id_feature'])->update(['is_enabled'=>$action==='enable' ? 1 : 0]);
} elseif ($action==='snapshot') {
    $hashes=[];
    foreach (['clinical_entries','clinical_objects','clinical_consents','clinical_audit','clinical_patient_state','clinical_consent_templates'] as $table) {
        $hashes[$table]=hash('sha256',json_encode($context['db']->query('SELECT * FROM '.$table.' ORDER BY 1')->getResultArray(),JSON_THROW_ON_ERROR));
    }
    echo json_encode($hashes,JSON_THROW_ON_ERROR);
} else throw new RuntimeException('Unsupported synthetic action.');
