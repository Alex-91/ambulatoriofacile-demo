<?php
declare(strict_types=1);

/**
 * Build a standalone demo runtime inside dist/demo-runtime without touching
 * the farmacia installation.
 *
 * Example:
 *   php tools/PrepareDemoRuntime.php --env-file=.env.demo --dest=dist/demo-runtime
 */

date_default_timezone_set('Europe/Rome');

const DEMO_RUNTIME_DEFAULT_ENV_FILE = '.env.demo';
const DEMO_RUNTIME_DEFAULT_DEST = 'dist/demo-runtime';
const DEMO_RUNTIME_REPORT_DIR = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'demo_setup';

main($argv ?? []);

function main(array $argv): void
{
    $root = normalizePath(dirname(__DIR__));
    $options = parseOptions($argv);
    $envFile = resolveProjectPath($root, $options['env_file']);
    $dest = resolveProjectPath($root, $options['dest']);

    ensureDirectory(DEMO_RUNTIME_REPORT_DIR);

    $report = [
        'started_at' => date('c'),
        'env_file' => $envFile,
        'destination' => $dest,
        'status' => 'running',
        'copied' => [],
        'generated' => [],
        'missing_assets' => [],
        'missing_assets_summary' => [],
        'notes' => [],
    ];

    try {
        assertPathIsInsideProject($root, $dest);
        assertDestinationIsSafe($root, $dest);
        assertFileExists($envFile, 'File env demo non trovato');

        resetDestination($dest);
        copyRuntimeSources($root, $dest, $report);
        rewriteRuntimeRoutes($dest, $report);
        writeRuntimeEnv($envFile, $dest, $report);
        writeRootIndex($dest, $report);
        writeRootSpark($dest, $report);
        writeRootHtaccess($dest, $report);
        createWritableStructure($dest, $report);
        $report['missing_assets'] = auditMissingAssets($root);
        $report['missing_assets_summary'] = summarizeMissingAssets($report['missing_assets']);
        $report['notes'] = buildNotes($report['missing_assets'], $report['missing_assets_summary']);
        $report['status'] = 'ok';
        $report['finished_at'] = date('c');

        $path = writeRuntimeReport($report);
        mirrorReportsIntoRuntime($root, $dest, $report);

        echo "Runtime demo preparata in: {$dest}\n";
        echo "Env demo copiato come .env dalla sorgente: {$envFile}\n";
        echo "Report: {$path}\n";
        if ($report['missing_assets'] !== []) {
            echo "Asset mancanti rilevati: " . count($report['missing_assets']) . "\n";
        }
        exit(0);
    } catch (Throwable $e) {
        $report['status'] = 'error';
        $report['finished_at'] = date('c');
        $report['error'] = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        $path = writeRuntimeReport($report);
        fwrite(STDERR, "Errore prepare demo runtime: {$e->getMessage()}\n");
        fwrite(STDERR, "Report: {$path}\n");
        exit(1);
    }
}

function parseOptions(array $argv): array
{
    return [
        'env_file' => optionValue($argv, 'env-file') ?: DEMO_RUNTIME_DEFAULT_ENV_FILE,
        'dest' => optionValue($argv, 'dest') ?: DEMO_RUNTIME_DEFAULT_DEST,
    ];
}

function optionValue(array $argv, string $name): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }

    return null;
}

function resolveProjectPath(string $projectRoot, string $path): string
{
    if (preg_match('/^[A-Za-z]:\\\\|^\//', $path) === 1) {
        return normalizePath($path);
    }

    return normalizePath($projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
}

function normalizePath(string $path): string
{
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $parts = [];
    $prefix = '';

    if (preg_match('/^[A-Za-z]:'.preg_quote(DIRECTORY_SEPARATOR, '/').'/', $path) === 1) {
        $prefix = strtoupper(substr($path, 0, 2)) . DIRECTORY_SEPARATOR;
        $path = substr($path, 3);
    } elseif (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        $prefix = DIRECTORY_SEPARATOR;
        $path = ltrim($path, DIRECTORY_SEPARATOR);
    }

    foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $segment;
    }

    return $prefix . implode(DIRECTORY_SEPARATOR, $parts);
}

