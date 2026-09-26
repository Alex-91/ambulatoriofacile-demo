<?php

// Standalone regression check: php tests/ts_vat_regression.php
spl_autoload_register(static function (string $class): void {
    foreach (['App\\' => '/app/', 'CodeIgniter\\' => '/system/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $path = dirname(__DIR__) . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
    }
});

use App\Services\TsVatService;
use App\Services\BillingTsBridgeService;
use App\Services\TsDocumentValidationService;
use App\Services\TsPayloadBuilderService;
use App\Services\TsCryptoService;
use App\Services\TsSecretsService;
use App\Config\TsBilling;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach ([null, '', 0, '0.00', '0,00'] as $zero) {
    check(TsVatService::normalizeRate($zero) === null, 'Zero must be absent');
}
check(TsVatService::normalizeRate('22,00') === 22.0, 'Positive rate must remain');

$invoice = ['vat_rate' => '0.00', 'vat_nature' => 'ESENTE IVA',
    'template_snapshot_json' => json_encode(['document_vat' => ['nature_code' => 'N1']])];
$profile = ['metadata_json' => json_encode(['document_defaults' => ['vat_nature_code' => 'N4']])];
$oldDraft = ['source_type' => 'billing', 'local_state' => 'to_validate', 'vat_rate' => '0.00', 'vat_nature' => 'ART 10'];
$updatedDraft = TsVatService::refreshPendingBillingDocument($oldDraft, $profile);
check($updatedDraft['vat_rate'] === null && $updatedDraft['vat_nature'] === 'N4', 'Existing billing draft must refresh from TS profile');
foreach (['sent', 'sending', 'cancelled'] as $lockedState) {
    $locked = array_replace($oldDraft, ['local_state' => $lockedState]);
    check(TsVatService::refreshPendingBillingDocument($locked, $profile) === $locked, 'Transmitted or sending documents must stay unchanged');
}
foreach ([['source_type' => 'manual'], ['source_type' => 'ts_variation'], ['ts_protocol' => 'existing-protocol'], ['ts_state' => 'accepted']] as $protectedFields) {
    $protected = array_replace($oldDraft, $protectedFields);
    check(TsVatService::refreshPendingBillingDocument($protected, $profile) === $protected, 'Historical and manual data must stay unchanged');
}
$noDefault = TsVatService::refreshPendingBillingDocument($oldDraft, []);
check($noDefault['vat_nature'] === null, 'Missing profile default must not reuse description');
check(TsVatService::billingNatureCode($invoice, $profile) === 'N4', 'TS profile must override old invoice code and printed text');
check(TsVatService::billingNatureCode(['vat_nature' => 'N2.2']) === '', 'Printed code must never become TS nature');
check(TsVatService::billingNatureCode(['vat_nature' => 'ESENTE IVA']) === '', 'Never send printed text as a code');
check(TsVatService::billingNatureCode($invoice) === '', 'Missing TS default must not use old invoice code');
check(TsVatService::billingNatureCode(['vat_rate' => 22, 'vat_nature' => 'ESENTE IVA'], $profile) === '', 'Positive rate must suppress profile nature');

$bridge = (new ReflectionClass(BillingTsBridgeService::class))->newInstanceWithoutConstructor();
$payloadMethod = new ReflectionMethod($bridge, 'buildTsValidationPayload');
$payload = $payloadMethod->invoke($bridge, $invoice, $profile, 1);
check($payload['vat_rate'] === null && $payload['vat_nature'] === 'N4', 'Bridge must use TS default and omit zero');
$taxed = $payloadMethod->invoke($bridge, array_replace($invoice, ['vat_rate' => 22]), $profile, 1);
check($taxed['vat_rate'] === 22.0 && $taxed['vat_nature'] === '', 'Bridge must send positive rate only');
$recordMethod = new ReflectionMethod($bridge, 'buildTsDocumentRecord');
$record = $recordMethod->invoke($bridge, $invoice, $profile, 1, 'test-hash', ['valid' => true], 0, null);
check($record['vat_rate'] === null && $record['vat_nature'] === 'N4', 'TS record must store profile code and no zero rate');
check($invoice['vat_nature'] === 'ESENTE IVA', 'Printed description must remain intact');

$config = (new ReflectionClass(TsBilling::class))->newInstanceWithoutConstructor();
$profiles = (new ReflectionClass(App\Services\TsProfileService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($profiles, 'config'))->setValue($profiles, $config);
$normalize = new ReflectionMethod($profiles, 'normalizePayloadForSave');
$state = $normalize->invoke($profiles, ['default_vat_nature_code' => ' n2.2 '], null);
$metadata = json_decode($state['metadata_json'], true);
check($metadata['document_defaults']['vat_nature_code'] === 'N2.2', 'TS profile must persist normalized default');
$view = (new ReflectionMethod($profiles, 'buildViewProfile'))->invoke($profiles, ['metadata_json' => $state['metadata_json']]);
check($view['document_defaults']['vat_nature_code'] === 'N2.2', 'TS settings must reload saved default');
$preserved = $normalize->invoke($profiles, [], ['metadata_json' => $state['metadata_json']]);
check(TsVatService::profileNatureCode($preserved) === 'N2.2', 'Unrelated settings save must preserve default');
$cleared = $normalize->invoke($profiles, ['default_vat_nature_code' => ''], ['metadata_json' => $state['metadata_json']]);
check(TsVatService::profileNatureCode($cleared) === '', 'Default must be clearable');
try {
    $normalize->invoke($profiles, ['default_vat_nature_code' => 'ESENTE IVA'], null);
    throw new LogicException('Invalid TS default was accepted');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'codice natura IVA'), 'Invalid TS default must explain expected format');
}

