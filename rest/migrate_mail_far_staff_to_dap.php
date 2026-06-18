<?php
declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');
ignore_user_abort(true);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const DEFAULT_DB = 'mail';
const DEFAULT_REPORT_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'dap_staff_sync';
const DEFAULT_PASSWORD_EXPIRY_FALLBACK = '2031-02-08 19:44:09';
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
    $env = loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');
    $dbConfig = buildDbConfig($env, $options);

    ensureDirectory((string)$options['report_dir']);

    $stamp = date('Ymd_His');
    $logPath = (string)$options['report_dir'] . DIRECTORY_SEPARATOR . 'dap_staff_sync_' . $stamp . '.log';
    $reportPath = (string)$options['report_dir'] . DIRECTORY_SEPARATOR . 'dap_staff_sync_' . $stamp . '.json';

    $logger = new CliLogger($logPath);
    $logger->info('Avvio sync far01_ope -> dap01_users/dap03_personale', [
        'mode' => !empty($options['apply']) ? 'apply' : 'dry-run',
        'db' => $dbConfig['database'],
        'operator_filter' => $options['operators'],
        'role_filter' => $options['roles'],
    ]);

    $script = new FarStaffToDapSync($dbConfig, $options, $logger, $reportPath);
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

    $argv = ['migrate_mail_far_staff_to_dap.php'];
    if (!empty($request['apply']) && (string)$request['apply'] === '1') {
        $argv[] = '--apply';
    }

    $map = [
        'host',
        'port',
        'user',
        'pass',
        'db',
        'operators',
        'roles',
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
    $self = $_SERVER['PHP_SELF'] ?? 'migrate_mail_far_staff_to_dap.php';
    $base = $self !== '' ? $self : 'migrate_mail_far_staff_to_dap.php';
    $html = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Sync far01_ope -> dap*</title>
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
        <h1>Sync far01_ope -> dap01_users / dap03_personale</h1>
        <p>Questo e il primo step di migrazione staff. Legge <code>mail.far01_ope</code> e porta gli utenti mancanti in <code>mail.dap01_users</code> e <code>mail.dap03_personale</code>.</p>
        <p>I log e i report vengono salvati in <code>writable/dap_staff_sync</code>.</p>
    </div>

    <div class="box">
        <h2>Dry-run</h2>
        <form method="get" action="{$base}">
            <input type="hidden" name="run" value="1">
            <div class="grid">
                <div>
                    <label for="operators">operators</label>
                    <input id="operators" type="text" name="operators" value="" placeholder="es. 1,2,3">
                </div>
                <div>
                    <label for="roles">roles</label>
                    <input id="roles" type="text" name="roles" value="" placeholder="es. 1,2,3,5">
                </div>
                <div>
                    <label for="db">db</label>
                    <input id="db" type="text" name="db" value="mail">
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
                    <label for="operators-apply">operators</label>
                    <input id="operators-apply" type="text" name="operators" value="" placeholder="es. 1,2,3">
                </div>
                <div>
                    <label for="roles-apply">roles</label>
                    <input id="roles-apply" type="text" name="roles" value="" placeholder="es. 1,2,3,5">
                </div>
                <div>
                    <label for="db-apply">db</label>
                    <input id="db-apply" type="text" name="db" value="mail">
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
        'host' => optionValue($argv, 'host'),
        'port' => (int)(optionValue($argv, 'port') ?: 3306),
        'user' => optionValue($argv, 'user'),
        'pass' => optionValue($argv, 'pass'),
        'db' => optionValue($argv, 'db') ?: DEFAULT_DB,
        'operators' => parseCsvInts(optionValue($argv, 'operators')),
        'roles' => parseCsvInts(optionValue($argv, 'roles')),
        'report_dir' => optionValue($argv, 'report-dir') ?: DEFAULT_REPORT_DIR,
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

final class FarStaffToDapSync
{
    private mysqli $db;
    private array $dbConfig;
    private array $options;
    private CliLogger $logger;
    private string $reportPath;
    private string $database;
    private bool $apply;
    /** @var int[] */
    private array $operatorFilter = [];
    /** @var int[] */
    private array $roleFilter = [];
    private array $report = [];
    private array $sourceRoleLabels = [];
    private array $sourceRows = [];
    private array $targetUsersByUsername = [];
    private array $targetUsersById = [];
    private array $targetUserBucketsByUsername = [];
    private array $targetPersonaleById = [];
    private array $targetPersonaleByUserId = [];
    private array $targetPersonaleByLegacyDot = [];
    private array $targetPersonaleByLegacyOpe = [];
    private array $targetPersonaleColumns = [];
    private array $personaleFieldLimits = [
        'nome' => 255,
        'cognome' => 255,
        'qualifica' => 255,
        'email' => 2500,
        'cellulare' => 255,
    ];
    private int $dryRunUserId = -1;
    private int $dryRunPersonaleId = -1;

    public function __construct(array $dbConfig, array $options, CliLogger $logger, string $reportPath)
    {
        $this->dbConfig = $dbConfig;
        $this->options = $options;
        $this->logger = $logger;
        $this->reportPath = $reportPath;
        $this->database = (string)$dbConfig['database'];
        $this->apply = !empty($options['apply']);
        $this->operatorFilter = $options['operators'];
        $this->roleFilter = $options['roles'];
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

            $this->report = [
                'started_at' => date('c'),
                'mode' => $this->apply ? 'apply' : 'dry-run',
                'database' => $this->database,
                'log_path' => $this->logger->path(),
                'report_path' => $this->reportPath,
                'summary_text_path' => $this->getSummaryTextPath(),
                'summary_html_path' => $this->getSummaryHtmlPath(),
                'filters' => [
                    'operators' => $this->operatorFilter,
                    'roles' => $this->roleFilter,
                ],
                'assumptions' => [
                    'dap01_tipo_user_personale' => 2,
                    'password_strategy' => 'Il valore legacy di far01_ope.password viene trasportato come stringa e cifrato in dap01_users.password',
                    'doctor_defaults' => [
                        'titolare' => 1,
                        'sostituto' => 0,
                        'luogo' => 0,
                    ],
                ],
                'compatibility' => [
                    'field_limits' => $this->personaleFieldLimits,
                    'fallback_examples' => [],
                    'overflow_examples' => [],
                ],
                'audit' => [],
                'migration' => [],
            ];

            $this->loadRoleLabels();
            $this->loadTargetUsers();
            $this->loadTargetPersonale();
            $this->selectCanonicalTargetUsers();
            $this->loadSourceRows();
            $this->audit();
            $this->migrate();

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
        $this->db->query("SET block_encryption_mode = 'aes-256-cbc'");

        $key = $this->db->real_escape_string((string)$this->dbConfig['encryption_key']);
        $this->db->query("SET @key_str = SHA2('{$key}', 512)");
        $this->db->query("SET @init_vector = RANDOM_BYTES(16)");
    }

    private function loadRoleLabels(): void
    {
        $sql = "SELECT id_ruo, COALESCE(des_ruo, '') AS des_ruo FROM `{$this->database}`.far02_ruo";
        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $this->sourceRoleLabels[(int)$row['id_ruo']] = (string)$row['des_ruo'];
        }
    }

    private function loadTargetUsers(): void
    {
        $sql = "SELECT id_user, username, datascadenza, tipo_user, is_active FROM `{$this->database}`.dap01_users";
        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $row['id_user'] = (int)$row['id_user'];
            $row['tipo_user'] = (int)($row['tipo_user'] ?? 0);
            $row['is_active'] = (int)($row['is_active'] ?? 0);
            $row['username_norm'] = $this->normalizeUsername((string)$row['username']);
            $this->targetUsersById[(int)$row['id_user']] = $row;
            if ($row['username_norm'] !== '') {
                $this->targetUserBucketsByUsername[$row['username_norm']][] = $row;
            }
        }
    }

    private function loadTargetPersonale(): void
    {
        $this->inspectTargetPersonaleColumns();

        $sql = "
            SELECT
                id_personale,
                id_user,
                tipo,
                luogo,
                titolare,
                sostituto,
                is_dot,
                COALESCE(CAST(AES_DECRYPT(UNHEX(nome), @key_str, vector_id) AS CHAR), '') AS nome_plain,
                COALESCE(CAST(AES_DECRYPT(UNHEX(cognome), @key_str, vector_id) AS CHAR), '') AS cognome_plain,
                COALESCE(CAST(AES_DECRYPT(UNHEX(qualifica), @key_str, vector_id) AS CHAR), '') AS qualifica_plain,
                COALESCE(CAST(AES_DECRYPT(UNHEX(email), @key_str, vector_id) AS CHAR), '') AS email_plain,
                COALESCE(CAST(AES_DECRYPT(UNHEX(cellulare), @key_str, vector_id) AS CHAR), '') AS cellulare_plain," .
                ($this->targetPersonaleHasColumn('legacy_id_ope')
                    ? " COALESCE(legacy_id_ope, 0) AS legacy_id_ope,"
                    : " 0 AS legacy_id_ope,") .
                ($this->targetPersonaleHasColumn('legacy_id_dot')
                    ? " COALESCE(legacy_id_dot, 0) AS legacy_id_dot,"
                    : " 0 AS legacy_id_dot,") .
                ($this->targetPersonaleHasColumn('f_dom')
                    ? " COALESCE(f_dom, 0) AS f_dom,"
                    : " 0 AS f_dom,") .
                ($this->targetPersonaleHasColumn('legacy_dot_tipo_id')
                    ? " COALESCE(legacy_dot_tipo_id, 0) AS legacy_dot_tipo_id"
                    : " 0 AS legacy_dot_tipo_id") . "
            FROM `{$this->database}`.dap03_personale
        ";
        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $row['id_personale'] = (int)$row['id_personale'];
            $row['id_user'] = (int)($row['id_user'] ?? 0);
            $row['tipo'] = (int)($row['tipo'] ?? 0);
            $row['luogo'] = (int)($row['luogo'] ?? 0);
            $row['titolare'] = (int)($row['titolare'] ?? 0);
            $row['sostituto'] = (int)($row['sostituto'] ?? 0);
            $row['is_dot'] = (int)($row['is_dot'] ?? 0);
            $row['legacy_id_ope'] = (int)($row['legacy_id_ope'] ?? 0);
            $row['legacy_id_dot'] = (int)($row['legacy_id_dot'] ?? 0);
            $row['f_dom'] = (int)($row['f_dom'] ?? 0);
            $row['legacy_dot_tipo_id'] = (int)($row['legacy_dot_tipo_id'] ?? 0);
            $row['nome_plain'] = (string)($row['nome_plain'] ?? '');
            $row['cognome_plain'] = (string)($row['cognome_plain'] ?? '');
            $row['qualifica_plain'] = (string)($row['qualifica_plain'] ?? '');
            $row['email_plain'] = (string)($row['email_plain'] ?? '');
            $row['cellulare_plain'] = (string)($row['cellulare_plain'] ?? '');
            $this->registerTargetPersonaleRow($row);
        }
    }

    private function inspectTargetPersonaleColumns(): void
    {
        if ($this->targetPersonaleColumns !== []) {
            return;
        }

        $res = $this->db->query("SHOW COLUMNS FROM `{$this->database}`.dap03_personale");
        while ($row = $res->fetch_assoc()) {
            $name = strtolower((string)($row['Field'] ?? ''));
            if ($name !== '') {
                $this->targetPersonaleColumns[$name] = true;
            }
        }
    }

    private function targetPersonaleHasColumn(string $column): bool
    {
        $this->inspectTargetPersonaleColumns();
        return isset($this->targetPersonaleColumns[strtolower($column)]);
    }

    private function registerTargetPersonaleRow(array $row): void
    {
        $idPersonale = (int)($row['id_personale'] ?? 0);
        $idUser = (int)($row['id_user'] ?? 0);
        $legacyDot = (int)($row['legacy_id_dot'] ?? 0);
        $legacyOpe = (int)($row['legacy_id_ope'] ?? 0);

        if ($idPersonale !== 0) {
            $this->targetPersonaleById[$idPersonale] = $row;
        }
        if ($idUser > 0) {
            $this->targetPersonaleByUserId[$idUser] = $row;
        }
        if ($legacyDot > 0) {
            $this->targetPersonaleByLegacyDot[$legacyDot] = $row;
        }
        if ($legacyOpe > 0) {
            $this->targetPersonaleByLegacyOpe[$legacyOpe] = $row;
        }
    }

    private function findTargetPersonaleForSourceRow(array $row, ?array $user): ?array
    {
        if ($user !== null) {
            $idUser = (int)($user['id_user'] ?? 0);
            if ($idUser > 0 && isset($this->targetPersonaleByUserId[$idUser])) {
                return $this->targetPersonaleByUserId[$idUser];
            }
        }

        $legacyDot = (int)($row['id_dot'] ?? 0);
        if ($legacyDot > 0 && isset($this->targetPersonaleByLegacyDot[$legacyDot])) {
            return $this->targetPersonaleByLegacyDot[$legacyDot];
        }

        $legacyOpe = (int)($row['id_ope'] ?? 0);
        if ($legacyOpe > 0 && isset($this->targetPersonaleByLegacyOpe[$legacyOpe])) {
            return $this->targetPersonaleByLegacyOpe[$legacyOpe];
        }

        return null;
    }

    private function selectCanonicalTargetUsers(): void
    {
        $this->targetUsersByUsername = [];
        foreach ($this->targetUserBucketsByUsername as $usernameNorm => $rows) {
            usort($rows, function (array $left, array $right): int {
                $leftHasPersonale = isset($this->targetPersonaleByUserId[(int)$left['id_user']]) ? 1 : 0;
                $rightHasPersonale = isset($this->targetPersonaleByUserId[(int)$right['id_user']]) ? 1 : 0;
                if ($leftHasPersonale !== $rightHasPersonale) {
                    return $rightHasPersonale <=> $leftHasPersonale;
                }

                return (int)$left['id_user'] <=> (int)$right['id_user'];
            });

            $this->targetUsersByUsername[$usernameNorm] = $rows[0];
        }
    }

    private function loadSourceRows(): void
    {
        $where = [];
        if ($this->operatorFilter !== []) {
            $where[] = 'o.id_ope IN (' . implode(',', array_map('intval', $this->operatorFilter)) . ')';
        }
        if ($this->roleFilter !== []) {
            $where[] = 'o.id_ruo IN (' . implode(',', array_map('intval', $this->roleFilter)) . ')';
        }

        $sql = "
            SELECT
                o.id_ope,
                COALESCE(o.nome, '') AS ope_nome,
                COALESCE(o.cognome, '') AS ope_cognome,
                COALESCE(o.user, '') AS username,
                COALESCE(o.password, '') AS password_legacy,
                o.id_ruo,
                COALESCE(o.email, '') AS ope_email,
                o.data_scad_ute,
                o.data_scad_pass,
                d.id_dot,
                COALESCE(d.nome, '') AS dot_nome,
                COALESCE(d.cognome, '') AS dot_cognome,
                COALESCE(d.titolo, '') AS dot_titolo,
                d.tipo AS dot_tipo_id,
                COALESCE(d.telefono, '') AS dot_telefono,
                COALESCE(d.email, '') AS dot_email,
                COALESCE(d.f_dom, 0) AS dot_f_dom,
                COALESCE(t.tipo_des, '') AS dot_tipo_des
            FROM `{$this->database}`.far01_ope o
            LEFT JOIN `{$this->database}`.far03_dot d
              ON d.id_ope = o.id_ope
            LEFT JOIN `{$this->database}`.far04_tipo_dottore t
              ON t.id_tipo = d.tipo
        ";

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY o.id_ruo ASC, o.user ASC, o.id_ope ASC';

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $row['id_ope'] = (int)$row['id_ope'];
            $row['id_ruo'] = (int)($row['id_ruo'] ?? 0);
            $row['id_dot'] = isset($row['id_dot']) ? (int)$row['id_dot'] : 0;
            $row['dot_tipo_id'] = isset($row['dot_tipo_id']) ? (int)$row['dot_tipo_id'] : 0;
            $row['dot_f_dom'] = (int)($row['dot_f_dom'] ?? 0);
            $row['username_norm'] = $this->normalizeUsername((string)$row['username']);
            $this->sourceRows[] = $row;
        }

        $this->appendOrphanDoctorRows();
    }

    private function appendOrphanDoctorRows(): void
    {
        if ($this->roleFilter !== [] && !in_array(3, $this->roleFilter, true)) {
            return;
        }

        $where = ['o.id_ope IS NULL'];
        if ($this->operatorFilter !== []) {
            $where[] = 'd.id_ope IN (' . implode(',', array_map('intval', $this->operatorFilter)) . ')';
        }

        $sql = "
            SELECT
                COALESCE(d.id_ope, 0) AS id_ope,
                COALESCE(d.nome, '') AS ope_nome,
                COALESCE(d.cognome, '') AS ope_cognome,
                '' AS username,
                '' AS password_legacy,
                3 AS id_ruo,
                COALESCE(d.email, '') AS ope_email,
                NULL AS data_scad_ute,
                NULL AS data_scad_pass,
                d.id_dot,
                COALESCE(d.nome, '') AS dot_nome,
                COALESCE(d.cognome, '') AS dot_cognome,
                COALESCE(d.titolo, '') AS dot_titolo,
                d.tipo AS dot_tipo_id,
                COALESCE(d.telefono, '') AS dot_telefono,
                COALESCE(d.email, '') AS dot_email,
                COALESCE(d.f_dom, 0) AS dot_f_dom,
                COALESCE(t.tipo_des, '') AS dot_tipo_des
            FROM `{$this->database}`.far03_dot d
            LEFT JOIN `{$this->database}`.far01_ope o
              ON o.id_ope = d.id_ope
            LEFT JOIN `{$this->database}`.far04_tipo_dottore t
              ON t.id_tipo = d.tipo
            WHERE " . implode(' AND ', $where) . "
            ORDER BY d.id_dot ASC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $row['id_ope'] = (int)($row['id_ope'] ?? 0);
            $row['id_ruo'] = 3;
            $row['id_dot'] = (int)($row['id_dot'] ?? 0);
            $row['dot_tipo_id'] = (int)($row['dot_tipo_id'] ?? 0);
            $row['dot_f_dom'] = (int)($row['dot_f_dom'] ?? 0);
            $row['username_norm'] = '';
            $this->sourceRows[] = $row;
        }
    }

    private function audit(): void
    {
        $audit = [
            'source_far01_total' => count($this->sourceRows),
            'source_far01_with_username' => 0,
            'target_dap01_total' => count($this->targetUsersById),
            'target_dap03_total' => count($this->targetPersonaleById),
            'duplicate_dap01_usernames' => 0,
            'missing_dap01' => 0,
            'missing_dap03' => 0,
            'unsupported_roles' => 0,
            'doctor_rows' => 0,
            'doctor_rows_without_far03' => 0,
            'role_breakdown' => [],
            'duplicate_dap01_examples' => [],
            'missing_dap01_examples' => [],
            'missing_dap03_examples' => [],
            'doctor_rows_without_far03_examples' => [],
            'unsupported_role_examples' => [],
        ];

        foreach ($this->targetUserBucketsByUsername as $usernameNorm => $rows) {
            if (count($rows) <= 1) {
                continue;
            }

            $audit['duplicate_dap01_usernames']++;
            if (count($audit['duplicate_dap01_examples']) < 20) {
                $audit['duplicate_dap01_examples'][] = [
                    'username' => $rows[0]['username'],
                    'candidates' => array_map(function (array $user): array {
                        return [
                            'id_user' => (int)$user['id_user'],
                            'tipo_user' => (int)$user['tipo_user'],
                            'has_personale' => isset($this->targetPersonaleByUserId[(int)$user['id_user']]),
                        ];
                    }, $rows),
                    'canonical_id_user' => (int)$this->targetUsersByUsername[$usernameNorm]['id_user'],
                ];
            }
        }

        foreach ($this->sourceRows as $row) {
            $roleId = (int)$row['id_ruo'];
            $roleLabel = $this->sourceRoleLabels[$roleId] ?? ('Ruolo #' . $roleId);
            if (!isset($audit['role_breakdown'][$roleId])) {
                $audit['role_breakdown'][$roleId] = [
                    'id_ruo' => $roleId,
                    'des_ruo' => $roleLabel,
                    'rows' => 0,
                    'missing_dap01' => 0,
                    'missing_dap03' => 0,
                ];
            }
            $audit['role_breakdown'][$roleId]['rows']++;

            if ($row['username_norm'] !== '') {
                $audit['source_far01_with_username']++;
            }

            $mappedTipo = $this->mapFarRoleToDapTipo($roleId);
            if ($mappedTipo === null) {
                $audit['unsupported_roles']++;
                if (count($audit['unsupported_role_examples']) < 20) {
                    $audit['unsupported_role_examples'][] = [
                        'id_ope' => (int)$row['id_ope'],
                        'username' => (string)$row['username'],
                        'id_ruo' => $roleId,
                        'des_ruo' => $roleLabel,
                    ];
                }
                continue;
            }

            if ($mappedTipo === 1) {
                $audit['doctor_rows']++;
                if ((int)$row['id_dot'] <= 0) {
                    $audit['doctor_rows_without_far03']++;
                    if (count($audit['doctor_rows_without_far03_examples']) < 20) {
                        $audit['doctor_rows_without_far03_examples'][] = $this->buildExampleRow($row, null);
                    }
                }
            }

            if ($row['username_norm'] === '') {
                if ($mappedTipo === 1 && (int)($row['id_dot'] ?? 0) > 0) {
                    $personale = $this->findTargetPersonaleForSourceRow($row, null);
                    if ($personale === null) {
                        $audit['missing_dap03']++;
                        $audit['role_breakdown'][$roleId]['missing_dap03']++;
                        if (count($audit['missing_dap03_examples']) < 20) {
                            $audit['missing_dap03_examples'][] = $this->buildExampleRow($row, null);
                        }
                    }
                }
                continue;
            }

            $user = $this->targetUsersByUsername[$row['username_norm']] ?? null;
            if ($user === null) {
                $audit['missing_dap01']++;
                $audit['role_breakdown'][$roleId]['missing_dap01']++;
                if (count($audit['missing_dap01_examples']) < 40) {
                    $audit['missing_dap01_examples'][] = $this->buildExampleRow($row, null);
                }
                continue;
            }

            $personale = $this->findTargetPersonaleForSourceRow($row, $user);
            if ($personale === null) {
                $audit['missing_dap03']++;
                $audit['role_breakdown'][$roleId]['missing_dap03']++;
                if (count($audit['missing_dap03_examples']) < 20) {
                    $audit['missing_dap03_examples'][] = $this->buildExampleRow($row, $user);
                }
            }
        }

        ksort($audit['role_breakdown']);
        $audit['role_breakdown'] = array_values($audit['role_breakdown']);

        $this->report['audit'] = $audit;
        $this->logger->info('Audit completato', [
            'source_far01_total' => $audit['source_far01_total'],
            'duplicate_dap01_usernames' => $audit['duplicate_dap01_usernames'],
            'missing_dap01' => $audit['missing_dap01'],
            'missing_dap03' => $audit['missing_dap03'],
            'unsupported_roles' => $audit['unsupported_roles'],
            'doctor_rows_without_far03' => $audit['doctor_rows_without_far03'],
        ]);
    }

    private function migrate(): void
    {
        $phase = [
            'users_inserted' => 0,
            'users_skipped_existing' => 0,
            'users_skipped_blank_username' => 0,
            'personale_inserted' => 0,
            'personale_updated' => 0,
            'personale_already_aligned' => 0,
            'rows_skipped_unsupported_role' => 0,
            'examples_users_inserted' => [],
            'examples_personale_inserted' => [],
            'examples_personale_updated' => [],
        ];

        if ($this->apply) {
            $this->db->begin_transaction();
        }

        foreach ($this->sourceRows as $row) {
            $mappedTipo = $this->mapFarRoleToDapTipo((int)$row['id_ruo']);
            if ($mappedTipo === null) {
                $phase['rows_skipped_unsupported_role']++;
                continue;
            }

            if ($row['username_norm'] === '' && (int)($row['id_dot'] ?? 0) <= 0) {
                $phase['users_skipped_blank_username']++;
                continue;
            }

            $user = null;
            if ($row['username_norm'] === '') {
                $phase['users_skipped_blank_username']++;
            } else {
                $user = $this->targetUsersByUsername[$row['username_norm']] ?? null;
                if ($user === null) {
                    $userId = $this->insertDapUser($row);
                    $user = $this->targetUsersById[$userId];
                    $phase['users_inserted']++;
                    if (count($phase['examples_users_inserted']) < 30) {
                        $phase['examples_users_inserted'][] = $this->buildExampleRow($row, $user);
                    }
                } else {
                    $phase['users_skipped_existing']++;
                }
            }

            $personale = $this->findTargetPersonaleForSourceRow($row, $user);
            $personalePayload = $this->buildPersonalePayload($row, $user ? (int)$user['id_user'] : null, $mappedTipo);
            $this->validatePersonalePayload($personalePayload);

            if ($personale !== null) {
                $sync = $this->syncExistingDapPersonale($personale, $personalePayload);
                if ($sync['changed']) {
                    $phase['personale_updated']++;
                    if (count($phase['examples_personale_updated']) < 30) {
                        $phase['examples_personale_updated'][] = [
                            'id_user' => (int)$user['id_user'],
                            'username' => (string)$row['username'],
                            'changes' => $sync['changes'],
                        ];
                    }
                } else {
                    $phase['personale_already_aligned']++;
                }
                continue;
            }

            $this->insertDapPersonale($personalePayload);
            $phase['personale_inserted']++;
            if (count($phase['examples_personale_inserted']) < 30) {
                $phase['examples_personale_inserted'][] = $personalePayload;
            }
        }

        if ($this->apply) {
            $this->db->commit();
        }

        $phase['status'] = $this->apply ? 'apply_done' : 'dry_run_done';
        $this->report['migration'] = $phase;
        $this->logger->info('Migrazione completata', [
            'users_inserted' => $phase['users_inserted'],
            'personale_inserted' => $phase['personale_inserted'],
            'personale_updated' => $phase['personale_updated'],
            'rows_skipped_unsupported_role' => $phase['rows_skipped_unsupported_role'],
        ]);
    }

    private function insertDapUser(array $row): int
    {
        $datascadenza = $this->resolveDataScadenza($row);

        if (!$this->apply) {
            $userId = $this->dryRunUserId--;
            $this->registerTargetUser([
                'id_user' => $userId,
                'username' => (string)$row['username'],
                'datascadenza' => $datascadenza,
                'tipo_user' => 2,
                'is_active' => 1,
            ]);
            return $userId;
        }

        $this->db->query('SET @init_vector = RANDOM_BYTES(16)');
        $stmt = $this->db->prepare("
            INSERT INTO `{$this->database}`.dap01_users
            (username, password, datascadenza, tipo_user, privacy, is_active, vector_id)
            VALUES (?, HEX(AES_ENCRYPT(?, @key_str, @init_vector)), ?, 2, 0, 1, @init_vector)
        ");
        $passwordLegacy = (string)$row['password_legacy'];
        $username = (string)$row['username'];
        $stmt->bind_param('sss', $username, $passwordLegacy, $datascadenza);
        $stmt->execute();
        $stmt->close();

        $userId = (int)$this->db->insert_id;
        $this->registerTargetUser([
            'id_user' => $userId,
            'username' => $username,
            'datascadenza' => $datascadenza,
            'tipo_user' => 2,
            'is_active' => 1,
        ]);
        return $userId;
    }

    private function insertDapPersonale(array $payload): void
    {
        if (!$this->apply) {
            $this->registerTargetPersonaleRow([
                'id_personale' => $this->dryRunPersonaleId--,
                'id_user' => (int)($payload['id_user'] ?? 0),
                'tipo' => (int)$payload['tipo'],
                'luogo' => (int)$payload['luogo'],
                'titolare' => (int)$payload['titolare'],
                'sostituto' => (int)$payload['sostituto'],
                'is_dot' => (int)$payload['is_dot'],
                'legacy_id_ope' => (int)$payload['legacy_id_ope'],
                'legacy_id_dot' => (int)$payload['legacy_id_dot'],
                'f_dom' => (int)$payload['f_dom'],
                'legacy_dot_tipo_id' => (int)($payload['legacy_dot_tipo_id'] ?? 0),
                'nome_plain' => (string)$payload['nome'],
                'cognome_plain' => (string)$payload['cognome'],
                'qualifica_plain' => (string)$payload['qualifica'],
                'email_plain' => (string)$payload['email'],
                'cellulare_plain' => (string)$payload['cellulare'],
            ]);
            return;
        }

        $this->db->query('SET @init_vector = RANDOM_BYTES(16)');
        if (isset($payload['id_user']) && $payload['id_user'] !== null) {
            $stmt = $this->db->prepare("
                INSERT INTO `{$this->database}`.dap03_personale
                (id_user, nome, cognome, qualifica, tipo, email, cellulare, vector_id, is_dot, sostituto, titolare, luogo, is_active)
                VALUES
                (
                    ?,
                    HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                    HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                    HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                    ?,
                    HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                    HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                    @init_vector,
                    ?, ?, ?, ?, 1
                )
            ");

            $stmt->bind_param(
                'isssissiiii',
                $payload['id_user'],
                $payload['nome'],
                $payload['cognome'],
                $payload['qualifica'],
                $payload['tipo'],
                $payload['email'],
                $payload['cellulare'],
                $payload['is_dot'],
                $payload['sostituto'],
                $payload['titolare'],
                $payload['luogo']
            );
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO `{$this->database}`.dap03_personale
                (id_user, nome, cognome, qualifica, tipo, email, cellulare, vector_id, is_dot, sostituto, titolare, luogo, is_active)
                VALUES
                (
                    NULL,
                    HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                    HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                    HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                    ?,
                    HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                    HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                    @init_vector,
                    ?, ?, ?, ?, 1
                )
            ");

            $stmt->bind_param(
                'sssissiiii',
                $payload['nome'],
                $payload['cognome'],
                $payload['qualifica'],
                $payload['tipo'],
                $payload['email'],
                $payload['cellulare'],
                $payload['is_dot'],
                $payload['sostituto'],
                $payload['titolare'],
                $payload['luogo']
            );
        }
        $stmt->execute();
        $personaleId = (int)$this->db->insert_id;
        $stmt->close();

        $this->syncTargetPersonaleBridgeColumnsByPersonaleId($personaleId, $payload);

        $this->registerTargetPersonaleRow([
            'id_personale' => $personaleId,
            'id_user' => (int)($payload['id_user'] ?? 0),
            'tipo' => (int)$payload['tipo'],
            'luogo' => (int)$payload['luogo'],
            'titolare' => (int)$payload['titolare'],
            'sostituto' => (int)$payload['sostituto'],
            'is_dot' => (int)$payload['is_dot'],
            'legacy_id_ope' => (int)$payload['legacy_id_ope'],
            'legacy_id_dot' => (int)$payload['legacy_id_dot'],
            'f_dom' => (int)$payload['f_dom'],
            'legacy_dot_tipo_id' => (int)($payload['legacy_dot_tipo_id'] ?? 0),
            'nome_plain' => (string)$payload['nome'],
            'cognome_plain' => (string)$payload['cognome'],
            'qualifica_plain' => (string)$payload['qualifica'],
            'email_plain' => (string)$payload['email'],
            'cellulare_plain' => (string)$payload['cellulare'],
        ]);
    }

    private function syncExistingDapPersonale(array $existing, array $payload): array
    {
        $changes = [];
        $encryptedFieldChanges = [];

        $currentUserId = (int)($existing['id_user'] ?? 0);
        $targetUserId = (int)($payload['id_user'] ?? 0);
        if ($targetUserId > 0 && $currentUserId !== $targetUserId) {
            $changes['id_user'] = $targetUserId;
        }

        foreach (['tipo', 'luogo', 'titolare', 'sostituto', 'is_dot'] as $field) {
            $current = (int)($existing[$field] ?? 0);
            $target = (int)($payload[$field] ?? 0);
            if ($current !== $target) {
                $changes[$field] = $target;
            }
        }

        foreach (['legacy_id_ope', 'legacy_id_dot', 'f_dom', 'legacy_dot_tipo_id'] as $field) {
            if (!$this->targetPersonaleHasColumn($field)) {
                continue;
            }

            $current = (int)($existing[$field] ?? 0);
            $target = (int)($payload[$field] ?? 0);
            if ($current !== $target) {
                $changes[$field] = $target;
            }
        }

        foreach (['nome', 'cognome', 'qualifica', 'email', 'cellulare'] as $field) {
            $plainKey = $field . '_plain';
            $current = trim((string)($existing[$plainKey] ?? ''));
            $target = trim((string)($payload[$field] ?? ''));
            if ($current !== $target) {
                $encryptedFieldChanges[] = $field;
            }
        }

        if ($encryptedFieldChanges !== []) {
            $changes['encrypted_fields'] = $encryptedFieldChanges;
        }

        if ($changes === []) {
            return ['changed' => false, 'changes' => []];
        }

        if ($this->apply) {
            $set = [];
            foreach ($changes as $field => $value) {
                if ($field === 'encrypted_fields') {
                    continue;
                }

                $set[] = "{$field} = " . (int)$value;
            }

            if ($set !== []) {
                $this->db->query("
                    UPDATE `{$this->database}`.dap03_personale
                    SET " . implode(', ', $set) . "
                    WHERE id_personale = " . (int)$existing['id_personale']
                );
            }

            if ($encryptedFieldChanges !== []) {
                $this->updateEncryptedPersonaleFields((int)$existing['id_personale'], $payload);
            }
        }

        $merged = array_merge($existing, $changes);
        foreach (['nome', 'cognome', 'qualifica', 'email', 'cellulare'] as $field) {
            $merged[$field . '_plain'] = (string)($payload[$field] ?? '');
        }
        $this->registerTargetPersonaleRow($merged);

        return ['changed' => true, 'changes' => $changes];
    }

    private function updateEncryptedPersonaleFields(int $personaleId, array $payload): void
    {
        if ($personaleId <= 0 || !$this->apply) {
            return;
        }

        $this->db->query('SET @init_vector = RANDOM_BYTES(16)');
        $stmt = $this->db->prepare("
            UPDATE `{$this->database}`.dap03_personale
            SET
                nome = HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                cognome = HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                qualifica = HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                email = HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                cellulare = HEX(AES_ENCRYPT(?, @key_str, @init_vector)),
                vector_id = @init_vector
            WHERE id_personale = ?
        ");

        $stmt->bind_param(
            'sssssi',
            $payload['nome'],
            $payload['cognome'],
            $payload['qualifica'],
            $payload['email'],
            $payload['cellulare'],
            $personaleId
        );
        $stmt->execute();
        $stmt->close();
    }

    private function syncTargetPersonaleBridgeColumnsByPersonaleId(int $personaleId, array $payload): void
    {
        if ($personaleId <= 0 || !$this->apply) {
            return;
        }

        $changes = [];
        foreach (['legacy_id_ope', 'legacy_id_dot', 'f_dom', 'legacy_dot_tipo_id'] as $field) {
            if (!$this->targetPersonaleHasColumn($field)) {
                continue;
            }

            $changes[$field] = (int)($payload[$field] ?? 0);
        }

        if ($changes === []) {
            return;
        }

        $set = [];
        foreach ($changes as $field => $value) {
            $set[] = "{$field} = " . (int)$value;
        }

        $this->db->query("
            UPDATE `{$this->database}`.dap03_personale
            SET " . implode(', ', $set) . "
            WHERE id_personale = " . (int)$personaleId
        );
    }

    private function buildPersonalePayload(array $row, ?int $userId, int $mappedTipo): array
    {
        $isDoctor = $mappedTipo === 1;
        $nome = $isDoctor
            ? $this->resolveDoctorFieldWithFallback($row, 'nome', (string)$row['dot_nome'], (string)$row['ope_nome'])
            : trim((string)$row['ope_nome']);
        $cognome = $isDoctor
            ? $this->resolveDoctorFieldWithFallback($row, 'cognome', (string)$row['dot_cognome'], (string)$row['ope_cognome'])
            : trim((string)$row['ope_cognome']);
        $email = $isDoctor && trim((string)$row['dot_email']) !== ''
            ? trim((string)$row['dot_email'])
            : trim((string)$row['ope_email']);
        $cellulare = $isDoctor ? trim((string)$row['dot_telefono']) : '';
        $qualifica = $this->resolveQualifica($row, $mappedTipo);

        return [
            'id_user' => $userId,
            'nome' => $nome,
            'cognome' => $cognome,
            'qualifica' => $qualifica,
            'tipo' => $mappedTipo,
            'email' => $email,
            'cellulare' => $cellulare,
            'is_dot' => 0,
            'sostituto' => 0,
            'titolare' => $isDoctor ? 1 : 0,
            'luogo' => 0,
            'legacy_id_ope' => (int)$row['id_ope'],
            'legacy_id_dot' => (int)$row['id_dot'],
            'f_dom' => $isDoctor ? (int)$row['dot_f_dom'] : 0,
            'legacy_dot_tipo_id' => $isDoctor ? (int)$row['dot_tipo_id'] : 0,
            'legacy' => [
                'id_ope' => (int)$row['id_ope'],
                'username' => (string)$row['username'],
                'id_dot' => (int)$row['id_dot'],
                'id_ruo' => (int)$row['id_ruo'],
                'des_ruo' => $this->sourceRoleLabels[(int)$row['id_ruo']] ?? '',
                'dot_tipo_id' => (int)$row['dot_tipo_id'],
                'dot_tipo_des' => (string)$row['dot_tipo_des'],
                'dot_f_dom' => (int)$row['dot_f_dom'],
            ],
        ];
    }

    private function resolveDoctorFieldWithFallback(array $row, string $field, string $doctorValue, string $operatorValue): string
    {
        $doctorValue = trim($doctorValue);
        $operatorValue = trim($operatorValue);

        if ($doctorValue === '') {
            return $operatorValue;
        }

        if ($this->encryptedValueFitsColumn($field, $doctorValue)) {
            return $doctorValue;
        }

        if ($operatorValue !== '' && $this->encryptedValueFitsColumn($field, $operatorValue)) {
            $this->recordCompatibilityFallback($row, $field, $doctorValue, $operatorValue);
            return $operatorValue;
        }

        return $doctorValue;
    }

    private function resolveDataScadenza(array $row): string
    {
        $candidate = trim((string)($row['data_scad_ute'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $candidate = trim((string)($row['data_scad_pass'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        return DEFAULT_PASSWORD_EXPIRY_FALLBACK;
    }

    private function resolveQualifica(array $row, int $mappedTipo): string
    {
        if ($mappedTipo === 1) {
            $normalized = $this->normalizeDoctorTitle((string)$row['dot_titolo']);
            if ($normalized !== '') {
                return $normalized;
            }
            return 'Dott.';
        }

        return match ($mappedTipo) {
            2 => 'Infermiere',
            3 => 'Segreteria',
            4 => 'Admin',
            default => '',
        };
    }

    private function normalizeDoctorTitle(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = str_replace([' ', "\t", "\r", "\n"], '', $value);
        if ($value === '') {
            return '';
        }

        if (str_contains($value, 'SSA')) {
            return 'Dott.ssa';
        }

        if (str_contains($value, 'DOTT') || str_contains($value, 'DR')) {
            return 'Dott.';
        }

        return trim($value);
    }

    private function validatePersonalePayload(array $payload): void
    {
        foreach ($this->personaleFieldLimits as $field => $limit) {
            $value = (string)($payload[$field] ?? '');
            if ($value === '') {
                continue;
            }

            $hexLength = $this->encryptedHexLength($value);
            if ($hexLength <= $limit) {
                continue;
            }

            $legacy = $payload['legacy'] ?? [];
            $context = [
                'id_ope' => (int)($legacy['id_ope'] ?? 0),
                'id_dot' => (int)($legacy['id_dot'] ?? 0),
                'id_ruo' => (int)($legacy['id_ruo'] ?? 0),
                'username' => (string)($legacy['username'] ?? ''),
                'field' => $field,
                'plain_length' => strlen($value),
                'encrypted_hex_length' => $hexLength,
                'column_limit' => $limit,
                'value' => $value,
            ];

            $this->recordCompatibilityOverflow($context);

            throw new RuntimeException(
                'Campo personale non compatibile con la cifratura: '
                . 'username=' . (string)($legacy['username'] ?? '')
                . ', id_ope=' . (int)($legacy['id_ope'] ?? 0)
                . ', field=' . $field
                . ', plain_length=' . strlen($value)
                . ', encrypted_hex_length=' . $hexLength
                . ', column_limit=' . $limit
                . ', value="' . $value . '"'
            );
        }
    }

    private function encryptedValueFitsColumn(string $field, string $value): bool
    {
        $limit = (int)($this->personaleFieldLimits[$field] ?? 0);
        if ($limit <= 0) {
            return true;
        }

        return $this->encryptedHexLength($value) <= $limit;
    }

    private function encryptedHexLength(string $value): int
    {
        $length = strlen($value);
        if ($length === 0) {
            return 0;
        }

        $cipherBytes = (int)(ceil(($length + 1) / 16) * 16);
        return $cipherBytes * 2;
    }

    private function recordCompatibilityFallback(array $row, string $field, string $doctorValue, string $operatorValue): void
    {
        $entry = [
            'id_ope' => (int)$row['id_ope'],
            'username' => (string)$row['username'],
            'id_dot' => (int)$row['id_dot'],
            'field' => $field,
            'doctor_value' => $doctorValue,
            'doctor_plain_length' => strlen($doctorValue),
            'doctor_encrypted_hex_length' => $this->encryptedHexLength($doctorValue),
            'operator_value' => $operatorValue,
            'operator_plain_length' => strlen($operatorValue),
            'operator_encrypted_hex_length' => $this->encryptedHexLength($operatorValue),
        ];

        if (count($this->report['compatibility']['fallback_examples']) < 30) {
            $this->report['compatibility']['fallback_examples'][] = $entry;
        }

        $this->logger->warning('Fallback compatibilita personale applicato', $entry);
    }

    private function recordCompatibilityOverflow(array $entry): void
    {
        if (count($this->report['compatibility']['overflow_examples']) < 30) {
            $this->report['compatibility']['overflow_examples'][] = $entry;
        }

        $this->logger->error('Overflow compatibilita personale', $entry);
    }

    private function mapFarRoleToDapTipo(int $idRuo): ?int
    {
        return match ($idRuo) {
            1 => 4, // Amministratore -> Admin
            2 => 3, // Segreteria
            3 => 1, // Dottore
            5 => 2, // Infermiere
            4, 6 => 4, // fallback interno
            default => null,
        };
    }

    private function buildExampleRow(array $row, ?array $user): array
    {
        return [
            'id_ope' => (int)$row['id_ope'],
            'username' => (string)$row['username'],
            'id_ruo' => (int)$row['id_ruo'],
            'des_ruo' => $this->sourceRoleLabels[(int)$row['id_ruo']] ?? '',
            'nome' => (string)$row['ope_nome'],
            'cognome' => (string)$row['ope_cognome'],
            'id_dot' => (int)$row['id_dot'],
            'dot_titolo' => (string)$row['dot_titolo'],
            'dot_tipo_des' => (string)$row['dot_tipo_des'],
            'dap_id_user' => $user ? (int)$user['id_user'] : null,
        ];
    }

    private function registerTargetUser(array $row): void
    {
        $row['id_user'] = (int)$row['id_user'];
        $row['tipo_user'] = (int)($row['tipo_user'] ?? 0);
        $row['is_active'] = (int)($row['is_active'] ?? 0);
        $row['username_norm'] = $this->normalizeUsername((string)$row['username']);

        $this->targetUsersById[(int)$row['id_user']] = $row;
        if ($row['username_norm'] !== '') {
            $this->targetUserBucketsByUsername[$row['username_norm']][] = $row;
            $this->selectCanonicalTargetUsers();
        }
    }

    private function normalizeUsername(string $value): string
    {
        return strtoupper(trim($value));
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
                'json_error' => json_last_error_msg(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        file_put_contents($this->reportPath, $json . PHP_EOL);
        file_put_contents($this->getSummaryTextPath(), $this->buildReadableSummaryText());
        file_put_contents($this->getSummaryHtmlPath(), $this->buildReadableSummaryHtml());
    }

    private function buildReadableSummaryText(): string
    {
        $audit = $this->report['audit'] ?? [];
        $migration = $this->report['migration'] ?? [];
        $compatibility = $this->report['compatibility'] ?? [];

        $lines = [];
        $lines[] = 'SYNC FAR01_OPE -> DAP01_USERS / DAP03_PERSONALE';
        $lines[] = str_repeat('=', 58);
        $lines[] = 'Stato: ' . (string)($this->report['status'] ?? 'n/d');
        $lines[] = 'Modalita: ' . (string)($this->report['mode'] ?? 'n/d');
        $lines[] = 'Database: ' . (string)($this->report['database'] ?? 'n/d');
        $lines[] = 'Inizio: ' . (string)($this->report['started_at'] ?? 'n/d');
        $lines[] = 'Fine: ' . (string)($this->report['finished_at'] ?? 'n/d');
        $lines[] = 'Log: ' . (string)($this->report['log_path'] ?? '');
        $lines[] = 'JSON tecnico: ' . (string)($this->report['report_path'] ?? '');
        $lines[] = 'Summary HTML: ' . (string)($this->report['summary_html_path'] ?? '');
        $lines[] = '';

        $lines[] = 'FILTRI';
        $lines[] = '------';
        $lines[] = 'Operatori: ' . $this->formatListForSummary(($this->report['filters']['operators'] ?? []));
        $lines[] = 'Ruoli: ' . $this->formatListForSummary(($this->report['filters']['roles'] ?? []));
        $lines[] = '';

        $lines[] = 'NUMERI PRINCIPALI';
        $lines[] = '-----------------';
        $lines[] = 'Operatori far01 analizzati: ' . $this->summaryNumber($audit, 'source_far01_total');
        $lines[] = 'Operatori con username: ' . $this->summaryNumber($audit, 'source_far01_with_username');
        $lines[] = 'Utenti gia presenti in dap01_users: ' . $this->summaryNumber($audit, 'target_dap01_total');
        $lines[] = 'Personale gia presente in dap03_personale: ' . $this->summaryNumber($audit, 'target_dap03_total');
        $lines[] = 'Username duplicati in dap01_users: ' . $this->summaryNumber($audit, 'duplicate_dap01_usernames');
        $lines[] = 'Utenti mancanti in dap01_users: ' . $this->summaryNumber($audit, 'missing_dap01');
        $lines[] = 'Utenti senza personale in dap03_personale: ' . $this->summaryNumber($audit, 'missing_dap03');
        $lines[] = 'Ruoli non supportati: ' . $this->summaryNumber($audit, 'unsupported_roles');
        $lines[] = 'Righe dottore: ' . $this->summaryNumber($audit, 'doctor_rows');
        $lines[] = 'Dottori senza far03_dot: ' . $this->summaryNumber($audit, 'doctor_rows_without_far03');
        $lines[] = '';

        $lines[] = 'AZIONI PREVISTE / ESEGUITE';
        $lines[] = '-------------------------';
        $lines[] = 'Utenti inseriti: ' . $this->summaryNumber($migration, 'users_inserted');
        $lines[] = 'Utenti gia presenti: ' . $this->summaryNumber($migration, 'users_skipped_existing');
        $lines[] = 'Utenti con username vuoto saltati: ' . $this->summaryNumber($migration, 'users_skipped_blank_username');
        $lines[] = 'Personale inserito: ' . $this->summaryNumber($migration, 'personale_inserted');
        $lines[] = 'Personale aggiornato/allineato: ' . $this->summaryNumber($migration, 'personale_updated');
        $lines[] = 'Personale gia allineato: ' . $this->summaryNumber($migration, 'personale_already_aligned');
        $lines[] = 'Righe saltate per ruolo non supportato: ' . $this->summaryNumber($migration, 'rows_skipped_unsupported_role');
        $lines[] = '';

        $lines[] = 'RIPARTIZIONE PER RUOLO';
        $lines[] = '---------------------';
        foreach (($audit['role_breakdown'] ?? []) as $row) {
            $lines[] = sprintf(
                '- %s (#%d): righe=%d | missing_dap01=%d | missing_dap03=%d',
                (string)($row['des_ruo'] ?? ''),
                (int)($row['id_ruo'] ?? 0),
                (int)($row['rows'] ?? 0),
                (int)($row['missing_dap01'] ?? 0),
                (int)($row['missing_dap03'] ?? 0)
            );
        }
        if (($audit['role_breakdown'] ?? []) === []) {
            $lines[] = '- Nessun dato';
        }
        $lines[] = '';

        $this->appendSummaryExamples(
            $lines,
            'ESEMPI UTENTI MANCANTI IN DAP01_USERS',
            $audit['missing_dap01_examples'] ?? [],
            15,
            fn(array $row): string => $this->formatExamplePersonRow($row)
        );

        $this->appendSummaryExamples(
            $lines,
            'ESEMPI DOTTORI SENZA FAR03_DOT',
            $audit['doctor_rows_without_far03_examples'] ?? [],
            15,
            fn(array $row): string => $this->formatExamplePersonRow($row)
        );

        $this->appendSummaryExamples(
            $lines,
            'ESEMPI DUPLICATI USERNAME IN DAP01_USERS',
            $audit['duplicate_dap01_examples'] ?? [],
            10,
            fn(array $row): string => $this->formatDuplicateUserExample($row)
        );

        $this->appendSummaryExamples(
            $lines,
            'ESEMPI UTENTI CHE VERREBBERO INSERITI',
            $migration['examples_users_inserted'] ?? [],
            15,
            fn(array $row): string => $this->formatExamplePersonRow($row)
        );

        $this->appendSummaryExamples(
            $lines,
            'ESEMPI PERSONALE CHE VERREBBE INSERITO',
            $migration['examples_personale_inserted'] ?? [],
            15,
            fn(array $row): string => $this->formatPersonalePayloadExample($row)
        );

        $this->appendSummaryExamples(
            $lines,
            'FALLBACK COMPATIBILITA APPLICATI',
            $compatibility['fallback_examples'] ?? [],
            15,
            fn(array $row): string => $this->formatCompatibilityFallbackExample($row)
        );

        $this->appendSummaryExamples(
            $lines,
            'OVERFLOW COMPATIBILITA RILEVATI',
            $compatibility['overflow_examples'] ?? [],
            15,
            fn(array $row): string => $this->formatCompatibilityOverflowExample($row)
        );

        if (($this->report['status'] ?? '') === 'error') {
            $lines[] = 'ERRORE';
            $lines[] = '------';
            $error = $this->report['error'] ?? [];
            $lines[] = 'Messaggio: ' . (string)($error['message'] ?? 'n/d');
            $lines[] = 'File: ' . (string)($error['file'] ?? 'n/d');
            $lines[] = 'Linea: ' . (string)($error['line'] ?? 'n/d');
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function buildReadableSummaryHtml(): string
    {
        $audit = $this->report['audit'] ?? [];
        $migration = $this->report['migration'] ?? [];
        $compatibility = $this->report['compatibility'] ?? [];

        $roleRows = '';
        foreach (($audit['role_breakdown'] ?? []) as $row) {
            $roleRows .= '<tr>'
                . '<td>' . $this->html((string)($row['des_ruo'] ?? '')) . '</td>'
                . '<td>' . (int)($row['id_ruo'] ?? 0) . '</td>'
                . '<td>' . (int)($row['rows'] ?? 0) . '</td>'
                . '<td>' . (int)($row['missing_dap01'] ?? 0) . '</td>'
                . '<td>' . (int)($row['missing_dap03'] ?? 0) . '</td>'
                . '</tr>';
        }
        if ($roleRows === '') {
            $roleRows = '<tr><td colspan="5">Nessun dato</td></tr>';
        }

        $html = '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Summary sync far01_ope -> dap*</title>'
            . '<style>'
            . 'body{font-family:Arial,sans-serif;margin:24px;background:#f6f7f9;color:#1f2937;}'
            . '.box{background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:16px;margin-bottom:18px;max-width:1100px;}'
            . 'h1,h2{margin-top:0;}'
            . 'table{width:100%;border-collapse:collapse;}'
            . 'th,td{border:1px solid #d1d5db;padding:8px;text-align:left;vertical-align:top;}'
            . 'th{background:#eef2ff;}'
            . 'code{background:#eef2ff;padding:2px 6px;border-radius:4px;}'
            . 'ul{margin:0;padding-left:20px;}'
            . '</style></head><body>';

        $html .= '<div class="box"><h1>Sync far01_ope -> dap01_users / dap03_personale</h1>'
            . '<p><strong>Stato:</strong> ' . $this->html((string)($this->report['status'] ?? 'n/d')) . '</p>'
            . '<p><strong>Modalita:</strong> ' . $this->html((string)($this->report['mode'] ?? 'n/d')) . '</p>'
            . '<p><strong>Database:</strong> ' . $this->html((string)($this->report['database'] ?? 'n/d')) . '</p>'
            . '<p><strong>Inizio:</strong> ' . $this->html((string)($this->report['started_at'] ?? 'n/d')) . '<br>'
            . '<strong>Fine:</strong> ' . $this->html((string)($this->report['finished_at'] ?? 'n/d')) . '</p>'
            . '<p><strong>Log:</strong> <code>' . $this->html((string)($this->report['log_path'] ?? '')) . '</code><br>'
            . '<strong>JSON tecnico:</strong> <code>' . $this->html((string)($this->report['report_path'] ?? '')) . '</code></p>'
            . '</div>';

        $html .= '<div class="box"><h2>Numeri principali</h2><table><tbody>'
            . $this->metricRow('Operatori far01 analizzati', $this->summaryNumber($audit, 'source_far01_total'))
            . $this->metricRow('Operatori con username', $this->summaryNumber($audit, 'source_far01_with_username'))
            . $this->metricRow('Utenti gia presenti in dap01_users', $this->summaryNumber($audit, 'target_dap01_total'))
            . $this->metricRow('Personale gia presente in dap03_personale', $this->summaryNumber($audit, 'target_dap03_total'))
            . $this->metricRow('Username duplicati in dap01_users', $this->summaryNumber($audit, 'duplicate_dap01_usernames'))
            . $this->metricRow('Utenti mancanti in dap01_users', $this->summaryNumber($audit, 'missing_dap01'))
            . $this->metricRow('Utenti senza personale in dap03_personale', $this->summaryNumber($audit, 'missing_dap03'))
            . $this->metricRow('Ruoli non supportati', $this->summaryNumber($audit, 'unsupported_roles'))
            . $this->metricRow('Righe dottore', $this->summaryNumber($audit, 'doctor_rows'))
            . $this->metricRow('Dottori senza far03_dot', $this->summaryNumber($audit, 'doctor_rows_without_far03'))
            . '</tbody></table></div>';

        $html .= '<div class="box"><h2>Azioni previste / eseguite</h2><table><tbody>'
            . $this->metricRow('Utenti inseriti', $this->summaryNumber($migration, 'users_inserted'))
            . $this->metricRow('Utenti gia presenti', $this->summaryNumber($migration, 'users_skipped_existing'))
            . $this->metricRow('Utenti con username vuoto saltati', $this->summaryNumber($migration, 'users_skipped_blank_username'))
            . $this->metricRow('Personale inserito', $this->summaryNumber($migration, 'personale_inserted'))
            . $this->metricRow('Personale aggiornato/allineato', $this->summaryNumber($migration, 'personale_updated'))
            . $this->metricRow('Personale gia allineato', $this->summaryNumber($migration, 'personale_already_aligned'))
            . $this->metricRow('Righe saltate per ruolo non supportato', $this->summaryNumber($migration, 'rows_skipped_unsupported_role'))
            . '</tbody></table></div>';

        $html .= '<div class="box"><h2>Ripartizione per ruolo</h2><table><thead><tr>'
            . '<th>Ruolo</th><th>ID</th><th>Righe</th><th>Missing dap01</th><th>Missing dap03</th>'
            . '</tr></thead><tbody>' . $roleRows . '</tbody></table></div>';

        $html .= $this->buildHtmlExampleSection(
            'Esempi utenti mancanti in dap01_users',
            $audit['missing_dap01_examples'] ?? [],
            15,
            fn(array $row): string => $this->formatExamplePersonRow($row)
        );
        $html .= $this->buildHtmlExampleSection(
            'Esempi dottori senza far03_dot',
            $audit['doctor_rows_without_far03_examples'] ?? [],
            15,
            fn(array $row): string => $this->formatExamplePersonRow($row)
        );
        $html .= $this->buildHtmlExampleSection(
            'Esempi duplicati username in dap01_users',
            $audit['duplicate_dap01_examples'] ?? [],
            10,
            fn(array $row): string => $this->formatDuplicateUserExample($row)
        );
        $html .= $this->buildHtmlExampleSection(
            'Esempi utenti che verrebbero inseriti',
            $migration['examples_users_inserted'] ?? [],
            15,
            fn(array $row): string => $this->formatExamplePersonRow($row)
        );
        $html .= $this->buildHtmlExampleSection(
            'Esempi personale che verrebbe inserito',
            $migration['examples_personale_inserted'] ?? [],
            15,
            fn(array $row): string => $this->formatPersonalePayloadExample($row)
        );
        $html .= $this->buildHtmlExampleSection(
            'Fallback compatibilita applicati',
            $compatibility['fallback_examples'] ?? [],
            15,
            fn(array $row): string => $this->formatCompatibilityFallbackExample($row)
        );
        $html .= $this->buildHtmlExampleSection(
            'Overflow compatibilita rilevati',
            $compatibility['overflow_examples'] ?? [],
            15,
            fn(array $row): string => $this->formatCompatibilityOverflowExample($row)
        );

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

    private function summaryNumber(array $bucket, string $key): string
    {
        return isset($bucket[$key]) ? (string)$bucket[$key] : '0';
    }

    private function formatListForSummary(array $items): string
    {
        return $items === [] ? 'tutti' : implode(', ', array_map('strval', $items));
    }

    private function appendSummaryExamples(array &$lines, string $title, array $rows, int $limit, callable $formatter): void
    {
        $lines[] = $title;
        $lines[] = str_repeat('-', strlen($title));
        if ($rows === []) {
            $lines[] = '- Nessun elemento';
            $lines[] = '';
            return;
        }

        $count = 0;
        foreach ($rows as $row) {
            $lines[] = '- ' . $formatter($row);
            $count++;
            if ($count >= $limit) {
                break;
            }
        }
        $lines[] = '';
    }

    private function formatExamplePersonRow(array $row): string
    {
        $parts = [];
        if (isset($row['id_ope'])) {
            $parts[] = 'id_ope=' . (int)$row['id_ope'];
        }
        if (isset($row['username'])) {
            $parts[] = 'username=' . (string)$row['username'];
        }
        if (isset($row['des_ruo'])) {
            $parts[] = 'ruolo=' . (string)$row['des_ruo'];
        }

        $nome = trim(((string)($row['nome'] ?? '')) . ' ' . ((string)($row['cognome'] ?? '')));
        if ($nome !== '') {
            $parts[] = 'nominativo=' . $nome;
        }

        if (isset($row['id_dot']) && (int)$row['id_dot'] > 0) {
            $parts[] = 'id_dot=' . (int)$row['id_dot'];
        }
        if (isset($row['dot_tipo_des']) && trim((string)$row['dot_tipo_des']) !== '') {
            $parts[] = 'tipo_dottore=' . (string)$row['dot_tipo_des'];
        }
        if (isset($row['dap_id_user']) && $row['dap_id_user'] !== null) {
            $parts[] = 'dap_id_user=' . (int)$row['dap_id_user'];
        }

        return implode(' | ', $parts);
    }

    private function formatDuplicateUserExample(array $row): string
    {
        $candidates = [];
        foreach (($row['candidates'] ?? []) as $candidate) {
            $candidates[] = 'id_user=' . (int)($candidate['id_user'] ?? 0)
                . '/tipo_user=' . (int)($candidate['tipo_user'] ?? 0)
                . '/personale=' . (!empty($candidate['has_personale']) ? 'si' : 'no');
        }

        return 'username=' . (string)($row['username'] ?? '')
            . ' | canonical_id_user=' . (int)($row['canonical_id_user'] ?? 0)
            . ' | candidati=' . implode(', ', $candidates);
    }

    private function formatPersonalePayloadExample(array $row): string
    {
        return 'id_user=' . (int)($row['id_user'] ?? 0)
            . ' | tipo=' . (int)($row['tipo'] ?? 0)
            . ' | nominativo=' . trim(((string)($row['nome'] ?? '')) . ' ' . ((string)($row['cognome'] ?? '')))
            . ' | qualifica=' . (string)($row['qualifica'] ?? '')
            . ' | email=' . (string)($row['email'] ?? '')
            . ' | cellulare=' . (string)($row['cellulare'] ?? '');
    }

    private function formatCompatibilityFallbackExample(array $row): string
    {
        return 'id_ope=' . (int)($row['id_ope'] ?? 0)
            . ' | username=' . (string)($row['username'] ?? '')
            . ' | id_dot=' . (int)($row['id_dot'] ?? 0)
            . ' | field=' . (string)($row['field'] ?? '')
            . ' | doctor_value=' . (string)($row['doctor_value'] ?? '')
            . ' | operator_value=' . (string)($row['operator_value'] ?? '');
    }

    private function formatCompatibilityOverflowExample(array $row): string
    {
        return 'id_ope=' . (int)($row['id_ope'] ?? 0)
            . ' | username=' . (string)($row['username'] ?? '')
            . ' | id_dot=' . (int)($row['id_dot'] ?? 0)
            . ' | field=' . (string)($row['field'] ?? '')
            . ' | plain_length=' . (int)($row['plain_length'] ?? 0)
            . ' | encrypted_hex_length=' . (int)($row['encrypted_hex_length'] ?? 0)
            . ' | column_limit=' . (int)($row['column_limit'] ?? 0)
            . ' | value=' . (string)($row['value'] ?? '');
    }

    private function buildHtmlExampleSection(string $title, array $rows, int $limit, callable $formatter): string
    {
        $html = '<div class="box"><h2>' . $this->html($title) . '</h2>';
        if ($rows === []) {
            $html .= '<p>Nessun elemento</p></div>';
            return $html;
        }

        $html .= '<ul>';
        $count = 0;
        foreach ($rows as $row) {
            $html .= '<li>' . $this->html($formatter($row)) . '</li>';
            $count++;
            if ($count >= $limit) {
                break;
            }
        }
        $html .= '</ul></div>';
        return $html;
    }

    private function metricRow(string $label, string $value): string
    {
        return '<tr><th>' . $this->html($label) . '</th><td>' . $this->html($value) . '</td></tr>';
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
