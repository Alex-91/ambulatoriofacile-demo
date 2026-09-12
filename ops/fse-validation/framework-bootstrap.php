<?php
/** Opt-in CLI framework substitution; never used by the application's entry point. */
if (PHP_SAPI !== 'cli' && !(PHP_SAPI === 'cli-server' && defined('FSE_SYNTHETIC_APP_LAB_BOOT'))) { http_response_code(404); exit; }
$frameworkRepo = realpath(__DIR__ . '/../..');
$frameworkLab = realpath(getenv('FSE_FRAMEWORK_LAB') ?: '');
$frameworkVariant = getenv('FSE_FRAMEWORK_VARIANT') ?: 'candidate';
if ($frameworkLab === false || dirname($frameworkLab) !== realpath($frameworkRepo . '/rest/writable/fse-framework-compat')
    || !preg_match('/^[a-f0-9]{32}$/D', basename($frameworkLab))
    || !in_array($frameworkVariant, ['baseline', 'candidate'], true)) throw new RuntimeException('Invalid framework laboratory.');
$frameworkMarker = json_decode(file_get_contents($frameworkLab . '/lab.json'), true, 512, JSON_THROW_ON_ERROR);
if (($frameworkMarker['mode'] ?? '') !== 'LOCAL_FRAMEWORK_COMPATIBILITY_ONLY' || empty($frameworkMarker['active_system_unchanged'])) {
    throw new RuntimeException('Incomplete framework laboratory.');
}
$frameworkVersion = $frameworkVariant === 'candidate' ? '4.7.4' : '4.6.0';
$frameworkCommit = '2bd0f01d2813f9ec06db42643ce39d9f5428bf6d';
$frameworkSystem = $frameworkVariant === 'candidate'
    ? realpath($frameworkLab . '/source-4.7.4/CodeIgniter4-' . $frameworkCommit . '/system')
    : realpath($frameworkRepo . '/rest/system');
if ($frameworkSystem === false || defined('SYSTEMPATH')) throw new RuntimeException('Framework already booted or missing.');
$frameworkRun = $frameworkLab . '/run-' . $frameworkVariant . '-' . bin2hex(random_bytes(8));
mkdir($frameworkRun, 0700);
mkdir($frameworkRun . '/runtime', 0700);
define('FSE_FRAMEWORK_BOOTSTRAPPING', true);
define('SYSTEMPATH', $frameworkSystem . DIRECTORY_SEPARATOR);
defined('WRITEPATH') || define('WRITEPATH', $frameworkRun . '/runtime/');
foreach (['cache', 'logs', 'session', 'uploads'] as $directory) {
    if (!is_dir(WRITEPATH . $directory)) mkdir(WRITEPATH . $directory, 0700, true);
}
// rest/vendor has an optimized class map pointing at the active vendored system.
// Redirect that map in memory only, before any framework class can be autoloaded.
$frameworkComposer = require $frameworkRepo . '/rest/vendor/autoload.php';
$frameworkClassMap = [];
foreach ($frameworkComposer->getClassMap() as $class => $path) {
    if (str_starts_with($class, 'CodeIgniter\\') && !str_starts_with($class, 'CodeIgniter\\CodingStandard\\')) {
        $normalized = str_replace('\\', '/', realpath($path) ?: $path);
        $activePrefix = str_replace('\\', '/', $frameworkRepo) . '/rest/system/';
        if (!str_starts_with($normalized, $activePrefix)) throw new RuntimeException('Unexpected framework class map entry.');
        $frameworkClassMap[$class] = SYSTEMPATH . substr($normalized, strlen($activePrefix));
    }
}
$frameworkComposer->addClassMap($frameworkClassMap);
$frameworkComposer->setPsr4('CodeIgniter\\', [SYSTEMPATH]);
$frameworkCheckActive = static function () use ($frameworkMarker, $frameworkRepo): bool {
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($frameworkRepo . '/rest/system', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile()) continue;
        $name = str_replace('\\', '/', substr($file->getPathname(), strlen($frameworkRepo . '/rest/system/')));
        $found[$name] = hash_file('sha256', $file->getPathname());
    }
    if (count($found) !== count($frameworkMarker['active_system_inventory'])) return false;
    foreach ($frameworkMarker['active_system_inventory'] as $path => $entry) {
        if (!isset($found[$path]) || !hash_equals($entry['sha256'], $found[$path])) return false;
    }
    return true;
};
if (!$frameworkCheckActive()) throw new RuntimeException('Active framework baseline changed.');
// Capture the actual framework files loaded, including on test failure or fatal shutdown.
register_shutdown_function(static function () use ($frameworkRun, $frameworkVariant, $frameworkVersion, $frameworkSystem, $frameworkCheckActive): void {
    $sources = $outside = [];
    $prefix = str_replace('\\', '/', $frameworkSystem) . '/';
    $version = class_exists(\CodeIgniter\CodeIgniter::class) ? \CodeIgniter\CodeIgniter::CI_VERSION : null;
    foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $class) {
        if (!str_starts_with($class, 'CodeIgniter\\') || str_starts_with($class, 'CodeIgniter\\CodingStandard\\')) continue;
        $file = (new ReflectionClass($class))->getFileName();
        if (!$file) continue;
        $path = str_replace('\\', '/', realpath($file));
        if (!str_starts_with($path, $prefix)) $outside[$class] = $path;
        else $sources[substr($path, strlen($prefix))] = hash_file('sha256', $file);
    }
    ksort($sources);
    $unchanged = $frameworkCheckActive();
    $passed = $outside === [] && $version === $frameworkVersion && $unchanged;
    file_put_contents($frameworkRun . '/provenance.json', json_encode([
        'mode' => 'LOCAL_FRAMEWORK_PROVENANCE_ONLY', 'variant' => $frameworkVariant,
        'version' => $version, 'expected_version' => $frameworkVersion, 'passed' => $passed,
        'active_system_unchanged' => $unchanged, 'unexpected_framework_sources' => $outside,
        'loaded_framework_sha256' => $sources, 'bootstrap_sha256' => hash_file('sha256', __FILE__),
        'note' => 'Provenance is not a test pass or a production/accreditation attestation.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if (!$passed) { fwrite(STDERR, "Framework provenance failed: " . $frameworkRun . PHP_EOL); exit(1); }
});
if (!defined('FSE_SYNTHETIC_APP_LAB_BOOT')) {
    require __DIR__ . '/php-bootstrap.php';
    if (\CodeIgniter\CodeIgniter::CI_VERSION !== $frameworkVersion) throw new RuntimeException('Unexpected CodeIgniter version.');
}
