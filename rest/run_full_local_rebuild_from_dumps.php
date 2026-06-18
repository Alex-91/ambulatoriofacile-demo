<?php
declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '1024M');
ignore_user_abort(true);
date_default_timezone_set('Europe/Rome');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const ORCH_DEFAULT_SOURCE_DB = 'farmacia';
const ORCH_DEFAULT_TARGET_DB = 'mail';
const ORCH_DEFAULT_REPORT_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'full_rebuild';
const ORCH_DEFAULT_FUTURE_HORIZON_MONTHS = 18;
const ORCH_DEFAULT_AGENDA_DOCTOR_BATCH_SIZE = 4;
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
    ensureDirectory((string)$options['report_dir']);

    $stamp = date('Ymd_His');
    $baseDir = (string)$options['report_dir'] . DIRECTORY_SEPARATOR . 'full_rebuild_' . $stamp;
    ensureDirectory($baseDir);

    $logPath = $baseDir . DIRECTORY_SEPARATOR . 'full_rebuild.log';
    $reportPath = $baseDir . DIRECTORY_SEPARATOR . 'full_rebuild.json';
    $logger = new FullRebuildLogger($logPath);

    $env = loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');
    $dbConfig = buildDbConfig($env, $options);

    $logger->info('Avvio orchestrazione rebuild locale', [
        'mode' => !empty($options['apply']) ? 'apply' : 'dry-run',
        'source_db' => $dbConfig['source_db'],
        'target_db' => $dbConfig['target_db'],
        'report_dir' => $baseDir,
    ]);

    $report = [
        'started_at' => date('c'),
        'mode' => !empty($options['apply']) ? 'apply' : 'dry-run',
        'source_db' => $dbConfig['source_db'],
        'target_db' => $dbConfig['target_db'],
        'report_dir' => $baseDir,
        'log_path' => $logPath,
        'report_path' => $reportPath,
        'detected_windows' => [],
        'steps' => [],
        'status' => 'running',
    ];

    try {
        $windows = detectLegacyWindows($dbConfig, $options, $logger);
        $report['detected_windows'] = $windows;

        $steps = buildSteps($options, $dbConfig, $windows, $baseDir);
        foreach ($steps as $step) {
            $stepResult = runConfiguredStep($step, $logger, !empty($options['apply']), $dbConfig);
            $report['steps'][] = $stepResult;

            if (($stepResult['status'] ?? '') !== 'ok' && ($stepResult['status'] ?? '') !== 'skipped') {
                throw new RuntimeException('Step fallito: ' . ($step['name'] ?? 'unknown'));
            }
        }

        $report['finished_at'] = date('c');
        $report['status'] = 'ok';
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $logger->info('Rebuild locale completato', [
            'report_path' => $reportPath,
            'log_path' => $logPath,
        ]);
        exit(0);
    } catch (\Throwable $e) {
        $report['finished_at'] = date('c');
        $report['status'] = 'error';
        $report['error'] = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $logger->error('Rebuild locale fallito', $report['error'] + ['report_path' => $reportPath]);
        exit(1);
    }
}

