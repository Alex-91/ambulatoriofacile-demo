<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Rome');

const DEFAULT_ULTRAMSG_URL = 'https://api.ultramsg.com/instance123914/messages/chat';
const DEFAULT_ARUBA_BASEURL = 'https://adminsms.aruba.it/API/v1.0/REST/';
const DEFAULT_ARUBA_SENDER = 'AmbRIMAGGIO';

if (PHP_SAPI !== 'cli' && !defined('REMINDER_WEB_ALLOWED')) {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

exit(main($argv ?? []));

function main(array $argv): int
{
    $rootDir = __DIR__;
    $env = readDotEnv($rootDir . DIRECTORY_SEPARATOR . '.env');
    $options = readCliOptions($argv);

    $environment = strtolower((string) ($env['CI_ENVIRONMENT'] ?? 'production'));
    $sendMode = resolveSendMode($options, $environment);
    $channel = resolveChannel($options, $env);
    $targetDates = resolveTargetDates($options);
    $doctorFilter = normalizeDoctorFilter($options['doctor'] ?? null);
    $limit = isset($options['limit']) ? max(1, (int) $options['limit']) : null;
    $delayMs = resolveDelayMs($options, $env);
    $forceRecipient = normalizeRecipient((string) ($options['force-recipient'] ?? ($env['SMS_FORCE_RECIPIENT'] ?? '')));
    $logDir = $rootDir . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'logs';
    $stateDir = $rootDir . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'reminder_state';
    $lockDir = $rootDir . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'locks';

    ensureDirectory($logDir);
    ensureDirectory($stateDir);
    ensureDirectory($lockDir);

    $logFile = $logDir . DIRECTORY_SEPARATOR . 'cron_send_appointment_reminders.log';
    $lockFile = $lockDir . DIRECTORY_SEPARATOR . 'appointment_reminders_' . $channel . '.lock';

    $lockHandle = acquireLock($lockFile);
    if ($lockHandle === null) {
        logLine($logFile, 'warning', 'Esecuzione gia in corso, esco senza duplicare il job.', [
            'channel' => $channel,
        ]);
        return 0;
    }

    try {
        $firstStateFile = $stateDir . DIRECTORY_SEPARATOR . 'appointment_reminders_' . $channel . '_' . ($targetDates[0] ?? 'n-a') . '.json';
        logLine($logFile, 'info', 'Diagnostica ambiente cron.', buildRuntimeDiagnostics($env, $rootDir, $firstStateFile, $lockFile));

        $db = openDatabase($env);
        initializeDatabaseSession($db, $env);

        $overallStats = [
            'target_dates' => $targetDates,
            'days_count' => count($targetDates),
            'candidates' => 0,
            'already_sent' => 0,
            'skipped_invalid_recipient' => 0,
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
        ];

        logLine($logFile, 'info', 'Avvio batch promemoria multi-data.', [
            'environment' => $environment,
            'mode' => $sendMode ? 'send' : 'dry-run',
            'channel' => $channel,
            'target_dates' => $targetDates,
            'doctor_filter' => $doctorFilter,
            'limit' => $limit,
            'delay_ms' => $delayMs,
            'force_recipient' => $forceRecipient,
        ]);

        $providerSession = null;
        foreach ($targetDates as $targetDate) {
            $stats = processTargetDateBatch(
                $db,
                $env,
                $logFile,
                $stateDir,
                $environment,
                $sendMode,
                $channel,
                $targetDate,
                $doctorFilter,
                $limit,
                $delayMs,
                $forceRecipient,
                $providerSession
            );

            foreach (['candidates', 'already_sent', 'skipped_invalid_recipient', 'processed', 'sent', 'failed'] as $key) {
                $overallStats[$key] += (int) ($stats[$key] ?? 0);
            }
        }

        logLine($logFile, 'info', 'Batch promemoria multi-data completato.', $overallStats);
        return $overallStats['failed'] > 0 ? 1 : 0;
    } catch (Throwable $e) {
        logLine($logFile, 'error', 'Errore fatale durante il batch promemoria.', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return 1;
    } finally {
        if (is_resource($lockHandle)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}

function readCliOptions(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);

        if ($arg === 'send') {
            $options['send'] = true;
            continue;
        }

        if ($arg === 'dry-run') {
            $options['dry-run'] = true;
            continue;
        }

        $parts = explode('=', $arg, 2);
        $key = $parts[0];
        $value = $parts[1] ?? '1';
        $options[$key] = $value;
    }

    return $options;
}

function readDotEnv(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $env = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eqPos));
        if ($key === '') {
            continue;
        }

        $value = trim(substr($line, $eqPos + 1));
        $value = stripTrailingComment($value);
        $value = trim($value);

        if ($value === '') {
            $env[$key] = '';
            continue;
        }

        $first = $value[0] ?? '';
        $last = substr($value, -1);
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    return $env;
}