$config = (new ReflectionClass(TsBilling::class))->newInstanceWithoutConstructor();
$validator = new TsDocumentValidationService($config);
$vatErrors = static function ($rate, string $code) use ($validator): array {
    $result = $validator->validateDraft(['document_type' => 'F', 'vat_rate' => $rate, 'vat_nature' => $code], null);
    return array_values(array_filter($result['errors'], static fn ($error) => str_contains($error, 'aliquota IVA') || str_contains($error, 'natura IVA')));
};
check($vatErrors('0,00', 'N1') === [], 'Zero and N1 must validate');
check($vatErrors(0, 'N2.2') === [], 'Zero and N2.2 must validate');
check(count($vatErrors(22, 'N1')) > 0, 'Positive rate and nature must conflict');
check(count($vatErrors(0, '')) > 0, 'Invoice with neither IVA field must fail');
check(count($vatErrors(0, 'ESENTE IVA')) > 0, 'Description is not a nature code');
check($vatErrors(22, '') === [], 'Positive rate alone must validate');

$crypto = new class extends TsCryptoService {
    public function __construct() {}
    public function encryptRequired(string $plainText): string { return 'test-encrypted'; }
};
$builder = new TsPayloadBuilderService(new TsSecretsService(), $crypto);
$soapMethod = new ReflectionMethod($builder, 'buildDocumentoSpesaPayload');
$soap = $soapMethod->invoke($builder, ['document' => ['vat_rate' => '0.00', 'vat_nature' => 'N1']]);
check(!isset($soap['voceSpesa']['aliquotaIVA']) && $soap['voceSpesa']['naturaIVA'] === 'N1', 'SOAP must send nature only for zero rate');
$soap = $soapMethod->invoke($builder, ['document' => ['vat_rate' => 22, 'vat_nature' => '']]);
check(isset($soap['voceSpesa']['aliquotaIVA']) && !isset($soap['voceSpesa']['naturaIVA']), 'SOAP must preserve positive rate');

echo "PASS: TS profile default persistence, invoice text isolation, zero/positive rates, bridge, validation and SOAP payload\n";
