<?php
declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');
ignore_user_abort(true);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const DEFAULT_SOURCE_DB = '';
const DEFAULT_TARGET_DB = 'mail';
const DEFAULT_REPORT_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'dap_visibility_sync';
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
    $basePath = (string)$options['report_dir'] . DIRECTORY_SEPARATOR . 'dap_visibility_sync_' . $stamp;
    $logPath = $basePath . '.log';
    $reportPath = $basePath . '.json';

    $logger = new CliLogger($logPath);
    $logger->info('Avvio sync visibilita farmacia -> dap14/dap15', [
        'mode' => !empty($options['apply']) ? 'apply' : 'dry-run',
        'source_db' => $dbConfig['source_db'],
        'target_db' => $dbConfig['target_db'],
        'source_dot_filter' => $options['source_dots'],
    ]);

    $script = new DapVisibilitySync($dbConfig, $options, $logger, $reportPath);
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

    $argv = ['migrate_farmacia_visibility_to_mail_dap.php'];
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
        'source-dots',
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
    $self = $_SERVER['PHP_SELF'] ?? 'migrate_farmacia_visibility_to_mail_dap.php';
    $base = $self !== '' ? $self : 'migrate_farmacia_visibility_to_mail_dap.php';
    $html = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Sync visibilita farmacia -> dap*</title>
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
        <h1>Sync visibilita legacy -> dap14_seg_dot / dap15_inf_dot</h1>
        <p>Questo step legge i permessi legacy dalla tabella <code>far10_vis_dot</code> del DB che indichi tu in <code>source-db</code> e importa in modo additivo solo i link compatibili con <code>ambulatori.cloud</code>.</p>
        <p>Lo script non usa piu nessun database legacy in modo implicito: <code>source-db</code> va indicato esplicitamente solo per una migrazione una tantum.</p>
        <p>Non cancella nulla da <code>mail</code>. I log e i report vengono salvati in <code>writable/dap_visibility_sync</code>.</p>
    </div>

    <div class="box">
        <h2>Dry-run</h2>
        <form method="get" action="{$base}">
            <input type="hidden" name="run" value="1">
            <div class="grid">
                <div>
                    <label for="source-dots">source-dots</label>
                    <input id="source-dots" type="text" name="source-dots" value="" placeholder="es. 28,30,41">
                </div>
                <div>
                    <label for="source-db">source-db</label>
                    <input id="source-db" type="text" name="source-db" value="" placeholder="es. farmacia_dump_locale">
                </div>
                <div>
                    <label for="target-db">target-db</label>
                    <input id="target-db" type="text" name="target-db" value="mail">
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
                    <label for="source-dots-apply">source-dots</label>
                    <input id="source-dots-apply" type="text" name="source-dots" value="" placeholder="es. 28,30,41">
                </div>
                <div>
                    <label for="source-db-apply">source-db</label>
                    <input id="source-db-apply" type="text" name="source-db" value="" placeholder="es. farmacia_dump_locale">
                </div>
                <div>
                    <label for="target-db-apply">target-db</label>
                    <input id="target-db-apply" type="text" name="target-db" value="mail">
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
        'source_db' => optionValue($argv, 'source-db') ?: DEFAULT_SOURCE_DB,
        'target_db' => optionValue($argv, 'target-db') ?: DEFAULT_TARGET_DB,
        'source_dots' => parseCsvInts(optionValue($argv, 'source-dots')),
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
                return '[' . implode(', ', array_map(fn($item) => $this->formatScalar($item), $value)) . ']';
            }

            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = $key . ':' . $this->formatScalar($item);
            }
            return '{' . implode('; ', $parts) . '}';
        }

        return $this->formatScalar($value);
    }

    private function formatScalar(mixed $value): string
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

final class DapVisibilitySync
{
    private mysqli $db;
    private array $dbConfig;
    private array $options;
    private CliLogger $logger;
    private string $reportPath;
    private string $sourceDb;
    private string $targetDb;
    private bool $apply;
    /** @var int[] */
    private array $sourceDotFilter = [];
    private array $report = [];
    private array $sourceEntitiesByDot = [];
    private array $targetPersonaleByUsernameTipo = [];
    private array $targetInfLinks = [];
    private array $targetSegLinks = [];
    private array $infCandidates = [];
    private array $segCandidates = [];
    private array $skipped = [];

