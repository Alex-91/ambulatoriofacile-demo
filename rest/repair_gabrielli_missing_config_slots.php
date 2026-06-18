<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    main($_SERVER['argv'] ?? []);
}

function main(array $argv): void
{
    $options = parseOptions($argv);
    $env = loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');

    $host = trim((string)($env['database.default.hostname'] ?? '127.0.0.1'));
    $port = (int)($env['database.default.port'] ?? 3306);
    $user = trim((string)($env['database.default.username'] ?? 'root'));
    $pass = trim((string)($env['database.default.password'] ?? 'root'));
    $dbName = trim((string)($env['database.default.database'] ?? 'mail'));

    $db = new mysqli($host, $user, $pass, $dbName, $port);
    $db->set_charset('utf8mb4');

    $repair = new GabrielliMissingConfigSlotRepair($db, $dbName, $options);
    $summary = $repair->run();

    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}

function parseOptions(array $argv): array
{
    return [
        'apply' => hasFlag($argv, '--apply'),
        'doctor' => max(1, (int)(optionValue($argv, 'doctor') ?? 63)),
        'date_from' => normalizeDate(optionValue($argv, 'date-from')) ?? date('Y-m-d'),
        'date_to' => normalizeDate(optionValue($argv, 'date-to')) ?? date('Y-m-d', strtotime('+18 months')),
    ];
}

