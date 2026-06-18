<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

main($_SERVER['argv'] ?? []);

function main(array $argv): void
{
    $options = parseOptions($argv);
    $env = loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');

    $host = (string)($env['database.default.hostname'] ?? '127.0.0.1');
    $port = (int)($env['database.default.port'] ?? 3306);
    $user = (string)($env['database.default.username'] ?? 'root');
    $pass = (string)($env['database.default.password'] ?? 'root');
    $sourceDb = (string)$options['source_db'];

    $db = new mysqli($host, $user, $pass, $sourceDb, $port);
    $db->set_charset('utf8mb4');

    $exporter = new GabrielliRecurringExtraMailPatchExporter($db, $sourceDb, $options);
    $result = $exporter->export();

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}

function parseOptions(array $argv): array
{
    return [
        'source_db' => (string)(optionValue($argv, 'source-db') ?? 'farmacia'),
        'doctor_id' => (int)(optionValue($argv, 'doctor-id') ?? 63),
        'date_from' => normalizeDate(optionValue($argv, 'date-from')) ?? '2026-01-01',
        'date_to' => normalizeDate(optionValue($argv, 'date-to')) ?? '2027-12-31',
        'out' => (string)(optionValue($argv, 'out') ?? (__DIR__ . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'gabrielli_recurring_extra_mail_patch.sql')),
    ];
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

function normalizeDate(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $value = trim($value);
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt instanceof DateTime || $dt->format('Y-m-d') !== $value) {
        throw new RuntimeException("Data non valida: {$value}. Usa il formato YYYY-MM-DD.");
    }

    return $value;
}

function loadEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim((string)$parts[0]);
        $value = trim((string)$parts[1]);
        if ($value !== '' && (
            ($value[0] === '"' && substr($value, -1) === '"')
            || ($value[0] === "'" && substr($value, -1) === "'")
        )) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '') {
            $vars[$key] = $value;
        }
    }

    return $vars;
}

final class GabrielliRecurringExtraMailPatchExporter
{
    private mysqli $db;
    private string $sourceDb;
    private int $doctorId;
    private string $dateFrom;
    private string $dateTo;
    private string $outPath;

    public function __construct(mysqli $db, string $sourceDb, array $options)
    {
        $this->db = $db;
        $this->sourceDb = $sourceDb;
        $this->doctorId = (int)$options['doctor_id'];
        $this->dateFrom = (string)$options['date_from'];
        $this->dateTo = (string)$options['date_to'];
        $this->outPath = (string)$options['out'];
    }

    public function export(): array
    {
        $rules = $this->loadRules();
        $rows = $this->expandRows($rules);
        $sql = $this->buildSql($rules, $rows);

        $outDir = dirname($this->outPath);
        if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            throw new RuntimeException('Impossibile creare la directory di output: ' . $outDir);
        }

        file_put_contents($this->outPath, $sql);

