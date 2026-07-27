<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    main($_SERVER['argv'] ?? []);
}

function main(array $argv): void
{
    $options = parseOptions($argv);
    if ($options['username'] === '') {
        throw new RuntimeException('Devi indicare esplicitamente --username=<login_tenant>.');
    }

    $env = loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');

    $host = (string)($env['database.default.hostname'] ?? '127.0.0.1');
    $port = (int)($env['database.default.port'] ?? 3306);
    $user = (string)($env['database.default.username'] ?? 'root');
    $pass = (string)($env['database.default.password'] ?? 'root');
    $targetDb = (string)($options['target_db'] ?: ($env['database.default.database'] ?? ''));

    if ($targetDb === '') {
        throw new RuntimeException('Database target non disponibile. Passa --target-db=<nome_db>.');
    }

    $db = new mysqli($host, $user, $pass, $targetDb, $port);
    $db->set_charset('utf8mb4');

    $adder = new TargetedRecurringExtraSlotAdder($db, $targetDb, $options);
    $summary = $adder->run();

    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}

function parseOptions(array $argv): array
{
    $slotStart = normalizeTime(optionValue($argv, 'slot-start') ?? '18:30') ?? '18:30:00';
    $slotEnd = normalizeTime(optionValue($argv, 'slot-end') ?? '19:00') ?? '19:00:00';

    if ($slotEnd <= $slotStart) {
        throw new RuntimeException('Lo slot finale deve essere successivo allo slot iniziale.');
    }

    return [
        'apply' => hasFlag($argv, '--apply'),
        'allow_overlaps' => hasFlag($argv, '--allow-overlaps'),
        'target_db' => (string)(optionValue($argv, 'target-db') ?? ''),
        'username' => trim((string)(optionValue($argv, 'username') ?? '')),
        'date_from' => normalizeDate(optionValue($argv, 'date-from')) ?? date('Y-m-d'),
        'date_to' => normalizeDate(optionValue($argv, 'date-to')) ?? date('Y-m-d', strtotime('+18 months')),
        'weekdays' => parseWeekdays(optionValue($argv, 'weekdays') ?? '1,5'),
        'slot_start' => $slotStart,
        'slot_end' => $slotEnd,
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

/**
 * @return int[]
 */
function parseWeekdays(string $value): array
{
    $out = [];
    foreach (preg_split('/[\s,;|]+/', trim($value)) ?: [] as $chunk) {
        $day = (int)$chunk;
        if ($day >= 1 && $day <= 7) {
            $out[$day] = $day;
        }
    }

    if ($out === []) {
        throw new RuntimeException('Weekdays non validi. Usa valori ISO 1-7, es. 1,5.');
    }

    ksort($out);
    return array_values($out);
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

function normalizeTime(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $value = trim($value);
    if (preg_match('/^\d{1,2}:\d{2}$/', $value) === 1) {
        $value .= ':00';
    }

    $dt = DateTime::createFromFormat('H:i:s', $value);
    if (!$dt instanceof DateTime || $dt->format('H:i:s') !== $value) {
        throw new RuntimeException("Ora non valida: {$value}. Usa HH:MM oppure HH:MM:SS.");
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

final class TargetedRecurringExtraSlotAdder
{
    private mysqli $db;
    private string $targetDb;
    private bool $apply;
    private bool $allowOverlaps;
    private string $username;
    private string $dateFrom;
    private string $dateTo;
    /** @var int[] */
    private array $weekdays;
    private string $slotStart;
    private string $slotEnd;
    /** @var array<string,bool> */
    private array $blockedDayKeys = [];
    /** @var array<string,bool> */
    private array $columnExistsCache = [];
    /** @var array<string,bool> */
    private array $tableExistsCache = [];
    /** @var array<string,int> */
    private array $configIdCache = [];
    /** @var array<int, array<string, mixed>> */
    private array $activeConfigs = [];
    /** @var array<string, array<string, mixed>|null> */
    private array $dayRowCache = [];
    /** @var array<int, array<int, array<string, mixed>>> */
    private array $scheduleWindowsCache = [];
    /** @var array<string, array<string, mixed>> */
    private array $exactSlotCache = [];
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $overlapSlotCache = [];

    public function __construct(mysqli $db, string $targetDb, array $options)
    {
        $this->db = $db;
        $this->targetDb = $targetDb;
        $this->apply = !empty($options['apply']);
        $this->allowOverlaps = !empty($options['allow_overlaps']);
        $this->username = (string)$options['username'];
        $this->dateFrom = (string)$options['date_from'];
        $this->dateTo = (string)$options['date_to'];
        $this->weekdays = $options['weekdays'] ?? [];
        $this->slotStart = (string)$options['slot_start'];
        $this->slotEnd = (string)$options['slot_end'];
    }

    public function run(): array
    {
        if ($this->dateTo < $this->dateFrom) {
            throw new RuntimeException('date_to non puo essere precedente a date_from.');
        }

        $this->assertTables();
        $doctor = $this->loadDoctor();
        $this->loadBlockedDays((int)$doctor['id_dot']);
        $this->loadActiveConfigs((int)$doctor['id_dot']);
        $this->preloadExactSlots((int)$doctor['id_dot']);
        $this->preloadOverlappingSlots((int)$doctor['id_dot']);

        $summary = [
            'mode' => $this->apply ? 'apply' : 'dry-run',
            'target_db' => $this->targetDb,
            'username' => $doctor['username'],
            'id_user' => (int)$doctor['id_user'],
            'id_personale' => (int)$doctor['id_personale'],
            'id_dot' => (int)$doctor['id_dot'],
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'weekdays' => $this->weekdays,
            'slot_window' => substr($this->slotStart, 0, 5) . '-' . substr($this->slotEnd, 0, 5),
            'allow_overlaps' => $this->allowOverlaps,
            'checked_dates' => 0,
            'eligible_days' => 0,
            'inserted_extra_slots' => 0,
            'skipped_existing_exact_slots' => 0,
            'skipped_overlapping_slots' => 0,
            'skipped_no_active_config' => 0,
            'skipped_day_without_schedule' => 0,
            'skipped_day_marked_free' => 0,
            'examples' => [],
        ];

        if ($this->apply) {
            $this->db->begin_transaction();
        }

        try {
            foreach ($this->iterateDates() as $date) {
                $summary['checked_dates']++;
                $weekday = (int)(new DateTime($date))->format('N');

                $configId = $this->resolveConfigIdForDate((int)$doctor['id_dot'], $date);
                if ($configId <= 0) {
                    $summary['skipped_no_active_config']++;
                    $this->pushExample($summary, [
                        'date' => $date,
                        'action' => 'skip_no_active_config',
                    ]);
                    continue;
                }

                $dayRow = $this->loadDayRow($configId, $weekday);
                if ($dayRow === null) {
                    $summary['skipped_day_without_schedule']++;
                    $this->pushExample($summary, [
                        'date' => $date,
                        'action' => 'skip_day_without_schedule',
                        'id_config' => $configId,
                    ]);
                    continue;
                }

                if ((int)($dayRow['giorno_libero'] ?? 0) === 1) {
                    $summary['skipped_day_marked_free']++;
                    $this->pushExample($summary, [
                        'date' => $date,
                        'action' => 'skip_day_marked_free',
                        'id_config' => $configId,
                    ]);
                    continue;
                }

                $scheduleWindows = $this->loadScheduleWindows($dayRow);
                if ($scheduleWindows === []) {
                    $summary['skipped_day_without_schedule']++;
                    $this->pushExample($summary, [
                        'date' => $date,
                        'action' => 'skip_day_without_schedule',
                        'id_config' => $configId,
                    ]);
                    continue;
                }

                $summary['eligible_days']++;

                $slotStart = $date . ' ' . $this->slotStart;
                $slotEnd = $date . ' ' . $this->slotEnd;
                $exactSlot = $this->findExactSlot((int)$doctor['id_dot'], $date, $slotStart, $slotEnd);
                if ($exactSlot !== null) {
                    $summary['skipped_existing_exact_slots']++;
                    $this->pushExample($summary, [
                        'date' => $date,
                        'action' => 'skip_existing_exact',
                        'existing_slot_id' => (int)$exactSlot['id_slot'],
                        'existing_origin' => (string)$exactSlot['origine_slot'],
                        'existing_state' => (string)$exactSlot['stato'],
                    ]);
                    continue;
                }

                $overlaps = $this->loadOverlappingSlots((int)$doctor['id_dot'], $date, $slotStart, $slotEnd);
                if (!$this->allowOverlaps && $overlaps !== []) {
                    $summary['skipped_overlapping_slots']++;
                    $this->pushExample($summary, [
                        'date' => $date,
                        'action' => 'skip_overlap',
                        'overlap_slot_ids' => array_map(
                            static fn(array $row): int => (int)$row['id_slot'],
                            $overlaps
                        ),
                    ]);
                    continue;
                }

                $location = $this->selectLocationWindow($scheduleWindows);
                $state = isset($this->blockedDayKeys[$this->buildDoctorDayKey((int)$doctor['id_dot'], $date)])
                    ? 'CHIUSO'
                    : 'LIBERO';

                if ($this->apply) {
                    $this->insertExtraSlot(
                        (int)$doctor['id_dot'],
                        $configId,
                        $date,
                        $slotStart,
                        $slotEnd,
                        $state,
                        $location
                    );
                }

                $summary['inserted_extra_slots']++;
                $this->pushExample($summary, [
                    'date' => $date,
                    'action' => $this->apply ? 'inserted' : 'would_insert',
                    'state' => $state,
                    'id_config' => $configId,
                    'location' => [
                        'id_amb_legacy' => (int)($location['id_amb_legacy'] ?? 0),
                        'ambulatorio' => (string)($location['ambulatorio'] ?? ''),
                        'stanza' => (string)($location['stanza'] ?? ''),
                    ],
                ]);
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

    private function assertTables(): void
    {
        foreach (['dap01_users', 'dap03_personale', 'dap10_agenda_config', 'dap10_agenda_config_giorni', 'dap11_agenda_slot'] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException("Tabella mancante: {$this->targetDb}.{$table}");
            }
        }
    }

    private function loadDoctor(): array
    {
        $stmt = $this->db->prepare("
            SELECT
                u.id_user,
                u.username,
                p.id_personale,
                p.tipo,
                COALESCE(p.legacy_id_dot, 0) AS id_dot
            FROM `{$this->targetDb}`.dap01_users u
            INNER JOIN `{$this->targetDb}`.dap03_personale p
                ON p.id_user = u.id_user
            WHERE LOWER(u.username) = LOWER(?)
              AND p.tipo IN (1, 2)
            ORDER BY p.id_personale ASC
            LIMIT 1
        ");
        $stmt->bind_param('s', $this->username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException("Username professionista tenant non trovato: {$this->username}");
        }

        if ((int)($row['id_dot'] ?? 0) <= 0) {
            throw new RuntimeException("Lo username {$this->username} non ha un legacy_id_dot valido.");
        }

        return $row;
    }

    private function loadBlockedDays(int $idDot): void
    {
        if (!$this->tableExists('dap21_agenda_giorni_bloccati')) {
            return;
        }

        $stmt = $this->db->prepare("
            SELECT data_agenda
            FROM `{$this->targetDb}`.dap21_agenda_giorni_bloccati
            WHERE id_dot = ?
              AND data_agenda >= ?
              AND data_agenda <= ?
        ");
        $stmt->bind_param('iss', $idDot, $this->dateFrom, $this->dateTo);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as $row) {
            $this->blockedDayKeys[$this->buildDoctorDayKey($idDot, (string)$row['data_agenda'])] = true;
        }
    }

    /**
     * @return Generator<int, string>
     */
    private function iterateDates(): Generator
    {
        $cursor = new DateTime($this->dateFrom);
        $end = new DateTime($this->dateTo);

        while ($cursor <= $end) {
            $weekday = (int)$cursor->format('N');
            if (in_array($weekday, $this->weekdays, true)) {
                yield $cursor->format('Y-m-d');
            }

            $cursor->modify('+1 day');
        }
    }

    private function resolveConfigIdForDate(int $idDot, string $date): int
    {
        $cacheKey = $this->buildDoctorDayKey($idDot, $date);
        if (isset($this->configIdCache[$cacheKey])) {
            return $this->configIdCache[$cacheKey];
        }

        $resolvedId = 0;
        foreach ($this->activeConfigs as $config) {
            if ((int)($config['id_dot'] ?? 0) !== $idDot) {
                continue;
            }

            $configStart = (string)($config['data_inizio'] ?? '');
            $configEnd = (string)($config['data_fine'] ?? '');
            if ($configStart !== '' && $configEnd !== '' && $configStart <= $date && $configEnd >= $date) {
                $resolvedId = (int)($config['id_config'] ?? 0);
                break;
            }
        }

        $this->configIdCache[$cacheKey] = $resolvedId;
        return $this->configIdCache[$cacheKey];
    }

    private function loadDayRow(int $configId, int $weekday): ?array
    {
        $cacheKey = $configId . '|' . $weekday;
        if (array_key_exists($cacheKey, $this->dayRowCache)) {
            return $this->dayRowCache[$cacheKey];
        }

        $stmt = $this->db->prepare("
            SELECT
                id_config_giorno,
                giorno_settimana,
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
        $stmt->bind_param('ii', $configId, $weekday);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->dayRowCache[$cacheKey] = $row ?: null;
        return $this->dayRowCache[$cacheKey];
    }

    /**
     * @param array<string, mixed> $dayRow
     * @return array<int, array<string, mixed>>
     */
    private function loadScheduleWindows(array $dayRow): array
    {
        $dayRowId = (int)($dayRow['id_config_giorno'] ?? 0);
        if ($dayRowId > 0 && isset($this->scheduleWindowsCache[$dayRowId])) {
            return $this->scheduleWindowsCache[$dayRowId];
        }

        $windows = [];

        if ($dayRowId > 0 && $this->tableExists('dap10_agenda_config_fasce')) {
            $select = "
                id_config_fascia,
                ordine,
                ora_inizio,
                ora_fine,
                durata_slot,
                COALESCE(id_amb_legacy, 0) AS id_amb_legacy,
                COALESCE(ambulatorio, '') AS ambulatorio,
                COALESCE(stanza, '') AS stanza
            ";
            if ($this->columnExists('dap10_agenda_config_fasce', 'id_stanza')) {
                $select .= ",
                COALESCE(id_stanza, 0) AS id_stanza
                ";
            } else {
                $select .= ",
                0 AS id_stanza
                ";
            }

            $stmt = $this->db->prepare("
                SELECT {$select}
                FROM `{$this->targetDb}`.dap10_agenda_config_fasce
                WHERE id_config_giorno = ?
                ORDER BY ordine ASC, ora_inizio ASC, id_config_fascia ASC
            ");
            $stmt->bind_param('i', $dayRowId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($rows as $row) {
                $start = normalizeTime((string)($row['ora_inizio'] ?? ''));
                $end = normalizeTime((string)($row['ora_fine'] ?? ''));
                if ($start === null || $end === null || $end <= $start) {
                    continue;
                }

                $windows[] = [
                    'ora_inizio' => $start,
                    'ora_fine' => $end,
                    'durata_slot' => (int)($row['durata_slot'] ?? 0),
                    'id_amb_legacy' => (int)($row['id_amb_legacy'] ?? 0),
                    'ambulatorio' => trim((string)($row['ambulatorio'] ?? '')),
                    'stanza' => trim((string)($row['stanza'] ?? '')),
                    'id_stanza' => (int)($row['id_stanza'] ?? 0),
                ];
            }
        }

        if ($windows !== []) {
            $this->scheduleWindowsCache[$dayRowId] = $windows;
            return $windows;
        }

        $legacyWindows = [
            [
                'enabled' => (int)($dayRow['mattina_attiva'] ?? 0) === 1,
                'ora_inizio' => normalizeTime((string)($dayRow['mattina_ora_inizio'] ?? '')),
                'ora_fine' => normalizeTime((string)($dayRow['mattina_ora_fine'] ?? '')),
                'durata_slot' => (int)($dayRow['mattina_durata_slot'] ?? 0),
            ],
            [
                'enabled' => (int)($dayRow['pomeriggio_attiva'] ?? 0) === 1,
                'ora_inizio' => normalizeTime((string)($dayRow['pomeriggio_ora_inizio'] ?? '')),
                'ora_fine' => normalizeTime((string)($dayRow['pomeriggio_ora_fine'] ?? '')),
                'durata_slot' => (int)($dayRow['pomeriggio_durata_slot'] ?? 0),
            ],
        ];

        foreach ($legacyWindows as $window) {
            $start = (string)($window['ora_inizio'] ?? '');
            $end = (string)($window['ora_fine'] ?? '');
            if (empty($window['enabled']) || $start === '' || $end === '' || $end <= $start) {
                continue;
            }

            $windows[] = [
                'ora_inizio' => $start,
                'ora_fine' => $end,
                'durata_slot' => (int)($window['durata_slot'] ?? 0),
                'id_amb_legacy' => 0,
                'ambulatorio' => '',
                'stanza' => '',
                'id_stanza' => 0,
            ];
        }

        if ($dayRowId > 0) {
            $this->scheduleWindowsCache[$dayRowId] = $windows;
        }

        return $windows;
    }

    /**
     * @param array<int, array<string, mixed>> $scheduleWindows
     * @return array<string, mixed>
     */
    private function selectLocationWindow(array $scheduleWindows): array
    {
        $selected = $scheduleWindows[count($scheduleWindows) - 1];
        foreach ($scheduleWindows as $window) {
            $windowEnd = (string)($window['ora_fine'] ?? '');
            if ($windowEnd !== '' && $windowEnd <= $this->slotStart) {
                $selected = $window;
            }
        }

        return $selected;
    }

    private function findExactSlot(int $idDot, string $date, string $slotStart, string $slotEnd): ?array
    {
        $cacheKey = $this->buildDoctorDayKey($idDot, $date);
        if (isset($this->exactSlotCache[$cacheKey])) {
            return $this->exactSlotCache[$cacheKey];
        }

        $stmt = $this->db->prepare("
            SELECT id_slot, origine_slot, stato
            FROM `{$this->targetDb}`.dap11_agenda_slot
            WHERE id_dot = ?
              AND data_slot = ?
              AND ora_inizio = ?
              AND ora_fine = ?
            ORDER BY id_slot ASC
            LIMIT 1
        ");
        $stmt->bind_param('isss', $idDot, $date, $slotStart, $slotEnd);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadOverlappingSlots(int $idDot, string $date, string $slotStart, string $slotEnd): array
    {
        $cacheKey = $this->buildDoctorDayKey($idDot, $date);
        if (isset($this->overlapSlotCache[$cacheKey])) {
            return $this->overlapSlotCache[$cacheKey];
        }

        $stmt = $this->db->prepare("
            SELECT id_slot, origine_slot, stato, ora_inizio, ora_fine
            FROM `{$this->targetDb}`.dap11_agenda_slot
            WHERE id_dot = ?
              AND data_slot = ?
              AND ora_inizio < ?
              AND ora_fine > ?
            ORDER BY ora_inizio ASC, ora_fine ASC, id_slot ASC
        ");
        $stmt->bind_param('isss', $idDot, $date, $slotEnd, $slotStart);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    private function loadActiveConfigs(int $idDot): void
    {
        $stmt = $this->db->prepare("
            SELECT id_config, id_dot, data_inizio, data_fine
            FROM `{$this->targetDb}`.dap10_agenda_config
            WHERE id_dot = ?
              AND attiva = 1
              AND data_inizio <= ?
              AND data_fine >= ?
            ORDER BY id_config DESC
        ");
        $stmt->bind_param('iss', $idDot, $this->dateTo, $this->dateFrom);
        $stmt->execute();
        $this->activeConfigs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    private function preloadExactSlots(int $idDot): void
    {
        $slotStartTime = $this->slotStart;
        $slotEndTime = $this->slotEnd;

        $stmt = $this->db->prepare("
            SELECT data_slot, id_slot, origine_slot, stato
            FROM `{$this->targetDb}`.dap11_agenda_slot
            WHERE id_dot = ?
              AND data_slot >= ?
              AND data_slot <= ?
              AND TIME(ora_inizio) = ?
              AND TIME(ora_fine) = ?
            ORDER BY data_slot ASC, id_slot ASC
        ");
        $stmt->bind_param('issss', $idDot, $this->dateFrom, $this->dateTo, $slotStartTime, $slotEndTime);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as $row) {
            $cacheKey = $this->buildDoctorDayKey($idDot, (string)$row['data_slot']);
            if (!isset($this->exactSlotCache[$cacheKey])) {
                $this->exactSlotCache[$cacheKey] = $row;
            }
        }
    }

    private function preloadOverlappingSlots(int $idDot): void
    {
        $slotStartTime = $this->slotStart;
        $slotEndTime = $this->slotEnd;

        $stmt = $this->db->prepare("
            SELECT data_slot, id_slot, origine_slot, stato, ora_inizio, ora_fine
            FROM `{$this->targetDb}`.dap11_agenda_slot
            WHERE id_dot = ?
              AND data_slot >= ?
              AND data_slot <= ?
              AND ora_inizio < TIMESTAMP(data_slot, ?)
              AND ora_fine > TIMESTAMP(data_slot, ?)
            ORDER BY data_slot ASC, ora_inizio ASC, ora_fine ASC, id_slot ASC
        ");
        $stmt->bind_param('issss', $idDot, $this->dateFrom, $this->dateTo, $slotEndTime, $slotStartTime);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as $row) {
            $cacheKey = $this->buildDoctorDayKey($idDot, (string)$row['data_slot']);
            $this->overlapSlotCache[$cacheKey][] = $row;
        }
    }

    /**
     * @param array<string, mixed> $location
     */
    private function insertExtraSlot(
        int $idDot,
        int $configId,
        string $date,
        string $slotStart,
        string $slotEnd,
        string $state,
        array $location
    ): void {
        $idAmbLegacy = (int)($location['id_amb_legacy'] ?? 0);
        $ambulatorio = trim((string)($location['ambulatorio'] ?? ''));
        $stanza = trim((string)($location['stanza'] ?? ''));
        $noteInterne = sprintf(
            'Aggiunto da Codex: slot extra ricorrente %s-%s per %s',
            substr($this->slotStart, 0, 5),
            substr($this->slotEnd, 0, 5),
            $this->username
        );

        if ($this->columnExists('dap11_agenda_slot', 'id_stanza')) {
            $idStanza = (int)($location['id_stanza'] ?? 0);
            $stmt = $this->db->prepare("
                INSERT INTO `{$this->targetDb}`.dap11_agenda_slot
                (
                    id_dot, id_config, data_slot, ora_inizio, ora_fine, tipo_slot, stato,
                    titolo_libero, id_amb_legacy, ambulatorio, stanza, origine_slot, note_interne, id_stanza, created_at, updated_at
                )
                VALUES (
                    ?, ?, ?, ?, ?, 'AMBULATORIO', ?,
                    'EXTRA', NULLIF(?, 0), ?, ?, 'EXTRA', ?, NULLIF(?, 0), NOW(), NOW()
                )
            ");
            $stmt->bind_param(
                'iissssisssi',
                $idDot,
                $configId,
                $date,
                $slotStart,
                $slotEnd,
                $state,
                $idAmbLegacy,
                $ambulatorio,
                $stanza,
                $noteInterne,
                $idStanza
            );
            $stmt->execute();
            $stmt->close();
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO `{$this->targetDb}`.dap11_agenda_slot
            (
                id_dot, id_config, data_slot, ora_inizio, ora_fine, tipo_slot, stato,
                titolo_libero, id_amb_legacy, ambulatorio, stanza, origine_slot, note_interne, created_at, updated_at
            )
            VALUES (
                ?, ?, ?, ?, ?, 'AMBULATORIO', ?,
                'EXTRA', NULLIF(?, 0), ?, ?, 'EXTRA', ?, NOW(), NOW()
            )
        ");
        $stmt->bind_param(
            'iissssisss',
            $idDot,
            $configId,
            $date,
            $slotStart,
            $slotEnd,
            $state,
            $idAmbLegacy,
            $ambulatorio,
            $stanza,
            $noteInterne
        );
        $stmt->execute();
        $stmt->close();
    }

    private function tableExists(string $table): bool
    {
        $cacheKey = $this->targetDb . '.' . $table;
        if (isset($this->tableExistsCache[$cacheKey])) {
            return $this->tableExistsCache[$cacheKey];
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS c
            FROM information_schema.tables
            WHERE table_schema = ?
              AND table_name = ?
        ");
        $stmt->bind_param('ss', $this->targetDb, $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->tableExistsCache[$cacheKey] = (int)($row['c'] ?? 0) > 0;
        return $this->tableExistsCache[$cacheKey];
    }

    private function columnExists(string $table, string $column): bool
    {
        $cacheKey = $this->targetDb . '.' . $table . '.' . $column;
        if (isset($this->columnExistsCache[$cacheKey])) {
            return $this->columnExistsCache[$cacheKey];
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS c
            FROM information_schema.columns
            WHERE table_schema = ?
              AND table_name = ?
              AND column_name = ?
        ");
        $stmt->bind_param('sss', $this->targetDb, $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->columnExistsCache[$cacheKey] = (int)($row['c'] ?? 0) > 0;
        return $this->columnExistsCache[$cacheKey];
    }

    private function buildDoctorDayKey(int $idDot, string $date): string
    {
        return $idDot . '|' . $date;
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $row
     */
    private function pushExample(array &$summary, array $row): void
    {
        if (count($summary['examples']) >= 30) {
            return;
        }

        $summary['examples'][] = $row;
    }
}
