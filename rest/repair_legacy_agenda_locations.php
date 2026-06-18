<?php

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function loadEnvConfig(string $path): array
{
    $config = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
        $config[$key] = $value;
    }

    return $config;
}

function connectDb(array $config, ?string $database = null): mysqli
{
    $db = new mysqli(
        $config['database.default.hostname'] ?? '127.0.0.1',
        $config['database.default.username'] ?? 'root',
        $config['database.default.password'] ?? '',
        $database ?? ($config['database.default.database'] ?? '')
    );
    $db->set_charset('utf8mb4');

    return $db;
}

function normalizeDateValue(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '1900-01-01';
    }

    $dt = DateTime::createFromFormat('d/m/Y', $value);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }

    return '1900-01-01';
}

function normalizeTimeValue(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    foreach (['H:i:s', 'H:i'] as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime && $dt->format($format) === $value) {
            return $dt->format('H:i:s');
        }
    }

    return $value;
}

function minutesBetween(string $start, string $end): int
{
    $startTs = strtotime('1970-01-01 ' . $start);
    $endTs = strtotime('1970-01-01 ' . $end);

    if ($startTs === false || $endTs === false) {
        return 0;
    }

    return max(0, (int)(($endTs - $startTs) / 60));
}

function loadLegacyRules(mysqli $db, string $sourceDb): array
{
    $rules = [];

    $sql = "
        SELECT
            f15.id_dot,
            f15.id_giorno AS giorno_settimana,
            COALESCE(f15.data_ini_val, '') AS data_ini_val,
            f15.ora_ini,
            f15.ora_fin,
            COALESCE(f15.id_amb, 0) AS id_amb_legacy,
            COALESCE(amb.nome, '') AS ambulatorio,
            COALESCE(f15.stanza, '') AS stanza
        FROM `{$sourceDb}`.far15_fas_ora_dot f15
        LEFT JOIN `{$sourceDb}`.far22_amb amb
            ON amb.id_amb = f15.id_amb
        WHERE COALESCE(f15.id_amb, 0) <> 0
           OR COALESCE(amb.nome, '') <> ''
           OR COALESCE(f15.stanza, '') <> ''
        ORDER BY f15.id_dot ASC, f15.id_giorno ASC,
                 STR_TO_DATE(NULLIF(f15.data_ini_val, ''), '%d/%m/%Y') DESC,
                 f15.ora_ini ASC, f15.ora_fin ASC
    ";

    $res = $db->query($sql);
    while ($row = $res->fetch_assoc()) {
        $idDot = (int)($row['id_dot'] ?? 0);
        $day = (int)($row['giorno_settimana'] ?? 0);
        if ($idDot <= 0 || $day < 1 || $day > 7) {
            continue;
        }

        $effectiveFrom = normalizeDateValue((string)($row['data_ini_val'] ?? ''));
        $start = normalizeTimeValue((string)($row['ora_ini'] ?? ''));
        $end = normalizeTimeValue((string)($row['ora_fin'] ?? ''));
        if ($start === '' || $end === '' || $end <= $start) {
            continue;
        }

        $rules[$idDot][$day][$effectiveFrom][] = [
            'effective_from' => $effectiveFrom,
            'ora_inizio' => $start,
            'ora_fine' => $end,
            'id_amb_legacy' => (int)($row['id_amb_legacy'] ?? 0),
            'ambulatorio' => trim((string)($row['ambulatorio'] ?? '')),
            'stanza' => trim((string)($row['stanza'] ?? '')),
        ];
    }
    $res->close();

    $merged = [];
    foreach ($rules as $idDot => $dayRules) {
        foreach ($dayRules as $day => $effectiveRules) {
            $flat = [];
            foreach ($effectiveRules as $effectiveFrom => $rows) {
                usort($rows, static function (array $left, array $right): int {
                    $cmp = strcmp((string)$left['ora_inizio'], (string)$right['ora_inizio']);
                    if ($cmp !== 0) {
                        return $cmp;
                    }

                    return strcmp((string)$left['ora_fine'], (string)$right['ora_fine']);
                });

                $current = null;
                foreach ($rows as $row) {
                    if (
                        $current !== null
                        && (int)$current['id_amb_legacy'] === (int)$row['id_amb_legacy']
                        && (string)$current['ambulatorio'] === (string)$row['ambulatorio']
                        && (string)$current['stanza'] === (string)$row['stanza']
                        && (string)$row['ora_inizio'] <= (string)$current['ora_fine']
                    ) {
                        if ((string)$row['ora_fine'] > (string)$current['ora_fine']) {
                            $current['ora_fine'] = (string)$row['ora_fine'];
                        }
                        continue;
                    }

                    if ($current !== null) {
                        $flat[] = $current;
                    }

                    $current = $row;
                }

                if ($current !== null) {
                    $flat[] = $current;
                }
            }

            usort($flat, static function (array $left, array $right): int {
                $cmp = strcmp((string)$right['effective_from'], (string)$left['effective_from']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                $leftDuration = minutesBetween((string)$left['ora_inizio'], (string)$left['ora_fine']);
                $rightDuration = minutesBetween((string)$right['ora_inizio'], (string)$right['ora_fine']);
                if ($leftDuration !== $rightDuration) {
                    return $leftDuration <=> $rightDuration;
                }

                return strcmp((string)$left['ora_inizio'], (string)$right['ora_inizio']);
            });

            $merged[$idDot][$day] = $flat;
        }
    }

    return $merged;
}

function matchRule(array $rulesByDay, int $idDot, int $day, string $date, string $start, string $end): ?array
{
    $rules = $rulesByDay[$idDot][$day] ?? [];
    foreach ($rules as $rule) {
        if ((string)$rule['effective_from'] > $date) {
            continue;
        }

        if ($start >= (string)$rule['ora_inizio'] && $end <= (string)$rule['ora_fine']) {
            return $rule;
        }
    }

    return null;
}

function syncAmbulatori(mysqli $db, string $sourceDb, string $targetDb, bool $apply): int
{
    $count = 0;
    $res = $db->query("SELECT COUNT(*) AS c FROM `{$sourceDb}`.far22_amb");
    $row = $res->fetch_assoc();
    $count = (int)($row['c'] ?? 0);
    $res->close();

    if (!$apply) {
        return $count;
    }

    $sql = "
        INSERT INTO `{$targetDb}`.dap42_ambulatori
            (id_amb_legacy, nome, logo, indirizzo, citta, telefono, created_at, updated_at)
        SELECT
            id_amb,
            COALESCE(nome, ''),
            logo,
            COALESCE(indirizzo, ''),
            COALESCE(citta, ''),
            COALESCE(telefono, ''),
            NOW(),
            NOW()
        FROM `{$sourceDb}`.far22_amb
        ON DUPLICATE KEY UPDATE
            nome = VALUES(nome),
            logo = VALUES(logo),
            indirizzo = VALUES(indirizzo),
            citta = VALUES(citta),
            telefono = VALUES(telefono),
            updated_at = NOW()
    ";
    $db->query($sql);

    return $count;
}

function backfillConfigFasce(mysqli $db, string $targetDb, array $rulesByDay, bool $apply): array
{
    $summary = [
        'scanned' => 0,
        'matched' => 0,
        'updated' => 0,
    ];

    $sql = "
        SELECT
            f.id_config_fascia,
            c.id_dot,
            c.data_inizio,
            g.giorno_settimana,
            TIME_FORMAT(f.ora_inizio, '%H:%i:%s') AS ora_inizio,
            TIME_FORMAT(f.ora_fine, '%H:%i:%s') AS ora_fine,
            COALESCE(f.id_amb_legacy, 0) AS id_amb_legacy,
            COALESCE(f.ambulatorio, '') AS ambulatorio,
            COALESCE(f.stanza, '') AS stanza
        FROM `{$targetDb}`.dap10_agenda_config_fasce f
        INNER JOIN `{$targetDb}`.dap10_agenda_config_giorni g
            ON g.id_config_giorno = f.id_config_giorno
        INNER JOIN `{$targetDb}`.dap10_agenda_config c
            ON c.id_config = g.id_config
        WHERE c.attiva = 1
        ORDER BY c.id_dot ASC, g.giorno_settimana ASC, f.ordine ASC, f.ora_inizio ASC
    ";

    $stmt = null;
    if ($apply) {
        $stmt = $db->prepare("
            UPDATE `{$targetDb}`.dap10_agenda_config_fasce
            SET id_amb_legacy = ?, ambulatorio = ?, stanza = ?
            WHERE id_config_fascia = ?
        ");
    }

    $res = $db->query($sql);
    while ($row = $res->fetch_assoc()) {
        $summary['scanned']++;
        $idDot = (int)($row['id_dot'] ?? 0);
        $day = (int)($row['giorno_settimana'] ?? 0);
        $date = (string)($row['data_inizio'] ?? '1900-01-01');
        $start = normalizeTimeValue((string)($row['ora_inizio'] ?? ''));
        $end = normalizeTimeValue((string)($row['ora_fine'] ?? ''));

        $rule = matchRule($rulesByDay, $idDot, $day, $date, $start, $end);
        if ($rule === null) {
            continue;
        }

        $summary['matched']++;

        $newIdAmb = (int)($rule['id_amb_legacy'] ?? 0);
        $newAmb = trim((string)($rule['ambulatorio'] ?? ''));
        $newStanza = trim((string)($rule['stanza'] ?? ''));

        $currentIdAmb = (int)($row['id_amb_legacy'] ?? 0);
        $currentAmb = trim((string)($row['ambulatorio'] ?? ''));
        $currentStanza = trim((string)($row['stanza'] ?? ''));

        if ($currentIdAmb === $newIdAmb && $currentAmb === $newAmb && $currentStanza === $newStanza) {
            continue;
        }

        if ($apply && $stmt !== null) {
            $idConfigFascia = (int)($row['id_config_fascia'] ?? 0);
            $stmt->bind_param('issi', $newIdAmb, $newAmb, $newStanza, $idConfigFascia);
            $stmt->execute();
        }

        $summary['updated']++;
    }
    $res->close();

    if ($stmt !== null) {
        $stmt->close();
    }

    return $summary;
}

function flushSlotBatch(mysqli $db, string $targetDb, array &$batch, array &$summary, bool $apply): void
{
    if ($batch === []) {
        return;
    }

    if (!$apply) {
        $summary['updated'] += count($batch);
        $batch = [];
        return;
    }

    $values = [];
    foreach ($batch as $row) {
        $values[] = sprintf(
            "(%d,%d,'%s','%s')",
            (int)$row['id_slot'],
            (int)$row['id_amb_legacy'],
            $db->real_escape_string((string)$row['ambulatorio']),
            $db->real_escape_string((string)$row['stanza'])
        );
    }

    $db->query("
        INSERT INTO tmp_slot_location_updates (id_slot, id_amb_legacy, ambulatorio, stanza)
        VALUES " . implode(',', $values) . "
        ON DUPLICATE KEY UPDATE
            id_amb_legacy = VALUES(id_amb_legacy),
            ambulatorio = VALUES(ambulatorio),
            stanza = VALUES(stanza)
    ");

    $db->query("
        UPDATE `{$targetDb}`.dap11_agenda_slot s
        INNER JOIN tmp_slot_location_updates u
            ON u.id_slot = s.id_slot
        SET
            s.id_amb_legacy = NULLIF(u.id_amb_legacy, 0),
            s.ambulatorio = u.ambulatorio,
            s.stanza = u.stanza,
            s.updated_at = NOW()
    ");

    $summary['updated'] += max(0, $db->affected_rows);
    $db->query('TRUNCATE TABLE tmp_slot_location_updates');
    $batch = [];
}

function backfillFutureSlots(mysqli $readDb, mysqli $writeDb, string $targetDb, array $rulesByDay, string $dateFrom, bool $apply): array
{
    $summary = [
        'scanned' => 0,
        'matched' => 0,
        'updated' => 0,
    ];

    if ($apply) {
        $writeDb->query("
            CREATE TEMPORARY TABLE IF NOT EXISTS tmp_slot_location_updates (
                id_slot INT NOT NULL PRIMARY KEY,
                id_amb_legacy INT NOT NULL DEFAULT 0,
                ambulatorio VARCHAR(150) NOT NULL DEFAULT '',
                stanza VARCHAR(100) NOT NULL DEFAULT ''
            )
        ");
        $writeDb->query('TRUNCATE TABLE tmp_slot_location_updates');
    }

    $sql = "
        SELECT
            id_slot,
            id_dot,
            data_slot,
            TIME_FORMAT(ora_inizio, '%H:%i:%s') AS ora_inizio,
            TIME_FORMAT(ora_fine, '%H:%i:%s') AS ora_fine,
            COALESCE(id_amb_legacy, 0) AS id_amb_legacy,
            COALESCE(ambulatorio, '') AS ambulatorio,
            COALESCE(stanza, '') AS stanza
        FROM `{$targetDb}`.dap11_agenda_slot
        WHERE data_slot >= '{$readDb->real_escape_string($dateFrom)}'
          AND tipo_slot = 'AMBULATORIO'
        ORDER BY id_dot ASC, data_slot ASC, ora_inizio ASC
    ";

    $batch = [];
    $res = $readDb->query($sql, MYSQLI_USE_RESULT);
    while ($row = $res->fetch_assoc()) {
        $summary['scanned']++;

        $idDot = (int)($row['id_dot'] ?? 0);
        $date = (string)($row['data_slot'] ?? '');
        $day = (int)date('N', strtotime($date));
        $start = normalizeTimeValue((string)($row['ora_inizio'] ?? ''));
        $end = normalizeTimeValue((string)($row['ora_fine'] ?? ''));

        $rule = matchRule($rulesByDay, $idDot, $day, $date, $start, $end);
        if ($rule === null) {
            continue;
        }

        $summary['matched']++;

        $newIdAmb = (int)($rule['id_amb_legacy'] ?? 0);
        $newAmb = trim((string)($rule['ambulatorio'] ?? ''));
        $newStanza = trim((string)($rule['stanza'] ?? ''));

        $currentIdAmb = (int)($row['id_amb_legacy'] ?? 0);
        $currentAmb = trim((string)($row['ambulatorio'] ?? ''));
        $currentStanza = trim((string)($row['stanza'] ?? ''));

        if ($currentIdAmb === $newIdAmb && $currentAmb === $newAmb && $currentStanza === $newStanza) {
            continue;
        }

        $batch[] = [
            'id_slot' => (int)($row['id_slot'] ?? 0),
            'id_amb_legacy' => $newIdAmb,
            'ambulatorio' => $newAmb,
            'stanza' => $newStanza,
        ];

        if (count($batch) >= 5000) {
            flushSlotBatch($writeDb, $targetDb, $batch, $summary, $apply);
        }
    }
    $res->close();

    flushSlotBatch($writeDb, $targetDb, $batch, $summary, $apply);

    return $summary;
}

$options = getopt('', ['source-db::', 'target-db::', 'date-from::', 'apply::']);
$config = loadEnvConfig(__DIR__ . DIRECTORY_SEPARATOR . '.env');
$targetDb = (string)($options['target-db'] ?? ($config['database.default.database'] ?? 'mail'));
$sourceDb = (string)($options['source-db'] ?? 'farmacia');
$dateFrom = (string)($options['date-from'] ?? date('Y-m-d'));
$apply = !isset($options['apply']) || (string)$options['apply'] !== '0';

$readDb = connectDb($config);
$writeDb = connectDb($config);

$summary = [
    'source_db' => $sourceDb,
    'target_db' => $targetDb,
    'date_from' => $dateFrom,
    'apply' => $apply,
];

$rulesByDay = loadLegacyRules($readDb, $sourceDb);
$ruleCount = 0;
foreach ($rulesByDay as $dayRules) {
    foreach ($dayRules as $rules) {
        $ruleCount += count($rules);
    }
}

$summary['legacy_rules'] = $ruleCount;
$summary['ambulatori_synced'] = syncAmbulatori($writeDb, $sourceDb, $targetDb, $apply);
$summary['config_fasce'] = backfillConfigFasce($writeDb, $targetDb, $rulesByDay, $apply);
$summary['future_slots'] = backfillFutureSlots($readDb, $writeDb, $targetDb, $rulesByDay, $dateFrom, $apply);

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