function assertPathIsInsideProject(string $projectRoot, string $path): void
{
    $projectRoot = rtrim(normalizePath($projectRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $path = normalizePath($path);

    if (!str_starts_with($path . DIRECTORY_SEPARATOR, $projectRoot) && $path !== rtrim($projectRoot, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('La destinazione deve rimanere dentro questo workspace.');
    }
}

function assertDestinationIsSafe(string $projectRoot, string $dest): void
{
    $allowedBase = normalizePath($projectRoot . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'demo-runtime');
    if ($dest !== $allowedBase && !str_starts_with($dest . DIRECTORY_SEPARATOR, $allowedBase . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('La runtime demo puo essere generata solo dentro dist/demo-runtime.');
    }
}

function assertFileExists(string $path, string $message): void
{
    if (!is_file($path)) {
        throw new RuntimeException($message . ': ' . $path);
    }
}

function resetDestination(string $dest): void
{
    if (is_dir($dest)) {
        deleteDirectoryTree($dest);
    }

    ensureDirectory($dest);
}

function deleteDirectoryTree(string $path): void
{
    $path = normalizePath($path);
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        throw new RuntimeException('Impossibile leggere la directory da eliminare: ' . $path);
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $full = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full) && !is_link($full)) {
            deleteDirectoryTree($full);
            continue;
        }

        if (!unlink($full)) {
            throw new RuntimeException('Impossibile eliminare il file: ' . $full);
        }
    }

    if (!rmdir($path)) {
        throw new RuntimeException('Impossibile eliminare la directory: ' . $path);
    }
}

function copyRuntimeSources(string $root, string $dest, array &$report): void
{
    $directoryCopies = [
        'app' => 'app',
        'system' => 'system',
        'public' => 'public',
        'productization' => 'productization',
        'upload' => 'upload',
    ];

    foreach ($directoryCopies as $fromRel => $toRel) {
        $from = $root . DIRECTORY_SEPARATOR . $fromRel;
        $to = $dest . DIRECTORY_SEPARATOR . $toRel;
        if (!is_dir($from)) {
            throw new RuntimeException('Directory sorgente mancante: ' . $from);
        }
        copyDirectoryTree($from, $to);
        $report['copied'][] = ['type' => 'dir', 'from' => $from, 'to' => $to];
    }

    $fileCopies = [
        'spark' => 'spark',
        'preload.php' => 'preload.php',
        'composer.json' => 'composer.json',
        'phpunit.xml.dist' => 'phpunit.xml.dist',
        'LICENSE' => 'LICENSE',
        'README.md' => 'README.md',
        'public/sw.js' => 'sw.js',
        'public/manifest.json' => 'manifest.json',
        'public/favicon.ico' => 'favicon.ico',
        'public/robots.txt' => 'robots.txt',
    ];

    foreach ($fileCopies as $fromRel => $toRel) {
        $from = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fromRel);
        if (!is_file($from)) {
            continue;
        }

        $to = $dest . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $toRel);
        ensureDirectory(dirname($to));
        if (!copy($from, $to)) {
            throw new RuntimeException('Impossibile copiare il file: ' . $from);
        }
        $report['copied'][] = ['type' => 'file', 'from' => $from, 'to' => $to];
    }

    $rootWebDirs = [
        'public/js' => 'js',
        'public/notifications' => 'notifications',
    ];

    foreach ($rootWebDirs as $fromRel => $toRel) {
        $from = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fromRel);
        if (!is_dir($from)) {
            continue;
        }

        $to = $dest . DIRECTORY_SEPARATOR . $toRel;
        copyDirectoryTree($from, $to);
        $report['copied'][] = ['type' => 'dir', 'from' => $from, 'to' => $to];
    }

    copyExternalWebAssets($root, $dest, $report);
}