    public function __construct(array $dbConfig, array $options, CliLogger $logger, string $reportPath)
    {
        $this->dbConfig = $dbConfig;
        $this->options = $options;
        $this->logger = $logger;
        $this->reportPath = $reportPath;
        $this->sourceDb = (string)$dbConfig['source_db'];
        $this->targetDb = (string)$dbConfig['target_db'];
        $this->apply = !empty($options['apply']);
        $this->sourceDotFilter = $options['source_dots'];
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

            $this->report = [
                'started_at' => date('c'),
                'mode' => $this->apply ? 'apply' : 'dry-run',
                'source_db' => $this->sourceDb,
                'target_db' => $this->targetDb,
                'log_path' => $this->logger->path(),
                'report_path' => $this->reportPath,
                'summary_text_path' => $this->getSummaryTextPath(),
                'summary_html_path' => $this->getSummaryHtmlPath(),
                'filters' => [
                    'source_dots' => $this->sourceDotFilter,
                ],
                'assumptions' => [
                    'mail_authoritative' => 'Le tabelle dap14_seg_dot e dap15_inf_dot vengono solo arricchite; nessun delete/update sui dati esistenti.',
                    'target_mapping_strategy' => 'Mapping per username + tipo personale su dap01_users/dap03_personale.',
                    'legacy_scope' => 'farmacia.far10_vis_dot collega dottori e infermieri; non esiste una tabella legacy dedicata segreteria->dottore individuata con certezza.',
                    'supported_target_tables' => ['dap14_seg_dot', 'dap15_inf_dot'],
                ],
                'audit' => [],
                'migration' => [],
            ];

            $this->loadTargetPersonaleMap();
            $this->loadExistingTargetLinks();
            $this->loadSourceEntities();
            $this->buildCandidates();
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

    private function loadTargetPersonaleMap(): void
    {
        $sql = "
            SELECT
                u.username,
                p.id_personale,
                p.id_user,
                p.tipo
            FROM `{$this->targetDb}`.dap01_users u
            INNER JOIN `{$this->targetDb}`.dap03_personale p
                ON p.id_user = u.id_user
            ORDER BY p.id_personale ASC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $usernameNorm = $this->normalizeUsername((string)$row['username']);
            $tipo = (int)($row['tipo'] ?? 0);
            if ($usernameNorm === '' || $tipo <= 0) {
                continue;
            }

            $key = $this->buildUsernameTipoKey($usernameNorm, $tipo);
            if (!isset($this->targetPersonaleByUsernameTipo[$key])) {
                $this->targetPersonaleByUsernameTipo[$key] = [
                    'id_personale' => (int)$row['id_personale'],
                    'id_user' => (int)$row['id_user'],
                    'username' => (string)$row['username'],
                    'tipo' => $tipo,
                ];
            }
        }
    }

    private function loadExistingTargetLinks(): void
    {
        $res = $this->db->query("SELECT id_inf, id_dot FROM `{$this->targetDb}`.dap15_inf_dot");
        while ($row = $res->fetch_assoc()) {
            $this->targetInfLinks[$this->buildPairKey((int)$row['id_inf'], (int)$row['id_dot'])] = true;
        }

        $res = $this->db->query("SELECT id_seg, id_dot FROM `{$this->targetDb}`.dap14_seg_dot");
        while ($row = $res->fetch_assoc()) {
            $this->targetSegLinks[$this->buildPairKey((int)$row['id_seg'], (int)$row['id_dot'])] = true;
        }
    }