function stripTrailingComment(string $value): string
{
    $inSingle = false;
    $inDouble = false;
    $length = strlen($value);

    for ($i = 0; $i < $length; $i++) {
        $char = $value[$i];
        $prev = $i > 0 ? $value[$i - 1] : '';

        if ($char === "'" && !$inDouble && $prev !== '\\') {
            $inSingle = !$inSingle;
            continue;
        }

        if ($char === '"' && !$inSingle && $prev !== '\\') {
            $inDouble = !$inDouble;
            continue;
        }

        if ($char === '#' && !$inSingle && !$inDouble && $i > 0 && ctype_space($prev)) {
            return rtrim(substr($value, 0, $i));
        }
    }

    return $value;
}

function resolveSendMode(array $options, string $environment): bool
{
    if (isset($options['send'])) {
        return true;
    }

    if (isset($options['dry-run'])) {
        return false;
    }

    return $environment === 'production';
}

function resolveChannel(array $options, array $env): string
{
    $channel = strtolower((string) ($options['channel'] ?? ($env['REMINDER_CHANNEL'] ?? 'wa')));
    return in_array($channel, ['wa', 'sms'], true) ? $channel : 'wa';
}

function resolveTargetDate(array $options): string
{
    if (!empty($options['date'])) {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $options['date']);
        if ($date === false) {
            throw new RuntimeException('Formato data non valido. Usa YYYY-MM-DD.');
        }

        return $date->format('Y-m-d');
    }

    $daysAhead = isset($options['days-ahead']) ? (int) $options['days-ahead'] : 6;
    $targetDate = (new DateTimeImmutable('today'))->modify(sprintf('+%d day', $daysAhead));
    return $targetDate->format('Y-m-d');
}

function resolveTargetDates(array $options): array
{
    if (!empty($options['start-date']) || !empty($options['days-count'])) {
        $startDate = !empty($options['start-date'])
            ? DateTimeImmutable::createFromFormat('Y-m-d', (string) $options['start-date'])
            : new DateTimeImmutable(resolveTargetDate($options));

        if ($startDate === false) {
            throw new RuntimeException('Formato start-date non valido. Usa YYYY-MM-DD.');
        }

        $daysCount = isset($options['days-count']) ? max(1, min(14, (int) $options['days-count'])) : 1;
        $dates = [];
        for ($i = 0; $i < $daysCount; $i++) {
            $dates[] = $startDate->modify('+' . $i . ' day')->format('Y-m-d');
        }

        return $dates;
    }

    return [resolveTargetDate($options)];
}