function copyDirectoryTree(string $source, string $dest): void
{
    ensureDirectory($dest);

    $items = scandir($source);
    if ($items === false) {
        throw new RuntimeException('Impossibile leggere la directory: ' . $source);
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $from = $source . DIRECTORY_SEPARATOR . $item;
        $to = $dest . DIRECTORY_SEPARATOR . $item;

        if (is_dir($from) && !is_link($from)) {
            copyDirectoryTree($from, $to);
            continue;
        }

        ensureDirectory(dirname($to));
        if (!copy($from, $to)) {
            throw new RuntimeException('Impossibile copiare il file: ' . $from);
        }
    }
}

function copyExternalWebAssets(string $root, string $dest, array &$report): void
{
    $parentRoot = dirname($root);
    if ($parentRoot === '' || $parentRoot === $root) {
        return;
    }

    $directoryCopies = [
        $parentRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fontawesome'
            => $dest . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fontawesome',
        $parentRoot . DIRECTORY_SEPARATOR . 'icons'
            => $dest . DIRECTORY_SEPARATOR . 'icons',
    ];

    foreach ($directoryCopies as $from => $to) {
        if (!is_dir($from)) {
            continue;
        }

        copyDirectoryTree($from, $to);
        $report['copied'][] = ['type' => 'dir', 'from' => $from, 'to' => $to];
    }

    $fileCopies = [
        $parentRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'login.css'
            => $dest . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'login.css',
        $parentRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'jquery.min.js'
            => $dest . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'jquery.min.js',
        $parentRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'logonew.jpg'
            => $dest . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'logonew.jpg',
        $parentRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'logonew.png'
            => $dest . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'logonew.png',
    ];

    foreach ($fileCopies as $from => $to) {
        if (!is_file($from)) {
            continue;
        }

        ensureDirectory(dirname($to));
        if (!copy($from, $to)) {
            throw new RuntimeException('Impossibile copiare il file asset esterno: ' . $from);
        }

        $report['copied'][] = ['type' => 'file', 'from' => $from, 'to' => $to];
    }
}

function rewriteRuntimeRoutes(string $dest, array &$report): void
{
    $routesPath = $dest . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'Routes.php';
    if (!is_file($routesPath)) {
        throw new RuntimeException('File Routes.php non trovato nella runtime demo: ' . $routesPath);
    }

    $content = file_get_contents($routesPath);
    if ($content === false) {
        throw new RuntimeException('Impossibile leggere Routes.php della runtime demo.');
    }

    $updated = str_replace(
        "\$routes->get('/', 'Home::index');",
        "\$routes->get('/', 'DemoController::index');",
        $content
    );

    if ($updated === $content) {
        throw new RuntimeException('Non sono riuscito a riscrivere la route root della runtime demo.');
    }

    if (file_put_contents($routesPath, $updated) === false) {
        throw new RuntimeException('Impossibile aggiornare Routes.php della runtime demo.');
    }

    $report['generated'][] = ['type' => 'route_patch', 'path' => $routesPath];
}

function writeRuntimeEnv(string $sourceEnv, string $dest, array &$report): void
{
    $target = $dest . DIRECTORY_SEPARATOR . '.env';
    $content = file_get_contents($sourceEnv);
    if ($content === false) {
        throw new RuntimeException('Impossibile leggere il file env demo sorgente.');
    }

    if (!str_contains($content, "app.baseURL = 'http://localhost/demo-app/public/'") && !str_contains($content, 'app.baseURL')) {
        $content .= PHP_EOL . "app.baseURL = 'http://localhost/demo-runtime/'" . PHP_EOL;
    }

    $content = preg_replace(
        "/^app\.baseURL\s*=.*$/m",
        "app.baseURL = 'http://localhost/demo-runtime/'",
        $content
    ) ?? $content;

    foreach ([
        'email.fromName',
    ] as $envKey) {
        $content = quoteEnvValueIfNeeded($content, $envKey);
    }

    if (file_put_contents($target, $content) === false) {
        throw new RuntimeException('Impossibile scrivere il file env della runtime demo.');
    }
    $report['generated'][] = ['type' => 'env', 'path' => $target];
}

