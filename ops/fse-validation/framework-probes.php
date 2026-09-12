<?php
/** Security regression probes on synthetic values, no remote request or real database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!getenv('FSE_FRAMEWORK_LAB')) throw new RuntimeException('Marked framework lab required.');
require __DIR__ . '/php-bootstrap.php';
$checks = $observations = [];
function frameworkProbe(string $name, callable $probe): void {
    global $checks;
    try { if ($probe() !== true) throw new RuntimeException('ASSERTION_FAILED'); $checks[$name] = ['passed' => true]; }
    catch (Throwable $error) { $checks[$name] = ['passed' => false, 'type' => get_class($error), 'message' => $error->getMessage()]; }
}
function frameworkRequest(array $proxies = [], bool $tls = false): CodeIgniter\HTTP\IncomingRequest {
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10'; // Documentation-only address, never contacted.
    $_SERVER['HTTPS'] = $tls ? 'on' : 'off';
    unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_FRONT_END_HTTPS']);
    Config\Services::resetSingle('superglobals');
    $config = new Config\App();
    $config->proxyIPs = $proxies;
    return new CodeIgniter\HTTP\IncomingRequest($config, new CodeIgniter\HTTP\URI('http://fse-synthetic.invalid/'), null, new CodeIgniter\HTTP\UserAgent());
}
$candidate = CodeIgniter\CodeIgniter::CI_VERSION === '4.7.4';
foreach (['X-Forwarded-Proto' => 'https', 'Front-End-Https' => 'on'] as $header => $value) {
    $request = frameworkRequest(); $request->setHeader($header, $value);
    $observations['untrusted_' . $header . '_accepted'] = $request->isSecure();
    if ($candidate) frameworkProbe('untrusted_' . $header . '_rejected', static fn () => !$request->isSecure());
    $trusted = frameworkRequest(['192.0.2.10' => 'X-Forwarded-For']); $trusted->setHeader($header, $value);
    frameworkProbe('trusted_' . $header . '_accepted', static fn () => $trusted->isSecure());
}
frameworkProbe('direct_tls_accepted', static fn () => frameworkRequest([], true)->isSecure());
frameworkProbe('plain_http_not_secure', static fn () => !frameworkRequest()->isSecure());

$fixture = WRITEPATH . 'uploads/pixel.png';
file_put_contents($fixture, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=', true));
foreach (['pixel.png', 'pixel.php'] as $clientName) {
    $_FILES = ['upload' => ['name' => $clientName, 'type' => 'image/png', 'tmp_name' => $fixture, 'error' => UPLOAD_ERR_OK, 'size' => filesize($fixture)]];
    $request = frameworkRequest();
    $rules = new CodeIgniter\Validation\StrictRules\FileRules($request);
    foreach (['is_image' => 'upload', 'mime_in' => 'upload,image/png', 'ext_in' => 'upload,png'] as $method => $params) {
        $accepted = $rules->{$method}('', $params);
        $observations[$method . '_' . $clientName . '_accepted'] = $accepted;
        if ($clientName === 'pixel.png' || $candidate) {
            frameworkProbe($method . '_' . $clientName, static fn () => $accepted === ($clientName === 'pixel.png'));
        }
    }
}
$_FILES = [];
Config\Services::resetSingle('superglobals');
frameworkProbe('application_classes_load_with_framework_signatures', static function (): bool {
    foreach ([App\Controllers\Agenda::class, App\Controllers\Admin\FseDocumentsController::class,
        App\Controllers\Admin\BillingDocumentsController::class, App\Controllers\AuthMFA\AuthenticationController::class,
        App\Libraries\FilteredMigrationRunner::class, App\Services\PlatformAuthService::class,
        App\Services\FseDocumentService::class, App\Libraries\PushService::class] as $class) {
        if (!class_exists($class)) return false;
    }
    return true; // Class loading only, not full business-flow coverage.
});
frameworkProbe('query_builder_where_bind_preserved', static function () use (&$observations, $candidate): bool {
    $db = Config\Database::connect();
    if ($db->DBDriver !== 'SQLite3' || $db->database !== ':memory:') throw new RuntimeException('SYNTHETIC_DB_ONLY');
    $db->query('CREATE TABLE probe_records (id INTEGER PRIMARY KEY, label TEXT)');
    $value = "SYNTHETIC ' quotation";
    // Exercise the shared compiler with SQLite escaping, without executing dialect-specific SQL.
    $sql = (new CodeIgniter\Database\BaseBuilder('probe_records', $db))->where('label', $value)->testMode()->deleteBatch([['id' => 1]], 'id');
    $sql = is_array($sql) ? implode("\n", $sql) : (string) $sql;
    $escaped = str_contains($sql, $db->escape($value));
    $observations['delete_batch_where_value_escaped'] = $escaped;
    return $candidate ? $escaped : $sql !== '';
});
$passed = !in_array(false, array_column($checks, 'passed'), true);
$report = ['mode' => 'OFFLINE_FRAMEWORK_PROBES', 'version' => CodeIgniter\CodeIgniter::CI_VERSION,
    'passed' => $passed, 'checks' => $checks, 'observations' => $observations,
    'runner_sha256' => hash_file('sha256', __FILE__),
    'limitations' => 'Synthetic headers/files and SQLite query generation only; no real upload move, proxy, browser, or exploitability assessment of production.'];
$target = $frameworkRun . '/probes.json';
file_put_contents($target, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo json_encode(['report' => $target, 'passed' => $passed, 'checks' => count($checks), 'failed' => array_filter($checks, static fn ($check) => !$check['passed']), 'observations' => $observations]) . PHP_EOL;
exit($passed ? 0 : 1);