function processTargetDateBatch(
    mysqli $db,
    array $env,
    string $logFile,
    string $stateDir,
    string $environment,
    bool $sendMode,
    string $channel,
    string $targetDate,
    array $doctorFilter,
    ?int $limit,
    int $delayMs,
    ?string $forceRecipient,
    ?array &$providerSession
): array {
    $stateFile = $stateDir . DIRECTORY_SEPARATOR . 'appointment_reminders_' . $channel . '_' . $targetDate . '.json';
    $state = loadState($stateFile);
    $rows = fetchReminderCandidates($db, $targetDate, $doctorFilter, $limit);

    logLine($logFile, 'info', 'Avvio batch promemoria appuntamenti.', [
        'environment' => $environment,
        'mode' => $sendMode ? 'send' : 'dry-run',
        'channel' => $channel,
        'target_date' => $targetDate,
        'doctor_filter' => $doctorFilter,
        'limit' => $limit,
        'delay_ms' => $delayMs,
        'force_recipient' => $forceRecipient,
        'candidates' => count($rows),
    ]);

    $stats = [
        'target_date' => $targetDate,
        'candidates' => count($rows),
        'already_sent' => 0,
        'skipped_invalid_recipient' => 0,
        'processed' => 0,
        'sent' => 0,
        'failed' => 0,
    ];

    foreach ($rows as $row) {
        $appointmentId = (int) $row['id_appuntamento'];
        $patientLabel = trim((string) $row['patient_cognome'] . ' ' . (string) $row['patient_nome']);
        $recipient = $forceRecipient ?: selectRecipient($row);

        if ($recipient === null) {
            $stats['skipped_invalid_recipient']++;
            logLine($logFile, 'info', 'Appuntamento saltato: nessun numero mobile valido.', [
                'target_date' => $targetDate,
                'id_appuntamento' => $appointmentId,
                'patient' => $patientLabel,
                'cellulare' => (string) ($row['cellulare'] ?? ''),
                'telefono' => (string) ($row['telefono'] ?? ''),
            ]);
            continue;
        }

        if (isset($state['sent'][(string) $appointmentId])) {
            $stats['already_sent']++;
            logLine($logFile, 'info', 'Appuntamento gia inviato in precedenza, salto.', [
                'target_date' => $targetDate,
                'id_appuntamento' => $appointmentId,
                'patient' => $patientLabel,
                'recipient' => $recipient,
            ]);
            continue;
        }

        $message = buildReminderMessage($row, $targetDate);
        $stats['processed']++;

        if (!$sendMode) {
            logLine($logFile, 'info', 'Dry-run: promemoria pronto.', [
                'target_date' => $targetDate,
                'id_appuntamento' => $appointmentId,
                'patient' => $patientLabel,
                'recipient' => $recipient,
                'message' => $message,
            ]);
            continue;
        }

        if ($channel === 'sms') {
            if ($providerSession === null) {
                $providerSession = loginArubaSms($env);
            }

            $result = sendArubaSms($env, $providerSession, $recipient, $message);
        } else {
            $result = sendUltraMsg($env, $recipient, $message);
        }

        if ($result['success']) {
            $stats['sent']++;
            $state['sent'][(string) $appointmentId] = [
                'recipient' => $recipient,
                'sent_at' => date('c'),
                'channel' => $channel,
                'provider_id' => $result['provider_id'],
                'response' => $result['response_body'],
            ];
            saveState($stateFile, $state);

            logLine($logFile, 'info', 'Promemoria inviato con successo.', [
                'target_date' => $targetDate,
                'id_appuntamento' => $appointmentId,
                'patient' => $patientLabel,
                'recipient' => $recipient,
                'provider_id' => $result['provider_id'],
                'channel' => $channel,
            ]);
        } else {
            $stats['failed']++;
            logLine($logFile, 'error', 'Invio promemoria fallito.', [
                'target_date' => $targetDate,
                'id_appuntamento' => $appointmentId,
                'patient' => $patientLabel,
                'recipient' => $recipient,
                'channel' => $channel,
                'error' => $result['error'],
                'response' => $result['response_body'],
            ]);
        }

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    logLine($logFile, 'info', 'Batch promemoria completato.', $stats);
    return $stats;
}

function resolveDelayMs(array $options, array $env): int
{
    if (isset($options['delay-ms'])) {
        return max(0, (int) $options['delay-ms']);
    }

    if (isset($env['SMS_BATCH_DELAY_MS'])) {
        return max(0, (int) $env['SMS_BATCH_DELAY_MS']);
    }

    return 900000;
}

function normalizeDoctorFilter(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $parts = preg_split('/\s*,\s*/', trim($raw));
    if ($parts === false) {
        return [];
    }

    $ids = [];
    foreach ($parts as $part) {
        $value = (int) $part;
        if ($value > 0) {
            $ids[] = $value;
        }
    }

    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Impossibile creare la directory: ' . $path);
    }
}

function acquireLock(string $lockFile)
{
    $handle = fopen($lockFile, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Impossibile aprire il lock file: ' . $lockFile);
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }

    ftruncate($handle, 0);
    fwrite($handle, (string) getmypid());
    fflush($handle);
    return $handle;
}

