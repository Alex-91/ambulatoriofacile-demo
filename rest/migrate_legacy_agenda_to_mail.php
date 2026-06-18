<?php
declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '1024M');
ignore_user_abort(true);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const DEFAULT_SOURCE_DB = '';
const DEFAULT_TARGET_DB = 'mail';
const DEFAULT_BLOCKED_FROM = '2018-01-01';
const DEFAULT_NOTES_FROM = '2018-01-01';
const LEGACY_OPEN_ENDED_DATE = '2039-12-31';
const WEB_APPLY_CONFIRM_PHRASE = 'APPLICA_SU_MAIL';

bootstrapRequest();

function bootstrapRequest(): void
{
    if (isCliRequest()) {
        main($_SERVER['argv'] ?? []);
        return;
    }

    $request = getWebRequestData();
    $argv = buildWebArgvFromRequest($request);
    if ($argv === null) {
        renderWebUsage();
        return;
    }

    $isApply = in_array('--apply', $argv, true);
    if ($isApply) {
        if (!isLocalWebRequest()) {
            renderWebPlainText("Apply da browser consentito solo da localhost.\n");
            return;
        }

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            renderWebPlainText("Apply da browser consentito solo via POST.\n");
            return;
        }

        $confirmPhrase = trim((string)($request['confirm_phrase'] ?? ''));
        if ($confirmPhrase !== WEB_APPLY_CONFIRM_PHRASE) {
            renderWebPlainText(
                "Conferma non valida.\n" .
                "Per applicare davvero da browser devi inviare confirm_phrase=" . WEB_APPLY_CONFIRM_PHRASE . "\n"
            );
            return;
        }
    } elseif (!headers_sent()) {
        header('Content-Type: text/plain; charset=UTF-8');
    } else {
        return;
    }

    main($argv);
}

function main(array $argv): void
{
    $options = parseCliOptions($argv);
    validateSourceDbOption((string)($options['source_db'] ?? ''));
    $env = loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');
    $dbConfig = buildDbConfig($env, $options);

    $reportDir = (string)$options['report_dir'];
    ensureDirectory($reportDir);

    $stamp = date('Ymd_His');
    $logPath = $reportDir . DIRECTORY_SEPARATOR . 'agenda_merge_' . $stamp . '.log';
    $reportPath = $reportDir . DIRECTORY_SEPARATOR . 'agenda_merge_' . $stamp . '.json';

    $logger = new CliLogger($logPath);
    $logger->info('Avvio script migrazione agenda legacy', [
        'mode' => !empty($options['apply']) ? 'apply' : 'dry-run',
        'source_db' => $dbConfig['source_db'],
        'target_db' => $dbConfig['target_db'],
        'appointments_from' => $options['appointments_from'],
        'blocked_from' => $options['blocked_from'],
        'notes_from' => $options['notes_from'],
        'structure_from' => $options['structure_from'],
        'structure_to' => $options['structure_to'],
        'doctor_filter' => $options['doctors'],
    ]);

    $script = new AgendaLegacyAgendaMerger($dbConfig, $options, $logger, $reportPath);
    $exitCode = $script->run();
    exit($exitCode);
}

function isCliRequest(): bool
{
    return PHP_SAPI === 'cli';
}

function isLocalWebRequest(): bool
{
    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return in_array($remoteAddr, ['127.0.0.1', '::1'], true);
}

function getWebRequestData(): array
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    return $method === 'POST' ? $_POST : $_GET;
}

function buildWebArgvFromRequest(array $request): ?array
{
    if (!isset($request['run']) || (string)$request['run'] !== '1') {
        return null;
    }

    $argv = ['migrate_legacy_agenda_to_mail.php'];
    if (!empty($request['apply']) && (string)$request['apply'] === '1') {
        $argv[] = '--apply';
    }

    $map = [
        'host',
        'port',
        'user',
        'pass',
        'source-db',
        'target-db',
        'appointments-from',
        'blocked-from',
        'notes-from',
        'structure-from',
        'structure-to',
        'doctors',
        'report-dir',
        'rebuild-structure',
        'drop-target-far15',
        'reset-target-agenda',
    ];

    foreach ($map as $name) {
        if (!isset($request[$name])) {
            continue;
        }

        $value = trim((string)$request[$name]);
        if ($value === '') {
            continue;
        }

        $argv[] = '--' . $name . '=' . $value;
    }

    return $argv;
}

function renderWebPlainText(string $text): void
{
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=UTF-8');
    }

    echo $text;
}