    private function loadSourceEntities(): void
    {
        $sql = "
            SELECT
                d.id_dot,
                d.id_ope,
                COALESCE(o.user, '') AS username,
                COALESCE(o.nome, '') AS ope_nome,
                COALESCE(o.cognome, '') AS ope_cognome,
                COALESCE(o.id_ruo, 0) AS id_ruo
            FROM `{$this->sourceDb}`.far03_dot d
            LEFT JOIN `{$this->sourceDb}`.far01_ope o
                ON o.id_ope = d.id_ope
            ORDER BY d.id_dot ASC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $idDot = (int)$row['id_dot'];
            $this->sourceEntitiesByDot[$idDot] = [
                'id_dot' => $idDot,
                'id_ope' => (int)($row['id_ope'] ?? 0),
                'id_ruo' => (int)($row['id_ruo'] ?? 0),
                'username' => (string)$row['username'],
                'username_norm' => $this->normalizeUsername((string)$row['username']),
                'ope_nome' => (string)$row['ope_nome'],
                'ope_cognome' => (string)$row['ope_cognome'],
            ];
        }
    }

    private function buildCandidates(): void
    {
        $where = '';
        if ($this->sourceDotFilter !== []) {
            $ids = implode(',', array_map('intval', $this->sourceDotFilter));
            $where = " WHERE v.id_dot IN ({$ids}) OR v.id_dot_vis IN ({$ids})";
        }

        $sql = "
            SELECT v.id_dot, v.id_dot_vis
            FROM `{$this->sourceDb}`.far10_vis_dot v
            {$where}
            ORDER BY v.id_dot ASC, v.id_dot_vis ASC
        ";

        $res = $this->db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $masterDot = (int)$row['id_dot'];
            $visibleDot = (int)$row['id_dot_vis'];
            $master = $this->sourceEntitiesByDot[$masterDot] ?? null;
            $visible = $this->sourceEntitiesByDot[$visibleDot] ?? null;

            if ($master === null || $visible === null) {
                $this->registerSkipped('missing_source_dot', [
                    'source_id_dot' => $masterDot,
                    'source_id_dot_vis' => $visibleDot,
                    'master_found' => $master !== null,
                    'visible_found' => $visible !== null,
                ]);
                continue;
            }

            $masterRole = (int)$master['id_ruo'];
            $visibleRole = (int)$visible['id_ruo'];

            if ($this->isDoctorNursePair($masterRole, $visibleRole)) {
                $doctor = $masterRole === 3 ? $master : $visible;
                $nurse = $masterRole === 5 ? $master : $visible;
                $this->registerInfCandidate($master, $visible, $doctor, $nurse);
                continue;
            }

            if ($this->isDoctorSecretaryPair($masterRole, $visibleRole)) {
                $doctor = $masterRole === 3 ? $master : $visible;
                $secretary = $masterRole === 2 ? $master : $visible;
                $this->registerSegCandidate($master, $visible, $doctor, $secretary);
                continue;
            }

            if ($masterRole === 3 && $visibleRole === 3) {
                $this->registerSkipped('doctor_doctor_pair_no_dap_table', [
                    'source_id_dot' => $masterDot,
                    'source_id_dot_vis' => $visibleDot,
                    'master_username' => $master['username'],
                    'visible_username' => $visible['username'],
                ]);
                continue;
            }

            if ($masterRole === 5 && $visibleRole === 5) {
                $this->registerSkipped('nurse_nurse_pair_no_dap_table', [
                    'source_id_dot' => $masterDot,
                    'source_id_dot_vis' => $visibleDot,
                    'master_username' => $master['username'],
                    'visible_username' => $visible['username'],
                ]);
                continue;
            }

            $this->registerSkipped('unsupported_roles', [
                'source_id_dot' => $masterDot,
                'source_id_dot_vis' => $visibleDot,
                'master_role' => $masterRole,
                'visible_role' => $visibleRole,
                'master_username' => $master['username'],
                'visible_username' => $visible['username'],
            ]);
        }
    }

    private function registerInfCandidate(array $master, array $visible, array $doctor, array $nurse): void
    {
        $targetDoctor = $this->resolveTargetPersonaleByUsernameAndTipo((string)$doctor['username'], 1);
        $targetNurse = $this->resolveTargetPersonaleByUsernameAndTipo((string)$nurse['username'], 2);

        if ($targetDoctor === null || $targetNurse === null) {
            $this->registerSkipped('missing_target_personale_inf', [
                'source_master_dot' => (int)$master['id_dot'],
                'source_visible_dot' => (int)$visible['id_dot'],
                'doctor_username' => (string)$doctor['username'],
                'nurse_username' => (string)$nurse['username'],
                'target_doctor_found' => $targetDoctor !== null,
                'target_nurse_found' => $targetNurse !== null,
            ]);
            return;
        }

        $key = $this->buildPairKey((int)$targetNurse['id_personale'], (int)$targetDoctor['id_personale']);
        if (isset($this->infCandidates[$key])) {
            return;
        }

        $this->infCandidates[$key] = [
            'id_inf' => (int)$targetNurse['id_personale'],
            'id_dot' => (int)$targetDoctor['id_personale'],
            'source_master_dot' => (int)$master['id_dot'],
            'source_visible_dot' => (int)$visible['id_dot'],
            'doctor_username' => (string)$doctor['username'],
            'nurse_username' => (string)$nurse['username'],
            'doctor_id_ope' => (int)$doctor['id_ope'],
            'nurse_id_ope' => (int)$nurse['id_ope'],
            'already_exists' => isset($this->targetInfLinks[$key]),
        ];
    }

    private function registerSegCandidate(array $master, array $visible, array $doctor, array $secretary): void
    {
        $targetDoctor = $this->resolveTargetPersonaleByUsernameAndTipo((string)$doctor['username'], 1);
        $targetSecretary = $this->resolveTargetPersonaleByUsernameAndTipo((string)$secretary['username'], 3);

        if ($targetDoctor === null || $targetSecretary === null) {
            $this->registerSkipped('missing_target_personale_seg', [
                'source_master_dot' => (int)$master['id_dot'],
                'source_visible_dot' => (int)$visible['id_dot'],
                'doctor_username' => (string)$doctor['username'],
                'secretary_username' => (string)$secretary['username'],
                'target_doctor_found' => $targetDoctor !== null,
                'target_secretary_found' => $targetSecretary !== null,
            ]);
            return;
        }

        $key = $this->buildPairKey((int)$targetSecretary['id_personale'], (int)$targetDoctor['id_personale']);
        if (isset($this->segCandidates[$key])) {
            return;
        }

        $this->segCandidates[$key] = [
            'id_seg' => (int)$targetSecretary['id_personale'],
            'id_dot' => (int)$targetDoctor['id_personale'],
            'source_master_dot' => (int)$master['id_dot'],
            'source_visible_dot' => (int)$visible['id_dot'],
            'doctor_username' => (string)$doctor['username'],
            'secretary_username' => (string)$secretary['username'],
            'doctor_id_ope' => (int)$doctor['id_ope'],
            'secretary_id_ope' => (int)$secretary['id_ope'],
            'already_exists' => isset($this->targetSegLinks[$key]),
        ];
    }

    private function audit(): void
    {
        $sourcePairCounts = $this->computeSourcePairCounts();
        $targetAudit = $this->auditCurrentTargetTables();

        $audit = [
            'source_entities_far03_total' => count($this->sourceEntitiesByDot),
            'source_far10_vis_dot_rows' => $this->scalar("SELECT COUNT(*) AS c FROM `{$this->sourceDb}`.far10_vis_dot"),
            'source_doctor_doctor_pairs' => $sourcePairCounts['doctor_doctor'],
            'source_doctor_nurse_pairs' => $sourcePairCounts['doctor_nurse'],
            'source_nurse_nurse_pairs' => $sourcePairCounts['nurse_nurse'],
            'source_secretary_pairs_detected' => $sourcePairCounts['secretary_related'],
            'target_dap14_existing_rows' => count($this->targetSegLinks),
            'target_dap15_existing_rows' => count($this->targetInfLinks),
            'target_dap14_duplicate_pairs' => $targetAudit['dap14_duplicates'],
            'target_dap15_duplicate_pairs' => $targetAudit['dap15_duplicates'],
            'target_dap14_orphan_rows' => $targetAudit['dap14_orphans'],
            'target_dap15_orphan_rows' => $targetAudit['dap15_orphans'],
            'inf_candidates_total' => count($this->infCandidates),
            'inf_candidates_existing' => count(array_filter($this->infCandidates, static fn(array $c): bool => !empty($c['already_exists']))),
            'inf_candidates_missing' => count(array_filter($this->infCandidates, static fn(array $c): bool => empty($c['already_exists']))),
            'seg_candidates_total' => count($this->segCandidates),
            'seg_candidates_existing' => count(array_filter($this->segCandidates, static fn(array $c): bool => !empty($c['already_exists']))),
            'seg_candidates_missing' => count(array_filter($this->segCandidates, static fn(array $c): bool => empty($c['already_exists']))),
            'skipped_counts' => $this->computeSkippedCounts(),
            'examples_inf_candidates_missing' => array_values(array_slice(array_filter(
                $this->infCandidates,
                static fn(array $c): bool => empty($c['already_exists'])
            ), 0, 25)),
            'examples_seg_candidates_missing' => array_values(array_slice(array_filter(
                $this->segCandidates,
                static fn(array $c): bool => empty($c['already_exists'])
            ), 0, 25)),
            'examples_skipped' => array_slice($this->skipped, 0, 40),
            'examples_target_duplicates' => $targetAudit['duplicate_examples'],
            'examples_target_orphans' => $targetAudit['orphan_examples'],
        ];

        $this->report['audit'] = $audit;
        $this->logger->info('Audit completato', [
            'inf_candidates_total' => $audit['inf_candidates_total'],
            'inf_candidates_missing' => $audit['inf_candidates_missing'],
            'seg_candidates_total' => $audit['seg_candidates_total'],
            'seg_candidates_missing' => $audit['seg_candidates_missing'],
            'skipped_total' => array_sum($audit['skipped_counts']),
        ]);
    }

    private function migrate(): void
    {
        $phase = [
            'dap15_inf_inserted' => 0,
            'dap15_inf_skipped_existing' => 0,
            'dap14_seg_inserted' => 0,
            'dap14_seg_skipped_existing' => 0,
            'examples_dap15_inf_inserted' => [],
            'examples_dap14_seg_inserted' => [],
        ];

        if ($this->apply) {
            $this->db->begin_transaction();
        }

        foreach ($this->infCandidates as $candidate) {
            $key = $this->buildPairKey((int)$candidate['id_inf'], (int)$candidate['id_dot']);
            if (isset($this->targetInfLinks[$key])) {
                $phase['dap15_inf_skipped_existing']++;
                continue;
            }

            if ($this->apply) {
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->targetDb}`.dap15_inf_dot (id_dot, id_inf)
                    VALUES (?, ?)
                ");
                $stmt->bind_param('ii', $candidate['id_dot'], $candidate['id_inf']);
                $stmt->execute();
                $stmt->close();
            }

            $this->targetInfLinks[$key] = true;
            $phase['dap15_inf_inserted']++;
            if (count($phase['examples_dap15_inf_inserted']) < 30) {
                $phase['examples_dap15_inf_inserted'][] = $candidate;
            }
        }

        foreach ($this->segCandidates as $candidate) {
            $key = $this->buildPairKey((int)$candidate['id_seg'], (int)$candidate['id_dot']);
            if (isset($this->targetSegLinks[$key])) {
                $phase['dap14_seg_skipped_existing']++;
                continue;
            }

            if ($this->apply) {
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->targetDb}`.dap14_seg_dot (id_seg, id_dot)
                    VALUES (?, ?)
                ");
                $stmt->bind_param('ii', $candidate['id_seg'], $candidate['id_dot']);
                $stmt->execute();
                $stmt->close();
            }

            $this->targetSegLinks[$key] = true;
            $phase['dap14_seg_inserted']++;
            if (count($phase['examples_dap14_seg_inserted']) < 30) {
                $phase['examples_dap14_seg_inserted'][] = $candidate;
            }
        }

        if ($this->apply) {
            $this->db->commit();
        }

        $phase['status'] = $this->apply ? 'apply_done' : 'dry_run_done';
        $this->report['migration'] = $phase;
        $this->logger->info('Migrazione completata', [
            'dap15_inf_inserted' => $phase['dap15_inf_inserted'],
            'dap14_seg_inserted' => $phase['dap14_seg_inserted'],
            'dap15_inf_skipped_existing' => $phase['dap15_inf_skipped_existing'],
            'dap14_seg_skipped_existing' => $phase['dap14_seg_skipped_existing'],
        ]);
    }

    private function resolveTargetPersonaleByUsernameAndTipo(string $username, int $tipo): ?array
    {
        $usernameNorm = $this->normalizeUsername($username);
        if ($usernameNorm === '' || $tipo <= 0) {
            return null;
        }

        $key = $this->buildUsernameTipoKey($usernameNorm, $tipo);
        return $this->targetPersonaleByUsernameTipo[$key] ?? null;
    }

    private function computeSourcePairCounts(): array
    {
        $counts = [
            'doctor_doctor' => 0,
            'doctor_nurse' => 0,
            'nurse_nurse' => 0,
            'secretary_related' => 0,
        ];

        foreach ($this->skipped as $row) {
            if (($row['reason'] ?? '') === 'doctor_doctor_pair_no_dap_table') {
                $counts['doctor_doctor']++;
            } elseif (($row['reason'] ?? '') === 'nurse_nurse_pair_no_dap_table') {
                $counts['nurse_nurse']++;
            } elseif (str_contains((string)($row['reason'] ?? ''), 'seg')) {
                $counts['secretary_related']++;
            }
        }

        $counts['doctor_nurse'] = count($this->infCandidates);
        $counts['secretary_related'] += count($this->segCandidates);

        return $counts;
    }

    private function auditCurrentTargetTables(): array
    {
        $duplicateExamples = [];
        $orphanExamples = [];

        $dap14Duplicates = (int)$this->scalar("
            SELECT COUNT(*) AS c
            FROM (
                SELECT id_seg, id_dot, COUNT(*) AS dup_count
                FROM `{$this->targetDb}`.dap14_seg_dot
                GROUP BY id_seg, id_dot
                HAVING COUNT(*) > 1
            ) d
        ");
        if ($dap14Duplicates > 0) {
            $res = $this->db->query("
                SELECT id_seg, id_dot, COUNT(*) AS dup_count
                FROM `{$this->targetDb}`.dap14_seg_dot
                GROUP BY id_seg, id_dot
                HAVING COUNT(*) > 1
                ORDER BY dup_count DESC, id_seg ASC, id_dot ASC
                LIMIT 20
            ");
            while ($row = $res->fetch_assoc()) {
                $duplicateExamples[] = ['table' => 'dap14_seg_dot'] + $row;
            }
        }

        $dap15Duplicates = (int)$this->scalar("
            SELECT COUNT(*) AS c
            FROM (
                SELECT id_inf, id_dot, COUNT(*) AS dup_count
                FROM `{$this->targetDb}`.dap15_inf_dot
                GROUP BY id_inf, id_dot
                HAVING COUNT(*) > 1
            ) d
        ");
        if ($dap15Duplicates > 0) {
            $res = $this->db->query("
                SELECT id_inf, id_dot, COUNT(*) AS dup_count
                FROM `{$this->targetDb}`.dap15_inf_dot
                GROUP BY id_inf, id_dot
                HAVING COUNT(*) > 1
                ORDER BY dup_count DESC, id_inf ASC, id_dot ASC
                LIMIT 20
            ");
            while ($row = $res->fetch_assoc()) {
                $duplicateExamples[] = ['table' => 'dap15_inf_dot'] + $row;
            }
        }

        $dap14Orphans = (int)$this->scalar("
            SELECT COUNT(*) AS c
            FROM `{$this->targetDb}`.dap14_seg_dot sd
            LEFT JOIN `{$this->targetDb}`.dap03_personale seg
                ON seg.id_personale = sd.id_seg AND seg.tipo = 3
            LEFT JOIN `{$this->targetDb}`.dap03_personale dot
                ON dot.id_personale = sd.id_dot AND dot.tipo = 1
            WHERE seg.id_personale IS NULL OR dot.id_personale IS NULL
        ");
        if ($dap14Orphans > 0) {
            $res = $this->db->query("
                SELECT sd.id_seg, sd.id_dot
                FROM `{$this->targetDb}`.dap14_seg_dot sd
                LEFT JOIN `{$this->targetDb}`.dap03_personale seg
                    ON seg.id_personale = sd.id_seg AND seg.tipo = 3
                LEFT JOIN `{$this->targetDb}`.dap03_personale dot
                    ON dot.id_personale = sd.id_dot AND dot.tipo = 1
                WHERE seg.id_personale IS NULL OR dot.id_personale IS NULL
                LIMIT 20
            ");
            while ($row = $res->fetch_assoc()) {
                $orphanExamples[] = ['table' => 'dap14_seg_dot'] + $row;
            }
        }

        $dap15Orphans = (int)$this->scalar("
            SELECT COUNT(*) AS c
            FROM `{$this->targetDb}`.dap15_inf_dot inf
            LEFT JOIN `{$this->targetDb}`.dap03_personale n
                ON n.id_personale = inf.id_inf AND n.tipo = 2
            LEFT JOIN `{$this->targetDb}`.dap03_personale dot
                ON dot.id_personale = inf.id_dot AND dot.tipo = 1
            WHERE n.id_personale IS NULL OR dot.id_personale IS NULL
        ");
        if ($dap15Orphans > 0) {
            $res = $this->db->query("
                SELECT inf.id_inf, inf.id_dot
                FROM `{$this->targetDb}`.dap15_inf_dot inf
                LEFT JOIN `{$this->targetDb}`.dap03_personale n
                    ON n.id_personale = inf.id_inf AND n.tipo = 2
                LEFT JOIN `{$this->targetDb}`.dap03_personale dot
                    ON dot.id_personale = inf.id_dot AND dot.tipo = 1
                WHERE n.id_personale IS NULL OR dot.id_personale IS NULL
                LIMIT 20
            ");
            while ($row = $res->fetch_assoc()) {
                $orphanExamples[] = ['table' => 'dap15_inf_dot'] + $row;
            }
        }

        return [
            'dap14_duplicates' => $dap14Duplicates,
            'dap15_duplicates' => $dap15Duplicates,
            'dap14_orphans' => $dap14Orphans,
            'dap15_orphans' => $dap15Orphans,
            'duplicate_examples' => $duplicateExamples,
            'orphan_examples' => $orphanExamples,
        ];
    }

    private function registerSkipped(string $reason, array $context): void
    {
        $entry = ['reason' => $reason] + $context;
        $this->skipped[] = $entry;
        if (count($this->skipped) <= 40) {
            $this->logger->warning('Visibilita legacy non importata', $entry);
        }
    }

    private function computeSkippedCounts(): array
    {
        $counts = [];
        foreach ($this->skipped as $row) {
            $reason = (string)($row['reason'] ?? 'unknown');
            $counts[$reason] = (int)($counts[$reason] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    private function scalar(string $sql): int
    {
        $row = $this->db->query($sql)->fetch_assoc();
        return (int)($row['c'] ?? 0);
    }

    private function isDoctorNursePair(int $leftRole, int $rightRole): bool
    {
        return ($leftRole === 3 && $rightRole === 5) || ($leftRole === 5 && $rightRole === 3);
    }

    private function isDoctorSecretaryPair(int $leftRole, int $rightRole): bool
    {
        return ($leftRole === 3 && $rightRole === 2) || ($leftRole === 2 && $rightRole === 3);
    }

    private function normalizeUsername(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function buildUsernameTipoKey(string $usernameNorm, int $tipo): string
    {
        return $usernameNorm . '|' . $tipo;
    }

    private function buildPairKey(int $left, int $right): string
    {
        return $left . '|' . $right;
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

        $lines = [];
        $lines[] = 'SYNC VISIBILITA FARMACIA -> DAP14_SEG_DOT / DAP15_INF_DOT';
        $lines[] = str_repeat('=', 64);
        $lines[] = 'Stato: ' . (string)($this->report['status'] ?? 'n/d');
        $lines[] = 'Modalita: ' . (string)($this->report['mode'] ?? 'n/d');
        $lines[] = 'Source DB: ' . (string)($this->report['source_db'] ?? 'n/d');
        $lines[] = 'Target DB: ' . (string)($this->report['target_db'] ?? 'n/d');
        $lines[] = 'Inizio: ' . (string)($this->report['started_at'] ?? 'n/d');
        $lines[] = 'Fine: ' . (string)($this->report['finished_at'] ?? 'n/d');
        $lines[] = 'Log: ' . (string)($this->report['log_path'] ?? '');
        $lines[] = 'JSON tecnico: ' . (string)($this->report['report_path'] ?? '');
        $lines[] = 'Summary HTML: ' . (string)($this->report['summary_html_path'] ?? '');
        $lines[] = '';

        $lines[] = 'FILTRI';
        $lines[] = '------';
        $lines[] = 'source_dots: ' . $this->formatListForSummary(($this->report['filters']['source_dots'] ?? []));
        $lines[] = '';

        $lines[] = 'NUMERI PRINCIPALI';
        $lines[] = '-----------------';
        $lines[] = 'Entita far03 analizzate: ' . $this->summaryNumber($audit, 'source_entities_far03_total');
        $lines[] = 'Righe far10_vis_dot: ' . $this->summaryNumber($audit, 'source_far10_vis_dot_rows');
        $lines[] = 'Pair legacy dottore-dottore: ' . $this->summaryNumber($audit, 'source_doctor_doctor_pairs');
        $lines[] = 'Pair legacy dottore-infermiere: ' . $this->summaryNumber($audit, 'source_doctor_nurse_pairs');
        $lines[] = 'Pair legacy infermiere-infermiere: ' . $this->summaryNumber($audit, 'source_nurse_nurse_pairs');
        $lines[] = 'Pair legacy segreteria rilevati: ' . $this->summaryNumber($audit, 'source_secretary_pairs_detected');
        $lines[] = 'Righe esistenti dap14_seg_dot: ' . $this->summaryNumber($audit, 'target_dap14_existing_rows');
        $lines[] = 'Righe esistenti dap15_inf_dot: ' . $this->summaryNumber($audit, 'target_dap15_existing_rows');
        $lines[] = 'Duplicati attuali dap14: ' . $this->summaryNumber($audit, 'target_dap14_duplicate_pairs');
        $lines[] = 'Duplicati attuali dap15: ' . $this->summaryNumber($audit, 'target_dap15_duplicate_pairs');
        $lines[] = 'Orfani attuali dap14: ' . $this->summaryNumber($audit, 'target_dap14_orphan_rows');
        $lines[] = 'Orfani attuali dap15: ' . $this->summaryNumber($audit, 'target_dap15_orphan_rows');
        $lines[] = '';

        $lines[] = 'CANDIDATI MAPPATI';
        $lines[] = '-----------------';
        $lines[] = 'Candidati dap15_inf_dot totali: ' . $this->summaryNumber($audit, 'inf_candidates_total');
        $lines[] = 'Candidati dap15 gia esistenti: ' . $this->summaryNumber($audit, 'inf_candidates_existing');
        $lines[] = 'Candidati dap15 mancanti: ' . $this->summaryNumber($audit, 'inf_candidates_missing');
        $lines[] = 'Candidati dap14_seg_dot totali: ' . $this->summaryNumber($audit, 'seg_candidates_total');
        $lines[] = 'Candidati dap14 gia esistenti: ' . $this->summaryNumber($audit, 'seg_candidates_existing');
        $lines[] = 'Candidati dap14 mancanti: ' . $this->summaryNumber($audit, 'seg_candidates_missing');
        $lines[] = '';

        $lines[] = 'AZIONI PREVISTE / ESEGUITE';
        $lines[] = '-------------------------';
        $lines[] = 'Nuovi link dap15 inseriti: ' . $this->summaryNumber($migration, 'dap15_inf_inserted');
        $lines[] = 'Link dap15 saltati perche esistenti: ' . $this->summaryNumber($migration, 'dap15_inf_skipped_existing');
        $lines[] = 'Nuovi link dap14 inseriti: ' . $this->summaryNumber($migration, 'dap14_seg_inserted');
        $lines[] = 'Link dap14 saltati perche esistenti: ' . $this->summaryNumber($migration, 'dap14_seg_skipped_existing');
        $lines[] = '';

        $lines[] = 'SCARTI LEGACY';
        $lines[] = '-------------';
        foreach (($audit['skipped_counts'] ?? []) as $reason => $count) {
            $lines[] = '- ' . $reason . ': ' . $count;
        }
        if (($audit['skipped_counts'] ?? []) === []) {
            $lines[] = '- Nessuno';
        }
        $lines[] = '';

        $this->appendSummaryExamples(
            $lines,
            'ESEMPI LINK DAP15 MANCANTI',
            $audit['examples_inf_candidates_missing'] ?? [],
            20,
            fn(array $row): string => $this->formatInfCandidateExample($row)
        );

        $this->appendSummaryExamples(
            $lines,
            'ESEMPI LINK DAP14 MANCANTI',
            $audit['examples_seg_candidates_missing'] ?? [],
            20,
            fn(array $row): string => $this->formatSegCandidateExample($row)
        );

        $this->appendSummaryExamples(
            $lines,
            'ESEMPI SCARTI LEGACY',
            $audit['examples_skipped'] ?? [],
            20,
            fn(array $row): string => $this->formatSkippedExample($row)
        );

        $this->appendSummaryExamples(
            $lines,
            'ESEMPI ANOMALIE TARGET',
            array_merge($audit['examples_target_duplicates'] ?? [], $audit['examples_target_orphans'] ?? []),
            20,
            fn(array $row): string => $this->formatTargetIssueExample($row)
        );

        if (($this->report['status'] ?? '') === 'error') {
            $error = $this->report['error'] ?? [];
            $lines[] = 'ERRORE';
            $lines[] = '------';
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
        $skippedCounts = $audit['skipped_counts'] ?? [];

        $html = '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Summary visibilita dap</title>'
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

        $html .= '<div class="box"><h1>Sync visibilita farmacia -> dap14_seg_dot / dap15_inf_dot</h1>'
            . '<p><strong>Stato:</strong> ' . $this->html((string)($this->report['status'] ?? 'n/d')) . '</p>'
            . '<p><strong>Modalita:</strong> ' . $this->html((string)($this->report['mode'] ?? 'n/d')) . '</p>'
            . '<p><strong>Source DB:</strong> ' . $this->html((string)($this->report['source_db'] ?? 'n/d')) . '<br>'
            . '<strong>Target DB:</strong> ' . $this->html((string)($this->report['target_db'] ?? 'n/d')) . '</p>'
            . '<p><strong>Log:</strong> <code>' . $this->html((string)($this->report['log_path'] ?? '')) . '</code><br>'
            . '<strong>JSON tecnico:</strong> <code>' . $this->html((string)($this->report['report_path'] ?? '')) . '</code></p>'
            . '</div>';

        $html .= '<div class="box"><h2>Numeri principali</h2><table><tbody>'
            . $this->metricRow('Entita far03 analizzate', $this->summaryNumber($audit, 'source_entities_far03_total'))
            . $this->metricRow('Righe far10_vis_dot', $this->summaryNumber($audit, 'source_far10_vis_dot_rows'))
            . $this->metricRow('Pair legacy dottore-dottore', $this->summaryNumber($audit, 'source_doctor_doctor_pairs'))
            . $this->metricRow('Pair legacy dottore-infermiere', $this->summaryNumber($audit, 'source_doctor_nurse_pairs'))
            . $this->metricRow('Pair legacy infermiere-infermiere', $this->summaryNumber($audit, 'source_nurse_nurse_pairs'))
            . $this->metricRow('Pair legacy segreteria rilevati', $this->summaryNumber($audit, 'source_secretary_pairs_detected'))
            . $this->metricRow('Righe esistenti dap14_seg_dot', $this->summaryNumber($audit, 'target_dap14_existing_rows'))
            . $this->metricRow('Righe esistenti dap15_inf_dot', $this->summaryNumber($audit, 'target_dap15_existing_rows'))
            . '</tbody></table></div>';

        $html .= '<div class="box"><h2>Candidati mappati</h2><table><tbody>'
            . $this->metricRow('Candidati dap15 totali', $this->summaryNumber($audit, 'inf_candidates_total'))
            . $this->metricRow('Candidati dap15 mancanti', $this->summaryNumber($audit, 'inf_candidates_missing'))
            . $this->metricRow('Candidati dap14 totali', $this->summaryNumber($audit, 'seg_candidates_total'))
            . $this->metricRow('Candidati dap14 mancanti', $this->summaryNumber($audit, 'seg_candidates_missing'))
            . $this->metricRow('Nuovi link dap15 inseriti', $this->summaryNumber($migration, 'dap15_inf_inserted'))
            . $this->metricRow('Nuovi link dap14 inseriti', $this->summaryNumber($migration, 'dap14_seg_inserted'))
            . '</tbody></table></div>';

        $html .= '<div class="box"><h2>Scarti legacy</h2>';
        if ($skippedCounts === []) {
            $html .= '<p>Nessuno</p>';
        } else {
            $html .= '<ul>';
            foreach ($skippedCounts as $reason => $count) {
                $html .= '<li>' . $this->html($reason . ': ' . $count) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div>';

        $html .= $this->buildHtmlExampleSection(
            'Esempi link dap15 mancanti',
            $audit['examples_inf_candidates_missing'] ?? [],
            20,
            fn(array $row): string => $this->formatInfCandidateExample($row)
        );
        $html .= $this->buildHtmlExampleSection(
            'Esempi link dap14 mancanti',
            $audit['examples_seg_candidates_missing'] ?? [],
            20,
            fn(array $row): string => $this->formatSegCandidateExample($row)
        );
        $html .= $this->buildHtmlExampleSection(
            'Esempi scarti legacy',
            $audit['examples_skipped'] ?? [],
            20,
            fn(array $row): string => $this->formatSkippedExample($row)
        );
        $html .= $this->buildHtmlExampleSection(
            'Esempi anomalie target',
            array_merge($audit['examples_target_duplicates'] ?? [], $audit['examples_target_orphans'] ?? []),
            20,
            fn(array $row): string => $this->formatTargetIssueExample($row)
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

    private function formatInfCandidateExample(array $row): string
    {
        return 'id_inf=' . (int)($row['id_inf'] ?? 0)
            . ' | id_dot=' . (int)($row['id_dot'] ?? 0)
            . ' | nurse=' . (string)($row['nurse_username'] ?? '')
            . ' | doctor=' . (string)($row['doctor_username'] ?? '')
            . ' | source_master_dot=' . (int)($row['source_master_dot'] ?? 0)
            . ' | source_visible_dot=' . (int)($row['source_visible_dot'] ?? 0);
    }

    private function formatSegCandidateExample(array $row): string
    {
        return 'id_seg=' . (int)($row['id_seg'] ?? 0)
            . ' | id_dot=' . (int)($row['id_dot'] ?? 0)
            . ' | segreteria=' . (string)($row['secretary_username'] ?? '')
            . ' | doctor=' . (string)($row['doctor_username'] ?? '')
            . ' | source_master_dot=' . (int)($row['source_master_dot'] ?? 0)
            . ' | source_visible_dot=' . (int)($row['source_visible_dot'] ?? 0);
    }

    private function formatSkippedExample(array $row): string
    {
        $parts = ['reason=' . (string)($row['reason'] ?? '')];
        foreach ($row as $key => $value) {
            if ($key === 'reason') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $parts[] = $key . '=' . (string)$value;
            }
        }

        return implode(' | ', $parts);
    }

    private function formatTargetIssueExample(array $row): string
    {
        $parts = ['table=' . (string)($row['table'] ?? '')];
        foreach ($row as $key => $value) {
            if ($key === 'table') {
                continue;
            }
            $parts[] = $key . '=' . (string)$value;
        }
        return implode(' | ', $parts);
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