function writeRootIndex(string $dest, array &$report): void
{
    $indexPath = $dest . DIRECTORY_SEPARATOR . 'index.php';
    $content = <<<'PHP'
<?php

declare(strict_types=1);

use CodeIgniter\Boot;
use Config\Paths;

$minPhpVersion = '8.1';
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    exit(sprintf(
        'Your PHP version must be %s or higher to run CodeIgniter. Current version: %s',
        $minPhpVersion,
        PHP_VERSION
    ));
}

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . 'app/Config/Paths.php';
$paths = new Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'Boot.php';

exit(Boot::bootWeb($paths));
PHP;

    if (file_put_contents($indexPath, $content . PHP_EOL) === false) {
        throw new RuntimeException('Impossibile scrivere il front controller demo.');
    }
    $report['generated'][] = ['type' => 'front_controller', 'path' => $indexPath];
}

function writeRootSpark(string $dest, array &$report): void
{
    $sparkPath = $dest . DIRECTORY_SEPARATOR . 'spark';
    $content = <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodeIgniter\Boot;
use Config\Paths;

if (str_starts_with(PHP_SAPI, 'cgi')) {
    exit("The cli tool is not supported when running php-cgi. It needs php-cli to function!\n\n");
}

$minPhpVersion = '8.1';
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    exit(sprintf(
        'Your PHP version must be %s or higher to run CodeIgniter. Current version: %s',
        $minPhpVersion,
        PHP_VERSION
    ));
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . 'app/Config/Paths.php';
$paths = new Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'Boot.php';

exit(Boot::bootSpark($paths));
PHP;

    if (file_put_contents($sparkPath, $content . PHP_EOL) === false) {
        throw new RuntimeException('Impossibile scrivere lo spark demo.');
    }

    $report['generated'][] = ['type' => 'spark', 'path' => $sparkPath];
}

function writeRootHtaccess(string $dest, array &$report): void
{
    $htaccessPath = $dest . DIRECTORY_SEPARATOR . '.htaccess';
    $content = <<<'HTACCESS'
<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php/$1 [L,QSA]
</IfModule>

<IfModule !mod_rewrite.c>
    ErrorDocument 404 /index.php
</IfModule>
HTACCESS;

    if (file_put_contents($htaccessPath, $content . PHP_EOL) === false) {
        throw new RuntimeException('Impossibile scrivere il file .htaccess demo.');
    }
    $report['generated'][] = ['type' => 'htaccess', 'path' => $htaccessPath];
}

function createWritableStructure(string $dest, array &$report): void
{
    $directories = [
        'writable',
        'writable/cache',
        'writable/cache/temp',
        'writable/logs',
        'writable/session',
        'writable/uploads',
        'writable/uploads/messages',
        'writable/uploads/drafts',
        'writable/debugbar',
        'writable/demo_setup',
        'writable/demo_requests',
    ];

    foreach ($directories as $dirRel) {
        $dir = $dest . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dirRel);
        ensureDirectory($dir);
    }

    $writableHtaccessSource = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_file($writableHtaccessSource)) {
        $target = $dest . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . '.htaccess';
        copy($writableHtaccessSource, $target);
        $report['copied'][] = ['type' => 'file', 'from' => $writableHtaccessSource, 'to' => $target];
    }
}

