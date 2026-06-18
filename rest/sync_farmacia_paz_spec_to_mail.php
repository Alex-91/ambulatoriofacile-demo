<?php
declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const SPEC_DEFAULT_SOURCE_DB = 'farmacia';
const SPEC_DEFAULT_TARGET_DB = 'mail';
const SPEC_DEFAULT_REPORT_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'paz_spec_sync';

main($_SERVER['argv'] ?? []);

function main(array $argv): void
{
    $apply = in_array('--apply', $argv, true);
    $sourceDb = optionValue($argv, 'source-db') ?: SPEC_DEFAULT_SOURCE_DB;
    $targetDb = optionValue($argv, 'target-db') ?: SPEC_DEFAULT_TARGET_DB;
    $reportDir = optionValue($argv, 'report-dir') ?: SPEC_DEFAULT_REPORT_DIR;
    $hostOverride = optionValue($argv, 'host');
    $userOverride = optionValue($argv, 'user');
    $passOverride = optionValue($argv, 'pass');
    $portOverride = optionValue($argv, 'port');

    if (!is_dir($reportDir)) {
        mkdir($reportDir, 0777, true);
    }

    $stamp = date('Ymd_His');
    $logPath = $reportDir . DIRECTORY_SEPARATOR . 'paz_spec_sync_' . $stamp . '.log';
    $reportPath = $reportDir . DIRECTORY_SEPARATOR . 'paz_spec_sync_' . $stamp . '.json';
    $logger = new PazSpecSyncLogger($logPath);

    $env = loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');
    $host = (string)($hostOverride ?: ($env['database.default.hostname'] ?? 'localhost'));
    $user = (string)($userOverride ?: ($env['database.default.username'] ?? 'root'));
    $pass = (string)($passOverride ?: ($env['database.default.password'] ?? 'root'));
    $port = (int)($portOverride ?: ($env['database.default.port'] ?? 3306));

    $db = new mysqli($host, $user, $pass, $targetDb, $port);
    $db->set_charset('latin1');
    initializeEncryptionSession($db, $env);

    $report = [
        'started_at' => date('c'),
        'mode' => $apply ? 'apply' : 'dry-run',
        'source_db' => $sourceDb,
        'target_db' => $targetDb,
        'log_path' => $logPath,
        'report_path' => $reportPath,
        'summary' => [],
        'examples' => [],
        'conflicts' => [],
    ];

    try {
        $sourceRows = loadSourceSpecialRows($db, $sourceDb);
        $targetRows = loadTargetRows($db, $targetDb);
        $indexes = buildTargetIndexes($targetRows);

        $assignments = [];
        $examples = [];
        $conflicts = [];
        $matchedSources = 0;
        $unmatchedSources = 0;

        foreach ($sourceRows as $row) {
            $matches = findTargetMatches($row, $indexes, $targetRows);
            if ($matches === []) {
                $unmatchedSources++;
                if (count($examples) < 30) {
                    $examples[] = [
                        'status' => 'unmatched_source',
                        'source' => compactSource($row),
                    ];
                }
                continue;
            }

            $matchedSources++;
            foreach ($matches as $targetId) {
                $spec = (string)$row['paz_spec'];
                if (isset($assignments[$targetId]) && $assignments[$targetId] !== $spec) {
                    $conflicts[] = [
                        'target_id_paziente' => $targetId,
                        'existing_spec' => $assignments[$targetId],
                        'incoming_spec' => $spec,
                        'source' => compactSource($row),
                        'target' => compactTarget($targetRows[$targetId] ?? []),
                    ];
                    continue;
                }

                $assignments[$targetId] = $spec;
            }

            if (count($examples) < 30) {
                $examples[] = [
                    'status' => 'matched_source',
                    'source' => compactSource($row),
                    'target_ids' => $matches,
                ];
            }
        }

        $targetWithSpecBefore = 0;
        foreach ($targetRows as $row) {
            if (trim((string)($row['paz_spec'] ?? '')) !== '') {
                $targetWithSpecBefore++;
            }
        }

        $targetToClear = 0;
        $targetToUpdate = 0;
        foreach ($targetRows as $targetId => $row) {
            $current = trim((string)($row['paz_spec'] ?? ''));
            $desired = trim((string)($assignments[$targetId] ?? ''));
            if ($current !== '' && $desired === '') {
                $targetToClear++;
            }
            if ($current !== $desired) {
                $targetToUpdate++;
            }
        }

        if ($apply) {
            $db->begin_transaction();
            try {
                $db->query("
                    UPDATE `" . $targetDb . "`.dap02_clients
                    SET paz_spec = NULL
                    WHERE paz_spec IS NOT NULL
                      AND TRIM(COALESCE(CAST(AES_DECRYPT(UNHEX(paz_spec), @key_str, vector_id) AS CHAR), '')) <> ''
                ");

                foreach ($assignments as $targetId => $spec) {
                    $escapedSpec = $db->real_escape_string($spec);
                    $db->query("
                        UPDATE `" . $targetDb . "`.dap02_clients
                        SET paz_spec = HEX(AES_ENCRYPT('{$escapedSpec}', @key_str, vector_id))
                        WHERE id_client = " . (int)$targetId . "
                        LIMIT 1
                    ");
                }
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }
        }

        $targetWithSpecAfter = $targetWithSpecBefore;
        if ($apply) {
            $targetWithSpecAfter = (int)scalar(
                $db,
                "SELECT COUNT(*) AS c
                 FROM `" . $targetDb . "`.dap02_clients
                 WHERE paz_spec IS NOT NULL
                   AND TRIM(COALESCE(CAST(AES_DECRYPT(UNHEX(paz_spec), @key_str, vector_id) AS CHAR), '')) <> ''"
            );
        }

        $report['summary'] = [
            'source_special_rows' => count($sourceRows),
            'matched_source_rows' => $matchedSources,
            'unmatched_source_rows' => $unmatchedSources,
            'target_rows_total' => count($targetRows),
            'target_rows_with_spec_before' => $targetWithSpecBefore,
            'target_rows_with_spec_after' => $targetWithSpecAfter,
            'target_rows_to_clear' => $targetToClear,
            'target_rows_to_update' => $targetToUpdate,
            'target_rows_assigned' => count($assignments),
            'conflicting_assignment_candidates' => count($conflicts),
        ];
        $report['examples'] = $examples;
        $report['conflicts'] = array_slice($conflicts, 0, 50);
        $report['finished_at'] = date('c');
        $report['status'] = 'ok';

        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $logger->info('Sync paz_spec completata su dap02_clients', $report['summary'] + ['report_path' => $reportPath]);
        $db->close();
    } catch (\Throwable $e) {
        $report['finished_at'] = date('c');
        $report['status'] = 'error';
        $report['error'] = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $logger->error('Sync paz_spec fallita su dap02_clients', $report['error'] + ['report_path' => $reportPath]);
        $db->close();
        exit(1);
    }
}

function loadSourceSpecialRows(mysqli $db, string $sourceDb): array
{
    $sql = "
        SELECT
            id_paziente,
            COALESCE(id_dot, 0) AS id_dot,
            COALESCE(cognome, '') AS cognome,
            COALESCE(nome, '') AS nome,
            COALESCE(cod_fis, '') AS cod_fis,
            COALESCE(telefono, '') AS telefono,
            COALESCE(cellulare, '') AS cellulare,
            TRIM(COALESCE(paz_spec, '')) AS paz_spec
        FROM `" . $sourceDb . "`.far05_pazienti
        WHERE paz_spec IS NOT NULL
          AND TRIM(paz_spec) <> ''
        ORDER BY id_paziente ASC
    ";

    $res = $db->query($sql);
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $row['id_paziente'] = (int)$row['id_paziente'];
        $row['id_dot'] = (int)($row['id_dot'] ?? 0);
        $rows[] = $row;
    }
    $res->close();

    return $rows;
}

function loadTargetRows(mysqli $db, string $targetDb): array
{
    $sql = "
        SELECT
            c.id_client,
            COALESCE(c.legacy_id_paziente, 0) AS legacy_id_paziente,
            COALESCE(p.legacy_id_dot, 0) AS id_dot,
            COALESCE(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR), '') AS cognome,
            COALESCE(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR), '') AS nome,
            COALESCE(CAST(AES_DECRYPT(UNHEX(c.codice_fiscale), @key_str, c.vector_id) AS CHAR), '') AS cod_fis,
            COALESCE(CAST(AES_DECRYPT(UNHEX(c.telefono), @key_str, c.vector_id) AS CHAR), '') AS telefono,
            COALESCE(CAST(AES_DECRYPT(UNHEX(c.cellulare), @key_str, c.vector_id) AS CHAR), '') AS cellulare,
            COALESCE(CAST(AES_DECRYPT(UNHEX(c.paz_spec), @key_str, c.vector_id) AS CHAR), '') AS paz_spec
        FROM `" . $targetDb . "`.dap02_clients c
        LEFT JOIN `" . $targetDb . "`.dap03_personale p
          ON p.id_personale = c.id_personale
    ";

    $res = $db->query($sql);
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $row['id_client'] = (int)$row['id_client'];
        $row['legacy_id_paziente'] = (int)($row['legacy_id_paziente'] ?? 0);
        $row['id_dot'] = (int)($row['id_dot'] ?? 0);
        $rows[(int)$row['id_client']] = $row;
    }
    $res->close();

    return $rows;
}

