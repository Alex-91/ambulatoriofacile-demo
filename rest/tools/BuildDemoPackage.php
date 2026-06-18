<?php
declare(strict_types=1);

/**
 * Build a sanitized demo package ready to share:
 * - clones schema from the local source database
 * - copies only safe catalog tables
 * - seeds the demo database with fake data
 * - prepares a standalone runtime
 * - removes uploads/backup artifacts from the runtime
 * - rewrites brand/domain/secrets inside the runtime copy
 * - exports a SQL dump of the demo database
 * - creates a final zip under dist/
 *
 * Example:
 *   php tools/BuildDemoPackage.php --source-db=mailsimo --target-db=ambulatoriofacile_demo
 */

date_default_timezone_set('Europe/Rome');

const DEMO_BUILD_DEFAULT_ENV_FILE = '.env.demo';
const DEMO_BUILD_DEFAULT_SOURCE_DB = 'mailsimo';
const DEMO_BUILD_DEFAULT_TARGET_DB = 'ambulatoriofacile_demo';
const DEMO_BUILD_DEFAULT_BRAND = 'AmbulatorioFacile';
const DEMO_BUILD_DEFAULT_RUNTIME_DEST = 'dist/demo-runtime';
const DEMO_BUILD_DEFAULT_PACKAGE_DIR = 'dist/ambulatoriofacile-demo-package';
const DEMO_BUILD_DEFAULT_PACKAGE_ZIP = 'dist/ambulatoriofacile-demo-package.zip';
const DEMO_BUILD_DEFAULT_DEMO_PASSWORD = 'Demo2026';
const DEMO_BUILD_REPORT_DIR = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'demo_setup';

main($argv ?? []);