        return [
            'source_db' => $this->sourceDb,
            'doctor_id' => $this->doctorId,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'rules' => count($rules),
            'rows' => count($rows),
            'out' => $this->outPath,
        ];
    }

    private function loadRules(): array
    {
        $hasAmbTable = $this->tableExists($this->sourceDb, 'far22_amb');
        $ambJoin = $hasAmbTable
            ? "LEFT JOIN `{$this->sourceDb}`.far22_amb amb ON amb.id_amb = se.id_amb"
            : '';
        $ambSelect = $hasAmbTable
            ? "COALESCE(amb.nome, '') AS ambulatorio"
            : "'' AS ambulatorio";

        $stmt = $this->db->prepare("
            SELECT
                se.id_slot_extra,
                se.id_dot,
                se.id_giorno,
                COALESCE(se.ora_ini, '') AS ora_ini,
                COALESCE(se.ora_fin, '') AS ora_fin,
                COALESCE(se.id_amb, 0) AS id_amb_legacy,
                {$ambSelect},
                COALESCE(se.stanza, '') AS stanza,
                COALESCE(se.data_ini, '') AS data_ini,
                COALESCE(se.data_fin, '') AS data_fin
            FROM `{$this->sourceDb}`.far36_slot_extra se
            {$ambJoin}
            WHERE se.id_dot = ?
              AND COALESCE(se.id_giorno, 0) BETWEEN 1 AND 7
            ORDER BY se.id_giorno ASC, STR_TO_DATE(NULLIF(se.data_ini, ''), '%d/%m/%Y') ASC, se.ora_ini ASC
        ");
        $stmt->bind_param('i', $this->doctorId);
        $stmt->execute();
        $res = $stmt->get_result();

        $rules = [];
        while ($row = $res->fetch_assoc()) {
            $start = $this->parseLegacyDate((string)$row['data_ini']);
            $end = $this->parseLegacyDate((string)$row['data_fin']);
            $oraIni = $this->normalizeTime((string)$row['ora_ini']);
            $oraFin = $this->normalizeTime((string)$row['ora_fin']);

            if (
                $start === null
                || $end === null
                || $oraIni === ''
                || $oraFin === ''
                || $oraFin <= $oraIni
            ) {
                continue;
            }

            $rules[] = [
                'id_slot_extra' => (int)$row['id_slot_extra'],
                'id_dot' => (int)$row['id_dot'],
                'id_giorno' => (int)$row['id_giorno'],
                'ora_ini' => $oraIni,
                'ora_fin' => $oraFin,
                'id_amb_legacy' => (int)($row['id_amb_legacy'] ?? 0),
                'ambulatorio' => trim((string)($row['ambulatorio'] ?? '')),
                'stanza' => trim((string)($row['stanza'] ?? '')),
                'data_ini' => max($start, $this->dateFrom),
                'data_fin' => min($end, $this->dateTo),
            ];
        }

        $stmt->close();

        return array_values(array_filter($rules, static fn(array $rule): bool => $rule['data_ini'] <= $rule['data_fin']));
    }

    private function expandRows(array $rules): array
    {
        $rows = [];

        foreach ($rules as $rule) {
            $cursor = new DateTime((string)$rule['data_ini']);
            $end = new DateTime((string)$rule['data_fin']);
            $weekday = (int)$rule['id_giorno'];

            while ((int)$cursor->format('N') !== $weekday) {
                $cursor->modify('+1 day');
                if ($cursor > $end) {
                    continue 2;
                }
            }

            while ($cursor <= $end) {
                $key = implode('|', [
                    (int)$rule['id_dot'],
                    $cursor->format('Y-m-d'),
                    (string)$rule['ora_ini'],
                    (string)$rule['ora_fin'],
                ]);

                $rows[$key] = [
                    'id_dot' => (int)$rule['id_dot'],
                    'data_slot' => $cursor->format('Y-m-d'),
                    'ora_ini' => (string)$rule['ora_ini'],
                    'ora_fin' => (string)$rule['ora_fin'],
                    'id_amb_legacy' => (int)$rule['id_amb_legacy'],
                    'ambulatorio' => (string)$rule['ambulatorio'],
                    'stanza' => (string)$rule['stanza'],
                ];

                $cursor->modify('+7 days');
            }
        }

        ksort($rows);
        return array_values($rows);
    }

    private function buildSql(array $rules, array $rows): string
    {
        $values = [];
        foreach ($rows as $row) {
            $values[] = sprintf(
                "(%d, '%s', '%s', '%s', %s, %s, %s)",
                (int)$row['id_dot'],
                $this->sqlString((string)$row['data_slot']),
                $this->sqlString((string)$row['ora_ini']),
                $this->sqlString((string)$row['ora_fin']),
                $this->sqlNullableInt((int)$row['id_amb_legacy']),
                $this->sqlNullableString((string)$row['ambulatorio']),
                $this->sqlNullableString((string)$row['stanza'])
            );
        }

        $ruleComments = [];
        foreach ($rules as $rule) {
            $ruleComments[] = sprintf(
                "-- far36 id_slot_extra=%d giorno=%d fascia=%s-%s periodo=%s..%s",
                (int)$rule['id_slot_extra'],
                (int)$rule['id_giorno'],
                (string)$rule['ora_ini'],
                (string)$rule['ora_fin'],
                (string)$rule['data_ini'],
                (string)$rule['data_fin']
            );
        }

        $generatedAt = date('Y-m-d H:i:s');
        $header = [
            '-- Gabrielli recurring extra patch for target mail DB only',
            '-- Generated locally from legacy source `' . $this->sourceDb . '` on ' . $generatedAt,
            '-- Doctor id_dot = ' . $this->doctorId,
            '-- Run this after selecting the target `mail` database in phpMyAdmin/Adminer.',
            '-- The patch touches only current/future dates thanks to p.data_slot >= CURRENT_DATE().',
            '-- Overlapping CONFIG slots are preserved: only exact EXTRA duplicates are skipped.',
        ];

        $lines = array_merge($header, $ruleComments, [
            '',
            'START TRANSACTION;',
            '',
            'DROP TEMPORARY TABLE IF EXISTS tmp_gabrielli_recurring_extra_patch;',
            'CREATE TEMPORARY TABLE tmp_gabrielli_recurring_extra_patch (',
            '  id_dot INT NOT NULL,',
            '  data_slot DATE NOT NULL,',
            '  ora_inizio TIME NOT NULL,',
            '  ora_fine TIME NOT NULL,',
            '  id_amb_legacy INT NULL,',
            '  ambulatorio VARCHAR(150) NULL,',
            '  stanza VARCHAR(100) NULL,',
            '  PRIMARY KEY (id_dot, data_slot, ora_inizio, ora_fine)',
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
            '',
            'INSERT INTO tmp_gabrielli_recurring_extra_patch',
            '    (id_dot, data_slot, ora_inizio, ora_fine, id_amb_legacy, ambulatorio, stanza)',
            'VALUES',
            '    ' . implode(",\n    ", $values) . ';',
            '',
            'INSERT INTO dap11_agenda_slot (',
            '    id_dot, id_config, data_slot, ora_inizio, ora_fine, tipo_slot, stato,',
            '    titolo_libero, id_amb_legacy, ambulatorio, stanza, origine_slot, note_interne, created_at, updated_at',
            ')',
            'SELECT',
            '    p.id_dot,',
            '    (',
            '        SELECT c.id_config',
            '        FROM dap10_agenda_config c',
            '        WHERE c.id_dot = p.id_dot',
            '          AND c.attiva = 1',
            '          AND c.data_inizio <= p.data_slot',
            '          AND c.data_fine >= p.data_slot',
            '        ORDER BY c.id_config DESC',
            '        LIMIT 1',
            '    ) AS id_config,',
            '    p.data_slot,',
            '    TIMESTAMP(p.data_slot, p.ora_inizio) AS ora_inizio,',
            '    TIMESTAMP(p.data_slot, p.ora_fine) AS ora_fine,',
            '    \'AMBULATORIO\' AS tipo_slot,',
            '    CASE',
            '        WHEN EXISTS (',
            '            SELECT 1',
            '            FROM dap21_agenda_giorni_bloccati b',
            '            WHERE b.id_dot = p.id_dot',
            '              AND b.data_agenda = p.data_slot',
            '        ) THEN \'CHIUSO\'',
            '        ELSE \'LIBERO\'',
            '    END AS stato,',
            '    \'EXTRA\' AS titolo_libero,',
            '    NULLIF(p.id_amb_legacy, 0) AS id_amb_legacy,',
            '    NULLIF(p.ambulatorio, \'\') AS ambulatorio,',
            '    NULLIF(p.stanza, \'\') AS stanza,',
            '    \'EXTRA\' AS origine_slot,',
            '    \'Patch Gabrielli recurring extra esportata da locale\' AS note_interne,',
            '    NOW() AS created_at,',
            '    NOW() AS updated_at',
            'FROM tmp_gabrielli_recurring_extra_patch p',
            'LEFT JOIN dap11_agenda_slot existing',
            '    ON existing.id_dot = p.id_dot',
            '   AND existing.data_slot = p.data_slot',
            '   AND existing.ora_inizio = TIMESTAMP(p.data_slot, p.ora_inizio)',
            '   AND existing.ora_fine = TIMESTAMP(p.data_slot, p.ora_fine)',
            'WHERE p.data_slot >= CURRENT_DATE()',
            '  AND existing.id_slot IS NULL;',
            '',
            '-- Verifica rapida post-patch',
            'SELECT',
            '    COUNT(*) AS coexisting_overlapping_config',
            'FROM dap11_agenda_slot s',
            'INNER JOIN tmp_gabrielli_recurring_extra_patch p',
            '    ON p.id_dot = s.id_dot',
            '   AND p.data_slot = s.data_slot',
            '   AND s.origine_slot = \'CONFIG\'',
            '   AND s.ora_inizio < TIMESTAMP(p.data_slot, p.ora_fine)',
            '   AND s.ora_fine > TIMESTAMP(p.data_slot, p.ora_inizio)',
            'WHERE p.data_slot >= CURRENT_DATE()',
            '  AND NOT (TIME(s.ora_inizio) = p.ora_inizio AND TIME(s.ora_fine) = p.ora_fine);',
            '',
            'SELECT',
            '    s.data_slot, TIME(s.ora_inizio) AS ora_inizio, TIME(s.ora_fine) AS ora_fine, s.origine_slot, s.stato',
            'FROM dap11_agenda_slot s',
            'WHERE s.id_dot = ' . $this->doctorId,
            '  AND s.data_slot >= CURRENT_DATE()',
            '  AND (',
            '      (TIME(s.ora_inizio) = \'13:00:00\' AND TIME(s.ora_fine) = \'13:30:00\')',
            '      OR (TIME(s.ora_inizio) = \'19:00:00\' AND TIME(s.ora_fine) = \'19:30:00\')',
            '      OR (TIME(s.ora_inizio) = \'12:30:00\' AND TIME(s.ora_fine) = \'13:15:00\')',
            '      OR (TIME(s.ora_inizio) = \'18:30:00\' AND TIME(s.ora_fine) = \'19:15:00\')',
            '  )',
            'ORDER BY s.data_slot ASC, s.ora_inizio ASC, s.ora_fine ASC, s.origine_slot ASC',
            'LIMIT 200;',
            '',
            'COMMIT;',
            '',
        ]);

        return implode(PHP_EOL, $lines);
    }

    private function parseLegacyDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['d/m/Y', 'j/n/Y'] as $format) {
            $dt = DateTime::createFromFormat($format, $value);
            if ($dt instanceof DateTime && $dt->format($format === 'd/m/Y' ? 'd/m/Y' : 'j/n/Y') === $value) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    private function normalizeTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        foreach (['H:i:s', 'H:i'] as $format) {
            $dt = DateTime::createFromFormat($format, $value);
            if ($dt instanceof DateTime && $dt->format($format) === $value) {
                return $dt->format('H:i:s');
            }
        }

        return '';
    }

    private function sqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function sqlNullableString(string $value): string
    {
        return $value === '' ? 'NULL' : "'" . $this->sqlString($value) . "'";
    }

    private function sqlNullableInt(int $value): string
    {
        return $value > 0 ? (string)$value : 'NULL';
    }

    private function tableExists(string $schema, string $table): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS c
            FROM information_schema.tables
            WHERE table_schema = ?
              AND table_name = ?
        ");
        $stmt->bind_param('ss', $schema, $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['c'] ?? 0) > 0;
    }
}