function buildTargetIndexes(array $targetRows): array
{
    $indexes = [
        'by_legacy' => [],
        'by_cf' => [],
        'by_doctor_name' => [],
        'by_name' => [],
    ];

    foreach ($targetRows as $targetId => $row) {
        $legacyId = (int)($row['legacy_id_paziente'] ?? 0);
        if ($legacyId > 0) {
            $indexes['by_legacy'][$legacyId][] = $targetId;
        }

        $cf = normalizeUsableFiscalCode((string)($row['cod_fis'] ?? ''));
        if ($cf !== '') {
            $indexes['by_cf'][$cf][] = $targetId;
        }

        $nameKey = normalizeNamePair((string)($row['cognome'] ?? ''), (string)($row['nome'] ?? ''));
        if ($nameKey !== '') {
            $doctorNameKey = buildDoctorNameKey((int)($row['id_dot'] ?? 0), $nameKey);
            $indexes['by_doctor_name'][$doctorNameKey][] = $targetId;
            $indexes['by_name'][$nameKey][] = $targetId;
        }
    }

    return $indexes;
}

function findTargetMatches(array $sourceRow, array $indexes, array $targetRows): array
{
    $matches = [];
    $sourceId = (int)($sourceRow['id_paziente'] ?? 0);
    if ($sourceId > 0 && isset($indexes['by_legacy'][$sourceId])) {
        $matches = array_merge($matches, $indexes['by_legacy'][$sourceId]);
    }

    $cf = normalizeUsableFiscalCode((string)($sourceRow['cod_fis'] ?? ''));
    if ($cf !== '' && isset($indexes['by_cf'][$cf])) {
        $matches = array_merge($matches, $indexes['by_cf'][$cf]);
    }

    $nameKey = normalizeNamePair((string)($sourceRow['cognome'] ?? ''), (string)($sourceRow['nome'] ?? ''));
    if ($nameKey === '') {
        return uniqueSorted($matches);
    }

    $doctorId = (int)($sourceRow['id_dot'] ?? 0);
    if ($doctorId > 0) {
        $doctorNameKey = buildDoctorNameKey($doctorId, $nameKey);
        if (isset($indexes['by_doctor_name'][$doctorNameKey])) {
            $matches = array_merge($matches, $indexes['by_doctor_name'][$doctorNameKey]);
        }
    }

    if (isset($indexes['by_name'][$nameKey])) {
        $matches = array_merge($matches, $indexes['by_name'][$nameKey]);
    }

    return uniqueSorted($matches);
}

