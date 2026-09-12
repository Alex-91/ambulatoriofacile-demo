<?php
/** CLI-only joint framework/dependency regression. Never loaded by the application. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$fseApplicationCompat = (static function (): array {
    $repo = realpath(__DIR__ . '/../..');
    $lab = realpath(getenv('FSE_DEPENDENCY_LAB') ?: '');
    $variant = getenv('FSE_APPLICATION_VARIANT') ?: 'candidate';
    if ($lab === false || dirname($lab) !== realpath($repo . '/rest/writable/fse-dependency-compat')
        || !preg_match('/^[a-f0-9]{32}$/D', basename($lab))
        || !in_array($variant, ['baseline', 'candidate'], true) || !getenv('FSE_FRAMEWORK_LAB')) {
        throw new RuntimeException('Marked dependency and framework laboratories are required.');
    }
    if (defined('SYSTEMPATH') || defined('WRITEPATH')) throw new RuntimeException('Application already booted.');
    $marker = json_decode(file_get_contents($lab . '/lab.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($marker['mode'] ?? '') !== 'LOCAL_DEPENDENCY_COMPATIBILITY_ONLY'
        || ($marker['install_exit_code'] ?? -1) !== 0 || empty($marker['source_unchanged']) || empty($marker['layout_valid'])) {
        throw new RuntimeException('Incomplete dependency installation.');
    }
    $guarded = ['composer.json', 'composer.lock', 'vendor/composer/installed.json',
        'rest/vendor/composer/installed.json', 'rest/system/CodeIgniter.php'];
    foreach ($guarded as $path) {
        if (!isset($marker['source_sha256'][$path])
            || !hash_equals(strtolower($marker['source_sha256'][$path]), hash_file('sha256', $repo . '/' . $path))) {
            throw new RuntimeException('Active dependency baseline changed.');
        }
    }
    foreach (['composer.json', 'composer.lock'] as $path) {
        if (!hash_equals(strtolower($marker['candidate_sha256'][$path]), hash_file('sha256', $lab . '/candidate/' . $path))) {
            throw new RuntimeException('Candidate dependency manifest changed.');
        }
    }
    $vendor = realpath($variant === 'candidate' ? $lab . '/candidate/vendor' : $repo . '/vendor');
    if ($vendor === false) throw new RuntimeException('Missing selected vendor.');
    $lock = json_decode(file_get_contents(($variant === 'candidate' ? $lab . '/candidate' : $repo) . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $installed = json_decode(file_get_contents($vendor . '/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $expected = $actual = [];
    foreach ($lock['packages'] as $package) $expected[$package['name']] = ltrim($package['version'], 'v');
    foreach ($installed['packages'] as $package) $actual[$package['name']] = ltrim($package['version'], 'v');
    ksort($expected); ksort($actual);
    if ($expected !== $actual) throw new RuntimeException('Installed packages do not match the selected lock.');

    $run = $lab . '/application-' . $variant . '-' . bin2hex(random_bytes(8));
    mkdir($run, 0700);
    mkdir($run . '/runtime', 0700);
    define('WRITEPATH', $run . '/runtime/');
    // Suppress inherited external service credentials before any app configuration is loaded.
    foreach (array_unique(array_merge(array_keys(getenv()), array_keys($_ENV), array_keys($_SERVER))) as $key) {
        if (preg_match('/^(email[._]|smtp[._]|notification[._]|whatsapp[._]|sms[._]|push[._]|fse2?[._]|app[._])/i', $key)
            && !in_array($key, ['FSE_DEPENDENCY_LAB', 'FSE_APPLICATION_VARIANT', 'FSE_FRAMEWORK_LAB'], true)) {
            putenv($key); unset($_ENV[$key], $_SERVER[$key]);
        }
    }
    putenv('FSE_FRAMEWORK_VARIANT=' . $variant);
    // Register rest's loader first (also used by PHPUnit); then give the selected root
    // packages priority. framework-bootstrap remaps only CodeIgniter, in memory.
    require_once $repo . '/rest/vendor/autoload.php';
    $loader = require $vendor . '/autoload.php';
    $loader->unregister();
    $loader->register(true);

    $inventory = static function () use ($repo, $guarded): array {
        $hashes = [];
        foreach ($guarded as $path) $hashes[$path] = hash_file('sha256', $repo . '/' . $path);
        foreach (['rest/app', 'rest/tests', 'ops/fse-validation'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'xml'], true)) continue;
                $hashes[str_replace('\\', '/', substr($file->getPathname(), strlen($repo) + 1))] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($hashes);
        return $hashes;
    };
    $before = $inventory();
    $prefixes = ['Dompdf\\', 'FontLib\\', 'Svg\\', 'Sabberworm\\', 'GuzzleHttp\\', 'Webauthn\\', 'Jose\\', 'Minishlink\\WebPush\\'];
    register_shutdown_function(static function () use ($run, $variant, $vendor, $expected, $before, $inventory, $prefixes): void {
        $sources = $unexpected = [];
        $vendorPrefix = str_replace('\\', '/', $vendor) . '/';
        foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $class) {
            foreach ($prefixes as $prefix) {
                if (!str_starts_with($class, $prefix)) continue;
                $file = (new ReflectionClass($class))->getFileName();
                $path = $file ? str_replace('\\', '/', realpath($file)) : '';
                if (!str_starts_with($path, $vendorPrefix)) $unexpected[] = $class;
                else $sources[substr($path, strlen($vendorPrefix))] = hash_file('sha256', $file);
                break;
            }
        }
        ksort($sources);
        $unchanged = $before === $inventory();
        $version = class_exists(\CodeIgniter\CodeIgniter::class, false) ? \CodeIgniter\CodeIgniter::CI_VERSION : null;
        $passed = $unchanged && $unexpected === [] && $sources !== []
            && $version === ($variant === 'candidate' ? '4.7.4' : '4.6.0');
        file_put_contents($run . '/provenance.json', json_encode([
            'mode' => 'LOCAL_APPLICATION_COMPATIBILITY_PROVENANCE_ONLY', 'variant' => $variant,
            'generated_at' => gmdate('c'), 'passed' => $passed, 'framework_version' => $version,
            'selected_packages' => $expected, 'loaded_dependency_sha256' => $sources,
            'unexpected_dependency_sources' => $unexpected, 'source_unchanged' => $unchanged,
            'application_and_test_sources_sha256' => $before,
            'limitations' => ['Provenance alone is not a test result.', 'No .env loaded. Only reviewed synthetic tests are permitted.',
                'This bootstrap is not an OS-level outbound-network sandbox. Tests must mock all external transports.'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (!$passed) { fwrite(STDERR, "Joint application provenance failed.\n"); exit(1); }
    });
    require __DIR__ . '/php-bootstrap.php';
    // Resolve representative classes, without creating clients or making calls.
    foreach ([Dompdf\Dompdf::class, GuzzleHttp\Client::class, GuzzleHttp\Psr7\Uri::class,
        Webauthn\PublicKeyCredentialRequestOptions::class, Jose\Component\Signature\JWSVerifier::class,
        Minishlink\WebPush\WebPush::class] as $class) {
        if (!class_exists($class)) throw new RuntimeException('Missing selected dependency.');
        $path = str_replace('\\', '/', realpath((new ReflectionClass($class))->getFileName()));
        if (!str_starts_with($path, str_replace('\\', '/', $vendor) . '/')) throw new RuntimeException('Wrong dependency source.');
    }
    fwrite(STDERR, 'Joint application laboratory: ' . $run . PHP_EOL);
    return compact('repo', 'lab', 'run', 'variant', 'vendor');
})();