function parseCliOptions(array $argv): array
{
    return [
        'apply' => hasFlag($argv, '--apply'),
        'host' => optionValue($argv, 'host'),
        'port' => (int)(optionValue($argv, 'port') ?: 3306),
        'user' => optionValue($argv, 'user'),
        'pass' => optionValue($argv, 'pass'),
        'source_db' => optionValue($argv, 'source-db') ?: ORCH_DEFAULT_SOURCE_DB,
        'target_db' => optionValue($argv, 'target-db') ?: ORCH_DEFAULT_TARGET_DB,
        'report_dir' => optionValue($argv, 'report-dir') ?: ORCH_DEFAULT_REPORT_DIR,
        'appointments_from' => optionValue($argv, 'appointments-from'),
        'blocked_from' => optionValue($argv, 'blocked-from'),
        'notes_from' => optionValue($argv, 'notes-from'),
        'structure_from' => optionValue($argv, 'structure-from'),
        'structure_to' => optionValue($argv, 'structure-to'),
        'future_horizon_months' => max(1, (int)(optionValue($argv, 'future-horizon-months') ?: ORCH_DEFAULT_FUTURE_HORIZON_MONTHS)),
        'agenda_doctor_batch_size' => max(0, (int)(optionValue($argv, 'agenda-doctor-batch-size') ?: ORCH_DEFAULT_AGENDA_DOCTOR_BATCH_SIZE)),
        'reset_agenda' => hasFlag($argv, '--reset-agenda') || optionValue($argv, 'reset-agenda') === '1',
        'doctors' => parseCsvInts(optionValue($argv, 'doctors')),
    ];
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

    $argv = ['run_full_local_rebuild_from_dumps.php'];
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
        'report-dir',
        'appointments-from',
        'blocked-from',
        'notes-from',
        'structure-from',
        'structure-to',
        'future-horizon-months',
        'agenda-doctor-batch-size',
        'reset-agenda',
        'doctors',
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
    $self = $_SERVER['PHP_SELF'] ?? 'run_full_local_rebuild_from_dumps.php';
    $base = $self !== '' ? $self : 'run_full_local_rebuild_from_dumps.php';
    $html = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Rebuild Locale Completo</title>
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
        p, li { line-height: 1.45; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Rebuild locale completo</h1>
        <p>Questo orchestratore rilancia in sequenza tutte le operazioni gia consolidate: migration schema, sync staff, visibilita, pazienti, rebuild agenda completo e sync <code>paz_spec</code>.</p>
        <p>I log e i report vengono salvati in <code>writable/full_rebuild</code>. Il progetto nuovo continua a lavorare solo su <code>mail</code>; <code>source-db</code> serve solo come sorgente temporanea del dump legacy.</p>
        <ul>
            <li><code>Dry-run</code>: analizza tutto e non scrive sul DB target.</li>
            <li><code>Apply reale</code>: esegue davvero tutti gli step in sequenza.</li>
        </ul>
    </div>

    <div class="box">
        <h2>Dry-run</h2>
        <form method="get" action="{$base}">
            <input type="hidden" name="run" value="1">
            <div class="grid">
                <div>
                    <label for="source-db">source-db</label>
                    <input id="source-db" type="text" name="source-db" value="farmacia">
                </div>
                <div>
                    <label for="target-db">target-db</label>
                    <input id="target-db" type="text" name="target-db" value="mail">
                </div>
                <div>
                    <label for="doctors">doctors</label>
                    <input id="doctors" type="text" name="doctors" value="" placeholder="es. 22,23">
                </div>
                <div>
                    <label for="future-horizon-months">future-horizon-months</label>
                    <input id="future-horizon-months" type="text" name="future-horizon-months" value="18">
                </div>
                <div>
                    <label for="agenda-doctor-batch-size">agenda-doctor-batch-size</label>
                    <input id="agenda-doctor-batch-size" type="text" name="agenda-doctor-batch-size" value="4">
                </div>
                <div>
                    <label for="reset-agenda">reset-agenda</label>
                    <input id="reset-agenda" type="text" name="reset-agenda" value="0" placeholder="0 oppure 1">
                </div>
                <div>
                    <label for="appointments-from">appointments-from</label>
                    <input id="appointments-from" type="text" name="appointments-from" value="" placeholder="auto">
                </div>
                <div>
                    <label for="blocked-from">blocked-from</label>
                    <input id="blocked-from" type="text" name="blocked-from" value="" placeholder="auto">
                </div>
                <div>
                    <label for="notes-from">notes-from</label>
                    <input id="notes-from" type="text" name="notes-from" value="" placeholder="auto">
                </div>
                <div>
                    <label for="structure-from">structure-from</label>
                    <input id="structure-from" type="text" name="structure-from" value="" placeholder="auto">
                </div>
                <div>
                    <label for="structure-to">structure-to</label>
                    <input id="structure-to" type="text" name="structure-to" value="" placeholder="auto">
                </div>
                <div>
                    <label for="report-dir">report-dir</label>
                    <input id="report-dir" type="text" name="report-dir" value="" placeholder="default writable/full_rebuild">
                </div>
            </div>
            <div class="actions">
                <button class="dry" type="submit">Esegui dry-run completo</button>
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
                    <input id="apply-source-db" type="text" name="source-db" value="farmacia">
                </div>
                <div>
                    <label for="apply-target-db">target-db</label>
                    <input id="apply-target-db" type="text" name="target-db" value="mail">
                </div>
                <div>
                    <label for="apply-doctors">doctors</label>
                    <input id="apply-doctors" type="text" name="doctors" value="" placeholder="es. 22,23">
                </div>
                <div>
                    <label for="apply-future-horizon-months">future-horizon-months</label>
                    <input id="apply-future-horizon-months" type="text" name="future-horizon-months" value="18">
                </div>
                <div>
                    <label for="apply-agenda-doctor-batch-size">agenda-doctor-batch-size</label>
                    <input id="apply-agenda-doctor-batch-size" type="text" name="agenda-doctor-batch-size" value="4">
                </div>
                <div>
                    <label for="apply-reset-agenda">reset-agenda</label>
                    <input id="apply-reset-agenda" type="text" name="reset-agenda" value="1" placeholder="0 oppure 1">
                </div>
                <div>
                    <label for="apply-appointments-from">appointments-from</label>
                    <input id="apply-appointments-from" type="text" name="appointments-from" value="" placeholder="auto">
                </div>
                <div>
                    <label for="apply-blocked-from">blocked-from</label>
                    <input id="apply-blocked-from" type="text" name="blocked-from" value="" placeholder="auto">
                </div>
                <div>
                    <label for="apply-notes-from">notes-from</label>
                    <input id="apply-notes-from" type="text" name="notes-from" value="" placeholder="auto">
                </div>
                <div>
                    <label for="apply-structure-from">structure-from</label>
                    <input id="apply-structure-from" type="text" name="structure-from" value="" placeholder="auto">
                </div>
                <div>
                    <label for="apply-structure-to">structure-to</label>
                    <input id="apply-structure-to" type="text" name="structure-to" value="" placeholder="auto">
                </div>
                <div>
                    <label for="apply-report-dir">report-dir</label>
                    <input id="apply-report-dir" type="text" name="report-dir" value="" placeholder="default writable/full_rebuild">
                </div>
                <div>
                    <label for="confirm-phrase">confirm_phrase</label>
                    <input id="confirm-phrase" type="text" name="confirm_phrase" value="" placeholder="__CONFIRM_PHRASE__">
                </div>
            </div>
            <p>Per confermare devi scrivere esattamente: <code>APPLICA_SU_MAIL</code></p>
            <div class="actions">
                <button class="apply" type="submit">Esegui apply completo</button>
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

function buildDbConfig(array $env, array $options): array
{
    return [
        'host' => (string)($options['host'] ?: ($env['database.default.hostname'] ?? 'localhost')),
        'port' => (int)($options['port'] ?: ($env['database.default.port'] ?? 3306)),
        'user' => (string)($options['user'] ?: ($env['database.default.username'] ?? 'root')),
        'pass' => (string)($options['pass'] ?: ($env['database.default.password'] ?? 'root')),
        'source_db' => (string)$options['source_db'],
        'target_db' => (string)$options['target_db'],
    ];
}

function detectLegacyWindows(array $dbConfig, array $options, FullRebuildLogger $logger): array
{
    $db = new mysqli(
        (string)$dbConfig['host'],
        (string)$dbConfig['user'],
        (string)$dbConfig['pass'],
        (string)$dbConfig['source_db'],
        (int)$dbConfig['port']
    );
    $db->set_charset('latin1');

    try {
        $appointmentsMin = (string)firstNonEmpty([
            normalizeDateString(queryScalarDate($db, "
                SELECT MIN(DATE(a.data_ora_ini)) AS d
                FROM `{$dbConfig['source_db']}`.far06_appuntamenti a
                INNER JOIN `{$dbConfig['source_db']}`.far08_prenotazioni p
                    ON p.id_appuntamento = a.id_appuntamento
            ")),
        ], date('Y-m-d'));

        $appointmentsMax = (string)firstNonEmpty([
            normalizeDateString(queryScalarDate($db, "
                SELECT MAX(DATE(a.data_ora_ini)) AS d
                FROM `{$dbConfig['source_db']}`.far06_appuntamenti a
                INNER JOIN `{$dbConfig['source_db']}`.far08_prenotazioni p
                    ON p.id_appuntamento = a.id_appuntamento
            ")),
        ], $appointmentsMin);

        $notesCandidates = [
            normalizeDateString(queryScalarDate($db, "SELECT MIN(STR_TO_DATE(giorno, '%d/%m/%Y')) AS d FROM `{$dbConfig['source_db']}`.far21_note")),
            normalizeDateString(queryScalarDate($db, "SELECT MIN(DATE(data_ins)) AS d FROM `{$dbConfig['source_db']}`.far29_not_dot")),
            normalizeDateString(queryScalarDate($db, "SELECT MIN(DATE(giorno)) AS d FROM `{$dbConfig['source_db']}`.far11_vis_dom")),
        ];
        $blockedCandidates = [
            normalizeDateString(queryScalarDate($db, "SELECT MIN(STR_TO_DATE(giorno, '%d/%m/%Y')) AS d FROM `{$dbConfig['source_db']}`.far20_stampa")),
            normalizeDateString(queryScalarDate($db, "SELECT MIN(STR_TO_DATE(giorno, '%d/%m/%Y')) AS d FROM `{$dbConfig['source_db']}`.far37_block_memo")),
            normalizeDateString(queryScalarDate($db, "SELECT MIN(STR_TO_DATE(giorno, '%d/%m/%Y')) AS d FROM `{$dbConfig['source_db']}`.far31_block_dom")),
        ];

        $futureHorizonDate = date('Y-m-d', strtotime('+' . (int)$options['future_horizon_months'] . ' months'));
        $autoStructureFromFloor = date('Y-m-01', strtotime('-6 months'));
        $autoStructureFrom = maxDateString($appointmentsMin, $autoStructureFromFloor);
        $autoStructureTo = maxDateString($appointmentsMax, $futureHorizonDate);

        $appointmentsFrom = normalizeDateString((string)($options['appointments_from'] ?? '')) ?: $appointmentsMin;
        $notesFrom = normalizeDateString((string)($options['notes_from'] ?? '')) ?: (string)firstNonEmpty($notesCandidates, $appointmentsFrom);
        $blockedFrom = normalizeDateString((string)($options['blocked_from'] ?? '')) ?: (string)firstNonEmpty($blockedCandidates, $notesFrom);
        $structureFrom = normalizeDateString((string)($options['structure_from'] ?? '')) ?: $autoStructureFrom;
        $structureTo = normalizeDateString((string)($options['structure_to'] ?? '')) ?: $autoStructureTo;

        if ($structureTo < $structureFrom) {
            $structureTo = $structureFrom;
        }

        $windows = [
            'appointments_from' => $appointmentsFrom,
            'appointments_max_detected' => $appointmentsMax,
            'blocked_from' => $blockedFrom,
            'notes_from' => $notesFrom,
            'structure_from' => $structureFrom,
            'structure_to' => $structureTo,
            'future_horizon_date' => $futureHorizonDate,
            'auto_structure_from_floor' => $autoStructureFromFloor,
            'source_db' => $dbConfig['source_db'],
        ];

        $logger->info('Finestre legacy rilevate', $windows);
        return $windows;
    } finally {
        $db->close();
    }
}

function buildSteps(array $options, array $dbConfig, array $windows, string $baseDir): array
{
    $php = resolvePhpBinary();
    $doctorFilter = csvOrNull($options['doctors']);
    $agendaDoctorBatchSize = max(0, (int)($options['agenda_doctor_batch_size'] ?? ORCH_DEFAULT_AGENDA_DOCTOR_BATCH_SIZE));

    $commonDbArgs = [];
    if ($options['host']) {
        $commonDbArgs[] = '--host=' . (string)$dbConfig['host'];
    }
    if ($options['port']) {
        $commonDbArgs[] = '--port=' . (string)$dbConfig['port'];
    }
    if ($options['user']) {
        $commonDbArgs[] = '--user=' . (string)$dbConfig['user'];
    }
    if ($options['pass']) {
        $commonDbArgs[] = '--pass=' . (string)$dbConfig['pass'];
    }

    $steps = [];

    $steps[] = [
        'name' => 'schema_migrations',
        'description' => 'Applica tutte le migration pendenti',
        'skip_when_dry_run' => true,
        'command' => [$php, 'spark', 'migrate'],
    ];

    $staffArgs = array_merge(
        [$php, 'migrate_mail_far_staff_to_dap.php'],
        !empty($options['apply']) ? ['--apply'] : [],
        $commonDbArgs,
        ['--db=' . (string)$dbConfig['target_db'], '--report-dir=' . $baseDir . DIRECTORY_SEPARATOR . 'staff']
    );
    $steps[] = [
        'name' => 'staff_to_dap',
        'description' => 'Allinea far01_ope verso dap01_users e dap03_personale',
        'command' => $staffArgs,
    ];

    $visibilityArgs = array_merge(
        [$php, 'migrate_farmacia_visibility_to_mail_dap.php'],
        !empty($options['apply']) ? ['--apply'] : [],
        $commonDbArgs,
        [
            '--source-db=' . (string)$dbConfig['source_db'],
            '--target-db=' . (string)$dbConfig['target_db'],
            '--report-dir=' . $baseDir . DIRECTORY_SEPARATOR . 'visibility',
        ]
    );
    if ($doctorFilter !== null) {
        $visibilityArgs[] = '--source-dots=' . $doctorFilter;
    }
    $steps[] = [
        'name' => 'visibility_to_dap',
        'description' => 'Importa visibilita legacy verso dap14/dap15',
        'command' => $visibilityArgs,
    ];

    $patientsArgs = array_merge(
        [$php, 'migrate_mail_far_patients_to_dap.php'],
        !empty($options['apply']) ? ['--apply'] : [],
        $commonDbArgs,
        [
            '--db=' . (string)$dbConfig['target_db'],
            '--source-db=' . (string)$dbConfig['source_db'],
            '--report-dir=' . $baseDir . DIRECTORY_SEPARATOR . 'patients',
        ]
    );
    if ($doctorFilter !== null) {
        $patientsArgs[] = '--doctors=' . $doctorFilter;
    }
    $steps[] = [
        'name' => 'patients_to_dap',
        'description' => 'Allinea far05 verso dap02_clients e dap09_client_doctor',
        'command' => $patientsArgs,
    ];

    $steps[] = [
        'name' => 'agenda_full_rebuild',
        'description' => 'Ricostruisce struttura agenda e reimporta note, memo, domiciliari, blocchi e appuntamenti',
        'kind' => 'agenda_batched',
        'php' => $php,
        'apply' => !empty($options['apply']),
        'common_db_args' => $commonDbArgs,
        'source_db' => (string)$dbConfig['source_db'],
        'target_db' => (string)$dbConfig['target_db'],
        'appointments_from' => (string)$windows['appointments_from'],
        'blocked_from' => (string)$windows['blocked_from'],
        'notes_from' => (string)$windows['notes_from'],
        'structure_from' => (string)$windows['structure_from'],
        'structure_to' => (string)$windows['structure_to'],
        'doctor_filter' => $options['doctors'],
        'doctor_batch_size' => $agendaDoctorBatchSize,
        'reset_agenda' => !empty($options['reset_agenda']),
        'report_dir' => $baseDir . DIRECTORY_SEPARATOR . 'agenda',
    ];

    $pazSpecArgs = array_merge(
        [$php, 'sync_farmacia_paz_spec_to_mail.php'],
        !empty($options['apply']) ? ['--apply'] : [],
        $commonDbArgs,
        [
            '--source-db=' . (string)$dbConfig['source_db'],
            '--target-db=' . (string)$dbConfig['target_db'],
            '--report-dir=' . $baseDir . DIRECTORY_SEPARATOR . 'paz_spec',
        ]
    );
    $steps[] = [
        'name' => 'paz_spec_sync',
        'description' => 'Allinea i pazienti speciali su dap02_clients',
        'command' => $pazSpecArgs,
    ];

    return $steps;
}

function runStep(array $step, FullRebuildLogger $logger, bool $applyMode): array
{
    $startedAt = date('c');
    $command = buildCommandString((array)$step['command']);

    if (!$applyMode && !empty($step['skip_when_dry_run'])) {
        $logger->info('Step saltato in dry-run', [
            'step' => $step['name'],
            'command' => $command,
        ]);
        return [
            'name' => $step['name'],
            'description' => $step['description'],
            'command' => $command,
            'started_at' => $startedAt,
            'finished_at' => date('c'),
            'status' => 'skipped',
            'output_tail' => [],
        ];
    }

    $logger->info('Avvio step', [
        'step' => $step['name'],
        'description' => $step['description'],
        'command' => $command,
    ]);

    $tail = [];
    $exitCode = 1;
    $handle = popen($command . ' 2>&1', 'r');
    if ($handle === false) {
        throw new RuntimeException('Impossibile eseguire il comando: ' . $command);
    }

    while (!feof($handle)) {
        $line = fgets($handle);
        if ($line === false) {
            break;
        }
        $line = rtrim($line, "\r\n");
        echo $line . PHP_EOL;
        $logger->raw('[' . $step['name'] . '] ' . $line);
        $tail[] = $line;
        if (count($tail) > 80) {
            array_shift($tail);
        }
    }
    $exitCode = pclose($handle);

    $fatalOutputPatterns = [
        'your php version must be 8.1 or higher',
        'php fatal error',
        'uncaught exception',
        'uncaught error',
        'could not open input file: spark',
    ];

    $fatalOutputDetected = false;
    foreach ($tail as $line) {
        $normalized = strtolower($line);
        foreach ($fatalOutputPatterns as $pattern) {
            if (strpos($normalized, $pattern) !== false) {
                $fatalOutputDetected = true;
                break 2;
            }
        }
    }

    if ($fatalOutputDetected && $exitCode === 0) {
        $exitCode = 1;
    }

    $status = $exitCode === 0 ? 'ok' : 'error';
    $logger->info('Step terminato', [
        'step' => $step['name'],
        'status' => $status,
        'exit_code' => $exitCode,
    ]);

    return [
        'name' => $step['name'],
        'description' => $step['description'],
        'command' => $command,
        'started_at' => $startedAt,
        'finished_at' => date('c'),
        'status' => $status,
        'exit_code' => $exitCode,
        'output_tail' => $tail,
    ];
}

function runConfiguredStep(array $step, FullRebuildLogger $logger, bool $applyMode, array $dbConfig): array
{
    $kind = (string)($step['kind'] ?? 'command');
    if ($kind === 'agenda_batched') {
        return runAgendaBatchedStep($step, $logger, $applyMode, $dbConfig);
    }

    return runStep($step, $logger, $applyMode);
}

function runAgendaBatchedStep(array $step, FullRebuildLogger $logger, bool $applyMode, array $dbConfig): array
{
    $startedAt = date('c');
    $doctorFilter = array_values(array_unique(array_map('intval', (array)($step['doctor_filter'] ?? []))));
    $doctorIds = resolveAgendaDoctorIdsForBatches($dbConfig, $doctorFilter);
    $batchSize = max(0, (int)($step['doctor_batch_size'] ?? ORCH_DEFAULT_AGENDA_DOCTOR_BATCH_SIZE));
    if ($batchSize <= 0) {
        $batchSize = max(1, count($doctorIds));
    }

    if ($doctorIds === []) {
        $logger->info('Step agenda saltato: nessun dottore disponibile per il rebuild', [
            'step' => $step['name'],
        ]);

        return [
            'name' => $step['name'],
            'description' => $step['description'],
            'started_at' => $startedAt,
            'finished_at' => date('c'),
            'status' => 'skipped',
            'doctor_count' => 0,
            'batch_size' => $batchSize,
            'batch_count' => 0,
            'output_tail' => [],
        ];
    }

    $batches = array_chunk($doctorIds, $batchSize);
    $logger->info('Avvio step agenda in batch per dottore', [
        'step' => $step['name'],
        'doctor_count' => count($doctorIds),
        'batch_size' => $batchSize,
        'batch_count' => count($batches),
        'structure_from' => $step['structure_from'] ?? null,
        'structure_to' => $step['structure_to'] ?? null,
    ]);

    $substeps = [];
    $allTail = [];

    foreach ($batches as $index => $batchDoctorIds) {
        $batchName = sprintf('%s_batch_%03d', (string)$step['name'], $index + 1);
        $batchDir = (string)$step['report_dir'] . DIRECTORY_SEPARATOR . sprintf('batch_%03d', $index + 1);
        $command = array_merge(
            [(string)$step['php'], 'migrate_legacy_agenda_to_mail.php'],
            !empty($step['apply']) ? ['--apply'] : [],
            (array)($step['common_db_args'] ?? []),
            [
                '--source-db=' . (string)$step['source_db'],
                '--target-db=' . (string)$step['target_db'],
                '--appointments-from=' . (string)$step['appointments_from'],
                '--blocked-from=' . (string)$step['blocked_from'],
                '--notes-from=' . (string)$step['notes_from'],
                '--structure-from=' . (string)$step['structure_from'],
                '--structure-to=' . (string)$step['structure_to'],
                '--rebuild-structure=1',
                '--doctors=' . implode(',', array_map('strval', $batchDoctorIds)),
                '--report-dir=' . $batchDir,
            ]
        );

        if ($index === 0) {
            $command[] = '--drop-target-far15=1';
            if (!empty($step['reset_agenda'])) {
                $command[] = '--reset-target-agenda=1';
            }
        }

        $logger->info('Avvio batch agenda', [
            'batch_index' => $index + 1,
            'batch_count' => count($batches),
            'doctor_ids' => $batchDoctorIds,
        ]);

        $substep = runStep([
            'name' => $batchName,
            'description' => sprintf('Ricostruisce agenda per batch %d/%d', $index + 1, count($batches)),
            'command' => $command,
        ], $logger, $applyMode);

        $substep['doctor_ids'] = $batchDoctorIds;
        $substeps[] = $substep;

        foreach ((array)($substep['output_tail'] ?? []) as $line) {
            $allTail[] = $line;
            if (count($allTail) > 120) {
                array_shift($allTail);
            }
        }

        if (($substep['status'] ?? '') !== 'ok' && ($substep['status'] ?? '') !== 'skipped') {
            $logger->error('Batch agenda fallito', [
                'batch_index' => $index + 1,
                'doctor_ids' => $batchDoctorIds,
            ]);

            return [
                'name' => $step['name'],
                'description' => $step['description'],
                'started_at' => $startedAt,
                'finished_at' => date('c'),
                'status' => 'error',
                'doctor_count' => count($doctorIds),
                'batch_size' => $batchSize,
                'batch_count' => count($batches),
                'failed_batch_index' => $index + 1,
                'substeps' => $substeps,
                'output_tail' => $allTail,
            ];
        }
    }

    $logger->info('Step agenda in batch completato', [
        'step' => $step['name'],
        'doctor_count' => count($doctorIds),
        'batch_size' => $batchSize,
        'batch_count' => count($batches),
    ]);

    return [
        'name' => $step['name'],
        'description' => $step['description'],
        'started_at' => $startedAt,
        'finished_at' => date('c'),
        'status' => 'ok',
        'doctor_count' => count($doctorIds),
        'batch_size' => $batchSize,
        'batch_count' => count($batches),
        'substeps' => $substeps,
        'output_tail' => $allTail,
    ];
}

function buildCommandString(array $parts): string
{
    $escaped = [];
    foreach ($parts as $part) {
        $escaped[] = escapeshellarg((string)$part);
    }

    return implode(' ', $escaped);
}

function resolvePhpBinary(): string
{
    $binary = (string)(PHP_BINARY ?: 'php');
    $normalizedBinary = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $binary);
    $fileName = strtolower(basename($normalizedBinary));

    $candidates = [];

    $workspaceRootCandidates = detectWorkspacePhpRoots();
    foreach ($workspaceRootCandidates as $root) {
        $candidates[] = $root . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe';
        $candidates[] = $root . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php';
    }

    if (defined('PHP_BINDIR')) {
        $bindir = rtrim((string)PHP_BINDIR, '\\/');
        if ($bindir !== '') {
            $candidates[] = $bindir . DIRECTORY_SEPARATOR . 'php.exe';
            $candidates[] = $bindir . DIRECTORY_SEPARATOR . 'php';
        }
    }

    $phprc = trim((string)($_SERVER['PHPRC'] ?? ''));
    if ($phprc !== '') {
        $phprc = rtrim($phprc, '\\/');
        $candidates[] = $phprc . DIRECTORY_SEPARATOR . 'php.exe';
        $candidates[] = $phprc . DIRECTORY_SEPARATOR . 'php';
    }

    $dir = __DIR__;
    while ($dir !== '' && $dir !== dirname($dir)) {
        $candidates[] = $dir . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe';
        $candidates[] = $dir . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php';
        $dir = dirname($dir);
    }

    if ($fileName !== 'php.exe' && $fileName !== 'php') {
        $candidates[] = dirname($normalizedBinary) . DIRECTORY_SEPARATOR . 'php.exe';
        $candidates[] = dirname($normalizedBinary) . DIRECTORY_SEPARATOR . 'php';
    }

    $candidates[] = $normalizedBinary;

    foreach (array_values(array_unique($candidates)) as $candidate) {
        if ($candidate === '') {
            continue;
        }

        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    return 'php';
}

function detectWorkspacePhpRoots(): array
{
    $roots = [];
    $dir = realpath(__DIR__) ?: __DIR__;

    while ($dir !== '' && $dir !== dirname($dir)) {
        $base = strtolower(basename($dir));
        if ($base === 'htdocs') {
            $parent = dirname($dir);
            if ($parent !== '' && $parent !== $dir) {
                $roots[] = $parent;
            }
        }

        $dir = dirname($dir);
    }

    return array_values(array_unique(array_filter($roots, static fn ($path) => is_dir($path))));
}

function resolveAgendaDoctorIdsForBatches(array $dbConfig, array $doctorFilter): array
{
    if ($doctorFilter !== []) {
        return array_values(array_unique(array_map('intval', $doctorFilter)));
    }

    $db = new mysqli(
        (string)$dbConfig['host'],
        (string)$dbConfig['user'],
        (string)$dbConfig['pass'],
        (string)$dbConfig['target_db'],
        (int)$dbConfig['port']
    );
    $db->set_charset('latin1');

    try {
        $doctorIds = [];

        if (tableExistsForOrchestrator($db, (string)$dbConfig['target_db'], 'far03_dot')) {
            $sql = "SELECT id_dot FROM `{$dbConfig['target_db']}`.far03_dot ORDER BY id_dot ASC";
            $res = $db->query($sql);
            while ($row = $res->fetch_assoc()) {
                $idDot = (int)($row['id_dot'] ?? 0);
                if ($idDot > 0) {
                    $doctorIds[] = $idDot;
                }
            }
            $res->close();
        }

        if ($doctorIds === [] && tableExistsForOrchestrator($db, (string)$dbConfig['target_db'], 'dap03_personale')) {
            $sql = "
                SELECT DISTINCT legacy_id_dot
                FROM `{$dbConfig['target_db']}`.dap03_personale
                WHERE COALESCE(legacy_id_dot, 0) > 0
                ORDER BY legacy_id_dot ASC
            ";
            $res = $db->query($sql);
            while ($row = $res->fetch_assoc()) {
                $idDot = (int)($row['legacy_id_dot'] ?? 0);
                if ($idDot > 0) {
                    $doctorIds[] = $idDot;
                }
            }
            $res->close();
        }

        return array_values(array_unique(array_map('intval', $doctorIds)));
    } finally {
        $db->close();
    }
}

function tableExistsForOrchestrator(mysqli $db, string $schema, string $table): bool
{
    $schemaEscaped = $db->real_escape_string($schema);
    $tableEscaped = $db->real_escape_string($table);
    $sql = "
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = '{$schemaEscaped}'
          AND TABLE_NAME = '{$tableEscaped}'
        LIMIT 1
    ";

    try {
        $res = $db->query($sql);
        if (!$res) {
            return false;
        }
        $exists = $res->fetch_assoc() !== null;
        $res->close();
        return $exists;
    } catch (\Throwable $e) {
        return false;
    }
}

function parseCsvInts(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }

    $out = [];
    foreach (explode(',', $value) as $item) {
        $item = trim($item);
        if ($item === '' || !preg_match('/^-?\d+$/', $item)) {
            continue;
        }
        $out[] = (int)$item;
    }

    return array_values(array_unique($out));
}

function csvOrNull(array $ints): ?string
{
    return $ints === [] ? null : implode(',', array_map('strval', $ints));
}

function firstNonEmpty(array $values, ?string $fallback = null): ?string
{
    foreach ($values as $value) {
        $value = normalizeDateString((string)$value);
        if ($value !== null && $value !== '') {
            return $value;
        }
    }

    return $fallback;
}

function normalizeDateString(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || strpos($value, '0000-00-00') === 0) {
        return null;
    }

    $formats = ['Y-m-d', 'Y-m-d H:i:s'];
    foreach ($formats as $format) {
        $dt = \DateTime::createFromFormat($format, $value);
        if ($dt instanceof \DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d', $ts);
}

function maxDateString(string $left, string $right): string
{
    return $left >= $right ? $left : $right;
}

function queryScalarDate(mysqli $db, string $sql): ?string
{
    try {
        $res = $db->query($sql);
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        $res->close();
        return isset($row['d']) ? (string)$row['d'] : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function loadEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $vars = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
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
        $vars[$key] = $value;
    }

    return $vars;
}

function ensureDirectory(string $dir): void
{
    if ($dir !== '' && !is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function hasFlag(array $argv, string $flag): bool
{
    return in_array($flag, $argv, true);
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

final class FullRebuildLogger
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function raw(string $line): void
    {
        file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND);
    }

    private function write(string $level, string $message, array $context = []): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] {$level} {$message}";
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND);
        echo $line . PHP_EOL;
    }
}
