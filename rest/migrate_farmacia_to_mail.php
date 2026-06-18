<?php

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const DEFAULT_TABLES = [
    'far01_ope',
    'far03_dot',
    'far05_pazienti',
    'far06_appuntamenti',
    'far08_prenotazioni',
    'far15_fas_ora_dot',
    'far20_stampa',
    'far41_spec',
    'far48_gio_ros',
    'far49_dot_spec',
];

$options = getopt('', [
    'source-host:',
    'source-port::',
    'source-db:',
    'source-user:',
    'source-pass:',
    'target-host::',
    'target-port::',
    'target-db::',
    'target-user::',
    'target-pass::',
    'tables::',
    'batch-size::',
    'backup-suffix::',
    'no-backup',
    'dry-run',
]);

$sourceConfig = [
    'host'    => (string)($options['source-host'] ?? ''),
    'port'    => (int)($options['source-port'] ?? 3306),
    'db'      => (string)($options['source-db'] ?? ''),
    'user'    => (string)($options['source-user'] ?? ''),
    'pass'    => (string)($options['source-pass'] ?? ''),
    'charset' => 'latin1',
];

$targetConfig = [
    'host'    => (string)($options['target-host'] ?? 'localhost'),
    'port'    => (int)($options['target-port'] ?? 3306),
    'db'      => (string)($options['target-db'] ?? 'mail'),
    'user'    => (string)($options['target-user'] ?? 'root'),
    'pass'    => (string)($options['target-pass'] ?? 'root'),
    'charset' => 'latin1',
];

$tables = DEFAULT_TABLES;
if (!empty($options['tables'])) {
    $tables = array_values(array_filter(array_map('trim', explode(',', (string)$options['tables']))));
}

$batchSize = max(1, (int)($options['batch-size'] ?? 500));
$backupSuffix = preg_replace('/[^A-Za-z0-9_]/', '_', (string)($options['backup-suffix'] ?? date('Ymd_His')));
$useBackup = !isset($options['no-backup']);
$dryRun = isset($options['dry-run']);

validateConfig($sourceConfig, 'source');
validateConfig($targetConfig, 'target');

$log = [
    'started_at'    => date('c'),
    'source'        => redactSecrets($sourceConfig),
    'target'        => redactSecrets($targetConfig),
    'tables'        => $tables,
    'batch_size'    => $batchSize,
    'use_backup'    => $useBackup,
    'dry_run'       => $dryRun,
    'backup_suffix' => $backupSuffix,
    'operations'    => [],
];

$source = connectDb($sourceConfig);
$target = connectDb($targetConfig);

try {
    $target->query('SET foreign_key_checks = 0');

    foreach ($tables as $table) {
        $tableLog = [
            'table' => $table,
            'steps' => [],
        ];

        $sourceExists = tableExists($source, $table);
        $tableLog['steps'][] = [
            'action' => 'check_source_table',
            'exists' => $sourceExists,
        ];

        if (!$sourceExists) {
            throw new RuntimeException("Source table not found: {$table}");
        }

        $createSql = getCreateTableSql($source, $table);
        $sourceCount = getRowCount($source, $table);

        $targetExists = tableExists($target, $table);
        $tableLog['steps'][] = [
            'action' => 'inspect_target_table',
            'exists' => $targetExists,
            'source_row_count' => $sourceCount,
        ];

        if ($targetExists && $useBackup) {
            $backupTable = $table . '__backup_' . $backupSuffix;
            $tableLog['steps'][] = [
                'action' => 'backup_target_table',
                'backup_table' => $backupTable,
            ];

            if (!$dryRun) {
                backupTable($target, $table, $backupTable);
            }
        }

        $tableLog['steps'][] = [
            'action' => 'recreate_target_table',
        ];

        if (!$dryRun) {
            recreateTargetTable($target, $table, $createSql);
        }

        $tableLog['steps'][] = [
            'action' => 'copy_rows',
            'expected_rows' => $sourceCount,
        ];

        $insertedRows = $dryRun ? $sourceCount : copyTableData($source, $target, $table, $batchSize);
        $targetCount = $dryRun ? $sourceCount : getRowCount($target, $table);

        $tableLog['steps'][] = [
            'action' => 'verify_target_row_count',
            'inserted_rows' => $insertedRows,
            'target_row_count' => $targetCount,
        ];

        if ($targetCount !== $sourceCount) {
            throw new RuntimeException("Row count mismatch for {$table}: source={$sourceCount}, target={$targetCount}");
        }

        $log['operations'][] = $tableLog;
        fwrite(STDOUT, "[OK] {$table}: {$targetCount} righe migrate" . PHP_EOL);
    }

    $target->query('SET foreign_key_checks = 1');
    $log['finished_at'] = date('c');
    $log['status'] = 'ok';
} catch (Throwable $e) {
    $log['finished_at'] = date('c');
    $log['status'] = 'error';
    $log['error'] = $e->getMessage();
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    throw $e;
} finally {
    try {
        $target->query('SET foreign_key_checks = 1');
    } catch (Throwable $ignored) {
    }

    writeLog($log);
    $source->close();
    $target->close();
}

