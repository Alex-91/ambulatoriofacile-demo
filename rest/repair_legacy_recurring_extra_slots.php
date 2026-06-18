<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    main($_SERVER['argv'] ?? []);
}

function main(array $argv): void
{
    $options = parseOptions($argv);
    if ($options['source_db'] === '') {
        throw new RuntimeException('Devi indicare esplicitamente --source-db=<db_legacy>.');
    }

    $env = loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');

    $host = (string)($env['database.default.hostname'] ?? '127.0.0.1');
    $port = (int)($env['database.default.port'] ?? 3306);
    $user = (string)($env['database.default.username'] ?? 'root');
    $pass = (string)($env['database.default.password'] ?? 'root');
    $targetDb = (string)($options['target_db'] ?: ($env['database.default.database'] ?? 'mail'));

    $db = new mysqli($host, $user, $pass, $targetDb, $port);
    $db->set_charset('utf8mb4');

    $repair = new LegacyRecurringExtraSlotRepair($db, (string)$options['source_db'], $targetDb, $options);
    $summary = $repair->run();

    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}

function parseOptions(array $argv): array
{
    return [
        'apply' => hasFlag($argv, '--apply'),
        'source_db' => (string)(optionValue($argv, 'source-db') ?? ''),
        'target_db' => (string)(optionValue($argv, 'target-db') ?? ''),
        'doctors' => parseCsvInts(optionValue($argv, 'doctors')),
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

function parseCsvInts(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }

    $out = [];
    foreach (preg_split('/[\s,;|]+/', trim($value)) ?: [] as $chunk) {
        $id = (int)$chunk;
        if ($id > 0) {
            $out[$id] = $id;
        }
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

final class LegacyRecurringExtraSlotRepair
{
    private mysqli $db;
    private string $sourceDb;
    private string $targetDb;
    private bool $apply;
    /** @var int[] */
    private array $doctorFilter;
    private string $dateFrom;
    private string $dateTo;
    /** @var array<string,bool> */
    private array $blockedDayKeys = [];
    /** @var array<string,int|null> */
    private array $configIdCache = [];

    public function __construct(mysqli $db, string $sourceDb, string $targetDb, array $options)
    {
        $this->db = $db;
        $this->sourceDb = $sourceDb;
        $this->targetDb = $targetDb;
        $this->apply = !empty($options['apply']);
        $this->doctorFilter = $options['doctors'] ?? [];
        $this->dateFrom = (string)$options['date_from'];
        $this->dateTo = (string)$options['date_to'];
    }

    public function run(): array
    {
        $this->assertTables();
        $this->loadBlockedDays();
        $rules = $this->loadRecurringExtraRules();

        $summary = [
            'mode' => $this->apply ? 'apply' : 'dry-run',
            'source_db' => $this->sourceDb,
            'target_db' => $this->targetDb,
            'doctor_filter' => $this->doctorFilter,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'rules' => count($rules),
            'dates_considered' => 0,
            'inserted_extra_slots' => 0,
            'preserved_overlapping_config_slots' => 0,
            'skipped_existing_exact_slots' => 0,
            'examples' => [],
        ];

        foreach ($rules as $rule) {
            $targetDoctorId = (int)$rule['target_id_dot'];
            foreach ($this->iterateRuleDates($rule) as $date) {
                $summary['dates_considered']++;

                $slotStart = $date . ' ' . $rule['ora_ini'] . ':00';
                $slotEnd = $date . ' ' . $rule['ora_fin'] . ':00';

                $overlappingConfigSlots = $this->loadOverlappingConfigSlots($targetDoctorId, $date, $slotStart, $slotEnd);
                $preservedOverlapIds = [];
                foreach ($overlappingConfigSlots as $slot) {
                    $isExact = (string)$slot['ora_inizio'] === $slotStart && (string)$slot['ora_fine'] === $slotEnd;
                    if ($isExact) {
                        continue;
                    }

                    $preservedOverlapIds[] = (int)$slot['id_slot'];
                }
                $summary['preserved_overlapping_config_slots'] += count($preservedOverlapIds);

                $exactSlot = $this->findExactSlot($targetDoctorId, $date, $slotStart, $slotEnd);
                if ($exactSlot !== null) {
                    $summary['skipped_existing_exact_slots']++;
                } else {
                    $state = isset($this->blockedDayKeys[$this->buildDoctorDayKey($targetDoctorId, $date)]) ? 'CHIUSO' : 'LIBERO';
                    $configId = $this->resolveConfigIdForSlot($targetDoctorId, $date);

                    if ($this->apply) {
                        $stmt = $this->db->prepare("
                            INSERT INTO `{$this->targetDb}`.dap11_agenda_slot
                            (
                                id_dot, id_config, data_slot, ora_inizio, ora_fine, tipo_slot, stato,
                                titolo_libero, id_amb_legacy, ambulatorio, stanza, origine_slot, note_interne, created_at, updated_at
                            )
                            VALUES (?, ?, ?, ?, ?, 'AMBULATORIO', ?, 'EXTRA', NULLIF(?, 0), ?, ?, 'EXTRA', ?, NOW(), NOW())
                        ");
                        $noteInterne = 'Sincronizzato da farmacia legacy - recurring extra far36 id_slot_extra=' . (int)$rule['id_slot_extra'];
                        $idAmbLegacy = (int)$rule['id_amb_legacy'];
                        $ambulatorio = (string)$rule['ambulatorio'];
                        $stanza = (string)$rule['stanza'];
                        $stmt->bind_param(
                            'iissssisss',
                            $targetDoctorId,
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

                    $summary['inserted_extra_slots']++;
                }

                if (count($summary['examples']) < 30 && ($preservedOverlapIds !== [] || $exactSlot === null)) {
                    $summary['examples'][] = [
                        'id_dot' => $targetDoctorId,
                        'date' => $date,
                        'extra' => $rule['ora_ini'] . '-' . $rule['ora_fin'],
                        'preserved_overlapping_config_slot_ids' => $preservedOverlapIds,
                        'inserted_extra' => $exactSlot === null,
                    ];
                }
            }
        }

        return $summary;
    }

    private function assertTables(): void
    {
        foreach (
            [
                [$this->sourceDb, 'far36_slot_extra'],
                [$this->targetDb, 'dap11_agenda_slot'],
                [$this->targetDb, 'dap10_agenda_config'],
            ] as [$schema, $table]
        ) {
            if (!$this->tableExists($schema, $table)) {
                throw new RuntimeException("Tabella mancante: {$schema}.{$table}");
            }
        }
    }

    private function loadBlockedDays(): void
    {
        if (!$this->tableExists($this->targetDb, 'dap21_agenda_giorni_bloccati')) {
            return;
        }

        $whereDoctor = $this->doctorFilter === []
            ? ''
            : ' AND id_dot IN (' . implode(',', array_map('intval', $this->doctorFilter)) . ')';
        $fromEscaped = $this->db->real_escape_string($this->dateFrom);
        $toEscaped = $this->db->real_escape_string($this->dateTo);

        $sql = "
            SELECT id_dot, data_agenda
            FROM `{$this->targetDb}`.dap21_agenda_giorni_bloccati
            WHERE data_agenda >= '{$fromEscaped}'
              AND data_agenda <= '{$toEscaped}'
              {$whereDoctor}
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $this->blockedDayKeys[$this->buildDoctorDayKey((int)$row['id_dot'], (string)$row['data_agenda'])] = true;
        }
    }

    private function loadRecurringExtraRules(): array
    {
        $whereDoctor = $this->doctorFilter === []
            ? ''
            : ' AND se.id_dot IN (' . implode(',', array_map('intval', $this->doctorFilter)) . ')';
        $fromEscaped = $this->db->real_escape_string($this->dateFrom);
        $toEscaped = $this->db->real_escape_string($this->dateTo);

        $hasAmbTable = $this->tableExists($this->sourceDb, 'far22_amb');
        $ambJoin = $hasAmbTable
            ? "LEFT JOIN `{$this->sourceDb}`.far22_amb amb ON amb.id_amb = se.id_amb"
            : '';
        $ambSelect = $hasAmbTable
            ? "COALESCE(amb.nome, '') AS ambulatorio"
            : "'' AS ambulatorio";

        $sql = "
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
            WHERE COALESCE(se.id_dot, 0) > 0
              AND COALESCE(se.id_giorno, 0) BETWEEN 1 AND 7
              {$whereDoctor}
            ORDER BY se.id_dot ASC, se.id_giorno ASC, STR_TO_DATE(NULLIF(se.data_ini, ''), '%d/%m/%Y') ASC, se.ora_ini ASC
        ";

        $rules = [];
        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $dateStart = $this->parseLegacyDate((string)($row['data_ini'] ?? ''));
            $dateEnd = $this->parseLegacyDate((string)($row['data_fin'] ?? ''));
            $oraIni = $this->normalizeTime((string)($row['ora_ini'] ?? ''));
            $oraFin = $this->normalizeTime((string)($row['ora_fin'] ?? ''));
            $sourceDoctorId = (int)($row['id_dot'] ?? 0);
            $targetDoctorId = $this->resolveTargetDoctorId($sourceDoctorId);

            if (
                $targetDoctorId === null
                || $dateStart === null
                || $dateEnd === null
                || $dateStart > $this->dateTo
                || $dateEnd < $this->dateFrom
                || $oraIni === ''
                || $oraFin === ''
                || $oraFin <= $oraIni
            ) {
                continue;
            }

            $rules[] = [
                'id_slot_extra' => (int)$row['id_slot_extra'],
                'source_id_dot' => $sourceDoctorId,
                'target_id_dot' => $targetDoctorId,
                'id_giorno' => (int)$row['id_giorno'],
                'ora_ini' => substr($oraIni, 0, 5),
                'ora_fin' => substr($oraFin, 0, 5),
                'id_amb_legacy' => (int)($row['id_amb_legacy'] ?? 0),
                'ambulatorio' => trim((string)($row['ambulatorio'] ?? '')),
                'stanza' => trim((string)($row['stanza'] ?? '')),
                'date_start' => max($dateStart, $this->dateFrom),
                'date_end' => min($dateEnd, $this->dateTo),
            ];
        }

        return $rules;
    }

    private function iterateRuleDates(array $rule): Generator
    {
        $cursor = new DateTime((string)$rule['date_start']);
        $end = new DateTime((string)$rule['date_end']);
        $weekday = (int)$rule['id_giorno'];

        while ((int)$cursor->format('N') !== $weekday) {
            $cursor->modify('+1 day');
            if ($cursor > $end) {
                return;
            }
        }

        while ($cursor <= $end) {
            yield $cursor->format('Y-m-d');
            $cursor->modify('+7 days');
        }
    }

    private function loadOverlappingConfigSlots(int $idDot, string $date, string $slotStart, string $slotEnd): array
    {
        $stmt = $this->db->prepare("
            SELECT
                s.id_slot,
                s.ora_inizio,
                s.ora_fine
            FROM `{$this->targetDb}`.dap11_agenda_slot s
            WHERE s.id_dot = ?
              AND s.data_slot = ?
              AND s.origine_slot = 'CONFIG'
              AND s.ora_inizio < ?
              AND s.ora_fine > ?
            ORDER BY s.ora_inizio ASC, s.ora_fine ASC, s.id_slot ASC
        ");
        $stmt->bind_param('isss', $idDot, $date, $slotEnd, $slotStart);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    private function findExactSlot(int $idDot, string $date, string $slotStart, string $slotEnd): ?array
    {
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

    private function resolveConfigIdForSlot(int $idDot, string $date): int
    {
        $cacheKey = $this->buildDoctorDayKey($idDot, $date);
        if (array_key_exists($cacheKey, $this->configIdCache)) {
            return (int)($this->configIdCache[$cacheKey] ?? 0);
        }

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
        $stmt->bind_param('iss', $idDot, $date, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->configIdCache[$cacheKey] = $row ? (int)$row['id_config'] : 0;
        return (int)$this->configIdCache[$cacheKey];
    }

    private function resolveTargetDoctorId(int $sourceDoctorId): ?int
    {
        if ($sourceDoctorId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS c
            FROM `{$this->targetDb}`.dap10_agenda_config
            WHERE id_dot = ?
        ");
        $stmt->bind_param('i', $sourceDoctorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['c'] ?? 0) > 0 ? $sourceDoctorId : null;
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

    private function buildDoctorDayKey(int $idDot, string $date): string
    {
        return $idDot . '|' . $date;
    }
}
