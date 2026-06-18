<?php
declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '1024M');
ignore_user_abort(true);
date_default_timezone_set('Europe/Rome');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const DEFAULT_DB = 'mail';
const DEFAULT_SOURCE_DB = '';
const DEFAULT_REPORT_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'dap_patient_sync';
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
    }

    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=UTF-8');
    }

    main($argv);
}

function main(array $argv): void
{
    $options = parseCliOptions($argv);
    validateSourceDbOption((string)($options['source_db'] ?? ''));
    $env = loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');
    $dbConfig = buildDbConfig($env, $options);

    ensureDirectory((string)$options['report_dir']);

    $stamp = date('Ymd_His');
    $logPath = (string)$options['report_dir'] . DIRECTORY_SEPARATOR . 'dap_patient_sync_' . $stamp . '.log';
    $reportPath = (string)$options['report_dir'] . DIRECTORY_SEPARATOR . 'dap_patient_sync_' . $stamp . '.json';

    $logger = new CliLogger($logPath);
    $logger->info('Avvio sync far05_pazienti -> dap02_clients/dap09_client_doctor', [
        'mode' => !empty($options['apply']) ? 'apply' : 'dry-run',
        'db' => $dbConfig['database'],
        'source_db' => $options['source_db'],
        'doctor_filter' => $options['doctors'],
        'patient_filter' => $options['patients'],
        'only_resolvable_multi_doctor_cf' => !empty($options['only_resolvable_multi_doctor_cf']) ? 1 : 0,
    ]);

    $script = new FarPatientsToDapSync($dbConfig, $options, $logger, $reportPath);
    exit($script->run());
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

    $argv = ['migrate_mail_far_patients_to_dap.php'];
    if (!empty($request['apply']) && (string)$request['apply'] === '1') {
        $argv[] = '--apply';
    }

    $map = [
        'host',
        'port',
        'user',
        'pass',
        'db',
        'source-db',
        'doctors',
        'patients',
        'report-dir',
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
    $self = $_SERVER['PHP_SELF'] ?? 'migrate_mail_far_patients_to_dap.php';
    $base = $self !== '' ? $self : 'migrate_mail_far_patients_to_dap.php';
    $html = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Sync far05 -> dap02</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; background: #f6f7f9; color: #1f2937; }
        h1 { margin-top: 0; }
        .box { background: #fff; border: 1px solid #d1d5db; border-radius: 8px; padding: 16px; margin-bottom: 18px; max-width: 980px; }
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
        <h1>Sync far05_pazienti -> dap02_clients / dap09_client_doctor</h1>
        <p>Questo step allinea i pazienti legacy di <code>far05_pazienti</code> dentro <code>dap02_clients</code> e collega ogni record al professionista corretto in <code>dap09_client_doctor</code>.</p>
        <p>Lo script non usa piu nessun database legacy in modo implicito: <code>source-db</code> va indicato esplicitamente solo per una migrazione una tantum.</p>
        <p>Il matching usa prima il codice fiscale, poi fallback prudente per medico su anagrafica + cellulare/email. I dati gia presenti in <code>mail</code> non vengono sovrascritti se non sono vuoti.</p>
        <p>I log e i report vengono salvati in <code>writable/dap_patient_sync</code>.</p>
    </div>

    <div class="box">
        <h2>Dry-run</h2>
        <form method="get" action="{$base}">
            <input type="hidden" name="run" value="1">
            <div class="grid">
                <div>
                    <label for="doctors">doctors</label>
                    <input id="doctors" type="text" name="doctors" value="" placeholder="es. 1,2,3 legacy id_dot">
                </div>
                <div>
                    <label for="patients">patients</label>
                    <input id="patients" type="text" name="patients" value="" placeholder="es. 10,11,12 id_paziente">
                </div>
                <div>
                    <label for="db">db</label>
                    <input id="db" type="text" name="db" value="mail">
                </div>
                <div>
                    <label for="source-db">source-db</label>
                    <input id="source-db" type="text" name="source-db" value="" placeholder="es. farmacia_dump_locale">
                </div>
                <div>
                    <label for="report-dir">report-dir</label>
                    <input id="report-dir" type="text" name="report-dir" value="">
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
                    <label for="doctors-apply">doctors</label>
                    <input id="doctors-apply" type="text" name="doctors" value="" placeholder="es. 1,2,3 legacy id_dot">
                </div>
                <div>
                    <label for="patients-apply">patients</label>
                    <input id="patients-apply" type="text" name="patients" value="" placeholder="es. 10,11,12 id_paziente">
                </div>
                <div>
                    <label for="db-apply">db</label>
                    <input id="db-apply" type="text" name="db" value="mail">
                </div>
                <div>
                    <label for="source-db-apply">source-db</label>
                    <input id="source-db-apply" type="text" name="source-db" value="" placeholder="es. farmacia_dump_locale">
                </div>
                <div>
                    <label for="report-dir-apply">report-dir</label>
                    <input id="report-dir-apply" type="text" name="report-dir" value="">
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
    return [
        'apply' => hasFlag($argv, '--apply'),
        'only_resolvable_multi_doctor_cf' => hasFlag($argv, '--only-resolvable-multi-doctor-cf'),
        'host' => optionValue($argv, 'host'),
        'port' => optionValue($argv, 'port'),
        'user' => optionValue($argv, 'user'),
        'pass' => optionValue($argv, 'pass'),
        'db' => optionValue($argv, 'db') ?: DEFAULT_DB,
        'source_db' => optionValue($argv, 'source-db') ?: DEFAULT_SOURCE_DB,
        'doctors' => parseIdList(optionValue($argv, 'doctors')),
        'patients' => parseIdList(optionValue($argv, 'patients')),
        'report_dir' => optionValue($argv, 'report-dir') ?: DEFAULT_REPORT_DIR,
    ];
}

function validateSourceDbOption(string $sourceDb): void
{
    if (trim($sourceDb) === '') {
        throw new RuntimeException('Devi indicare esplicitamente --source-db=<dump_legacy_locale>. Nessun collegamento implicito a farmacia e consentito.');
    }
}

function hasFlag(array $argv, string $flag): bool
{
    return in_array($flag, $argv, true);
}

function optionValue(array $argv, string $name): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string)$arg, $prefix)) {
            return substr((string)$arg, strlen($prefix));
        }
    }

    return null;
}

function parseIdList(?string $value): array
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
        'database' => (string)$options['db'],
        'encryption_key' => (string)($env['DB_ENCRYPTION_KEY'] ?? 'PartitaIVA22'),
        'encryption_mode' => (string)($env['DB_ENCRYPTION_MODE'] ?? 'aes-256-cbc'),
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

    private function write(string $level, string $message, array $context = []): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] {$level} {$message}";
        if ($context !== []) {
            $line .= ' | ' . $this->formatContext($context);
        }

        file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND);
        if (isCliRequest()) {
            fwrite(STDOUT, $line . PHP_EOL);
            return;
        }

        echo $line . "\n";
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    private function formatContext(array $context): string
    {
        $parts = [];
        foreach ($context as $key => $value) {
            $parts[] = $key . '=' . $this->formatValue($value);
        }

        return implode(' | ', $parts);
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            if ($value === []) {
                return '-';
            }

            if (array_is_list($value)) {
                $items = array_map(fn($item) => $this->formatScalarValue($item), $value);
                return '[' . implode(', ', $items) . ']';
            }

            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = $key . ':' . $this->formatScalarValue($item);
            }
            return '{' . implode('; ', $parts) . '}';
        }

        return $this->formatScalarValue($value);
    }

    private function formatScalarValue(mixed $value): string
    {
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            return $json !== false ? $json : '[array]';
        }

        $text = (string)$value;
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text) ?: $text;
        return trim($text) !== '' ? trim($text) : '""';
    }
}

final class FarPatientsToDapSync
{
    private mysqli $db;
    private array $dbConfig;
    private array $options;
    private CliLogger $logger;
    private string $reportPath;
    private string $database;
    private string $sourceDatabase;
    private bool $apply;
    /** @var int[] */
    private array $doctorFilter = [];
    /** @var int[] */
    private array $patientFilter = [];
    private array $report = [];
    private array $doctorMapByLegacyId = [];
    private array $doctorByPersonaleId = [];
    private array $legacyDoctorBuckets = [];
    private array $targetPatientUsersByCf = [];
    private array $targetClientsById = [];
    private array $targetClientByUserId = [];
    private array $targetClientsByLegacyPatientId = [];
    private array $targetClientsByCf = [];
    private array $targetClientsByDoctorTriad = [];
    private array $targetClientsByDoctorEmail = [];
    private array $targetLinksByClientId = [];
    private array $targetDoctorIdsByClientId = [];
    private array $targetLinkCountsByClientId = [];
    private array $sourceRows = [];
    private array $sourceUsableCfDoctorSets = [];
    private array $sourceMultiDoctorCf = [];
    private array $resolvedMultiDoctorCfTargetByCf = [];
    private int $dryRunClientId = -1;
    private bool $onlyResolvableMultiDoctorCf = false;
    private bool $hasLegacyPatientBridge = false;