function main(array $argv): void
{
    ensureDirectory(DEMO_BUILD_REPORT_DIR);

    $options = parseOptions($argv);
    $root = normalizePath(dirname(__DIR__));
    $envPath = resolveProjectPath($root, $options['env_file']);
    $env = loadSimpleEnvFile($envPath);
    $config = buildConfig($root, $options, $env, $envPath);

    $report = [
        'started_at' => date('c'),
        'env_file' => $config['env_file'],
        'source_db' => $config['source_db'],
        'target_db' => $config['target_db'],
        'brand' => $config['brand'],
        'runtime_dest' => $config['runtime_dest'],
        'package_dir' => $config['package_dir'],
        'package_zip' => $config['package_zip'],
        'dump_path' => $config['dump_path'],
        'status' => 'running',
        'steps' => [],
    ];

    try {
        assertPathIsInsideProject($root, $config['runtime_dest']);
        assertPathIsInsideProject($root, $config['package_dir']);
        assertPathIsInsideProject($root, $config['package_zip']);
        assertPathIsInsideProject($root, $config['dump_path']);

        resetPath($config['package_dir']);
        deleteFileIfExists($config['package_zip']);

        runBuildStep($report, 'initialize_demo_database', function () use ($config, $root): array {
            return runPhpScript($root, 'tools/InitializeDemoDatabase.php', [
                '--host=' . $config['host'],
                '--port=' . $config['port'],
                '--user=' . $config['user'],
                '--pass=' . $config['pass'],
                '--source-db=' . $config['source_db'],
                '--target-db=' . $config['target_db'],
            ], $config['php_bin']);
        });

        runBuildStep($report, 'copy_demo_catalog', function () use ($config, $root): array {
            return runPhpScript($root, 'tools/CopyDemoCatalogData.php', [
                '--host=' . $config['host'],
                '--port=' . $config['port'],
                '--user=' . $config['user'],
                '--pass=' . $config['pass'],
                '--source-db=' . $config['source_db'],
                '--target-db=' . $config['target_db'],
            ], $config['php_bin']);
        });

        runBuildStep($report, 'seed_demo_data', function () use ($config, $root): array {
            return runPhpScript($root, 'tools/SeedDemoData.php', [
                '--env-file=' . relativePath($root, $config['env_file']),
                '--host=' . $config['host'],
                '--port=' . $config['port'],
                '--user=' . $config['user'],
                '--pass=' . $config['pass'],
                '--database=' . $config['target_db'],
                '--brand=' . $config['brand'],
                '--demo-password=' . $config['demo_password'],
            ], $config['php_bin']);
        });

        runBuildStep($report, 'prepare_demo_runtime', function () use ($config, $root): array {
            return runPhpScript($root, 'tools/PrepareDemoRuntime.php', [
                '--env-file=' . relativePath($root, $config['env_file']),
                '--dest=' . relativePath($root, $config['runtime_dest']),
            ], $config['php_bin']);
        });

        runBuildStep($report, 'sanitize_runtime', function () use ($config): array {
            $runtimeReport = sanitizeRuntime($config);

            return [
                'exit_code' => 0,
                'stdout' => 'Runtime sanitizzata in ' . $config['runtime_dest'],
                'stderr' => '',
                'details' => $runtimeReport,
            ];
        });

        runBuildStep($report, 'dump_demo_database', function () use ($config): array {
            exportDatabaseDump($config);

            return [
                'exit_code' => 0,
                'stdout' => 'Dump creato in ' . $config['dump_path'],
                'stderr' => '',
            ];
        });

        runBuildStep($report, 'assemble_package', function () use ($config, $root): array {
            ensureDirectory($config['package_dir']);
            $runtimeTarget = $config['package_dir'] . DIRECTORY_SEPARATOR . 'runtime';

            copyDirectoryTree($config['runtime_dest'], $runtimeTarget);
            writePackageReadme($config, $root, $config['package_dir']);

            return [
                'exit_code' => 0,
                'stdout' => 'Pacchetto assemblato in ' . $config['package_dir'],
                'stderr' => '',
            ];
        });

        runBuildStep($report, 'zip_package', function () use ($config): array {
            createZipFromDirectory($config['package_dir'], $config['package_zip']);

            return [
                'exit_code' => 0,
                'stdout' => 'Zip creato in ' . $config['package_zip'],
                'stderr' => '',
            ];
        });

        $report['finished_at'] = date('c');
        $report['status'] = 'ok';
        $report['seed_report'] = latestMatchingFile(DEMO_BUILD_REPORT_DIR . DIRECTORY_SEPARATOR . 'demo_seed_*.json');
        $report['runtime_report'] = latestMatchingFile(DEMO_BUILD_REPORT_DIR . DIRECTORY_SEPARATOR . 'demo_runtime_*.json');
        $report['build_summary'] = [
            'package_zip' => $config['package_zip'],
            'package_dir' => $config['package_dir'],
            'runtime_dest' => $config['runtime_dest'],
            'dump_path' => $config['dump_path'],
            'brand' => $config['brand'],
            'database' => $config['target_db'],
        ];

        $reportPath = writeBuildReport($report);

        echo "Pacchetto demo pronto.\n";
        echo "Brand: {$config['brand']}\n";
        echo "Database demo: {$config['target_db']}\n";
        echo "Runtime: {$config['runtime_dest']}\n";
        echo "Dump SQL: {$config['dump_path']}\n";
        echo "Zip finale: {$config['package_zip']}\n";
        echo "Report: {$reportPath}\n";
        exit(0);
    } catch (Throwable $e) {
        $report['finished_at'] = date('c');
        $report['status'] = 'error';
        $report['error'] = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        $reportPath = writeBuildReport($report);

        fwrite(STDERR, "Errore build demo package: {$e->getMessage()}\n");
        fwrite(STDERR, "Report: {$reportPath}\n");
        exit(1);
    }
}