function auditMissingAssets(string $root): array
{
    $patterns = [
        "/base_url\\('([^']+)'\\)/",
        '/base_url\\("([^"]+)"\\)/',
        "/FCPATH\\s*\\.\\s*'([^']+)'/",
        '/FCPATH\\s*\\.\\s*"([^"]+)"/',
    ];

    $files = listPhpFiles($root . DIRECTORY_SEPARATOR . 'app');
    $missing = [];
    $seen = [];

    foreach ($files as $file) {
        $content = file_get_contents($file);
        if ($content === false) {
            continue;
        }

        foreach ($patterns as $pattern) {
            $result = preg_match_all($pattern, $content, $matches);
            if ($result === false || $result === 0) {
                continue;
            }

            foreach (($matches[1] ?? []) as $match) {
                $asset = trim((string)$match);
                if ($asset === '' || str_contains($asset, '<?=') || str_contains($asset, '<?php')) {
                    continue;
                }

                if (preg_match('#^(https?:)?//#i', $asset) === 1) {
                    continue;
                }

                if (str_contains($asset, '$') || str_contains($asset, '{') || str_contains($asset, '?')) {
                    continue;
                }

                if (!isLikelyStaticReference($asset)) {
                    continue;
                }

                $assetPath = resolveReferencedAssetPath($root, $asset);
                if ($assetPath === null || is_file($assetPath) || is_dir($assetPath)) {
                    continue;
                }

                $key = $file . '|' . $asset;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $missing[] = [
                    'file' => $file,
                    'reference' => $asset,
                    'expected_path' => $assetPath,
                ];
            }
        }
    }

    usort($missing, static function (array $a, array $b): int {
        $cmp = strcmp($a['reference'], $b['reference']);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp($a['file'], $b['file']);
    });

    return $missing;
}

function isLikelyStaticReference(string $asset): bool
{
    $asset = ltrim(str_replace(['\\', '/'], '/', trim($asset)), '/');
    if ($asset === '') {
        return false;
    }

    $prefixes = [
        'public/',
        'assets/',
        'bootstrap/',
        'dist/',
        'plugins/',
        'css/',
        'js/',
        'img/',
        'images/',
        'fonts/',
        'notifications/',
        'sounds/',
        'upload/',
        'uploads/',
        'favicon',
        'manifest.json',
        'robots.txt',
        'sw.js',
    ];

    foreach ($prefixes as $prefix) {
        if (str_starts_with($asset, $prefix)) {
            return true;
        }
    }

    return preg_match('/\.(css|js|map|json|png|jpe?g|gif|svg|ico|webp|woff2?|ttf|otf|eot|mp3|wav|ogg|mp4|webm|pdf|txt)$/i', $asset) === 1;
}

function listPhpFiles(string $directory): array
{
    $result = [];
    $items = scandir($directory);
    if ($items === false) {
        return $result;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $full = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full)) {
            $result = array_merge($result, listPhpFiles($full));
            continue;
        }

        if (strtolower(pathinfo($full, PATHINFO_EXTENSION)) === 'php') {
            $result[] = $full;
        }
    }

    return $result;
}

function resolveReferencedAssetPath(string $root, string $asset): ?string
{
    $asset = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $asset), DIRECTORY_SEPARATOR);
    if ($asset === '') {
        return null;
    }

    $candidates = [
        $root . DIRECTORY_SEPARATOR . $asset,
    ];

    if (!str_starts_with($asset, 'public' . DIRECTORY_SEPARATOR)) {
        $candidates[] = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $asset;
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate) || is_dir($candidate)) {
            return $candidate;
        }
    }

    return $candidates[0] ?? null;
}