    public function __construct(array $dbConfig, array $options, CliLogger $logger, string $reportPath)
    {
        $this->dbConfig = $dbConfig;
        $this->options = $options;
        $this->logger = $logger;
        $this->reportPath = $reportPath;
        $this->database = (string)$dbConfig['database'];
        $this->sourceDatabase = (string)($options['source_db'] ?? DEFAULT_SOURCE_DB);
        $this->apply = !empty($options['apply']);
        $this->doctorFilter = $options['doctors'];
        $this->patientFilter = $options['patients'];
        $this->onlyResolvableMultiDoctorCf = !empty($options['only_resolvable_multi_doctor_cf']);
    }

    public function run(): int
    {
        try {
            $this->db = new mysqli(
                (string)$this->dbConfig['host'],
                (string)$this->dbConfig['user'],
                (string)$this->dbConfig['pass'],
                $this->database,
                (int)$this->dbConfig['port']
            );
            $this->db->set_charset('latin1');
            $this->initializeCryptoSession();

            if ($this->apply) {
                $this->db->begin_transaction();
            }

            $this->report = [
                'started_at' => date('c'),
                'mode' => $this->apply ? 'apply' : 'dry-run',
                'source_database' => $this->sourceDatabase,
                'database' => $this->database,
                'log_path' => $this->logger->path(),
                'report_path' => $this->reportPath,
                'summary_text_path' => $this->getSummaryTextPath(),
                'summary_html_path' => $this->getSummaryHtmlPath(),
                'filters' => [
                    'doctors' => $this->doctorFilter,
                    'patients' => $this->patientFilter,
                    'only_resolvable_multi_doctor_cf' => $this->onlyResolvableMultiDoctorCf,
                ],
                'assumptions' => [
                    'mail_authoritative' => 'I dati gia presenti in dap02_clients e dap09_client_doctor non vengono sovrascritti se non sono vuoti.',
                    'source_of_truth_far05' => 'La sorgente legacy usata per la sync pazienti e ' . $this->sourceDatabase . '.far05_pazienti; i record creati in mail.far05_pazienti per esigenze agenda non vengono trattati come sorgente primaria di questa sync.',
                    'matching_order' => [
                        'legacy_id_paziente',
                        'registered_user_cf',
                        'client_cf',
                        'doctor_triad',
                        'doctor_email',
                        'insert_new_client',
                    ],
                    'registration' => 'I pazienti senza accesso restano con id_user nullo; la registrazione successiva aggancera dap01_users al profilo gia importato.',
                    'multi_doctor_cf_policy' => 'Se un codice fiscale valido compare sotto piu dottori in far05, il merge automatico viene fermato e segnalato come conflitto, salvo il caso in cui tra i dottori legacy coinvolti ce ne sia uno solo attivo nel nuovo DB: in quel caso il paziente viene agganciato a quel dottore.',
                ],
                'audit' => [],
                'migration' => [],
            ];

            $this->loadDoctorMap();
            $this->loadTargetUsers();
            $this->loadTargetLinks();
            $this->loadTargetClients();
            $this->loadSourceRows();
            $this->audit();
            $this->migrate();

            if ($this->apply) {
                $this->db->commit();
            }

            $this->report['finished_at'] = date('c');
            $this->report['status'] = 'ok';
            $this->writeReport();

            $this->logger->info('Script completato', [
                'report_path' => $this->reportPath,
                'summary_text_path' => $this->getSummaryTextPath(),
                'summary_html_path' => $this->getSummaryHtmlPath(),
                'log_path' => $this->logger->path(),
            ]);

            $this->db->close();
            return 0;
        } catch (\Throwable $e) {
            if (isset($this->db) && $this->db instanceof mysqli) {
                try {
                    if ($this->apply) {
                        $this->db->rollback();
                    }
                } catch (\Throwable) {
                    // ignore
                }
                $this->db->close();
            }

            $this->report['finished_at'] = date('c');
            $this->report['status'] = 'error';
            $this->report['error'] = [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
            $this->writeReport();
            $this->logger->error('Script terminato con errore', $this->report['error']);
            return 1;
        }
    }

    private function initializeCryptoSession(): void
    {
        $this->db->query("SET lc_time_names = 'it_IT'");
        $mode = $this->db->real_escape_string((string)$this->dbConfig['encryption_mode']);
        $this->db->query("SET block_encryption_mode = '{$mode}'");

        $key = $this->db->real_escape_string((string)$this->dbConfig['encryption_key']);
        $this->db->query("SET @key_str = SHA2('{$key}', 512)");
        $this->db->query("SET @init_vector = RANDOM_BYTES(16)");
    }

    private function loadDoctorMap(): void
    {
        $sql = "
            SELECT
                id_personale,
                COALESCE(legacy_id_dot, 0) AS legacy_id_dot,
                COALESCE(id_user, 0) AS id_user,
                COALESCE(tipo, 0) AS tipo,
                COALESCE(titolare, 0) AS titolare,
                COALESCE(sostituto, 0) AS sostituto,
                COALESCE(is_active, 1) AS is_active,
                COALESCE(f_dom, 0) AS f_dom,
                COALESCE(legacy_dot_tipo_id, 0) AS legacy_dot_tipo_id
            FROM `{$this->database}`.dap03_personale
            WHERE legacy_id_dot IS NOT NULL
              AND legacy_id_dot > 0
            ORDER BY legacy_id_dot ASC, titolare DESC, tipo ASC, id_personale ASC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $row['id_personale'] = (int)$row['id_personale'];
            $row['legacy_id_dot'] = (int)$row['legacy_id_dot'];
            $row['id_user'] = (int)$row['id_user'];
            $row['tipo'] = (int)$row['tipo'];
            $row['titolare'] = (int)$row['titolare'];
            $row['sostituto'] = (int)($row['sostituto'] ?? 0);
            $row['is_active'] = (int)($row['is_active'] ?? 1);
            $row['f_dom'] = (int)($row['f_dom'] ?? 0);
            $row['legacy_dot_tipo_id'] = (int)($row['legacy_dot_tipo_id'] ?? 0);
            $this->doctorByPersonaleId[(int)$row['id_personale']] = $row;
            $this->legacyDoctorBuckets[(int)$row['legacy_id_dot']][] = $row;
        }

        foreach ($this->legacyDoctorBuckets as $legacyId => $bucket) {
            usort($bucket, function (array $left, array $right): int {
                $leftDoctorScore = ((int)$left['titolare'] === 1 ? 2 : 0) + ((int)$left['tipo'] === 1 ? 1 : 0);
                $rightDoctorScore = ((int)$right['titolare'] === 1 ? 2 : 0) + ((int)$right['tipo'] === 1 ? 1 : 0);
                if ($leftDoctorScore !== $rightDoctorScore) {
                    return $rightDoctorScore <=> $leftDoctorScore;
                }

                return (int)$left['id_personale'] <=> (int)$right['id_personale'];
            });

            $this->legacyDoctorBuckets[$legacyId] = $bucket;
            $this->doctorMapByLegacyId[$legacyId] = $bucket[0];
        }
    }

