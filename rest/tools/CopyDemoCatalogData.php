<?php
declare(strict_types=1);

/**
 * Copy only safe catalog/lookup data from the local product database
 * into the dedicated demo database. No users, clients, staff, messages,
 * agenda slots, appointments, or other personal/business records are copied.
 *
 * Example:
 *   php tools/CopyDemoCatalogData.php --host=localhost --port=3306 --user=root --pass=root --source-db=mailsimo --target-db=ambulatoriofacile_demo
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Europe/Rome');

const DEMO_CATALOG_DEFAULT_HOST = 'localhost';
const DEMO_CATALOG_DEFAULT_PORT = 3306;
const DEMO_CATALOG_DEFAULT_USER = 'root';
const DEMO_CATALOG_DEFAULT_PASS = 'root';
const DEMO_CATALOG_DEFAULT_SOURCE_DB = 'mailsimo';
const DEMO_CATALOG_DEFAULT_TARGET_DB = 'ambulatoriofacile_demo';
const DEMO_CATALOG_TABLES = [
    'dap04_type_users',
    'dap05_type_doctors',
    'dap06_mnu',
    'dap07_sub_mnu',
    'dap08_mnu_ruo',
    'dap13_function_select',
    'dap17_agenda_menu',
    'dap18_agenda_menu_permessi',
    'dap41_spec',
    'dap48_gio_ros',
    'dap_menu_prenotazioni',
    'dap_menu_schede',
];
const DEMO_CATALOG_REPORT_DIR = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'demo_setup';

main($argv ?? []);

function main(array $argv): void
{
    $options = parseOptions($argv);
    ensureDirectory(DEMO_CATALOG_REPORT_DIR);

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
        'source_db' => $options['source_db'],
        'target_db' => $options['target_db'],
        'tables' => [],
        'status' => 'running',
    ];

    try {
        assertDatabaseExists($db, $options['source_db']);
        assertDatabaseExists($db, $options['target_db']);
        copyCatalogTables($db, $options['source_db'], $options['target_db'], $report);

        $report['finished_at'] = date('c');
        $report['status'] = 'ok';
        $path = writeReport($report);
        echo "Catalogo demo copiato su {$options['target_db']}\n";
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
        fwrite(STDERR, "Errore copia catalogo demo: {$e->getMessage()}\n");
        fwrite(STDERR, "Report: {$path}\n");
        exit(1);
    } finally {
        $db->close();
    }
}

function parseOptions(array $argv): array
{
    return [
        'host' => optionValue($argv, 'host') ?: DEMO_CATALOG_DEFAULT_HOST,
        'port' => (int) (optionValue($argv, 'port') ?: DEMO_CATALOG_DEFAULT_PORT),
        'user' => optionValue($argv, 'user') ?: DEMO_CATALOG_DEFAULT_USER,
        'pass' => optionValue($argv, 'pass') ?: DEMO_CATALOG_DEFAULT_PASS,
        'source_db' => optionValue($argv, 'source-db') ?: DEMO_CATALOG_DEFAULT_SOURCE_DB,
        'target_db' => optionValue($argv, 'target-db') ?: DEMO_CATALOG_DEFAULT_TARGET_DB,
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
        throw new RuntimeException("Database non trovato: {$database}");
    }
}

function copyCatalogTables(mysqli $db, string $sourceDb, string $targetDb, array &$report): void
{
    $db->query('SET FOREIGN_KEY_CHECKS=0');

    try {
        foreach (DEMO_CATALOG_TABLES as $table) {
            if (!tableExists($db, $sourceDb, $table) || !tableExists($db, $targetDb, $table)) {
                $report['tables'][] = [
                    'table' => $table,
                    'status' => 'missing',
                    'rows_copied' => 0,
                ];
                continue;
            }

            $db->query(sprintf('TRUNCATE TABLE `%s`.`%s`', $db->real_escape_string($targetDb), $db->real_escape_string($table)));
            $db->query(sprintf(
                'INSERT INTO `%s`.`%s` SELECT * FROM `%s`.`%s`',
                $db->real_escape_string($targetDb),
                $db->real_escape_string($table),
                $db->real_escape_string($sourceDb),
                $db->real_escape_string($table)
            ));

            $rows = (int) ($db->query(sprintf(
                'SELECT COUNT(*) AS c FROM `%s`.`%s`',
                $db->real_escape_string($targetDb),
                $db->real_escape_string($table)
            ))->fetch_assoc()['c'] ?? 0);

            $report['tables'][] = [
                'table' => $table,
                'status' => 'copied',
                'rows_copied' => $rows,
            ];
        }
    } finally {
        $db->query('SET FOREIGN_KEY_CHECKS=1');
    }
}

function tableExists(mysqli $db, string $database, string $table): bool
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
    $path = DEMO_CATALOG_REPORT_DIR . DIRECTORY_SEPARATOR . 'demo_catalog_copy_' . date('Ymd_His') . '.json';
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $path;
}
