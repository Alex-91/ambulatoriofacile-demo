<?php
/** Standalone synthetic component checks; no live DB, email, push or FSE requests. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$repo = realpath(__DIR__ . '/../..');
$lab = realpath($argv[1] ?? '');
$variant = $argv[2] ?? '';
$expectedParent = realpath($repo . '/rest/writable/fse-dependency-compat');
if ($lab === false || dirname($lab) !== $expectedParent || !preg_match('/^[a-f0-9]{32}$/D', basename($lab))
    || !in_array($variant, ['baseline', 'candidate'], true)) throw new RuntimeException('Private compatibility lab required.');
$marker = json_decode(file_get_contents($lab . '/lab.json'), true, 512, JSON_THROW_ON_ERROR);
if (($marker['mode'] ?? '') !== 'LOCAL_DEPENDENCY_COMPATIBILITY_ONLY' || ($marker['install_exit_code'] ?? -1) !== 0
    || empty($marker['source_unchanged']) || empty($marker['layout_valid'])) throw new RuntimeException('Incomplete lab installation.');
foreach ($marker['source_sha256'] as $path => $hash) {
    if (!in_array($path, ['composer.json','composer.lock','vendor/composer/installed.json','rest/vendor/composer/installed.json','rest/system/CodeIgniter.php'], true)
        || !hash_equals(strtolower($hash), hash_file('sha256', $repo . '/' . $path))) throw new RuntimeException('Active dependency baseline changed.');
}
foreach (['composer.json', 'composer.lock'] as $path) {
    if (!hash_equals(strtolower($marker['candidate_sha256'][$path]), hash_file('sha256', $lab . '/candidate/' . $path))) throw new RuntimeException('Candidate changed.');
}
$vendor = realpath($variant === 'baseline' ? $repo . '/vendor' : $lab . '/candidate/vendor');
if ($vendor === false) throw new RuntimeException('Dependency installation missing.');
$run = $lab . '/' . $variant . '-' . bin2hex(random_bytes(8));
mkdir($run, 0700);
mkdir($run . '/runtime', 0700);
define('WRITEPATH', $run . '/runtime/');
// Candidate Composer loader comes first; paths of exercised libraries are checked below.
require $vendor . '/autoload.php';
require __DIR__ . '/php-bootstrap.php';

$checks = [];
function compatCheck(string $name, callable $check): void
{
    global $checks;
    try { $check(); $checks[$name] = ['passed' => true]; }
    catch (Throwable $error) { $checks[$name] = ['passed' => false, 'error_class' => get_class($error), 'line' => $error->getLine()]; }
}
function compatAssert(bool $condition, string $code): void { if (!$condition) throw new RuntimeException($code); }
function compatRejects(callable $operation): bool { try { $operation(); return false; } catch (Throwable $error) { return true; } }

$classes = [Dompdf\Dompdf::class, GuzzleHttp\Client::class, GuzzleHttp\Psr7\Uri::class,
    Webauthn\PublicKeyCredentialRequestOptions::class, Jose\Component\Signature\JWSVerifier::class,
    Minishlink\WebPush\WebPush::class];
$provenance = [];
foreach ($classes as $class) {
    compatCheck('source_' . str_replace('\\', '_', $class), function () use ($class, $vendor, &$provenance): void {
        $path = realpath((new ReflectionClass($class))->getFileName());
        compatAssert($path !== false && str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $vendor) . '/'), 'WRONG_DEPENDENCY_SOURCE');
        $provenance[$class] = ['relative_file' => substr($path, strlen($vendor) + 1), 'sha256' => hash_file('sha256', $path)];
    });
}

compatCheck('installed_versions_match_selected_lock', static function () use ($vendor, $variant, $repo, $lab): void {
    $lock = json_decode(file_get_contents(($variant === 'baseline' ? $repo : $lab . '/candidate') . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $installed = json_decode(file_get_contents($vendor . '/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $expected = $actual = [];
    foreach ($lock['packages'] as $package) $expected[$package['name']] = ltrim($package['version'], 'v');
    foreach ($installed['packages'] as $package) $actual[$package['name']] = ltrim($package['version'], 'v');
    ksort($expected); ksort($actual);
    compatAssert($expected === $actual, 'INSTALLED_LOCK_MISMATCH');
});

compatCheck('http_client_mock_json_and_cookies', static function (): void {
    $history = [];
    $handler = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([
        new GuzzleHttp\Psr7\Response(200, ['Set-Cookie' => 'session=SYNTHETIC; Path=/; Secure'], '{"ok":true}'),
        new GuzzleHttp\Psr7\Response(200, [], '{}'),
    ]));
    $handler->push(GuzzleHttp\Middleware::history($history));
    $client = new GuzzleHttp\Client(['handler' => $handler, 'cookies' => true, 'allow_redirects' => false]);
    $response = $client->post('https://synthetic.invalid/check', ['json' => ['synthetic' => true]]);
    $client->get('https://synthetic.invalid/next');
    compatAssert(json_decode((string) $response->getBody(), true)['ok'] === true, 'MOCK_JSON');
    compatAssert($history[0]['request']->getHeaderLine('Content-Type') === 'application/json', 'JSON_CONTENT_TYPE');
    compatAssert(str_contains($history[1]['request']->getHeaderLine('Cookie'), 'session=SYNTHETIC'), 'COOKIE_ROUNDTRIP');
});

compatCheck('http_header_newline_rejected', static function (): void {
    compatAssert(compatRejects(static fn () => new GuzzleHttp\Psr7\Request('GET', 'https://synthetic.invalid', ['X-Test' => "ok\r\nInjected: true"])), 'HEADER_INJECTION_ACCEPTED');
});

compatCheck('jwt_es256_signature_and_tamper', static function (): void {
    $key = Jose\Component\KeyManagement\JWKFactory::createECKey('P-256');
    $manager = new Jose\Component\Core\AlgorithmManager([new Jose\Component\Signature\Algorithm\ES256()]);
    $builder = new Jose\Component\Signature\JWSBuilder($manager);
    $serializer = new Jose\Component\Signature\Serializer\CompactSerializer();
    $signed = $builder->create()->withPayload('{"test":"SYNTHETIC"}')->addSignature($key, ['alg' => 'ES256'])->build();
    $compact = $serializer->serialize($signed, 0);
    $verifier = new Jose\Component\Signature\JWSVerifier($manager);
    compatAssert($verifier->verifyWithKey($serializer->unserialize($compact), $key->toPublic(), 0), 'VALID_JWT_REJECTED');
    $parts = explode('.', $compact);
    $parts[1] = Base64Url\Base64Url::encode('{"test":"TAMPERED"}');
    compatAssert(!$verifier->verifyWithKey($serializer->unserialize(implode('.', $parts)), $key->toPublic(), 0), 'ALTERED_JWT_ACCEPTED');
});

compatCheck('webpush_encrypted_payload_and_expired_mock', static function (): void {
    $keys = Minishlink\WebPush\VAPID::createVapidKeys();
    $recipient = Minishlink\WebPush\VAPID::createVapidKeys();
    $history = [];
    $handler = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([
        new GuzzleHttp\Psr7\Response(201), new GuzzleHttp\Psr7\Response(410),
    ]));
    $handler->push(GuzzleHttp\Middleware::history($history));
    $push = new Minishlink\WebPush\WebPush(['VAPID' => ['subject' => 'mailto:test@example.invalid'] + $keys], [], 5, ['handler' => $handler]);
    $subscription = Minishlink\WebPush\Subscription::create(['endpoint' => 'https://synthetic.invalid/push',
        'keys' => ['p256dh' => $recipient['publicKey'], 'auth' => Base64Url\Base64Url::encode(random_bytes(16))], 'contentEncoding' => 'aes128gcm']);
    $report = $push->sendOneNotification($subscription, '{"title":"SYNTHETIC_ONLY"}');
    compatAssert($report->isSuccess(), 'MOCK_PUSH_FAILED');
    compatAssert($history[0]['request']->getHeaderLine('Content-Encoding') === 'aes128gcm', 'PUSH_ENCODING');
    compatAssert(!str_contains((string) $history[0]['request']->getBody(), 'SYNTHETIC_ONLY'), 'PLAINTEXT_PUSH');
    compatAssert(str_starts_with($history[0]['request']->getHeaderLine('Authorization'), 'vapid t='), 'VAPID_JWT');
    $expired = $push->sendOneNotification($subscription, '{}');
    compatAssert(!$expired->isSuccess() && $expired->isSubscriptionExpired(), 'PUSH_410');
});

compatCheck('webauthn_request_options_api_not_browser_login', static function (): void {
    $challenge = random_bytes(32);
    $options = Webauthn\PublicKeyCredentialRequestOptions::create($challenge, 'synthetic.invalid', [], 'required', 60000);
    compatAssert($options->challenge === $challenge && $options->rpId === 'synthetic.invalid' && $options->userVerification === 'required', 'WEBAUTHN_REQUEST_OPTIONS');
    compatAssert(compatRejects(static fn () => Webauthn\PublicKeyCredentialRequestOptions::create('x', 'synthetic.invalid', [], 'invalid')), 'WEBAUTHN_INVALID_POLICY');
});

compatCheck('application_password_auth_and_tenant_filter', static function (): void {
    $password = 'Synthetic-test-only-2026!';
    $model = new class($password) extends App\Models\PlatformUsersModel {
        private string $hash;
        public function __construct(string $password) { $this->hash = password_hash($password, PASSWORD_DEFAULT); }
        public function findByEmailInsensitive(string $email): ?array {
            return $email === 'test@example.invalid' ? ['id_platform_user' => 42, 'status' => 'active', 'password_hash' => $this->hash] : null;
        }
    };
    $catalog = new class extends App\Services\TenantCatalogService {
        public function __construct() {}
        public function listTenantsForPlatformUser(int $id): array { return [
            ['id_tenant' => 42, 'tenant_name' => 'Synthetic A', 'tenant_is_active' => 1, 'tenant_status' => 'active'],
            ['id_tenant' => 43, 'tenant_name' => 'Synthetic B', 'tenant_is_active' => 1, 'tenant_status' => 'suspended'],
        ]; }
    };
    $service = new App\Services\PlatformAuthService($model, $catalog);
    $result = $service->authenticate('TEST@example.invalid', $password);
    compatAssert(array_column($result['selectable_tenants'], 'id_tenant') === [42], 'TENANT_FILTER');
    compatAssert($service->authenticate('test@example.invalid', $password . 'wrong') === null, 'BAD_PASSWORD');
    compatAssert($service->authenticate('missing@example.invalid', $password) === null, 'MISSING_USER');
});

foreach ([2, 90] as $lineCount) {
    compatCheck('application_billing_template_pdf_' . $lineCount . '_lines', static function () use ($lineCount, $run): void {
        $preview = [
            'tenant' => ['tenant_name' => 'STUDIO SINTETICO - NON VALIDO'],
            'document_type_label' => 'Documento di prova', 'payment_method_label' => 'Simulato',
            'document' => ['document_number' => 'TEST-2026-001', 'issue_date' => '2026-09-11', 'patient_name' => 'PAZIENTE SINTETICO',
                'subtotal_amount' => $lineCount * 40, 'amount_total' => $lineCount * 40, 'notes' => 'PROVA TECNICA SENZA VALORE FISCALE'],
            'line_items' => array_fill(0, $lineCount, ['description' => 'Prestazione sintetica <script>NO_EXECUTION</script>', 'quantity' => 1, 'unit_amount' => 40, 'line_total' => 40]),
            'template' => ['layout' => ['show_header' => true, 'show_patient_box' => true, 'show_payment_box' => true],
                'fields' => ['show_document_number' => true, 'show_issue_date' => true, 'show_patient_name' => true, 'show_notes' => true],
                'branding' => ['logo_mode' => 'none', 'accent_color' => '#2c8895']],
        ];
        $html = view('admin/billing/document_pdf', ['preview' => $preview], ['saveData' => false]);
        compatAssert(!str_contains($html, '<script>NO_EXECUTION') && str_contains($html, '&lt;script&gt;NO_EXECUTION'), 'BILLING_TEMPLATE_ESCAPING');
        $options = new Dompdf\Options();
        // No network/images/PHP/JS; generated PDF stays in memory, font/temp writes in this private run only.
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('fontDir', $run . '/runtime');
        $options->set('fontCache', $run . '/runtime');
        $options->set('tempDir', $run . '/runtime');
        $pdf = new Dompdf\Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        $bytes = $pdf->output();
        compatAssert(str_starts_with($bytes, '%PDF-') && strlen($bytes) > 1000, 'PDF_OUTPUT');
        compatAssert($pdf->getCanvas()->get_page_count() >= ($lineCount > 2 ? 2 : 1), 'PDF_PAGINATION');
    });
}

// A bounded regression demonstration using self-generated keys, no real tokens or targets.
$unprotectedRejected = compatRejects(static function (): void {
    $key = Jose\Component\KeyManagement\JWKFactory::createECKey('P-256');
    $manager = new Jose\Component\Core\AlgorithmManager([new Jose\Component\Signature\Algorithm\ES256()]);
    $jws = (new Jose\Component\Signature\JWSBuilder($manager))->create()->withPayload('SYNTHETIC')
        ->addSignature($key, [], ['alg' => 'ES256'])->build();
    if (!(new Jose\Component\Signature\JWSVerifier($manager))->verifyWithKey($jws, $key->toPublic(), 0)) throw new RuntimeException('REJECTED');
});
if ($variant === 'candidate') compatCheck('jwt_unprotected_algorithm_is_rejected', static fn () => compatAssert($unprotectedRejected, 'UNPROTECTED_ALG_ACCEPTED'));
$sourceUnchanged = true;
foreach ($marker['source_sha256'] as $path => $hash) $sourceUnchanged = $sourceUnchanged && hash_equals(strtolower($hash), hash_file('sha256', $repo . '/' . $path));
$passed = $sourceUnchanged && !in_array(false, array_column($checks, 'passed'), true);
$report = ['mode' => 'SYNTHETIC_COMPONENT_COMPATIBILITY_ONLY', 'variant' => $variant, 'generated_at' => gmdate('c'),
    'framework_version' => \CodeIgniter\CodeIgniter::CI_VERSION,
    'status' => $passed ? 'passed_component_checks' : 'failed_component_checks', 'tests' => count($checks), 'checks' => $checks,
    'dependency_sources' => $provenance, 'source_unchanged' => $sourceUnchanged,
    'security_probe_unprotected_jws_algorithm_rejected' => $unprotectedRejected,
    'runner_sha256' => hash_file('sha256', __FILE__),
    'application_sources_sha256' => array_combine(
        ['billing_template', 'password_auth_service', 'synthetic_bootstrap', 'synthetic_database'],
        array_map(static fn ($path) => hash_file('sha256', $path), [$repo . '/rest/app/Views/admin/billing/document_pdf.php',
            $repo . '/rest/app/Services/PlatformAuthService.php', __DIR__ . '/php-bootstrap.php', __DIR__ . '/synthetic-database.php'])),
    'limitations' => ['No production activation, real DB, SMTP, push, FSE or browser requests.',
        'WebAuthn options API only; not authenticator/origin/browser end-to-end verification.',
        'Actual billing view rendered with synthetic values; PDF bytes/pagination checked, no visual layout comparison.',
        'Push library checked with mock HTTP; application remote push service not exercised.',
        getenv('FSE_FRAMEWORK_LAB') ? 'Isolated framework substitution; see its separate provenance report. Active application unchanged.'
            : 'CodeIgniter version unchanged; its advisories remain unresolved.']];
file_put_contents($run . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo json_encode(['report' => $run . '/report.json', 'status' => $report['status'], 'tests' => $report['tests'],
    'failed' => array_keys(array_filter($checks, static fn ($check) => !$check['passed'])), 'unprotected_algorithm_rejected' => $unprotectedRejected]) . PHP_EOL;
exit($passed ? 0 : 1);
