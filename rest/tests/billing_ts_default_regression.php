<?php
require __DIR__ . '/billing_preferences_regression.php';

$build = new ReflectionMethod($service, 'buildDefaultDocument');
$template['defaults']['ts_expense_type_code'] = 'SP';
$profile = ['metadata_json' => json_encode(['document_defaults' => ['expense_type_code' => 'SR']])];
$new = $build->invoke($service, $template, true, null, $profile);
check($new['ts_expense_type_code'] === 'SR', 'TS profile default must override legacy billing SP');
$profile['metadata_json'] = json_encode(['document_defaults' => ['expense_type_code' => 'AS']]);
check($build->invoke($service, $template, true, null, $profile)['ts_expense_type_code'] === 'AS', 'Changes to TS defaults must apply to the next invoice');
foreach ([null, ['metadata_json' => '{}'], ['metadata_json' => '{invalid'], ['metadata_json' => '{"document_defaults":{"expense_type_code":"INVALID"}}']] as $missing) {
    check($build->invoke($service, $template, true, null, $missing)['ts_expense_type_code'] === 'SP', 'Missing or invalid TS settings must retain billing fallback');
}
echo "PASS: new invoice TS defaults follow profile with safe legacy fallback\n";