function summarizeMissingAssets(array $missingAssets): array
{
    $summary = [];

    foreach ($missingAssets as $row) {
        $reference = ltrim(str_replace(['\\', '/'], '/', (string) $row['reference']), '/');
        $parts = explode('/', $reference);

        $bucket = $parts[0] ?? 'other';
        if ($bucket === 'public' && isset($parts[1])) {
            $bucket .= '/' . $parts[1];
        }

        if (!isset($summary[$bucket])) {
            $summary[$bucket] = [
                'bucket' => $bucket,
                'count' => 0,
                'examples' => [],
            ];
        }

        $summary[$bucket]['count']++;
        if (count($summary[$bucket]['examples']) < 5 && !in_array($reference, $summary[$bucket]['examples'], true)) {
            $summary[$bucket]['examples'][] = $reference;
        }
    }

    usort($summary, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
    return array_values($summary);
}

function buildNotes(array $missingAssets, array $summary): array
{
    if ($missingAssets === []) {
        return [
            'Runtime demo pronta senza asset mancanti rilevati nelle referenze statiche.',
        ];
    }

    $topBuckets = array_slice(array_map(
        static fn(array $row): string => $row['bucket'] . ' (' . $row['count'] . ')',
        $summary
    ), 0, 5);

    return [
        'La runtime demo e stata generata, ma il repository non contiene ancora tutti gli asset legacy referenziati dalle view.',
        'I riferimenti statici mancanti sono stati filtrati escludendo le route applicative, quindi il report evidenzia solo gap reali di frontend o media.',
        'Fino al recupero o alla neutralizzazione di questi asset, alcune schermate legacy potrebbero non essere renderizzate correttamente.',
        'Bucket principali rilevati: ' . implode(', ', $topBuckets),
    ];
}

function writeRuntimeReport(array $report): string
{
    $path = DEMO_RUNTIME_REPORT_DIR . DIRECTORY_SEPARATOR . 'demo_runtime_' . date('Ymd_His') . '.json';
    writeJsonFile($path, $report, 'Impossibile scrivere il report della runtime demo.');
    return $path;
}

function ensureDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Impossibile creare la directory: ' . $directory);
    }
}

function mirrorReportsIntoRuntime(string $root, string $dest, array $report): void
{
    $runtimeReportDir = $dest . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'demo_setup';
    ensureDirectory($runtimeReportDir);

    $runtimeReportPath = $runtimeReportDir . DIRECTORY_SEPARATOR . 'demo_runtime_' . date('Ymd_His') . '.json';
    writeJsonFile($runtimeReportPath, $report, 'Impossibile scrivere il report runtime dentro la copia demo.');

    $sourceReportDir = $root . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'demo_setup';
    foreach (['demo_seed_*.json'] as $pattern) {
        $latestPath = latestMatchingFile($sourceReportDir . DIRECTORY_SEPARATOR . $pattern);
        if ($latestPath === null) {
            continue;
        }

        $targetPath = $runtimeReportDir . DIRECTORY_SEPARATOR . basename($latestPath);
        if (!copy($latestPath, $targetPath)) {
            throw new RuntimeException('Impossibile copiare il report nella runtime demo: ' . $latestPath);
        }
    }
}

function latestMatchingFile(string $pattern): ?string
{
    $matches = glob($pattern) ?: [];
    if ($matches === []) {
        return null;
    }

    usort($matches, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return $matches[0] ?? null;
}

function writeJsonFile(string $path, array $payload, string $errorMessage): void
{
    if (file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
        throw new RuntimeException($errorMessage);
    }
}

function quoteEnvValueIfNeeded(string $content, string $key): string
{
    $pattern = '/^' . preg_quote($key, '/') . '\s*=\s*(.+)$/m';

    return preg_replace_callback(
        $pattern,
        static function (array $matches) use ($key): string {
            $rawValue = trim((string) ($matches[1] ?? ''));
            if ($rawValue === '') {
                return $key . ' =';
            }

            if (
                (str_starts_with($rawValue, "'") && str_ends_with($rawValue, "'")) ||
                (str_starts_with($rawValue, '"') && str_ends_with($rawValue, '"'))
            ) {
                return $key . ' = ' . $rawValue;
            }

            if (!preg_match('/\s/', $rawValue)) {
                return $key . ' = ' . $rawValue;
            }

            return $key . " = '" . str_replace("'", "\\'", $rawValue) . "'";
        },
        $content
    ) ?? $content;
}