function hasFlag(array $argv, string $flag): bool
{
    foreach ($argv as $arg) {
        if ((string)$arg === $flag) {
            return true;
        }
    }

    return false;
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

final class GabrielliMissingConfigSlotRepair
{
    private mysqli $db;
    private string $targetDb;
    private bool $apply;
    private int $doctorId;
    private string $dateFrom;
    private string $dateTo;
    private string $patchNote = 'Patch Gabrielli recurring extra esportata da locale';

    public function __construct(mysqli $db, string $targetDb, array $options)
    {
        $this->db = $db;
        $this->targetDb = $targetDb;
        $this->apply = !empty($options['apply']);
        $this->doctorId = (int)($options['doctor'] ?? 63);
        $this->dateFrom = (string)$options['date_from'];
        $this->dateTo = (string)$options['date_to'];
    }

    public function run(): array
    {
        $days = $this->loadAffectedDays();

        $summary = [
            'mode' => $this->apply ? 'apply' : 'dry-run',
            'target_db' => $this->targetDb,
            'doctor_id' => $this->doctorId,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'affected_days' => count($days),
            'expected_overlapping_config_slots' => 0,
            'inserted_missing_config_slots' => 0,
            'skipped_existing_exact_slots' => 0,
            'days_without_config' => [],
            'examples' => [],
        ];

        if ($this->apply) {
            $this->db->begin_transaction();
        }

        try {
            foreach ($days as $day) {
                $date = (string)$day['data_slot'];
                $extraWindows = $day['extra_windows'] ?? [];

                $config = $this->loadActiveConfig($date);
                if ($config === null) {
                    $summary['days_without_config'][] = $date;
                    continue;
                }

                $dayRow = $this->loadDayRow((int)$config['id_config'], $date);
                if ($dayRow === null || (int)($dayRow['giorno_libero'] ?? 0) === 1) {
                    $summary['days_without_config'][] = $date;
                    continue;
                }

                $fasce = $this->loadFasceForDayRow($dayRow);
                if ($fasce === []) {
                    $summary['days_without_config'][] = $date;
                    continue;
                }

                $state = $this->isBlockedDay($date) ? 'CHIUSO' : 'LIBERO';
                $insertedForDay = [];
                $skippedForDay = [];

                foreach ($fasce as $fascia) {
                    foreach ($this->buildConfigSlotsForDate($date, $fascia) as $slot) {
                        if (!$this->overlapsAnyExtraWindow($slot, $extraWindows)) {
                            continue;
                        }

                        $summary['expected_overlapping_config_slots']++;

                        if ($this->hasExactSlot($date, $slot['ora_inizio_dt'], $slot['ora_fine_dt'])) {
                            $summary['skipped_existing_exact_slots']++;
                            $skippedForDay[] = $slot['ora_inizio'] . '-' . $slot['ora_fine'];
                            continue;
                        }

                        if ($this->apply) {
                            $this->insertConfigSlot(
                                (int)$config['id_config'],
                                $date,
                                $slot['ora_inizio_dt'],
                                $slot['ora_fine_dt'],
                                $state,
                                $fascia
                            );
                        }

                        $summary['inserted_missing_config_slots']++;
                        $insertedForDay[] = $slot['ora_inizio'] . '-' . $slot['ora_fine'];
                    }
                }

                if (count($summary['examples']) < 30 && ($insertedForDay !== [] || $skippedForDay !== [])) {
                    $summary['examples'][] = [
                        'date' => $date,
                        'extra_windows' => array_map(
                            static fn(array $window): string => $window['ora_inizio'] . '-' . $window['ora_fine'],
                            $extraWindows
                        ),
                        'inserted_config_slots' => $insertedForDay,
                        'already_present_config_slots' => $skippedForDay,
                    ];
                }
            }

            if ($this->apply) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($this->apply) {
                $this->db->rollback();
            }

            throw $e;
        }

        return $summary;
    }

    private function loadAffectedDays(): array
    {
        $stmt = $this->db->prepare("
            SELECT data_slot, TIME(ora_inizio) AS ora_inizio, TIME(ora_fine) AS ora_fine
            FROM `{$this->targetDb}`.dap11_agenda_slot
            WHERE id_dot = ?
              AND origine_slot = 'EXTRA'
              AND note_interne = ?
              AND data_slot >= ?
              AND data_slot <= ?
            ORDER BY data_slot ASC, ora_inizio ASC, ora_fine ASC, id_slot ASC
        ");
        $stmt->bind_param('isss', $this->doctorId, $this->patchNote, $this->dateFrom, $this->dateTo);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $grouped = [];
        foreach ($rows as $row) {
            $date = (string)$row['data_slot'];
            if (!isset($grouped[$date])) {
                $grouped[$date] = [
                    'data_slot' => $date,
                    'extra_windows' => [],
                ];
            }

            $grouped[$date]['extra_windows'][] = [
                'ora_inizio' => substr((string)$row['ora_inizio'], 0, 5),
                'ora_fine' => substr((string)$row['ora_fine'], 0, 5),
            ];
        }

        return array_values($grouped);
    }

    private function loadActiveConfig(string $date): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id_config
            FROM `{$this->targetDb}`.dap10_agenda_config
            WHERE id_dot = ?
              AND attiva = 1
              AND data_inizio <= ?
              AND data_fine >= ?
            ORDER BY id_config DESC
            LIMIT 1
        ");
        $stmt->bind_param('iss', $this->doctorId, $date, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function loadDayRow(int $idConfig, string $date): ?array
    {
        $weekday = (int)date('N', strtotime($date));

        $stmt = $this->db->prepare("
            SELECT
                id_config_giorno,
                giorno_libero,
                mattina_attiva,
                mattina_ora_inizio,
                mattina_ora_fine,
                mattina_durata_slot,
                pomeriggio_attiva,
                pomeriggio_ora_inizio,
                pomeriggio_ora_fine,
                pomeriggio_durata_slot
            FROM `{$this->targetDb}`.dap10_agenda_config_giorni
            WHERE id_config = ?
              AND giorno_settimana = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $idConfig, $weekday);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function loadFasceForDayRow(array $dayRow): array
    {
        $idConfigGiorno = (int)($dayRow['id_config_giorno'] ?? 0);
        if ($idConfigGiorno > 0) {
            $stmt = $this->db->prepare("
                SELECT ora_inizio, ora_fine, durata_slot, id_amb_legacy, ambulatorio, stanza
                FROM `{$this->targetDb}`.dap10_agenda_config_fasce
                WHERE id_config_giorno = ?
                ORDER BY ordine ASC, id_config_fascia ASC
            ");
            $stmt->bind_param('i', $idConfigGiorno);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if ($rows !== []) {
                return $rows;
            }
        }

        $fasce = [];
        if ((int)($dayRow['mattina_attiva'] ?? 0) === 1) {
            $fasce[] = [
                'ora_inizio' => (string)($dayRow['mattina_ora_inizio'] ?? ''),
                'ora_fine' => (string)($dayRow['mattina_ora_fine'] ?? ''),
                'durata_slot' => (int)($dayRow['mattina_durata_slot'] ?? 0),
                'id_amb_legacy' => null,
                'ambulatorio' => null,
                'stanza' => null,
            ];
        }

        if ((int)($dayRow['pomeriggio_attiva'] ?? 0) === 1) {
            $fasce[] = [
                'ora_inizio' => (string)($dayRow['pomeriggio_ora_inizio'] ?? ''),
                'ora_fine' => (string)($dayRow['pomeriggio_ora_fine'] ?? ''),
                'durata_slot' => (int)($dayRow['pomeriggio_durata_slot'] ?? 0),
                'id_amb_legacy' => null,
                'ambulatorio' => null,
                'stanza' => null,
            ];
        }

        return $fasce;
    }

    private function buildConfigSlotsForDate(string $date, array $fascia): array
    {
        $oraInizio = substr((string)($fascia['ora_inizio'] ?? ''), 0, 8);
        $oraFine = substr((string)($fascia['ora_fine'] ?? ''), 0, 8);
        $durata = (int)($fascia['durata_slot'] ?? 0);

        if ($oraInizio === '' || $oraFine === '' || $durata <= 0) {
            return [];
        }

        $cursor = new DateTime($date . ' ' . $oraInizio);
        $limit = new DateTime($date . ' ' . $oraFine);
        $slots = [];

        while ($cursor < $limit) {
            $slotStart = clone $cursor;
            $slotEnd = (clone $cursor)->modify('+' . $durata . ' minutes');

            if ($slotEnd > $limit) {
                break;
            }

            $slots[] = [
                'ora_inizio_dt' => $slotStart->format('Y-m-d H:i:s'),
                'ora_fine_dt' => $slotEnd->format('Y-m-d H:i:s'),
                'ora_inizio' => $slotStart->format('H:i'),
                'ora_fine' => $slotEnd->format('H:i'),
            ];

            $cursor = $slotEnd;
        }

        return $slots;
    }

    private function overlapsAnyExtraWindow(array $slot, array $extraWindows): bool
    {
        $slotStart = (string)$slot['ora_inizio'];
        $slotEnd = (string)$slot['ora_fine'];

        foreach ($extraWindows as $window) {
            $extraStart = (string)($window['ora_inizio'] ?? '');
            $extraEnd = (string)($window['ora_fine'] ?? '');

            if ($slotStart < $extraEnd && $slotEnd > $extraStart) {
                return true;
            }
        }

        return false;
    }

    private function hasExactSlot(string $date, string $slotStart, string $slotEnd): bool
    {
        $stmt = $this->db->prepare("
            SELECT id_slot
            FROM `{$this->targetDb}`.dap11_agenda_slot
            WHERE id_dot = ?
              AND data_slot = ?
              AND ora_inizio = ?
              AND ora_fine = ?
            LIMIT 1
        ");
        $stmt->bind_param('isss', $this->doctorId, $date, $slotStart, $slotEnd);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null;
    }

    private function isBlockedDay(string $date): bool
    {
        $stmt = $this->db->prepare("
            SELECT id_blocco
            FROM `{$this->targetDb}`.dap21_agenda_giorni_bloccati
            WHERE id_dot = ?
              AND data_agenda = ?
            LIMIT 1
        ");
        $stmt->bind_param('is', $this->doctorId, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null;
    }

    private function insertConfigSlot(int $idConfig, string $date, string $slotStart, string $slotEnd, string $state, array $fascia): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO `{$this->targetDb}`.dap11_agenda_slot
            (
                id_dot, id_config, data_slot, ora_inizio, ora_fine, tipo_slot, stato,
                titolo_libero, id_amb_legacy, ambulatorio, stanza, origine_slot, note_interne, created_at, updated_at
            )
            VALUES (?, ?, ?, ?, ?, 'AMBULATORIO', ?, NULL, ?, ?, ?, 'CONFIG', ?, NOW(), NOW())
        ");

        $idAmbLegacy = !empty($fascia['id_amb_legacy']) ? (int)$fascia['id_amb_legacy'] : null;
        $ambulatorio = isset($fascia['ambulatorio']) ? trim((string)$fascia['ambulatorio']) : null;
        $stanza = isset($fascia['stanza']) ? trim((string)$fascia['stanza']) : null;
        $ambulatorio = $ambulatorio === '' ? null : $ambulatorio;
        $stanza = $stanza === '' ? null : $stanza;
        $note = 'Ripristinato CONFIG dopo patch Gabrielli recurring extra';

        $stmt->bind_param(
            'iissssisss',
            $this->doctorId,
            $idConfig,
            $date,
            $slotStart,
            $slotEnd,
            $state,
            $idAmbLegacy,
            $ambulatorio,
            $stanza,
            $note
        );
        $stmt->execute();
        $stmt->close();
    }
}
