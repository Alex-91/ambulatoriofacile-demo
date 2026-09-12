<?php
/** Opt-in dependency substitution for the already validated, synthetic CLI/HTTP lab. */
if (!defined('FSE_SYNTHETIC_APP_LAB_BOOT') || !in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
    http_response_code(404); exit;
}

(static function (): void {
    $appLab = fse_lab_config();
    if (realpath(FSE_SYNTHETIC_APP_LAB_BOOT) !== $appLab['root']
        || !defined('WRITEPATH') || realpath(WRITEPATH) !== realpath($appLab['root'] . '/writable')) {
        throw new RuntimeException('Synthetic application boundary mismatch.');
    }
    $repo = realpath(__DIR__ . '/../..');
    $lab = realpath(getenv('FSE_DEPENDENCY_LAB') ?: '');
    $variant = getenv('FSE_APPLICATION_VARIANT') ?: 'candidate';
    if ($lab === false || dirname($lab) !== realpath($repo . '/rest/writable/fse-dependency-compat')
        || !preg_match('/^[a-f0-9]{32}$/D', basename($lab)) || !in_array($variant, ['baseline', 'candidate'], true)
        || !getenv('FSE_FRAMEWORK_LAB') || (getenv('FSE_FRAMEWORK_VARIANT') ?: 'candidate') !== $variant) {
        throw new RuntimeException('Matching marked framework and dependency labs required.');
    }
    $marker = json_decode(file_get_contents($lab . '/lab.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($marker['mode'] ?? '') !== 'LOCAL_DEPENDENCY_COMPATIBILITY_ONLY'
        || ($marker['install_exit_code'] ?? -1) !== 0 || empty($marker['source_unchanged']) || empty($marker['layout_valid'])) {
        throw new RuntimeException('Incomplete dependency installation.');
    }
    $guarded = ['composer.json', 'composer.lock', 'vendor/composer/installed.json',
        'rest/vendor/composer/installed.json', 'rest/system/CodeIgniter.php'];
    $checkActive = static function () use ($repo, $marker, $guarded): bool {
        foreach ($guarded as $file) {
            if (!isset($marker['source_sha256'][$file])
                || !hash_equals(strtolower($marker['source_sha256'][$file]), hash_file('sha256', $repo . '/' . $file))) return false;
        }
        return true;
    };
    if (!$checkActive()) throw new RuntimeException('Active dependencies changed.');
    foreach (['composer.json', 'composer.lock'] as $file) {
        if (!hash_equals(strtolower($marker['candidate_sha256'][$file]), hash_file('sha256', $lab . '/candidate/' . $file))) {
            throw new RuntimeException('Candidate manifests changed.');
        }
    }
    $vendor = realpath($variant === 'candidate' ? $lab . '/candidate/vendor' : $repo . '/vendor');
    if ($vendor === false) throw new RuntimeException('Selected vendor missing.');
    $lock = json_decode(file_get_contents(($variant === 'candidate' ? $lab . '/candidate' : $repo) . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $installed = json_decode(file_get_contents($vendor . '/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $expected = $actual = [];
    foreach ($lock['packages'] as $package) $expected[$package['name']] = ltrim($package['version'], 'v');
    foreach ($installed['packages'] as $package) $actual[$package['name']] = ltrim($package['version'], 'v');
    ksort($expected); ksort($actual);
    if ($expected !== $actual) throw new RuntimeException('Selected installed versions differ from lock.');
    require_once $repo . '/rest/vendor/autoload.php';
    $loader = require $vendor . '/autoload.php';
    $loader->unregister(); $loader->register(true);

    $id = bin2hex(random_bytes(16));
    $directory = WRITEPATH . 'dependency-provenance';
    if (!is_dir($directory)) mkdir($directory, 0700);
    $GLOBALS['fse_lab_dependencies'] = ['variant' => $variant, 'request_id' => $id];
    register_shutdown_function(static function () use ($directory, $id, $appLab, $variant, $vendor, $checkActive, $actual): void {
        $sources = $unexpected = [];
        $prefix = str_replace('\\', '/', $vendor) . '/';
        foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $class) {
            if (!preg_match('/^(Dompdf|FontLib|Svg|Sabberworm|GuzzleHttp|Webauthn|Jose|Minishlink\\\\WebPush)\\\\/', $class)) continue;
            $file = (new ReflectionClass($class))->getFileName();
            $path = $file ? str_replace('\\', '/', realpath($file)) : '';
            if (!str_starts_with($path, $prefix)) $unexpected[] = $class;
            else $sources[substr($path, strlen($prefix))] = hash_file('sha256', $file);
        }
        ksort($sources);
        $version = class_exists(\CodeIgniter\CodeIgniter::class) ? \CodeIgniter\CodeIgniter::CI_VERSION : null;
        $unchanged = $checkActive();
        $passed = $unchanged && $sources !== [] && $unexpected === [] && $version === ($variant === 'candidate' ? '4.7.4' : '4.6.0');
        $report = ['mode' => 'SYNTHETIC_HTTP_DEPENDENCY_PROVENANCE_ONLY', 'passed' => $passed,
            'lab_id' => basename($appLab['root']), 'request_id' => $id, 'variant' => $variant,
            'framework_version' => $version, 'active_dependencies_unchanged' => $unchanged,
            'loaded_dependency_sha256' => $sources, 'unexpected_dependency_sources' => $unexpected,
            'selected_packages' => $actual, 'bootstrap_sha256' => hash_file('sha256', __FILE__),
            'note' => 'Class provenance only; eagerly loaded classes are not functional coverage. Check the separate HTTP result.'];
        file_put_contents($directory . '/' . $id . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (!$passed) { error_log('Synthetic dependency provenance failed: ' . $id); exit(1); }
    });
    foreach ([Dompdf\Dompdf::class, GuzzleHttp\Client::class, GuzzleHttp\Psr7\Uri::class,
        Webauthn\PublicKeyCredentialRequestOptions::class, Jose\Component\Signature\JWSVerifier::class,
        Minishlink\WebPush\WebPush::class] as $class) {
        if (!class_exists($class)) throw new RuntimeException('Selected class missing.');
        $path = str_replace('\\', '/', realpath((new ReflectionClass($class))->getFileName()));
        if (!str_starts_with($path, str_replace('\\', '/', $vendor) . '/')) throw new RuntimeException('Wrong selected dependency source.');
    }
})();