function validateConfig(array $config, string $label): void
{
    foreach (['host', 'db', 'user'] as $key) {
        if ($config[$key] === '') {
            throw new InvalidArgumentException("Missing {$label} config: {$key}");
        }
    }
}

function redactSecrets(array $config): array
{
    $copy = $config;
    $copy['pass'] = $copy['pass'] === '' ? '' : '***';

    return $copy;
}

function connectDb(array $config): mysqli
{
    $db = new mysqli(
        $config['host'],
        $config['user'],
        $config['pass'],
        $config['db'],
        $config['port']
    );
    $db->set_charset($config['charset']);

    return $db;
}

function tableExists(mysqli $db, string $table): bool
{
    $sql = sprintf(
        "SHOW TABLES LIKE '%s'",
        $db->real_escape_string($table)
    );
    $res = $db->query($sql);

    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function getCreateTableSql(mysqli $db, string $table): string
{
    $sql = sprintf('SHOW CREATE TABLE `%s`', $table);
    $row = $db->query($sql)->fetch_assoc();
    if (!$row) {
        throw new RuntimeException("Unable to load CREATE TABLE for {$table}");
    }

    return (string)($row['Create Table'] ?? '');
}

function getRowCount(mysqli $db, string $table): int
{
    $sql = sprintf('SELECT COUNT(*) AS c FROM `%s`', $table);
    $row = $db->query($sql)->fetch_assoc();

    return (int)($row['c'] ?? 0);
}

function backupTable(mysqli $db, string $table, string $backupTable): void
{
    $db->query(sprintf('DROP TABLE IF EXISTS `%s`', $backupTable));
    $db->query(sprintf('CREATE TABLE `%s` LIKE `%s`', $backupTable, $table));
    $db->query(sprintf('INSERT INTO `%s` SELECT * FROM `%s`', $backupTable, $table));
}

function recreateTargetTable(mysqli $db, string $table, string $createSql): void
{
    $db->query(sprintf('DROP TABLE IF EXISTS `%s`', $table));
    $db->query($createSql);
}

function copyTableData(mysqli $source, mysqli $target, string $table, int $batchSize): int
{
    $result = $source->query(sprintf('SELECT * FROM `%s`', $table), MYSQLI_USE_RESULT);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException("Unable to read rows from {$table}");
    }

    $columns = array_map(
        static fn(object $field): string => (string)$field->name,
        $result->fetch_fields()
    );

    if ($columns === []) {
        $result->close();
        return 0;
    }

    $escapedColumns = '`' . implode('`,`', $columns) . '`';
    $prefix = sprintf('INSERT INTO `%s` (%s) VALUES ', $table, $escapedColumns);

    $rows = [];
    $inserted = 0;

    while ($row = $result->fetch_assoc()) {
        $values = [];
        foreach ($columns as $column) {
            $value = $row[$column];
            if ($value === null) {
                $values[] = 'NULL';
                continue;
            }

            $values[] = "'" . $target->real_escape_string((string)$value) . "'";
        }

        $rows[] = '(' . implode(',', $values) . ')';

        if (count($rows) >= $batchSize) {
            $target->query($prefix . implode(',', $rows));
            $inserted += count($rows);
            $rows = [];
        }
    }

    if ($rows !== []) {
        $target->query($prefix . implode(',', $rows));
        $inserted += count($rows);
    }

    $result->close();

    return $inserted;
}

function writeLog(array $log): void
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'db-migrations';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $filename = $dir . DIRECTORY_SEPARATOR . 'farmacia_to_mail_' . date('Ymd_His') . '.json';
    file_put_contents(
        $filename,
        json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    fwrite(STDOUT, 'Log migrazione: ' . $filename . PHP_EOL);
}