function parseOptions(array $argv): array
{
    return [
        'env_file' => optionValue($argv, 'env-file') ?: DEMO_BUILD_DEFAULT_ENV_FILE,
        'source_db' => optionValue($argv, 'source-db') ?: DEMO_BUILD_DEFAULT_SOURCE_DB,
        'target_db' => optionValue($argv, 'target-db') ?: DEMO_BUILD_DEFAULT_TARGET_DB,
        'brand' => optionValue($argv, 'brand') ?: DEMO_BUILD_DEFAULT_BRAND,
        'runtime_dest' => optionValue($argv, 'runtime-dest') ?: DEMO_BUILD_DEFAULT_RUNTIME_DEST,
        'package_dir' => optionValue($argv, 'package-dir') ?: DEMO_BUILD_DEFAULT_PACKAGE_DIR,
        'package_zip' => optionValue($argv, 'package-zip') ?: DEMO_BUILD_DEFAULT_PACKAGE_ZIP,
        'demo_password' => optionValue($argv, 'demo-password') ?: DEMO_BUILD_DEFAULT_DEMO_PASSWORD,
        'php_bin' => optionValue($argv, 'php-bin'),
        'mysqldump_bin' => optionValue($argv, 'mysqldump-bin'),
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

function buildConfig(string $root, array $options, array $env, string $envPath): array
{
    $host = trim((string)($env['database.default.hostname'] ?? 'localhost'));
    $port = (int)($env['database.default.port'] ?? 3306);
    $user = trim((string)($env['database.default.username'] ?? 'root'));
    $pass = (string)($env['database.default.password'] ?? 'root');
    $targetDb = trim((string)($options['target_db'] ?: ($env['database.default.database'] ?? DEMO_BUILD_DEFAULT_TARGET_DB)));
    $brand = trim((string)($options['brand'] ?: ($env['PRODUCT_BRAND_NAME'] ?? DEMO_BUILD_DEFAULT_BRAND)));
    $phpBin = trim((string)($options['php_bin'] ?: PHP_BINARY));

    if ($targetDb === '') {
        throw new RuntimeException('Database demo target non configurato.');
    }

    if ($brand === '') {
        throw new RuntimeException('Brand demo non configurato.');
    }

    return [
        'env_file' => $envPath,
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'pass' => $pass,
        'source_db' => trim((string)$options['source_db']),
        'target_db' => $targetDb,
        'brand' => $brand,
        'brand_short' => trim((string)($env['PRODUCT_BRAND_SHORT_NAME'] ?? $brand)),
        'brand_description' => trim((string)($env['PRODUCT_BRAND_MANIFEST_DESCRIPTION'] ?? 'Piattaforma operativa per agenda, team, promemoria e comunicazione.')),
        'canonical_url' => normalizeCanonicalUrl((string)($env['APP_CANONICAL_URL'] ?? 'https://demo.ambulatoriofacile.example/')),
        'from_email' => trim((string)($env['email.fromEmail'] ?? 'demo@ambulatoriofacile.example')),
        'from_name' => trim((string)($env['email.fromName'] ?? $brand)),
        'db_key' => trim((string)($env['DB_ENCRYPTION_KEY'] ?? ($env['database.default.DB_ENCRYPTION_KEY'] ?? 'change-demo-key'))),
        'demo_password' => trim((string)$options['demo_password']),
        'runtime_dest' => resolveProjectPath($root, (string)$options['runtime_dest']),
        'package_dir' => resolveProjectPath($root, (string)$options['package_dir']),
        'package_zip' => resolveProjectPath($root, (string)$options['package_zip']),
        'dump_path' => resolveProjectPath($root, str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$options['package_dir']) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . $targetDb . '.sql'),
        'php_bin' => $phpBin,
        'mysqldump_bin' => trim((string)($options['mysqldump_bin'] ?: '')),
        'mysqldump_candidates' => findMysqldumpCandidates((string)($options['mysqldump_bin'] ?: '')),
        'auth_issuer' => trim((string)($env['AUTH_HANDOFF_ISSUER'] ?? slugify($brand . '-demo'))),
    ];
}

function sanitizeRuntime(array $config): array
{
    $runtimePath = normalizePath($config['runtime_dest']);
    if (!is_dir($runtimePath)) {
        throw new RuntimeException('Runtime demo non trovata: ' . $runtimePath);
    }

    $removedArtifacts = pruneRuntimeArtifacts($runtimePath);
    resetUploadDirectory($runtimePath . DIRECTORY_SEPARATOR . 'upload');
    $updatedFiles = rewriteRuntimeTextFiles($runtimePath, $config);
    writeRuntimeReadme($runtimePath, $config);

    return [
        'runtime_path' => $runtimePath,
        'removed_artifacts' => $removedArtifacts,
        'text_files_updated' => $updatedFiles,
    ];
}

function pruneRuntimeArtifacts(string $runtimePath): array
{
    $removed = [];
    foreach (listAllFiles($runtimePath) as $file) {
        $basename = strtolower(basename($file));
        if (
            str_contains($basename, '_old') ||
            str_contains($basename, '.php_') ||
            str_contains($basename, ' copy.') ||
            str_ends_with($basename, '.zip')
        ) {
            if (@unlink($file)) {
                $removed[] = $file;
            }
        }
    }

    return $removed;
}

function resetUploadDirectory(string $uploadPath): void
{
    if (is_dir($uploadPath)) {
        deleteDirectoryTree($uploadPath);
    }

    ensureDirectory($uploadPath);
    $gitkeep = $uploadPath . DIRECTORY_SEPARATOR . '.gitkeep';
    if (file_put_contents($gitkeep, '') === false) {
        throw new RuntimeException('Impossibile ricreare il placeholder upload: ' . $gitkeep);
    }
}

function rewriteRuntimeTextFiles(string $runtimePath, array $config): int
{
    $replacements = buildRuntimeReplacementMap($config);
    $files = listTextFilesForRewrite($runtimePath);
    $updatedFiles = 0;

    foreach ($files as $file) {
        $content = file_get_contents($file);
        if ($content === false) {
            continue;
        }

        $updated = str_replace(array_keys($replacements), array_values($replacements), $content);
        if ($updated === $content) {
            continue;
        }

        if (file_put_contents($file, $updated) === false) {
            throw new RuntimeException('Impossibile aggiornare il file runtime: ' . $file);
        }

        $updatedFiles++;
    }

    return $updatedFiles;
}

function buildRuntimeReplacementMap(array $config): array
{
    $canonical = normalizeCanonicalUrl((string)$config['canonical_url']);
    $canonicalNoSlash = rtrim($canonical, '/');
    $uploadBase = rtrim($canonical, '/') . '/upload/';
    $fromEmail = trim((string)$config['from_email']);
    $dbPasswordPlaceholder = trim((string)$config['pass']) !== '' ? trim((string)$config['pass']) : 'change-demo-db-pass';

    return [
        'AmbulatoriCLOUD' => (string)$config['brand'],
        'AmbulatoriCloud' => (string)$config['brand'],
        'AMBULATORI.Cloud' => (string)$config['brand'],
        'AgendaFlow' => (string)$config['brand'],
        'agendaflow-demo' => (string)$config['auth_issuer'],
        'ambulatori-cloud-legacy' => (string)$config['auth_issuer'],
        'noreply@ambulatori.cloud' => $fromEmail,
        'demo@example.com' => $fromEmail,
        'mailto:you@example.com' => 'mailto:' . $fromEmail,
        'mailto:demo@example.com' => 'mailto:' . $fromEmail,
        'https://www.ambulatori.cloud/upload/' => $uploadBase,
        'https://ambulatori.cloud/upload/' => $uploadBase,
        'https://www.ambulatori.cloud/' => $canonical,
        'https://ambulatori.cloud/' => $canonical,
        'https://www.ambulatori.cloud' => $canonicalNoSlash,
        'https://ambulatori.cloud' => $canonicalNoSlash,
        'https://demo.example.com/' => $canonical,
        'Piattaforma operativa per prenotazioni, comunicazione e notifiche.' => (string)$config['brand_description'],
        'Demo2026!' => (string)$config['demo_password'],
        'PartitaIVA22' => (string)$config['db_key'],
        'Tira74GL!#' => $dbPasswordPlaceholder,
        'Tira74GL!' => 'change-demo-secret',
        '4cdpjcxdfm926joe' => 'change-demo-sms-token',
        'nOyFfgv1gUMfZKrXgonpENfNEY4Nf6JM' => 'change-demo-token',
    ];
}

function listTextFilesForRewrite(string $runtimePath): array
{
    $extensions = ['php', 'js', 'json', 'svg', 'html', 'txt', 'md', 'css', 'env'];
    $files = [];

    foreach (listAllFiles($runtimePath) as $file) {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $basename = strtolower(basename($file));

        if ($basename === '.env' || in_array($extension, $extensions, true)) {
            $files[] = $file;
        }
    }

    return $files;
}

function listAllFiles(string $directory): array
{
    $files = [];
    $items = scandir($directory);
    if ($items === false) {
        return $files;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $full = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full) && !is_link($full)) {
            $files = array_merge($files, listAllFiles($full));
            continue;
        }

        $files[] = $full;
    }

    return $files;
}

