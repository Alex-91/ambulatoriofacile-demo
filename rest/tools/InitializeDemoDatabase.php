<?php
declare(strict_types=1);

/**
 * Create a dedicated local demo database and clone the schema only
 * from a non-farmacia source database. No table data is copied.
 *
 * Example:
 *   php tools/InitializeDemoDatabase.php --host=localhost --port=3306 --user=root --pass=root --source-db=mailsimo --target-db=ambulatoriofacile_demo
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Europe/Rome');

const DEMO_DEFAULT_HOST = 'localhost';
const DEMO_DEFAULT_PORT = 3306;
const DEMO_DEFAULT_USER = 'root';
const DEMO_DEFAULT_PASS = 'root';
const DEMO_DEFAULT_SOURCE_DB = 'mailsimo';
const DEMO_DEFAULT_TARGET_DB = 'ambulatoriofacile_demo';
const DEMO_FORBIDDEN_TARGETS = ['farmacia', 'mail', 'mailsimo'];
const DEMO_REPORT_DIR = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'demo_setup';

main($argv ?? []);

function main(array $argv): void
{
    $options = parseOptions($argv);
    ensureDirectory(DEMO_REPORT_DIR);

    if (in_array(strtolower($options['target_db']), DEMO_FORBIDDEN_TARGETS, true)) {
        throw new RuntimeException('Il target demo non puo essere farmacia, mail o mailsimo.');
    }

    if (strcasecmp($options['source_db'], $options['target_db']) === 0) {
        throw new RuntimeException('Source e target database devono essere diversi.');
    }

    $db = new mysqli(
        $options['host'],
        $options['user'],
        $options['pass'],
        '',
        $options['port']
    );
    $db->set_charset('utf8mb4');

    $report = [
        'started_at' => date('c'),
        'host' => $options['host'],
        'port' => $options['port'],
        'source_db' => $options['source_db'],
        'target_db' => $options['target_db'],
        'tables_cloned' => [],
        'tables_skipped' => [],
        'views_skipped' => [],
        'status' => 'running',
    ];

    try {
        assertDatabaseExists($db, $options['source_db']);
        createTargetDatabase($db, $options['target_db']);
        cloneSchemaOnly($db, $options['source_db'], $options['target_db'], $report);

        $report['finished_at'] = date('c');
        $report['status'] = 'ok';
        $report['summary'] = [
            'tables_cloned' => count($report['tables_cloned']),
            'tables_skipped' => count($report['tables_skipped']),
            'views_skipped' => count($report['views_skipped']),
        ];

        $path = writeReport($report);
        echo "Demo database pronto: {$options['target_db']}\n";
        echo "Report: {$path}\n";
        exit(0);
    } catch (Throwable $e) {
        $report['finished_at'] = date('c');
        $report['status'] = 'error';
        $report['error'] = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        $path = writeReport($report);
        fwrite(STDERR, "Errore bootstrap demo: {$e->getMessage()}\n");
        fwrite(STDERR, "Report: {$path}\n");
        exit(1);
    } finally {
        $db->close();
    }
}

function parseOptions(array $argv): array
{
    return [
        'host' => optionValue($argv, 'host') ?: DEMO_DEFAULT_HOST,
        'port' => (int) (optionValue($argv, 'port') ?: DEMO_DEFAULT_PORT),
        'user' => optionValue($argv, 'user') ?: DEMO_DEFAULT_USER,
        'pass' => optionValue($argv, 'pass') ?: DEMO_DEFAULT_PASS,
        'source_db' => optionValue($argv, 'source-db') ?: DEMO_DEFAULT_SOURCE_DB,
        'target_db' => optionValue($argv, 'target-db') ?: DEMO_DEFAULT_TARGET_DB,
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

function assertDatabaseExists(mysqli $db, string $database): void
{
    $stmt = $db->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
    $stmt->bind_param('s', $database);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$result) {
        throw new RuntimeException("Database sorgente non trovato: {$database}");
    }
}

function createTargetDatabase(mysqli $db, string $database): void
{
    $sql = sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET latin1 COLLATE latin1_swedish_ci',
        $db->real_escape_string($database)
    );
    $db->query($sql);
}

function cloneSchemaOnly(mysqli $db, string $sourceDb, string $targetDb, array &$report): void
{
    $sql = sprintf(
        "SELECT TABLE_NAME, TABLE_TYPE
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = '%s'
         ORDER BY TABLE_NAME ASC",
        $db->real_escape_string($sourceDb)
    );

    $result = $db->query($sql);
    while ($row = $result->fetch_assoc()) {
        $table = (string) ($row['TABLE_NAME'] ?? '');
        $type = strtoupper((string) ($row['TABLE_TYPE'] ?? ''));
        if ($table === '') {
            continue;
        }

        if ($type !== 'BASE TABLE') {
            $report['views_skipped'][] = $table;
            continue;
        }

        if (targetTableExists($db, $targetDb, $table)) {
            $report['tables_skipped'][] = $table;
            continue;
        }

        $cloneSql = sprintf(
            'CREATE TABLE `%s`.`%s` LIKE `%s`.`%s`',
            $db->real_escape_string($targetDb),
            $db->real_escape_string($table),
            $db->real_escape_string($sourceDb),
            $db->real_escape_string($table)
        );
        $db->query($cloneSql);
        $report['tables_cloned'][] = $table;
    }
    $result->free();
}

function targetTableExists(mysqli $db, string $database, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $database, $table);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (bool) $result;
}

function ensureDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Impossibile creare la directory {$directory}");
    }
}

function writeReport(array $report): string
{
    $path = DEMO_REPORT_DIR . DIRECTORY_SEPARATOR . 'demo_db_bootstrap_' . date('Ymd_His') . '.json';
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $path;
}
