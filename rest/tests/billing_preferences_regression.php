<?php
require __DIR__ . '/ts_vat_regression.php';

$settings = (new ReflectionClass(App\Services\BillingDocumentSettingsService::class))->newInstanceWithoutConstructor();
$sanitize = new ReflectionMethod($settings, 'sanitizeConfig');
$template = $sanitize->invoke($settings, [
    'defaults' => ['stamp_duty_amount' => '2,00'],
    'fields' => ['show_patient_address' => true, 'show_patient_email' => false],
]);
check((float) $template['defaults']['stamp_duty_amount'] === 2.0, 'Stamp default must accept comma decimals');
check($template['fields']['show_patient_address'] && !$template['fields']['show_patient_email'], 'Independent patient field settings must persist');
$service = (new ReflectionClass(App\Services\BillingDocumentService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($service, 'tsConfig'))->setValue($service, $config);
$new = (new ReflectionMethod($service, 'buildDefaultDocument'))->invoke($service, $template, false);
check((float) $new['stamp_duty_amount'] === 2.0, 'New invoices must receive configured stamp');
$defaults = $settings->defaultConfig();
check((float) $defaults['defaults']['stamp_duty_amount'] === 0.0, 'Existing tenants must not gain automatic charges');
foreach (['address', 'city', 'email', 'phone', 'mobile'] as $key) {
    check(!$defaults['fields']['show_patient_' . $key], 'Additional contact data must be opt-in');
}
if (!function_exists('esc')) {
    function esc($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}
$template['patient_details'] = ['patient_address' => '<Via Roma>', 'patient_email' => 'hidden@example.com'];
$fields = $template['fields'];
$document = [];
ob_start();
require dirname(__DIR__) . '/app/Views/admin/billing/patient_details.php';
$rendered = ob_get_clean();
check(str_contains($rendered, '&lt;Via Roma&gt;'), 'Selected snapshot data must render escaped');
check(!str_contains($rendered, 'hidden@example.com'), 'Disabled patient data must not render');
echo "PASS: billing stamp defaults, independent visibility, private field omission and safe rendering\n";