function writeRuntimeReadme(string $runtimePath, array $config): void
{
    $content = [
        (string)$config['brand'] . ' - runtime demo sanitizzata',
        '',
        'Questa copia e stata generata per una demo condivisibile:',
        '- brand demo: ' . $config['brand'],
        '- database previsto: ' . $config['target_db'],
        '- nessun allegato reale copiato da upload/',
        '- nessuna credenziale reale inclusa nel package',
        '',
        'Accessi demo principali:',
        '- demo.admin / ' . $config['demo_password'],
        '- alessio2 / ' . $config['demo_password'] . ' / OTP 2510',
        '- demo.portal.med / ' . $config['demo_password'],
        '- demo.portal.sport / ' . $config['demo_password'],
        '',
        'Nota:',
        '- se pubblichi su un dominio diverso, aggiorna app.baseURL e APP_CANONICAL_URL dentro .env.',
    ];

    $path = $runtimePath . DIRECTORY_SEPARATOR . 'README-DEMO.txt';
    if (file_put_contents($path, implode(PHP_EOL, $content) . PHP_EOL) === false) {
        throw new RuntimeException('Impossibile scrivere README-DEMO.txt nella runtime.');
    }
}

function exportDatabaseDump(array $config): void
{
    $binaries = (array)($config['mysqldump_candidates'] ?? []);
    if ($binaries === []) {
        throw new RuntimeException('mysqldump.exe non trovato. Passa --mysqldump-bin=percorso\\mysqldump.exe');
    }

    ensureDirectory(dirname((string)$config['dump_path']));
    deleteFileIfExists((string)$config['dump_path']);

    $errors = [];
    foreach ($binaries as $binary) {
        $parts = [
            escapeshellarg($binary),
            escapeshellarg('--host=' . (string)$config['host']),
            escapeshellarg('--port=' . (string)(int)$config['port']),
            escapeshellarg('--user=' . (string)$config['user']),
            '--default-character-set=latin1',
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--skip-comments',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            escapeshellarg('--result-file=' . (string)$config['dump_path']),
            escapeshellarg((string)$config['target_db']),
        ];

        if ((string)$config['pass'] !== '') {
            $parts[] = escapeshellarg('--password=' . (string)$config['pass']);
        }

        deleteFileIfExists((string)$config['dump_path']);
        $result = runProcess(implode(' ', $parts), dirname((string)$config['dump_path']));
        if ($result['exit_code'] === 0 && is_file((string)$config['dump_path'])) {
            return;
        }

        $errors[] = basename($binary) . ': ' . trim($result['stderr'] ?: $result['stdout']);
    }

    throw new RuntimeException('Export SQL demo fallito: ' . implode(' | ', $errors));
}

