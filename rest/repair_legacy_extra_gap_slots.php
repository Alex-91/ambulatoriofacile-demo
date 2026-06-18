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
    $dbName = (string)($options['target_db'] ?? ($env['database.default.database'] ?? 'mail'));

    $db = new mysqli($host, $user, $pass, $dbName, $port);
    $db->set_charset('utf8mb4');

    $repair = new LegacyExtraGapSlotRepair($db, $dbName, $options);
    $summary = $repair->run();

    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}

function parseOptions(array $argv): array
{
    return [
        'apply' => hasFlag($argv, '--apply'),
        'doctors' => parseCsvInts(optionValue($argv, 'doctors')),
        'date_from' => normalizeDate(optionValue($argv, 'date-from')),
        'date_to' => normalizeDate(optionValue($argv, 'date-to')),
        'target_db' => optionValue($argv, 'target-db'),
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

final class LegacyExtraGapSlotRepair
{
    private mysqli $db;
    private string $dbName;
    private bool $apply;
    /** @var int[] */
    private array $doctorFilter;
    private ?string $dateFrom;
    private ?string $dateTo;
    /** @var array<string,bool> */
    private array $blockedDayKeys = [];
    /** @var array<string,int|null> */
    private array $configCache = [];

    public function __construct(mysqli $db, string $dbName, array $options)
    {
        $this->db = $db;
        $this->dbName = $dbName;
        $this->apply = !empty($options['apply']);
        $this->doctorFilter = $options['doctors'] ?? [];
        $this->dateFrom = $options['date_from'] ?? null;
        $this->dateTo = $options['date_to'] ?? null;
    }

    public function run(): array
    {
        $this->loadBlockedDays();
        $candidates = $this->loadCandidates();

        $summary = [
            'mode' => $this->apply ? 'apply' : 'dry-run',
            'target_db' => $this->dbName,
            'doctor_filter' => $this->doctorFilter,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'candidates' => count($candidates),
            'inserted' => 0,
            'skipped_existing' => 0,
            'per_doctor' => [],
            'examples' => [],
        ];

        foreach ($candidates as $candidate) {
            $slotKey = $this->buildSlotKey((int)$candidate['id_dot'], (string)$candidate['gap_start'], (string)$candidate['gap_end']);
            if ($this->slotExists($slotKey, (int)$candidate['id_dot'], (string)$candidate['data_slot'], (string)$candidate['gap_start'], (string)$candidate['gap_end'])) {
                $summary['skipped_existing']++;
                continue;
            }

            $idDot = (int)$candidate['id_dot'];
            $dayKey = $this->buildDoctorDayKey($idDot, (string)$candidate['data_slot']);
            $slotState = isset($this->blockedDayKeys[$dayKey]) ? 'CHIUSO' : 'LIBERO';
            $configId = (int)($candidate['id_config'] ?? 0);
            if ($configId <= 0) {
                $configId = (int)($this->resolveConfigIdForSlot($idDot, (string)$candidate['data_slot']) ?? 0);
            }

            if ($this->apply) {
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->dbName}`.dap11_agenda_slot
                    (
                        id_dot, id_config, data_slot, ora_inizio, ora_fine, tipo_slot, stato,
                        titolo_libero, id_amb_legacy, ambulatorio, stanza, origine_slot, note_interne, created_at, updated_at
                    )
                    VALUES (?, ?, ?, ?, ?, 'AMBULATORIO', ?, NULL, NULLIF(?, 0), ?, ?, 'EXTRA', ?, NOW(), NOW())
                ");
                $noteInterne = 'Slot libero inferito tra appuntamenti legacy extra contigui';
                $stmt->bind_param(
                    'iissssisss',
                    $idDot,
                    $configId,
                    $candidate['data_slot'],
                    $candidate['gap_start'],
                    $candidate['gap_end'],
                    $slotState,
                    $candidate['id_amb_legacy'],
                    $candidate['ambulatorio'],
                    $candidate['stanza'],
                    $noteInterne
                );
                $stmt->execute();
                $stmt->close();
            }

            $summary['inserted']++;
            $summary['per_doctor'][(string)$idDot] = (int)($summary['per_doctor'][(string)$idDot] ?? 0) + 1;
            if (count($summary['examples']) < 20) {
                $summary['examples'][] = [
                    'id_dot' => $idDot,
                    'data_slot' => $candidate['data_slot'],
                    'ora_inizio' => $candidate['gap_start'],
                    'ora_fine' => $candidate['gap_end'],
                    'stato' => $slotState,
                ];
            }
        }

        ksort($summary['per_doctor']);
        return $summary;
    }

    private function loadCandidates(): array
    {
        $filters = [];
        if ($this->doctorFilter !== []) {
            $filters[] = 's1.id_dot IN (' . implode(',', array_map('intval', $this->doctorFilter)) . ')';
        }
        if ($this->dateFrom !== null) {
            $filters[] = "s1.data_slot >= '" . $this->db->real_escape_string($this->dateFrom) . "'";
        }
        if ($this->dateTo !== null) {
            $filters[] = "s1.data_slot <= '" . $this->db->real_escape_string($this->dateTo) . "'";
        }

        $whereExtra = $filters === [] ? '' : ' AND ' . implode(' AND ', $filters);

        $sql = "
            SELECT
                s1.id_dot,
                COALESCE(NULLIF(s1.id_config, 0), NULLIF(s2.id_config, 0), 0) AS id_config,
                s1.data_slot,
                s1.ora_fine AS gap_start,
                DATE_ADD(s1.ora_fine, INTERVAL TIMESTAMPDIFF(MINUTE, s1.ora_inizio, s1.ora_fine) MINUTE) AS gap_end,
                TIMESTAMPDIFF(MINUTE, s1.ora_inizio, s1.ora_fine) AS slot_dur,
                COALESCE(s1.id_amb_legacy, 0) AS id_amb_legacy,
                COALESCE(s1.ambulatorio, '') AS ambulatorio,
                COALESCE(s1.stanza, '') AS stanza
            FROM `{$this->dbName}`.dap11_agenda_slot s1
            INNER JOIN `{$this->dbName}`.dap11_agenda_slot s2
                ON s2.id_dot = s1.id_dot
               AND s2.data_slot = s1.data_slot
               AND COALESCE(s2.id_amb_legacy, 0) = COALESCE(s1.id_amb_legacy, 0)
               AND COALESCE(s2.ambulatorio, '') = COALESCE(s1.ambulatorio, '')
               AND COALESCE(s2.stanza, '') = COALESCE(s1.stanza, '')
               AND s2.ora_inizio > s1.ora_inizio
            LEFT JOIN `{$this->dbName}`.dap11_agenda_slot sx
                ON sx.id_dot = s1.id_dot
               AND sx.data_slot = s1.data_slot
               AND COALESCE(sx.id_amb_legacy, 0) = COALESCE(s1.id_amb_legacy, 0)
               AND COALESCE(sx.ambulatorio, '') = COALESCE(s1.ambulatorio, '')
               AND COALESCE(sx.stanza, '') = COALESCE(s1.stanza, '')
               AND sx.ora_inizio > s1.ora_inizio
               AND sx.ora_inizio < s2.ora_inizio
            WHERE s1.origine_slot = 'EXTRA'
              AND s2.origine_slot = 'EXTRA'
              AND s1.titolo_libero = 'MIGRATO_LEGACY'
              AND s2.titolo_libero = 'MIGRATO_LEGACY'
              AND sx.id_slot IS NULL
              AND TIMESTAMPDIFF(MINUTE, s1.ora_fine, s2.ora_inizio) = TIMESTAMPDIFF(MINUTE, s1.ora_inizio, s1.ora_fine)
              AND TIMESTAMPDIFF(MINUTE, s2.ora_inizio, s2.ora_fine) = TIMESTAMPDIFF(MINUTE, s1.ora_inizio, s1.ora_fine)
              {$whereExtra}
            ORDER BY s1.data_slot ASC, s1.id_dot ASC, s1.ora_fine ASC
        ";

        $rows = [];
        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'id_dot' => (int)($row['id_dot'] ?? 0),
                'id_config' => (int)($row['id_config'] ?? 0),
                'data_slot' => (string)($row['data_slot'] ?? ''),
                'gap_start' => (string)($row['gap_start'] ?? ''),
                'gap_end' => (string)($row['gap_end'] ?? ''),
                'slot_dur' => (int)($row['slot_dur'] ?? 0),
                'id_amb_legacy' => (int)($row['id_amb_legacy'] ?? 0),
                'ambulatorio' => (string)($row['ambulatorio'] ?? ''),
                'stanza' => (string)($row['stanza'] ?? ''),
            ];
        }
        $res->close();

        return $rows;
    }

    private function loadBlockedDays(): void
    {
        $filters = [];
        if ($this->doctorFilter !== []) {
            $filters[] = 'id_dot IN (' . implode(',', array_map('intval', $this->doctorFilter)) . ')';
        }
        if ($this->dateFrom !== null) {
            $filters[] = "data_agenda >= '" . $this->db->real_escape_string($this->dateFrom) . "'";
        }
        if ($this->dateTo !== null) {
            $filters[] = "data_agenda <= '" . $this->db->real_escape_string($this->dateTo) . "'";
        }

        $where = $filters === [] ? '' : ' WHERE ' . implode(' AND ', $filters);
        $sql = "SELECT id_dot, data_agenda FROM `{$this->dbName}`.dap21_agenda_giorni_bloccati{$where}";
        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $this->blockedDayKeys[$this->buildDoctorDayKey((int)$row['id_dot'], (string)$row['data_agenda'])] = true;
        }
        $res->close();
    }

    private function slotExists(string $slotKey, int $idDot, string $dataSlot, string $startDateTime, string $endDateTime): bool
    {
        static $cache = [];
        if (isset($cache[$slotKey])) {
            return true;
        }

        $stmt = $this->db->prepare("
            SELECT id_slot
            FROM `{$this->dbName}`.dap11_agenda_slot
            WHERE id_dot = ?
              AND data_slot = ?
              AND ora_inizio = ?
              AND ora_fine = ?
            LIMIT 1
        ");
        $stmt->bind_param('isss', $idDot, $dataSlot, $startDateTime, $endDateTime);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $cache[$slotKey] = true;
            return true;
        }

        return false;
    }

    private function resolveConfigIdForSlot(int $idDot, string $date): ?int
    {
        $cacheKey = $this->buildDoctorDayKey($idDot, $date);
        if (array_key_exists($cacheKey, $this->configCache)) {
            return $this->configCache[$cacheKey];
        }

        $stmt = $this->db->prepare("
            SELECT id_config
            FROM `{$this->dbName}`.dap10_agenda_config
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

        $this->configCache[$cacheKey] = $row ? (int)$row['id_config'] : null;
        return $this->configCache[$cacheKey];
    }

    private function buildDoctorDayKey(int $idDot, string $date): string
    {
        return $idDot . '|' . $date;
    }

    private function buildSlotKey(int $idDot, string $startDateTime, string $endDateTime): string
    {
        return $idDot . '|' . $startDateTime . '|' . $endDateTime;
    }
}