function buildRuntimeDiagnostics(array $env, string $rootDir, string $stateFile, string $lockFile): array
{
    $dbHost = (string) ($env['database.default.hostname'] ?? '89.46.111.163');
    $dbName = (string) ($env['database.default.database'] ?? 'Sql1688505_1');
    $dbUser = (string) ($env['database.default.username'] ?? 'Sql1688505');
    $dbPass = (string) ($env['database.default.password'] ?? 'Tira74GL!#');
    $dbPort = isset($env['database.default.port']) ? (int) $env['database.default.port'] : 3306;
    $cronToken = (string) ($env['CRON_ACCESS_TOKEN'] ?? '');

    return [
        'php_sapi' => PHP_SAPI,
        'script_file' => __FILE__,
        'root_dir' => $rootDir,
        'cwd' => getcwd() ?: null,
        'env_file' => $rootDir . DIRECTORY_SEPARATOR . '.env',
        'ci_environment' => (string) ($env['CI_ENVIRONMENT'] ?? ''),
        'db_host_effective' => $dbHost,
        'db_name_effective' => $dbName,
        'db_user_effective' => $dbUser,
        'db_port_effective' => $dbPort,
        'db_password_masked' => maskSecret($dbPass),
        'db_password_length' => strlen($dbPass),
        'cron_token_masked' => maskSecret($cronToken),
        'cron_token_length' => strlen($cronToken),
        'http_host' => $_SERVER['HTTP_HOST'] ?? null,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
        'reminder_origin' => $_GET['origin'] ?? ($_SERVER['HTTP_X_REMINDER_ORIGIN'] ?? null),
        'server_addr' => $_SERVER['SERVER_ADDR'] ?? null,
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        'state_file' => $stateFile,
        'lock_file' => $lockFile,
    ];
}

function maskSecret(string $value): string
{
    if ($value === '') {
        return '';
    }

    $length = strlen($value);
    if ($length <= 4) {
        return str_repeat('*', $length);
    }

    return substr($value, 0, 2) . str_repeat('*', max(0, $length - 4)) . substr($value, -2);
}

function openDatabase(array $env): mysqli
{
    $host = (string) ($env['database.default.hostname'] ?? '89.46.111.163');
    $dbName = (string) ($env['database.default.database'] ?? 'Sql1688505_1');
    $user = (string) ($env['database.default.username'] ?? 'Sql1688505');
    $pass = (string) ($env['database.default.password'] ?? 'Tira74GL!#');
    $port = isset($env['database.default.port']) ? (int) $env['database.default.port'] : 3306;

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($host, $user, $pass, $dbName, $port);
    $db->set_charset('utf8mb4');
    return $db;
}

function initializeDatabaseSession(mysqli $db, array $env): void
{
    $key = (string) ($env['DB_ENCRYPTION_KEY'] ?? 'PartitaIVA22');
    $mode = (string) ($env['DB_ENCRYPTION_MODE'] ?? 'aes-256-cbc');

    $db->query("SET @key_str = SHA2('" . $db->real_escape_string($key) . "', 512)");
    $db->query("SET block_encryption_mode = '" . $db->real_escape_string($mode) . "'");
}

function fetchReminderCandidates(mysqli $db, string $targetDate, array $doctorFilter, ?int $limit): array
{
    $doctorSql = '';
    if ($doctorFilter !== []) {
        $doctorSql = ' AND s.id_dot IN (' . implode(',', array_map('intval', $doctorFilter)) . ')';
    }

    $limitSql = $limit !== null ? ' LIMIT ' . (int) $limit : '';

    $sql = "
        SELECT
            a.id_appuntamento,
            a.id_slot,
            a.id_dot,
            a.id_paziente,
            a.id_client,
            a.cognome AS patient_cognome,
            a.nome AS patient_nome,
            a.cellulare,
            a.telefono,
            a.stato,
            s.data_slot,
            DATE_FORMAT(s.ora_inizio, '%H:%i') AS ora_label,
            s.id_amb_legacy,
            s.ambulatorio,
            s.stanza,
            conf.conferma,
            COALESCE(amb.nome, s.ambulatorio, '') AS ambulatorio_label,
            COALESCE(amb.indirizzo, '') AS indirizzo,
            COALESCE(amb.citta, '') AS citta,
            COALESCE(amb.telefono, '') AS amb_tel,
            " . decryptExpr('p.qualifica', 'p.vector_id') . " AS doc_qualifica,
            " . decryptExpr('p.nome', 'p.vector_id') . " AS doc_nome,
            " . decryptExpr('p.cognome', 'p.vector_id') . " AS doc_cognome
        FROM dap12_agenda_appuntamenti a
        INNER JOIN dap11_agenda_slot s
            ON s.id_slot = a.id_slot
        INNER JOIN dap39_sms_dot conf
            ON conf.id_dot = s.id_dot
        LEFT JOIN dap03_personale p
            ON p.legacy_id_dot = s.id_dot
           AND p.tipo IN (1, 2)
        LEFT JOIN dap42_ambulatori amb
            ON amb.id_amb_legacy = s.id_amb_legacy
        WHERE s.data_slot = ?
          AND a.stato <> 'ANNULLATO'
          {$doctorSql}
        ORDER BY s.id_dot ASC, s.ora_inizio ASC, a.id_appuntamento ASC
        {$limitSql}
    ";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $targetDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function decryptExpr(string $fieldExpr, string $vectorExpr): string
{
    return "CONVERT(CAST(AES_DECRYPT(UNHEX({$fieldExpr}), @key_str, {$vectorExpr}) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
}

function loadState(string $stateFile): array
{
    if (!is_file($stateFile)) {
        return ['sent' => []];
    }

    $json = file_get_contents($stateFile);
    if ($json === false || trim($json) === '') {
        return ['sent' => []];
    }

    $state = json_decode($json, true);
    if (!is_array($state)) {
        return ['sent' => []];
    }

    if (!isset($state['sent']) || !is_array($state['sent'])) {
        $state['sent'] = [];
    }

    return $state;
}

function saveState(string $stateFile, array $state): void
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Impossibile serializzare lo stato invii.');
    }

    file_put_contents($stateFile, $json);
}