function writePackageReadme(array $config, string $root, string $packageDir): void
{
    $seedReportPath = latestMatchingFile(DEMO_BUILD_REPORT_DIR . DIRECTORY_SEPARATOR . 'demo_seed_*.json');
    $seedReport = readJsonFile($seedReportPath);
    $dumpRelative = relativePath($packageDir, $config['dump_path']);
    $runtimeRelative = relativePath($packageDir, $config['package_dir'] . DIRECTORY_SEPARATOR . 'runtime');
    $accounts = buildReadmeAccounts($seedReport, (string)$config['demo_password']);

    $lines = [
        (string)$config['brand'] . ' - pacchetto demo condivisibile',
        '',
        'Contenuto del pacchetto:',
        '- runtime applicativa: ' . $runtimeRelative,
        '- dump SQL demo: ' . $dumpRelative,
        '',
        'Istruzioni rapide:',
        '1. Crea un database MySQL vuoto con nome ' . $config['target_db'] . '.',
        '2. Importa il file SQL dalla cartella database.',
        '3. Configura runtime/.env con host, utente e password del nuovo server.',
        '4. Se cambi dominio o cartella pubblica, aggiorna app.baseURL e APP_CANONICAL_URL.',
        '5. Pubblica la cartella runtime sul server.',
        '',
        'Account demo consigliati:',
    ];

    foreach ($accounts as $account) {
        $lines[] = '- ' . $account;
    }

    $lines[] = '';
    $lines[] = 'Nota sicurezza:';
    $lines[] = '- il package non include upload reali, credenziali reali, lead demo locali o dati cliente reali.';

    $path = $packageDir . DIRECTORY_SEPARATOR . 'README-SETUP-DEMO.txt';
    if (file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL) === false) {
        throw new RuntimeException('Impossibile scrivere README-SETUP-DEMO.txt.');
    }
}