function compactSource(array $row): array
{
    return [
        'id_paziente' => (int)($row['id_paziente'] ?? 0),
        'id_dot' => (int)($row['id_dot'] ?? 0),
        'cognome' => (string)($row['cognome'] ?? ''),
        'nome' => (string)($row['nome'] ?? ''),
        'cod_fis' => (string)($row['cod_fis'] ?? ''),
        'paz_spec' => (string)($row['paz_spec'] ?? ''),
    ];
}

function compactTarget(array $row): array
{
    return [
        'id_client' => (int)($row['id_client'] ?? 0),
        'legacy_id_paziente' => (int)($row['legacy_id_paziente'] ?? 0),
        'id_dot' => (int)($row['id_dot'] ?? 0),
        'cognome' => (string)($row['cognome'] ?? ''),
        'nome' => (string)($row['nome'] ?? ''),
        'cod_fis' => (string)($row['cod_fis'] ?? ''),
        'paz_spec' => (string)($row['paz_spec'] ?? ''),
    ];
}

function uniqueSorted(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);
    return $ids;
}

function buildDoctorNameKey(int $doctorId, string $nameKey): string
{
    return $doctorId . '|' . $nameKey;
}

function normalizeUsableFiscalCode(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    return strlen($value) === 16 ? $value : '';
}

function normalizeText(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function normalizeNamePair(string $cognome, string $nome): string
{
    $cognome = normalizeText($cognome);
    $nome = normalizeText($nome);
    return trim($cognome . '|' . $nome, '|');
}

function scalar(mysqli $db, string $sql): int
{
    $res = $db->query($sql);
    $row = $res->fetch_assoc();
    $res->close();
    return (int)($row['c'] ?? 0);
}

function initializeEncryptionSession(mysqli $db, array $env): void
{
    $key = (string)($env['DB_ENCRYPTION_KEY'] ?? '');
    $mode = (string)($env['DB_ENCRYPTION_MODE'] ?? 'aes-256-cbc');
    $db->query("SET @key_str = SHA2('" . $db->real_escape_string($key) . "', 512)");
    $db->query("SET NAMES latin1");
    $db->query("SET block_encryption_mode = '" . $db->real_escape_string($mode) . "'");
    $db->query("SET @init_vector = RANDOM_BYTES(16)");
}

function optionValue(array $argv, string $name): ?string
{
    foreach ($argv as $arg) {
        if (!is_string($arg)) {
            continue;
        }

        if (preg_match('/^--' . preg_quote($name, '/') . '=(.*)$/i', $arg, $m)) {
            return trim((string)$m[1]);
        }
    }

    return null;
}

function loadEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $vars = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $vars[$key] = $value;
    }

    return $vars;
}

final class PazSpecSyncLogger
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context = []): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] {$level} {$message}";
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND);
        fwrite(STDOUT, $line . PHP_EOL);
    }
}