    private function loadTargetUsers(): void
    {
        $sql = "SELECT id_user, username, COALESCE(tipo_user, 0) AS tipo_user FROM `{$this->database}`.dap01_users";
        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $idUser = (int)$row['id_user'];
            $tipoUser = (int)($row['tipo_user'] ?? 0);
            if ($tipoUser !== 3) {
                continue;
            }

            $cf = $this->normalizeUsableFiscalCode((string)$row['username']);
            if ($cf === '') {
                continue;
            }

            $this->targetPatientUsersByCf[$cf] = [
                'id_user' => $idUser,
                'username' => (string)$row['username'],
            ];
        }
    }

    private function loadTargetLinks(): void
    {
        $sql = "
            SELECT
                id_users_doctor,
                COALESCE(id_client, 0) AS id_client,
                COALESCE(id_dot, 0) AS id_dot
            FROM `{$this->database}`.dap09_client_doctor
            ORDER BY id_users_doctor DESC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $clientId = (int)($row['id_client'] ?? 0);
            $doctorId = (int)($row['id_dot'] ?? 0);
            if ($clientId <= 0) {
                continue;
            }

            $this->targetLinkCountsByClientId[$clientId] = (int)($this->targetLinkCountsByClientId[$clientId] ?? 0) + 1;
            if ($doctorId > 0) {
                $this->targetDoctorIdsByClientId[$clientId][$doctorId] = true;
            }
            if (!isset($this->targetLinksByClientId[$clientId]) && $doctorId > 0) {
                $this->targetLinksByClientId[$clientId] = $doctorId;
            }
        }
    }

    private function loadTargetClients(): void
    {
        $this->hasLegacyPatientBridge = $this->columnExists($this->database, 'dap02_clients', 'legacy_id_paziente');
        $sql = "
            SELECT
                c.id_client,
                COALESCE(c.id_user, 0) AS id_user,
                COALESCE(c.id_personale, 0) AS id_personale,
                COALESCE(c.avviso_mail, 0) AS avviso_mail,
                " . ($this->hasLegacyPatientBridge ? "COALESCE(c.legacy_id_paziente, 0)" : "0") . " AS legacy_id_paziente,
                COALESCE(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR), '') AS nome,
                COALESCE(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR), '') AS cognome,
                COALESCE(CAST(AES_DECRYPT(UNHEX(c.cellulare), @key_str, c.vector_id) AS CHAR), '') AS cellulare,
                COALESCE(CAST(AES_DECRYPT(UNHEX(c.email), @key_str, c.vector_id) AS CHAR), '') AS email,
                COALESCE(CAST(AES_DECRYPT(UNHEX(c.indirizzo), @key_str, c.vector_id) AS CHAR), '') AS indirizzo,
                COALESCE(CAST(AES_DECRYPT(UNHEX(c.citta), @key_str, c.vector_id) AS CHAR), '') AS citta,
                COALESCE(CAST(AES_DECRYPT(UNHEX(c.provincia), @key_str, c.vector_id) AS CHAR), '') AS provincia,
                COALESCE(CAST(AES_DECRYPT(UNHEX(c.codice_fiscale), @key_str, c.vector_id) AS CHAR), '') AS codice_fiscale
            FROM `{$this->database}`.dap02_clients c
            ORDER BY c.id_client ASC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $row['id_client'] = (int)$row['id_client'];
            $row['id_user'] = (int)($row['id_user'] ?? 0);
            $row['id_personale'] = (int)($row['id_personale'] ?? 0);
            $row['avviso_mail'] = (int)($row['avviso_mail'] ?? 0);
            $row['legacy_id_paziente'] = (int)($row['legacy_id_paziente'] ?? 0);
            $this->registerTargetClientRow($row);
        }
    }

    private function registerTargetClientRow(array $row): void
    {
        $clientId = (int)$row['id_client'];
        $this->targetClientsById[$clientId] = $row;

        if ((int)($row['id_user'] ?? 0) > 0 && !isset($this->targetClientByUserId[(int)$row['id_user']])) {
            $this->targetClientByUserId[(int)$row['id_user']] = $clientId;
        }

        $legacyPatientId = (int)($row['legacy_id_paziente'] ?? 0);
        if ($legacyPatientId > 0 && !isset($this->targetClientsByLegacyPatientId[$legacyPatientId])) {
            $this->targetClientsByLegacyPatientId[$legacyPatientId] = $clientId;
        }

        $cf = $this->normalizeUsableFiscalCode((string)($row['codice_fiscale'] ?? ''));
        if ($cf !== '' && !isset($this->targetClientsByCf[$cf])) {
            $this->targetClientsByCf[$cf] = $clientId;
        }

        foreach ($this->getKnownDoctorIdsForClientRow($row) as $doctorId) {
            $triadKey = $this->buildDoctorPatientTriadKey(
                $doctorId,
                (string)($row['cognome'] ?? ''),
                (string)($row['nome'] ?? ''),
                (string)($row['cellulare'] ?? '')
            );
            if ($triadKey !== '' && !isset($this->targetClientsByDoctorTriad[$triadKey])) {
                $this->targetClientsByDoctorTriad[$triadKey] = $clientId;
            }

            $emailKey = $this->buildDoctorPatientEmailKey(
                $doctorId,
                (string)($row['cognome'] ?? ''),
                (string)($row['nome'] ?? ''),
                (string)($row['email'] ?? '')
            );
            if ($emailKey !== '' && !isset($this->targetClientsByDoctorEmail[$emailKey])) {
                $this->targetClientsByDoctorEmail[$emailKey] = $clientId;
            }
        }
    }

    private function loadSourceRows(): void
    {
        $where = [];
        if ($this->doctorFilter !== []) {
            $where[] = 'p.id_dot IN (' . implode(',', array_map('intval', $this->doctorFilter)) . ')';
        }
        if ($this->patientFilter !== []) {
            $where[] = 'p.id_paziente IN (' . implode(',', array_map('intval', $this->patientFilter)) . ')';
        }

        $sql = "
            SELECT
                p.id_paziente,
                COALESCE(p.cognome, '') AS cognome,
                COALESCE(p.nome, '') AS nome,
                COALESCE(p.data_nascita, '') AS data_nascita,
                COALESCE(p.cod_fis, '') AS cod_fis,
                COALESCE(p.indirizzo, '') AS indirizzo,
                COALESCE(p.citta, '') AS citta,
                COALESCE(p.provincia, '') AS provincia,
                COALESCE(p.telefono, '') AS telefono,
                COALESCE(p.cellulare, '') AS cellulare,
                COALESCE(p.email, '') AS email,
                COALESCE(p.id_dot, 0) AS id_dot,
                COALESCE(p.bloccato, 0) AS bloccato,
                COALESCE(p.paz_spec, '') AS paz_spec
            FROM `{$this->sourceDatabase}`.far05_pazienti p
        ";

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY p.id_dot ASC, p.cognome ASC, p.nome ASC, p.id_paziente ASC';

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $row['id_paziente'] = (int)$row['id_paziente'];
            $row['id_dot'] = (int)($row['id_dot'] ?? 0);
            $row['bloccato'] = (int)($row['bloccato'] ?? 0);
            $row['usable_cf'] = $this->normalizeUsableFiscalCode((string)$row['cod_fis']);
            if ($row['usable_cf'] !== '') {
                $legacyDoctorId = (int)$row['id_dot'];
                $this->sourceUsableCfDoctorSets[$row['usable_cf']][$legacyDoctorId > 0 ? $legacyDoctorId : -1] = true;
            }
            $this->sourceRows[] = $row;
        }
    }

    private function audit(): void
    {
        $multiDoctorExamples = [];
        foreach ($this->sourceUsableCfDoctorSets as $cf => $doctorSet) {
            if (count($doctorSet) <= 1) {
                continue;
            }

            $this->sourceMultiDoctorCf[$cf] = array_keys($doctorSet);
            if (count($multiDoctorExamples) < 20) {
                $multiDoctorExamples[] = [
                    'codice_fiscale' => $cf,
                    'legacy_doctors' => array_keys($doctorSet),
                ];
            }
        }

        $multiDoctorSingleActiveExamples = [];
        foreach ($this->sourceMultiDoctorCf as $cf => $legacyDoctors) {
            $resolvedDoctor = $this->resolveSingleActiveTargetDoctorForMultiDoctorCf($cf);
            if ($resolvedDoctor === null) {
                continue;
            }

            if (count($multiDoctorSingleActiveExamples) < 20) {
                $multiDoctorSingleActiveExamples[] = [
                    'codice_fiscale' => $cf,
                    'legacy_doctors' => array_values($legacyDoctors),
                    'resolved_target_id_personale' => (int)$resolvedDoctor['id_personale'],
                    'resolved_target_legacy_id_dot' => (int)$resolvedDoctor['legacy_id_dot'],
                ];
            }
        }

        $unmappedDoctorIds = [];
        foreach ($this->sourceRows as $row) {
            $legacyDoctorId = (int)$row['id_dot'];
            if ($legacyDoctorId > 0 && !isset($this->doctorMapByLegacyId[$legacyDoctorId])) {
                $unmappedDoctorIds[$legacyDoctorId] = $legacyDoctorId;
            }
        }
        ksort($unmappedDoctorIds);

        $duplicateDoctorMapExamples = [];
        foreach ($this->legacyDoctorBuckets as $legacyId => $bucket) {
            if (count($bucket) <= 1) {
                continue;
            }
            if (count($duplicateDoctorMapExamples) >= 20) {
                continue;
            }

            $duplicateDoctorMapExamples[] = [
                'legacy_id_dot' => $legacyId,
                'target_candidates' => array_map(static function (array $row): array {
                    return [
                        'id_personale' => (int)$row['id_personale'],
                        'tipo' => (int)$row['tipo'],
                        'titolare' => (int)$row['titolare'],
                    ];
                }, $bucket),
                'selected_id_personale' => (int)$bucket[0]['id_personale'],
            ];
        }

        $duplicateLinkExamples = [];
        foreach ($this->targetLinkCountsByClientId as $clientId => $count) {
            if ($count <= 1 || count($duplicateLinkExamples) >= 20) {
                continue;
            }

            $duplicateLinkExamples[] = [
                'id_client' => $clientId,
                'relation_count' => $count,
                'selected_id_dot' => (int)($this->targetLinksByClientId[$clientId] ?? 0),
            ];
        }

        $usableCfCount = 0;
        foreach ($this->sourceRows as $row) {
            if ((string)$row['usable_cf'] !== '') {
                $usableCfCount++;
            }
        }

        $targetWithUser = 0;
        $targetWithoutUser = 0;
        $targetWithoutDoctor = 0;
        foreach ($this->targetClientsById as $row) {
            if ((int)($row['id_user'] ?? 0) > 0) {
                $targetWithUser++;
            } else {
                $targetWithoutUser++;
            }

            if ((int)($row['id_personale'] ?? 0) <= 0) {
                $targetWithoutDoctor++;
            }
        }

        $clientsWithoutLink = 0;
        foreach ($this->targetClientsById as $clientId => $row) {
            if (!isset($this->targetLinksByClientId[$clientId])) {
                $clientsWithoutLink++;
            }
        }

        $this->report['audit'] = [
            'source_far05_total' => count($this->sourceRows),
            'source_far05_with_usable_cf' => $usableCfCount,
            'source_far05_without_usable_cf' => count($this->sourceRows) - $usableCfCount,
            'source_far05_distinct_usable_cf' => count($this->sourceUsableCfDoctorSets),
            'source_far05_multi_doctor_usable_cf' => count($this->sourceMultiDoctorCf),
            'source_far05_multi_doctor_single_active_resolvable' => count(array_filter(
                $this->sourceMultiDoctorCf,
                fn(string $cf): bool => $this->resolveSingleActiveTargetDoctorForMultiDoctorCf($cf) !== null,
                ARRAY_FILTER_USE_KEY
            )),
            'source_far05_without_legacy_doctor' => count(array_filter(
                $this->sourceRows,
                static fn(array $row): bool => (int)($row['id_dot'] ?? 0) <= 0
            )),
            'target_dap01_patient_users' => count($this->targetPatientUsersByCf),
            'target_dap02_total' => count($this->targetClientsById),
            'target_dap02_with_user' => $targetWithUser,
            'target_dap02_without_user' => $targetWithoutUser,
            'target_dap09_total' => array_sum($this->targetLinkCountsByClientId),
            'target_clients_without_link' => $clientsWithoutLink,
            'target_clients_without_doctor' => $targetWithoutDoctor,
            'mapped_legacy_doctors' => count($this->doctorMapByLegacyId),
            'unmapped_legacy_doctors' => count($unmappedDoctorIds),
            'duplicate_legacy_doctor_mappings' => count(array_filter(
                $this->legacyDoctorBuckets,
                static fn(array $bucket): bool => count($bucket) > 1
            )),
            'duplicate_target_link_groups' => count(array_filter(
                $this->targetLinkCountsByClientId,
                static fn(int $count): bool => $count > 1
            )),
            'multi_doctor_cf_examples' => $multiDoctorExamples,
            'multi_doctor_single_active_examples' => $multiDoctorSingleActiveExamples,
            'unmapped_legacy_doctor_examples' => array_slice(array_values($unmappedDoctorIds), 0, 30),
            'duplicate_legacy_doctor_mapping_examples' => $duplicateDoctorMapExamples,
            'duplicate_target_link_examples' => $duplicateLinkExamples,
        ];
    }

    private function migrate(): void
    {
        $phase = [
            'source_rows_considered' => 0,
            'source_rows_collapsed' => 0,
            'skipped_no_doctor_map' => 0,
            'skipped_multi_doctor_cf_conflicts' => 0,
            'skipped_user_conflicts' => 0,
            'skipped_doctor_conflicts' => 0,
            'multi_doctor_cf_auto_resolved' => 0,
            'matched_legacy_patient_id' => 0,
            'matched_user_cf' => 0,
            'matched_client_cf' => 0,
            'matched_doctor_triad' => 0,
            'matched_doctor_email' => 0,
            'clients_inserted_with_user' => 0,
            'clients_inserted_without_user' => 0,
            'clients_enriched' => 0,
            'clients_user_linked' => 0,
            'links_inserted' => 0,
            'links_already_aligned' => 0,
            'collapsed_examples' => [],
            'insert_examples' => [],
            'enrich_examples' => [],
            'multi_doctor_auto_resolve_examples' => [],
            'multi_doctor_conflict_examples' => [],
            'doctor_conflict_examples' => [],
            'user_conflict_examples' => [],
            'no_doctor_examples' => [],
        ];

        $seenSourceKeys = [];

        foreach ($this->sourceRows as $index => $row) {
            $phase['source_rows_considered']++;
            if ($phase['source_rows_considered'] % 5000 === 0) {
                $this->logger->info('Progresso sync pazienti', [
                    'processed' => $phase['source_rows_considered'],
                    'inserted' => $phase['clients_inserted_with_user'] + $phase['clients_inserted_without_user'],
                    'enriched' => $phase['clients_enriched'],
                    'links_inserted' => $phase['links_inserted'],
                ]);
            }

            $legacyDoctorId = (int)($row['id_dot'] ?? 0);
            $usableCf = (string)($row['usable_cf'] ?? '');
            $targetDoctor = $this->doctorMapByLegacyId[$legacyDoctorId] ?? null;
            $resolvedMultiDoctorTarget = null;
            if ($usableCf !== '' && isset($this->sourceMultiDoctorCf[$usableCf])) {
                $resolvedMultiDoctorTarget = $this->resolveSingleActiveTargetDoctorForMultiDoctorCf($usableCf);
                if ($resolvedMultiDoctorTarget !== null) {
                    $targetDoctor = $resolvedMultiDoctorTarget;
                }
            }
            if ($this->onlyResolvableMultiDoctorCf && $resolvedMultiDoctorTarget === null) {
                continue;
            }

            if ($legacyDoctorId <= 0 || $targetDoctor === null) {
                $phase['skipped_no_doctor_map']++;
                $this->pushExample($phase['no_doctor_examples'], [
                    'id_paziente' => (int)$row['id_paziente'],
                    'legacy_id_dot' => $legacyDoctorId,
                    'cognome' => (string)$row['cognome'],
                    'nome' => (string)$row['nome'],
                    'codice_fiscale' => (string)$row['usable_cf'],
                ]);
                continue;
            }

            $targetDoctorId = (int)$targetDoctor['id_personale'];
            if ($resolvedMultiDoctorTarget !== null) {
                $phase['multi_doctor_cf_auto_resolved']++;
                $this->pushExample($phase['multi_doctor_auto_resolve_examples'], [
                    'id_paziente' => (int)$row['id_paziente'],
                    'codice_fiscale' => $usableCf,
                    'legacy_id_dot' => $legacyDoctorId,
                    'legacy_doctors_for_cf' => $this->sourceMultiDoctorCf[$usableCf],
                    'resolved_target_id_personale' => $targetDoctorId,
                    'resolved_target_legacy_id_dot' => (int)$resolvedMultiDoctorTarget['legacy_id_dot'],
                ]);
            }

            $sourceKey = $this->buildSourceLogicalKey($row, $targetDoctorId);
            if ($sourceKey !== '' && isset($seenSourceKeys[$sourceKey])) {
                $phase['source_rows_collapsed']++;
                $this->pushExample($phase['collapsed_examples'], [
                    'id_paziente' => (int)$row['id_paziente'],
                    'legacy_id_dot' => $legacyDoctorId,
                    'target_id_personale' => $targetDoctorId,
                    'dedupe_key' => $sourceKey,
                ]);
                continue;
            }
            if ($sourceKey !== '') {
                $seenSourceKeys[$sourceKey] = true;
            }

            $patientUser = $usableCf !== '' ? ($this->targetPatientUsersByCf[$usableCf] ?? null) : null;

            if ($usableCf !== '' && isset($this->sourceMultiDoctorCf[$usableCf]) && $resolvedMultiDoctorTarget === null) {
                $existingClientId = 0;
                if ($patientUser !== null && isset($this->targetClientByUserId[(int)$patientUser['id_user']])) {
                    $existingClientId = (int)$this->targetClientByUserId[(int)$patientUser['id_user']];
                } elseif (isset($this->targetClientsByCf[$usableCf])) {
                    $existingClientId = (int)$this->targetClientsByCf[$usableCf];
                }

                $existingClient = $existingClientId > 0
                    ? ($this->targetClientsById[$existingClientId] ?? ['id_personale' => 0])
                    : ['id_personale' => 0];

                if ($existingClientId <= 0 || !$this->canClientBeLinkedToDoctor($existingClientId, $existingClient, $targetDoctorId)) {
                    $phase['skipped_multi_doctor_cf_conflicts']++;
                    $this->pushExample($phase['multi_doctor_conflict_examples'], [
                        'id_paziente' => (int)$row['id_paziente'],
                        'codice_fiscale' => $usableCf,
                        'legacy_id_dot' => $legacyDoctorId,
                        'target_id_personale' => $targetDoctorId,
                        'legacy_doctors_for_cf' => $this->sourceMultiDoctorCf[$usableCf],
                    ]);
                    continue;
                }
            }

            [$clientId, $strategy] = $this->resolveExistingClientId($row, $targetDoctorId, $patientUser);
            if ($clientId > 0) {
                if ($strategy === 'user_cf') {
                    $phase['matched_user_cf']++;
                } elseif ($strategy === 'legacy_patient_id') {
                    $phase['matched_legacy_patient_id']++;
                } elseif ($strategy === 'client_cf') {
                    $phase['matched_client_cf']++;
                } elseif ($strategy === 'doctor_triad') {
                    $phase['matched_doctor_triad']++;
                } elseif ($strategy === 'doctor_email') {
                    $phase['matched_doctor_email']++;
                }

                $existing = $this->targetClientsById[$clientId];
                if ($patientUser !== null && (int)($existing['id_user'] ?? 0) > 0 && (int)$existing['id_user'] !== (int)$patientUser['id_user']) {
                    $phase['skipped_user_conflicts']++;
                    $this->pushExample($phase['user_conflict_examples'], [
                        'id_client' => $clientId,
                        'id_paziente' => (int)$row['id_paziente'],
                        'codice_fiscale' => $usableCf,
                        'target_id_user' => (int)$existing['id_user'],
                        'expected_id_user' => (int)$patientUser['id_user'],
                    ]);
                    continue;
                }

                $patch = $this->buildClientPatch($existing, $row, $targetDoctorId, $patientUser);
                if ($patch['has_changes']) {
                    if ($this->apply) {
                        $this->updateExistingClient($clientId, $patch);
                    }

                    if (!empty($patch['id_user'])) {
                        $phase['clients_user_linked']++;
                    }
                    $phase['clients_enriched']++;
                    $this->pushExample($phase['enrich_examples'], [
                        'id_client' => $clientId,
                        'id_paziente' => (int)$row['id_paziente'],
                        'strategy' => $strategy,
                        'fields' => $patch['changed_fields'],
                    ]);

                    $updated = $existing;
                    if (!empty($patch['id_user'])) {
                        $updated['id_user'] = (int)$patch['id_user'];
                    }
                    if (!empty($patch['id_personale'])) {
                        $updated['id_personale'] = (int)$patch['id_personale'];
                    }
                    if (!empty($patch['legacy_id_paziente'])) {
                        $updated['legacy_id_paziente'] = (int)$patch['legacy_id_paziente'];
                    }
                    foreach ($patch['encrypted'] as $field => $value) {
                        $updated[$field] = $value;
                    }
                    $this->registerTargetClientRow($updated);
                    $existing = $updated;
                }

                $linkResult = $this->ensureDoctorLink($clientId, $existing, $targetDoctorId);
                if ($linkResult === 'inserted') {
                    $phase['links_inserted']++;
                } elseif ($linkResult === 'aligned') {
                    $phase['links_already_aligned']++;
                } elseif ($linkResult === 'conflict') {
                    $phase['skipped_doctor_conflicts']++;
                    $this->pushExample($phase['doctor_conflict_examples'], [
                        'id_client' => $clientId,
                        'id_paziente' => (int)$row['id_paziente'],
                        'legacy_id_dot' => $legacyDoctorId,
                        'target_id_personale' => $targetDoctorId,
                        'current_doctor' => $this->getCurrentDoctorForClient($clientId),
                    ]);
                }

                continue;
            }

            $plain = $this->buildPlainClientData($row);
            $idUser = $patientUser !== null ? (int)$patientUser['id_user'] : null;

            if ($this->apply) {
                $clientId = $this->insertClient($plain, $idUser, $targetDoctorId);
                $insertedRow = [
                    'id_client' => $clientId,
                    'id_user' => $idUser ?? 0,
                    'id_personale' => $targetDoctorId,
                    'avviso_mail' => 0,
                    'legacy_id_paziente' => (int)($row['id_paziente'] ?? 0),
                    'nome' => $plain['nome'],
                    'cognome' => $plain['cognome'],
                    'cellulare' => $plain['cellulare'],
                    'email' => $plain['email'],
                    'indirizzo' => $plain['indirizzo'],
                    'citta' => $plain['citta'],
                    'provincia' => $plain['provincia'],
                    'codice_fiscale' => $plain['codice_fiscale'],
                ];
                $this->registerTargetClientRow($insertedRow);
                $this->insertDoctorLink($clientId, $targetDoctorId);
            } else {
                $clientId = $this->dryRunClientId--;
            }

            if ($idUser !== null && $idUser > 0) {
                $phase['clients_inserted_with_user']++;
            } else {
                $phase['clients_inserted_without_user']++;
            }
            $phase['links_inserted']++;
            $this->pushExample($phase['insert_examples'], [
                'id_client' => $clientId,
                'id_paziente' => (int)$row['id_paziente'],
                'legacy_id_dot' => $legacyDoctorId,
                'target_id_personale' => $targetDoctorId,
                'codice_fiscale' => $plain['codice_fiscale'],
                'id_user' => $idUser,
            ]);
        }

        $this->report['migration'] = $phase;
    }

    private function resolveExistingClientId(array $row, int $targetDoctorId, ?array $patientUser): array
    {
        $legacyPatientId = (int)($row['id_paziente'] ?? 0);
        if ($legacyPatientId > 0 && isset($this->targetClientsByLegacyPatientId[$legacyPatientId])) {
            return [(int)$this->targetClientsByLegacyPatientId[$legacyPatientId], 'legacy_patient_id'];
        }

        if ($patientUser !== null) {
            $userId = (int)$patientUser['id_user'];
            if (isset($this->targetClientByUserId[$userId])) {
                return [(int)$this->targetClientByUserId[$userId], 'user_cf'];
            }
        }

        $usableCf = (string)($row['usable_cf'] ?? '');
        if ($usableCf !== '' && isset($this->targetClientsByCf[$usableCf])) {
            return [(int)$this->targetClientsByCf[$usableCf], 'client_cf'];
        }

        $triadKey = $this->buildDoctorPatientTriadKey(
            $targetDoctorId,
            (string)$row['cognome'],
            (string)$row['nome'],
            (string)$row['cellulare']
        );
        if ($triadKey !== '' && isset($this->targetClientsByDoctorTriad[$triadKey])) {
            return [(int)$this->targetClientsByDoctorTriad[$triadKey], 'doctor_triad'];
        }

        $emailKey = $this->buildDoctorPatientEmailKey(
            $targetDoctorId,
            (string)$row['cognome'],
            (string)$row['nome'],
            (string)$row['email']
        );
        if ($emailKey !== '' && isset($this->targetClientsByDoctorEmail[$emailKey])) {
            return [(int)$this->targetClientsByDoctorEmail[$emailKey], 'doctor_email'];
        }

        return [0, 'new'];
    }

    private function buildClientPatch(array $existing, array $source, int $targetDoctorId, ?array $patientUser): array
    {
        $encrypted = [];
        $changedFields = [];

        $sourcePlain = $this->buildPlainClientData($source);
        foreach (['nome', 'cognome', 'cellulare', 'email', 'indirizzo', 'citta', 'provincia'] as $field) {
            $existingValue = trim((string)($existing[$field] ?? ''));
            $newValue = trim((string)($sourcePlain[$field] ?? ''));
            if ($existingValue === '' && $newValue !== '') {
                $encrypted[$field] = $newValue;
                $changedFields[] = $field;
            }
        }

        $usableCf = (string)($source['usable_cf'] ?? '');
        if (
            $usableCf !== ''
            && trim((string)($existing['codice_fiscale'] ?? '')) === ''
            && (!isset($this->targetClientsByCf[$usableCf]) || (int)$this->targetClientsByCf[$usableCf] === (int)$existing['id_client'])
        ) {
            $encrypted['codice_fiscale'] = $usableCf;
            $changedFields[] = 'codice_fiscale';
        }

        $idUser = null;
        if ($patientUser !== null && (int)($existing['id_user'] ?? 0) <= 0) {
            $idUser = (int)$patientUser['id_user'];
            $changedFields[] = 'id_user';
        }

        $idPersonale = null;
        $currentPrimaryDoctorId = (int)($existing['id_personale'] ?? 0);
        if ($targetDoctorId > 0) {
            if ($currentPrimaryDoctorId <= 0) {
                $idPersonale = $targetDoctorId;
                $changedFields[] = 'id_personale';
            } elseif ($this->isPrimaryFamilyDoctor($targetDoctorId) && !$this->isPrimaryFamilyDoctor($currentPrimaryDoctorId)) {
                $idPersonale = $targetDoctorId;
                $changedFields[] = 'id_personale';
            }
        }

        $legacyPatientId = null;
        $sourceLegacyPatientId = (int)($source['id_paziente'] ?? 0);
        if ($this->hasLegacyPatientBridge && $sourceLegacyPatientId > 0 && (int)($existing['legacy_id_paziente'] ?? 0) <= 0) {
            $legacyPatientId = $sourceLegacyPatientId;
            $changedFields[] = 'legacy_id_paziente';
        }

        return [
            'id_user' => $idUser,
            'id_personale' => $idPersonale,
            'legacy_id_paziente' => $legacyPatientId,
            'encrypted' => $encrypted,
            'changed_fields' => $changedFields,
            'has_changes' => $idUser !== null || $idPersonale !== null || $legacyPatientId !== null || $encrypted !== [],
        ];
    }

    private function ensureDoctorLink(int $clientId, array $existing, int $targetDoctorId): string
    {
        if ($this->clientHasDoctorLink($clientId, $targetDoctorId)) {
            return 'aligned';
        }

        if (!$this->canClientBeLinkedToDoctor($clientId, $existing, $targetDoctorId)) {
            return 'conflict';
        }

        if ($this->apply) {
            $this->insertDoctorLink($clientId, $targetDoctorId);
        } else {
            $this->targetLinksByClientId[$clientId] = $targetDoctorId;
            $this->targetDoctorIdsByClientId[$clientId][$targetDoctorId] = true;
            $this->targetLinkCountsByClientId[$clientId] = (int)($this->targetLinkCountsByClientId[$clientId] ?? 0) + 1;
        }

        return 'inserted';
    }

    private function updateExistingClient(int $clientId, array $patch): void
    {
        $set = [];
        if (!empty($patch['id_user'])) {
            $set[] = 'id_user=' . (int)$patch['id_user'];
        }
        if (!empty($patch['id_personale'])) {
            $set[] = 'id_personale=' . (int)$patch['id_personale'];
        }
        if (!empty($patch['legacy_id_paziente'])) {
            $set[] = 'legacy_id_paziente=' . (int)$patch['legacy_id_paziente'];
        }

        if ($patch['encrypted'] !== []) {
            $this->db->query('SET @sync_vector = RANDOM_BYTES(16)');
            $set[] = 'vector_id = COALESCE(vector_id, @sync_vector)';
            foreach ($patch['encrypted'] as $field => $value) {
                $set[] = $field . '=' . $this->encryptSqlWithVectorFallback((string)$value, 'COALESCE(vector_id, @sync_vector)');
            }
        }

        if ($set === []) {
            return;
        }

        $sql = "UPDATE `{$this->database}`.dap02_clients SET " . implode(', ', $set)
            . " WHERE id_client=" . (int)$clientId . " LIMIT 1";
        $this->db->query($sql);
    }

    private function insertClient(array $plain, ?int $idUser, int $targetDoctorId): int
    {
        $this->db->query('SET @init_vector = RANDOM_BYTES(16)');
        $idUserSql = $idUser !== null && $idUser > 0 ? (string)$idUser : 'NULL';
        $legacyPatientId = (int)($plain['legacy_id_paziente'] ?? 0);

        $columns = [
            'id_user',
            'nome',
            'cognome',
            'cellulare',
            'email',
            'indirizzo',
            'citta',
            'provincia',
            'codice_fiscale',
            'id_personale',
            'avviso_mail',
            'vector_id',
        ];

        $values = [
            $idUserSql,
            $this->encryptSql((string)$plain['nome']),
            $this->encryptSql((string)$plain['cognome']),
            $this->encryptSql((string)$plain['cellulare']),
            $this->encryptSql((string)$plain['email']),
            $this->encryptSql((string)$plain['indirizzo']),
            $this->encryptSql((string)$plain['citta']),
            $this->encryptSql((string)$plain['provincia']),
            $this->encryptSql((string)$plain['codice_fiscale']),
            (string)$targetDoctorId,
            '0',
            '@init_vector',
        ];

        if ($this->hasLegacyPatientBridge) {
            $columns[] = 'legacy_id_paziente';
            $values[] = $legacyPatientId > 0 ? (string)$legacyPatientId : 'NULL';
        }

        $sql = "
            INSERT INTO `{$this->database}`.dap02_clients
            (" . implode(', ', $columns) . ")
            VALUES
            (" . implode(', ', $values) . ")
        ";

        $this->db->query($sql);
        return (int)$this->db->insert_id;
    }

    private function insertDoctorLink(int $clientId, int $targetDoctorId): void
    {
        $sql = "
            INSERT INTO `{$this->database}`.dap09_client_doctor (id_client, id_dot)
            VALUES (" . (int)$clientId . ", " . (int)$targetDoctorId . ")
        ";
        $this->db->query($sql);
        $this->targetLinksByClientId[$clientId] = $targetDoctorId;
        $this->targetDoctorIdsByClientId[$clientId][$targetDoctorId] = true;
        $this->targetLinkCountsByClientId[$clientId] = (int)($this->targetLinkCountsByClientId[$clientId] ?? 0) + 1;
    }

    private function buildPlainClientData(array $row): array
    {
        return [
            'nome' => trim((string)($row['nome'] ?? '')),
            'cognome' => trim((string)($row['cognome'] ?? '')),
            'cellulare' => trim((string)($row['cellulare'] ?? '')),
            'email' => trim((string)($row['email'] ?? '')),
            'indirizzo' => trim((string)($row['indirizzo'] ?? '')),
            'citta' => trim((string)($row['citta'] ?? '')),
            'provincia' => trim((string)($row['provincia'] ?? '')),
            'codice_fiscale' => (string)($row['usable_cf'] ?? ''),
            'legacy_id_paziente' => (int)($row['id_paziente'] ?? 0),
        ];
    }

    private function getCurrentDoctorForClient(int $clientId): int
    {
        $fieldDoctor = (int)($this->targetClientsById[$clientId]['id_personale'] ?? 0);
        if ($fieldDoctor > 0) {
            return $fieldDoctor;
        }

        return (int)($this->targetLinksByClientId[$clientId] ?? 0);
    }

    private function getKnownDoctorIdsForClientRow(array $row): array
    {
        $clientId = (int)($row['id_client'] ?? 0);
        $doctorIds = [];

        if ((int)($row['id_personale'] ?? 0) > 0) {
            $doctorIds[] = (int)$row['id_personale'];
        }

        if ($clientId > 0 && isset($this->targetDoctorIdsByClientId[$clientId])) {
            foreach (array_keys($this->targetDoctorIdsByClientId[$clientId]) as $doctorId) {
                $doctorId = (int)$doctorId;
                if ($doctorId > 0 && !in_array($doctorId, $doctorIds, true)) {
                    $doctorIds[] = $doctorId;
                }
            }
        }

        return $doctorIds;
    }

    private function clientHasDoctorLink(int $clientId, int $targetDoctorId): bool
    {
        return $clientId > 0
            && $targetDoctorId > 0
            && !empty($this->targetDoctorIdsByClientId[$clientId][$targetDoctorId]);
    }

    private function canClientBeLinkedToDoctor(int $clientId, array $existing, int $targetDoctorId): bool
    {
        if ($clientId <= 0 || $targetDoctorId <= 0) {
            return false;
        }

        if ($this->clientHasDoctorLink($clientId, $targetDoctorId)) {
            return true;
        }

        if (!$this->isPrimaryFamilyDoctor($targetDoctorId)) {
            return true;
        }

        $currentFamilyDoctorId = $this->getCurrentFamilyDoctorForClient($clientId, $existing);
        return $currentFamilyDoctorId <= 0 || $currentFamilyDoctorId === $targetDoctorId;
    }

    private function getCurrentFamilyDoctorForClient(int $clientId, array $existing): int
    {
        foreach ($this->getKnownDoctorIdsForClientRow(['id_client' => $clientId, 'id_personale' => (int)($existing['id_personale'] ?? 0)]) as $doctorId) {
            if ($this->isPrimaryFamilyDoctor($doctorId)) {
                return $doctorId;
            }
        }

        return 0;
    }

    private function isPrimaryFamilyDoctor(int $doctorId): bool
    {
        if ($doctorId <= 0) {
            return false;
        }

        $doctor = $this->doctorByPersonaleId[$doctorId] ?? null;
        if ($doctor === null) {
            return false;
        }

        $legacyDotTypeId = (int)($doctor['legacy_dot_tipo_id'] ?? 0);
        if ($legacyDotTypeId > 0) {
            return $legacyDotTypeId === 1;
        }

        return (int)($doctor['f_dom'] ?? 0) === 1;
    }

    private function resolveSingleActiveTargetDoctorForMultiDoctorCf(string $usableCf): ?array
    {
        if ($usableCf === '' || !isset($this->sourceMultiDoctorCf[$usableCf])) {
            return null;
        }

        if (array_key_exists($usableCf, $this->resolvedMultiDoctorCfTargetByCf)) {
            return $this->resolvedMultiDoctorCfTargetByCf[$usableCf];
        }

        $activeTargets = [];
        foreach ($this->sourceMultiDoctorCf[$usableCf] as $legacyDoctorId) {
            $doctor = $this->doctorMapByLegacyId[(int)$legacyDoctorId] ?? null;
            if ($doctor === null || !$this->isActiveTargetDoctor($doctor)) {
                continue;
            }

            $activeTargets[(int)$doctor['id_personale']] = $doctor;
        }

        $resolved = count($activeTargets) === 1 ? array_values($activeTargets)[0] : null;
        $this->resolvedMultiDoctorCfTargetByCf[$usableCf] = $resolved;
        return $resolved;
    }

    private function isActiveTargetDoctor(array $doctor): bool
    {
        return (int)($doctor['tipo'] ?? 0) === 1
            && (int)($doctor['sostituto'] ?? 0) === 0
            && (int)($doctor['is_active'] ?? 1) === 1;
    }

    private function encryptSql(string $value): string
    {
        $escaped = $this->db->real_escape_string($value);
        return "HEX(AES_ENCRYPT('{$escaped}', @key_str, @init_vector))";
    }

    private function encryptSqlWithVectorFallback(string $value, string $vectorExpr): string
    {
        $escaped = $this->db->real_escape_string($value);
        return "HEX(AES_ENCRYPT('{$escaped}', @key_str, {$vectorExpr}))";
    }

    private function columnExists(string $schema, string $table, string $column): bool
    {
        $schemaEsc = $this->db->real_escape_string($schema);
        $tableEsc = $this->db->real_escape_string($table);
        $columnEsc = $this->db->real_escape_string($column);
        $sql = "
            SELECT COUNT(*) AS c
            FROM information_schema.columns
            WHERE table_schema = '{$schemaEsc}'
              AND table_name = '{$tableEsc}'
              AND column_name = '{$columnEsc}'
        ";

        $row = $this->db->query($sql)->fetch_assoc();
        return (int)($row['c'] ?? 0) > 0;
    }

    private function normalizeUsableFiscalCode(string $value): string
    {
        $value = $this->normalizeCode($value);
        $len = strlen($value);
        if ($len === 11 && preg_match('/^\d{11}$/', $value)) {
            return $value;
        }

        if ($len === 16 && preg_match('/^[A-Z0-9]{16}$/', $value) && preg_match('/[A-Z]/', $value)) {
            if (preg_match('/^(.)\1{15}$/', $value)) {
                return '';
            }
            return $value;
        }

        return '';
    }

    private function normalizeCode(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
        return $value;
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

    private function normalizeEmail(string $value): string
    {
        return strtolower(trim($value));
    }

    private function normalizeDateKey(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/\s+/', '', $value) ?? $value;
        return $value;
    }

    private function normalizeNamePair(string $cognome, string $nome): string
    {
        $cognome = $this->normalizeText($cognome);
        $nome = $this->normalizeText($nome);
        $joined = trim($cognome . '|' . $nome, '|');
        return $joined === '|' ? '' : $joined;
    }

    private function buildDoctorPatientTriadKey(int $doctorId, string $cognome, string $nome, string $cellulare): string
    {
        $nameKey = $this->normalizeNamePair($cognome, $nome);
        $cell = $this->normalizePhone($cellulare);
        if ($doctorId <= 0 || $nameKey === '' || $cell === '') {
            return '';
        }

        return $doctorId . '|' . $nameKey . '|' . $cell;
    }

    private function buildDoctorPatientEmailKey(int $doctorId, string $cognome, string $nome, string $email): string
    {
        $nameKey = $this->normalizeNamePair($cognome, $nome);
        $emailKey = $this->normalizeEmail($email);
        if ($doctorId <= 0 || $nameKey === '' || $emailKey === '' || !str_contains($emailKey, '@')) {
            return '';
        }

        return $doctorId . '|' . $nameKey . '|' . $emailKey;
    }

    private function buildDoctorPatientBirthKey(int $doctorId, string $cognome, string $nome, string $dataNascita): string
    {
        $nameKey = $this->normalizeNamePair($cognome, $nome);
        $birthKey = $this->normalizeDateKey($dataNascita);
        if ($doctorId <= 0 || $nameKey === '' || $birthKey === '') {
            return '';
        }

        return $doctorId . '|' . $nameKey . '|' . $birthKey;
    }

    private function buildSourceLogicalKey(array $row, int $targetDoctorId): string
    {
        $usableCf = (string)($row['usable_cf'] ?? '');
        if ($usableCf !== '' && $targetDoctorId > 0) {
            return 'cf|' . $targetDoctorId . '|' . $usableCf;
        }

        $triadKey = $this->buildDoctorPatientTriadKey(
            $targetDoctorId,
            (string)$row['cognome'],
            (string)$row['nome'],
            (string)$row['cellulare']
        );
        if ($triadKey !== '') {
            return 'triad|' . $triadKey;
        }

        $emailKey = $this->buildDoctorPatientEmailKey(
            $targetDoctorId,
            (string)$row['cognome'],
            (string)$row['nome'],
            (string)$row['email']
        );
        if ($emailKey !== '') {
            return 'email|' . $emailKey;
        }

        $birthKey = $this->buildDoctorPatientBirthKey(
            $targetDoctorId,
            (string)$row['cognome'],
            (string)$row['nome'],
            (string)$row['data_nascita']
        );
        if ($birthKey !== '') {
            return 'birth|' . $birthKey;
        }

        return 'row|' . (int)$row['id_paziente'];
    }

    private function pushExample(array &$bucket, array $row, int $limit = 25): void
    {
        if (count($bucket) >= $limit) {
            return;
        }

        $bucket[] = $row;
    }

    private function getSummaryTextPath(): string
    {
        return preg_replace('/\.json$/i', '.txt', $this->reportPath) ?: ($this->reportPath . '.txt');
    }

    private function getSummaryHtmlPath(): string
    {
        return preg_replace('/\.json$/i', '.html', $this->reportPath) ?: ($this->reportPath . '.html');
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
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        file_put_contents($this->reportPath, $json . PHP_EOL);
        file_put_contents($this->getSummaryTextPath(), $this->buildTextSummary());
        file_put_contents($this->getSummaryHtmlPath(), $this->buildHtmlSummary());
    }

    private function buildTextSummary(): string
    {
        $audit = $this->report['audit'] ?? [];
        $migration = $this->report['migration'] ?? [];

        $lines = [];
        $lines[] = 'Sync far05_pazienti -> dap02_clients / dap09_client_doctor';
        $lines[] = '======================================================';
        $lines[] = 'Stato: ' . (string)($this->report['status'] ?? 'n/d');
        $lines[] = 'Modalita: ' . (string)($this->report['mode'] ?? 'n/d');
        $lines[] = 'Database: ' . (string)($this->report['database'] ?? 'n/d');
        $lines[] = 'Inizio: ' . (string)($this->report['started_at'] ?? 'n/d');
        $lines[] = 'Fine: ' . (string)($this->report['finished_at'] ?? 'n/d');
        $lines[] = 'Log: ' . (string)($this->report['log_path'] ?? '');
        $lines[] = 'JSON tecnico: ' . (string)($this->report['report_path'] ?? '');
        $lines[] = 'Summary HTML: ' . (string)($this->report['summary_html_path'] ?? '');
        $lines[] = '';
        $lines[] = 'Filtri';
        $lines[] = 'Dottori legacy: ' . $this->formatListForSummary(($this->report['filters']['doctors'] ?? []));
        $lines[] = 'Pazienti legacy: ' . $this->formatListForSummary(($this->report['filters']['patients'] ?? []));
        $lines[] = '';
        $lines[] = 'Audit';
        $lines = array_merge($lines, $this->buildMetricLines($audit, [
            'source_far05_total' => 'Totale far05',
            'source_far05_with_usable_cf' => 'far05 con CF usabile',
            'source_far05_without_usable_cf' => 'far05 senza CF usabile',
            'source_far05_distinct_usable_cf' => 'CF usabili distinti in far05',
            'source_far05_multi_doctor_usable_cf' => 'CF usabili con piu dottori',
            'source_far05_multi_doctor_single_active_resolvable' => 'CF multi-dottore con un solo dottore attivo nel nuovo',
            'target_dap01_patient_users' => 'Utenti pazienti gia presenti in dap01',
            'target_dap02_total' => 'Clienti gia presenti in dap02',
            'target_dap02_without_user' => 'Clienti dap02 senza id_user',
            'target_clients_without_link' => 'Clienti dap02 senza link dottore',
            'mapped_legacy_doctors' => 'Legacy id_dot mappati su dap03',
            'unmapped_legacy_doctors' => 'Legacy id_dot non mappati',
        ]));
        $lines[] = '';
        $lines[] = 'Migrazione';
        $lines = array_merge($lines, $this->buildMetricLines($migration, [
            'source_rows_considered' => 'Righe sorgente considerate',
            'source_rows_collapsed' => 'Duplicati sorgente collassati',
            'skipped_no_doctor_map' => 'Saltati senza dottore mappato',
            'skipped_multi_doctor_cf_conflicts' => 'Saltati per CF multi-dottore',
            'multi_doctor_cf_auto_resolved' => 'CF multi-dottore risolti verso unico dottore attivo',
            'skipped_user_conflicts' => 'Saltati per conflitto id_user',
            'skipped_doctor_conflicts' => 'Saltati per conflitto dottore target',
            'matched_user_cf' => 'Match via account dap01',
            'matched_client_cf' => 'Match via CF gia presente in dap02',
            'matched_doctor_triad' => 'Match via medico + nominativo + cellulare',
            'matched_doctor_email' => 'Match via medico + nominativo + email',
            'clients_inserted_with_user' => 'Nuovi client con account',
            'clients_inserted_without_user' => 'Nuovi client senza account',
            'clients_enriched' => 'Client esistenti arricchiti',
            'clients_user_linked' => 'Client esistenti agganciati a id_user',
            'links_inserted' => 'Link dottore inseriti',
            'links_already_aligned' => 'Link dottore gia allineati',
        ]));

        $lines[] = '';
        $lines = array_merge($lines, $this->buildExampleLines('Esempi insert', $migration['insert_examples'] ?? []));
        $lines[] = '';
        $lines = array_merge($lines, $this->buildExampleLines('Esempi enrich', $migration['enrich_examples'] ?? []));
        $lines[] = '';
        $lines = array_merge($lines, $this->buildExampleLines('CF multi-dottore auto-risolti', $migration['multi_doctor_auto_resolve_examples'] ?? []));
        $lines[] = '';
        $lines = array_merge($lines, $this->buildExampleLines('Conflitti CF multi-dottore', $migration['multi_doctor_conflict_examples'] ?? []));
        $lines[] = '';
        $lines = array_merge($lines, $this->buildExampleLines('Conflitti dottore target', $migration['doctor_conflict_examples'] ?? []));
        $lines[] = '';
        $lines = array_merge($lines, $this->buildExampleLines('Legacy id_dot non mappati', $migration['no_doctor_examples'] ?? []));

        if (($this->report['status'] ?? '') === 'error') {
            $error = $this->report['error'] ?? [];
            $lines[] = '';
            $lines[] = 'Errore';
            $lines[] = 'Messaggio: ' . (string)($error['message'] ?? 'n/d');
            $lines[] = 'File: ' . (string)($error['file'] ?? 'n/d');
            $lines[] = 'Linea: ' . (string)($error['line'] ?? 'n/d');
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function buildHtmlSummary(): string
    {
        $audit = $this->report['audit'] ?? [];
        $migration = $this->report['migration'] ?? [];

        $html = '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Summary sync far05 -> dap02</title>'
            . '<style>body{font-family:Arial,sans-serif;margin:24px;background:#f6f7f9;color:#1f2937;}'
            . '.box{background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:16px;margin-bottom:18px;max-width:1100px;}'
            . 'table{border-collapse:collapse;width:100%;}th,td{border:1px solid #d1d5db;padding:8px 10px;text-align:left;vertical-align:top;}'
            . 'th{background:#eef2ff;}code{background:#eef2ff;padding:2px 6px;border-radius:4px;}ul{margin:0;padding-left:20px;}'
            . 'h1,h2{margin-top:0;}</style></head><body>';

        $html .= '<div class="box"><h1>Sync far05_pazienti -> dap02_clients / dap09_client_doctor</h1>'
            . '<p><strong>Stato:</strong> ' . $this->html((string)($this->report['status'] ?? 'n/d')) . '</p>'
            . '<p><strong>Modalita:</strong> ' . $this->html((string)($this->report['mode'] ?? 'n/d')) . '</p>'
            . '<p><strong>Database:</strong> ' . $this->html((string)($this->report['database'] ?? 'n/d')) . '</p>'
            . '<p><strong>Inizio:</strong> ' . $this->html((string)($this->report['started_at'] ?? 'n/d')) . '<br>'
            . '<strong>Fine:</strong> ' . $this->html((string)($this->report['finished_at'] ?? 'n/d')) . '</p>'
            . '<p><strong>Log:</strong> <code>' . $this->html((string)($this->report['log_path'] ?? '')) . '</code><br>'
            . '<strong>JSON tecnico:</strong> <code>' . $this->html((string)($this->report['report_path'] ?? '')) . '</code></p>'
            . '</div>';

        $html .= '<div class="box"><h2>Audit</h2><table><tbody>'
            . $this->buildHtmlMetricRows($audit, [
                'source_far05_total' => 'Totale far05',
                'source_far05_with_usable_cf' => 'far05 con CF usabile',
                'source_far05_without_usable_cf' => 'far05 senza CF usabile',
                'source_far05_distinct_usable_cf' => 'CF usabili distinti in far05',
                'source_far05_multi_doctor_usable_cf' => 'CF usabili con piu dottori',
                'source_far05_multi_doctor_single_active_resolvable' => 'CF multi-dottore con un solo dottore attivo nel nuovo',
                'target_dap01_patient_users' => 'Utenti pazienti gia presenti in dap01',
                'target_dap02_total' => 'Clienti gia presenti in dap02',
                'target_dap02_without_user' => 'Clienti dap02 senza id_user',
                'target_clients_without_link' => 'Clienti dap02 senza link dottore',
                'mapped_legacy_doctors' => 'Legacy id_dot mappati su dap03',
                'unmapped_legacy_doctors' => 'Legacy id_dot non mappati',
            ])
            . '</tbody></table></div>';

        $html .= '<div class="box"><h2>Migrazione</h2><table><tbody>'
            . $this->buildHtmlMetricRows($migration, [
                'source_rows_considered' => 'Righe sorgente considerate',
                'source_rows_collapsed' => 'Duplicati sorgente collassati',
                'skipped_no_doctor_map' => 'Saltati senza dottore mappato',
                'skipped_multi_doctor_cf_conflicts' => 'Saltati per CF multi-dottore',
                'multi_doctor_cf_auto_resolved' => 'CF multi-dottore risolti verso unico dottore attivo',
                'skipped_user_conflicts' => 'Saltati per conflitto id_user',
                'skipped_doctor_conflicts' => 'Saltati per conflitto dottore target',
                'matched_user_cf' => 'Match via account dap01',
                'matched_client_cf' => 'Match via CF gia presente in dap02',
                'matched_doctor_triad' => 'Match via medico + nominativo + cellulare',
                'matched_doctor_email' => 'Match via medico + nominativo + email',
                'clients_inserted_with_user' => 'Nuovi client con account',
                'clients_inserted_without_user' => 'Nuovi client senza account',
                'clients_enriched' => 'Client esistenti arricchiti',
                'clients_user_linked' => 'Client esistenti agganciati a id_user',
                'links_inserted' => 'Link dottore inseriti',
                'links_already_aligned' => 'Link dottore gia allineati',
            ])
            . '</tbody></table></div>';

        $html .= $this->buildHtmlExampleSection('Esempi insert', $migration['insert_examples'] ?? []);
        $html .= $this->buildHtmlExampleSection('Esempi enrich', $migration['enrich_examples'] ?? []);
        $html .= $this->buildHtmlExampleSection('CF multi-dottore auto-risolti', $migration['multi_doctor_auto_resolve_examples'] ?? []);
        $html .= $this->buildHtmlExampleSection('Conflitti CF multi-dottore', $migration['multi_doctor_conflict_examples'] ?? []);
        $html .= $this->buildHtmlExampleSection('Conflitti dottore target', $migration['doctor_conflict_examples'] ?? []);
        $html .= $this->buildHtmlExampleSection('Legacy id_dot non mappati', $migration['no_doctor_examples'] ?? []);

        if (($this->report['status'] ?? '') === 'error') {
            $error = $this->report['error'] ?? [];
            $html .= '<div class="box"><h2>Errore</h2>'
                . '<p><strong>Messaggio:</strong> ' . $this->html((string)($error['message'] ?? 'n/d')) . '</p>'
                . '<p><strong>File:</strong> ' . $this->html((string)($error['file'] ?? 'n/d')) . '<br>'
                . '<strong>Linea:</strong> ' . $this->html((string)($error['line'] ?? 'n/d')) . '</p></div>';
        }

        $html .= '</body></html>';
        return $html;
    }

    private function buildMetricLines(array $data, array $labels): array
    {
        $lines = [];
        foreach ($labels as $key => $label) {
            $lines[] = '- ' . $label . ': ' . (string)($data[$key] ?? 0);
        }

        return $lines;
    }

    private function buildExampleLines(string $title, array $rows): array
    {
        $lines = [$title];
        if ($rows === []) {
            $lines[] = '- Nessun elemento';
            return $lines;
        }

        foreach ($rows as $row) {
            $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $lines[] = '- ' . ($json !== false ? $json : '[json-error]');
        }

        return $lines;
    }

    private function buildHtmlMetricRows(array $data, array $labels): string
    {
        $rows = '';
        foreach ($labels as $key => $label) {
            $rows .= '<tr><th>' . $this->html($label) . '</th><td>' . $this->html((string)($data[$key] ?? 0)) . '</td></tr>';
        }

        return $rows;
    }

    private function buildHtmlExampleSection(string $title, array $rows): string
    {
        $html = '<div class="box"><h2>' . $this->html($title) . '</h2>';
        if ($rows === []) {
            $html .= '<p>Nessun elemento</p></div>';
            return $html;
        }

        $html .= '<ul>';
        foreach ($rows as $row) {
            $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $html .= '<li><code>' . $this->html($json !== false ? $json : '[json-error]') . '</code></li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    private function formatListForSummary(array $items): string
    {
        if ($items === []) {
            return 'tutti';
        }

        return implode(', ', array_map('strval', $items));
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