function buildReadmeAccounts(?array $seedReport, string $defaultPassword): array
{
    $accounts = [];
    $summary = [
        'demo.admin' => 'Admin demo',
        'alessio2' => 'Operativo con OTP 2510',
        'demo.portal.med' => 'Portale cliente medical',
        'demo.portal.sport' => 'Portale cliente sport',
    ];

    if (is_array($seedReport) && is_array($seedReport['accounts'] ?? null)) {
        foreach ((array)$seedReport['accounts'] as $row) {
            $username = trim((string)($row['username'] ?? ''));
            if ($username === '' || !isset($summary[$username])) {
                continue;
            }

            $label = $summary[$username];
            $note = $username === 'alessio2' ? ' / OTP 2510' : '';
            $accounts[] = $label . ': ' . $username . ' / ' . $defaultPassword . $note;
        }
    }

    if ($accounts !== []) {
        return $accounts;
    }

    return [
        'Admin demo: demo.admin / ' . $defaultPassword,
        'Operativo con OTP 2510: alessio2 / ' . $defaultPassword,
        'Portale cliente medical: demo.portal.med / ' . $defaultPassword,
        'Portale cliente sport: demo.portal.sport / ' . $defaultPassword,
    ];
}

function runBuildStep(array &$report, string $name, callable $callback): void
{
    $startedAt = date('c');
    $result = $callback();
    $report['steps'][] = [
        'name' => $name,
        'started_at' => $startedAt,
        'finished_at' => date('c'),
        'exit_code' => (int)($result['exit_code'] ?? 0),
        'stdout' => trim((string)($result['stdout'] ?? '')),
        'stderr' => trim((string)($result['stderr'] ?? '')),
        'details' => $result['details'] ?? null,
    ];

    if ((int)($result['exit_code'] ?? 0) !== 0) {
        throw new RuntimeException('Step fallito [' . $name . ']: ' . trim((string)($result['stderr'] ?? $result['stdout'] ?? '')));
    }
}

function runPhpScript(string $root, string $relativeScript, array $arguments, string $phpBin): array
{
    $scriptPath = resolveProjectPath($root, $relativeScript);
    if (!is_file($scriptPath)) {
        throw new RuntimeException('Script PHP non trovato: ' . $scriptPath);
    }

    $parts = [escapeshellarg($phpBin), escapeshellarg($scriptPath)];
    foreach ($arguments as $argument) {
        $parts[] = escapeshellarg($argument);
    }

    return runProcess(implode(' ', $parts), $root);
}

