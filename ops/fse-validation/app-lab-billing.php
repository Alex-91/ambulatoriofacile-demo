<?php
// Synthetic lab seed and read-only HTTP assertions; never load this from an application route.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/app-lab-common.php';
$lab = fse_lab_config();
$action = $argv[1] ?? '';
if (!in_array($action, ['seed', 'schema', 'snapshot'], true) || !is_file($lab['root'] . '/seeded.json')) {
    throw new RuntimeException('Seeded synthetic lab and explicit action required.');
}
fse_lab_boot();
$platform = \Config\Database::connect('platform');
if ($platform->hostname !== '127.0.0.1' || (int) $platform->port !== 33079 || $platform->database !== 'fselab_platform'
    || realpath($platform->query('SELECT @@datadir AS path')->getRowArray()['path']) !== realpath($lab['root'] . '/mysql')) {
    throw new RuntimeException('PLATFORM_LAB_BOUNDARY');
}
$contexts = [];
foreach ([42, 43] as $tenant) {
    $contexts[$tenant] = (new \App\Services\BillingTenantDatabaseContextService())->resolveTenantContext($tenant);
    (new \App\Services\FseSyntheticAppBoundary())->assertDatabase($tenant, $contexts[$tenant]['db']);
}
$marker = $lab['root'] . '/billing-seeded.json';
if ($action !== 'snapshot') {
    if (is_file($marker)) throw new RuntimeException('Billing lab already seeded; never overwrite.');
    foreach ($contexts as $context) {
        if ($context['db']->tableExists('billing_documents') && $context['db']->table('billing_documents')->countAllResults() !== 0) {
            throw new RuntimeException('Never initialize over existing billing documents.');
        }
    }
}
if ($action === 'schema') {
    $tenant = (int) ($argv[2] ?? 0);
    if (!isset($contexts[$tenant])) throw new RuntimeException('Synthetic tenant required.');
    $schema = (new \App\Services\BillingTenantSchemaService())->ensureTenantSchemaReady($tenant, true);
    if (!$schema['ready']) throw new RuntimeException('Synthetic billing migration failed.');
    exit;
}
if ($action === 'seed') {
    foreach ([
        '2026-06-22-000003_RepairTenantFeaturePreferencesSchema' => 'RepairTenantFeaturePreferencesSchema',
        '2026-07-04-000002_CreatePlatformTenantTsProfiles' => 'CreatePlatformTenantTsProfiles',
        '2026-07-06-000001_AddBillingFeature' => 'AddBillingFeature',
    ] as $file => $class) {
        require_once APPPATH . 'Database/Migrations/' . $file . '.php';
        $name = 'App\\Database\\Migrations\\' . $class;
        (new $name(\Config\Database::forge($platform)))->up();
    }
    $feature = $platform->table('platform_features')->where('feature_key', 'billing')->get()->getRowArray();
    foreach ([42, 43] as $tenant) {
        $grant = $platform->table('platform_tenant_features')->where('id_tenant', $tenant)->where('id_feature', $feature['id_feature'])->get()->getRowArray();
        if (!$grant) $platform->table('platform_tenant_features')->insert(['id_tenant' => $tenant, 'id_feature' => $feature['id_feature'], 'is_enabled' => 1]);
        elseif ((int) $grant['is_enabled'] !== 1) throw new RuntimeException('Unexpected synthetic billing grant.');
        // The existing runner copies migration classes to a unique path per tenant. Use separate
        // processes (as in web requests), avoiding PHP class redeclaration across tenant repairs.
        $process = proc_open([PHP_BINARY, __FILE__, 'schema', (string) $tenant], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Synthetic schema process failed.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Synthetic billing migration failed: ' . $output . $errors);
        (new \App\Services\BillingDocumentSettingsService())->saveTenantSettings($tenant, [
            'branding' => ['logo_mode' => 'none', 'logo_url' => ''],
            'fiscal_data' => ['business_name' => 'STUDIO FITTIZIO ' . $tenant . ' - COLLAUDO SENZA VALORE FISCALE'],
        ], $tenant);
    }
    file_put_contents($marker, json_encode(['mode' => 'SYNTHETIC_BILLING_HTTP', 'seeded_at' => gmdate('c'), 'tenants' => [42,43], 'dispatch_enabled' => false], JSON_PRETTY_PRINT));
    echo "Synthetic billing schema ready. No TS profile, credentials or email delivery configured.\n";
    exit;
}
if (!is_file($marker)) throw new RuntimeException('Billing seed required.');
$snapshot = ['tenants' => [], 'ts_profile_count' => $platform->table('platform_tenant_ts_profiles')->countAllResults()];
foreach ($contexts as $tenant => $context) {
    $db = $context['db'];
    $rows = $db->table('billing_documents')->orderBy('id_billing_document')->get()->getResultArray();
    $preferences = $platform->table('platform_tenant_feature_preferences')->where('id_tenant', $tenant)->orderBy('id_feature')->get()->getResultArray();
    $snapshot['tenants'][$tenant] = [
        'documents' => $rows,
        'documents_sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
        'preferences_sha256' => hash('sha256', json_encode($preferences, JSON_THROW_ON_ERROR)),
        'catalog_actor_ids' => array_column($preferences, 'updated_by_platform_user'),
        'catalog_item_counts' => array_map(static fn(array $row): int => count(json_decode($row['config_json'] ?? '{}', true)['service_catalog'] ?? []), $preferences),
        'email_log_count' => $db->tableExists('billing_document_email_log') ? $db->table('billing_document_email_log')->countAllResults() : 0,
        'ts_document_count' => $db->tableExists('ts_documents') ? $db->table('ts_documents')->countAllResults() : 0,
    ];
}
echo json_encode($snapshot, JSON_THROW_ON_ERROR);