function renderWebUsage(): void
{
    $self = $_SERVER['PHP_SELF'] ?? 'migrate_legacy_agenda_to_mail.php';
    $base = $self !== '' ? $self : 'migrate_legacy_agenda_to_mail.php';
    $html = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Migrazione Agenda Legacy</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; background: #f6f7f9; color: #1f2937; }
        h1 { margin-top: 0; }
        .box { background: #fff; border: 1px solid #d1d5db; border-radius: 8px; padding: 16px; margin-bottom: 18px; max-width: 900px; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(220px, 1fr)); gap: 12px 16px; }
        label { display: block; font-weight: 600; margin-bottom: 4px; }
        input[type="text"] { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; }
        .actions { margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
        button { padding: 10px 14px; border: 0; border-radius: 6px; cursor: pointer; }
        .dry { background: #0f766e; color: #fff; }
        .apply { background: #b91c1c; color: #fff; }
        code { background: #eef2ff; padding: 2px 6px; border-radius: 4px; }
        p { line-height: 1.45; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Migrazione agenda legacy -> mail</h1>
        <p>Da questa pagina puoi eseguire lo script dal browser. I log e i report vengono salvati in <code>writable/agenda_merge</code>.</p>
        <p>Lo script non usa piu nessun database legacy in modo implicito: <code>source-db</code> va indicato esplicitamente solo per una migrazione una tantum.</p>
        <p>La modalita <code>apply</code> scrive davvero su <code>mail</code>. Usala solo quando hai gia validato il dry-run.</p>
    </div>

    <div class="box">
        <h2>Dry-run</h2>
        <form method="get" action="{$base}">
            <input type="hidden" name="run" value="1">
            <div class="grid">
                <div>
                    <label for="source-db">source-db</label>
                    <input id="source-db" type="text" name="source-db" value="" placeholder="es. farmacia_dump_locale">
                </div>
                <div>
                    <label for="appointments-from">appointments-from</label>
                    <input id="appointments-from" type="text" name="appointments-from" value="2026-05-20">
                </div>
                <div>
                    <label for="blocked-from">blocked-from</label>
                    <input id="blocked-from" type="text" name="blocked-from" value="2026-01-01">
                </div>
                <div>
                    <label for="notes-from">notes-from</label>
                    <input id="notes-from" type="text" name="notes-from" value="2026-01-01">
                </div>
                <div>
                    <label for="structure-from">structure-from</label>
                    <input id="structure-from" type="text" name="structure-from" value="2025-12-01">
                </div>
                <div>
                    <label for="structure-to">structure-to</label>
                    <input id="structure-to" type="text" name="structure-to" value="2027-12-31">
                </div>
                <div>
                    <label for="doctors">doctors</label>
                    <input id="doctors" type="text" name="doctors" value="" placeholder="es. 1,2,3">
                </div>
            </div>
            <div class="actions">
                <button class="dry" type="submit">Esegui dry-run</button>
            </div>
        </form>
    </div>

    <div class="box">
        <h2>Apply reale</h2>
        <p>Disponibile solo da <code>localhost</code>, solo via <code>POST</code> e con conferma esplicita.</p>
        <form method="post" action="{$base}">
            <input type="hidden" name="run" value="1">
            <input type="hidden" name="apply" value="1">
            <div class="grid">
                <div>
                    <label for="apply-source-db">source-db</label>
                    <input id="apply-source-db" type="text" name="source-db" value="" placeholder="es. farmacia_dump_locale">
                </div>
                <div>
                    <label for="apply-appointments-from">appointments-from</label>
                    <input id="apply-appointments-from" type="text" name="appointments-from" value="2026-05-20">
                </div>
                <div>
                    <label for="apply-blocked-from">blocked-from</label>
                    <input id="apply-blocked-from" type="text" name="blocked-from" value="2026-01-01">
                </div>
                <div>
                    <label for="apply-notes-from">notes-from</label>
                    <input id="apply-notes-from" type="text" name="notes-from" value="2026-01-01">
                </div>
                <div>
                    <label for="apply-structure-from">structure-from</label>
                    <input id="apply-structure-from" type="text" name="structure-from" value="2025-12-01">
                </div>
                <div>
                    <label for="apply-structure-to">structure-to</label>
                    <input id="apply-structure-to" type="text" name="structure-to" value="2027-12-31">
                </div>
                <div>
                    <label for="apply-doctors">doctors</label>
                    <input id="apply-doctors" type="text" name="doctors" value="" placeholder="es. 1,2,3">
                </div>
                <div>
                    <label for="confirm-phrase">confirm_phrase</label>
                    <input id="confirm-phrase" type="text" name="confirm_phrase" value="" placeholder="__CONFIRM_PHRASE__">
                </div>
            </div>
            <p>Per confermare devi scrivere esattamente: <code>APPLICA_SU_MAIL</code></p>
            <div class="actions">
                <button class="apply" type="submit">Esegui apply reale</button>
            </div>
        </form>
    </div>
</body>
</html>
HTML;

    $html = str_replace('__CONFIRM_PHRASE__', WEB_APPLY_CONFIRM_PHRASE, $html);

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }

    echo $html;
}

function parseCliOptions(array $argv): array
{
    $appointmentsFrom = optionValue($argv, 'appointments-from') ?: date('Y-m-d');
    $blockedFrom = optionValue($argv, 'blocked-from') ?: DEFAULT_BLOCKED_FROM;
    $notesFrom = optionValue($argv, 'notes-from') ?: DEFAULT_NOTES_FROM;
    $structureFrom = optionValue($argv, 'structure-from');
    $structureTo = optionValue($argv, 'structure-to');
    $reportDir = optionValue($argv, 'report-dir')
        ?: (__DIR__ . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'agenda_merge');

    validateDateOption($appointmentsFrom, 'appointments-from');
    validateDateOption($blockedFrom, 'blocked-from');
    validateDateOption($notesFrom, 'notes-from');
    if ($structureFrom !== null && trim($structureFrom) !== '') {
        validateDateOption($structureFrom, 'structure-from');
    } else {
        $structureFrom = null;
    }
    if ($structureTo !== null && trim($structureTo) !== '') {
        validateDateOption($structureTo, 'structure-to');
    } else {
        $structureTo = null;
    }

    return [
        'apply' => hasFlag($argv, '--apply'),
        'rebuild_structure' => hasFlag($argv, '--rebuild-structure') || optionValue($argv, 'rebuild-structure') === '1',
        'drop_target_far15' => hasFlag($argv, '--drop-target-far15') || optionValue($argv, 'drop-target-far15') === '1',
        'reset_target_agenda' => hasFlag($argv, '--reset-target-agenda') || optionValue($argv, 'reset-target-agenda') === '1',
        'host' => optionValue($argv, 'host'),
        'port' => (int)(optionValue($argv, 'port') ?: 3306),
        'user' => optionValue($argv, 'user'),
        'pass' => optionValue($argv, 'pass'),
        'source_db' => optionValue($argv, 'source-db') ?: DEFAULT_SOURCE_DB,
        'target_db' => optionValue($argv, 'target-db') ?: DEFAULT_TARGET_DB,
        'appointments_from' => $appointmentsFrom,
        'blocked_from' => $blockedFrom,
        'notes_from' => $notesFrom,
        'structure_from' => $structureFrom,
        'structure_to' => $structureTo,
        'doctors' => parseCsvInts(optionValue($argv, 'doctors')),
        'report_dir' => $reportDir,
    ];
}

function validateSourceDbOption(string $sourceDb): void
{
    if (trim($sourceDb) === '') {
        throw new RuntimeException('Devi indicare esplicitamente --source-db=<dump_legacy_locale>. Nessun collegamento implicito a farmacia e consentito.');
    }
}

function validateDateOption(string $value, string $name): void
{
    $dt = \DateTime::createFromFormat('Y-m-d', $value);
    $valid = $dt instanceof \DateTime && $dt->format('Y-m-d') === $value;
    if (!$valid) {
        throw new RuntimeException("Valore non valido per --{$name}: usa il formato YYYY-MM-DD");
    }
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

    $ids = [];
    foreach (preg_split('/[\s,;|]+/', trim($value)) ?: [] as $chunk) {
        $id = (int)$chunk;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    ksort($ids);
    return array_values($ids);
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

function buildDbConfig(array $env, array $options): array
{
    return [
        'host' => (string)($options['host'] ?: ($env['database.default.hostname'] ?? '127.0.0.1')),
        'port' => (int)($options['port'] ?: ($env['database.default.port'] ?? 3306)),
        'user' => (string)($options['user'] ?: ($env['database.default.username'] ?? 'root')),
        'pass' => (string)($options['pass'] ?: ($env['database.default.password'] ?? 'root')),
        'source_db' => (string)$options['source_db'],
        'target_db' => (string)$options['target_db'],
        'encryption_key' => (string)($env['DB_ENCRYPTION_KEY'] ?? 'PartitaIVA22'),
    ];
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("Impossibile creare la directory {$path}");
    }
}

final class CliLogger
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context, false);
    }

    private function write(string $level, string $message, array $context = [], bool $echo = true): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] {$level} {$message}";
        if ($context !== []) {
            $line .= ' ' . json_encode(
                $context,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
        }

        file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND);
        if ($echo) {
            if (isCliRequest()) {
                fwrite(STDOUT, $line . PHP_EOL);
            } else {
                echo $line . PHP_EOL;
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
        }
    }
}

final class AgendaLegacyAgendaMerger
{
    private const TECHNICAL_APPOINTMENT_ALIASES = [
        'INFO|INFORMATRICE' => 'INFO|INFORMATORE',
    ];

    private const TECHNICAL_APPOINTMENT_LABELS = [
        'DDD|DISTANZIAMENTO' => ['cognome' => 'DDD', 'nome' => 'DISTANZIAMENTO'],
        'URG|URGENTE' => ['cognome' => 'URG', 'nome' => 'URGENTE'],
        'STOP|STOP' => ['cognome' => 'STOP', 'nome' => 'STOP'],
        'INFO|INFORMATORE' => ['cognome' => 'INFO', 'nome' => 'INFORMATORE'],
        'CER|CERTIFICATI' => ['cognome' => 'CER', 'nome' => 'CERTIFICATI'],
        'ESA|ESAMI' => ['cognome' => 'ESA', 'nome' => 'ESAMI'],
        'DOT|DOTTORE' => ['cognome' => 'DOT', 'nome' => 'DOTTORE'],
    ];

    private mysqli $db;
    private ?mysqli $writeDb = null;
    private array $dbConfig;
    private array $options;
    private CliLogger $logger;
    private string $reportPath;
    private string $sourceDb;
    private string $targetDb;
    private bool $apply;
    private string $appointmentsFrom;
    private string $blockedFrom;
    private string $notesFrom;
    private string $structureFrom = '';
    private string $structureTo = '';
    /** @var int[] */
    private array $doctorFilter = [];
    private array $report = [];

    private array $sourceDoctorsBySchema = [];
    private array $sourceOperatorsBySchema = [];
    private array $targetDoctorsByUsername = [];
    private array $targetOperatorsByUsername = [];
    private array $targetOperatorsById = [];
    private array $targetLoginUsersByUsername = [];
    private array $targetPatientsByCf = [];
    private array $targetPatientsByDoctorCf = [];
    private array $targetPatientsByDoctorTriad = [];
    private array $targetPatientsByTriad = [];
    private array $targetPatientsByDoctorPhone = [];
    private array $targetPatientIds = [];
    private array $targetPatientDoctorById = [];
    private array $targetClientsById = [];
    private array $targetClientsByLegacyPatientId = [];
    private array $targetTechnicalClientsByKey = [];
    private array $targetClientsByCf = [];
    private array $targetClientsByDoctorCf = [];
    private array $targetClientsByDoctorTriad = [];
    private array $targetClientsByDoctorEmail = [];
    private array $targetClientByUserId = [];
    private array $targetClientDoctorById = [];
    private array $targetClientDoctorIdsById = [];
    private array $agendaDoctorIdByPersonaleId = [];
    private array $personaleIdByAgendaDoctorId = [];
    private array $targetPersonaleLegacyDoctorTypeById = [];
    private array $targetPersonaleFamilyFlagById = [];
    private array $targetRegisteredClientsByUsername = [];
    private array $targetSlotsByKey = [];
    private array $targetBlockedDayKeys = [];
    private array $targetMemoBlockedDayKeys = [];
    private array $targetDomiciliariBlockedDayKeys = [];
    private array $targetDailyNotesByDay = [];
    private array $targetDailyNotesByExactKey = [];
    private array $activeAppointmentDayCount = [];
    private array $targetConfigCache = [];
    private array $targetActiveConfigsByDoctor = [];
    private array $targetActiveConfigIntervalsByDoctor = [];
    private array $targetAgendaVisibilityKeys = [];
    private array $targetAgendaNotesByLegacyId = [];
    private array $targetAgendaNotesBySignature = [];
    private array $targetDomiciliariByLegacyId = [];
    private array $targetDomiciliariBySignature = [];
    private array $patientResolutionCache = [];
    private int $dryRunOperatorId = -1;
    private int $dryRunConfigId = -1;
    private int $dryRunConfigDayId = -1;
    private int $dryRunSlotId = -1;
    private int $dryRunClientId = -1;
    private bool $targetAgendaAppointmentsHasIdClient = false;
    private bool $targetAgendaNotesHasIdClient = false;
    private bool $targetAgendaNotesHasLegacyId = false;
    private bool $targetDomiciliariHasIdClient = false;
    private bool $targetDomiciliariHasLegacyId = false;
    private bool $targetDomiciliariHasGiornoVisita = false;
    private bool $targetClientsHasLegacyPatientBridge = false;
    private string $encryptionKey = '';
    private bool $rebuildStructure = false;
    private bool $dropTargetFar15 = false;
    private bool $resetTargetAgenda = false;
    private array $legacyDurationHintsByDoctorDateDay = [];
    private array $structureRebindAppointments = [];

    public function __construct(array $dbConfig, array $options, CliLogger $logger, string $reportPath)
    {
        $this->dbConfig = $dbConfig;
        $this->options = $options;
        $this->logger = $logger;
        $this->reportPath = $reportPath;
        $this->sourceDb = (string)$dbConfig['source_db'];
        $this->targetDb = (string)$dbConfig['target_db'];
        $this->apply = !empty($options['apply']);
        $this->appointmentsFrom = (string)$options['appointments_from'];
        $this->blockedFrom = (string)$options['blocked_from'];
        $this->notesFrom = (string)$options['notes_from'];
        $this->structureFrom = (string)($options['structure_from'] ?? '');
        $this->structureTo = (string)($options['structure_to'] ?? '');
        $this->doctorFilter = $options['doctors'];
        $this->encryptionKey = (string)($dbConfig['encryption_key'] ?? 'PartitaIVA22');
        $this->rebuildStructure = !empty($options['rebuild_structure']);
        $this->dropTargetFar15 = !empty($options['drop_target_far15']);
        $this->resetTargetAgenda = !empty($options['reset_target_agenda']);
    }

    public function run(): int
    {
        try {
            $this->db = new mysqli(
                (string)$this->dbConfig['host'],
                (string)$this->dbConfig['user'],
                (string)$this->dbConfig['pass'],
                $this->targetDb,
                (int)$this->dbConfig['port']
            );
            $this->db->set_charset('latin1');
            if ($this->apply) {
                $this->writeDb = new mysqli(
                    (string)$this->dbConfig['host'],
                    (string)$this->dbConfig['user'],
                    (string)$this->dbConfig['pass'],
                    $this->targetDb,
                    (int)$this->dbConfig['port']
                );
                $this->writeDb->set_charset('latin1');
                $this->initializeEncryptionSessionOn($this->writeDb);
            }
            $this->initializeEncryptionSession();
            $this->initializeStructureWindow();

            $this->report = [
                'started_at' => date('c'),
                'mode' => $this->apply ? 'apply' : 'dry-run',
                'source_db' => $this->sourceDb,
                'target_db' => $this->targetDb,
                'log_path' => $this->logger->path(),
                'report_path' => $this->reportPath,
                'options' => [
                    'appointments_from' => $this->appointmentsFrom,
                    'blocked_from' => $this->blockedFrom,
                    'notes_from' => $this->notesFrom,
                    'structure_from' => $this->structureFrom,
                    'structure_to' => $this->structureTo,
                    'doctors' => $this->doctorFilter,
                    'rebuild_structure' => $this->rebuildStructure,
                    'drop_target_far15' => $this->dropTargetFar15,
                    'reset_target_agenda' => $this->resetTargetAgenda,
                ],
                'audit' => [],
                'migrations' => [],
            ];

            if ($this->resetTargetAgenda) {
                $this->resetEntireTargetAgenda();
            }

            $this->warmUpCaches();
            $this->auditDoctors();
            $this->auditOperators();
            $this->auditPatients();
            $this->auditLegacySources();

            $this->migrateOperatorsAndAgendaVisibility();
            $this->migrateAgendaStructure();
            $this->migrateDailyNotes();
            $this->migrateAgendaMemoNotes();
            $this->migrateMemoBlockedDays();
            $this->migrateDomiciliari();
            $this->migrateDomiciliariBlockedDays();
            $this->migrateAppointments();
            $this->backfillLegacyExtraGapSlots();
            $this->backfillTechnicalAppointmentClients();
            $this->migrateBlockedDays();
            $this->auditUnresolvedLegacyAreas();

            $this->report['finished_at'] = date('c');
            $this->report['status'] = 'ok';
            $this->writeReport();

            $this->logger->info('Script completato', [
                'report_path' => $this->reportPath,
                'log_path' => $this->logger->path(),
            ]);

            if ($this->writeDb instanceof mysqli) {
                $this->writeDb->close();
                $this->writeDb = null;
            }
            $this->db->close();
            return 0;
        } catch (\Throwable $e) {
            $this->report['finished_at'] = date('c');
            $this->report['status'] = 'error';
            $this->report['error'] = [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
            $this->writeReport();
            $this->logger->error('Script terminato con errore', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'report_path' => $this->reportPath,
            ]);

            if (isset($this->db) && $this->db instanceof mysqli) {
                $this->db->close();
            }
            if ($this->writeDb instanceof mysqli) {
                $this->writeDb->close();
                $this->writeDb = null;
            }

            return 1;
        }
    }

    private function warmUpCaches(): void
    {
        $this->logger->info('Carico cache iniziali');
        $this->loadOperatorsForSchema($this->sourceDb);
        $this->loadDoctorsForSchema($this->sourceDb);
        if ($this->sourceDb !== $this->targetDb) {
            $this->loadOperatorsForSchema($this->targetDb);
            $this->loadDoctorsForSchema($this->targetDb);
        }
        $this->loadTargetLoginUsers();
        $this->loadTargetPersonaleAgendaBridge();
        $this->loadTargetClientLinks();
        $this->loadTargetClients();
        $this->loadTargetPatients();
        $this->loadTargetRegisteredClients();
        $this->reloadAgendaCaches();
    }

    private function reloadAgendaCaches(): void
    {
        $this->loadTargetAgendaState();
        $this->loadTargetActiveConfigs();
    }

    private function clearAgendaCaches(): void
    {
        $this->targetSlotsByKey = [];
        $this->targetBlockedDayKeys = [];
        $this->targetMemoBlockedDayKeys = [];
        $this->targetDomiciliariBlockedDayKeys = [];
        $this->targetDailyNotesByDay = [];
        $this->targetDailyNotesByExactKey = [];
        $this->activeAppointmentDayCount = [];
        $this->targetConfigCache = [];
        $this->targetActiveConfigsByDoctor = [];
        $this->targetActiveConfigIntervalsByDoctor = [];
        $this->targetAgendaNotesByLegacyId = [];
        $this->targetAgendaNotesBySignature = [];
        $this->targetDomiciliariByLegacyId = [];
        $this->targetDomiciliariBySignature = [];
        $this->patientResolutionCache = [];
    }

    private function initializeEncryptionSession(): void
    {
        $this->initializeEncryptionSessionOn($this->db);
    }

    private function initializeEncryptionSessionOn(mysqli $connection): void
    {
        $key = $connection->real_escape_string($this->encryptionKey !== '' ? $this->encryptionKey : 'PartitaIVA22');
        $connection->query("SET @key_str = SHA2('{$key}', 512)");
        $connection->query('SET @init_vector = RANDOM_BYTES(16)');
    }

    private function loadTargetPersonaleAgendaBridge(): void
    {
        if (
            !$this->tableExists($this->targetDb, 'dap03_personale')
            || !$this->columnExists($this->targetDb, 'dap03_personale', 'legacy_id_dot')
        ) {
            return;
        }

        $hasLegacyDoctorType = $this->columnExists($this->targetDb, 'dap03_personale', 'legacy_dot_tipo_id');
        $hasFamilyFlag = $this->columnExists($this->targetDb, 'dap03_personale', 'f_dom');

        $sql = "
            SELECT
                id_personale,
                COALESCE(legacy_id_dot, 0) AS legacy_id_dot,
                " . ($hasLegacyDoctorType ? "COALESCE(legacy_dot_tipo_id, 0)" : "0") . " AS legacy_dot_tipo_id,
                " . ($hasFamilyFlag ? "COALESCE(f_dom, 0)" : "0") . " AS f_dom
            FROM `{$this->targetDb}`.dap03_personale
            WHERE COALESCE(legacy_id_dot, 0) > 0
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $personaleId = (int)($row['id_personale'] ?? 0);
            $agendaDoctorId = (int)($row['legacy_id_dot'] ?? 0);
            if ($personaleId <= 0 || $agendaDoctorId <= 0) {
                continue;
            }

            $this->agendaDoctorIdByPersonaleId[$personaleId] = $agendaDoctorId;
            $this->targetPersonaleLegacyDoctorTypeById[$personaleId] = (int)($row['legacy_dot_tipo_id'] ?? 0);
            $this->targetPersonaleFamilyFlagById[$personaleId] = (int)($row['f_dom'] ?? 0);
            if (!isset($this->personaleIdByAgendaDoctorId[$agendaDoctorId])) {
                $this->personaleIdByAgendaDoctorId[$agendaDoctorId] = $personaleId;
            }
        }
    }

    private function loadDoctorsForSchema(string $schema): void
    {
        $doctorTable = $this->resolveLegacyTableName($schema, 'far03_dot');
        if ($doctorTable === null) {
            $this->sourceDoctorsBySchema[$schema] = [];
            return;
        }

        $operatorTable = $this->resolveLegacyTableName($schema, 'far01_ope');
        $usernameSelect = $operatorTable !== null
            ? "COALESCE(o.user, '') AS username,"
            : "'' AS username,";
        $operatorJoin = $operatorTable !== null
            ? "LEFT JOIN `{$schema}`.`{$operatorTable}` o
              ON o.id_ope = d.id_ope"
            : '';

        $sql = "
            SELECT
                d.id_dot,
                d.id_ope,
                {$usernameSelect}
                COALESCE(d.cognome, '') AS cognome,
                COALESCE(d.nome, '') AS nome,
                COALESCE(d.email, '') AS email,
                COALESCE(d.telefono, '') AS telefono
            FROM `{$schema}`.`{$doctorTable}` d
            {$operatorJoin}
            ORDER BY d.id_dot ASC
        ";

        $res = $this->db->query($sql);
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $row['id_dot'] = (int)$row['id_dot'];
            $row['id_ope'] = (int)($row['id_ope'] ?? 0);
            $row['username_norm'] = $this->normalizeUsername((string)$row['username']);
            $rows[(int)$row['id_dot']] = $row;

            if ($schema === $this->targetDb && $row['username_norm'] !== '') {
                $this->targetDoctorsByUsername[$row['username_norm']] = $row;
            }
        }

        $this->sourceDoctorsBySchema[$schema] = $rows;
    }

    private function loadOperatorsForSchema(string $schema): void
    {
        $operatorTable = $this->resolveLegacyTableName($schema, 'far01_ope');
        if ($operatorTable === null) {
            $this->sourceOperatorsBySchema[$schema] = [];
            return;
        }

        $sql = "
            SELECT
                id_ope,
                COALESCE(nome, '') AS nome,
                COALESCE(cognome, '') AS cognome,
                COALESCE(user, '') AS user,
                COALESCE(password, '') AS password,
                id_ruo,
                data_ora_mod,
                COALESCE(email, '') AS email,
                data_scad_ute,
                data_scad_pass,
                vis_dot
            FROM `{$schema}`.`{$operatorTable}`
            ORDER BY id_ope ASC
        ";

        $res = $this->db->query($sql);
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $row['id_ope'] = (int)$row['id_ope'];
            $row['id_ruo'] = (int)($row['id_ruo'] ?? 0);
            $row['vis_dot'] = isset($row['vis_dot']) ? (int)$row['vis_dot'] : null;
            $row['username_norm'] = $this->normalizeUsername((string)$row['user']);
            $rows[(int)$row['id_ope']] = $row;

            if ($schema === $this->targetDb) {
                $this->targetOperatorsById[(int)$row['id_ope']] = $row;
                if ($row['username_norm'] !== '') {
                    $this->targetOperatorsByUsername[$row['username_norm']] = $row;
                }
            }
        }

        $this->sourceOperatorsBySchema[$schema] = $rows;
    }

    private function resolveLegacyTableName(string $schema, string $baseTable): ?string
    {
        if ($this->tableExists($schema, $baseTable)) {
            return $baseTable;
        }

        if ($schema === $this->targetDb) {
            $fallback = $baseTable . '_old';
            if ($this->tableExists($schema, $fallback)) {
                return $fallback;
            }
        }

        return null;
    }

    private function loadTargetLoginUsers(): void
    {
        $this->targetLoginUsersByUsername = [];
        if (!$this->tableExists($this->targetDb, 'dap01_users')) {
            return;
        }

        $sql = "
            SELECT id_user, username
            FROM `{$this->targetDb}`.dap01_users
        ";

        // Buffered result set: during import we also write on the same connection.
        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $username = $this->normalizeUsername((string)$row['username']);
            if ($username === '') {
                continue;
            }

            $this->targetLoginUsersByUsername[$username] = [
                'id_user' => (int)$row['id_user'],
                'username' => (string)$row['username'],
            ];
        }
        $res->close();
    }

    private function loadTargetPatients(): void
    {
        foreach ($this->targetClientsById as $clientRow) {
            $agendaDoctorIds = $this->getKnownAgendaDoctorIdsForClientRow($clientRow);
            if ($agendaDoctorIds === []) {
                continue;
            }

            foreach ($agendaDoctorIds as $agendaDoctorId) {
                $this->registerTargetPatientRow([
                    'id_paziente' => (int)($clientRow['id_client'] ?? 0),
                    'id_dot' => (int)$agendaDoctorId,
                    'cognome' => (string)($clientRow['cognome'] ?? ''),
                    'nome' => (string)($clientRow['nome'] ?? ''),
                    'cod_fis' => (string)($clientRow['codice_fiscale'] ?? ''),
                    'cellulare' => (string)($clientRow['cellulare'] ?? ''),
                    'telefono' => (string)($clientRow['telefono'] ?? ''),
                ]);
            }
        }
    }

    private function loadTargetClientLinks(): void
    {
        if (!$this->tableExists($this->targetDb, 'dap09_client_doctor')) {
            return;
        }

        $sql = "
            SELECT
                id_users_doctor,
                COALESCE(id_client, 0) AS id_client,
                COALESCE(id_dot, 0) AS id_dot
            FROM `{$this->targetDb}`.dap09_client_doctor
            ORDER BY id_users_doctor DESC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $clientId = (int)($row['id_client'] ?? 0);
            $doctorId = (int)($row['id_dot'] ?? 0);
            if ($clientId <= 0 || $doctorId <= 0) {
                continue;
            }

            $this->targetClientDoctorIdsById[$clientId][$doctorId] = true;
            if (!isset($this->targetClientDoctorById[$clientId])) {
                $this->targetClientDoctorById[$clientId] = $doctorId;
            }
        }
    }

    private function loadTargetClients(): void
    {
        if (!$this->tableExists($this->targetDb, 'dap02_clients')) {
            return;
        }

        $this->targetClientsHasLegacyPatientBridge = $this->columnExists($this->targetDb, 'dap02_clients', 'legacy_id_paziente');

        $sql = "
            SELECT
                c.id_client,
                COALESCE(c.id_user, 0) AS id_user,
                COALESCE(c.id_personale, 0) AS id_personale,
                " . ($this->targetClientsHasLegacyPatientBridge ? "COALESCE(c.legacy_id_paziente, 0)" : "0") . " AS legacy_id_paziente,
                COALESCE(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR), '') AS nome,
                COALESCE(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR), '') AS cognome,
                COALESCE(CAST(AES_DECRYPT(UNHEX(c.cellulare), @key_str, c.vector_id) AS CHAR), '') AS cellulare,
                COALESCE(CAST(AES_DECRYPT(UNHEX(c.telefono), @key_str, c.vector_id) AS CHAR), '') AS telefono,
                COALESCE(CAST(AES_DECRYPT(UNHEX(c.email), @key_str, c.vector_id) AS CHAR), '') AS email,
                COALESCE(CAST(AES_DECRYPT(UNHEX(c.codice_fiscale), @key_str, c.vector_id) AS CHAR), '') AS codice_fiscale
            FROM `{$this->targetDb}`.dap02_clients c
            ORDER BY c.id_client ASC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $row['id_client'] = (int)$row['id_client'];
            $row['id_user'] = (int)($row['id_user'] ?? 0);
            $row['id_personale'] = (int)($row['id_personale'] ?? 0);
            $row['legacy_id_paziente'] = (int)($row['legacy_id_paziente'] ?? 0);
            $this->registerTargetClientRow($row);
        }
    }

    private function registerTargetClientRow(array $row): void
    {
        $clientId = (int)($row['id_client'] ?? 0);
        if ($clientId <= 0) {
            return;
        }

        $personaleId = (int)($this->targetClientDoctorById[$clientId] ?? 0);
        if ($personaleId <= 0) {
            $personaleId = (int)($row['id_personale'] ?? 0);
        }

        $agendaDoctorId = (int)($this->agendaDoctorIdByPersonaleId[$personaleId] ?? 0);
        $row['resolved_id_personale'] = $personaleId;
        $row['resolved_id_dot'] = $agendaDoctorId;
        $this->targetClientsById[$clientId] = $row;

        $legacyPatientId = (int)($row['legacy_id_paziente'] ?? 0);
        if ($legacyPatientId > 0 && !isset($this->targetClientsByLegacyPatientId[$legacyPatientId])) {
            $this->targetClientsByLegacyPatientId[$legacyPatientId] = $clientId;
        }

        $userId = (int)($row['id_user'] ?? 0);
        if ($userId > 0 && !isset($this->targetClientByUserId[$userId])) {
            $this->targetClientByUserId[$userId] = $clientId;
        }

        $cf = $this->normalizeUsableFiscalCode((string)($row['codice_fiscale'] ?? ''));
        if ($cf !== '' && !isset($this->targetClientsByCf[$cf])) {
            $this->targetClientsByCf[$cf] = $clientId;
        }

        foreach ($this->getKnownAgendaDoctorIdsForClientRow($row) as $knownAgendaDoctorId) {
            $doctorCfKey = $this->buildDoctorPatientCfKey($knownAgendaDoctorId, (string)($row['codice_fiscale'] ?? ''));
            if ($doctorCfKey !== '' && !isset($this->targetClientsByDoctorCf[$doctorCfKey])) {
                $this->targetClientsByDoctorCf[$doctorCfKey] = $clientId;
            }

            $doctorTriadKey = $this->buildDoctorPatientTriadKey(
                $knownAgendaDoctorId,
                (string)($row['cognome'] ?? ''),
                (string)($row['nome'] ?? ''),
                (string)($row['cellulare'] ?? '')
            );
            if ($doctorTriadKey !== '' && !isset($this->targetClientsByDoctorTriad[$doctorTriadKey])) {
                $this->targetClientsByDoctorTriad[$doctorTriadKey] = $clientId;
            }

            $doctorEmailKey = $this->buildDoctorPatientEmailKey(
                $knownAgendaDoctorId,
                (string)($row['cognome'] ?? ''),
                (string)($row['nome'] ?? ''),
                (string)($row['email'] ?? '')
            );
            if ($doctorEmailKey !== '' && !isset($this->targetClientsByDoctorEmail[$doctorEmailKey])) {
                $this->targetClientsByDoctorEmail[$doctorEmailKey] = $clientId;
            }
        }

        $technicalKey = $this->resolveTechnicalAppointmentKey(
            (string)($row['cognome'] ?? ''),
            (string)($row['nome'] ?? '')
        );
        if ($technicalKey !== '' && !$this->clientHasAnyAgendaDoctorLink($row)) {
            $this->targetTechnicalClientsByKey[$technicalKey] = $clientId;
        }
    }

    private function clientHasAnyAgendaDoctorLink(array $row): bool
    {
        return $this->getKnownAgendaDoctorIdsForClientRow($row) !== [];
    }

    private function registerTargetPatientRow(array $row): void
    {
        $patientId = (int)$row['id_paziente'];
        $doctorId = (int)($row['id_dot'] ?? 0);
        $this->targetPatientIds[$patientId] = true;
        if (!isset($this->targetPatientDoctorById[$patientId])) {
            $this->targetPatientDoctorById[$patientId] = $doctorId;
        }

        $cf = $this->normalizeCode((string)$row['cod_fis']);
        if ($cf !== '' && !isset($this->targetPatientsByCf[$cf])) {
            $this->targetPatientsByCf[$cf] = $patientId;
        }
        $doctorCfKey = $this->buildDoctorPatientCfKey($doctorId, (string)$row['cod_fis']);
        if ($doctorCfKey !== '' && !isset($this->targetPatientsByDoctorCf[$doctorCfKey])) {
            $this->targetPatientsByDoctorCf[$doctorCfKey] = $patientId;
        }

        $triadKey = $this->buildPatientTriadKey(
            (string)$row['cognome'],
            (string)$row['nome'],
            (string)$row['cellulare']
        );
        $doctorTriadKey = $this->buildDoctorPatientTriadKey(
            $doctorId,
            (string)$row['cognome'],
            (string)$row['nome'],
            (string)$row['cellulare']
        );
        $doctorPhoneKey = $this->buildDoctorPatientPhoneKey(
            $doctorId,
            (string)$row['cognome'],
            (string)$row['nome'],
            (string)$row['telefono']
        );

        if ($triadKey !== '' && !isset($this->targetPatientsByTriad[$triadKey])) {
            $this->targetPatientsByTriad[$triadKey] = $patientId;
        }
        if ($doctorTriadKey !== '' && !isset($this->targetPatientsByDoctorTriad[$doctorTriadKey])) {
            $this->targetPatientsByDoctorTriad[$doctorTriadKey] = $patientId;
        }
        if ($doctorPhoneKey !== '' && !isset($this->targetPatientsByDoctorPhone[$doctorPhoneKey])) {
            $this->targetPatientsByDoctorPhone[$doctorPhoneKey] = $patientId;
        }
    }

    private function loadTargetRegisteredClients(): void
    {
        $sql = "
            SELECT
                c.id_client,
                c.id_user,
                u.username
            FROM `{$this->targetDb}`.dap02_clients c
            INNER JOIN `{$this->targetDb}`.dap01_users u
              ON u.id_user = c.id_user
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $username = $this->normalizeUsername((string)$row['username']);
            if ($username === '') {
                continue;
            }

            $this->targetRegisteredClientsByUsername[$username] = [
                'id_client' => (int)$row['id_client'],
                'id_user' => (int)$row['id_user'],
                'username' => (string)$row['username'],
            ];
        }
    }

    private function initializeStructureWindow(): void
    {
        $explicitFrom = trim($this->structureFrom);
        $explicitTo = trim($this->structureTo);

        if ($explicitFrom !== '' && $explicitTo !== '') {
            if ($explicitTo < $explicitFrom) {
                throw new RuntimeException('La finestra struttura non e valida: --structure-to e precedente a --structure-from');
            }
            return;
        }

        $bounds = $this->detectStructureBounds();
        $from = $explicitFrom !== '' ? $explicitFrom : ($bounds['min'] ?: $this->appointmentsFrom);
        $to = $explicitTo !== '' ? $explicitTo : ($bounds['max'] ?: $this->appointmentsFrom);

        if ($to < $from) {
            $to = $from;
        }

        $this->structureFrom = $from;
        $this->structureTo = $to;
    }

    private function detectStructureBounds(): array
    {
        $min = null;
        $max = null;

        foreach ([$this->sourceDb] as $schema) {
            if (!$this->tableExists($schema, 'far06_appuntamenti') || !$this->tableExists($schema, 'far08_prenotazioni')) {
                continue;
            }

            $sql = "
                SELECT
                    MIN(DATE(a.data_ora_ini)) AS min_d,
                    MAX(DATE(a.data_ora_ini)) AS max_d
                FROM `{$schema}`.far06_appuntamenti a
                INNER JOIN `{$schema}`.far08_prenotazioni p
                    ON p.id_appuntamento = a.id_appuntamento
            ";

            $row = $this->db->query($sql)->fetch_assoc();
            $min = $this->minDate($min, (string)($row['min_d'] ?? ''));
            $max = $this->maxDate($max, (string)($row['max_d'] ?? ''));
        }

        return [
            'min' => $min,
            'max' => $max,
        ];
    }

    private function loadTargetAgendaState(): void
    {
        $this->targetAgendaAppointmentsHasIdClient = $this->columnExists(
            $this->targetDb,
            'dap12_agenda_appuntamenti',
            'id_client'
        );
        $this->targetAgendaNotesHasIdClient = $this->columnExists(
            $this->targetDb,
            'dap15_agenda_note',
            'id_client'
        );
        $this->targetAgendaNotesHasLegacyId = $this->columnExists(
            $this->targetDb,
            'dap15_agenda_note',
            'legacy_id_not_dot'
        );
        $this->targetDomiciliariHasIdClient = $this->columnExists(
            $this->targetDb,
            'dap13_visite_domiciliari',
            'id_client'
        );
        $this->targetDomiciliariHasLegacyId = $this->columnExists(
            $this->targetDb,
            'dap13_visite_domiciliari',
            'legacy_id_vis'
        );
        $this->targetDomiciliariHasGiornoVisita = $this->columnExists(
            $this->targetDb,
            'dap13_visite_domiciliari',
            'giorno_visita'
        );
        $slotCacheFrom = $this->minDate($this->appointmentsFrom, $this->structureFrom) ?: $this->appointmentsFrom;
        $slotCacheTo = $this->maxDate($this->appointmentsFrom, $this->structureTo) ?: $this->structureTo;
        $allowedDoctorSql = $this->buildAllowedDoctorSqlList();
        $appointmentClientSelect = $this->targetAgendaAppointmentsHasIdClient
            ? 'a.id_client AS appointment_client_id,'
            : 'NULL AS appointment_client_id,';
        $slotSql = "
            SELECT
                s.id_slot,
                s.id_dot,
                s.data_slot,
                s.ora_inizio,
                s.ora_fine,
                s.stato AS slot_stato,
                s.origine_slot,
                a.id_appuntamento,
                a.id_paziente AS appointment_patient_id,
                {$appointmentClientSelect}
                COALESCE(a.cognome, '') AS appointment_cognome,
                COALESCE(a.nome, '') AS appointment_nome,
                COALESCE(a.stato, '') AS appointment_stato
            FROM `{$this->targetDb}`.dap11_agenda_slot s
            LEFT JOIN `{$this->targetDb}`.dap12_agenda_appuntamenti a
              ON a.id_slot = s.id_slot
             AND a.stato <> 'ANNULLATO'
            WHERE s.data_slot >= '{$this->db->real_escape_string($slotCacheFrom)}'
              AND s.data_slot <= '{$this->db->real_escape_string($slotCacheTo)}'
              AND s.id_dot IN ({$allowedDoctorSql})
        ";

        $res = $this->db->query($slotSql, MYSQLI_USE_RESULT);
        while ($row = $res->fetch_assoc()) {
            $key = $this->buildSlotKey(
                (int)$row['id_dot'],
                (string)$row['ora_inizio'],
                (string)$row['ora_fine']
            );

            $row['id_slot'] = (int)$row['id_slot'];
            $row['id_dot'] = (int)$row['id_dot'];
            $row['id_appuntamento'] = isset($row['id_appuntamento']) ? (int)$row['id_appuntamento'] : 0;
            $row['appointment_patient_id'] = isset($row['appointment_patient_id']) ? (int)$row['appointment_patient_id'] : 0;
            $row['appointment_client_id'] = isset($row['appointment_client_id']) ? (int)$row['appointment_client_id'] : 0;

            if ((int)$row['id_appuntamento'] > 0) {
                $dayKey = $this->buildDoctorDayKey((int)$row['id_dot'], (string)$row['data_slot']);
                $this->activeAppointmentDayCount[$dayKey] = (int)($this->activeAppointmentDayCount[$dayKey] ?? 0) + 1;
            }

            if ((string)$row['data_slot'] < $this->appointmentsFrom) {
                $this->targetSlotsByKey[$key] = true;
                continue;
            }

            if ((int)$row['id_appuntamento'] > 0) {
                $this->targetSlotsByKey[$key] = $row;
                continue;
            }

            $this->targetSlotsByKey[$key] = [
                'id_slot' => (int)$row['id_slot'],
                'id_dot' => (int)$row['id_dot'],
                'data_slot' => (string)$row['data_slot'],
                'ora_inizio' => (string)$row['ora_inizio'],
                'ora_fine' => (string)$row['ora_fine'],
                'slot_stato' => (string)$row['slot_stato'],
                'origine_slot' => (string)$row['origine_slot'],
                'id_appuntamento' => 0,
            ];
        }
        $res->close();

        $blockedSql = "
            SELECT id_dot, data_agenda
            FROM `{$this->targetDb}`.dap21_agenda_giorni_bloccati
            WHERE id_dot IN ({$allowedDoctorSql})
        ";
        $res = $this->db->query($blockedSql);
        while ($row = $res->fetch_assoc()) {
            $this->targetBlockedDayKeys[$this->buildDoctorDayKey((int)$row['id_dot'], (string)$row['data_agenda'])] = true;
        }

        if ($this->tableExists($this->targetDb, 'dap37_block_memo')) {
            $res = $this->db->query("
                SELECT id_dot, data_agenda
                FROM `{$this->targetDb}`.dap37_block_memo
                WHERE id_dot IN ({$allowedDoctorSql})
            ");
            while ($row = $res->fetch_assoc()) {
                $this->targetMemoBlockedDayKeys[$this->buildDoctorDayKey((int)$row['id_dot'], (string)$row['data_agenda'])] = true;
            }
        }

        if ($this->tableExists($this->targetDb, 'dap31_block_dom')) {
            $res = $this->db->query("
                SELECT id_dot, data_agenda
                FROM `{$this->targetDb}`.dap31_block_dom
                WHERE id_dot IN ({$allowedDoctorSql})
            ");
            while ($row = $res->fetch_assoc()) {
                $this->targetDomiciliariBlockedDayKeys[$this->buildDoctorDayKey((int)$row['id_dot'], (string)$row['data_agenda'])] = true;
            }
        }

        $notesSql = "
            SELECT id_nota_giorno, id_dot, data_agenda, COALESCE(nota, '') AS nota
            FROM `{$this->targetDb}`.dap23_agenda_nota_giorno
            WHERE id_dot IN ({$allowedDoctorSql})
        ";
        $res = $this->db->query($notesSql);
        while ($row = $res->fetch_assoc()) {
            $dayKey = $this->buildDoctorDayKey((int)$row['id_dot'], (string)$row['data_agenda']);
            $exactKey = $dayKey . '|' . md5((string)$row['nota']);
            $this->targetDailyNotesByDay[$dayKey] = [
                'id_nota_giorno' => (int)($row['id_nota_giorno'] ?? 0),
                'id_dot' => (int)$row['id_dot'],
                'data_agenda' => (string)$row['data_agenda'],
                'nota' => (string)$row['nota'],
            ];
            $this->targetDailyNotesByExactKey[$exactKey] = true;
        }

        if ($this->tableExists($this->targetDb, 'dap15_agenda_note')) {
            $agendaNoteClientSelect = $this->targetAgendaNotesHasIdClient
                ? 'COALESCE(id_client, 0) AS id_client,'
                : '0 AS id_client,';
            $agendaNoteLegacySelect = $this->targetAgendaNotesHasLegacyId
                ? 'COALESCE(legacy_id_not_dot, 0) AS legacy_id_not_dot,'
                : '0 AS legacy_id_not_dot,';
            $memoSql = "
                SELECT
                    id_nota,
                    id_dot,
                    data_inizio_validita,
                    COALESCE(cliente, '') AS cliente,
                    COALESCE(id_paziente, 0) AS id_paziente,
                    {$agendaNoteClientSelect}
                    COALESCE(note, '') AS note,
                    COALESCE(testo, '') AS testo,
                    COALESCE(fatta, 0) AS fatta,
                    COALESCE(attiva, 1) AS attiva,
                    {$agendaNoteLegacySelect}
                    COALESCE(created_at, '0000-00-00 00:00:00') AS created_at
                FROM `{$this->targetDb}`.dap15_agenda_note
                WHERE id_dot IN ({$allowedDoctorSql})
            ";
            $res = $this->db->query($memoSql);
            while ($row = $res->fetch_assoc()) {
                $legacyId = (int)($row['legacy_id_not_dot'] ?? 0);
                if ($legacyId > 0) {
                    $this->targetAgendaNotesByLegacyId[$legacyId] = (int)$row['id_nota'];
                }
                $signature = $this->buildAgendaMemoSignature($row);
                if ($signature !== '') {
                    $this->targetAgendaNotesBySignature[$signature] = (int)$row['id_nota'];
                }
            }
        }

        if ($this->tableExists($this->targetDb, 'dap13_visite_domiciliari')) {
            $domClientSelect = $this->targetDomiciliariHasIdClient
                ? 'COALESCE(id_client, 0) AS id_client,'
                : '0 AS id_client,';
            $domLegacySelect = $this->targetDomiciliariHasLegacyId
                ? 'COALESCE(legacy_id_vis, 0) AS legacy_id_vis,'
                : '0 AS legacy_id_vis,';
            $domDaySelect = $this->targetDomiciliariHasGiornoVisita
                ? "COALESCE(giorno_visita, '0000-00-00') AS giorno_visita,"
                : "DATE(COALESCE(data_creazione, '0000-00-00 00:00:00')) AS giorno_visita,";
            $domSql = "
                SELECT
                    id_visita,
                    id_dot,
                    COALESCE(id_paziente, 0) AS id_paziente,
                    {$domClientSelect}
                    COALESCE(cognome, '') AS cognome,
                    COALESCE(nome, '') AS nome,
                    COALESCE(telefono, '') AS telefono,
                    COALESCE(cellulare, '') AS cellulare,
                    COALESCE(indirizzo, '') AS indirizzo,
                    COALESCE(citta, '') AS citta,
                    COALESCE(note, '') AS note,
                    {$domDaySelect}
                    COALESCE(stato, 'ATTIVA') AS stato,
                    {$domLegacySelect}
                    COALESCE(data_creazione, '0000-00-00 00:00:00') AS data_creazione
                FROM `{$this->targetDb}`.dap13_visite_domiciliari
                WHERE id_dot IN ({$allowedDoctorSql})
            ";
            $res = $this->db->query($domSql);
            while ($row = $res->fetch_assoc()) {
                $legacyId = (int)($row['legacy_id_vis'] ?? 0);
                if ($legacyId > 0) {
                    $this->targetDomiciliariByLegacyId[$legacyId] = (int)$row['id_visita'];
                }
                $signature = $this->buildDomiciliareSignature($row);
                if ($signature !== '') {
                    $this->targetDomiciliariBySignature[$signature] = (int)$row['id_visita'];
                }
            }
        }

        if ($this->tableExists($this->targetDb, 'dap24_agenda_visibilita')) {
            $visSql = "
                SELECT id_ope, id_dot
                FROM `{$this->targetDb}`.dap24_agenda_visibilita
                WHERE id_dot IN ({$allowedDoctorSql})
            ";
            $res = $this->db->query($visSql);
            while ($row = $res->fetch_assoc()) {
                $this->targetAgendaVisibilityKeys[$this->buildOperatorDoctorKey((int)$row['id_ope'], (int)$row['id_dot'])] = true;
            }
        }
    }

    private function loadTargetActiveConfigs(): void
    {
        $this->targetActiveConfigsByDoctor = [];
        $this->targetActiveConfigIntervalsByDoctor = [];

        if (!$this->tableExists($this->targetDb, 'dap10_agenda_config')) {
            return;
        }

        $allowedDoctorSql = $this->buildAllowedDoctorSqlList();
        $sql = "
            SELECT
                c.id_config,
                c.id_dot,
                c.data_inizio,
                c.data_fine,
                COALESCE(c.descrizione, '') AS descrizione,
                g.id_config_giorno,
                g.giorno_settimana,
                g.giorno_libero,
                g.mattina_attiva,
                g.mattina_ora_inizio,
                g.mattina_ora_fine,
                g.mattina_durata_slot,
                g.pomeriggio_attiva,
                g.pomeriggio_modalita_inizio,
                g.pomeriggio_ora_inizio,
                g.pomeriggio_ora_fine,
                g.pomeriggio_durata_slot,
                f.id_config_fascia,
                f.ordine,
                f.ora_inizio,
                f.ora_fine,
                f.durata_slot,
                COALESCE(f.id_amb_legacy, 0) AS id_amb_legacy,
                COALESCE(f.ambulatorio, '') AS ambulatorio,
                COALESCE(f.stanza, '') AS stanza
            FROM `{$this->targetDb}`.dap10_agenda_config c
            LEFT JOIN `{$this->targetDb}`.dap10_agenda_config_giorni g
              ON g.id_config = c.id_config
            LEFT JOIN `{$this->targetDb}`.dap10_agenda_config_fasce f
              ON f.id_config_giorno = g.id_config_giorno
            WHERE c.attiva = 1
              AND c.data_inizio <= '{$this->db->real_escape_string($this->structureTo)}'
              AND c.data_fine >= '{$this->db->real_escape_string($this->structureFrom)}'
              AND c.id_dot IN ({$allowedDoctorSql})
            ORDER BY c.id_dot ASC, c.data_inizio ASC, c.id_config ASC, g.giorno_settimana ASC, f.ordine ASC, f.ora_inizio ASC
        ";

        $res = $this->db->query($sql);
        $byConfig = [];
        while ($row = $res->fetch_assoc()) {
            $idConfig = (int)$row['id_config'];
            $idDot = (int)$row['id_dot'];
            if (!$this->isDoctorAllowed($idDot)) {
                continue;
            }

            if (!isset($byConfig[$idConfig])) {
                $byConfig[$idConfig] = [
                    'id_config' => $idConfig,
                    'id_dot' => $idDot,
                    'data_inizio' => (string)$row['data_inizio'],
                    'data_fine' => (string)$row['data_fine'],
                    'descrizione' => (string)$row['descrizione'],
                    'days' => [],
                ];
            }

            $dayNumber = isset($row['giorno_settimana']) ? (int)$row['giorno_settimana'] : 0;
            if ($dayNumber < 1 || $dayNumber > 7) {
                continue;
            }

            if (!isset($byConfig[$idConfig]['days'][$dayNumber])) {
                $byConfig[$idConfig]['days'][$dayNumber] = [
                    'id_config_giorno' => (int)($row['id_config_giorno'] ?? 0),
                    'giorno_settimana' => $dayNumber,
                    'giorno_libero' => (int)($row['giorno_libero'] ?? 0),
                    'mattina_attiva' => (int)($row['mattina_attiva'] ?? 0),
                    'mattina_ora_inizio' => $this->normalizeTime((string)($row['mattina_ora_inizio'] ?? '')),
                    'mattina_ora_fine' => $this->normalizeTime((string)($row['mattina_ora_fine'] ?? '')),
                    'mattina_durata_slot' => isset($row['mattina_durata_slot']) ? (int)$row['mattina_durata_slot'] : 0,
                    'pomeriggio_attiva' => (int)($row['pomeriggio_attiva'] ?? 0),
                    'pomeriggio_modalita_inizio' => (string)($row['pomeriggio_modalita_inizio'] ?? 'FINE_MATTINA'),
                    'pomeriggio_ora_inizio' => $this->normalizeTime((string)($row['pomeriggio_ora_inizio'] ?? '')),
                    'pomeriggio_ora_fine' => $this->normalizeTime((string)($row['pomeriggio_ora_fine'] ?? '')),
                    'pomeriggio_durata_slot' => isset($row['pomeriggio_durata_slot']) ? (int)$row['pomeriggio_durata_slot'] : 0,
                    'fasce' => [],
                ];
            }

            if (!empty($row['id_config_fascia'])) {
                $byConfig[$idConfig]['days'][$dayNumber]['fasce'][] = [
                    'ordine' => (int)$row['ordine'],
                    'ora_inizio' => $this->normalizeTime((string)($row['ora_inizio'] ?? '')),
                    'ora_fine' => $this->normalizeTime((string)($row['ora_fine'] ?? '')),
                    'durata_slot' => (int)$row['durata_slot'],
                    'id_amb_legacy' => (int)($row['id_amb_legacy'] ?? 0),
                    'ambulatorio' => trim((string)($row['ambulatorio'] ?? '')),
                    'stanza' => trim((string)($row['stanza'] ?? '')),
                ];
            }
        }

        foreach ($byConfig as $config) {
            for ($day = 1; $day <= 7; $day++) {
                if (!isset($config['days'][$day])) {
                    continue;
                }

                if ($config['days'][$day]['fasce'] === []) {
                    $config['days'][$day]['fasce'] = $this->buildFasceFromDayRow($config['days'][$day]);
                } else {
                    usort($config['days'][$day]['fasce'], static function (array $left, array $right): int {
                        $leftOrder = (int)($left['ordine'] ?? 0);
                        $rightOrder = (int)($right['ordine'] ?? 0);
                        if ($leftOrder !== $rightOrder) {
                            return $leftOrder <=> $rightOrder;
                        }

                        return strcmp((string)$left['ora_inizio'], (string)$right['ora_inizio']);
                    });
                }
            }

            $this->registerActiveConfig($config);
        }
    }

    private function getAllowedDoctorIds(): array
    {
        if ($this->doctorFilter !== []) {
            return array_values(array_unique(array_map('intval', $this->doctorFilter)));
        }

        $ids = array_keys($this->sourceDoctorsBySchema[$this->targetDb] ?? []);
        sort($ids);

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function buildAllowedDoctorSqlList(): string
    {
        $doctorIds = $this->getAllowedDoctorIds();
        if ($doctorIds === []) {
            return '0';
        }

        return implode(',', array_map('intval', $doctorIds));
    }

    private function snapshotAppointmentsForStructureRebuild(): int
    {
        $this->structureRebindAppointments = [];
        $doctorIds = $this->getAllowedDoctorIds();
        if ($doctorIds === []) {
            return 0;
        }

        $doctorSql = implode(',', array_map('intval', $doctorIds));
        $appointmentsFromEscaped = $this->db->real_escape_string($this->appointmentsFrom);
        $sql = "
            SELECT
                a.id_appuntamento,
                a.id_slot,
                a.id_dot,
                s.data_slot,
                s.ora_inizio,
                s.ora_fine
            FROM `{$this->targetDb}`.dap12_agenda_appuntamenti a
            LEFT JOIN `{$this->targetDb}`.dap11_agenda_slot s
              ON s.id_slot = a.id_slot
            WHERE a.id_dot IN ({$doctorSql})
              AND (s.data_slot IS NULL OR s.data_slot >= '{$appointmentsFromEscaped}')
            ORDER BY a.id_appuntamento ASC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $this->structureRebindAppointments[] = [
                'id_appuntamento' => (int)($row['id_appuntamento'] ?? 0),
                'current_id_slot' => (int)($row['id_slot'] ?? 0),
                'id_dot' => (int)($row['id_dot'] ?? 0),
                'data_slot' => (string)($row['data_slot'] ?? ''),
                'ora_inizio' => (string)($row['ora_inizio'] ?? ''),
                'ora_fine' => (string)($row['ora_fine'] ?? ''),
            ];
        }

        $this->logger->info('Snapshot appuntamenti per rebuild struttura', [
            'appointments' => count($this->structureRebindAppointments),
        ]);

        return count($this->structureRebindAppointments);
    }

    private function resetTargetAgendaStructure(): array
    {
        $stats = [
            'slots_deleted' => 0,
            'configs_deleted' => 0,
            'config_days_deleted' => 0,
            'config_fasce_deleted' => 0,
            'blocked_days_deleted' => 0,
        ];

        $doctorIds = $this->getAllowedDoctorIds();
        if ($doctorIds === [] || !$this->tableExists($this->targetDb, 'dap10_agenda_config')) {
            return $stats;
        }

        $doctorSql = implode(',', array_map('intval', $doctorIds));
        $configIds = [];
        $res = $this->db->query("
            SELECT id_config
            FROM `{$this->targetDb}`.dap10_agenda_config
            WHERE id_dot IN ({$doctorSql})
        ");
        while ($row = $res->fetch_assoc()) {
            $configIds[] = (int)$row['id_config'];
        }

        $configDayIds = [];
        if ($configIds !== [] && $this->tableExists($this->targetDb, 'dap10_agenda_config_giorni')) {
            $configSql = implode(',', $configIds);
            $res = $this->db->query("
                SELECT id_config_giorno
                FROM `{$this->targetDb}`.dap10_agenda_config_giorni
                WHERE id_config IN ({$configSql})
            ");
            while ($row = $res->fetch_assoc()) {
                $configDayIds[] = (int)$row['id_config_giorno'];
            }
        }

        $stats['configs_deleted'] = count($configIds);
        $stats['config_days_deleted'] = count($configDayIds);

        if ($configDayIds !== [] && $this->tableExists($this->targetDb, 'dap10_agenda_config_fasce')) {
            $giornoSql = implode(',', $configDayIds);
            $stats['config_fasce_deleted'] = (int)($this->db->query("
                SELECT COUNT(*) AS c
                FROM `{$this->targetDb}`.dap10_agenda_config_fasce
                WHERE id_config_giorno IN ({$giornoSql})
            ")->fetch_assoc()['c'] ?? 0);
        }

        $stats['slots_deleted'] = (int)($this->db->query("
            SELECT COUNT(*) AS c
            FROM `{$this->targetDb}`.dap11_agenda_slot
            WHERE id_dot IN ({$doctorSql})
        ")->fetch_assoc()['c'] ?? 0);

        if ($this->tableExists($this->targetDb, 'dap21_agenda_giorni_bloccati')) {
            $blockedFromEscaped = $this->db->real_escape_string($this->blockedFrom);
            $stats['blocked_days_deleted'] = (int)($this->db->query("
                SELECT COUNT(*) AS c
                FROM `{$this->targetDb}`.dap21_agenda_giorni_bloccati
                WHERE id_dot IN ({$doctorSql})
                  AND data_agenda >= '{$blockedFromEscaped}'
            ")->fetch_assoc()['c'] ?? 0);
        }

        if (!$this->apply) {
            return $stats;
        }

        $this->db->begin_transaction();
        try {
            if ($configDayIds !== [] && $this->tableExists($this->targetDb, 'dap10_agenda_config_fasce')) {
                $giornoSql = implode(',', $configDayIds);
                $this->db->query("
                    DELETE FROM `{$this->targetDb}`.dap10_agenda_config_fasce
                    WHERE id_config_giorno IN ({$giornoSql})
                ");
            }

            if ($configIds !== [] && $this->tableExists($this->targetDb, 'dap10_agenda_config_giorni')) {
                $configSql = implode(',', $configIds);
                $this->db->query("
                    DELETE FROM `{$this->targetDb}`.dap10_agenda_config_giorni
                    WHERE id_config IN ({$configSql})
                ");
            }

            if ($configIds !== []) {
                $configSql = implode(',', $configIds);
                $this->db->query("
                    DELETE FROM `{$this->targetDb}`.dap10_agenda_config
                    WHERE id_config IN ({$configSql})
                ");
            }

            $this->db->query("
                DELETE FROM `{$this->targetDb}`.dap11_agenda_slot
                WHERE id_dot IN ({$doctorSql})
            ");

            if ($this->tableExists($this->targetDb, 'dap21_agenda_giorni_bloccati')) {
                $blockedFromEscaped = $this->db->real_escape_string($this->blockedFrom);
                $this->db->query("
                    DELETE FROM `{$this->targetDb}`.dap21_agenda_giorni_bloccati
                    WHERE id_dot IN ({$doctorSql})
                      AND data_agenda >= '{$blockedFromEscaped}'
                ");
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->logger->info('Struttura agenda target azzerata per rebuild', $stats);

        return $stats;
    }

    private function rebindAppointmentsAfterStructureRebuild(): array
    {
        $stats = [
            'rebound' => 0,
            'missing' => 0,
            'deleted_for_reimport' => 0,
        ];

        if ($this->structureRebindAppointments === []) {
            return $stats;
        }

        $stmt = null;
        if ($this->apply) {
            $stmt = $this->db->prepare("
                UPDATE `{$this->targetDb}`.dap12_agenda_appuntamenti
                SET id_slot = ?
                WHERE id_appuntamento = ?
            ");
        }
        $missingAppointmentIds = [];

        foreach ($this->structureRebindAppointments as $appointment) {
            $slotKey = $this->buildSlotKey(
                (int)$appointment['id_dot'],
                (string)$appointment['ora_inizio'],
                (string)$appointment['ora_fine']
            );
            $slot = $this->targetSlotsByKey[$slotKey] ?? null;
            if (!is_array($slot) || empty($slot['id_slot'])) {
                $stats['missing']++;
                if ($this->apply && !empty($appointment['id_appuntamento'])) {
                    $missingAppointmentIds[] = (int)$appointment['id_appuntamento'];
                }
                continue;
            }

            $newSlotId = (int)$slot['id_slot'];
            if ($newSlotId === 0 || $newSlotId === (int)$appointment['current_id_slot']) {
                continue;
            }

            if ($this->apply && $stmt !== null) {
                $appointmentId = (int)$appointment['id_appuntamento'];
                $stmt->bind_param('ii', $newSlotId, $appointmentId);
                $stmt->execute();
            }

            $stats['rebound']++;
        }

        if ($stmt !== null) {
            $stmt->close();
        }

        if ($this->apply && $missingAppointmentIds !== []) {
            foreach (array_chunk(array_values(array_unique($missingAppointmentIds)), 500) as $chunk) {
                $idsSql = implode(',', array_map('intval', $chunk));
                $this->db->query("
                    DELETE FROM `{$this->targetDb}`.dap12_agenda_appuntamenti
                    WHERE id_appuntamento IN ({$idsSql})
                ");
                $stats['deleted_for_reimport'] += count($chunk);
            }
        }

        $this->logger->info('Rebind appuntamenti dopo rebuild struttura', $stats);

        return $stats;
    }

    private function dropTargetLegacyFar15IfRequested(): bool
    {
        if (!$this->dropTargetFar15 || !$this->tableExists($this->targetDb, 'far15_fas_ora_dot')) {
            return false;
        }

        if (!$this->apply) {
            return false;
        }

        $this->db->query("DROP TABLE `{$this->targetDb}`.far15_fas_ora_dot");
        $this->logger->info('Tabella legacy rimossa da target', [
            'table' => $this->targetDb . '.far15_fas_ora_dot',
        ]);

        return true;
    }

    private function auditDoctors(): void
    {
        $sourceRows = $this->sourceDoctorsBySchema[$this->sourceDb] ?? [];
        $targetRows = $this->sourceDoctorsBySchema[$this->targetDb] ?? [];

        $mapped = 0;
        $missing = [];
        foreach ($sourceRows as $sourceDoctorId => $row) {
            $targetDoctorId = $this->resolveTargetDoctorId($this->sourceDb, (int)$sourceDoctorId);
            if ($targetDoctorId !== null) {
                $mapped++;
                continue;
            }

            $missing[] = [
                'source_id_dot' => (int)$sourceDoctorId,
                'username' => (string)$row['username'],
                'cognome' => (string)$row['cognome'],
                'nome' => (string)$row['nome'],
            ];
        }

        $mailOnly = [];
        foreach ($targetRows as $targetDoctorId => $row) {
            $usernameNorm = $this->normalizeUsername((string)$row['username']);
            if ($usernameNorm === '') {
                continue;
            }

            $found = false;
            foreach ($sourceRows as $sourceRow) {
                if ($this->normalizeUsername((string)$sourceRow['username']) === $usernameNorm) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $mailOnly[] = [
                    'target_id_dot' => (int)$targetDoctorId,
                    'username' => (string)$row['username'],
                    'cognome' => (string)$row['cognome'],
                    'nome' => (string)$row['nome'],
                ];
            }
        }

        $this->report['audit']['doctors'] = [
            'source_total' => count($sourceRows),
            'target_total' => count($targetRows),
            'mapped' => $mapped,
            'missing_in_target' => count($missing),
            'mail_only' => count($mailOnly),
            'missing_examples' => array_slice($missing, 0, 20),
            'mail_only_examples' => array_slice($mailOnly, 0, 20),
        ];

        $this->logger->info('Audit dottori completato', [
            'source_total' => count($sourceRows),
            'target_total' => count($targetRows),
            'mapped' => $mapped,
            'missing_in_target' => count($missing),
            'mail_only' => count($mailOnly),
        ]);
    }

    private function auditOperators(): void
    {
        $sourceRows = $this->sourceOperatorsBySchema[$this->sourceDb] ?? [];
        $targetRows = $this->sourceOperatorsBySchema[$this->targetDb] ?? [];
        $sourceDoctorOperatorIds = [];
        foreach ($this->sourceDoctorsBySchema[$this->sourceDb] ?? [] as $doctorRow) {
            $sourceOperatorId = (int)($doctorRow['id_ope'] ?? 0);
            if ($sourceOperatorId > 0) {
                $sourceDoctorOperatorIds[$sourceOperatorId] = true;
            }
        }

        $mapped = 0;
        $missingFar01 = [];
        $missingLogin = [];
        foreach ($sourceRows as $sourceOperatorId => $row) {
            if ($this->resolveTargetOperatorId($this->sourceDb, (int)$sourceOperatorId) !== null) {
                $mapped++;
            } elseif (count($missingFar01) < 20) {
                $missingFar01[] = [
                    'source_id_ope' => (int)$sourceOperatorId,
                    'username' => (string)$row['user'],
                    'nome' => (string)$row['nome'],
                    'cognome' => (string)$row['cognome'],
                    'id_ruo' => (int)$row['id_ruo'],
                ];
            }

            $usernameNorm = $this->normalizeUsername((string)$row['user']);
            if ($usernameNorm !== '' && !isset($this->targetLoginUsersByUsername[$usernameNorm]) && count($missingLogin) < 50) {
                $missingLogin[] = [
                    'source_id_ope' => (int)$sourceOperatorId,
                    'username' => (string)$row['user'],
                    'id_ruo' => (int)$row['id_ruo'],
                ];
            }
        }

        $legacyVisibilityRows = $this->tableExists($this->sourceDb, 'far10_vis_dot')
            ? $this->scalar("SELECT COUNT(*) AS c FROM `{$this->sourceDb}`.far10_vis_dot")
            : 0;

        $legacyVisibilityOperators = [];
        if ($this->tableExists($this->sourceDb, 'far10_vis_dot')) {
            $sql = "
                SELECT DISTINCT d.id_ope
                FROM `{$this->sourceDb}`.far10_vis_dot v
                INNER JOIN `{$this->sourceDb}`.far03_dot d
                  ON d.id_dot = v.id_dot
                WHERE d.id_ope IS NOT NULL
            ";
            $res = $this->db->query($sql);
            while ($row = $res->fetch_assoc()) {
                $legacyVisibilityOperators[(int)$row['id_ope']] = true;
            }
        }

        $sourceNonDoctorCount = 0;
        foreach (array_keys($sourceRows) as $sourceOperatorId) {
            if (!isset($sourceDoctorOperatorIds[(int)$sourceOperatorId])) {
                $sourceNonDoctorCount++;
            }
        }

        $this->report['audit']['operators'] = [
            'source_total_far01_ope' => count($sourceRows),
            'target_total_far01_ope' => count($targetRows),
            'mapped_to_target_far01_ope' => $mapped,
            'missing_in_target_far01_ope' => count($sourceRows) - $mapped,
            'missing_in_target_far01_examples' => $missingFar01,
            'missing_in_target_dap01_users_by_username' => count(array_filter($sourceRows, function (array $row): bool {
                $usernameNorm = $this->normalizeUsername((string)$row['user']);
                return $usernameNorm !== '' && !isset($this->targetLoginUsersByUsername[$usernameNorm]);
            })),
            'missing_in_target_dap01_examples' => $missingLogin,
            'source_doctor_linked_far01_ope' => count($sourceDoctorOperatorIds),
            'source_non_doctor_far01_ope' => $sourceNonDoctorCount,
            'legacy_visibility_rows_source' => $legacyVisibilityRows,
            'legacy_visibility_operator_count_source' => count($legacyVisibilityOperators),
            'legacy_visibility_scope' => 'far10_vis_dot collega solo operatori agganciati a far03_dot; per utenze non collegate a far03_dot nel legacy non esiste una tabella agenda utente->dottore da importare automaticamente',
            'source_non_doctor_without_legacy_visibility_source' => $sourceNonDoctorCount,
            'target_dap24_agenda_visibilita_rows' => count($this->targetAgendaVisibilityKeys),
        ];

        $this->logger->info('Audit operatori completato', [
            'source_total_far01_ope' => count($sourceRows),
            'target_total_far01_ope' => count($targetRows),
            'mapped_to_target_far01_ope' => $mapped,
            'missing_in_target_far01_ope' => count($sourceRows) - $mapped,
            'missing_in_target_dap01_users_by_username' => $this->report['audit']['operators']['missing_in_target_dap01_users_by_username'],
            'source_non_doctor_far01_ope' => $sourceNonDoctorCount,
            'legacy_visibility_rows_source' => $legacyVisibilityRows,
            'target_dap24_agenda_visibilita_rows' => count($this->targetAgendaVisibilityKeys),
        ]);
    }

    private function auditPatients(): void
    {
        if ($this->doctorFilter !== []) {
            $sourceScopeSql = "SELECT COUNT(*) AS c FROM `{$this->sourceDb}`.far05_pazienti WHERE id_dot IN (" . $this->buildAllowedDoctorSqlList() . ")";
            $targetScopeSql = "SELECT COUNT(*) AS c
                FROM `{$this->targetDb}`.dap09_client_doctor cd
                WHERE cd.id_dot IN (" . $this->buildAllowedDoctorSqlList() . ")";

            $sourceTotal = $this->scalar($sourceScopeSql);
            $targetTotal = $this->scalar($targetScopeSql);

            $this->report['audit']['patients'] = [
                'source_total_far05' => $sourceTotal,
                'target_total_far05' => $targetTotal,
                'same_codfis_between_source_and_target_far05' => null,
                'source_missing_in_target_far05_by_codfis' => null,
                'source_matching_registered_users_by_codfis' => null,
                'missing_codfis_examples' => [],
                'skipped_global_codfis_audit' => true,
                'doctor_filter' => $this->doctorFilter,
                'note' => 'Audit pazienti globale saltato per run filtrato per dottore per evitare query troppo pesanti su tutto il dataset.',
            ];

            $this->logger->info('Audit pazienti filtrato completato', [
                'source_total_far05' => $sourceTotal,
                'target_total_far05' => $targetTotal,
                'skipped_global_codfis_audit' => true,
                'doctor_filter' => $this->doctorFilter,
            ]);

            return;
        }

        $sourceTotal = $this->scalar("SELECT COUNT(*) AS c FROM `{$this->sourceDb}`.far05_pazienti");
        $targetTotal = $this->scalar("SELECT COUNT(*) AS c FROM `{$this->targetDb}`.dap02_clients");
        $sameCf = $this->scalar("
            SELECT COUNT(DISTINCT fp.id_paziente) AS c
            FROM `{$this->sourceDb}`.far05_pazienti fp
            JOIN `{$this->targetDb}`.dap02_clients mp
              ON TRIM(UPPER(COALESCE(CAST(AES_DECRYPT(UNHEX(mp.codice_fiscale), @key_str, mp.vector_id) AS CHAR), ''))) <> ''
             AND TRIM(UPPER(fp.cod_fis)) = TRIM(UPPER(COALESCE(CAST(AES_DECRYPT(UNHEX(mp.codice_fiscale), @key_str, mp.vector_id) AS CHAR), '')))
        ");
        $missingCf = $this->scalar("
            SELECT COUNT(*) AS c
            FROM `{$this->sourceDb}`.far05_pazienti fp
            LEFT JOIN `{$this->targetDb}`.dap02_clients mp
              ON TRIM(UPPER(COALESCE(CAST(AES_DECRYPT(UNHEX(mp.codice_fiscale), @key_str, mp.vector_id) AS CHAR), ''))) <> ''
             AND TRIM(UPPER(fp.cod_fis)) = TRIM(UPPER(COALESCE(CAST(AES_DECRYPT(UNHEX(mp.codice_fiscale), @key_str, mp.vector_id) AS CHAR), '')))
            WHERE TRIM(IFNULL(fp.cod_fis, '')) <> ''
              AND mp.id_client IS NULL
        ");
        $matchingRegistered = $this->scalar("
            SELECT COUNT(DISTINCT fp.id_paziente) AS c
            FROM `{$this->sourceDb}`.far05_pazienti fp
            JOIN `{$this->targetDb}`.dap01_users u
              ON TRIM(UPPER(u.username)) = TRIM(UPPER(fp.cod_fis))
            WHERE TRIM(IFNULL(fp.cod_fis, '')) <> ''
        ");

        $examples = [];
        $sql = "
            SELECT fp.id_paziente, fp.id_dot, fp.cod_fis, fp.cognome, fp.nome, fp.cellulare
            FROM `{$this->sourceDb}`.far05_pazienti fp
            LEFT JOIN `{$this->targetDb}`.dap02_clients mp
              ON TRIM(UPPER(COALESCE(CAST(AES_DECRYPT(UNHEX(mp.codice_fiscale), @key_str, mp.vector_id) AS CHAR), ''))) <> ''
             AND TRIM(UPPER(fp.cod_fis)) = TRIM(UPPER(COALESCE(CAST(AES_DECRYPT(UNHEX(mp.codice_fiscale), @key_str, mp.vector_id) AS CHAR), '')))
            WHERE TRIM(IFNULL(fp.cod_fis, '')) <> ''
              AND mp.id_client IS NULL
            ORDER BY fp.id_paziente DESC
            LIMIT 20
        ";
        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $examples[] = [
                'source_id_paziente' => (int)$row['id_paziente'],
                'source_id_dot' => (int)($row['id_dot'] ?? 0),
                'cod_fis' => (string)$row['cod_fis'],
                'cognome' => (string)$row['cognome'],
                'nome' => (string)$row['nome'],
                'cellulare' => (string)$row['cellulare'],
            ];
        }

        $this->report['audit']['patients'] = [
            'source_total_far05' => $sourceTotal,
            'target_total_far05' => $targetTotal,
            'same_codfis_between_source_and_target_far05' => $sameCf,
            'source_missing_in_target_far05_by_codfis' => $missingCf,
            'source_matching_registered_users_by_codfis' => $matchingRegistered,
            'missing_codfis_examples' => $examples,
        ];

        $this->logger->info('Audit pazienti completato', [
            'source_total_far05' => $sourceTotal,
            'target_total_far05' => $targetTotal,
            'same_codfis' => $sameCf,
            'missing_by_codfis' => $missingCf,
            'matching_registered_users' => $matchingRegistered,
        ]);
    }

    private function auditLegacySources(): void
    {
        $counts = [
            'legacy_future_booked_source_only' => $this->scalar("
                SELECT COUNT(*) AS c
                FROM `{$this->sourceDb}`.far08_prenotazioni p
                JOIN `{$this->sourceDb}`.far06_appuntamenti a
                  ON a.id_appuntamento = p.id_appuntamento
                WHERE a.data_ora_ini >= '{$this->db->real_escape_string($this->appointmentsFrom)}'
            "),
            'legacy_future_booked_target_legacy' => $this->scalar("
                SELECT COUNT(*) AS c
                FROM `{$this->targetDb}`.far08_prenotazioni p
                JOIN `{$this->targetDb}`.far06_appuntamenti a
                  ON a.id_appuntamento = p.id_appuntamento
                WHERE a.data_ora_ini >= '{$this->db->real_escape_string($this->appointmentsFrom)}'
            "),
            'legacy_blocked_source' => $this->tableExists($this->sourceDb, 'far20_stampa')
                ? $this->scalar("SELECT COUNT(*) AS c FROM `{$this->sourceDb}`.far20_stampa")
                : 0,
            'legacy_blocked_target_legacy' => $this->tableExists($this->targetDb, 'far20_stampa')
                ? $this->scalar("SELECT COUNT(*) AS c FROM `{$this->targetDb}`.far20_stampa")
                : 0,
            'legacy_notes_source' => $this->tableExists($this->sourceDb, 'far21_note')
                ? $this->scalar("SELECT COUNT(*) AS c FROM `{$this->sourceDb}`.far21_note")
                : 0,
            'new_notes_target' => $this->scalar("SELECT COUNT(*) AS c FROM `{$this->targetDb}`.dap23_agenda_nota_giorno"),
            'new_agenda_notes_target' => $this->scalar("SELECT COUNT(*) AS c FROM `{$this->targetDb}`.dap15_agenda_note"),
            'new_domiciliari_target' => $this->scalar("SELECT COUNT(*) AS c FROM `{$this->targetDb}`.dap13_visite_domiciliari"),
        ];

        $this->report['audit']['legacy_sources'] = $counts;
        $this->logger->info('Audit sorgenti legacy completato', $counts);
    }

    private function migrateOperatorsAndAgendaVisibility(): void
    {
        $phase = [
            'operators_source_total' => count($this->sourceOperatorsBySchema[$this->sourceDb] ?? []),
            'operators_inserted_far01_ope' => 0,
            'operators_skipped_existing_far01_ope' => 0,
            'operators_skipped_blank_username' => 0,
            'operators_missing_login_user_dap01' => 0,
            'operators_missing_login_examples' => [],
            'visibility_candidates' => 0,
            'visibility_inserted' => 0,
            'visibility_skipped_existing' => 0,
            'visibility_skipped_missing_operator' => 0,
            'visibility_skipped_missing_doctor' => 0,
            'visibility_examples' => [],
        ];

        foreach ($this->sourceOperatorsBySchema[$this->sourceDb] ?? [] as $sourceOperatorId => $row) {
            $usernameNorm = $this->normalizeUsername((string)$row['user']);
            $targetOperatorId = $this->resolveTargetOperatorId($this->sourceDb, (int)$sourceOperatorId);
            if ($targetOperatorId !== null) {
                $phase['operators_skipped_existing_far01_ope']++;
            } elseif ($usernameNorm === '') {
                $phase['operators_skipped_blank_username']++;
            } else {
                $targetOperatorId = $this->insertTargetOperatorFromSource($row);
                $phase['operators_inserted_far01_ope']++;
            }

            if ($usernameNorm !== '' && !isset($this->targetLoginUsersByUsername[$usernameNorm])) {
                $phase['operators_missing_login_user_dap01']++;
                if (count($phase['operators_missing_login_examples']) < 40) {
                    $phase['operators_missing_login_examples'][] = [
                        'source_id_ope' => (int)$sourceOperatorId,
                        'username' => (string)$row['user'],
                        'id_ruo' => (int)$row['id_ruo'],
                    ];
                }
            }
        }

        $candidates = $this->loadLegacyAgendaVisibilityCandidates();
        $phase['visibility_candidates'] = count($candidates);

        foreach ($candidates as $candidate) {
            $targetOperatorId = (int)($candidate['target_id_ope'] ?? 0);
            $targetDoctorId = (int)($candidate['target_id_dot'] ?? 0);

            if ($targetOperatorId <= 0) {
                $phase['visibility_skipped_missing_operator']++;
                continue;
            }
            if ($targetDoctorId <= 0) {
                $phase['visibility_skipped_missing_doctor']++;
                continue;
            }

            $key = $this->buildOperatorDoctorKey($targetOperatorId, $targetDoctorId);
            if (isset($this->targetAgendaVisibilityKeys[$key])) {
                $phase['visibility_skipped_existing']++;
                continue;
            }

            if ($this->apply) {
                $stmt = $this->db->prepare("
                    INSERT IGNORE INTO `{$this->targetDb}`.dap24_agenda_visibilita
                    (id_ope, id_dot, created_by, created_at)
                    VALUES (?, ?, NULL, NOW())
                ");
                $stmt->bind_param('ii', $targetOperatorId, $targetDoctorId);
                $stmt->execute();
                $affectedRows = $stmt->affected_rows;
                $stmt->close();
                if ($affectedRows <= 0) {
                    $phase['visibility_skipped_existing']++;
                    $this->targetAgendaVisibilityKeys[$key] = true;
                    continue;
                }
            }

            $phase['visibility_inserted']++;
            $this->targetAgendaVisibilityKeys[$key] = true;

            if (count($phase['visibility_examples']) < 30) {
                $phase['visibility_examples'][] = $candidate;
            }
        }

        $phase['status'] = $this->apply ? 'apply_done' : 'dry_run_done';
        $this->report['migrations']['operators_and_visibility'] = $phase;
        $this->logger->info('Migrazione operatori e visibilita agenda completata', [
            'operators_inserted_far01_ope' => $phase['operators_inserted_far01_ope'],
            'operators_missing_login_user_dap01' => $phase['operators_missing_login_user_dap01'],
            'visibility_candidates' => $phase['visibility_candidates'],
            'visibility_inserted' => $phase['visibility_inserted'],
            'visibility_skipped_existing' => $phase['visibility_skipped_existing'],
        ]);
    }

    private function migrateAgendaStructure(): void
    {
        $phase = [
            'structure_from' => $this->structureFrom,
            'structure_to' => $this->structureTo,
            'legacy_ambulatori_synced' => 0,
            'structure_rebuild_requested' => $this->rebuildStructure,
            'mail_active_configs_loaded' => $this->countActiveConfigs(),
            'legacy_doctors_with_snapshots' => 0,
            'legacy_snapshots' => 0,
            'legacy_segments' => 0,
            'legacy_segments_fully_covered_by_mail' => 0,
            'legacy_configs_inserted' => 0,
            'config_days_inserted' => 0,
            'config_fasce_inserted' => 0,
            'slot_configs_scanned' => 0,
            'slot_candidates' => 0,
            'slots_inserted' => 0,
            'slots_inserted_closed' => 0,
            'slots_skipped_existing' => 0,
            'mail_authoritative_configs_without_days' => 0,
            'mail_authoritative_examples' => [],
            'appointments_snapshotted_for_rebind' => 0,
            'slots_deleted' => 0,
            'configs_deleted' => 0,
            'config_days_deleted' => 0,
            'config_fasce_deleted' => 0,
            'blocked_days_deleted' => 0,
            'appointments_rebound' => 0,
            'appointments_missing_after_rebuild' => 0,
            'appointments_deleted_for_reimport' => 0,
            'target_far15_dropped' => false,
        ];

        if ($this->rebuildStructure) {
            $phase['appointments_snapshotted_for_rebind'] = $this->snapshotAppointmentsForStructureRebuild();
            $resetStats = $this->resetTargetAgendaStructure();
            $phase['slots_deleted'] = (int)($resetStats['slots_deleted'] ?? 0);
            $phase['configs_deleted'] = (int)($resetStats['configs_deleted'] ?? 0);
            $phase['config_days_deleted'] = (int)($resetStats['config_days_deleted'] ?? 0);
            $phase['config_fasce_deleted'] = (int)($resetStats['config_fasce_deleted'] ?? 0);
            $phase['blocked_days_deleted'] = (int)($resetStats['blocked_days_deleted'] ?? 0);
            $this->clearAgendaCaches();
            if ($this->apply) {
                $this->reloadAgendaCaches();
            }
            $phase['mail_active_configs_loaded'] = $this->countActiveConfigs();
        }

        $phase['legacy_ambulatori_synced'] = $this->syncLegacyAmbulatori();

        $legacyByDoctor = $this->loadLegacyAgendaSnapshots();
        $phase['legacy_doctors_with_snapshots'] = count($legacyByDoctor);
        foreach ($legacyByDoctor as $snapshots) {
            $phase['legacy_snapshots'] += count($snapshots);
        }

        foreach ($legacyByDoctor as $targetDoctorId => $snapshots) {
            $segments = $this->buildLegacyStructureSegments((int)$targetDoctorId, $snapshots);
            foreach ($segments as $segment) {
                $phase['legacy_segments']++;
                $uncovered = $this->subtractCoveredIntervals(
                    (string)$segment['data_inizio'],
                    (string)$segment['data_fine'],
                    $this->targetActiveConfigIntervalsByDoctor[(int)$targetDoctorId] ?? []
                );

                if ($uncovered === []) {
                    $phase['legacy_segments_fully_covered_by_mail']++;
                    if (count($phase['mail_authoritative_examples']) < 15) {
                        $phase['mail_authoritative_examples'][] = [
                            'target_id_dot' => (int)$targetDoctorId,
                            'data_inizio' => (string)$segment['data_inizio'],
                            'data_fine' => (string)$segment['data_fine'],
                            'reason' => 'covered_by_mail_active_config',
                        ];
                    }
                    continue;
                }

                foreach ($uncovered as $interval) {
                    $config = $this->buildLegacyConfigFromSegment((int)$targetDoctorId, $segment, $interval);
                    $insertStats = $this->upsertLegacyConfigPreview($config);
                    $phase['legacy_configs_inserted']++;
                    $phase['config_days_inserted'] += (int)$insertStats['days_inserted'];
                    $phase['config_fasce_inserted'] += (int)$insertStats['fasce_inserted'];
                }
            }
        }

        foreach ($this->iterateConfigsForStructureWindow() as $config) {
            $phase['slot_configs_scanned']++;
            $hasUsableDay = false;

            for ($day = 1; $day <= 7; $day++) {
                $dayRow = $config['days'][$day] ?? null;
                if ($dayRow === null) {
                    continue;
                }
                if (!empty($dayRow['giorno_libero'])) {
                    continue;
                }
                if (($dayRow['fasce'] ?? []) !== []) {
                    $hasUsableDay = true;
                    break;
                }
            }

            if (!$hasUsableDay) {
                $phase['mail_authoritative_configs_without_days']++;
                continue;
            }

            $overlapStart = $this->maxDate((string)$config['data_inizio'], $this->structureFrom);
            $overlapEnd = $this->minDate((string)$config['data_fine'], $this->structureTo);
            if ($overlapStart === null || $overlapEnd === null || $overlapStart > $overlapEnd) {
                continue;
            }

            $cursor = $overlapStart;
            while ($cursor <= $overlapEnd) {
                $weekday = (int)date('N', strtotime($cursor));
                $dayRow = $config['days'][$weekday] ?? null;
                if ($dayRow !== null && empty($dayRow['giorno_libero'])) {
                    foreach (($dayRow['fasce'] ?? []) as $fascia) {
                        $stats = $this->generateSlotsForFascia(
                            (int)$config['id_dot'],
                            (int)$config['id_config'],
                            $cursor,
                            $fascia
                        );
                        $phase['slot_candidates'] += (int)$stats['candidates'];
                        $phase['slots_inserted'] += (int)$stats['inserted'];
                        $phase['slots_inserted_closed'] += (int)$stats['inserted_closed'];
                        $phase['slots_skipped_existing'] += (int)$stats['skipped_existing'];
                    }
                }

                $cursor = $this->datePlusOneDay($cursor);
            }
        }

        if ($this->rebuildStructure) {
            $rebindStats = $this->rebindAppointmentsAfterStructureRebuild();
            $phase['appointments_rebound'] = (int)($rebindStats['rebound'] ?? 0);
            $phase['appointments_missing_after_rebuild'] = (int)($rebindStats['missing'] ?? 0);
            $phase['appointments_deleted_for_reimport'] = (int)($rebindStats['deleted_for_reimport'] ?? 0);
            $phase['target_far15_dropped'] = $this->dropTargetLegacyFar15IfRequested();
            $this->clearAgendaCaches();
            if ($this->apply) {
                $this->reloadAgendaCaches();
            }
        }

        $phase['status'] = $this->apply ? 'apply_done' : 'dry_run_done';
        $this->report['migrations']['agenda_structure'] = $phase;
        $this->logger->info('Riallineamento struttura agenda completato', $phase);
    }

    private function countActiveConfigs(): int
    {
        $count = 0;
        foreach ($this->targetActiveConfigsByDoctor as $configs) {
            $count += count($configs);
        }

        return $count;
    }

    private function syncLegacyAmbulatori(): int
    {
        if (
            !$this->tableExists($this->sourceDb, 'far22_amb')
            || !$this->tableExists($this->targetDb, 'dap42_ambulatori')
        ) {
            return 0;
        }

        $synced = 0;
        $sql = "
            SELECT
                id_amb,
                COALESCE(nome, '') AS nome,
                logo,
                COALESCE(indirizzo, '') AS indirizzo,
                COALESCE(citta, '') AS citta,
                COALESCE(telefono, '') AS telefono
            FROM `{$this->sourceDb}`.far22_amb
            ORDER BY id_amb ASC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $synced++;

            if (!$this->apply) {
                continue;
            }

            $stmt = $this->db->prepare("
                INSERT INTO `{$this->targetDb}`.dap42_ambulatori
                (id_amb_legacy, nome, logo, indirizzo, citta, telefono, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    nome = VALUES(nome),
                    logo = VALUES(logo),
                    indirizzo = VALUES(indirizzo),
                    citta = VALUES(citta),
                    telefono = VALUES(telefono),
                    updated_at = NOW()
            ");

            $idAmbLegacy = (int)($row['id_amb'] ?? 0);
            $nome = trim((string)($row['nome'] ?? ''));
            $logo = $row['logo'] ?? null;
            $indirizzo = trim((string)($row['indirizzo'] ?? ''));
            $citta = trim((string)($row['citta'] ?? ''));
            $telefono = trim((string)($row['telefono'] ?? ''));

            $stmt->bind_param(
                'isssss',
                $idAmbLegacy,
                $nome,
                $logo,
                $indirizzo,
                $citta,
                $telefono
            );
            $stmt->execute();
            $stmt->close();
        }
        $res->close();

        return $synced;
    }

    private function resetEntireTargetAgenda(): void
    {
        $phase = [
            'requested' => true,
            'tables' => [],
            'status' => $this->apply ? 'apply_pending' : 'dry_run_done',
        ];

        $orderedTables = [
            'dap12_agenda_appuntamenti',
            'dap11_agenda_slot',
            'dap21_agenda_giorni_bloccati',
            'dap23_agenda_nota_giorno',
            'dap15_agenda_note',
            'dap13_visite_domiciliari',
            'dap31_block_dom',
            'dap37_block_memo',
            'dap24_agenda_visibilita',
            'dap10_agenda_config_fasce',
            'dap10_agenda_config_giorni',
            'dap10_agenda_config',
        ];

        foreach ($orderedTables as $table) {
            if (!$this->tableExists($this->targetDb, $table)) {
                $phase['tables'][$table] = [
                    'exists' => false,
                    'rows_before' => 0,
                    'rows_deleted' => 0,
                ];
                continue;
            }

            $rowsBefore = (int)($this->db->query("SELECT COUNT(*) AS c FROM `{$this->targetDb}`.`{$table}`")->fetch_assoc()['c'] ?? 0);
            $phase['tables'][$table] = [
                'exists' => true,
                'rows_before' => $rowsBefore,
                'rows_deleted' => $rowsBefore,
            ];
        }

        if (!$this->apply) {
            $this->report['migrations']['agenda_global_reset'] = $phase;
            $this->logger->info('DRY RUN reset totale agenda target calcolato', $phase);
            return;
        }

        $this->db->begin_transaction();
        try {
            foreach ($orderedTables as $table) {
                if (!$this->tableExists($this->targetDb, $table)) {
                    continue;
                }

                $this->db->query("DELETE FROM `{$this->targetDb}`.`{$table}`");
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $phase['status'] = 'apply_done';
        $this->report['migrations']['agenda_global_reset'] = $phase;
        $this->logger->info('Reset totale agenda target completato', $phase);
    }

    private function loadLegacyDurationHints(): array
    {
        if ($this->legacyDurationHintsByDoctorDateDay !== []) {
            return $this->legacyDurationHintsByDoctorDateDay;
        }

        if (
            !$this->tableExists($this->sourceDb, 'far16_fas_dot')
            || !$this->tableExists($this->sourceDb, 'far17_fas')
        ) {
            return [];
        }

        $sql = "
            SELECT
                id_dot,
                id_giorno,
                COALESCE(data_ini_val, '') AS data_ini_val,
                fm.n_min AS durata_mattina,
                fp.n_min AS durata_pomeriggio
            FROM `{$this->sourceDb}`.far16_fas_dot fd
            LEFT JOIN `{$this->sourceDb}`.far17_fas fm
              ON fm.id_fas = fd.id_fas_mat
            LEFT JOIN `{$this->sourceDb}`.far17_fas fp
              ON fp.id_fas = fd.id_fas_pom
            ORDER BY id_dot ASC, STR_TO_DATE(NULLIF(data_ini_val, ''), '%d/%m/%Y') ASC, id_giorno ASC
        ";

        $votes = [];
        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $targetDoctorId = $this->resolveTargetDoctorId($this->sourceDb, (int)($row['id_dot'] ?? 0));
            if ($targetDoctorId === null || !$this->isDoctorAllowed($targetDoctorId)) {
                continue;
            }

            $dayNumber = (int)($row['id_giorno'] ?? 0);
            if ($dayNumber < 1 || $dayNumber > 7) {
                continue;
            }

            $effectiveFrom = $this->parseLegacyDay((string)($row['data_ini_val'] ?? '')) ?? '1900-01-01';
            $durataMattina = (int)($row['durata_mattina'] ?? 0);
            $durataPomeriggio = (int)($row['durata_pomeriggio'] ?? 0);

            if ($durataMattina > 0) {
                $votes[$targetDoctorId][$effectiveFrom][$dayNumber]['M'][$durataMattina] =
                    ($votes[$targetDoctorId][$effectiveFrom][$dayNumber]['M'][$durataMattina] ?? 0) + 1;
            }
            if ($durataPomeriggio > 0) {
                $votes[$targetDoctorId][$effectiveFrom][$dayNumber]['P'][$durataPomeriggio] =
                    ($votes[$targetDoctorId][$effectiveFrom][$dayNumber]['P'][$durataPomeriggio] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($votes as $targetDoctorId => $dates) {
            foreach ($dates as $effectiveFrom => $days) {
                foreach ($days as $dayNumber => $types) {
                    foreach ($types as $type => $durations) {
                        arsort($durations);
                        $bestDuration = (int)array_key_first($durations);
                        if ($bestDuration > 0) {
                            $out[$targetDoctorId][$effectiveFrom][$dayNumber][$type] = $bestDuration;
                        }
                    }
                }
            }
        }

        $this->legacyDurationHintsByDoctorDateDay = $out;

        return $out;
    }

    private function loadLegacyAgendaSnapshots(): array
    {
        $raw = [];
        $seenRows = [];
        $legacyDurationHints = $this->loadLegacyDurationHints();
        $schema = $this->sourceDb;

        if (!$this->tableExists($schema, 'far15_fas_ora_dot')) {
            return [];
        }

        $hasAmbulatoriTable = $this->tableExists($schema, 'far22_amb');
        $ambulatorioSelect = $hasAmbulatoriTable
            ? "COALESCE(amb.nome, '') AS ambulatorio"
            : "'' AS ambulatorio";
        $ambulatorioJoin = $hasAmbulatoriTable
            ? "LEFT JOIN `{$schema}`.far22_amb amb\n              ON amb.id_amb = f15.id_amb"
            : '';

        $sql = "
            SELECT
                '{$schema}' AS source_schema,
                f15.id_dot,
                f15.id_giorno,
                COALESCE(f15.tipo_orario, '') AS tipo_orario,
                COALESCE(f15.data_ini_val, '') AS data_ini_val,
                f15.ora_ini,
                f15.ora_fin,
                COALESCE(f15.id_amb, 0) AS id_amb_legacy,
                {$ambulatorioSelect},
                COALESCE(f15.stanza, '') AS stanza
            FROM `{$schema}`.far15_fas_ora_dot f15
            {$ambulatorioJoin}
            ORDER BY f15.id_dot ASC, STR_TO_DATE(NULLIF(f15.data_ini_val, ''), '%d/%m/%Y') ASC, f15.id_giorno ASC, f15.tipo_orario ASC, f15.ora_ini ASC, f15.ora_fin ASC
        ";

        $res = $this->db->query($sql, MYSQLI_USE_RESULT);
        while ($row = $res->fetch_assoc()) {
            $targetDoctorId = $this->resolveTargetDoctorId($schema, (int)$row['id_dot']);
            if ($targetDoctorId === null || !$this->isDoctorAllowed($targetDoctorId)) {
                continue;
            }

            $dayNumber = (int)($row['id_giorno'] ?? 0);
            if ($dayNumber < 1 || $dayNumber > 7) {
                continue;
            }

            $effectiveFrom = $this->parseLegacyDay((string)$row['data_ini_val']) ?? '1900-01-01';
            $type = strtoupper(trim((string)$row['tipo_orario']));
            $startTime = $this->normalizeTime((string)($row['ora_ini'] ?? ''));
            $endTime = $this->normalizeTime((string)($row['ora_fin'] ?? ''));
            $idAmbLegacy = (int)($row['id_amb_legacy'] ?? 0);
            $ambulatorio = trim((string)($row['ambulatorio'] ?? ''));
            $stanza = trim((string)($row['stanza'] ?? ''));
            $rowKey = implode('|', [$targetDoctorId, $effectiveFrom, $dayNumber, $type, $startTime, $endTime, $idAmbLegacy, $ambulatorio, $stanza]);
            if (isset($seenRows[$rowKey])) {
                continue;
            }
            $seenRows[$rowKey] = true;

            if (!isset($raw[$targetDoctorId][$effectiveFrom])) {
                $raw[$targetDoctorId][$effectiveFrom] = [
                    'target_id_dot' => $targetDoctorId,
                    'effective_from' => $effectiveFrom,
                    'source_schemas' => [],
                    'days_raw' => [],
                ];
            }

            $raw[$targetDoctorId][$effectiveFrom]['source_schemas'][$schema] = true;
            if (!isset($raw[$targetDoctorId][$effectiveFrom]['days_raw'][$dayNumber])) {
                $raw[$targetDoctorId][$effectiveFrom]['days_raw'][$dayNumber] = [
                    'slots' => [],
                    'markers' => [],
                ];
            }

            if ($startTime !== '' && $endTime !== '' && in_array($type, ['M', 'P'], true)) {
                $duration = $this->minutesBetweenTimes($startTime, $endTime);
                if ($duration > 0) {
                    $raw[$targetDoctorId][$effectiveFrom]['days_raw'][$dayNumber]['slots'][] = [
                        'ora_inizio' => $startTime,
                        'ora_fine' => $endTime,
                        'durata_slot' => $duration,
                        'legacy_tipo_orario' => $type,
                        'id_amb_legacy' => $idAmbLegacy,
                        'ambulatorio' => $ambulatorio,
                        'stanza' => $stanza,
                    ];
                }
            } else {
                $raw[$targetDoctorId][$effectiveFrom]['days_raw'][$dayNumber]['markers'][$type] = true;
            }
        }
        $res->close();

        $out = [];
        foreach ($raw as $targetDoctorId => $snapshotsByDate) {
            ksort($snapshotsByDate);
            $carryDays = $this->buildClosedWeek();
            foreach ($snapshotsByDate as $snapshot) {
                $currentDays = $carryDays;
                foreach (($snapshot['days_raw'] ?? []) as $dayNumber => $rawDay) {
                    $durationHints = $legacyDurationHints[(int)$targetDoctorId][(string)$snapshot['effective_from']][(int)$dayNumber] ?? [];
                    $currentDays[(int)$dayNumber] = $this->buildLegacyDayDefinition((int)$dayNumber, $rawDay, $durationHints);
                }

                $out[(int)$targetDoctorId][] = [
                    'target_id_dot' => (int)$targetDoctorId,
                    'effective_from' => (string)$snapshot['effective_from'],
                    'source_schemas' => array_keys($snapshot['source_schemas']),
                    'days' => $currentDays,
                ];
                $carryDays = $currentDays;
            }
        }

        return $out;
    }

    private function buildLegacyStructureSegments(int $targetDoctorId, array $snapshots): array
    {
        $segments = [];
        $count = count($snapshots);

        for ($index = 0; $index < $count; $index++) {
            $snapshot = $snapshots[$index];
            $segmentStart = $this->maxDate((string)$snapshot['effective_from'], $this->structureFrom);
            $segmentEnd = LEGACY_OPEN_ENDED_DATE;
            if (isset($snapshots[$index + 1])) {
                $segmentEnd = $this->dateMinusOneDay((string)$snapshots[$index + 1]['effective_from']);
            }

            $segmentEnd = $this->minDate($segmentEnd, $this->structureTo);
            if ($segmentStart === null || $segmentEnd === null || $segmentStart > $segmentEnd) {
                continue;
            }

            $segments[] = [
                'target_id_dot' => $targetDoctorId,
                'effective_from' => (string)$snapshot['effective_from'],
                'data_inizio' => $segmentStart,
                'data_fine' => $segmentEnd,
                'source_schemas' => $snapshot['source_schemas'] ?? [],
                'days' => $snapshot['days'] ?? $this->buildClosedWeek(),
            ];
        }

        return $segments;
    }

    private function buildLegacyConfigFromSegment(int $targetDoctorId, array $segment, array $interval): array
    {
        return [
            'id_config' => 0,
            'id_dot' => $targetDoctorId,
            'data_inizio' => (string)$interval['start'],
            'data_fine' => (string)$interval['end'],
            'descrizione' => 'Ricostruito da far15/far16/far17 legacy',
            'days' => $segment['days'] ?? $this->buildClosedWeek(),
        ];
    }

    private function upsertLegacyConfigPreview(array $config): array
    {
        $configWithId = $config;
        $configWithId['id_config'] = $this->apply ? 0 : $this->dryRunConfigId--;
        $daysInserted = 0;
        $fasceInserted = 0;
        $now = date('Y-m-d H:i:s');

        if ($this->apply) {
            $this->db->begin_transaction();
            try {
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->targetDb}`.dap10_agenda_config
                    (id_dot, data_inizio, data_fine, descrizione, attiva, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 1, NULL, NOW(), NOW())
                ");
                $stmt->bind_param(
                    'isss',
                    $config['id_dot'],
                    $config['data_inizio'],
                    $config['data_fine'],
                    $config['descrizione']
                );
                $stmt->execute();
                $configWithId['id_config'] = (int)$this->db->insert_id;
                $stmt->close();

                for ($day = 1; $day <= 7; $day++) {
                    $dayRow = $config['days'][$day] ?? $this->buildClosedDay($day);
                    $dayInsert = $this->buildLegacyDayColumnsFromFasce($dayRow);

                    $stmt = $this->db->prepare("
                        INSERT INTO `{$this->targetDb}`.dap10_agenda_config_giorni
                        (
                            id_config, giorno_settimana, giorno_libero, mattina_attiva, mattina_ora_inizio, mattina_ora_fine,
                            mattina_durata_slot, pomeriggio_attiva, pomeriggio_modalita_inizio, pomeriggio_ora_inizio,
                            pomeriggio_ora_fine, pomeriggio_durata_slot, created_at, updated_at
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->bind_param(
                        'iiiissiisssiss',
                        $configWithId['id_config'],
                        $day,
                        $dayInsert['giorno_libero'],
                        $dayInsert['mattina_attiva'],
                        $dayInsert['mattina_ora_inizio'],
                        $dayInsert['mattina_ora_fine'],
                        $dayInsert['mattina_durata_slot'],
                        $dayInsert['pomeriggio_attiva'],
                        $dayInsert['pomeriggio_modalita_inizio'],
                        $dayInsert['pomeriggio_ora_inizio'],
                        $dayInsert['pomeriggio_ora_fine'],
                        $dayInsert['pomeriggio_durata_slot'],
                        $now,
                        $now
                    );
                    $stmt->execute();
                    $idConfigGiorno = (int)$this->db->insert_id;
                    $stmt->close();

                    $configWithId['days'][$day]['id_config_giorno'] = $idConfigGiorno;
                    $daysInserted++;

                    foreach (($dayRow['fasce'] ?? []) as $index => $fascia) {
                        $stmt = $this->db->prepare("
                            INSERT INTO `{$this->targetDb}`.dap10_agenda_config_fasce
                            (
                                id_config_giorno, ordine, ora_inizio, ora_fine, durata_slot,
                                id_amb_legacy, ambulatorio, stanza, created_at, updated_at
                            )
                            VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?)
                        ");
                        $ordine = $index + 1;
                        $idAmbLegacy = (int)($fascia['id_amb_legacy'] ?? 0);
                        $ambulatorio = trim((string)($fascia['ambulatorio'] ?? ''));
                        $stanza = trim((string)($fascia['stanza'] ?? ''));
                        $stmt->bind_param(
                            'iissiissss',
                            $idConfigGiorno,
                            $ordine,
                            $fascia['ora_inizio'],
                            $fascia['ora_fine'],
                            $fascia['durata_slot'],
                            $idAmbLegacy,
                            $ambulatorio,
                            $stanza,
                            $now,
                            $now
                        );
                        $stmt->execute();
                        $stmt->close();
                        $fasceInserted++;
                    }
                }

                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
        } else {
            for ($day = 1; $day <= 7; $day++) {
                $configWithId['days'][$day]['id_config_giorno'] = $this->dryRunConfigDayId--;
                $daysInserted++;
                $fasceInserted += count($configWithId['days'][$day]['fasce'] ?? []);
            }
        }

        $this->registerActiveConfig($configWithId);

        return [
            'days_inserted' => $daysInserted,
            'fasce_inserted' => $fasceInserted,
        ];
    }

    private function iterateConfigsForStructureWindow(): \Generator
    {
        $configs = [];
        foreach ($this->targetActiveConfigsByDoctor as $doctorConfigs) {
            foreach ($doctorConfigs as $config) {
                $configs[] = $config;
            }
        }

        usort($configs, static function (array $left, array $right): int {
            $leftKey = $left['data_inizio'] . '|' . str_pad((string)$left['id_dot'], 6, '0', STR_PAD_LEFT) . '|' . str_pad((string)$left['id_config'], 10, '0', STR_PAD_LEFT);
            $rightKey = $right['data_inizio'] . '|' . str_pad((string)$right['id_dot'], 6, '0', STR_PAD_LEFT) . '|' . str_pad((string)$right['id_config'], 10, '0', STR_PAD_LEFT);
            return strcmp($leftKey, $rightKey);
        });

        foreach ($configs as $config) {
            yield $config;
        }
    }

    private function generateSlotsForFascia(int $targetDoctorId, int $configId, string $date, array $fascia): array
    {
        $stats = [
            'candidates' => 0,
            'inserted' => 0,
            'inserted_closed' => 0,
            'skipped_existing' => 0,
        ];

        $oraInizio = $this->normalizeTime((string)($fascia['ora_inizio'] ?? ''));
        $oraFine = $this->normalizeTime((string)($fascia['ora_fine'] ?? ''));
        $durata = (int)($fascia['durata_slot'] ?? 0);
        if ($oraInizio === '' || $oraFine === '' || $durata <= 0) {
            return $stats;
        }

        $cursor = new \DateTime($date . ' ' . $oraInizio);
        $limit = new \DateTime($date . ' ' . $oraFine);
        $dayKey = $this->buildDoctorDayKey($targetDoctorId, $date);
        $slotState = isset($this->targetBlockedDayKeys[$dayKey]) ? 'CHIUSO' : 'LIBERO';
        $idAmbLegacy = (int)($fascia['id_amb_legacy'] ?? 0);
        $ambulatorio = trim((string)($fascia['ambulatorio'] ?? ''));
        $stanza = trim((string)($fascia['stanza'] ?? ''));

        while ($cursor < $limit) {
            $slotStart = clone $cursor;
            $slotEnd = (clone $cursor)->modify('+' . $durata . ' minutes');
            if ($slotEnd > $limit) {
                break;
            }

            $slotStartText = $slotStart->format('Y-m-d H:i:s');
            $slotEndText = $slotEnd->format('Y-m-d H:i:s');
            $slotKey = $this->buildSlotKey($targetDoctorId, $slotStartText, $slotEndText);
            $stats['candidates']++;

            if (isset($this->targetSlotsByKey[$slotKey])) {
                $stats['skipped_existing']++;
                $cursor->modify('+' . $durata . ' minutes');
                continue;
            }

            if ($this->apply) {
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->targetDb}`.dap11_agenda_slot
                    (
                        id_dot, id_config, data_slot, ora_inizio, ora_fine, tipo_slot, stato,
                        titolo_libero, id_amb_legacy, ambulatorio, stanza, origine_slot, note_interne, created_at, updated_at
                    )
                    VALUES (?, ?, ?, ?, ?, 'AMBULATORIO', ?, NULL, NULLIF(?, 0), ?, ?, 'CONFIG', ?, NOW(), NOW())
                ");
                $noteInterne = 'Riallineato da configurazione agenda';
                $stmt->bind_param(
                    'iissssisss',
                    $targetDoctorId,
                    $configId,
                    $date,
                    $slotStartText,
                    $slotEndText,
                    $slotState,
                    $idAmbLegacy,
                    $ambulatorio,
                    $stanza,
                    $noteInterne
                );
                $stmt->execute();
                $slotId = (int)$this->db->insert_id;
                $stmt->close();
            } else {
                $slotId = $this->dryRunSlotId--;
            }

            $this->registerGeneratedSlotCache($slotId, $targetDoctorId, $configId, $date, $slotStartText, $slotEndText, $slotState);
            $stats['inserted']++;
            if ($slotState === 'CHIUSO') {
                $stats['inserted_closed']++;
            }

            $cursor->modify('+' . $durata . ' minutes');
        }

        return $stats;
    }

    private function migrateDailyNotes(): void
    {
        $phase = [
            'candidates' => 0,
            'inserted' => 0,
            'skipped_existing' => 0,
            'skipped_conflict' => 0,
            'merged_conflicts' => 0,
            'skipped_blank' => 0,
            'skipped_missing_doctor' => 0,
            'conflict_examples' => [],
        ];

        if (!$this->tableExists($this->sourceDb, 'far21_note')) {
            $this->report['migrations']['daily_notes'] = $phase + ['status' => 'source_table_missing'];
            $this->logger->warning('Migrazione note giornaliere saltata: tabella legacy assente');
            return;
        }

        $grouped = $this->loadLegacyDailyNotes();
        $phase['candidates'] = count($grouped);
        $processed = 0;

        foreach ($grouped as $candidate) {
            $processed++;

            $dayKey = $this->buildDoctorDayKey((int)$candidate['target_id_dot'], (string)$candidate['data_agenda']);
            $exactKey = $dayKey . '|' . md5((string)$candidate['nota']);

            if (isset($this->targetDailyNotesByExactKey[$exactKey])) {
                $phase['skipped_existing']++;
                $this->logger->debug('Note giornaliere gia presenti', $candidate);
                continue;
            }

            if (isset($this->targetDailyNotesByDay[$dayKey])) {
                $existingRow = $this->targetDailyNotesByDay[$dayKey];
                $mergedNote = $this->mergeLegacyTextBlocks(
                    (string)($existingRow['nota'] ?? ''),
                    (string)$candidate['nota']
                );

                if ($mergedNote === (string)($existingRow['nota'] ?? '')) {
                    $phase['skipped_existing']++;
                    continue;
                }

                $phase['merged_conflicts']++;
                if (count($phase['conflict_examples']) < 20) {
                    $phase['conflict_examples'][] = [
                        'candidate' => $candidate,
                        'target' => $existingRow,
                        'merged_nota' => $mergedNote,
                    ];
                }
                if ($this->apply && (int)($existingRow['id_nota_giorno'] ?? 0) > 0) {
                    $stmt = $this->db->prepare("
                        UPDATE `{$this->targetDb}`.dap23_agenda_nota_giorno
                        SET nota = ?, updated_at = NOW()
                        WHERE id_nota_giorno = ?
                        LIMIT 1
                    ");
                    $idNotaGiorno = (int)$existingRow['id_nota_giorno'];
                    $stmt->bind_param('si', $mergedNote, $idNotaGiorno);
                    $stmt->execute();
                    $stmt->close();
                }
                $this->targetDailyNotesByDay[$dayKey] = [
                    'id_nota_giorno' => (int)($existingRow['id_nota_giorno'] ?? 0),
                    'id_dot' => (int)$candidate['target_id_dot'],
                    'data_agenda' => (string)$candidate['data_agenda'],
                    'nota' => $mergedNote,
                ];
                $this->targetDailyNotesByExactKey[$dayKey . '|' . md5($mergedNote)] = true;
                $this->logger->warning('Note giornaliere legacy fuse con nota gia presente', [
                    'target_id_dot' => $candidate['target_id_dot'],
                    'data_agenda' => $candidate['data_agenda'],
                ]);
                continue;
            }

            if ($this->apply) {
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->targetDb}`.dap23_agenda_nota_giorno
                    (id_dot, data_agenda, nota, created_by, updated_by, created_at, updated_at)
                    VALUES (?, ?, ?, NULL, NULL, NOW(), NOW())
                ");
                $stmt->bind_param(
                    'iss',
                    $candidate['target_id_dot'],
                    $candidate['data_agenda'],
                    $candidate['nota']
                );
                $stmt->execute();
                $stmt->close();
            }

            $phase['inserted']++;
            $this->targetDailyNotesByDay[$dayKey] = [
                'id_nota_giorno' => 0,
                'id_dot' => (int)$candidate['target_id_dot'],
                'data_agenda' => (string)$candidate['data_agenda'],
                'nota' => (string)$candidate['nota'],
            ];
            $this->targetDailyNotesByExactKey[$exactKey] = true;
            $this->logger->debug($this->apply ? 'Inserita nota giornaliera' : 'DRY RUN nota giornaliera', $candidate);

            if ($processed % 500 === 0) {
                $this->logger->info('Progresso note giornaliere', [
                    'processed' => $processed,
                    'candidates' => $phase['candidates'],
                    'inserted' => $phase['inserted'],
                ]);
            }
        }

        $phase['status'] = $this->apply ? 'apply_done' : 'dry_run_done';
        $this->report['migrations']['daily_notes'] = $phase;
        $this->logger->info('Migrazione note giornaliere completata', $phase);
    }

    private function migrateAgendaMemoNotes(): void
    {
        $phase = [
            'candidates' => 0,
            'inserted' => 0,
            'inserted_done' => 0,
            'inserted_pending' => 0,
            'skipped_existing' => 0,
            'skipped_missing_doctor' => 0,
            'skipped_invalid_date' => 0,
            'linked_target_patients' => 0,
            'linked_target_clients' => 0,
            'missing_target_patient_link' => 0,
            'missing_target_client_link' => 0,
            'missing_patient_examples' => [],
        ];

        if (!$this->tableExists($this->sourceDb, 'far29_not_dot')) {
            $this->report['migrations']['agenda_memo_notes'] = $phase + ['status' => 'source_table_missing'];
            $this->logger->warning('Migrazione memo agenda saltata: tabella legacy assente');
            return;
        }

        $sql = "
            SELECT
                n.id_not_dot,
                n.id_dot,
                n.data_ins,
                n.data_ese,
                COALESCE(n.note, '') AS note,
                COALESCE(n.id_ope, 0) AS id_ope,
                COALESCE(n.fatto, 0) AS fatto,
                n.data_val,
                COALESCE(p.id_paziente, 0) AS id_paziente,
                COALESCE(p.cognome, '') AS paz_cognome,
                COALESCE(p.nome, '') AS paz_nome,
                COALESCE(p.data_nascita, '') AS paz_data_nascita,
                COALESCE(p.cod_fis, '') AS paz_cod_fis,
                COALESCE(p.comune_nascita, '') AS paz_comune_nascita,
                COALESCE(p.provincia_nascita, '') AS paz_provincia_nascita,
                COALESCE(p.indirizzo, '') AS paz_indirizzo,
                COALESCE(p.citta, '') AS paz_citta,
                COALESCE(p.cap, '') AS paz_cap,
                COALESCE(p.provincia, '') AS paz_provincia,
                COALESCE(p.residenza_indirizzo, '') AS paz_residenza_indirizzo,
                COALESCE(p.residenza_comune, '') AS paz_residenza_comune,
                COALESCE(p.residenza_cap, '') AS paz_residenza_cap,
                COALESCE(p.residenza_provincia, '') AS paz_residenza_provincia,
                COALESCE(p.telefono, '') AS paz_telefono,
                COALESCE(p.cellulare, '') AS paz_cellulare,
                COALESCE(p.email, '') AS paz_email,
                COALESCE(p.paz_spec, '') AS paz_spec,
                COALESCE(p.bloccato, 0) AS paz_bloccato,
                COALESCE(p.id_dot, 0) AS paz_id_dot
            FROM `{$this->sourceDb}`.far29_not_dot n
            LEFT JOIN `{$this->sourceDb}`.far05_pazienti p
              ON p.id_paziente = n.id_paziente
            WHERE COALESCE(n.data_ins, '1900-01-01 00:00:00') >= '{$this->db->real_escape_string($this->notesFrom)} 00:00:00'
            ORDER BY n.id_not_dot ASC
        ";

        $res = $this->db->query($sql, MYSQLI_USE_RESULT);
        $processed = 0;

        while ($row = $res->fetch_assoc()) {
            $processed++;
            $phase['candidates']++;

            $sourceDoctorId = (int)($row['id_dot'] ?? 0);
            $targetDoctorId = $this->resolveTargetDoctorId($this->sourceDb, $sourceDoctorId);
            if ($targetDoctorId === null || !$this->isDoctorAllowed($targetDoctorId)) {
                $phase['skipped_missing_doctor']++;
                continue;
            }

            $dataInizioValidita = $this->normalizeReasonableDate((string)($row['data_val'] ?? ''))
                ?? $this->normalizeReasonableDate((string)($row['data_ins'] ?? ''))
                ?? $this->normalizeReasonableDate((string)($row['data_ese'] ?? ''));
            if ($dataInizioValidita === null) {
                $phase['skipped_invalid_date']++;
                continue;
            }

            $createdAt = $this->normalizeReasonableDateTime((string)($row['data_ins'] ?? ''))
                ?? ($dataInizioValidita . ' 00:00:00');
            $dataFatta = (int)($row['fatto'] ?? 0) === 1
                ? ($this->normalizeReasonableDateTime((string)($row['data_ese'] ?? '')) ?? $createdAt)
                : null;
            $updatedAt = $dataFatta ?? $createdAt;

            $candidate = $this->buildLegacyPatientCandidateFromSourceRow($row, $targetDoctorId);
            $candidate['source_schema'] = $this->sourceDb;
            $candidate['target_id_dot'] = $targetDoctorId;
            $candidate['data_inizio_validita'] = $dataInizioValidita;
            $candidate['cliente'] = $this->composeLegacyPatientLabel($row);
            $candidate['note'] = trim((string)($row['note'] ?? ''));
            $candidate['testo'] = $candidate['note'];
            $candidate['fatta'] = (int)($row['fatto'] ?? 0) === 1 ? 1 : 0;
            $candidate['legacy_id_not_dot'] = (int)($row['id_not_dot'] ?? 0);
            $candidate['legacy_created_at'] = $createdAt;
            $candidate['legacy_updated_at'] = $updatedAt;
            $candidate['legacy_data_fatta'] = $dataFatta;

            $legacyId = (int)$candidate['legacy_id_not_dot'];
            $signature = $this->buildAgendaMemoSignature($candidate);
            if (
                ($legacyId > 0 && isset($this->targetAgendaNotesByLegacyId[$legacyId]))
                || ($signature !== '' && isset($this->targetAgendaNotesBySignature[$signature]))
            ) {
                $phase['skipped_existing']++;
                continue;
            }

            $patientResolution = $this->resolveTargetPatientLinkOnly($candidate);
            if ((int)($patientResolution['id_paziente'] ?? 0) > 0) {
                $phase['linked_target_patients']++;
            } else {
                $phase['missing_target_patient_link']++;
                if (count($phase['missing_patient_examples']) < 25) {
                    $phase['missing_patient_examples'][] = $this->buildMissingPatientExample('memo', $candidate, $patientResolution);
                }
            }

            if ((int)($patientResolution['id_client'] ?? 0) > 0) {
                $phase['linked_target_clients']++;
            } else {
                $phase['missing_target_client_link']++;
            }

            $createdBy = $this->resolveTargetOperatorId($this->sourceDb, (int)($row['id_ope'] ?? 0));
            $noteId = 0;
            if ($this->apply) {
                $noteId = $this->insertAgendaMemoNote($candidate, $patientResolution, $createdBy);
            }

            $phase['inserted']++;
            if ((int)$candidate['fatta'] === 1) {
                $phase['inserted_done']++;
            } else {
                $phase['inserted_pending']++;
            }

            if ($legacyId > 0) {
                $this->targetAgendaNotesByLegacyId[$legacyId] = $noteId;
            }
            if ($signature !== '') {
                $this->targetAgendaNotesBySignature[$signature] = $noteId;
            }

            if ($processed % 2000 === 0) {
                $this->logger->info('Progresso memo agenda legacy', [
                    'processed' => $processed,
                    'candidates' => $phase['candidates'],
                    'inserted' => $phase['inserted'],
                    'missing_target_patient_link' => $phase['missing_target_patient_link'],
                ]);
            }
        }
        $res->close();

        $phase['status'] = $this->apply ? 'apply_done' : 'dry_run_done';
        $this->report['migrations']['agenda_memo_notes'] = $phase;
        $this->logger->info('Migrazione memo agenda completata', $phase);
    }

    private function migrateMemoBlockedDays(): void
    {
        $phase = [
            'candidates' => 0,
            'inserted' => 0,
            'skipped_existing' => 0,
            'skipped_missing_doctor' => 0,
            'skipped_invalid_date' => 0,
        ];

        if (!$this->tableExists($this->sourceDb, 'far37_block_memo')) {
            $this->report['migrations']['memo_blocked_days'] = $phase + ['status' => 'source_table_missing'];
            $this->logger->warning('Migrazione block memo saltata: tabella legacy assente');
            return;
        }

        if (!$this->tableExists($this->targetDb, 'dap37_block_memo')) {
            $this->report['migrations']['memo_blocked_days'] = $phase + ['status' => 'target_table_missing'];
            $this->logger->warning('Migrazione block memo saltata: tabella target assente');
            return;
        }

        $sql = "
            SELECT id_stampa, id_medico, giorno
            FROM `{$this->sourceDb}`.far37_block_memo
            WHERE STR_TO_DATE(giorno, '%d/%m/%Y') >= '{$this->db->real_escape_string($this->blockedFrom)}'
            ORDER BY STR_TO_DATE(giorno, '%d/%m/%Y') ASC, id_medico ASC, id_stampa ASC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $phase['candidates']++;

            $targetDoctorId = $this->resolveTargetDoctorId($this->sourceDb, (int)($row['id_medico'] ?? 0));
            if ($targetDoctorId === null || !$this->isDoctorAllowed($targetDoctorId)) {
                $phase['skipped_missing_doctor']++;
                continue;
            }

            $dataAgenda = $this->parseLegacyDay((string)($row['giorno'] ?? ''));
            if ($dataAgenda === null) {
                $phase['skipped_invalid_date']++;
                continue;
            }

            $dayKey = $this->buildDoctorDayKey($targetDoctorId, $dataAgenda);
            if (isset($this->targetMemoBlockedDayKeys[$dayKey])) {
                $phase['skipped_existing']++;
                continue;
            }

            if ($this->apply) {
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->targetDb}`.dap37_block_memo
                    (id_dot, data_agenda, legacy_id_stampa, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                ");
                $legacyId = (int)($row['id_stampa'] ?? 0);
                $stmt->bind_param('isi', $targetDoctorId, $dataAgenda, $legacyId);
                $stmt->execute();
                $stmt->close();
            }

            $phase['inserted']++;
            $this->targetMemoBlockedDayKeys[$dayKey] = true;
        }
        $res->close();

        $phase['status'] = $this->apply ? 'apply_done' : 'dry_run_done';
        $this->report['migrations']['memo_blocked_days'] = $phase;
        $this->logger->info('Migrazione giorni bloccati memo completata', $phase);
    }

    private function migrateDomiciliari(): void
    {
        $phase = [
            'candidates' => 0,
            'inserted' => 0,
            'inserted_active' => 0,
            'inserted_archived' => 0,
            'skipped_existing' => 0,
            'skipped_missing_doctor' => 0,
            'skipped_invalid_date' => 0,
            'linked_target_patients' => 0,
            'linked_target_clients' => 0,
            'missing_target_patient_link' => 0,
            'missing_target_client_link' => 0,
            'missing_patient_examples' => [],
        ];

        if (!$this->tableExists($this->sourceDb, 'far11_vis_dom')) {
            $this->report['migrations']['domiciliari'] = $phase + ['status' => 'source_table_missing'];
            $this->logger->warning('Migrazione domiciliari saltata: tabella legacy assente');
            return;
        }

        $sql = "
            SELECT
                v.id_vis,
                v.id_med,
                v.giorno,
                v.data_ora_ins,
                v.data_ora_mod,
                COALESCE(v.note, '') AS note,
                COALESCE(p.id_paziente, 0) AS id_paziente,
                COALESCE(p.cognome, '') AS paz_cognome,
                COALESCE(p.nome, '') AS paz_nome,
                COALESCE(p.data_nascita, '') AS paz_data_nascita,
                COALESCE(p.cod_fis, '') AS paz_cod_fis,
                COALESCE(p.comune_nascita, '') AS paz_comune_nascita,
                COALESCE(p.provincia_nascita, '') AS paz_provincia_nascita,
                COALESCE(p.indirizzo, '') AS paz_indirizzo,
                COALESCE(p.citta, '') AS paz_citta,
                COALESCE(p.cap, '') AS paz_cap,
                COALESCE(p.provincia, '') AS paz_provincia,
                COALESCE(p.residenza_indirizzo, '') AS paz_residenza_indirizzo,
                COALESCE(p.residenza_comune, '') AS paz_residenza_comune,
                COALESCE(p.residenza_cap, '') AS paz_residenza_cap,
                COALESCE(p.residenza_provincia, '') AS paz_residenza_provincia,
                COALESCE(p.telefono, '') AS paz_telefono,
                COALESCE(p.cellulare, '') AS paz_cellulare,
                COALESCE(p.email, '') AS paz_email,
                COALESCE(p.paz_spec, '') AS paz_spec,
                COALESCE(p.bloccato, 0) AS paz_bloccato,
                COALESCE(p.id_dot, 0) AS paz_id_dot
            FROM `{$this->sourceDb}`.far11_vis_dom v
            LEFT JOIN `{$this->sourceDb}`.far05_pazienti p
              ON p.id_paziente = v.id_paziente
            WHERE v.giorno >= '{$this->db->real_escape_string($this->notesFrom)}'
            ORDER BY v.giorno ASC, v.id_med ASC, v.id_vis ASC
        ";

        $res = $this->db->query($sql, MYSQLI_USE_RESULT);
        $processed = 0;
        $today = date('Y-m-d');

        while ($row = $res->fetch_assoc()) {
            $processed++;
            $phase['candidates']++;

            $sourceDoctorId = (int)($row['id_med'] ?? 0);
            $targetDoctorId = $this->resolveTargetDoctorId($this->sourceDb, $sourceDoctorId);
            if ($targetDoctorId === null || !$this->isDoctorAllowed($targetDoctorId)) {
                $phase['skipped_missing_doctor']++;
                continue;
            }

            $giornoVisita = $this->normalizeReasonableDate((string)($row['giorno'] ?? ''))
                ?? $this->normalizeReasonableDate((string)($row['data_ora_ins'] ?? ''));
            if ($giornoVisita === null) {
                $phase['skipped_invalid_date']++;
                continue;
            }

            $createdAt = $this->normalizeReasonableDateTime((string)($row['data_ora_ins'] ?? ''))
                ?? ($giornoVisita . ' 00:00:00');
            $updatedAt = $this->normalizeReasonableDateTime((string)($row['data_ora_mod'] ?? ''));
            $status = $giornoVisita >= $today ? 'ATTIVA' : 'ANNULLATA';

            $candidate = $this->buildLegacyPatientCandidateFromSourceRow($row, $targetDoctorId);
            $candidate['source_schema'] = $this->sourceDb;
            $candidate['target_id_dot'] = $targetDoctorId;
            $candidate['giorno_visita'] = $giornoVisita;
            $candidate['legacy_id_vis'] = (int)($row['id_vis'] ?? 0);
            $candidate['legacy_created_at'] = $createdAt;
            $candidate['legacy_updated_at'] = $updatedAt;
            $candidate['stato'] = $status;
            $candidate['note'] = trim((string)($row['note'] ?? ''));

            $legacyId = (int)$candidate['legacy_id_vis'];
            $signature = $this->buildDomiciliareSignature($candidate);
            if (
                ($legacyId > 0 && isset($this->targetDomiciliariByLegacyId[$legacyId]))
                || ($signature !== '' && isset($this->targetDomiciliariBySignature[$signature]))
            ) {
                $phase['skipped_existing']++;
                continue;
            }

            $patientResolution = $this->resolveTargetPatientLinkOnly($candidate);
            if ((int)($patientResolution['id_paziente'] ?? 0) > 0) {
                $phase['linked_target_patients']++;
            } else {
                $phase['missing_target_patient_link']++;
                if (count($phase['missing_patient_examples']) < 25) {
                    $phase['missing_patient_examples'][] = $this->buildMissingPatientExample('domiciliare', $candidate, $patientResolution);
                }
            }

            if ((int)($patientResolution['id_client'] ?? 0) > 0) {
                $phase['linked_target_clients']++;
            } else {
                $phase['missing_target_client_link']++;
            }

            $visitaId = 0;
            if ($this->apply) {
                $visitaId = $this->insertLegacyDomiciliare($candidate, $patientResolution);
            }

            $phase['inserted']++;
            if ($status === 'ATTIVA') {
                $phase['inserted_active']++;
            } else {
                $phase['inserted_archived']++;
            }

            if ($legacyId > 0) {
                $this->targetDomiciliariByLegacyId[$legacyId] = $visitaId;
            }
            if ($signature !== '') {
                $this->targetDomiciliariBySignature[$signature] = $visitaId;
            }

            if ($processed % 1000 === 0) {
                $this->logger->info('Progresso domiciliari legacy', [
                    'processed' => $processed,
                    'candidates' => $phase['candidates'],
                    'inserted' => $phase['inserted'],
                    'missing_target_patient_link' => $phase['missing_target_patient_link'],
                ]);
            }
        }
        $res->close();

        $phase['status'] = $this->apply ? 'apply_done' : 'dry_run_done';
        $this->report['migrations']['domiciliari'] = $phase;
        $this->logger->info('Migrazione domiciliari completata', $phase);
    }

    private function migrateDomiciliariBlockedDays(): void
    {
        $phase = [
            'candidates' => 0,
            'inserted' => 0,
            'skipped_existing' => 0,
            'skipped_missing_doctor' => 0,
            'skipped_invalid_date' => 0,
        ];

        if (!$this->tableExists($this->sourceDb, 'far31_block_dom')) {
            $this->report['migrations']['domiciliari_blocked_days'] = $phase + ['status' => 'source_table_missing'];
            $this->logger->warning('Migrazione block domiciliari saltata: tabella legacy assente');
            return;
        }

        if (!$this->tableExists($this->targetDb, 'dap31_block_dom')) {
            $this->report['migrations']['domiciliari_blocked_days'] = $phase + ['status' => 'target_table_missing'];
            $this->logger->warning('Migrazione block domiciliari saltata: tabella target assente');
            return;
        }

        $sql = "
            SELECT id_stampa, id_medico, giorno
            FROM `{$this->sourceDb}`.far31_block_dom
            WHERE STR_TO_DATE(giorno, '%d/%m/%Y') >= '{$this->db->real_escape_string($this->blockedFrom)}'
            ORDER BY STR_TO_DATE(giorno, '%d/%m/%Y') ASC, id_medico ASC, id_stampa ASC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $phase['candidates']++;

            $targetDoctorId = $this->resolveTargetDoctorId($this->sourceDb, (int)($row['id_medico'] ?? 0));
            if ($targetDoctorId === null || !$this->isDoctorAllowed($targetDoctorId)) {
                $phase['skipped_missing_doctor']++;
                continue;
            }

            $dataAgenda = $this->parseLegacyDay((string)($row['giorno'] ?? ''));
            if ($dataAgenda === null) {
                $phase['skipped_invalid_date']++;
                continue;
            }

            $dayKey = $this->buildDoctorDayKey($targetDoctorId, $dataAgenda);
            if (isset($this->targetDomiciliariBlockedDayKeys[$dayKey])) {
                $phase['skipped_existing']++;
                continue;
            }

            if ($this->apply) {
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->targetDb}`.dap31_block_dom
                    (id_dot, data_agenda, legacy_id_stampa, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                ");
                $legacyId = (int)($row['id_stampa'] ?? 0);
                $stmt->bind_param('isi', $targetDoctorId, $dataAgenda, $legacyId);
                $stmt->execute();
                $stmt->close();
            }

            $phase['inserted']++;
            $this->targetDomiciliariBlockedDayKeys[$dayKey] = true;
        }
        $res->close();

        $phase['status'] = $this->apply ? 'apply_done' : 'dry_run_done';
        $this->report['migrations']['domiciliari_blocked_days'] = $phase;
        $this->logger->info('Migrazione giorni bloccati domiciliari completata', $phase);
    }

    private function migrateAppointments(): void
    {
        $phase = [
            'candidates' => 0,
            'inserted_slot_and_appointment' => 0,
            'inserted_appointment_on_existing_slot' => 0,
            'patched_existing_appointments' => 0,
            'resolved_conflict_replaced' => 0,
            'inserted_patients' => 0,
            'resolved_client_links' => 0,
            'appointments_without_client' => 0,
            'skipped_existing' => 0,
            'skipped_conflict' => 0,
            'skipped_missing_doctor' => 0,
            'conflict_examples' => [],
        ];

        $processed = 0;

        foreach ($this->streamLegacyAppointmentCandidates() as $candidate) {
            $processed++;
            $phase['candidates']++;

            $slotKey = $this->buildSlotKey(
                (int)$candidate['target_id_dot'],
                (string)$candidate['data_ora_ini'],
                (string)$candidate['data_ora_fin']
            );

            $patientResolution = $this->resolveTargetPatientForAppointment($candidate);
            if (!empty($patientResolution['created'])) {
                $phase['inserted_patients']++;
            }
            if ((int)($patientResolution['id_client'] ?? 0) > 0) {
                $phase['resolved_client_links']++;
            } else {
                $phase['appointments_without_client']++;
            }

            $existing = $this->targetSlotsByKey[$slotKey] ?? null;
            if ($existing !== null && $this->appointmentMatchesExistingSlot($existing, $candidate, $patientResolution)) {
                if ($this->shouldPatchExistingAppointment($existing, $patientResolution)) {
                    if ($this->apply) {
                        $this->patchExistingAppointmentLink($existing, $patientResolution);
                    }
                    $phase['patched_existing_appointments']++;
                    $this->registerAppointmentCache((int)$existing['id_slot'], $candidate, $patientResolution, $existing);
                    $this->logger->debug(
                        $this->apply ? 'Aggiornato appuntamento esistente con riferimenti paziente/client' : 'DRY RUN patch appuntamento esistente',
                        $this->compactAppointmentForReport($candidate, $patientResolution)
                    );
                }

                $phase['skipped_existing']++;
                $this->logger->debug('Appuntamento legacy gia presente nel nuovo agenda', [
                    'source_schema' => $candidate['source_schema'],
                    'target_id_dot' => $candidate['target_id_dot'],
                    'data_ora_ini' => $candidate['data_ora_ini'],
                ]);
                continue;
            }

            if ($existing !== null && (int)($existing['id_appuntamento'] ?? 0) > 0) {
                if (count($phase['conflict_examples']) < 20) {
                    $phase['conflict_examples'][] = [
                        'candidate' => $this->compactAppointmentForReport($candidate, $patientResolution),
                        'target' => [
                            'id_slot' => (int)$existing['id_slot'],
                            'id_appuntamento' => (int)$existing['id_appuntamento'],
                            'appointment_patient_id' => (int)($existing['appointment_patient_id'] ?? 0),
                            'appointment_cognome' => (string)($existing['appointment_cognome'] ?? ''),
                            'appointment_nome' => (string)($existing['appointment_nome'] ?? ''),
                            'slot_stato' => (string)($existing['slot_stato'] ?? ''),
                        ],
                    ];
                }
                $this->logger->warning('Conflitto appuntamento legacy: sostituisco i dati del target con il dump legacy', [
                    'target_id_dot' => $candidate['target_id_dot'],
                    'data_ora_ini' => $candidate['data_ora_ini'],
                    'source_schema' => $candidate['source_schema'],
                ]);

                if ($this->apply) {
                    $this->replaceConflictingAppointment($existing, $candidate, $patientResolution);
                }
                $phase['resolved_conflict_replaced']++;
                $this->registerAppointmentCache((int)$existing['id_slot'], $candidate, $patientResolution, $existing);
                continue;
            }

            if ($existing !== null) {
                if ($this->apply) {
                    $this->insertAppointmentOnExistingSlot($existing, $candidate, $patientResolution);
                }

                $phase['inserted_appointment_on_existing_slot']++;
                $this->registerAppointmentCache($existing['id_slot'], $candidate, $patientResolution, $existing);
                $this->logger->debug(
                    $this->apply ? 'Inserito appuntamento su slot esistente' : 'DRY RUN appuntamento su slot esistente',
                    $this->compactAppointmentForReport($candidate, $patientResolution)
                );
                continue;
            }

            if ($this->apply) {
                $slotId = $this->insertSlotAndAppointment($candidate, $patientResolution);
            } else {
                $slotId = -1;
            }

            $phase['inserted_slot_and_appointment']++;
            $this->registerAppointmentCache($slotId, $candidate, $patientResolution, null);
            $this->logger->debug(
                $this->apply ? 'Inseriti slot e appuntamento legacy' : 'DRY RUN slot e appuntamento legacy',
                $this->compactAppointmentForReport($candidate, $patientResolution)
            );

            if ($processed % 250 === 0) {
                $this->logger->info('Progresso appuntamenti legacy', [
                    'processed' => $processed,
                    'candidates' => $phase['candidates'],
                    'inserted_slot_and_appointment' => $phase['inserted_slot_and_appointment'],
                    'inserted_on_existing_slot' => $phase['inserted_appointment_on_existing_slot'],
                ]);
            }
        }

        $phase['status'] = $this->apply ? 'apply_done' : 'dry_run_done';
        $this->report['migrations']['appointments'] = $phase;
        $this->logger->info('Migrazione appuntamenti legacy completata', $phase);
    }

    private function backfillLegacyExtraGapSlots(): void
    {
        $phase = [
            'candidate_pairs' => 0,
            'gaps_detected' => 0,
            'slots_inserted' => 0,
            'slots_skipped_existing' => 0,
            'examples' => [],
        ];

        if (!$this->tableExists($this->targetDb, 'dap11_agenda_slot')) {
            $this->report['migrations']['legacy_extra_gap_slots'] = $phase + ['status' => 'target_table_missing'];
            $this->logger->warning('Riempimento gap slot extra legacy saltato: tabella target assente');
            return;
        }

        $allowedDoctorSql = $this->buildAllowedDoctorSqlList();
        $sql = "
            SELECT
                id_slot,
                id_dot,
                id_config,
                data_slot,
                ora_inizio,
                ora_fine,
                stato,
                COALESCE(id_amb_legacy, 0) AS id_amb_legacy,
                COALESCE(ambulatorio, '') AS ambulatorio,
                COALESCE(stanza, '') AS stanza
            FROM `{$this->targetDb}`.dap11_agenda_slot
            WHERE origine_slot = 'EXTRA'
              AND titolo_libero = 'MIGRATO_LEGACY'
              AND data_slot >= '{$this->db->real_escape_string($this->appointmentsFrom)}'
              AND id_dot IN ({$allowedDoctorSql})
            ORDER BY id_dot ASC, data_slot ASC, COALESCE(id_amb_legacy, 0) ASC, ambulatorio ASC, stanza ASC, ora_inizio ASC
        ";

        $rows = [];
        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'id_slot' => (int)($row['id_slot'] ?? 0),
                'id_dot' => (int)($row['id_dot'] ?? 0),
                'id_config' => (int)($row['id_config'] ?? 0),
                'data_slot' => (string)($row['data_slot'] ?? ''),
                'ora_inizio' => (string)($row['ora_inizio'] ?? ''),
                'ora_fine' => (string)($row['ora_fine'] ?? ''),
                'stato' => (string)($row['stato'] ?? ''),
                'id_amb_legacy' => (int)($row['id_amb_legacy'] ?? 0),
                'ambulatorio' => trim((string)($row['ambulatorio'] ?? '')),
                'stanza' => trim((string)($row['stanza'] ?? '')),
            ];
        }
        $res->close();

        $previous = null;
        foreach ($rows as $current) {
            if ($previous === null) {
                $previous = $current;
                continue;
            }

            $sameTrack =
                (int)$previous['id_dot'] === (int)$current['id_dot']
                && (string)$previous['data_slot'] === (string)$current['data_slot']
                && (int)$previous['id_amb_legacy'] === (int)$current['id_amb_legacy']
                && (string)$previous['ambulatorio'] === (string)$current['ambulatorio']
                && (string)$previous['stanza'] === (string)$current['stanza'];

            if (!$sameTrack) {
                $previous = $current;
                continue;
            }

            $phase['candidate_pairs']++;

            $slotDuration = $this->minutesBetweenDateTimes((string)$previous['ora_inizio'], (string)$previous['ora_fine']);
            $gapDuration = $this->minutesBetweenDateTimes((string)$previous['ora_fine'], (string)$current['ora_inizio']);
            $currentDuration = $this->minutesBetweenDateTimes((string)$current['ora_inizio'], (string)$current['ora_fine']);

            if ($slotDuration <= 0 || $currentDuration <= 0 || $gapDuration !== $slotDuration || $currentDuration !== $slotDuration) {
                $previous = $current;
                continue;
            }

            $gapStart = (string)$previous['ora_fine'];
            $gapEnd = date('Y-m-d H:i:s', strtotime($gapStart . ' +' . $slotDuration . ' minutes'));
            $slotKey = $this->buildSlotKey((int)$current['id_dot'], $gapStart, $gapEnd);
            if (isset($this->targetSlotsByKey[$slotKey])) {
                $phase['slots_skipped_existing']++;
                $previous = $current;
                continue;
            }

            $phase['gaps_detected']++;
            if (count($phase['examples']) < 10) {
                $phase['examples'][] = [
                    'id_dot' => (int)$current['id_dot'],
                    'data_slot' => (string)$current['data_slot'],
                    'ora_inizio' => $gapStart,
                    'ora_fine' => $gapEnd,
                ];
            }

            $slotState = isset($this->targetBlockedDayKeys[$this->buildDoctorDayKey((int)$current['id_dot'], (string)$current['data_slot'])])
                ? 'CHIUSO'
                : 'LIBERO';
            $configId = (int)$current['id_config'] > 0
                ? (int)$current['id_config']
                : (int)($this->resolveConfigIdForSlot((int)$current['id_dot'], (string)$current['data_slot']) ?? 0);

            if ($this->apply) {
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->targetDb}`.dap11_agenda_slot
                    (
                        id_dot, id_config, data_slot, ora_inizio, ora_fine, tipo_slot, stato,
                        titolo_libero, id_amb_legacy, ambulatorio, stanza, origine_slot, note_interne, created_at, updated_at
                    )
                    VALUES (?, ?, ?, ?, ?, 'AMBULATORIO', ?, NULL, NULLIF(?, 0), ?, ?, 'EXTRA', ?, NOW(), NOW())
                ");
                $noteInterne = 'Slot libero inferito tra appuntamenti legacy extra contigui';
                $idAmbLegacy = (int)$current['id_amb_legacy'];
                $ambulatorio = (string)$current['ambulatorio'];
                $stanza = (string)$current['stanza'];
                $stmt->bind_param(
                    'iissssisss',
                    $current['id_dot'],
                    $configId,
                    $current['data_slot'],
                    $gapStart,
                    $gapEnd,
                    $slotState,
                    $idAmbLegacy,
                    $ambulatorio,
                    $stanza,
                    $noteInterne
                );
                $stmt->execute();
                $slotId = (int)$this->db->insert_id;
                $stmt->close();
            } else {
                $slotId = $this->dryRunSlotId--;
            }

            $this->registerGeneratedSlotCache(
                $slotId,
                (int)$current['id_dot'],
                $configId,
                (string)$current['data_slot'],
                $gapStart,
                $gapEnd,
                $slotState,
                'EXTRA'
            );
            $phase['slots_inserted']++;
            $previous = $current;
        }

        $phase['status'] = $this->apply ? 'apply_done' : 'dry_run_done';
        $this->report['migrations']['legacy_extra_gap_slots'] = $phase;
        $this->logger->info('Riempimento gap slot extra legacy completato', $phase);
    }

    private function migrateBlockedDays(): void
    {
        $phase = [
            'candidates' => 0,
            'inserted' => 0,
            'skipped_existing' => 0,
            'skipped_missing_doctor' => 0,
            'closed_slots_updated' => 0,
            'inserted_with_active_appointments' => 0,
        ];

        $candidates = $this->loadLegacyBlockedDayCandidates();
        $phase['candidates'] = count($candidates);
        $processed = 0;

        foreach ($candidates as $candidate) {
            $processed++;
            $dayKey = $this->buildDoctorDayKey((int)$candidate['target_id_dot'], (string)$candidate['data_agenda']);

            if (isset($this->targetBlockedDayKeys[$dayKey])) {
                $phase['skipped_existing']++;
                $this->logger->debug('Giorno bloccato gia presente', $candidate);
                continue;
            }

            $activeAppointments = (int)($this->activeAppointmentDayCount[$dayKey] ?? 0);

            $affectedRows = 0;
            if ($this->apply) {
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->targetDb}`.dap21_agenda_giorni_bloccati
                    (id_dot, data_agenda, motivo, created_by, created_at)
                    VALUES (?, ?, ?, NULL, NOW())
                ");
                $stmt->bind_param(
                    'iss',
                    $candidate['target_id_dot'],
                    $candidate['data_agenda'],
                    $candidate['motivo']
                );
                $stmt->execute();
                $stmt->close();

                $stmt = $this->db->prepare("
                    UPDATE `{$this->targetDb}`.dap11_agenda_slot
                    SET stato = 'CHIUSO', updated_at = NOW()
                    WHERE id_dot = ?
                      AND data_slot = ?
                      AND stato IN ('LIBERO', 'BLOCCATO')
                ");
                $stmt->bind_param('is', $candidate['target_id_dot'], $candidate['data_agenda']);
                $stmt->execute();
                $affectedRows = $stmt->affected_rows;
                $stmt->close();
            }

            $phase['inserted']++;
            $phase['closed_slots_updated'] += $affectedRows;
            if ($activeAppointments > 0) {
                $phase['inserted_with_active_appointments']++;
            }
            $this->targetBlockedDayKeys[$dayKey] = true;
            $this->logger->debug(
                $this->apply ? 'Inserito giorno bloccato legacy' : 'DRY RUN giorno bloccato legacy',
                $candidate + [
                    'closed_slots_updated' => $affectedRows,
                    'active_appointments' => $activeAppointments,
                ]
            );

            if ($processed % 500 === 0) {
                $this->logger->info('Progresso giorni bloccati', [
                    'processed' => $processed,
                    'candidates' => $phase['candidates'],
                    'inserted' => $phase['inserted'],
                ]);
            }
        }

        $phase['status'] = $this->apply ? 'apply_done' : 'dry_run_done';
        $this->report['migrations']['blocked_days'] = $phase;
        $this->logger->info('Migrazione giorni bloccati completata', $phase);
    }

    private function backfillTechnicalAppointmentClients(): void
    {
        if (!$this->targetAgendaAppointmentsHasIdClient) {
            return;
        }

        $phase = [
            'candidates' => 0,
            'patched_appointments' => 0,
            'created_clients' => 0,
            'reused_clients' => 0,
            'labels' => [],
        ];

        $sql = "
            SELECT id_appuntamento, cognome, nome
            FROM `{$this->targetDb}`.dap12_agenda_appuntamenti
            WHERE COALESCE(id_client, 0) <= 0
            ORDER BY id_appuntamento ASC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $technicalKey = $this->resolveTechnicalAppointmentKey(
                (string)($row['cognome'] ?? ''),
                (string)($row['nome'] ?? '')
            );
            if ($technicalKey === '') {
                continue;
            }

            $phase['candidates']++;
            $hadClient = isset($this->targetTechnicalClientsByKey[$technicalKey]);
            [$clientId] = $this->resolveTechnicalClientForAppointment([
                'paz_cognome' => (string)($row['cognome'] ?? ''),
                'paz_nome' => (string)($row['nome'] ?? ''),
            ]);
            if ($clientId <= 0) {
                continue;
            }

            $phase['labels'][$technicalKey] = (int)($phase['labels'][$technicalKey] ?? 0) + 1;
            if ($hadClient) {
                $phase['reused_clients']++;
            } else {
                $phase['created_clients']++;
            }

            if ($this->apply) {
                $stmt = $this->db->prepare("
                    UPDATE `{$this->targetDb}`.dap12_agenda_appuntamenti
                    SET id_client = ?
                    WHERE id_appuntamento = ?
                      AND COALESCE(id_client, 0) <= 0
                ");
                $appointmentId = (int)$row['id_appuntamento'];
                $stmt->bind_param('ii', $clientId, $appointmentId);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $phase['patched_appointments']++;
                }
                $stmt->close();
            } else {
                $phase['patched_appointments']++;
            }
        }
        $res->close();

        ksort($phase['labels']);
        $phase['status'] = $this->apply ? 'apply_done' : 'dry_run_done';
        $this->report['migrations']['technical_appointment_clients'] = $phase;
        $this->logger->info('Backfill client tecnici agenda completato', $phase);
    }

    private function auditUnresolvedLegacyAreas(): void
    {
        $this->report['audit']['unresolved_legacy_areas'] = [
            'memo_blocked_day_markers' => [
                'legacy_table' => $this->tableExists($this->sourceDb, 'far37_block_memo') ? 'far37_block_memo' : null,
                'legacy_rows' => $this->tableExists($this->sourceDb, 'far37_block_memo')
                    ? $this->scalar("SELECT COUNT(*) AS c FROM `{$this->sourceDb}`.far37_block_memo")
                    : 0,
                'target_table' => $this->tableExists($this->targetDb, 'dap37_block_memo') ? 'dap37_block_memo' : null,
                'target_rows' => $this->tableExists($this->targetDb, 'dap37_block_memo')
                    ? $this->scalar("SELECT COUNT(*) AS c FROM `{$this->targetDb}`.dap37_block_memo")
                    : 0,
                'status' => $this->tableExists($this->targetDb, 'dap37_block_memo')
                    ? 'managed_with_target_table'
                    : 'legacy_marker_without_target_table',
                'reason' => $this->tableExists($this->targetDb, 'dap37_block_memo')
                    ? 'I giorni bloccati memo sono migrati nella tabella dedicata dap37_block_memo.'
                    : 'Nel nuovo schema non esiste una tabella dedicata ai soli giorni bloccati del modulo memo. Il contenuto memo viene importato da far29_not_dot.',
            ],
            'domiciliari_blocked_day_markers' => [
                'legacy_table' => $this->tableExists($this->sourceDb, 'far31_block_dom') ? 'far31_block_dom' : null,
                'legacy_rows' => $this->tableExists($this->sourceDb, 'far31_block_dom')
                    ? $this->scalar("SELECT COUNT(*) AS c FROM `{$this->sourceDb}`.far31_block_dom")
                    : 0,
                'target_table' => $this->tableExists($this->targetDb, 'dap31_block_dom') ? 'dap31_block_dom' : null,
                'target_rows' => $this->tableExists($this->targetDb, 'dap31_block_dom')
                    ? $this->scalar("SELECT COUNT(*) AS c FROM `{$this->targetDb}`.dap31_block_dom")
                    : 0,
                'status' => $this->tableExists($this->targetDb, 'dap31_block_dom')
                    ? 'managed_with_target_table'
                    : 'legacy_marker_without_target_table',
                'reason' => $this->tableExists($this->targetDb, 'dap31_block_dom')
                    ? 'I giorni bloccati domiciliari sono migrati nella tabella dedicata dap31_block_dom.'
                    : 'Nel nuovo schema non esiste una tabella separata per i giorni bloccati dei domiciliari. I record domiciliari vengono importati da far11_vis_dom.',
            ],
        ];

        $allManaged = true;
        foreach ($this->report['audit']['unresolved_legacy_areas'] as $area) {
            if (($area['status'] ?? '') !== 'managed_with_target_table') {
                $allManaged = false;
                break;
            }
        }

        if ($allManaged) {
            $this->logger->info('Aree legacy block memo/dom ora gestite', $this->report['audit']['unresolved_legacy_areas']);
            return;
        }

        $this->logger->warning('Aree legacy non migrate in questa prima versione', $this->report['audit']['unresolved_legacy_areas']);
    }

    private function loadLegacyDailyNotes(): array
    {
        $sql = "
            SELECT
                id_medico,
                giorno,
                COALESCE(testo, '') AS testo
            FROM `{$this->sourceDb}`.far21_note
            WHERE STR_TO_DATE(giorno, '%d/%m/%Y') >= '{$this->db->real_escape_string($this->notesFrom)}'
            ORDER BY id_medico ASC, STR_TO_DATE(giorno, '%d/%m/%Y') ASC, id_nota ASC
        ";

        $res = $this->db->query($sql);
        $grouped = [];
        while ($row = $res->fetch_assoc()) {
            $targetDoctorId = $this->resolveTargetDoctorId($this->sourceDb, (int)$row['id_medico']);
            if ($targetDoctorId === null) {
                continue;
            }
            if (!$this->isDoctorAllowed($targetDoctorId)) {
                continue;
            }

            $date = $this->parseLegacyDay((string)$row['giorno']);
            if ($date === null) {
                continue;
            }

            $text = trim((string)$row['testo']);
            if ($text === '') {
                continue;
            }

            $key = $this->buildDoctorDayKey($targetDoctorId, $date);
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'target_id_dot' => $targetDoctorId,
                    'data_agenda' => $date,
                    'note_parts' => [],
                ];
            }

            if (!in_array($text, $grouped[$key]['note_parts'], true)) {
                $grouped[$key]['note_parts'][] = $text;
            }
        }

        $out = [];
        foreach ($grouped as $row) {
            $out[] = [
                'target_id_dot' => (int)$row['target_id_dot'],
                'data_agenda' => (string)$row['data_agenda'],
                'nota' => implode("\n\n", $row['note_parts']),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            $left = $a['data_agenda'] . '|' . str_pad((string)$a['target_id_dot'], 6, '0', STR_PAD_LEFT);
            $right = $b['data_agenda'] . '|' . str_pad((string)$b['target_id_dot'], 6, '0', STR_PAD_LEFT);
            return strcmp($left, $right);
        });

        return $out;
    }

    private function streamLegacyAppointmentCandidates(): \Generator
    {
        $seen = [];
        foreach ([$this->sourceDb] as $schema) {
            if (!$this->tableExists($schema, 'far06_appuntamenti') || !$this->tableExists($schema, 'far08_prenotazioni')) {
                continue;
            }

            $sql = "
                SELECT
                    '{$schema}' AS source_schema,
                    a.id_appuntamento,
                    p.id_prenotazione,
                    a.id_medico,
                    a.data_ora_ini,
                    a.data_ora_fin,
                    a.f_lck,
                    a.id_fas,
                    a.esitato,
                    p.id_paziente,
                    COALESCE(p.note, '') AS legacy_note,
                    COALESCE(pt.cognome, '') AS paz_cognome,
                    COALESCE(pt.nome, '') AS paz_nome,
                    COALESCE(pt.data_nascita, '') AS paz_data_nascita,
                    COALESCE(pt.cod_fis, '') AS paz_cod_fis,
                    COALESCE(pt.comune_nascita, '') AS paz_comune_nascita,
                    COALESCE(pt.provincia_nascita, '') AS paz_provincia_nascita,
                    COALESCE(pt.indirizzo, '') AS paz_indirizzo,
                    COALESCE(pt.citta, '') AS paz_citta,
                    COALESCE(pt.cap, '') AS paz_cap,
                    COALESCE(pt.provincia, '') AS paz_provincia,
                    COALESCE(pt.residenza_indirizzo, '') AS paz_residenza_indirizzo,
                    COALESCE(pt.residenza_comune, '') AS paz_residenza_comune,
                    COALESCE(pt.residenza_cap, '') AS paz_residenza_cap,
                    COALESCE(pt.residenza_provincia, '') AS paz_residenza_provincia,
                    COALESCE(pt.telefono, '') AS paz_telefono,
                    COALESCE(pt.cellulare, '') AS paz_cellulare,
                    COALESCE(pt.email, '') AS paz_email,
                    COALESCE(pt.paz_spec, '') AS paz_spec,
                    COALESCE(pt.bloccato, 0) AS paz_bloccato,
                    COALESCE(pt.id_dot, 0) AS paz_id_dot
                FROM `{$schema}`.far08_prenotazioni p
                INNER JOIN `{$schema}`.far06_appuntamenti a
                    ON a.id_appuntamento = p.id_appuntamento
                LEFT JOIN `{$schema}`.far05_pazienti pt
                    ON pt.id_paziente = p.id_paziente
                WHERE a.data_ora_ini >= '{$this->db->real_escape_string($this->appointmentsFrom)}'
                ORDER BY a.data_ora_ini ASC, a.id_medico ASC, a.id_appuntamento ASC
            ";

            $res = $this->db->query($sql);
            while ($row = $res->fetch_assoc()) {
                $targetDoctorId = $this->resolveTargetDoctorId($schema, (int)$row['id_medico']);
                if ($targetDoctorId === null) {
                    continue;
                }
                if (!$this->isDoctorAllowed($targetDoctorId)) {
                    continue;
                }

                $key = $this->buildSlotKey(
                    $targetDoctorId,
                    (string)$row['data_ora_ini'],
                    (string)$row['data_ora_fin']
                );

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $row['target_id_dot'] = $targetDoctorId;
                $row['data_slot'] = substr((string)$row['data_ora_ini'], 0, 10);
                $row['paz_bloccato'] = (int)($row['paz_bloccato'] ?? 0);
                $row['id_paziente'] = (int)($row['id_paziente'] ?? 0);
                yield $row;
            }
            $res->close();
        }
    }

    private function loadLegacyBlockedDayCandidates(): array
    {
        $candidates = [];
        foreach ([$this->sourceDb] as $schema) {
            if (!$this->tableExists($schema, 'far20_stampa')) {
                continue;
            }

            $sql = "
                SELECT
                    '{$schema}' AS source_schema,
                    id_medico,
                    giorno,
                    COALESCE(ferie, 0) AS ferie
                FROM `{$schema}`.far20_stampa
                WHERE STR_TO_DATE(giorno, '%d/%m/%Y') >= '{$this->db->real_escape_string($this->blockedFrom)}'
                ORDER BY STR_TO_DATE(giorno, '%d/%m/%Y') ASC, id_medico ASC
            ";

            $res = $this->db->query($sql);
            while ($row = $res->fetch_assoc()) {
                $targetDoctorId = $this->resolveTargetDoctorId($schema, (int)$row['id_medico']);
                if ($targetDoctorId === null) {
                    continue;
                }
                if (!$this->isDoctorAllowed($targetDoctorId)) {
                    continue;
                }

                $date = $this->parseLegacyDay((string)$row['giorno']);
                if ($date === null) {
                    continue;
                }

                $key = $this->buildDoctorDayKey($targetDoctorId, $date);
                if (isset($candidates[$key])) {
                    continue;
                }

                $candidates[$key] = [
                    'source_schema' => $schema,
                    'target_id_dot' => $targetDoctorId,
                    'data_agenda' => $date,
                    'legacy_giorno' => (string)$row['giorno'],
                    'ferie' => (int)($row['ferie'] ?? 0),
                    'motivo' => (int)($row['ferie'] ?? 0) === 1
                        ? 'Legacy far20_stampa - ferie'
                        : 'Legacy far20_stampa - giorno bloccato',
                ];
            }
        }

        return array_values($candidates);
    }

    private function loadLegacyAgendaVisibilityCandidates(): array
    {
        $candidates = [];
        if (!$this->tableExists($this->sourceDb, 'far10_vis_dot')) {
            return $candidates;
        }

        $sql = "
            SELECT id_dot, id_dot_vis
            FROM `{$this->sourceDb}`.far10_vis_dot
            ORDER BY id_dot ASC, id_dot_vis ASC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $sourceDoctorId = (int)$row['id_dot'];
            $sourceVisibleDoctorId = (int)$row['id_dot_vis'];
            $masterDoctor = $this->sourceDoctorsBySchema[$this->sourceDb][$sourceDoctorId] ?? null;
            if ($masterDoctor === null) {
                continue;
            }

            $targetMasterDoctorId = $this->resolveTargetDoctorId($this->sourceDb, $sourceDoctorId);
            $targetVisibleDoctorId = $this->resolveTargetDoctorId($this->sourceDb, $sourceVisibleDoctorId);
            if ($targetVisibleDoctorId === null) {
                continue;
            }

            if (
                $this->doctorFilter !== []
                && !in_array((int)$targetVisibleDoctorId, $this->doctorFilter, true)
                && !in_array((int)($targetMasterDoctorId ?? 0), $this->doctorFilter, true)
            ) {
                continue;
            }

            $targetOperatorId = $this->resolveTargetOperatorId($this->sourceDb, (int)($masterDoctor['id_ope'] ?? 0));
            $candidateKey = ($targetOperatorId ?? 0) > 0
                ? $this->buildOperatorDoctorKey((int)$targetOperatorId, (int)$targetVisibleDoctorId)
                : 'source|' . $sourceDoctorId . '|' . $sourceVisibleDoctorId;
            if (isset($candidates[$candidateKey])) {
                continue;
            }

            $candidates[$candidateKey] = [
                'source_id_dot' => $sourceDoctorId,
                'source_id_dot_vis' => $sourceVisibleDoctorId,
                'target_master_id_dot' => (int)($targetMasterDoctorId ?? 0),
                'target_id_dot' => (int)$targetVisibleDoctorId,
                'source_id_ope' => (int)($masterDoctor['id_ope'] ?? 0),
                'target_id_ope' => (int)($targetOperatorId ?? 0),
                'master_username' => (string)($masterDoctor['username'] ?? ''),
            ];
        }

        return array_values($candidates);
    }

    private function resolveTargetOperatorId(string $schema, int $sourceOperatorId): ?int
    {
        if ($sourceOperatorId <= 0) {
            return null;
        }

        if ($schema === $this->targetDb) {
            return isset($this->targetOperatorsById[$sourceOperatorId]) ? $sourceOperatorId : null;
        }

        $row = $this->sourceOperatorsBySchema[$schema][$sourceOperatorId] ?? null;
        if ($row === null) {
            return null;
        }

        $usernameNorm = $this->normalizeUsername((string)$row['user']);
        if ($usernameNorm !== '' && isset($this->targetOperatorsByUsername[$usernameNorm])) {
            return (int)$this->targetOperatorsByUsername[$usernameNorm]['id_ope'];
        }

        $targetRow = $this->targetOperatorsById[$sourceOperatorId] ?? null;
        if ($targetRow !== null) {
            $targetUsernameNorm = $this->normalizeUsername((string)($targetRow['user'] ?? ''));
            if ($usernameNorm === '' || $targetUsernameNorm === '' || $targetUsernameNorm === $usernameNorm) {
                return (int)$targetRow['id_ope'];
            }
        }

        return null;
    }

    private function insertTargetOperatorFromSource(array $sourceRow): int
    {
        $preferredId = (int)$sourceRow['id_ope'];
        $targetId = $this->allocateTargetOperatorId($preferredId);
        $row = $sourceRow;
        $row['id_ope'] = $targetId;

        if ($this->apply) {
            $stmt = $this->db->prepare("
                INSERT INTO `{$this->targetDb}`.far01_ope
                (
                    id_ope, nome, cognome, user, password, id_ruo, data_ora_mod,
                    email, data_scad_ute, data_scad_pass, vis_dot
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $visDot = $sourceRow['vis_dot'];
            $stmt->bind_param(
                'issssissssi',
                $targetId,
                $sourceRow['nome'],
                $sourceRow['cognome'],
                $sourceRow['user'],
                $sourceRow['password'],
                $sourceRow['id_ruo'],
                $sourceRow['data_ora_mod'],
                $sourceRow['email'],
                $sourceRow['data_scad_ute'],
                $sourceRow['data_scad_pass'],
                $visDot
            );
            $stmt->execute();
            $stmt->close();
        }

        $this->registerTargetOperatorRow($row);
        return $targetId;
    }

    private function allocateTargetOperatorId(int $preferredId): int
    {
        if ($preferredId > 0 && !isset($this->targetOperatorsById[$preferredId])) {
            return $preferredId;
        }

        if (!$this->apply) {
            return $this->dryRunOperatorId--;
        }

        $next = 1;
        if ($this->targetOperatorsById !== []) {
            $next = max(array_keys($this->targetOperatorsById)) + 1;
        }

        while (isset($this->targetOperatorsById[$next])) {
            $next++;
        }

        return $next;
    }

    private function registerTargetOperatorRow(array $row): void
    {
        $row['id_ope'] = (int)$row['id_ope'];
        $row['id_ruo'] = (int)($row['id_ruo'] ?? 0);
        $row['username_norm'] = $this->normalizeUsername((string)($row['user'] ?? ''));

        $this->targetOperatorsById[(int)$row['id_ope']] = $row;
        if ($row['username_norm'] !== '') {
            $this->targetOperatorsByUsername[$row['username_norm']] = $row;
        }
        $this->sourceOperatorsBySchema[$this->targetDb][(int)$row['id_ope']] = $row;
    }

    private function resolveTargetPatientForAppointment(array $candidate): array
    {
        return $this->resolveTargetPatientForCandidate($candidate, true, 'appointment');
    }

    private function resolveTargetPatientLinkOnly(array $candidate): array
    {
        return $this->resolveTargetPatientForCandidate($candidate, false, 'link_only');
    }

    private function resolveTargetPatientForCandidate(array $candidate, bool $allowInsert, string $mode): array
    {
        $cacheKey = implode('|', [
            $mode,
            $candidate['source_schema'],
            $candidate['id_paziente'],
            $candidate['target_id_dot'],
            $candidate['data_ora_ini'] ?? ($candidate['data_slot'] ?? ($candidate['data_inizio_validita'] ?? '')),
        ]);

        if (isset($this->patientResolutionCache[$cacheKey])) {
            return $this->patientResolutionCache[$cacheKey];
        }

        $resolved = [
            'id_paziente' => 0,
            'created' => false,
            'strategy' => 'unresolved',
            'id_client' => null,
            'id_user' => null,
            'client_strategy' => 'unresolved',
        ];

        $technicalKey = $this->resolveTechnicalAppointmentKey(
            (string)($candidate['paz_cognome'] ?? ''),
            (string)($candidate['paz_nome'] ?? '')
        );
        if ($technicalKey !== '') {
            [$clientId, $clientStrategy] = $this->resolveTechnicalClientForAppointment($candidate);
            if ($clientId > 0) {
                $resolved['id_client'] = $clientId;
                $resolved['client_strategy'] = $clientStrategy;
                $resolved['id_user'] = (int)($this->targetClientsById[$clientId]['id_user'] ?? 0);
            }
            $resolved['strategy'] = 'technical_placeholder';
            $this->patientResolutionCache[$cacheKey] = $resolved;
            return $resolved;
        }

        if (
            (string)$candidate['source_schema'] === $this->targetDb
            && (int)$candidate['id_paziente'] > 0
            && isset($this->targetPatientIds[(int)$candidate['id_paziente']])
            && (int)($this->targetPatientDoctorById[(int)$candidate['id_paziente']] ?? 0) === (int)$candidate['target_id_dot']
        ) {
            $resolved['id_paziente'] = (int)$candidate['id_paziente'];
            $resolved['strategy'] = 'same_mail_id';
        }

        $cf = $this->normalizeUsableFiscalCode((string)$candidate['paz_cod_fis']);
        $doctorCfKey = $this->buildDoctorPatientCfKey((int)$candidate['target_id_dot'], (string)$candidate['paz_cod_fis']);
        if ($resolved['id_paziente'] > 0) {
            // niente: abbiamo gia risolto con la sorgente mail stessa
        } elseif ($doctorCfKey !== '' && isset($this->targetPatientsByDoctorCf[$doctorCfKey])) {
            $resolved['id_paziente'] = (int)$this->targetPatientsByDoctorCf[$doctorCfKey];
            $resolved['strategy'] = 'match_doctor_cod_fis';
        } else {
            $doctorTriadKey = $this->buildDoctorPatientTriadKey(
                (int)$candidate['target_id_dot'],
                (string)$candidate['paz_cognome'],
                (string)$candidate['paz_nome'],
                (string)$candidate['paz_cellulare']
            );
            $doctorPhoneKey = $this->buildDoctorPatientPhoneKey(
                (int)$candidate['target_id_dot'],
                (string)$candidate['paz_cognome'],
                (string)$candidate['paz_nome'],
                (string)$candidate['paz_telefono']
            );

            if ($doctorTriadKey !== '' && isset($this->targetPatientsByDoctorTriad[$doctorTriadKey])) {
                $resolved['id_paziente'] = (int)$this->targetPatientsByDoctorTriad[$doctorTriadKey];
                $resolved['strategy'] = 'match_doctor_triad';
            } elseif ($doctorPhoneKey !== '' && isset($this->targetPatientsByDoctorPhone[$doctorPhoneKey])) {
                $resolved['id_paziente'] = (int)$this->targetPatientsByDoctorPhone[$doctorPhoneKey];
                $resolved['strategy'] = 'match_doctor_phone';
            } elseif ($allowInsert) {
                if ($this->apply) {
                    $resolved['id_paziente'] = $this->insertTargetPatientFromLegacy($candidate);
                }
                $resolved['created'] = true;
                $resolved['strategy'] = $this->apply ? 'insert_dap02_client' : 'insert_dap02_client_preview';
            } else {
                $resolved['strategy'] = 'missing_target_patient';
            }
        }

        [$clientId, $clientStrategy] = $this->resolveTargetClientForAppointment($candidate, $cf);
        if ($clientId > 0) {
            $resolved['id_client'] = $clientId;
            $resolved['client_strategy'] = $clientStrategy;
            $resolved['id_user'] = (int)($this->targetClientsById[$clientId]['id_user'] ?? 0);
            if ((int)$resolved['id_paziente'] <= 0) {
                $resolved['id_paziente'] = $clientId;
                if (($resolved['strategy'] ?? 'unresolved') === 'unresolved') {
                    $resolved['strategy'] = 'mirror_client_id';
                }
            }
        }

        $this->patientResolutionCache[$cacheKey] = $resolved;
        return $resolved;
    }

    private function resolveTargetClientForAppointment(array $candidate, string $usableCf): array
    {
        [$technicalClientId, $technicalStrategy] = $this->resolveTechnicalClientForAppointment($candidate);
        if ($technicalClientId > 0) {
            return [$technicalClientId, $technicalStrategy];
        }

        $targetDoctorId = (int)$candidate['target_id_dot'];
        $legacyPatientId = (int)($candidate['id_paziente'] ?? 0);

        if ($legacyPatientId > 0 && isset($this->targetClientsByLegacyPatientId[$legacyPatientId])) {
            $clientId = (int)$this->targetClientsByLegacyPatientId[$legacyPatientId];
            if ($clientId > 0 && $this->isClientCompatibleWithDoctor($clientId, $targetDoctorId)) {
                return [$clientId, 'legacy_patient_id'];
            }
        }

        if ($usableCf !== '' && isset($this->targetRegisteredClientsByUsername[$usableCf])) {
            $registered = $this->targetRegisteredClientsByUsername[$usableCf];
            $clientId = (int)($registered['id_client'] ?? 0);
            if ($clientId > 0 && $this->isClientCompatibleWithDoctor($clientId, $targetDoctorId)) {
                return [$clientId, 'registered_user_cf'];
            }
        }

        $doctorCfKey = $this->buildDoctorPatientCfKey($targetDoctorId, (string)$candidate['paz_cod_fis']);
        if ($doctorCfKey !== '' && isset($this->targetClientsByDoctorCf[$doctorCfKey])) {
            return [(int)$this->targetClientsByDoctorCf[$doctorCfKey], 'doctor_client_cf'];
        }

        $doctorTriadKey = $this->buildDoctorPatientTriadKey(
            $targetDoctorId,
            (string)$candidate['paz_cognome'],
            (string)$candidate['paz_nome'],
            (string)$candidate['paz_cellulare']
        );
        if ($doctorTriadKey !== '' && isset($this->targetClientsByDoctorTriad[$doctorTriadKey])) {
            return [(int)$this->targetClientsByDoctorTriad[$doctorTriadKey], 'doctor_client_triad'];
        }

        $doctorEmailKey = $this->buildDoctorPatientEmailKey(
            $targetDoctorId,
            (string)$candidate['paz_cognome'],
            (string)$candidate['paz_nome'],
            (string)$candidate['paz_email']
        );
        if ($doctorEmailKey !== '' && isset($this->targetClientsByDoctorEmail[$doctorEmailKey])) {
            return [(int)$this->targetClientsByDoctorEmail[$doctorEmailKey], 'doctor_client_email'];
        }

        if ($usableCf !== '' && isset($this->targetClientsByCf[$usableCf])) {
            $clientId = (int)$this->targetClientsByCf[$usableCf];
            if ($clientId > 0 && $this->isClientCompatibleWithDoctor($clientId, $targetDoctorId)) {
                return [$clientId, 'client_cf'];
            }
        }

        return [0, 'unresolved'];
    }

    private function resolveTechnicalClientForAppointment(array $candidate): array
    {
        $technicalKey = $this->resolveTechnicalAppointmentKey(
            (string)($candidate['paz_cognome'] ?? ''),
            (string)($candidate['paz_nome'] ?? '')
        );
        if ($technicalKey === '') {
            return [0, 'not_technical'];
        }

        if (isset($this->targetTechnicalClientsByKey[$technicalKey])) {
            return [(int)$this->targetTechnicalClientsByKey[$technicalKey], 'technical_placeholder_reused'];
        }

        $label = self::TECHNICAL_APPOINTMENT_LABELS[$technicalKey] ?? null;
        if ($label === null) {
            return [0, 'technical_placeholder_unknown'];
        }

        $clientId = $this->apply
            ? $this->insertTechnicalClient((string)$label['cognome'], (string)$label['nome'])
            : $this->dryRunClientId--;

        $row = [
            'id_client' => $clientId,
            'id_user' => 0,
            'id_personale' => 0,
            'legacy_id_paziente' => 0,
            'nome' => (string)$label['nome'],
            'cognome' => (string)$label['cognome'],
            'cellulare' => '',
            'email' => '',
            'codice_fiscale' => '',
        ];
        $this->registerTargetClientRow($row);

        return [$clientId, $this->apply ? 'technical_placeholder_created' : 'technical_placeholder_preview'];
    }

    private function insertTechnicalClient(string $cognome, string $nome): int
    {
        $writeDb = $this->writeDb ?? $this->db;
        $this->initializeEncryptionSessionOn($writeDb);

        $sql = "
            INSERT INTO `{$this->targetDb}`.dap02_clients
            (
                id_user, nome, cognome, cellulare, email, indirizzo, citta, provincia,
                codice_fiscale, id_personale, legacy_id_paziente, avviso_mail, vector_id
            )
            VALUES
            (
                NULL,
                " . $this->encryptSqlOn($writeDb, $nome) . ",
                " . $this->encryptSqlOn($writeDb, $cognome) . ",
                " . $this->encryptSqlOn($writeDb, '') . ",
                " . $this->encryptSqlOn($writeDb, '') . ",
                " . $this->encryptSqlOn($writeDb, '') . ",
                " . $this->encryptSqlOn($writeDb, '') . ",
                " . $this->encryptSqlOn($writeDb, '') . ",
                " . $this->encryptSqlOn($writeDb, '') . ",
                NULL,
                NULL,
                0,
                @init_vector
            )
        ";

        $writeDb->query($sql);
        return (int)$writeDb->insert_id;
    }

    private function insertTargetPatientFromLegacy(array $candidate): int
    {
        $writeDb = $this->writeDb ?? $this->db;
        $this->initializeEncryptionSessionOn($writeDb);

        $agendaDoctorId = (int)$candidate['target_id_dot'];
        $personaleId = (int)($this->personaleIdByAgendaDoctorId[$agendaDoctorId] ?? 0);
        $primaryPersonaleId = $this->isPrimaryFamilyAgendaDoctor($agendaDoctorId) ? $personaleId : 0;
        $legacyPatientId = (int)($candidate['id_paziente'] ?? 0);
        $bloccato = (int)($candidate['paz_bloccato'] ?? 0);

        $sql = "
            INSERT INTO `{$this->targetDb}`.dap02_clients
            (
                id_user, nome, cognome, cellulare, email, indirizzo, citta, provincia,
                codice_fiscale, id_personale, legacy_id_paziente, avviso_mail, vector_id,
                telefono, data_nascita, comune_nascita, provincia_nascita, cap,
                residenza_indirizzo, residenza_comune, residenza_cap, residenza_provincia,
                paz_spec, bloccato
            )
            VALUES
            (
                NULL,
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_nome']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_cognome']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_cellulare']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_email']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_indirizzo']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_citta']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_provincia']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_cod_fis']) . ",
                " . ($primaryPersonaleId > 0 ? (string)$primaryPersonaleId : 'NULL') . ",
                " . ($legacyPatientId > 0 ? (string)$legacyPatientId : 'NULL') . ",
                0,
                @init_vector,
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_telefono']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_data_nascita']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_comune_nascita']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_provincia_nascita']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_cap']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_residenza_indirizzo']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_residenza_comune']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_residenza_cap']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_residenza_provincia']) . ",
                " . $this->encryptSqlOn($writeDb, (string)$candidate['paz_spec']) . ",
                {$bloccato}
            )
        ";

        $writeDb->query($sql);
        $clientId = (int)$writeDb->insert_id;

        if ($personaleId > 0) {
            $this->ensureTargetClientDoctorLink($clientId, $agendaDoctorId);
        }

        $row = [
            'id_client' => $clientId,
            'id_user' => 0,
            'id_personale' => $primaryPersonaleId,
            'legacy_id_paziente' => $legacyPatientId,
            'nome' => (string)$candidate['paz_nome'],
            'cognome' => (string)$candidate['paz_cognome'],
            'cellulare' => (string)$candidate['paz_cellulare'],
            'telefono' => (string)$candidate['paz_telefono'],
            'email' => (string)$candidate['paz_email'],
            'codice_fiscale' => (string)$candidate['paz_cod_fis'],
        ];
        $this->registerTargetClientRow($row);
        $this->registerTargetPatientRow([
            'id_paziente' => $clientId,
            'id_dot' => $agendaDoctorId,
            'cognome' => (string)$candidate['paz_cognome'],
            'nome' => (string)$candidate['paz_nome'],
            'cod_fis' => (string)$candidate['paz_cod_fis'],
            'cellulare' => (string)$candidate['paz_cellulare'],
            'telefono' => (string)$candidate['paz_telefono'],
        ]);

        return $clientId;
    }

    private function ensureTargetClientDoctorLink(int $clientId, int $agendaDoctorId): void
    {
        if ($clientId <= 0 || $agendaDoctorId <= 0) {
            return;
        }

        $personaleId = (int)($this->personaleIdByAgendaDoctorId[$agendaDoctorId] ?? 0);
        if ($personaleId <= 0 || !empty($this->targetClientDoctorIdsById[$clientId][$personaleId])) {
            return;
        }

        if ($this->apply) {
            $stmt = $this->db->prepare("
                INSERT INTO `{$this->targetDb}`.dap09_client_doctor
                (id_client, id_dot)
                VALUES (?, ?)
            ");
            $stmt->bind_param('ii', $clientId, $personaleId);
            $stmt->execute();
            $stmt->close();
        }

        $this->targetClientDoctorIdsById[$clientId][$personaleId] = true;
        if (!isset($this->targetClientDoctorById[$clientId])) {
            $this->targetClientDoctorById[$clientId] = $personaleId;
        }
    }

    private function appointmentMatchesExistingSlot(array $existing, array $candidate, array $patientResolution): bool
    {
        if ((int)($existing['id_appuntamento'] ?? 0) <= 0) {
            return false;
        }

        $targetPatientId = (int)($patientResolution['id_paziente'] ?? 0);
        $existingPatientId = (int)($existing['appointment_patient_id'] ?? 0);

        if ($targetPatientId > 0 && $existingPatientId > 0 && $targetPatientId === $existingPatientId) {
            return true;
        }

        $leftName = $this->normalizeNamePair((string)$candidate['paz_cognome'], (string)$candidate['paz_nome']);
        $rightName = $this->normalizeNamePair((string)($existing['appointment_cognome'] ?? ''), (string)($existing['appointment_nome'] ?? ''));

        return $leftName !== '' && $leftName === $rightName;
    }

    private function shouldPatchExistingAppointment(array $existing, array $patientResolution): bool
    {
        $resolvedPatientId = (int)($patientResolution['id_paziente'] ?? 0);
        $resolvedClientId = (int)($patientResolution['id_client'] ?? 0);
        $existingPatientId = (int)($existing['appointment_patient_id'] ?? 0);
        $existingClientId = (int)($existing['appointment_client_id'] ?? 0);

        if ($resolvedPatientId > 0 && $existingPatientId <= 0) {
            return true;
        }

        if ($this->targetAgendaAppointmentsHasIdClient && $resolvedClientId > 0 && $existingClientId <= 0) {
            return true;
        }

        return false;
    }

    private function patchExistingAppointmentLink(array $existing, array $patientResolution): void
    {
        $set = [];
        $types = '';
        $params = [];

        $resolvedPatientId = (int)($patientResolution['id_paziente'] ?? 0);
        if ($resolvedPatientId > 0 && (int)($existing['appointment_patient_id'] ?? 0) <= 0) {
            $set[] = 'id_paziente = ?';
            $types .= 'i';
            $params[] = $resolvedPatientId;
        }

        $resolvedClientId = (int)($patientResolution['id_client'] ?? 0);
        if (
            $this->targetAgendaAppointmentsHasIdClient
            && $resolvedClientId > 0
            && (int)($existing['appointment_client_id'] ?? 0) <= 0
        ) {
            $set[] = 'id_client = ?';
            $types .= 'i';
            $params[] = $resolvedClientId;
        }

        if ($set === []) {
            return;
        }

        $sql = "
            UPDATE `{$this->targetDb}`.dap12_agenda_appuntamenti
            SET " . implode(', ', $set) . "
            WHERE id_appuntamento = ?
            LIMIT 1
        ";

        $types .= 'i';
        $params[] = (int)$existing['id_appuntamento'];

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }

    private function replaceConflictingAppointment(array $existing, array $candidate, array $patientResolution): void
    {
        $set = [
            'id_dot = ?',
            'id_paziente = ?',
            'cognome = ?',
            'nome = ?',
            'telefono = ?',
            'cellulare = ?',
            'email = ?',
            'note = ?',
            'stato = ?',
        ];
        $types = 'iisssssss';
        $params = [
            (int)$candidate['target_id_dot'],
            (int)($patientResolution['id_paziente'] ?? 0),
            (string)$candidate['paz_cognome'],
            (string)$candidate['paz_nome'],
            (string)$candidate['paz_telefono'],
            (string)$candidate['paz_cellulare'],
            (string)$candidate['paz_email'],
            (string)$candidate['legacy_note'],
            $this->mapLegacyAppointmentState($candidate),
        ];

        if ($this->targetAgendaAppointmentsHasIdClient) {
            $set[] = 'id_client = ?';
            $types .= 'i';
            $params[] = (int)($patientResolution['id_client'] ?? 0);
        }

        $sql = "
            UPDATE `{$this->targetDb}`.dap12_agenda_appuntamenti
            SET " . implode(', ', $set) . "
            WHERE id_appuntamento = ?
            LIMIT 1
        ";

        $types .= 'i';
        $params[] = (int)$existing['id_appuntamento'];

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        $slotStmt = $this->db->prepare("
            UPDATE `{$this->targetDb}`.dap11_agenda_slot
            SET stato = 'PRENOTATO', updated_at = NOW()
            WHERE id_slot = ?
            LIMIT 1
        ");
        $slotStmt->bind_param('i', $existing['id_slot']);
        $slotStmt->execute();
        $slotStmt->close();
    }

    private function mapLegacyAppointmentState(array $candidate): string
    {
        return (int)($candidate['esitato'] ?? 0) === 1 ? 'ESEGUITO' : 'CONFERMATO';
    }

    private function insertAppointmentOnExistingSlot(array $existing, array $candidate, array $patientResolution): void
    {
        $this->db->begin_transaction();
        try {
            $idPaziente = (int)($patientResolution['id_paziente'] ?? 0);
            $idClient = (int)($patientResolution['id_client'] ?? 0);
            $state = $this->mapLegacyAppointmentState($candidate);

            if ($this->targetAgendaAppointmentsHasIdClient) {
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->targetDb}`.dap12_agenda_appuntamenti
                    (
                        id_slot, id_dot, id_paziente, id_client, cognome, nome, telefono, cellulare, email,
                        note, motivo_visita, indirizzo_visita, comune_visita, stato, created_by, created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, NULL, NOW())
                ");
                $stmt->bind_param(
                    'iiiisssssss',
                    $existing['id_slot'],
                    $candidate['target_id_dot'],
                    $idPaziente,
                    $idClient,
                    $candidate['paz_cognome'],
                    $candidate['paz_nome'],
                    $candidate['paz_telefono'],
                    $candidate['paz_cellulare'],
                    $candidate['paz_email'],
                    $candidate['legacy_note'],
                    $state
                );
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->targetDb}`.dap12_agenda_appuntamenti
                    (
                        id_slot, id_dot, id_paziente, cognome, nome, telefono, cellulare, email,
                        note, motivo_visita, indirizzo_visita, comune_visita, stato, created_by, created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, NULL, NOW())
                ");
                $stmt->bind_param(
                    'iiisssssss',
                    $existing['id_slot'],
                    $candidate['target_id_dot'],
                    $idPaziente,
                    $candidate['paz_cognome'],
                    $candidate['paz_nome'],
                    $candidate['paz_telefono'],
                    $candidate['paz_cellulare'],
                    $candidate['paz_email'],
                    $candidate['legacy_note'],
                    $state
                );
            }
            $stmt->execute();
            $stmt->close();

            $stmt = $this->db->prepare("
                UPDATE `{$this->targetDb}`.dap11_agenda_slot
                SET stato = 'PRENOTATO', updated_at = NOW()
                WHERE id_slot = ?
            ");
            $stmt->bind_param('i', $existing['id_slot']);
            $stmt->execute();
            $stmt->close();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function insertSlotAndAppointment(array $candidate, array $patientResolution): int
    {
        $configId = $this->resolveConfigIdForSlot((int)$candidate['target_id_dot'], (string)$candidate['data_slot']);
        $state = $this->mapLegacyAppointmentState($candidate);
        $slotMeta = $this->resolveSlotMetadataForAppointmentCandidate($candidate);

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO `{$this->targetDb}`.dap11_agenda_slot
                (
                    id_dot, id_config, data_slot, ora_inizio, ora_fine, tipo_slot, stato,
                    titolo_libero, id_amb_legacy, ambulatorio, stanza, origine_slot, note_interne, created_at, updated_at
                )
                VALUES (?, ?, ?, ?, ?, 'AMBULATORIO', 'PRENOTATO', 'MIGRATO_LEGACY', NULLIF(?, 0), ?, ?, 'EXTRA', ?, NOW(), NOW())
            ");
            $noteInterne = sprintf(
                'Migrato da %s legacy - id_appuntamento=%d id_prenotazione=%d',
                (string)$candidate['source_schema'],
                (int)$candidate['id_appuntamento'],
                (int)$candidate['id_prenotazione']
            );
            $idAmbLegacy = (int)($slotMeta['id_amb_legacy'] ?? 0);
            $ambulatorio = trim((string)($slotMeta['ambulatorio'] ?? ''));
            $stanza = trim((string)($slotMeta['stanza'] ?? ''));
            $stmt->bind_param(
                'iisssisss',
                $candidate['target_id_dot'],
                $configId,
                $candidate['data_slot'],
                $candidate['data_ora_ini'],
                $candidate['data_ora_fin'],
                $idAmbLegacy,
                $ambulatorio,
                $stanza,
                $noteInterne
            );
            $stmt->execute();
            $slotId = (int)$this->db->insert_id;
            $stmt->close();

            $idPaziente = (int)($patientResolution['id_paziente'] ?? 0);
            $idClient = (int)($patientResolution['id_client'] ?? 0);

            if ($this->targetAgendaAppointmentsHasIdClient) {
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->targetDb}`.dap12_agenda_appuntamenti
                    (
                        id_slot, id_dot, id_paziente, id_client, cognome, nome, telefono, cellulare, email,
                        note, motivo_visita, indirizzo_visita, comune_visita, stato, created_by, created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, NULL, NOW())
                ");
                $stmt->bind_param(
                    'iiiisssssss',
                    $slotId,
                    $candidate['target_id_dot'],
                    $idPaziente,
                    $idClient,
                    $candidate['paz_cognome'],
                    $candidate['paz_nome'],
                    $candidate['paz_telefono'],
                    $candidate['paz_cellulare'],
                    $candidate['paz_email'],
                    $candidate['legacy_note'],
                    $state
                );
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->targetDb}`.dap12_agenda_appuntamenti
                    (
                        id_slot, id_dot, id_paziente, cognome, nome, telefono, cellulare, email,
                        note, motivo_visita, indirizzo_visita, comune_visita, stato, created_by, created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, NULL, NOW())
                ");
                $stmt->bind_param(
                    'iiisssssss',
                    $slotId,
                    $candidate['target_id_dot'],
                    $idPaziente,
                    $candidate['paz_cognome'],
                    $candidate['paz_nome'],
                    $candidate['paz_telefono'],
                    $candidate['paz_cellulare'],
                    $candidate['paz_email'],
                    $candidate['legacy_note'],
                    $state
                );
            }
            $stmt->execute();
            $stmt->close();

            $this->db->commit();
            return $slotId;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function resolveSlotMetadataForAppointmentCandidate(array $candidate): array
    {
        $targetDoctorId = (int)($candidate['target_id_dot'] ?? 0);
        $date = (string)($candidate['data_slot'] ?? '');
        $startTime = $this->normalizeTime(substr((string)($candidate['data_ora_ini'] ?? ''), 11));
        $endTime = $this->normalizeTime(substr((string)($candidate['data_ora_fin'] ?? ''), 11));

        if ($targetDoctorId <= 0 || $date === '' || $startTime === '' || $endTime === '') {
            return [
                'id_amb_legacy' => 0,
                'ambulatorio' => '',
                'stanza' => '',
            ];
        }

        $weekday = (int)date('N', strtotime($date));
        foreach ($this->targetActiveConfigsByDoctor[$targetDoctorId] ?? [] as $config) {
            if ($date < (string)$config['data_inizio'] || $date > (string)$config['data_fine']) {
                continue;
            }

            $dayRow = $config['days'][$weekday] ?? null;
            if ($dayRow === null) {
                continue;
            }

            foreach (($dayRow['fasce'] ?? []) as $fascia) {
                $fasciaStart = $this->normalizeTime((string)($fascia['ora_inizio'] ?? ''));
                $fasciaEnd = $this->normalizeTime((string)($fascia['ora_fine'] ?? ''));
                if ($fasciaStart === '' || $fasciaEnd === '') {
                    continue;
                }

                if ($startTime >= $fasciaStart && $endTime <= $fasciaEnd) {
                    return [
                        'id_amb_legacy' => (int)($fascia['id_amb_legacy'] ?? 0),
                        'ambulatorio' => trim((string)($fascia['ambulatorio'] ?? '')),
                        'stanza' => trim((string)($fascia['stanza'] ?? '')),
                    ];
                }
            }
        }

        return [
            'id_amb_legacy' => 0,
            'ambulatorio' => '',
            'stanza' => '',
        ];
    }

    private function registerAppointmentCache(int $slotId, array $candidate, array $patientResolution, ?array $existingSlot): void
    {
        $key = $this->buildSlotKey(
            (int)$candidate['target_id_dot'],
            (string)$candidate['data_ora_ini'],
            (string)$candidate['data_ora_fin']
        );

        $idSlot = $slotId > 0 ? $slotId : (int)($existingSlot['id_slot'] ?? 0);
        $idPaziente = (int)($patientResolution['id_paziente'] ?? 0);
        $dataSlot = (string)$candidate['data_slot'];

        $this->targetSlotsByKey[$key] = [
            'id_slot' => $idSlot,
            'id_dot' => (int)$candidate['target_id_dot'],
            'data_slot' => $dataSlot,
            'ora_inizio' => (string)$candidate['data_ora_ini'],
            'ora_fine' => (string)$candidate['data_ora_fin'],
            'slot_stato' => 'PRENOTATO',
            'origine_slot' => $existingSlot['origine_slot'] ?? 'EXTRA',
            'id_appuntamento' => 1,
            'appointment_patient_id' => $idPaziente,
            'appointment_client_id' => (int)($patientResolution['id_client'] ?? 0),
            'appointment_cognome' => (string)$candidate['paz_cognome'],
            'appointment_nome' => (string)$candidate['paz_nome'],
            'appointment_stato' => $this->mapLegacyAppointmentState($candidate),
        ];

        $dayKey = $this->buildDoctorDayKey((int)$candidate['target_id_dot'], $dataSlot);
        $this->activeAppointmentDayCount[$dayKey] = (int)($this->activeAppointmentDayCount[$dayKey] ?? 0) + 1;
    }

    private function compactAppointmentForReport(array $candidate, array $patientResolution): array
    {
        return [
            'source_schema' => (string)$candidate['source_schema'],
            'legacy_id_appuntamento' => (int)$candidate['id_appuntamento'],
            'legacy_id_prenotazione' => (int)$candidate['id_prenotazione'],
            'target_id_dot' => (int)$candidate['target_id_dot'],
            'data_ora_ini' => (string)$candidate['data_ora_ini'],
            'data_ora_fin' => (string)$candidate['data_ora_fin'],
            'legacy_id_paziente' => (int)$candidate['id_paziente'],
            'target_id_paziente' => (int)($patientResolution['id_paziente'] ?? 0),
            'target_id_client' => (int)($patientResolution['id_client'] ?? 0),
            'patient_strategy' => (string)($patientResolution['strategy'] ?? ''),
            'client_strategy' => (string)($patientResolution['client_strategy'] ?? ''),
            'paziente' => trim((string)$candidate['paz_cognome'] . ' ' . (string)$candidate['paz_nome']),
            'appointment_stato' => $this->mapLegacyAppointmentState($candidate),
        ];
    }

    private function buildLegacyPatientCandidateFromSourceRow(array $row, int $targetDoctorId): array
    {
        return [
            'source_schema' => $this->sourceDb,
            'target_id_dot' => $targetDoctorId,
            'id_paziente' => (int)($row['id_paziente'] ?? 0),
            'paz_cognome' => (string)($row['paz_cognome'] ?? ''),
            'paz_nome' => (string)($row['paz_nome'] ?? ''),
            'paz_data_nascita' => (string)($row['paz_data_nascita'] ?? ''),
            'paz_cod_fis' => (string)($row['paz_cod_fis'] ?? ''),
            'paz_comune_nascita' => (string)($row['paz_comune_nascita'] ?? ''),
            'paz_provincia_nascita' => (string)($row['paz_provincia_nascita'] ?? ''),
            'paz_indirizzo' => (string)($row['paz_indirizzo'] ?? ''),
            'paz_citta' => (string)($row['paz_citta'] ?? ''),
            'paz_cap' => (string)($row['paz_cap'] ?? ''),
            'paz_provincia' => (string)($row['paz_provincia'] ?? ''),
            'paz_residenza_indirizzo' => (string)($row['paz_residenza_indirizzo'] ?? ''),
            'paz_residenza_comune' => (string)($row['paz_residenza_comune'] ?? ''),
            'paz_residenza_cap' => (string)($row['paz_residenza_cap'] ?? ''),
            'paz_residenza_provincia' => (string)($row['paz_residenza_provincia'] ?? ''),
            'paz_telefono' => (string)($row['paz_telefono'] ?? ''),
            'paz_cellulare' => (string)($row['paz_cellulare'] ?? ''),
            'paz_email' => (string)($row['paz_email'] ?? ''),
            'paz_spec' => (string)($row['paz_spec'] ?? ''),
            'paz_bloccato' => (int)($row['paz_bloccato'] ?? 0),
            'paz_id_dot' => (int)($row['paz_id_dot'] ?? 0),
        ];
    }

    private function composeLegacyPatientLabel(array $row): string
    {
        $label = trim((string)($row['paz_cognome'] ?? '') . ' ' . (string)($row['paz_nome'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        $legacyPatientId = (int)($row['id_paziente'] ?? 0);
        if ($legacyPatientId > 0) {
            return 'Paziente legacy #' . $legacyPatientId;
        }

        return 'Paziente legacy';
    }

    private function insertAgendaMemoNote(array $candidate, array $patientResolution, ?int $createdBy): int
    {
        $writeDb = $this->writeDb ?? $this->db;
        $patientId = (int)($patientResolution['id_paziente'] ?? 0);
        $patientId = $patientId > 0 ? $patientId : null;
        $clientId = (int)($patientResolution['id_client'] ?? 0);
        $clientId = $clientId > 0 ? $clientId : null;

        $columns = [
            'id_dot',
            'data_inizio_validita',
            'cliente',
            'id_paziente',
        ];
        $placeholders = ['?', '?', '?', '?'];
        $types = 'issi';
        $values = [
            (int)$candidate['target_id_dot'],
            (string)$candidate['data_inizio_validita'],
            (string)$candidate['cliente'],
            $patientId,
        ];

        if ($this->targetAgendaNotesHasIdClient) {
            $columns[] = 'id_client';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $clientId;
        }

        $extraColumns = [
            'telefono' => (string)($candidate['paz_telefono'] ?? ''),
            'cellulare' => (string)($candidate['paz_cellulare'] ?? ''),
            'indirizzo' => (string)($candidate['paz_indirizzo'] ?? ''),
            'citta' => (string)($candidate['paz_citta'] ?? ''),
            'note' => (string)($candidate['note'] ?? ''),
            'testo' => (string)($candidate['testo'] ?? ''),
            'fatta' => (int)($candidate['fatta'] ?? 0),
            'data_fatta' => $candidate['legacy_data_fatta'],
            'attiva' => 1,
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
            'created_at' => (string)$candidate['legacy_created_at'],
            'updated_at' => (string)$candidate['legacy_updated_at'],
        ];

        foreach ($extraColumns as $column => $value) {
            $columns[] = $column;
            $placeholders[] = '?';
            $types .= is_int($value) ? 'i' : 's';
            $values[] = $value;
        }

        if ($this->targetAgendaNotesHasLegacyId) {
            $columns[] = 'legacy_id_not_dot';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = (int)($candidate['legacy_id_not_dot'] ?? 0);
        }

        $sql = "
            INSERT INTO `{$this->targetDb}`.dap15_agenda_note
            (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $placeholders) . ")
        ";

        $stmt = $writeDb->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $id = (int)$writeDb->insert_id;
        $stmt->close();

        return $id;
    }

    private function insertLegacyDomiciliare(array $candidate, array $patientResolution): int
    {
        $writeDb = $this->writeDb ?? $this->db;
        $patientId = (int)($patientResolution['id_paziente'] ?? 0);
        $patientId = $patientId > 0 ? $patientId : null;
        $clientId = (int)($patientResolution['id_client'] ?? 0);
        $clientId = $clientId > 0 ? $clientId : null;

        $columns = [
            'id_dot',
            'id_paziente',
        ];
        $placeholders = ['?', '?'];
        $types = 'ii';
        $values = [
            (int)$candidate['target_id_dot'],
            $patientId,
        ];

        if ($this->targetDomiciliariHasIdClient) {
            $columns[] = 'id_client';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $clientId;
        }

        if ($this->targetDomiciliariHasGiornoVisita) {
            $columns[] = 'giorno_visita';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = (string)$candidate['giorno_visita'];
        }

        $extraColumns = [
            'cognome' => (string)($candidate['paz_cognome'] ?? ''),
            'nome' => (string)($candidate['paz_nome'] ?? ''),
            'telefono' => (string)($candidate['paz_telefono'] ?? ''),
            'cellulare' => (string)($candidate['paz_cellulare'] ?? ''),
            'indirizzo' => (string)($candidate['paz_indirizzo'] ?? ''),
            'citta' => (string)($candidate['paz_citta'] ?? ''),
            'note' => (string)($candidate['note'] ?? ''),
            'data_creazione' => (string)$candidate['legacy_created_at'],
            'data_modifica' => $candidate['legacy_updated_at'] ?? null,
            'stato' => (string)($candidate['stato'] ?? 'ATTIVA'),
        ];

        foreach ($extraColumns as $column => $value) {
            $columns[] = $column;
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $value;
        }

        if ($this->targetDomiciliariHasLegacyId) {
            $columns[] = 'legacy_id_vis';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = (int)($candidate['legacy_id_vis'] ?? 0);
        }

        $sql = "
            INSERT INTO `{$this->targetDb}`.dap13_visite_domiciliari
            (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $placeholders) . ")
        ";

        $stmt = $writeDb->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $id = (int)$writeDb->insert_id;
        $stmt->close();

        return $id;
    }

    private function buildAgendaMemoSignature(array $row): string
    {
        $doctorId = (int)($row['id_dot'] ?? ($row['target_id_dot'] ?? 0));
        $validFrom = (string)($row['data_inizio_validita'] ?? '');
        $cliente = $this->normalizeFreeText((string)($row['cliente'] ?? ''));
        $note = $this->normalizeFreeText((string)($row['note'] ?? ($row['testo'] ?? '')));
        $fatta = (int)($row['fatta'] ?? 0);
        $createdAt = (string)($row['created_at'] ?? ($row['legacy_created_at'] ?? ''));

        if ($doctorId <= 0 || $validFrom === '' || $cliente === '' || $note === '') {
            return '';
        }

        return implode('|', [
            $doctorId,
            $validFrom,
            $fatta,
            $createdAt,
            md5($cliente . '|' . $note),
        ]);
    }

    private function buildDomiciliareSignature(array $row): string
    {
        $doctorId = (int)($row['id_dot'] ?? ($row['target_id_dot'] ?? 0));
        $giorno = (string)($row['giorno_visita'] ?? '');
        $label = $this->normalizeFreeText(trim((string)($row['cognome'] ?? ($row['paz_cognome'] ?? '')) . ' ' . (string)($row['nome'] ?? ($row['paz_nome'] ?? ''))));
        $indirizzo = $this->normalizeFreeText((string)($row['indirizzo'] ?? ($row['paz_indirizzo'] ?? '')));
        $note = $this->normalizeFreeText((string)($row['note'] ?? ''));
        $createdAt = (string)($row['data_creazione'] ?? ($row['legacy_created_at'] ?? ''));

        if ($doctorId <= 0 || $giorno === '' || $label === '') {
            return '';
        }

        return implode('|', [
            $doctorId,
            $giorno,
            $createdAt,
            md5($label . '|' . $indirizzo . '|' . $note),
        ]);
    }

    private function buildMissingPatientExample(string $type, array $candidate, array $patientResolution): array
    {
        return [
            'type' => $type,
            'target_id_dot' => (int)($candidate['target_id_dot'] ?? 0),
            'legacy_id_paziente' => (int)($candidate['id_paziente'] ?? 0),
            'legacy_cod_fis' => (string)($candidate['paz_cod_fis'] ?? ''),
            'paziente' => trim((string)($candidate['paz_cognome'] ?? '') . ' ' . (string)($candidate['paz_nome'] ?? '')),
            'reference_date' => (string)($candidate['giorno_visita'] ?? ($candidate['data_inizio_validita'] ?? ($candidate['data_slot'] ?? ''))),
            'patient_strategy' => (string)($patientResolution['strategy'] ?? ''),
            'client_strategy' => (string)($patientResolution['client_strategy'] ?? ''),
        ];
    }

    private function normalizeReasonableDate(string $value): ?string
    {
        $dateTime = $this->normalizeReasonableDateTime($value);
        return $dateTime !== null ? substr($dateTime, 0, 10) : null;
    }

    private function normalizeReasonableDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        $year = (int)date('Y', $timestamp);
        if ($year < 2000 || $year > 2100) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeFreeText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function mergeLegacyTextBlocks(string $existing, string $incoming): string
    {
        $parts = [];
        foreach ([$existing, $incoming] as $text) {
            foreach (preg_split("/\R{2,}/", trim($text)) ?: [] as $part) {
                $clean = trim($part);
                if ($clean === '') {
                    continue;
                }
                $key = $this->normalizeFreeText($clean);
                if (!isset($parts[$key])) {
                    $parts[$key] = $clean;
                }
            }
        }

        return implode("\n\n", array_values($parts));
    }

    private function registerActiveConfig(array $config): void
    {
        $idDot = (int)$config['id_dot'];
        $idConfig = (int)$config['id_config'];
        if (!isset($this->targetActiveConfigsByDoctor[$idDot])) {
            $this->targetActiveConfigsByDoctor[$idDot] = [];
        }
        if (!isset($this->targetActiveConfigIntervalsByDoctor[$idDot])) {
            $this->targetActiveConfigIntervalsByDoctor[$idDot] = [];
        }

        $replaced = false;
        foreach ($this->targetActiveConfigsByDoctor[$idDot] as $index => $existing) {
            if ((int)$existing['id_config'] === $idConfig) {
                $this->targetActiveConfigsByDoctor[$idDot][$index] = $config;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $this->targetActiveConfigsByDoctor[$idDot][] = $config;
        }

        $interval = [
            'id_config' => $idConfig,
            'start' => (string)$config['data_inizio'],
            'end' => (string)$config['data_fine'],
        ];
        $replacedInterval = false;
        foreach ($this->targetActiveConfigIntervalsByDoctor[$idDot] as $index => $existing) {
            if ((int)$existing['id_config'] === $idConfig) {
                $this->targetActiveConfigIntervalsByDoctor[$idDot][$index] = $interval;
                $replacedInterval = true;
                break;
            }
        }
        if (!$replacedInterval) {
            $this->targetActiveConfigIntervalsByDoctor[$idDot][] = $interval;
        }

        usort($this->targetActiveConfigsByDoctor[$idDot], static function (array $left, array $right): int {
            $leftKey = $left['data_inizio'] . '|' . str_pad((string)$left['id_config'], 10, '0', STR_PAD_LEFT);
            $rightKey = $right['data_inizio'] . '|' . str_pad((string)$right['id_config'], 10, '0', STR_PAD_LEFT);
            return strcmp($leftKey, $rightKey);
        });
        usort($this->targetActiveConfigIntervalsByDoctor[$idDot], static function (array $left, array $right): int {
            $leftKey = $left['start'] . '|' . str_pad((string)$left['id_config'], 10, '0', STR_PAD_LEFT);
            $rightKey = $right['start'] . '|' . str_pad((string)$right['id_config'], 10, '0', STR_PAD_LEFT);
            return strcmp($leftKey, $rightKey);
        });
    }

    private function registerGeneratedSlotCache(
        int $slotId,
        int $targetDoctorId,
        int $configId,
        string $date,
        string $startDateTime,
        string $endDateTime,
        string $slotState,
        string $origin = 'CONFIG'
    ): void {
        $key = $this->buildSlotKey($targetDoctorId, $startDateTime, $endDateTime);
        if ($date < $this->appointmentsFrom) {
            $this->targetSlotsByKey[$key] = true;
            return;
        }

        $this->targetSlotsByKey[$key] = [
            'id_slot' => $slotId,
            'id_dot' => $targetDoctorId,
            'data_slot' => $date,
            'ora_inizio' => $startDateTime,
            'ora_fine' => $endDateTime,
            'slot_stato' => $slotState,
            'origine_slot' => $origin,
            'id_appuntamento' => 0,
            'id_config' => $configId,
        ];
    }

    private function subtractCoveredIntervals(string $start, string $end, array $intervals): array
    {
        if ($intervals === []) {
            return [['start' => $start, 'end' => $end]];
        }

        usort($intervals, static function (array $left, array $right): int {
            $cmp = strcmp((string)$left['start'], (string)$right['start']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string)$left['end'], (string)$right['end']);
        });

        $cursor = $start;
        $out = [];
        foreach ($intervals as $interval) {
            $intervalStart = (string)$interval['start'];
            $intervalEnd = (string)$interval['end'];
            if ($intervalEnd < $cursor) {
                continue;
            }
            if ($intervalStart > $end) {
                break;
            }

            if ($intervalStart > $cursor) {
                $gapEnd = $this->dateMinusOneDay($intervalStart);
                if ($gapEnd >= $cursor) {
                    $out[] = [
                        'start' => $cursor,
                        'end' => $this->minDate($gapEnd, $end),
                    ];
                }
            }

            if ($intervalEnd >= $end) {
                $cursor = $this->datePlusOneDay($end);
                break;
            }

            $cursor = $this->datePlusOneDay($intervalEnd);
            if ($cursor > $end) {
                break;
            }
        }

        if ($cursor <= $end) {
            $out[] = [
                'start' => $cursor,
                'end' => $end,
            ];
        }

        return array_values(array_filter($out, static function (array $interval): bool {
            return !empty($interval['start']) && !empty($interval['end']) && $interval['start'] <= $interval['end'];
        }));
    }

    private function buildClosedWeek(): array
    {
        $days = [];
        for ($day = 1; $day <= 7; $day++) {
            $days[$day] = $this->buildClosedDay($day);
        }

        return $days;
    }

    private function buildClosedDay(int $dayNumber): array
    {
        return [
            'giorno_settimana' => $dayNumber,
            'giorno_libero' => 1,
            'mattina_attiva' => 0,
            'mattina_ora_inizio' => null,
            'mattina_ora_fine' => null,
            'mattina_durata_slot' => 0,
            'pomeriggio_attiva' => 0,
            'pomeriggio_modalita_inizio' => 'FINE_MATTINA',
            'pomeriggio_ora_inizio' => null,
            'pomeriggio_ora_fine' => null,
            'pomeriggio_durata_slot' => 0,
            'fasce' => [],
        ];
    }

    private function buildLegacyDayDefinition(int $dayNumber, array $rawDay, array $durationHints = []): array
    {
        $fasce = $this->compressLegacySlotsToFasce($rawDay['slots'] ?? [], $durationHints);
        $day = $this->buildClosedDay($dayNumber);
        $day['giorno_libero'] = $fasce === [] ? 1 : 0;
        $day['fasce'] = $fasce;

        $legacyColumns = $this->buildLegacyDayColumnsFromFasce($day);
        foreach ($legacyColumns as $key => $value) {
            $day[$key] = $value;
        }

        return $day;
    }

    private function compressLegacySlotsToFasce(array $slots, array $durationHints = []): array
    {
        $normalized = [];
        foreach ($slots as $slot) {
            $oraInizio = $this->normalizeTime((string)($slot['ora_inizio'] ?? ''));
            $oraFine = $this->normalizeTime((string)($slot['ora_fine'] ?? ''));
            $durata = (int)($slot['durata_slot'] ?? 0);
            $legacyType = strtoupper(trim((string)($slot['legacy_tipo_orario'] ?? '')));
            $idAmbLegacy = (int)($slot['id_amb_legacy'] ?? 0);
            $ambulatorio = trim((string)($slot['ambulatorio'] ?? ''));
            $stanza = trim((string)($slot['stanza'] ?? ''));
            if ($oraInizio === '' || $oraFine === '' || $durata <= 0) {
                continue;
            }

            $normalized[] = [
                'ora_inizio' => $oraInizio,
                'ora_fine' => $oraFine,
                'durata_slot' => $durata,
                'legacy_tipo_orario' => $legacyType,
                'id_amb_legacy' => $idAmbLegacy,
                'ambulatorio' => $ambulatorio,
                'stanza' => $stanza,
            ];
        }

        usort($normalized, static function (array $left, array $right): int {
            $cmp = strcmp((string)$left['ora_inizio'], (string)$right['ora_inizio']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string)$left['ora_fine'], (string)$right['ora_fine']);
        });

        $fasce = [];
        $current = null;
        foreach ($normalized as $slot) {
            if ($current === null) {
                $current = $slot;
                continue;
            }

            if (
                (string)$slot['ora_inizio'] === (string)$current['ora_fine']
                && (int)$slot['durata_slot'] === (int)$current['durata_slot']
                && (string)($slot['legacy_tipo_orario'] ?? '') === (string)($current['legacy_tipo_orario'] ?? '')
                && (int)($slot['id_amb_legacy'] ?? 0) === (int)($current['id_amb_legacy'] ?? 0)
                && (string)($slot['ambulatorio'] ?? '') === (string)($current['ambulatorio'] ?? '')
                && (string)($slot['stanza'] ?? '') === (string)($current['stanza'] ?? '')
            ) {
                $current['ora_fine'] = $slot['ora_fine'];
                continue;
            }

            $fasce[] = $current;
            $current = $slot;
        }

        if ($current !== null) {
            $fasce[] = $current;
        }

        foreach ($fasce as &$fascia) {
            $legacyType = (string)($fascia['legacy_tipo_orario'] ?? '');
            $hintDuration = (int)($durationHints[$legacyType] ?? 0);
            if ($hintDuration > 0) {
                $fascia['durata_slot'] = $hintDuration;
            }
        }
        unset($fascia);

        return $fasce;
    }

    private function buildLegacyDayColumnsFromFasce(array $dayRow): array
    {
        $fasce = array_values($dayRow['fasce'] ?? []);
        $prima = $fasce[0] ?? null;
        $seconda = $fasce[1] ?? null;

        return [
            'giorno_libero' => empty($dayRow['giorno_libero']) ? 0 : 1,
            'mattina_attiva' => $prima !== null ? 1 : 0,
            'mattina_ora_inizio' => $prima['ora_inizio'] ?? null,
            'mattina_ora_fine' => $prima['ora_fine'] ?? null,
            'mattina_durata_slot' => $prima !== null ? (int)$prima['durata_slot'] : 0,
            'pomeriggio_attiva' => $seconda !== null ? 1 : 0,
            'pomeriggio_modalita_inizio' => $seconda !== null ? 'MANUALE' : 'FINE_MATTINA',
            'pomeriggio_ora_inizio' => $seconda['ora_inizio'] ?? null,
            'pomeriggio_ora_fine' => $seconda['ora_fine'] ?? null,
            'pomeriggio_durata_slot' => $seconda !== null ? (int)$seconda['durata_slot'] : 0,
        ];
    }

    private function buildFasceFromDayRow(array $row): array
    {
        $fasce = [];

        $mattinaInizio = $this->normalizeTime((string)($row['mattina_ora_inizio'] ?? ''));
        $mattinaFine = $this->normalizeTime((string)($row['mattina_ora_fine'] ?? ''));
        $mattinaDurata = (int)($row['mattina_durata_slot'] ?? 0);
        if ((int)($row['mattina_attiva'] ?? 0) === 1 && $mattinaInizio !== '' && $mattinaFine !== '' && $mattinaDurata > 0) {
            $fasce[] = [
                'ordine' => 1,
                'ora_inizio' => $mattinaInizio,
                'ora_fine' => $mattinaFine,
                'durata_slot' => $mattinaDurata,
                'id_amb_legacy' => (int)($row['id_amb_legacy'] ?? 0),
                'ambulatorio' => trim((string)($row['ambulatorio'] ?? '')),
                'stanza' => trim((string)($row['stanza'] ?? '')),
            ];
        }

        $pomeriggioInizio = '';
        $mode = (string)($row['pomeriggio_modalita_inizio'] ?? 'FINE_MATTINA');
        if ($mode === 'ORE_14') {
            $pomeriggioInizio = '14:00:00';
        } elseif ($mode === 'MANUALE') {
            $pomeriggioInizio = $this->normalizeTime((string)($row['pomeriggio_ora_inizio'] ?? ''));
        } else {
            $pomeriggioInizio = $mattinaFine;
        }

        $pomeriggioFine = $this->normalizeTime((string)($row['pomeriggio_ora_fine'] ?? ''));
        $pomeriggioDurata = (int)($row['pomeriggio_durata_slot'] ?? 0);
        if ((int)($row['pomeriggio_attiva'] ?? 0) === 1 && $pomeriggioInizio !== '' && $pomeriggioFine !== '' && $pomeriggioDurata > 0) {
            $fasce[] = [
                'ordine' => count($fasce) + 1,
                'ora_inizio' => $pomeriggioInizio,
                'ora_fine' => $pomeriggioFine,
                'durata_slot' => $pomeriggioDurata,
                'id_amb_legacy' => (int)($row['id_amb_legacy'] ?? 0),
                'ambulatorio' => trim((string)($row['ambulatorio'] ?? '')),
                'stanza' => trim((string)($row['stanza'] ?? '')),
            ];
        }

        usort($fasce, static function (array $left, array $right): int {
            return strcmp((string)$left['ora_inizio'], (string)$right['ora_inizio']);
        });

        return $fasce;
    }

    private function normalizeTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        foreach (['H:i:s', 'H:i'] as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt instanceof \DateTime && $dt->format($format) === $value) {
                return $dt->format('H:i:s');
            }
        }

        return $value;
    }

    private function minutesBetweenTimes(string $startTime, string $endTime): int
    {
        $start = strtotime('1970-01-01 ' . $startTime);
        $end = strtotime('1970-01-01 ' . $endTime);
        if ($start === false || $end === false || $end <= $start) {
            return 0;
        }

        return (int)(($end - $start) / 60);
    }

    private function minutesBetweenDateTimes(string $startDateTime, string $endDateTime): int
    {
        $start = strtotime($startDateTime);
        $end = strtotime($endDateTime);
        if ($start === false || $end === false || $end <= $start) {
            return 0;
        }

        return (int)(($end - $start) / 60);
    }

    private function minDate(?string $left, ?string $right): ?string
    {
        $left = $left !== null && trim($left) !== '' ? trim($left) : null;
        $right = $right !== null && trim($right) !== '' ? trim($right) : null;
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return $left <= $right ? $left : $right;
    }

    private function maxDate(?string $left, ?string $right): ?string
    {
        $left = $left !== null && trim($left) !== '' ? trim($left) : null;
        $right = $right !== null && trim($right) !== '' ? trim($right) : null;
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return $left >= $right ? $left : $right;
    }

    private function datePlusOneDay(string $date): string
    {
        return date('Y-m-d', strtotime($date . ' +1 day'));
    }

    private function dateMinusOneDay(string $date): string
    {
        return date('Y-m-d', strtotime($date . ' -1 day'));
    }

    private function resolveConfigIdForSlot(int $targetDoctorId, string $date): ?int
    {
        $cacheKey = $this->buildDoctorDayKey($targetDoctorId, $date);
        if (array_key_exists($cacheKey, $this->targetConfigCache)) {
            return $this->targetConfigCache[$cacheKey];
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
        $stmt->bind_param('iss', $targetDoctorId, $date, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->targetConfigCache[$cacheKey] = $row ? (int)$row['id_config'] : null;
        return $this->targetConfigCache[$cacheKey];
    }

    private function resolveTargetDoctorId(string $schema, int $sourceDoctorId): ?int
    {
        $doctorRow = $this->sourceDoctorsBySchema[$schema][$sourceDoctorId] ?? null;
        if ($doctorRow === null) {
            return null;
        }

        $usernameNorm = $this->normalizeUsername((string)$doctorRow['username']);
        if ($schema === $this->targetDb) {
            return $sourceDoctorId;
        }

        if (isset($this->personaleIdByAgendaDoctorId[$sourceDoctorId])) {
            return $sourceDoctorId;
        }

        if ($usernameNorm === '') {
            return null;
        }

        $target = $this->targetDoctorsByUsername[$usernameNorm] ?? null;
        return $target ? (int)$target['id_dot'] : null;
    }

    private function isDoctorAllowed(int $targetDoctorId): bool
    {
        return $this->doctorFilter === [] || in_array($targetDoctorId, $this->doctorFilter, true);
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

    private function columnExists(string $schema, string $table, string $column): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS c
            FROM information_schema.columns
            WHERE table_schema = ?
              AND table_name = ?
              AND column_name = ?
        ");
        $stmt->bind_param('sss', $schema, $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['c'] ?? 0) > 0;
    }

    private function scalar(string $sql): int
    {
        $row = $this->db->query($sql)->fetch_assoc();
        return (int)($row['c'] ?? 0);
    }

    private function parseLegacyDay(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\s+/', '', $value) ?? $value;
        $formats = ['d/m/Y', 'j/n/Y'];
        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt instanceof \DateTime && $dt->format($format === 'd/m/Y' ? 'd/m/Y' : 'j/n/Y') === $value) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    private function normalizeUsername(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function normalizeCode(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
        return $value;
    }

    private function normalizeUsableFiscalCode(string $value): string
    {
        $value = $this->normalizeCode($value);
        return strlen($value) === 16 ? $value : '';
    }

    private function normalizeText(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return trim($value);
    }

    private function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return trim($digits);
    }

    private function normalizeNamePair(string $cognome, string $nome): string
    {
        $cognome = $this->normalizeText($cognome);
        $nome = $this->normalizeText($nome);
        $joined = trim($cognome . '|' . $nome, '|');
        return $joined === '|' ? '' : $joined;
    }

    private function resolveTechnicalAppointmentKey(string $cognome, string $nome): string
    {
        $key = $this->normalizeNamePair($cognome, $nome);
        if ($key === '') {
            return '';
        }

        if (isset(self::TECHNICAL_APPOINTMENT_ALIASES[$key])) {
            $key = self::TECHNICAL_APPOINTMENT_ALIASES[$key];
        }

        return isset(self::TECHNICAL_APPOINTMENT_LABELS[$key]) ? $key : '';
    }

    private function buildPatientTriadKey(string $cognome, string $nome, string $cellulare): string
    {
        $nameKey = $this->normalizeNamePair($cognome, $nome);
        $cell = $this->normalizePhone($cellulare);
        if ($nameKey === '' || $cell === '') {
            return '';
        }

        return $nameKey . '|' . $cell;
    }

    private function buildDoctorPatientCfKey(int $idDot, string $codFis): string
    {
        $cf = $this->normalizeCode($codFis);
        if ($idDot <= 0 || $cf === '') {
            return '';
        }

        return $idDot . '|' . $cf;
    }

    private function buildDoctorPatientTriadKey(int $idDot, string $cognome, string $nome, string $cellulare): string
    {
        $triad = $this->buildPatientTriadKey($cognome, $nome, $cellulare);
        if ($triad === '') {
            return '';
        }

        return $idDot . '|' . $triad;
    }

    private function buildDoctorPatientEmailKey(int $idDot, string $cognome, string $nome, string $email): string
    {
        $nameKey = $this->normalizeNamePair($cognome, $nome);
        $email = strtolower(trim($email));
        if ($idDot <= 0 || $nameKey === '' || $email === '') {
            return '';
        }

        return $idDot . '|' . $nameKey . '|' . $email;
    }

    private function buildDoctorPatientPhoneKey(int $idDot, string $cognome, string $nome, string $telefono): string
    {
        $nameKey = $this->normalizeNamePair($cognome, $nome);
        $phone = $this->normalizePhone($telefono);
        if ($nameKey === '' || $phone === '') {
            return '';
        }

        return $idDot . '|' . $nameKey . '|' . $phone;
    }

    private function isClientCompatibleWithDoctor(int $clientId, int $targetDoctorId): bool
    {
        if ($clientId <= 0 || $targetDoctorId <= 0) {
            return false;
        }

        if ($this->clientHasAgendaDoctorLink($clientId, $targetDoctorId)) {
            return true;
        }

        if (!$this->isPrimaryFamilyAgendaDoctor($targetDoctorId)) {
            return true;
        }

        $currentFamilyDoctorId = $this->getCurrentFamilyAgendaDoctorForClient($clientId);
        return $currentFamilyDoctorId <= 0 || $currentFamilyDoctorId === $targetDoctorId;
    }

    private function getKnownAgendaDoctorIdsForClientRow(array $row): array
    {
        $clientId = (int)($row['id_client'] ?? 0);
        $agendaDoctorIds = [];

        $resolvedAgendaDoctorId = (int)($row['resolved_id_dot'] ?? 0);
        if ($resolvedAgendaDoctorId > 0) {
            $agendaDoctorIds[] = $resolvedAgendaDoctorId;
        }

        if ($clientId > 0 && isset($this->targetClientDoctorIdsById[$clientId])) {
            foreach (array_keys($this->targetClientDoctorIdsById[$clientId]) as $personaleId) {
                $personaleId = (int)$personaleId;
                $agendaDoctorId = (int)($this->agendaDoctorIdByPersonaleId[$personaleId] ?? 0);
                if ($agendaDoctorId > 0 && !in_array($agendaDoctorId, $agendaDoctorIds, true)) {
                    $agendaDoctorIds[] = $agendaDoctorId;
                }
            }
        }

        return $agendaDoctorIds;
    }

    private function clientHasAgendaDoctorLink(int $clientId, int $targetDoctorId): bool
    {
        if ($clientId <= 0 || $targetDoctorId <= 0) {
            return false;
        }

        $targetPersonaleId = (int)($this->personaleIdByAgendaDoctorId[$targetDoctorId] ?? 0);
        return $targetPersonaleId > 0 && !empty($this->targetClientDoctorIdsById[$clientId][$targetPersonaleId]);
    }

    private function getCurrentFamilyAgendaDoctorForClient(int $clientId): int
    {
        $clientRow = $this->targetClientsById[$clientId] ?? null;
        if ($clientRow === null) {
            return 0;
        }

        foreach ($this->getKnownAgendaDoctorIdsForClientRow($clientRow) as $agendaDoctorId) {
            if ($this->isPrimaryFamilyAgendaDoctor($agendaDoctorId)) {
                return $agendaDoctorId;
            }
        }

        return 0;
    }

    private function isPrimaryFamilyAgendaDoctor(int $agendaDoctorId): bool
    {
        if ($agendaDoctorId <= 0) {
            return false;
        }

        $personaleId = (int)($this->personaleIdByAgendaDoctorId[$agendaDoctorId] ?? 0);
        if ($personaleId <= 0) {
            return false;
        }

        $legacyDoctorTypeId = (int)($this->targetPersonaleLegacyDoctorTypeById[$personaleId] ?? 0);
        if ($legacyDoctorTypeId > 0) {
            return $legacyDoctorTypeId === 1;
        }

        return (int)($this->targetPersonaleFamilyFlagById[$personaleId] ?? 0) === 1;
    }

    private function encryptSqlOn(mysqli $connection, string $value): string
    {
        $escaped = $connection->real_escape_string($value);
        return "HEX(AES_ENCRYPT('{$escaped}', @key_str, @init_vector))";
    }

    private function buildSlotKey(int $idDot, string $start, string $end): string
    {
        return $idDot . '|' . $start . '|' . $end;
    }

    private function buildDoctorDayKey(int $idDot, string $date): string
    {
        return $idDot . '|' . $date;
    }

    private function buildOperatorDoctorKey(int $idOpe, int $idDot): string
    {
        return $idOpe . '|' . $idDot;
    }

    private function writeReport(): void
    {
        $json = json_encode(
            $this->report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            $json = json_encode([
                'status' => 'error',
                'message' => 'Impossibile serializzare il report JSON',
                'json_error' => json_last_error_msg(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        file_put_contents(
            $this->reportPath,
            $json . PHP_EOL
        );
    }
}