function runProcess(string $command, string $workingDirectory): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $workingDirectory);
    if (!is_resource($process)) {
        throw new RuntimeException('Impossibile avviare il processo: ' . $command);
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

function findMysqldumpCandidates(string $preferred = ''): array
{
    $candidates = [];
    if ($preferred !== '') {
        $candidates[] = $preferred;
    }

    $candidates = array_merge($candidates, [
        'C:\\wamp64\\bin\\mysql\\mysql8.3.0\\bin\\mysqldump.exe',
        'C:\\xampp_82\\mysql\\bin\\mysqldump.exe',
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    ]);

    $resolved = [];
    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }

        $normalized = normalizePath($candidate);
        if (in_array($normalized, $resolved, true)) {
            continue;
        }
        $resolved[] = $normalized;
    }

    return $resolved;
}

function createZipFromDirectory(string $sourceDir, string $zipPath): void
{
    ensureDirectory(dirname($zipPath));

    $zip = new ZipArchive();
    $status = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($status !== true) {
        throw new RuntimeException('Impossibile creare lo zip finale: ' . $zipPath);
    }

    $sourceDir = normalizePath($sourceDir);
    foreach (listAllFiles($sourceDir) as $file) {
        $localName = relativePath($sourceDir, $file);
        if (!$zip->addFile($file, $localName)) {
            $zip->close();
            throw new RuntimeException('Impossibile aggiungere il file allo zip: ' . $file);
        }
    }

    if (!$zip->close()) {
        throw new RuntimeException('Impossibile chiudere correttamente lo zip finale.');
    }
}

function loadSimpleEnvFile(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('File env demo non trovato: ' . $path);
    }

    $rows = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($rows === false) {
        throw new RuntimeException('Impossibile leggere il file env demo: ' . $path);
    }

    $env = [];
    foreach ($rows as $row) {
        $line = trim((string)$row);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        if ($value !== '' && (($value[0] === "'" && substr($value, -1) === "'") || ($value[0] === '"' && substr($value, -1) === '"'))) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    return $env;
}

function normalizeCanonicalUrl(string $url): string
{
    $trimmed = trim($url);
    if ($trimmed === '') {
        return 'https://demo.ambulatoriofacile.example/';
    }

    return rtrim($trimmed, '/') . '/';
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

function relativePath(string $basePath, string $targetPath): string
{
    $basePath = rtrim(normalizePath($basePath), DIRECTORY_SEPARATOR);
    $targetPath = normalizePath($targetPath);

    if (str_starts_with($targetPath, $basePath . DIRECTORY_SEPARATOR)) {
        return substr($targetPath, strlen($basePath) + 1);
    }

    return $targetPath;
}

function assertPathIsInsideProject(string $projectRoot, string $path): void
{
    $projectRoot = rtrim(normalizePath($projectRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $path = normalizePath($path);

    if (!str_starts_with($path . DIRECTORY_SEPARATOR, $projectRoot) && $path !== rtrim($projectRoot, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Il path deve rimanere dentro questo workspace: ' . $path);
    }
}

function resetPath(string $path): void
{
    $path = normalizePath($path);
    if (is_dir($path)) {
        deleteDirectoryTree($path);
    } elseif (is_file($path)) {
        deleteFileIfExists($path);
    }

    ensureDirectory($path);
}

function deleteFileIfExists(string $path): void
{
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('Impossibile eliminare il file: ' . $path);
    }
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

function ensureDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Impossibile creare la directory: ' . $directory);
    }
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

        copyFileEnsuringDirectory($from, $to);
    }
}

function copyFileEnsuringDirectory(string $source, string $dest): void
{
    ensureDirectory(dirname($dest));
    if (!copy($source, $dest)) {
        throw new RuntimeException('Impossibile copiare il file: ' . $source);
    }
}

function readJsonFile(?string $path): ?array
{
    if ($path === null || $path === '' || !is_file($path)) {
        return null;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        return null;
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : null;
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

function writeBuildReport(array $report): string
{
    $path = DEMO_BUILD_REPORT_DIR . DIRECTORY_SEPARATOR . 'demo_package_' . date('Ymd_His') . '.json';
    $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false || file_put_contents($path, $payload) === false) {
        throw new RuntimeException('Impossibile scrivere il report del pacchetto demo.');
    }

    return $path;
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
    return trim($value, '-');
}