function selectRecipient(array $row): ?string
{
    $cellulare = normalizeRecipient((string) ($row['cellulare'] ?? ''));
    if ($cellulare !== null) {
        return $cellulare;
    }

    return normalizeRecipient((string) ($row['telefono'] ?? ''));
}

function normalizeRecipient(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '.' || $raw === '0') {
        return null;
    }

    $digits = preg_replace('/[^0-9+]/', '', $raw);
    if ($digits === null || $digits === '') {
        return null;
    }

    if (str_starts_with($digits, '00')) {
        $digits = '+' . substr($digits, 2);
    }

    if (str_starts_with($digits, '+39')) {
        $local = substr($digits, 3);
    } elseif (str_starts_with($digits, '39')) {
        $local = substr($digits, 2);
    } else {
        $local = ltrim($digits, '+');
    }

    if (!preg_match('/^3[0-9]{8,9}$/', $local)) {
        return null;
    }

    return '+39' . $local;
}

function buildReminderMessage(array $row, string $targetDate): string
{
    $date = new DateTimeImmutable($targetDate);
    $weekday = italianWeekdayShort($date);
    $month = italianMonthName($date);
    $dayNumber = $date->format('d');
    $time = (string) ($row['ora_label'] ?? '');

    $clinicName = trim((string) ($row['ambulatorio_label'] ?? ''));
    $prefix = inferBrandPrefix($clinicName);

    $doctor = trim(implode(' ', array_filter([
        trim((string) ($row['doc_qualifica'] ?? '')),
        trim((string) ($row['doc_cognome'] ?? '')),
        trim((string) ($row['doc_nome'] ?? '')),
    ], static fn(string $value): bool => $value !== '')));
    if ($doctor === '') {
        $doctor = 'il medico';
    }
    $doctor = normalizeUpperText($doctor);

    $locationParts = [];
    $indirizzo = trim((string) ($row['indirizzo'] ?? ''));
    $citta = trim((string) ($row['citta'] ?? ''));
    if ($indirizzo !== '') {
        $locationParts[] = normalizeUpperText($indirizzo);
    }
    if ($citta !== '') {
        $locationParts[] = normalizeUpperText($citta);
    }
    $location = trim(implode(' ', $locationParts));

    $clinicPhone = preg_replace('/\s+/', '', trim((string) ($row['amb_tel'] ?? '')));
    $message = sprintf(
        '%s Ambulatori le ricorda l\'appuntamento di %s %s %s alle ore %s con %s',
        $prefix,
        $weekday,
        $dayNumber,
        $month,
        $time,
        $doctor
    );

    if ($location !== '') {
        $message .= ' presso ' . $location;
    }

    if ($clinicPhone !== null && $clinicPhone !== '') {
        $message .= ' TEL. ' . $clinicPhone;
    }

    $message .= '.';

    if ((int) ($row['conferma'] ?? 0) === 1) {
        $message .= " \nPer *CONFERMARE* l'appuntamento scrivi *1* ed invia \nPer *ANNULLARE* l'appuntamento scrivi *2* ed invia.";
    }

    return $message;
}

function inferBrandPrefix(string $clinicName): string
{
    $clinicName = strtoupper(trim($clinicName));
    if ($clinicName !== '' && str_starts_with($clinicName, 'RIM')) {
        return 'Rimaggio';
    }

    return 'Nova';
}

function normalizeUpperText(string $value): string
{
    $value = mb_strtoupper($value, 'UTF-8');
    $value = str_replace('.', '. ', $value);
    return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
}

