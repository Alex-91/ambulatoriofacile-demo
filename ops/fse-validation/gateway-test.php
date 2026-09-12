<?php
require __DIR__ . '/gateway-bootstrap.php';
require __DIR__ . '/gateway-diagnostic-transport.php';
require __DIR__ . '/gateway-negative-jwt.php';

use App\Config\Fse2;
use App\Services\FseCertificateInspector;
use App\Services\FseGatewayClient;
use App\Services\FseJwtService;

// Only pinned official examples or a synthetic app fixture, national test endpoint, VERIFICA only.
try {
    $options = getopt('', ['credentials:', 'case:', 'negative:', 'send']);
    if (!isset($options['credentials']) || array_diff(array_keys($options), ['credentials', 'case', 'negative', 'send'])) {
        throw new RuntimeException('Uso: php gateway-test.php --credentials=received-<hash> [--case=1] [--send]');
    }
    $credentialName = (string) $options['credentials'];
    if (!preg_match('/^received-[a-f0-9]{16}$/D', $credentialName)) throw new RuntimeException('Directory credenziali non ammessa.');
    $case = (string) ($options['case'] ?? '1');
    $negative = (string) ($options['negative'] ?? '');
    if (!in_array($negative,['','jwt-missing-purpose','jwt-action-invalid','cda-no-given','cda-gender','cda-control'],true)
        || ($negative !== '' && $case !== 'app')) throw new RuntimeException('Scenario negativo non ammesso: usare --case=app e una prova predefinita.');
    if (!in_array($case, ['1', '2', '3', '4', '24', '25', 'app'], true)) throw new RuntimeException('Caso RSA di prova non disponibile.');
    $root = realpath(__DIR__ . '/../.local/fse-accreditamento');
    if ($root === false) throw new RuntimeException('Area privata FSE non disponibile.');
    $credentials = $root . '/x509/' . $credentialName;
    $receipt = json_decode(file_get_contents($credentials . '/receipt.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($receipt['mode'] ?? '') !== 'SOGEI_TEST_CREDENTIALS') throw new RuntimeException('Credenziali non di test.');
    foreach (['auth', 'sign'] as $role) {
        $meta = FseCertificateInspector::inspect(file_get_contents($credentials . '/' . $role . '.pem'),
            file_get_contents($root . '/x509/ambulatoriofacile-' . $role . '-private.key'), '',
            file_get_contents($root . '/x509/ambulatoriofacile-' . $role . '.csr'));
        if (!hash_equals($receipt['certificates'][$role]['certificate_sha256'], $meta['certificate_sha256'])
            || $meta['issuer_common_name'] !== 'CA Ministero della Salute Test') throw new RuntimeException('Credenziali modificate dopo importazione.');
    }
    $revision = 'd937255fd7e9c079c5641c537da17fe98a2f2259';
    $fixtures = $root . '/gateway-fixtures/' . $revision;
    $manifest = json_decode(file_get_contents($fixtures . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['mode'] ?? '') !== 'OFFICIAL_RSA_EXAMPLES_PREPARATORY_ONLY' || $manifest['revision'] !== $revision) {
        throw new RuntimeException('Dataset non riconosciuto.');
    }
    $fixture = $manifest['cases'][$case];
    $expectedName = $case === 'app' ? '/^rsa-case-app-[a-f0-9]{16}\.pdf$/D' : '/^rsa-case-' . $case . '\.pdf$/D';
    if (str_starts_with($negative,'cda-')) {
        $fixtures=$root . '/negative-fixtures';
        $negativeManifest=json_decode(file_get_contents($fixtures . '/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        if (($negativeManifest['mode'] ?? '') !== 'RSA_FAULT_INJECTION_PREPARATORY_ONLY'
            || ($negativeManifest['source_revision'] ?? '') !== $revision || empty($negativeManifest['baseline_validation']['ok'])) {
            throw new RuntimeException('Manifest prove negative non verificato.');
        }
        $fixture=$negativeManifest['cases'][$negative];
        $expectedName='/^' . preg_quote($negative,'/') . '-[a-f0-9]{16}\.pdf$/D';
    }
    if (!preg_match($expectedName, $fixture['pdf_file'])) throw new RuntimeException('Nome fixture non ammesso.');
    $pdf = $fixtures . '/' . $fixture['pdf_file'];
    if (!hash_equals($fixture['pdf_sha256'], hash_file('sha256', $pdf)) || $fixture['pdfa'] !== '3b'
        || ($fixture['local_cda_validation']['ok'] ?? false) !== ($negative !== 'cda-no-given')) {
        throw new RuntimeException('PDF di test alterato o non verificato.');
    }
    $config = new Fse2();
    $config->secretsRoot = $root . '/x509';
    $config->allowProduction = false;
    $config->allowToscanaStage = false;
    $config->allowAbsoluteCertificatePaths = false;
    $config->connectTimeout = 15;
    $config->requestTimeout = 45;
    $config->gatewayCaBundle = $root . '/tls/cacert-20260909.pem';
    if (!is_file($config->gatewayCaBundle) || !hash_equals('f66dff1bdf8f96060b8177976f8b7d9254bc89bc4db933d769f7384d28480bc9', hash_file('sha256', $config->gatewayCaBundle))) {
        throw new RuntimeException('Bundle CA Mozilla di test assente o modificato.');
    }
    $endpoint = 'https://modipa-val.fse.salute.gov.it/govway/rest/in/FSE/gateway/v1';
    $identity = json_decode(file_get_contents($root . '/anagrafica-accreditamento.json'), true, 512, JSON_THROW_ON_ERROR);
    $profile = ['environment' => 'test', 'access_mode' => 'gateway', 'gateway_base_url' => $endpoint, 'jwt_audience' => $endpoint,
        'auth_certificate_path' => $credentialName . '/auth.pem', 'auth_private_key_path' => 'ambulatoriofacile-auth-private.key',
        'signature_certificate_path' => $credentialName . '/sign.pem', 'signature_private_key_path' => 'ambulatoriofacile-sign-private.key',
        'app_vendor' => $identity['subjectApplicationVendor'], 'app_id' => $identity['subjectApplicationId'],
        'app_version' => $identity['subjectApplicationVersion'], 'subject_role' => 'DRS',
        // Test placeholders from the official Lazio examples, never an actual customer's configuration.
        'organization_name' => 'Regione Lazio', 'organization_id' => '120',
        'locality' => 'XXX^^^^^&2.16.840.1.113883.2.9.4.1.2&ISO^^^^XXX'];
    $jwt = str_starts_with($negative,'jwt-') ? new FseNegativeTestJwt($config,$negative) : new FseJwtService($config);
    $tokens = $jwt->createTokens($profile, $fixture['document'], $pdf);
    foreach (['authorization', 'signature'] as $kind) {
        [$header, $claims, $signature] = explode('.', $tokens[$kind]);
        $decoded = base64_decode(strtr($signature, '-_', '+/'), true);
        if ($decoded === false || openssl_verify($header . '.' . $claims, $decoded,
            file_get_contents($credentials . '/sign.pem'), OPENSSL_ALGO_SHA256) !== 1) throw new RuntimeException('Autoverifica JWT fallita.');
    }
    unset($tokens);
    $report = ['mode' => 'NATIONAL_GATEWAY_PREPARATORY_VERIFICA', 'started_at' => gmdate('c'),
        'status' => 'LOCAL_PREFLIGHT_PASSED', 'official_accreditation_evidence' => false,
        'endpoint' => $endpoint . '/documents/validation', 'activity' => 'VERIFICA', 'source_revision' => $revision,
        'source_case' => $case, 'source_url' => $fixture['source_url'], 'pdf_sha256' => $fixture['pdf_sha256'],
        'fixture_kind' => $case === 'app' ? 'APP_GENERATED_SYNTHETIC_NOT_OFFICIAL_CASE' : 'OFFICIAL_XML_UNMODIFIED',
        'cda_sha256' => $fixture['cda_sha256'], 'local_cda_validation' => $fixture['local_cda_validation'],
        'jwt_local_signature_verified' => true, 'app_vendor' => $profile['app_vendor'], 'app_id' => $profile['app_id'],
        'tls_ca_bundle_sha256' => hash_file('sha256', $config->gatewayCaBundle),
        'app_version' => $profile['app_version'], 'network_attempted' => false, 'production_access' => false,
        'regional_access' => false, 'publication_attempted' => false, 'clinical_signature' => 'NOT_APPLIED'];
    $report['scenario']=$negative ?: 'positive';
    if ($negative !== '') {
        $report['mode']='NATIONAL_GATEWAY_PREPARATORY_FAULT_DIAGNOSTIC';
        $report['fixture_kind']=$fixture['fixture_kind'];
        $report['expected_http_status']=str_starts_with($negative,'jwt-') ? 403 : ($negative==='cda-control' ? 200 : ($negative==='cda-no-given' ? 422 : 400));
        $report['expected_error_type']=str_starts_with($negative,'jwt-') ? '/msg/jwt-validation' : ($negative==='cda-no-given' ? '/msg/semantic' : ($negative==='cda-gender' ? '/msg/vocabulary' : null));
        $report['mutation']=str_starts_with($negative,'jwt-') ? $negative : ($fixture['mutation_xpath'] ?? null);
        $report['official_case_completed']=false;
    }
    $report['fixture_file'] = $fixture['pdf_file'];
    $report['source_sha256'] = [];
    foreach (['rest/app/Config/Fse2.php', 'rest/app/Services/FseGatewayClient.php', 'rest/app/Services/FseJwtService.php',
        'rest/app/Services/FseCertificateInspector.php', 'rest/app/Services/FseCdaRsaBuilderService.php',
        'rest/app/Services/FseGatewayResponse.php', 'ops/fse-validation/gateway-test.php',
        'ops/fse-validation/gateway-diagnostic-transport.php', 'ops/fse-validation/gateway-negative-jwt.php',
        'ops/fse-validation/prepare-rsa-negative-fixtures.py', 'ops/fse-validation/gateway-app-cda.php'] as $source) {
        $report['source_sha256'][$source] = hash_file('sha256', __DIR__ . '/../../' . $source);
    }
    if (!array_key_exists('send', $options)) { echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"; exit; }
    $runId = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $run = $root . '/gateway-runs/' . $runId;
    if (!mkdir($run, 0700, true)) throw new RuntimeException('Impossibile conservare le evidenze della chiamata.');
    if (!copy($pdf, $run . '/request.pdf') || !hash_equals($fixture['pdf_sha256'], hash_file('sha256', $run . '/request.pdf'))) {
        throw new RuntimeException('Impossibile conservare il PDF sintetico della chiamata.');
    }
    $pdf = $run . '/request.pdf';
    $report['network_attempted'] = true;
    $report['status'] = 'STARTED_NO_RESULT';
    $save = static function () use (&$report, $run): void {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($run . '/result.json', $json, LOCK_EX) !== strlen($json)) throw new RuntimeException('Scrittura evidenze fallita.');
    };
    $save();
    try {
        $result = (new FseGatewayClient($jwt, $config, new FseGatewayDiagnosticTransport()))->validate($profile, $fixture['document'], $pdf, 'VERIFICA');
        $report['result'] = $result;
        $accepted = $result['ok'] && $result['http_status'] === 200 && $result['curl_error_code'] === 0
            && $result['tls_verify_result'] === 0 && !empty($result['payload']['workflowInstanceId'])
            && (!empty($result['payload']['traceID']) || !empty($result['payload']['traceId']));
        $report['status'] = $accepted ? 'GATEWAY_VERIFICA_ACCEPTED' : 'GATEWAY_VERIFICA_NOT_ACCEPTED';
        if ($negative !== '' && $negative !== 'cda-control') {
            $matched = !$result['ok'] && $result['http_status']===$report['expected_http_status']
                && ($result['test_only_diagnostics']['type'] ?? null)===$report['expected_error_type']
                && $result['curl_error_code']===0 && $result['tls_verify_result']===0 && empty($result['outcome_uncertain']);
            $report['status']=$matched ? 'EXPECTED_NEGATIVE_RESPONSE_OBSERVED' : 'NEGATIVE_RESPONSE_NEEDS_REVIEW';
        }
    } catch (Throwable $error) { $report['status'] = 'LOCAL_OR_TRANSPORT_EXCEPTION'; }
    $report['finished_at'] = gmdate('c');
    $save();
    echo json_encode(['run' => $runId] + $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(in_array($report['status'],['GATEWAY_VERIFICA_ACCEPTED','EXPECTED_NEGATIVE_RESPONSE_OBSERVED'],true) ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n"); exit(1);
}