function italianWeekdayShort(DateTimeImmutable $date): string
{
    $map = [
        1 => 'Lun',
        2 => 'Mar',
        3 => 'Mer',
        4 => 'Gio',
        5 => 'Ven',
        6 => 'Sab',
        7 => 'Dom',
    ];

    return $map[(int) $date->format('N')] ?? $date->format('D');
}

function italianMonthName(DateTimeImmutable $date): string
{
    $map = [
        1 => 'Gennaio',
        2 => 'Febbraio',
        3 => 'Marzo',
        4 => 'Aprile',
        5 => 'Maggio',
        6 => 'Giugno',
        7 => 'Luglio',
        8 => 'Agosto',
        9 => 'Settembre',
        10 => 'Ottobre',
        11 => 'Novembre',
        12 => 'Dicembre',
    ];

    return $map[(int) $date->format('n')] ?? $date->format('F');
}

function sendUltraMsg(array $env, string $recipient, string $message): array
{
    $url = (string) ($env['SMS_ULTRAMSG_URL'] ?? DEFAULT_ULTRAMSG_URL);
    $token = (string) ($env['SMS_API_TOKEN'] ?? '');
    if ($token === '') {
        return [
            'success' => false,
            'provider_id' => null,
            'response_body' => null,
            'error' => 'SMS_API_TOKEN non configurato.',
        ];
    }

    $payload = http_build_query([
        'token' => $token,
        'to' => $recipient,
        'body' => $message,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return [
            'success' => false,
            'provider_id' => null,
            'response_body' => null,
            'error' => $curlError !== '' ? $curlError : 'Errore sconosciuto curl.',
        ];
    }

    $decoded = json_decode($body, true);
    $success = $status >= 200 && $status < 300 && is_array($decoded) && (($decoded['sent'] ?? '') === 'true' || ($decoded['sent'] ?? false) === true);

    return [
        'success' => $success,
        'provider_id' => is_array($decoded) ? ($decoded['id'] ?? null) : null,
        'response_body' => $body,
        'error' => $success ? null : ('HTTP ' . $status),
    ];
}

function loginArubaSms(array $env): array
{
    $username = (string) ($env['SMS_USERNAME'] ?? '');
    $password = (string) ($env['SMS_PASSWORD'] ?? '');

    if ($username === '' || $password === '') {
        throw new RuntimeException('SMS_USERNAME o SMS_PASSWORD mancanti per il canale sms.');
    }

    $url = DEFAULT_ARUBA_BASEURL . 'login?username=' . rawurlencode($username) . '&password=' . rawurlencode($password);
    $response = httpGet($url);

    if ($response['status'] !== 200 || trim($response['body']) === '') {
        throw new RuntimeException('Login Aruba SMS fallito: HTTP ' . $response['status']);
    }

    $parts = explode(';', trim($response['body']));
    if (count($parts) < 2) {
        throw new RuntimeException('Login Aruba SMS fallito: risposta non valida.');
    }

    return [
        'user_key' => $parts[0],
        'session_key' => $parts[1],
    ];
}

function sendArubaSms(array $env, array $session, string $recipient, string $message): array
{
    $sender = (string) ($env['SMS_SENDER'] ?? DEFAULT_ARUBA_SENDER);
    $payload = json_encode([
        'message_type' => 'N',
        'message' => $message,
        'returnCredits' => false,
        'recipient' => [$recipient],
        'sender' => $sender,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return [
            'success' => false,
            'provider_id' => null,
            'response_body' => null,
            'error' => 'Impossibile serializzare il payload sms.',
        ];
    }

    $ch = curl_init(DEFAULT_ARUBA_BASEURL . 'sms');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'user_key: ' . $session['user_key'],
            'Session_key: ' . $session['session_key'],
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return [
            'success' => false,
            'provider_id' => null,
            'response_body' => null,
            'error' => $curlError !== '' ? $curlError : 'Errore sconosciuto curl.',
        ];
    }

    $decoded = json_decode($body, true);
    $success = $status === 201;

    return [
        'success' => $success,
        'provider_id' => is_array($decoded) ? ($decoded['order_id'] ?? null) : null,
        'response_body' => $body,
        'error' => $success ? null : ('HTTP ' . $status),
    ];
}

function httpGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException($curlError !== '' ? $curlError : 'Errore HTTP sconosciuto.');
    }

    return [
        'status' => $status,
        'body' => $body,
    ];
}

function logLine(string $logFile, string $level, string $message, array $context = []): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($level) . ' ' . $message;
    if ($context !== []) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $line .= ' ' . $json;
        }
    }

    $line .= PHP_EOL;
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}
