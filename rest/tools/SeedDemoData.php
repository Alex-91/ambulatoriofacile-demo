<?php
declare(strict_types=1);

/**
 * Seed a commercial demo dataset into the dedicated demo database only.
 *
 * Example:
 *   php tools/SeedDemoData.php --env-file=.env.demo
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Europe/Rome');

const DEMO_SEED_DEFAULT_PASSWORD = 'Demo2026';
const DEMO_SEED_FORBIDDEN_DATABASES = ['farmacia', 'mail', 'mailsimo'];
const DEMO_SEED_REPORT_DIR = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'demo_setup';
const DEMO_SEED_LOCAL_DOCTOR_ID_BASE = 1000000000;
const DEMO_SEED_DEFAULT_AGENDA_BUSINESS_DAYS = 5;
const DEMO_SEED_MIN_AGENDA_BUSINESS_DAYS = 2;
const DEMO_PLATFORM_BOOTSTRAP_MIGRATIONS = [
    '2026-06-19-000001_CreatePlatformMultiTenantFoundation.php' => 'App\\Database\\Migrations\\CreatePlatformMultiTenantFoundation',
    '2026-06-19-000002_CreatePlatformUserAccessTokens.php' => 'App\\Database\\Migrations\\CreatePlatformUserAccessTokens',
    '2026-06-20-000001_AddTenantManagedFeatureControls.php' => 'App\\Database\\Migrations\\AddTenantManagedFeatureControls',
    '2026-06-20-000002_AddPlatformAdminFlagToPlatformUsers.php' => 'App\\Database\\Migrations\\AddPlatformAdminFlagToPlatformUsers',
    '2026-06-21-000001_AddSharedAgendaPatientsFeature.php' => 'App\\Database\\Migrations\\AddSharedAgendaPatientsFeature',
    '2026-06-21-000002_AddAgendaTeamDayViewFeature.php' => 'App\\Database\\Migrations\\AddAgendaTeamDayViewFeature',
    '2026-06-22-000001_AddSharedAgendaMemosFeature.php' => 'App\\Database\\Migrations\\AddSharedAgendaMemosFeature',
    '2026-06-22-000003_RepairTenantFeaturePreferencesSchema.php' => 'App\\Database\\Migrations\\RepairTenantFeaturePreferencesSchema',
];

if (seedDemoDataShouldRunCli()) {
    exit(seedDemoDataCliMain($argv ?? []));
}

function seedDemoDataShouldRunCli(): bool
{
    if (PHP_SAPI !== 'cli') {
        return false;
    }

    $scriptFilename = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    return $scriptFilename !== false && $scriptFilename === __FILE__;
}

function seedDemoDataCliMain(array $argv): int
{
    $result = seedDemoDataRun($argv, static function (string $message, string $stream = 'stdout'): void {
        $line = rtrim($message, "\r\n") . PHP_EOL;

        if ($stream === 'stderr') {
            fwrite(STDERR, $line);
            return;
        }

        echo $line;
    });

    return (int) ($result['exit_code'] ?? 1);
}

/**
 * @param array<int, string> $argv
 * @param null|callable(string, string): void $logger
 * @return array<string, mixed>
 */
function seedDemoDataRun(array $argv, ?callable $logger = null): array
{
    ensureDirectory(DEMO_SEED_REPORT_DIR);

    $options = parseOptions($argv);
    $envPath = resolveEnvFilePath($options['env_file']);
    $env = $envPath !== '' ? loadSimpleEnvFile($envPath) : [];
    if ($env !== []) {
        applyRuntimeEnvironment($env);
    }

    $config = buildRuntimeConfig($options, $env, $envPath);
    applyRuntimeConfigOverrides($config);
    assertTargetDatabaseIsSafe($config['database']);

    $db = new mysqli(
        $config['host'],
        $config['user'],
        $config['pass'],
        '',
        $config['port']
    );
    $db->set_charset('utf8mb4');

    $report = [
        'started_at' => date('c'),
        'env_file' => $envPath,
        'target_db' => $config['database'],
        'brand' => $config['brand'],
        'password' => $config['demo_password'],
        'status' => 'running',
        'summary' => [],
        'accounts' => [],
    ];

    try {
        assertDatabaseExists($db, $config['database']);
        selectDatabase($db, $config['database']);
        configureCryptoSession($db, $config['db_key'], $config['db_mode']);
        bootstrapPlatformCatalog($report);

        $state = [
            'groups' => [],
            'ambulatori' => [],
            'rooms' => [],
            'users' => [],
            'staff' => [],
            'clients' => [],
        ];

        $fixtures = buildFixtures($config['demo_password'], $config['agenda_days']);
        $report['summary']['agenda_window'] = [
            'start_date' => $fixtures['agenda_days'][0] ?? $config['agenda_start_date'],
            'end_date' => $fixtures['agenda_days'][count($fixtures['agenda_days']) - 1] ?? $config['agenda_start_date'],
            'business_days' => count($fixtures['agenda_days']),
        ];

        $db->begin_transaction();

        resetDemoTables($db);
        seedGroups($db, $fixtures['groups'], $state, $report);
        seedLocations($db, $fixtures['locations'], $state, $report);
        seedUsersAndStaff($db, $fixtures['staff'], $state, $report);
        seedClients($db, $fixtures['clients'], $state, $report);
        seedUserSchede($db, $state, $report);
        seedStaffLinks($db, $fixtures['staff_links'], $state, $report);
        seedDoctorReminderFlags($db, $state, $report);
        seedAgenda($db, $fixtures['agenda'], $fixtures['agenda_days'], $state, $report);
        seedHomeVisits($db, $fixtures['home_visits'], $state, $report);
        seedChatData($db, $fixtures['chat_threads'], $state, $report);
        seedPostaData($db, $fixtures['posta_threads'], $state, $report);
        rebuildDoctorPatientSearch($db, $state, $report);

        $db->commit();

        $report['finished_at'] = date('c');
        $report['status'] = 'ok';
        $report['accounts'] = buildAccountSummary($fixtures['staff'], $fixtures['clients']);
        $report['summary']['login_notes'] = [
            'admin_direct' => 'demo.admin / ' . $config['demo_password'],
            'doctor_direct' => 'demo.dietista / ' . $config['demo_password'] . ' / OTP 2510',
            'impersonation' => 'demo.admin->demo.segreteria / ' . $config['demo_password'] . ' / OTP 2510',
        ];

        $path = writeReport($report);
        seedDemoDataLog($logger, "Demo seed completato su {$config['database']}");
        seedDemoDataLog($logger, "Brand demo: {$config['brand']}");
        seedDemoDataLog($logger, 'Finestra agenda: ' . ($report['summary']['agenda_window']['start_date'] ?? '?') . ' -> ' . ($report['summary']['agenda_window']['end_date'] ?? '?') . ' (' . (int) ($report['summary']['agenda_window']['business_days'] ?? 0) . ' giorni lavorativi)');
        seedDemoDataLog($logger, "Password demo comune: {$config['demo_password']}");
        seedDemoDataLog($logger, 'Account admin: demo.admin');
        seedDemoDataLog($logger, 'Account operativo con OTP fisso: demo.dietista (OTP 2510)');
        seedDemoDataLog($logger, "Report: {$path}");

        return [
            'ok' => true,
            'exit_code' => 0,
            'report' => $report,
            'report_path' => $path,
            'config' => $config,
        ];
    } catch (Throwable $e) {
        safeRollback($db);

        $report['finished_at'] = date('c');
        $report['status'] = 'error';
        $report['error'] = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        $path = writeReport($report);
        seedDemoDataLog($logger, "Errore seed demo: {$e->getMessage()}", 'stderr');
        seedDemoDataLog($logger, "Report: {$path}", 'stderr');

        return [
            'ok' => false,
            'exit_code' => 1,
            'report' => $report,
            'report_path' => $path,
            'config' => $config ?? [],
            'error' => $e,
        ];
    } finally {
        $db->close();
    }
}

function parseOptions(array $argv): array
{
    return [
        'env_file' => optionValue($argv, 'env-file') ?: '',
        'host' => optionValue($argv, 'host'),
        'port' => optionValue($argv, 'port'),
        'user' => optionValue($argv, 'user'),
        'pass' => optionValue($argv, 'pass'),
        'database' => optionValue($argv, 'database'),
        'db_key' => optionValue($argv, 'db-key'),
        'db_mode' => optionValue($argv, 'db-mode'),
        'brand' => optionValue($argv, 'brand'),
        'demo_password' => optionValue($argv, 'demo-password') ?: DEMO_SEED_DEFAULT_PASSWORD,
        'agenda_start_date' => optionValue($argv, 'agenda-start-date'),
        'agenda_business_days' => optionValue($argv, 'agenda-business-days'),
    ];
}

function optionValue(array $argv, string $name): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }

    return null;
}

function resolveEnvFilePath(?string $envFile): string
{
    $envFile = trim((string) $envFile);
    if ($envFile === '') {
        return '';
    }

    $candidate = $envFile;
    if (!preg_match('/^[A-Za-z]:\\\\|^\//', $candidate)) {
        $candidate = dirname(__DIR__) . DIRECTORY_SEPARATOR . $envFile;
    }

    $real = realpath($candidate);
    return $real !== false ? $real : $candidate;
}

function loadSimpleEnvFile(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("File env demo non trovato: {$path}");
    }

    $rows = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($rows === false) {
        throw new RuntimeException("Impossibile leggere il file env demo: {$path}");
    }

    $env = [];
    foreach ($rows as $row) {
        $line = trim((string)$row);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($value !== '' && (($value[0] === "'" && substr($value, -1) === "'") || ($value[0] === '"' && substr($value, -1) === '"'))) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    return $env;
}

function applyRuntimeEnvironment(array $env): void
{
    foreach ($env as $key => $value) {
        if (!is_string($key) || trim($key) === '') {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    if (!isset($env['CI_ENVIRONMENT']) || trim((string)$env['CI_ENVIRONMENT']) === '') {
        putenv('CI_ENVIRONMENT=development');
        $_ENV['CI_ENVIRONMENT'] = 'development';
        $_SERVER['CI_ENVIRONMENT'] = 'development';
    }
}

function applyRuntimeConfigOverrides(array $config): void
{
    $overrides = [
        'database.default.hostname' => (string)$config['host'],
        'database.default.port' => (string)$config['port'],
        'database.default.database' => (string)$config['database'],
        'database.default.username' => (string)$config['user'],
        'database.default.password' => (string)$config['pass'],
        'database.default.DB_ENCRYPTION_KEY' => (string)$config['db_key'],
        'DB_ENCRYPTION_KEY' => (string)$config['db_key'],
        'DB_ENCRYPTION_MODE' => (string)$config['db_mode'],
        'PRODUCT_BRAND_NAME' => (string)$config['brand'],
    ];

    foreach ($overrides as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function buildRuntimeConfig(array $options, array $env, string $envPath): array
{
    $config = [
        'host' => trim((string) runtimeConfigValue($options['host'] ?? null, $env, ['database.default.hostname', 'DB_HOST'], 'localhost')),
        'port' => (int) runtimeConfigValue($options['port'] ?? null, $env, ['database.default.port', 'DB_PORT'], '3306'),
        'user' => trim((string) runtimeConfigValue($options['user'] ?? null, $env, ['database.default.username', 'DB_USERNAME'], 'root')),
        'pass' => (string) runtimeConfigValue($options['pass'] ?? null, $env, ['database.default.password', 'DB_PASSWORD'], 'root'),
        'database' => trim((string) runtimeConfigValue($options['database'] ?? null, $env, ['database.default.database', 'DB_DATABASE'], 'ambulatoriofacile_demo')),
        'db_key' => trim((string) runtimeConfigValue($options['db_key'] ?? null, $env, ['DB_ENCRYPTION_KEY', 'database.default.DB_ENCRYPTION_KEY'], '')),
        'db_mode' => trim((string) runtimeConfigValue($options['db_mode'] ?? null, $env, ['DB_ENCRYPTION_MODE'], 'aes-256-cbc')),
        'brand' => trim((string) runtimeConfigValue($options['brand'] ?? null, $env, ['PRODUCT_BRAND_NAME'], 'AmbulatorioFacile')),
        'demo_password' => trim((string)$options['demo_password']),
        'agenda_start_date' => normalizeDemoSeedDate((string) runtimeConfigValue($options['agenda_start_date'] ?? null, $env, ['DEMO_SEED_AGENDA_START_DATE'], date('Y-m-d'))),
        'agenda_business_days' => normalizeDemoSeedBusinessDays(runtimeConfigValue($options['agenda_business_days'] ?? null, $env, ['DEMO_SEED_AGENDA_BUSINESS_DAYS'], (string) DEMO_SEED_DEFAULT_AGENDA_BUSINESS_DAYS)),
        'env_path' => $envPath,
    ];

    $config['agenda_days'] = nextBusinessDaysFromDate($config['agenda_start_date'], $config['agenda_business_days']);

    if ($config['database'] === '') {
        throw new RuntimeException('Database demo non configurato.');
    }

    if ($config['db_key'] === '') {
        throw new RuntimeException('Chiave DB_ENCRYPTION_KEY mancante nell\'ambiente demo.');
    }

    if ($config['demo_password'] === '') {
        throw new RuntimeException('Password demo non valida.');
    }

    return $config;
}

function assertTargetDatabaseIsSafe(string $database): void
{
    $lower = strtolower(trim($database));
    if ($lower === '' || in_array($lower, DEMO_SEED_FORBIDDEN_DATABASES, true) || !str_contains($lower, 'demo')) {
        throw new RuntimeException('Il seed demo puo lavorare solo su un database demo dedicato.');
    }
}

/**
 * @param array<string, string> $env
 * @param array<int, string> $keys
 */
function runtimeConfigValue(?string $optionValue, array $env, array $keys, string $fallback = ''): string
{
    $optionValue = trim((string) $optionValue);
    if ($optionValue !== '') {
        return $optionValue;
    }

    foreach ($keys as $key) {
        $envValue = trim((string) ($env[$key] ?? ''));
        if ($envValue !== '') {
            return $envValue;
        }

        $runtimeValue = getenv($key);
        if ($runtimeValue !== false && trim((string) $runtimeValue) !== '') {
            return trim((string) $runtimeValue);
        }
    }

    return $fallback;
}

function normalizeDemoSeedDate(string $value): string
{
    $value = trim($value);
    $timestamp = strtotime($value);

    if ($value === '' || $timestamp === false) {
        throw new RuntimeException('Data seed demo non valida. Usa il formato YYYY-MM-DD.');
    }

    return date('Y-m-d', $timestamp);
}

/**
 * @param string|int $value
 */
function normalizeDemoSeedBusinessDays($value): int
{
    $days = (int) $value;
    if ($days < DEMO_SEED_MIN_AGENDA_BUSINESS_DAYS) {
        $days = DEMO_SEED_MIN_AGENDA_BUSINESS_DAYS;
    }

    return $days;
}

function nextBusinessDaysFromDate(string $startDate, int $days): array
{
    $result = [];
    $cursor = strtotime($startDate);

    if ($cursor === false) {
        throw new RuntimeException('Impossibile calcolare la finestra agenda demo.');
    }

    while (count($result) < $days) {
        if ((int) date('N', $cursor) <= 5) {
            $result[] = date('Y-m-d', $cursor);
        }

        $cursor = strtotime('+1 day', $cursor);
        if ($cursor === false) {
            throw new RuntimeException('Impossibile calcolare la finestra agenda demo.');
        }
    }

    return $result;
}

function seedDemoDataLog(?callable $logger, string $message, string $stream = 'stdout'): void
{
    if ($logger !== null) {
        $logger($message, $stream);
    }
}

function assertDatabaseExists(mysqli $db, string $database): void
{
    $stmt = $db->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
    $stmt->bind_param('s', $database);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$result) {
        throw new RuntimeException("Database non trovato: {$database}");
    }
}

function selectDatabase(mysqli $db, string $database): void
{
    if (!$db->select_db($database)) {
        throw new RuntimeException("Impossibile selezionare il database {$database}");
    }
}

function configureCryptoSession(mysqli $db, string $dbKey, string $dbMode): void
{
    $db->query('SET NAMES latin1');
    $db->query("SET block_encryption_mode = '" . $db->real_escape_string($dbMode) . "'");
    $db->query("SET @key_str = SHA2('" . $db->real_escape_string($dbKey) . "', 512)");
    $db->query('SET @init_vector = RANDOM_BYTES(16)');
}

function bootstrapPlatformCatalog(array &$report): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    bootstrapCodeIgniterRuntimeForDemoTools();

    foreach (DEMO_PLATFORM_BOOTSTRAP_MIGRATIONS as $file => $className) {
        $migrationPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR . 'Migrations' . DIRECTORY_SEPARATOR . $file;
        if (!is_file($migrationPath)) {
            throw new RuntimeException("Migration platform demo non trovata: {$migrationPath}");
        }

        require_once $migrationPath;
        if (!class_exists($className)) {
            throw new RuntimeException("Classe migration non caricata: {$className}");
        }

        /** @var object $migration */
        $migration = new $className();
        if (!method_exists($migration, 'up')) {
            throw new RuntimeException("Migration piattaforma non valida: {$className}");
        }

        $migration->up();
    }

    $platformDb = \Config\Database::connect('platform');
    $platformTables = [
        'platform_packages',
        'platform_features',
        'platform_package_features',
        'platform_tenants',
        'platform_tenant_features',
        'platform_tenant_feature_preferences',
        'platform_users',
        'platform_user_tenants',
        'platform_user_access_tokens',
    ];

    foreach ($platformTables as $table) {
        if (!$platformDb->tableExists($table)) {
            throw new RuntimeException("Tabella platform mancante dopo bootstrap demo: {$table}");
        }
    }

    $report['summary']['platform_tables'] = count($platformTables);
    $report['summary']['platform_packages'] = countTableRows($platformDb, 'platform_packages');
    $report['summary']['platform_features'] = countTableRows($platformDb, 'platform_features');

    $bootstrapped = true;
}

function bootstrapCodeIgniterRuntimeForDemoTools(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $repoRoot = dirname(dirname(__DIR__));
    require_once $repoRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'Paths.php';

    $paths = new \Config\Paths();

    if (!defined('APPPATH')) {
        define('APPPATH', realpath(rtrim($paths->appDirectory, '\\/ ')) . DIRECTORY_SEPARATOR);
    }

    if (!defined('ROOTPATH')) {
        define('ROOTPATH', realpath(APPPATH . '..') . DIRECTORY_SEPARATOR);
    }

    if (!defined('SYSTEMPATH')) {
        define('SYSTEMPATH', realpath(rtrim($paths->systemDirectory, '\\/ ')) . DIRECTORY_SEPARATOR);
    }

    if (!defined('WRITEPATH')) {
        $writePath = realpath(rtrim($paths->writableDirectory, '\\/ '));
        if ($writePath === false) {
            throw new RuntimeException('WRITEPATH non disponibile per il bootstrap demo.');
        }

        define('WRITEPATH', $writePath . DIRECTORY_SEPARATOR);
    }

    if (!defined('TESTPATH')) {
        define('TESTPATH', realpath(rtrim($paths->testsDirectory, '\\/ ')) . DIRECTORY_SEPARATOR);
    }

    if (!defined('ENVIRONMENT')) {
        define('ENVIRONMENT', (string)(getenv('CI_ENVIRONMENT') ?: 'development'));
    }

    if (!defined('CI_DEBUG')) {
        define('CI_DEBUG', ENVIRONMENT !== 'production');
    }

    require_once APPPATH . 'Config' . DIRECTORY_SEPARATOR . 'Constants.php';
    require_once SYSTEMPATH . 'Config' . DIRECTORY_SEPARATOR . 'AutoloadConfig.php';
    require_once APPPATH . 'Config' . DIRECTORY_SEPARATOR . 'Autoload.php';
    require_once SYSTEMPATH . 'Modules' . DIRECTORY_SEPARATOR . 'Modules.php';
    require_once APPPATH . 'Config' . DIRECTORY_SEPARATOR . 'Modules.php';
    require_once SYSTEMPATH . 'Autoloader' . DIRECTORY_SEPARATOR . 'Autoloader.php';
    require_once SYSTEMPATH . 'Config' . DIRECTORY_SEPARATOR . 'BaseService.php';
    require_once SYSTEMPATH . 'Config' . DIRECTORY_SEPARATOR . 'Services.php';
    require_once APPPATH . 'Config' . DIRECTORY_SEPARATOR . 'Services.php';

    if (is_file(APPPATH . 'Common.php')) {
        require_once APPPATH . 'Common.php';
    }
    require_once SYSTEMPATH . 'Common.php';

    \Config\Services::autoloader()->initialize(new \Config\Autoload(), new \Config\Modules())->register();

    $loaded = true;
}

function countTableRows(\CodeIgniter\Database\BaseConnection $db, string $table): int
{
    return (int)$db->table($table)->countAllResults();
}

function resetDemoTables(mysqli $db): void
{
    $tables = [
        'msg_user_flags',
        'msg_attachments',
        'msg_drafts',
        'msg_messages',
        'msg_threads',
        'dap_chat_attachments',
        'dap_chat_message',
        'dap_chat_thread_user',
        'dap_chat_thread',
        'dap26_doctor_patient_search',
        'dap13_visite_domiciliari',
        'dap12_agenda_appuntamenti',
        'dap11_agenda_slot',
        'dap39_sms_dot',
        'dap15_inf_dot',
        'dap14_seg_dot',
        'dap24_agenda_visibilita',
        'dap09_client_doctor',
        'dap_user_schede',
        'dap16_auth_code',
        'push_delivery_logs',
        'push_outbox',
        'push_subscriptions',
        'device_links',
        'otp_delivery_logs',
        'dap02_clients',
        'dap03_personale',
        'dap01_users',
        'dap43_ambulatori_stanze',
        'dap42_ambulatori',
        'dap21_gruppo',
    ];

    $db->query('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($tables as $table) {
            if (tableExists($db, $table)) {
                $db->query("TRUNCATE TABLE `{$table}`");
            }
        }
    } finally {
        $db->query('SET FOREIGN_KEY_CHECKS=1');
    }
}

function tableExists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (bool)$result;
}

function seedGroups(mysqli $db, array $groups, array &$state, array &$report): void
{
    $count = 0;
    foreach ($groups as $group) {
        $sql = "INSERT INTO dap21_gruppo (nome) VALUES (" . sqlLiteral($db, $group['name']) . ")";
        $db->query($sql);
        $state['groups'][$group['key']] = [
            'id' => (int)$db->insert_id,
            'name' => $group['name'],
        ];
        $count++;
    }

    $report['summary']['groups'] = $count;
}

function seedLocations(mysqli $db, array $locations, array &$state, array &$report): void
{
    $locationCount = 0;
    $roomCount = 0;

    foreach ($locations as $location) {
        $ambId = (int)$location['amb_id'];
        $createdAt = sqlLiteral($db, date('Y-m-d H:i:s'));
        $sql = "
            INSERT INTO dap42_ambulatori (id_amb_legacy, nome, indirizzo, citta, telefono, created_at, updated_at)
            VALUES (
                {$ambId},
                " . sqlLiteral($db, $location['name']) . ",
                " . sqlNullableLiteral($db, $location['address']) . ",
                " . sqlNullableLiteral($db, $location['city']) . ",
                " . sqlNullableLiteral($db, $location['phone']) . ",
                {$createdAt},
                {$createdAt}
            )
        ";
        $db->query($sql);

        $state['ambulatori'][$location['key']] = [
            'id' => $ambId,
            'name' => $location['name'],
        ];
        $locationCount++;

        foreach ($location['rooms'] as $index => $room) {
            $roomSql = "
                INSERT INTO dap43_ambulatori_stanze (id_amb_legacy, nome, ordinamento, attiva, created_at, updated_at)
                VALUES (
                    {$ambId},
                    " . sqlLiteral($db, $room['name']) . ",
                    " . ((int)($room['order'] ?? ($index + 1))) . ",
                    1,
                    {$createdAt},
                    {$createdAt}
                )
            ";
            $db->query($roomSql);
            $state['rooms'][$room['key']] = [
                'id' => (int)$db->insert_id,
                'name' => $room['name'],
                'amb_id' => $ambId,
                'amb_name' => $location['name'],
            ];
            $roomCount++;
        }
    }

    $report['summary']['ambulatori'] = $locationCount;
    $report['summary']['stanze'] = $roomCount;
}

function seedUsersAndStaff(mysqli $db, array $staffFixtures, array &$state, array &$report): void
{
    $userCount = 0;
    $staffCount = 0;

    foreach ($staffFixtures as $fixture) {
        $userId = insertUser($db, $fixture['username'], $fixture['password'], (int)$fixture['tipo_user']);
        $state['users'][$fixture['key']] = [
            'id' => $userId,
            'username' => $fixture['username'],
            'tipo_user' => (int)$fixture['tipo_user'],
        ];
        $userCount++;

        $groupId = (int)($state['groups'][$fixture['group_key']]['id'] ?? 0);
        $personaleId = insertStaffPerson($db, $userId, $fixture, $groupId);

        $legacyIdDot = 0;
        if ((int)$fixture['tipo_personale'] === 1) {
            $legacyIdDot = DEMO_SEED_LOCAL_DOCTOR_ID_BASE + $personaleId;
            $db->query("UPDATE dap03_personale SET legacy_id_dot = {$legacyIdDot} WHERE id_personale = {$personaleId} LIMIT 1");
        }

        $state['staff'][$fixture['key']] = [
            'id_user' => $userId,
            'username' => $fixture['username'],
            'id_personale' => $personaleId,
            'tipo_personale' => (int)$fixture['tipo_personale'],
            'legacy_id_dot' => $legacyIdDot,
            'full_name' => trim($fixture['qualifica'] . ' ' . $fixture['last_name'] . ' ' . $fixture['first_name']),
        ];
        $staffCount++;
    }

    $report['summary']['users_demo'] = $userCount;
    $report['summary']['personale_demo'] = $staffCount;
}

function insertUser(mysqli $db, string $username, string $password, int $tipoUser): int
{
    $db->query('SET @init_vector = RANDOM_BYTES(16)');
    $expiry = date('Y-m-d H:i:s', strtotime('+1 year'));
    $sql = "
        INSERT INTO dap01_users (
            username,
            password,
            datascadenza,
            tipo_user,
            privacy,
            data_privacy,
            is_active,
            vector_id
        ) VALUES (
            " . sqlLiteral($db, $username) . ",
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $password) . ", @key_str, @init_vector)),
            " . sqlLiteral($db, $expiry) . ",
            {$tipoUser},
            1,
            CURDATE(),
            1,
            @init_vector
        )
    ";
    $db->query($sql);

    return (int)$db->insert_id;
}

function insertStaffPerson(mysqli $db, int $userId, array $fixture, int $groupId): int
{
    $db->query('SET @init_vector = RANDOM_BYTES(16)');

    $tipoPersonale = (int)$fixture['tipo_personale'];
    $isDoctor = $tipoPersonale === 1 ? 1 : 0;
    $sql = "
        INSERT INTO dap03_personale (
            id_user,
            nome,
            cognome,
            qualifica,
            tipo,
            email,
            cellulare,
            vector_id,
            is_dot,
            f_dom,
            legacy_dot_tipo_id,
            sostituto,
            titolare,
            luogo,
            is_active,
            show_in_agenda,
            show_in_posta,
            show_in_chat
        ) VALUES (
            {$userId},
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['first_name']) . ", @key_str, @init_vector)),
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['last_name']) . ", @key_str, @init_vector)),
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['qualifica']) . ", @key_str, @init_vector)),
            {$tipoPersonale},
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['email']) . ", @key_str, @init_vector)),
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['phone']) . ", @key_str, @init_vector)),
            @init_vector,
            {$isDoctor},
            " . ((int)($fixture['f_dom'] ?? 0)) . ",
            " . ((int)($fixture['legacy_dot_tipo_id'] ?? 0)) . ",
            " . ((int)($fixture['sostituto'] ?? 0)) . ",
            " . ((int)($fixture['titolare'] ?? ($isDoctor ? 1 : 0))) . ",
            {$groupId},
            1,
            " . ((int)($fixture['show_in_agenda'] ?? 1)) . ",
            " . ((int)($fixture['show_in_posta'] ?? 1)) . ",
            " . ((int)($fixture['show_in_chat'] ?? 1)) . "
        )
    ";

    $db->query($sql);
    return (int)$db->insert_id;
}

function seedClients(mysqli $db, array $clientFixtures, array &$state, array &$report): void
{
    $clientCount = 0;
    $portalUserCount = 0;

    foreach ($clientFixtures as $fixture) {
        $userId = null;
        if (!empty($fixture['portal_username'])) {
            $userId = insertUser($db, (string)$fixture['portal_username'], (string)$fixture['portal_password'], 3);
            $state['users'][$fixture['key'] . '_portal'] = [
                'id' => $userId,
                'username' => $fixture['portal_username'],
                'tipo_user' => 3,
            ];
            $portalUserCount++;
        }

        $doctor = $state['staff'][$fixture['doctor_key']] ?? null;
        if (!$doctor) {
            throw new RuntimeException('Dottore demo non trovato per il cliente ' . $fixture['key']);
        }

        $clientId = insertClient($db, $fixture, $doctor['id_personale'], $userId);
        $state['clients'][$fixture['key']] = [
            'id_client' => $clientId,
            'id_user' => $userId,
            'id_personale' => $doctor['id_personale'],
            'legacy_id_paziente' => (int)$fixture['legacy_id_paziente'],
            'legacy_id_dot' => (int)$doctor['legacy_id_dot'],
            'doctor_key' => $fixture['doctor_key'],
            'full_name' => trim($fixture['last_name'] . ' ' . $fixture['first_name']),
            'phone' => $fixture['mobile'],
            'email' => $fixture['email'],
            'note' => $fixture['paz_spec'],
            'fiscal_code' => $fixture['fiscal_code'],
        ];

        $db->query("INSERT INTO dap09_client_doctor (id_client, id_dot) VALUES ({$clientId}, {$doctor['id_personale']})");
        $clientCount++;
    }

    $report['summary']['clients_demo'] = $clientCount;
    $report['summary']['client_portal_users'] = $portalUserCount;
}

function insertClient(mysqli $db, array $fixture, int $doctorPersonaleId, ?int $userId): int
{
    $db->query('SET @init_vector = RANDOM_BYTES(16)');

    $sql = "
        INSERT INTO dap02_clients (
            id_user,
            nome,
            cognome,
            cellulare,
            email,
            indirizzo,
            citta,
            vector_id,
            provincia,
            codice_fiscale,
            id_personale,
            legacy_id_paziente,
            avviso_mail,
            telefono,
            paz_spec,
            bloccato
        ) VALUES (
            " . ($userId !== null ? (string)$userId : 'NULL') . ",
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['first_name']) . ", @key_str, @init_vector)),
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['last_name']) . ", @key_str, @init_vector)),
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['mobile']) . ", @key_str, @init_vector)),
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['email']) . ", @key_str, @init_vector)),
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['address']) . ", @key_str, @init_vector)),
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['city']) . ", @key_str, @init_vector)),
            @init_vector,
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['province']) . ", @key_str, @init_vector)),
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['fiscal_code']) . ", @key_str, @init_vector)),
            {$doctorPersonaleId},
            " . ((int)$fixture['legacy_id_paziente']) . ",
            1,
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['phone']) . ", @key_str, @init_vector)),
            HEX(AES_ENCRYPT(" . sqlLiteral($db, $fixture['paz_spec']) . ", @key_str, @init_vector)),
            0
        )
    ";

    $db->query($sql);
    return (int)$db->insert_id;
}

function seedUserSchede(mysqli $db, array $state, array &$report): void
{
    $schedeMap = fetchSchedeIdMap($db);
    $rowsInserted = 0;

    foreach ($state['users'] as $key => $user) {
        $username = (string)($user['username'] ?? '');
        $tipoUser = (int)($user['tipo_user'] ?? 0);

        $codes = match ($tipoUser) {
            1, 2 => ['agenda', 'posta', 'chat'],
            3 => ['posta', 'chat'],
            default => [],
        };

        foreach ($codes as $code) {
            if (!isset($schedeMap[$code])) {
                continue;
            }

            $sql = "
                INSERT INTO dap_user_schede (id_user, id_scheda, can_view, can_access)
                VALUES ({$user['id']}, {$schedeMap[$code]}, 1, 1)
            ";
            $db->query($sql);
            $rowsInserted++;
        }

        if ($username === 'demo.admin' && isset($schedeMap['agenda'])) {
            // Admin usa il backend, ma teniamo comunque le schede utente valorizzate per debug/demo.
        }
    }

    $report['summary']['schede_user_rows'] = $rowsInserted;
}

function fetchSchedeIdMap(mysqli $db): array
{
    $rows = $db->query('SELECT id_scheda, codice FROM dap_menu_schede WHERE attiva = 1')->fetch_all(MYSQLI_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $map[(string)$row['codice']] = (int)$row['id_scheda'];
    }

    return $map;
}

function seedStaffLinks(mysqli $db, array $links, array &$state, array &$report): void
{
    $segCount = 0;
    $infCount = 0;
    $agendaVisibilityCount = 0;
    $agendaVisibilityExists = tableExists($db, 'dap24_agenda_visibilita');

    foreach ($links as $link) {
        $staff = $state['staff'][$link['staff_key']] ?? null;
        if (!$staff) {
            throw new RuntimeException('Staff demo non trovato per link: ' . $link['staff_key']);
        }

        foreach ($link['doctor_keys'] as $doctorKey) {
            $doctor = $state['staff'][$doctorKey] ?? null;
            if (!$doctor) {
                throw new RuntimeException('Dottore demo non trovato per link: ' . $doctorKey);
            }

            if ($link['type'] === 'segreteria') {
                $db->query("INSERT INTO dap14_seg_dot (id_seg, id_dot) VALUES ({$staff['id_personale']}, {$doctor['id_personale']})");
                if ($agendaVisibilityExists) {
                    foreach (staffAgendaVisibilityOperatorIds($staff) as $operatorId) {
                        $db->query("
                            INSERT INTO dap24_agenda_visibilita (id_ope, id_dot, created_by, created_at)
                            VALUES ({$operatorId}, {$doctor['legacy_id_dot']}, {$staff['id_user']}, NOW())
                        ");
                        $agendaVisibilityCount++;
                    }
                }
                $segCount++;
                continue;
            }

            if ($link['type'] === 'infermiere') {
                $db->query("INSERT INTO dap15_inf_dot (id_inf, id_dot) VALUES ({$staff['id_personale']}, {$doctor['id_personale']})");
                if ($agendaVisibilityExists) {
                    foreach (staffAgendaVisibilityOperatorIds($staff) as $operatorId) {
                        $db->query("
                            INSERT INTO dap24_agenda_visibilita (id_ope, id_dot, created_by, created_at)
                            VALUES ({$operatorId}, {$doctor['legacy_id_dot']}, {$staff['id_user']}, NOW())
                        ");
                        $agendaVisibilityCount++;
                    }
                }
                $infCount++;
            }
        }
    }

    $report['summary']['link_segreterie'] = $segCount;
    $report['summary']['link_infermieri'] = $infCount;
    $report['summary']['agenda_visibility_links'] = $agendaVisibilityCount;
}

function staffAgendaVisibilityOperatorIds(array $staff): array
{
    $ids = [];

    $idPersonale = (int)($staff['id_personale'] ?? 0);
    if ($idPersonale > 0) {
        $ids[] = $idPersonale;
    }

    $legacyIdOpe = (int)($staff['legacy_id_ope'] ?? 0);
    if ($legacyIdOpe > 0) {
        $ids[] = $legacyIdOpe;
    }

    $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    sort($ids);

    return $ids;
}

function seedDoctorReminderFlags(mysqli $db, array &$state, array &$report): void
{
    $count = 0;
    foreach ($state['staff'] as $staff) {
        $legacyIdDot = (int)($staff['legacy_id_dot'] ?? 0);
        if ($legacyIdDot <= 0) {
            continue;
        }

        $db->query("INSERT INTO dap39_sms_dot (id_dot, conferma) VALUES ({$legacyIdDot}, 1)");
        $count++;
    }

    $report['summary']['doctor_reminder_flags'] = $count;
}

function seedAgenda(mysqli $db, array $agendaFixtures, array $businessDays, array &$state, array &$report): void
{
    $slotCount = 0;
    $appointmentCount = 0;

    foreach ($agendaFixtures as $fixture) {
        $doctor = $state['staff'][$fixture['doctor_key']] ?? null;
        $room = $state['rooms'][$fixture['room_key']] ?? null;
        if (!$doctor || !$room) {
            throw new RuntimeException('Configurazione agenda demo incompleta per ' . $fixture['doctor_key']);
        }

        $doctorClients = clientsForDoctor($state['clients'], (string)$fixture['doctor_key']);
        if ($doctorClients === []) {
            continue;
        }

        foreach ($businessDays as $dayIndex => $date) {
            $slotsPerDay = (int)$fixture['slots_per_day'];
            $bookedSlots = min($slotsPerDay, agendaFixtureBookedSlotsForDay($fixture, $dayIndex, $date));
            $blockedSlotIndex = agendaFixtureBlockedSlotIndexForDay($fixture, $dayIndex, $date);
            if ($blockedSlotIndex >= $slotsPerDay) {
                $blockedSlotIndex = -1;
            }
            $duration = (int)$fixture['slot_minutes'];
            $cursorTime = agendaFixtureStartTimeForDay($fixture, $dayIndex);

            for ($slotIndex = 0; $slotIndex < $slotsPerDay; $slotIndex++) {
                $slotStart = date('Y-m-d H:i:s', strtotime($date . ' ' . $cursorTime));
                $slotEnd = date('Y-m-d H:i:s', strtotime($slotStart . " +{$duration} minutes"));
                $slotState = 'LIBERO';
                $slotTitle = null;
                $slotNote = null;

                if ($blockedSlotIndex === $slotIndex) {
                    $slotState = 'BLOCCATO';
                    $slotTitle = 'Riunione interna';
                    $slotNote = 'Spazio riservato a coordinamento team';
                } elseif ($slotIndex < $bookedSlots) {
                    $slotState = 'PRENOTATO';
                }

                $slotId = insertAgendaSlot(
                    $db,
                    (int)$doctor['legacy_id_dot'],
                    $room,
                    $date,
                    $slotStart,
                    $slotEnd,
                    $slotState,
                    $slotTitle,
                    $slotNote
                );
                $slotCount++;

                if ($slotState === 'PRENOTATO') {
                    $client = $doctorClients[$slotIndex % count($doctorClients)];
                    $appointmentStatus = $dayIndex === 0 ? 'CONFERMATO' : ($slotIndex % 3 === 0 ? 'IN_ATTESA' : 'CONFERMATO');
                    insertAppointment(
                        $db,
                        $slotId,
                        (int)$doctor['legacy_id_dot'],
                        $client,
                        (int)($state['users']['demo_admin']['id'] ?? $state['staff']['demo_admin']['id_user'] ?? 0),
                        $fixture['visit_reasons'][$slotIndex % count($fixture['visit_reasons'])],
                        $appointmentStatus
                    );
                    $appointmentCount++;
                }

                $cursorTime = date('H:i:s', strtotime($slotEnd));
            }
        }
    }

    $report['summary']['agenda_slots'] = $slotCount;
    $report['summary']['agenda_appointments'] = $appointmentCount;
}

function clientsForDoctor(array $clients, string $doctorKey): array
{
    $result = [];
    foreach ($clients as $key => $client) {
        if (($client['doctor_key'] ?? null) === $doctorKey) {
            $result[] = $client;
        }
    }

    return $result;
}

function agendaFixtureStartTimeForDay(array $fixture, int $dayIndex): string
{
    $startTimes = is_array($fixture['start_times'] ?? null) ? $fixture['start_times'] : [];
    $selected = $startTimes[$dayIndex] ?? $startTimes[0] ?? '09:00:00';

    return is_string($selected) && $selected !== '' ? $selected : '09:00:00';
}

function agendaFixtureBookedSlotsForDay(array $fixture, int $dayIndex, string $date): int
{
    if (isset($fixture['booked_per_day'][$dayIndex])) {
        return max(0, (int)$fixture['booked_per_day'][$dayIndex]);
    }

    $dayOfWeek = (int)date('N', strtotime($date));
    $recurring = is_array($fixture['recurring_booked_per_weekday'] ?? null)
        ? $fixture['recurring_booked_per_weekday']
        : [];

    return max(0, (int)($recurring[$dayOfWeek] ?? 0));
}

function agendaFixtureBlockedSlotIndexForDay(array $fixture, int $dayIndex, string $date): int
{
    if (isset($fixture['blocked_slot_index'][$dayIndex])) {
        return (int)$fixture['blocked_slot_index'][$dayIndex];
    }

    $dayOfWeek = (int)date('N', strtotime($date));
    $recurring = is_array($fixture['recurring_blocked_slot_index_by_weekday'] ?? null)
        ? $fixture['recurring_blocked_slot_index_by_weekday']
        : [];

    return (int)($recurring[$dayOfWeek] ?? -1);
}

function insertAgendaSlot(
    mysqli $db,
    int $legacyDoctorId,
    array $room,
    string $date,
    string $slotStart,
    string $slotEnd,
    string $state,
    ?string $title,
    ?string $note
): int {
    $sql = "
        INSERT INTO dap11_agenda_slot (
            id_dot,
            id_config,
            data_slot,
            ora_inizio,
            ora_fine,
            tipo_slot,
            stato,
            titolo_libero,
            id_amb_legacy,
            id_stanza,
            ambulatorio,
            stanza,
            origine_slot,
            note_interne,
            created_at,
            updated_at
        ) VALUES (
            {$legacyDoctorId},
            NULL,
            " . sqlLiteral($db, $date) . ",
            " . sqlLiteral($db, $slotStart) . ",
            " . sqlLiteral($db, $slotEnd) . ",
            'AMBULATORIO',
            " . sqlLiteral($db, $state) . ",
            " . sqlNullableLiteral($db, $title) . ",
            {$room['amb_id']},
            {$room['id']},
            " . sqlLiteral($db, $room['amb_name']) . ",
            " . sqlLiteral($db, $room['name']) . ",
            'EXTRA',
            " . sqlNullableLiteral($db, $note) . ",
            NOW(),
            NOW()
        )
    ";
    $db->query($sql);

    return (int)$db->insert_id;
}

function insertAppointment(
    mysqli $db,
    int $slotId,
    int $legacyDoctorId,
    array $client,
    int $createdByUserId,
    string $reason,
    string $status
): void {
    $firstName = firstNameFromFullName((string)$client['full_name']);
    $lastName = lastNameFromFullName((string)$client['full_name']);

    $sql = "
        INSERT INTO dap12_agenda_appuntamenti (
            id_slot,
            id_dot,
            id_paziente,
            id_client,
            cognome,
            nome,
            telefono,
            cellulare,
            email,
            note,
            motivo_visita,
            indirizzo_visita,
            comune_visita,
            stato,
            created_by,
            created_at
        ) VALUES (
            {$slotId},
            {$legacyDoctorId},
            {$client['legacy_id_paziente']},
            {$client['id_client']},
            " . sqlLiteral($db, $lastName) . ",
            " . sqlLiteral($db, $firstName) . ",
            " . sqlLiteral($db, $client['phone']) . ",
            " . sqlLiteral($db, $client['phone']) . ",
            " . sqlLiteral($db, $client['email']) . ",
            " . sqlLiteral($db, 'Prenotazione demo generata automaticamente.') . ",
            " . sqlLiteral($db, $reason) . ",
            NULL,
            NULL,
            " . sqlLiteral($db, $status) . ",
            " . ($createdByUserId > 0 ? $createdByUserId : 'NULL') . ",
            NOW()
        )
    ";
    $db->query($sql);
}

function seedHomeVisits(mysqli $db, array $fixtures, array &$state, array &$report): void
{
    $count = 0;
    foreach ($fixtures as $fixture) {
        $doctor = $state['staff'][$fixture['doctor_key']] ?? null;
        $client = $state['clients'][$fixture['client_key']] ?? null;
        if (!$doctor || !$client) {
            throw new RuntimeException('Configurazione visite domiciliari demo incompleta.');
        }

        $sql = "
            INSERT INTO dap13_visite_domiciliari (
                id_dot,
                id_paziente,
                id_client,
                giorno_visita,
                cognome,
                nome,
                telefono,
                cellulare,
                indirizzo,
                citta,
                note,
                data_creazione,
                data_modifica,
                stato,
                legacy_id_vis
            ) VALUES (
                {$doctor['legacy_id_dot']},
                {$client['legacy_id_paziente']},
                {$client['id_client']},
                " . sqlLiteral($db, $fixture['date']) . ",
                " . sqlLiteral($db, lastNameFromFullName($client['full_name'])) . ",
                " . sqlLiteral($db, firstNameFromFullName($client['full_name'])) . ",
                " . sqlLiteral($db, $client['phone']) . ",
                " . sqlLiteral($db, $client['phone']) . ",
                " . sqlLiteral($db, $fixture['address']) . ",
                " . sqlLiteral($db, $fixture['city']) . ",
                " . sqlLiteral($db, $fixture['note']) . ",
                NOW(),
                NOW(),
                'ATTIVA',
                {$fixture['legacy_id_vis']}
            )
        ";
        $db->query($sql);
        $count++;
    }

    $report['summary']['home_visits'] = $count;
}

function seedChatData(mysqli $db, array $fixtures, array &$state, array &$report): void
{
    $threadCount = 0;
    $messageCount = 0;

    foreach ($fixtures as $fixture) {
        $members = [];
        foreach ($fixture['member_staff_keys'] as $staffKey) {
            $staff = $state['staff'][$staffKey] ?? null;
            if (!$staff) {
                throw new RuntimeException('Membro chat demo non trovato: ' . $staffKey);
            }
            $members[$staffKey] = $staff;
        }

        $threadId = createChatThread(
            $db,
            $fixture['thread_type'],
            $fixture['group_key_prefix'],
            $fixture['title'],
            $members
        );
        $threadCount++;

        foreach ($fixture['messages'] as $messageIndex => $message) {
            $sender = $members[$message['sender_key']] ?? null;
            if (!$sender) {
                throw new RuntimeException('Mittente chat demo non trovato: ' . $message['sender_key']);
            }

            insertChatMessage($db, $threadId, (int)$sender['id_user'], $message['body'], $message['created_at']);
            $messageCount++;
        }

        foreach ($fixture['read_state'] as $readerKey => $lastReadAt) {
            $reader = $members[$readerKey] ?? null;
            if (!$reader) {
                continue;
            }

            $sql = "
                UPDATE dap_chat_thread_user
                SET last_read_at = " . sqlNullableLiteral($db, $lastReadAt) . "
                WHERE id_thread = {$threadId}
                  AND id_user = {$reader['id_user']}
                LIMIT 1
            ";
            $db->query($sql);
        }
    }

    $report['summary']['chat_threads'] = $threadCount;
    $report['summary']['chat_messages'] = $messageCount;
}

function createChatThread(mysqli $db, string $threadType, string $groupKeyPrefix, string $title, array $members): int
{
    $doctorUser = null;
    foreach ($members as $member) {
        if ((int)($member['tipo_personale'] ?? 0) === 1) {
            $doctorUser = (int)$member['id_user'];
            break;
        }
    }

    $groupKey = $groupKeyPrefix !== '' && $doctorUser !== null
        ? $groupKeyPrefix . '_' . $doctorUser
        : null;

    $sql = "
        INSERT INTO dap_chat_thread (thread_type, group_key, title, created_at)
        VALUES (
            " . sqlLiteral($db, $threadType) . ",
            " . sqlNullableLiteral($db, $groupKey) . ",
            " . sqlLiteral($db, $title) . ",
            NOW()
        )
    ";
    $db->query($sql);
    $threadId = (int)$db->insert_id;

    foreach ($members as $member) {
        $db->query("
            INSERT INTO dap_chat_thread_user (id_thread, id_user, last_read_at, cleared_at)
            VALUES ({$threadId}, {$member['id_user']}, NULL, NULL)
        ");
    }

    return $threadId;
}

function insertChatMessage(mysqli $db, int $threadId, int $senderUserId, string $body, string $createdAt): void
{
    $sql = "
        INSERT INTO dap_chat_message (id_thread, sender_id, body, created_at)
        VALUES (
            {$threadId},
            {$senderUserId},
            " . sqlLiteral($db, $body) . ",
            " . sqlLiteral($db, $createdAt) . "
        )
    ";
    $db->query($sql);
}

function seedPostaData(mysqli $db, array $fixtures, array &$state, array &$report): void
{
    $threadCount = 0;
    $messageCount = 0;

    foreach ($fixtures as $fixture) {
        $patient = $state['clients'][$fixture['client_key']] ?? null;
        $doctor = $state['staff'][$fixture['doctor_key']] ?? null;
        if (!$patient || !$doctor) {
            throw new RuntimeException('Configurazione posta demo incompleta.');
        }

        $threadId = createPostaThread($db, $patient, $doctor, $fixture['messages']);
        $threadCount++;
        $messageCount += count($fixture['messages']);

        if (!empty($fixture['mark_patient_read'])) {
            markPostaThreadReadForUser($db, $threadId, (int)$patient['id_client']);
        }
        if (!empty($fixture['mark_doctor_read'])) {
            markPostaThreadReadForUser($db, $threadId, (int)$doctor['id_personale']);
        }
    }

    $report['summary']['posta_threads'] = $threadCount;
    $report['summary']['posta_messages'] = $messageCount;
}

function createPostaThread(mysqli $db, array $patient, array $doctor, array $messages): int
{
    $rootAuthorClientId = (int)$patient['id_client'];
    $doctorPersonaleId = (int)$doctor['id_personale'];
    $db->query("INSERT INTO msg_threads (root_author_user_id, created_at, updated_at) VALUES ({$rootAuthorClientId}, NOW(), NOW())");
    $threadId = (int)$db->insert_id;
    $rootMessageId = 0;
    $parentMessageId = null;

    foreach ($messages as $index => $message) {
        $vectorHex = bin2hex(random_bytes(16));
        $senderRole = strtolower(trim((string)($message['sender'] ?? 'patient')));
        $senderActorId = $senderRole === 'doctor' ? $doctorPersonaleId : $rootAuthorClientId;
        $recipientActorId = $senderRole === 'doctor' ? $rootAuthorClientId : $doctorPersonaleId;
        $messageType = $index === 0 ? 'ROOT' : 'REPLY';
        $replyToUserId = $index === 0 ? 'NULL' : (string)$recipientActorId;
        $rootMessageValue = $index === 0 ? 'NULL' : (string)$rootMessageId;
        $parentMessageValue = $parentMessageId !== null ? (string)$parentMessageId : 'NULL';

        $sql = "
            INSERT INTO msg_messages (
                id_thread,
                message_type,
                status,
                root_message_id,
                parent_message_id,
                reply_to_user_id,
                sender_user_id,
                recipient_type,
                recipient_user_id,
                recipient_role,
                body_cipher_hex,
                vector_id,
                root_author_user_id,
                created_at
            ) VALUES (
                {$threadId},
                " . sqlLiteral($db, $messageType) . ",
                'SENT',
                {$rootMessageValue},
                {$parentMessageValue},
                {$replyToUserId},
                {$senderActorId},
                'USER',
                {$recipientActorId},
                NULL,
                HEX(AES_ENCRYPT(" . sqlLiteral($db, $message['body']) . ", @key_str, UNHEX('{$vectorHex}'))),
                UNHEX('{$vectorHex}'),
                {$rootAuthorClientId},
                " . sqlLiteral($db, $message['created_at']) . "
            )
        ";
        $db->query($sql);
        $messageId = (int)$db->insert_id;

        if ($index === 0) {
            $rootMessageId = $messageId;
            $db->query("UPDATE msg_threads SET root_message_id = {$rootMessageId}, updated_at = NOW() WHERE id_thread = {$threadId} LIMIT 1");
        } else {
            $db->query("UPDATE msg_messages SET root_message_id = {$rootMessageId} WHERE id_message = {$messageId} LIMIT 1");
            $db->query("UPDATE msg_threads SET updated_at = NOW() WHERE id_thread = {$threadId} LIMIT 1");
        }

        $parentMessageId = $messageId;
    }

    return $threadId;
}

function markPostaThreadReadForUser(mysqli $db, int $threadId, int $userId): void
{
    $sql = "
        INSERT INTO msg_user_flags (
            id_message,
            user_id,
            is_read,
            read_at
        )
        SELECT
            m.id_message,
            {$userId},
            1,
            NOW()
        FROM msg_messages m
        WHERE m.id_thread = {$threadId}
        ON DUPLICATE KEY UPDATE
            is_read = 1,
            read_at = NOW()
    ";
    $db->query($sql);
}

function rebuildDoctorPatientSearch(mysqli $db, array $state, array &$report): void
{
    if (!tableExists($db, 'dap26_doctor_patient_search')) {
        return;
    }

    $db->query('TRUNCATE TABLE dap26_doctor_patient_search');
    $count = 0;

    foreach ($state['clients'] as $client) {
        $legacyDoctorId = (int)($client['legacy_id_dot'] ?? 0);
        if ($legacyDoctorId <= 0) {
            continue;
        }

        $fullName = strtolower(trim((string)$client['full_name']));
        $parts = preg_split('/\s+/', $fullName) ?: [];
        $lastName = strtolower((string)($parts[0] ?? ''));
        $firstName = strtolower(implode(' ', array_slice($parts, 1)));

        $sql = "
            INSERT INTO dap26_doctor_patient_search (
                id_dot,
                id_client,
                cognome_norm,
                nome_norm,
                full_norm,
                cf_norm,
                tel_norm,
                cell_norm,
                email_norm,
                paz_spec_norm,
                updated_at
            ) VALUES (
                {$legacyDoctorId},
                {$client['id_client']},
                " . sqlLiteral($db, normalizeSearchValue($lastName)) . ",
                " . sqlLiteral($db, normalizeSearchValue($firstName)) . ",
                " . sqlLiteral($db, normalizeSearchValue($fullName)) . ",
                " . sqlLiteral($db, normalizeCodeValue((string)($client['fiscal_code'] ?? ''))) . ",
                " . sqlLiteral($db, normalizePhoneValue((string)($client['phone'] ?? ''))) . ",
                " . sqlLiteral($db, normalizePhoneValue((string)($client['phone'] ?? ''))) . ",
                " . sqlLiteral($db, normalizeSearchValue((string)($client['email'] ?? ''))) . ",
                " . sqlLiteral($db, normalizeSearchValue((string)($client['note'] ?? ''))) . ",
                NOW()
            )
        ";
        $db->query($sql);
        $count++;
    }

    $report['summary']['doctor_patient_search_rows'] = $count;
}

function firstNameFromFullName(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];
    return implode(' ', array_slice($parts, 1));
}

function lastNameFromFullName(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];
    return (string)($parts[0] ?? '');
}

function normalizeSearchValue(string $value): string
{
    $value = strtolower(trim($value));
    return preg_replace('/\s+/', ' ', $value) ?? '';
}

function normalizePhoneValue(string $value): string
{
    return preg_replace('/\D+/', '', strtolower(trim($value))) ?? '';
}

function normalizeCodeValue(string $value): string
{
    return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', trim($value)) ?? '');
}

function businessDaysInRange(string $startDate, string $endDate): array
{
    $days = [];
    $cursor = strtotime($startDate);
    $end = strtotime($endDate);

    while ($cursor !== false && $end !== false && $cursor <= $end) {
        if ((int)date('N', $cursor) <= 5) {
            $days[] = date('Y-m-d', $cursor);
        }
        $cursor = strtotime('+1 day', $cursor);
    }

    return $days;
}

function buildAccountSummary(array $staffFixtures, array $clientFixtures): array
{
    $accounts = [];
    foreach ($staffFixtures as $fixture) {
        $accounts[] = [
            'type' => 'staff',
            'key' => $fixture['key'],
            'username' => $fixture['username'],
            'label' => trim($fixture['qualifica'] . ' ' . $fixture['last_name'] . ' ' . $fixture['first_name']),
        ];
    }

    foreach ($clientFixtures as $fixture) {
        if (empty($fixture['portal_username'])) {
            continue;
        }

        $accounts[] = [
            'type' => 'client_portal',
            'key' => $fixture['key'],
            'username' => $fixture['portal_username'],
            'label' => trim($fixture['last_name'] . ' ' . $fixture['first_name']),
        ];
    }

    return $accounts;
}

function buildFixtures(string $demoPassword, array $futureDays): array
{
    $clients = [
        clientFixture('nutrition_01', 'Laura', 'Bianchi', 'nutrition_lead', 61001, 'Prima visita nutrizionale dimagrimento', 'laura.bianchi@example.test', '3477001001', '0217001001', 'Via Solari 12', 'Milano', 'MI', 'BNCLRA80A41F205A', 'demo.portal.nutri', $demoPassword),
        clientFixture('nutrition_02', 'Marco', 'Conti', 'nutrition_lead', 61002, 'Controllo piano alimentare e aderenza', 'marco.conti@example.test', '3477001002', '0217001002', 'Via Savona 24', 'Milano', 'MI', 'CNTMRC79B12F205B'),
        clientFixture('nutrition_03', 'Sara', 'Rinaldi', 'nutrition_lead', 61003, 'Follow up insulino-resistenza', 'sara.rinaldi@example.test', '3477001003', '0217001003', 'Via Foppa 7', 'Milano', 'MI', 'RNLSRA84C53F205C'),
        clientFixture('nutrition_04', 'Paolo', 'Gatti', 'nutrition_lead', 61004, 'Educazione alimentare colon irritabile', 'paolo.gatti@example.test', '3477001004', '0217001004', 'Viale Coni Zugna 81', 'Milano', 'MI', 'GTTPLA76D14F205D'),
        clientFixture('nutrition_05', 'Elena', 'Neri', 'nutrition_lead', 61005, 'Ricomposizione corporea e monitoraggio', 'elena.neri@example.test', '3477001005', '0217001005', 'Via Tortona 48', 'Milano', 'MI', 'NRELEN88E55F205E'),
        clientFixture('nutrition_06', 'Giulia', 'Pellegrini', 'nutrition_lead', 61006, 'Consulenza nutrizionale post partum', 'giulia.pellegrini@example.test', '3477001006', '0217001006', 'Via Savona 110', 'Milano', 'MI', 'PLLGLI88F16F205F'),
        clientFixture('nutrition_07', 'Chiara', 'Marini', 'nutrition_collab', 61007, 'Bioimpedenziometria e follow up sportivo', 'chiara.marini@example.test', '3477001007', '0217001007', 'Via Arena 5', 'Milano', 'MI', 'MRNCHR93A41F205M'),
        clientFixture('nutrition_08', 'Davide', 'Greco', 'nutrition_collab', 61008, 'Revisione diario alimentare', 'davide.greco@example.test', '3477001008', '0217001008', 'Via Ampere 72', 'Milano', 'MI', 'GRCDVD77H18F205H'),
        clientFixture('nutrition_09', 'Marta', 'Leone', 'nutrition_collab', 61009, 'Nutrizione vegetariana e integrazione', 'marta.leone@example.test', '3477001009', '0217001009', 'Via Vallazze 15', 'Milano', 'MI', 'LNEMRT81I59F205I'),
        clientFixture('nutrition_10', 'Stefano', 'Pini', 'nutrition_collab', 61010, 'Aumento massa muscolare', 'stefano.pini@example.test', '3477001010', '0217001010', 'Via Bassini 19', 'Milano', 'MI', 'PNISFN75L20F205L'),
        clientFixture('nutrition_11', 'Riccardo', 'De Luca', 'nutrition_team_3', 61011, 'Percorso nutrizione clinica per turni di lavoro', 'riccardo.deluca@example.test', '3477001011', '0217001011', 'Via Cadorna 9', 'Milano', 'MI', 'DLCRCR82A15F205R'),
        clientFixture('nutrition_12', 'Ilaria', 'Monti', 'nutrition_team_3', 61012, 'Follow up sindrome del colon irritabile', 'ilaria.monti@example.test', '3477001012', '0217001012', 'Via Washington 31', 'Milano', 'MI', 'MNTLRI87B56F205S'),
        clientFixture('nutrition_13', 'Tommaso', 'Riva', 'nutrition_team_3', 61013, 'Educazione alimentare pre gara podistica', 'tommaso.riva@example.test', '3477001013', '0217001013', 'Via San Vittore 14', 'Milano', 'MI', 'RVITMS90C17F205T'),
        clientFixture('nutrition_14', 'Beatrice', 'Sala', 'nutrition_team_3', 61014, 'Riorganizzazione pasti per lavoro su turni', 'beatrice.sala@example.test', '3477001014', '0217001014', 'Via Solferino 28', 'Milano', 'MI', 'SLABRC85D58F205U'),
        clientFixture('sport_01', 'Chiara', 'Marini', 'sport_physio_1', 62001, 'Recupero caviglia post distorsione', 'chiara.marini@example.test', '3477002001', '0288002001', 'Via Arena 5', 'Milano', 'MI', 'MRNCHR93A41F205M', 'demo.portal.sport', $demoPassword),
        clientFixture('sport_02', 'Luca', 'Serra', 'sport_physio_1', 62002, 'Valutazione ritorno alla corsa', 'luca.serra@example.test', '3477002002', '0288002002', 'Via Procaccini 22', 'Milano', 'MI', 'SRRLCU91B12F205N'),
        clientFixture('sport_03', 'Federica', 'Longhi', 'sport_physio_1', 62003, 'Trattamento cervicale sportivo', 'federica.longhi@example.test', '3477002003', '0288002003', 'Via Piero della Francesca 47', 'Milano', 'MI', 'LNGFRC90C53F205O'),
        clientFixture('sport_04', 'Andrea', 'Moro', 'sport_physio_1', 62004, 'Seduta recupero quadricipite', 'andrea.moro@example.test', '3477002004', '0288002004', 'Via Cenisio 14', 'Milano', 'MI', 'MRODRN92D14F205P'),
        clientFixture('sport_05', 'Valentina', 'Fontana', 'sport_physio_1', 62005, 'Controllo postura runner', 'valentina.fontana@example.test', '3477002005', '0288002005', 'Via Losanna 16', 'Milano', 'MI', 'FNTVNT89E55F205Q'),
        clientFixture('sport_06', 'Matteo', 'Rossetti', 'sport_physio_2', 62006, 'Dolore lombare atleta', 'matteo.rossetti@example.test', '3477002006', '0288002006', 'Via Canonica 25', 'Milano', 'MI', 'RSSMTT88F16F205R'),
        clientFixture('sport_07', 'Beatrice', 'Sala', 'sport_physio_2', 62007, 'Seduta osteopatica preventiva', 'beatrice.sala@example.test', '3477002007', '0288002007', 'Via Borsieri 9', 'Milano', 'MI', 'SLABRC87G57F205S'),
        clientFixture('sport_08', 'Riccardo', 'Testa', 'sport_physio_2', 62008, 'Mobilita spalla tennis', 'riccardo.testa@example.test', '3477002008', '0288002008', 'Via Pasubio 18', 'Milano', 'MI', 'TSTRCR86H18F205T'),
        clientFixture('sport_09', 'Camilla', 'Grassi', 'sport_physio_2', 62009, 'Valutazione catena posteriore', 'camilla.grassi@example.test', '3477002009', '0288002009', 'Via Alserio 12', 'Milano', 'MI', 'GRSCML85I59F205U'),
        clientFixture('sport_10', 'Tommaso', 'Parisi', 'sport_physio_2', 62010, 'Recupero post maratona', 'tommaso.parisi@example.test', '3477002010', '0288002010', 'Via Farini 33', 'Milano', 'MI', 'PRSTMS94L20F205V'),
    ];

    $staff = [
        [
            'key' => 'demo_admin',
            'username' => 'demo.admin',
            'password' => $demoPassword,
            'tipo_user' => 1,
            'tipo_personale' => 4,
            'first_name' => 'Giulia',
            'last_name' => 'Conti',
            'qualifica' => 'Admin demo',
            'email' => 'admin.demo@example.test',
            'phone' => '3477100000',
            'group_key' => 'nutrition_studio',
            'legacy_dot_tipo_id' => 0,
            'f_dom' => 0,
        ],
        [
            'key' => 'nutrition_lead',
            'username' => 'demo.dietista',
            'password' => $demoPassword,
            'tipo_user' => 2,
            'tipo_personale' => 1,
            'first_name' => 'Professionista1',
            'last_name' => '',
            'qualifica' => '',
            'email' => 'elena.rossi@example.test',
            'phone' => '3477100001',
            'group_key' => 'nutrition_studio',
            'legacy_dot_tipo_id' => 2,
            'f_dom' => 0,
            'titolare' => 1,
        ],
        [
            'key' => 'nutrition_collab',
            'username' => 'demo.nutrizionista',
            'password' => $demoPassword,
            'tipo_user' => 2,
            'tipo_personale' => 1,
            'first_name' => 'Professionista2',
            'last_name' => '',
            'qualifica' => '',
            'email' => 'marta.riva@example.test',
            'phone' => '3477100002',
            'group_key' => 'nutrition_studio',
            'legacy_dot_tipo_id' => 2,
            'f_dom' => 0,
            'titolare' => 1,
        ],
        [
            'key' => 'nutrition_team_3',
            'username' => 'demo.nutrizionista2',
            'password' => $demoPassword,
            'tipo_user' => 2,
            'tipo_personale' => 1,
            'first_name' => 'Professionista3',
            'last_name' => '',
            'qualifica' => '',
            'email' => 'valentina.greco@example.test',
            'phone' => '3477100007',
            'group_key' => 'nutrition_studio',
            'legacy_dot_tipo_id' => 2,
            'f_dom' => 0,
            'titolare' => 1,
        ],
        [
            'key' => 'frontdesk_nutri',
            'username' => 'demo.segreteria',
            'password' => $demoPassword,
            'tipo_user' => 2,
            'tipo_personale' => 3,
            'first_name' => 'Sara',
            'last_name' => 'Colombo',
            'qualifica' => 'Segreteria',
            'email' => 'segreteria.nutri@example.test',
            'phone' => '3477100003',
            'group_key' => 'nutrition_studio',
            'legacy_dot_tipo_id' => 0,
            'f_dom' => 0,
        ],
        [
            'key' => 'sport_physio_1',
            'username' => 'demo.fisio1',
            'password' => $demoPassword,
            'tipo_user' => 2,
            'tipo_personale' => 1,
            'first_name' => 'Marco',
            'last_name' => 'Riva',
            'qualifica' => 'Fisioterapista',
            'email' => 'marco.riva@example.test',
            'phone' => '3477100004',
            'group_key' => 'sport_centro',
            'legacy_dot_tipo_id' => 2,
            'f_dom' => 0,
            'titolare' => 1,
        ],
        [
            'key' => 'sport_physio_2',
            'username' => 'demo.osteopata',
            'password' => $demoPassword,
            'tipo_user' => 2,
            'tipo_personale' => 1,
            'first_name' => 'Lorenzo',
            'last_name' => 'Pace',
            'qualifica' => 'Osteopata',
            'email' => 'lorenzo.pace@example.test',
            'phone' => '3477100005',
            'group_key' => 'sport_centro',
            'legacy_dot_tipo_id' => 2,
            'f_dom' => 0,
            'titolare' => 1,
        ],
        [
            'key' => 'frontdesk_sport',
            'username' => 'demo.frontdesk.sport',
            'password' => $demoPassword,
            'tipo_user' => 2,
            'tipo_personale' => 3,
            'first_name' => 'Irene',
            'last_name' => 'Sala',
            'qualifica' => 'Coordinamento',
            'email' => 'frontdesk.sport@example.test',
            'phone' => '3477100006',
            'group_key' => 'sport_centro',
            'legacy_dot_tipo_id' => 0,
            'f_dom' => 0,
        ],
    ];

    return [
        'agenda_days' => $futureDays,
        'groups' => [
            ['key' => 'nutrition_studio', 'name' => 'Studio Nutrizione Equilibrio'],
            ['key' => 'sport_centro', 'name' => 'SportLab Arena'],
        ],
        'locations' => [
            [
                'key' => 'nutrition_equilibrio',
                'amb_id' => 101,
                'name' => 'Studio Nutrizione Equilibrio',
                'address' => 'Via Tortona 42',
                'city' => 'Milano',
                'phone' => '0211000101',
                'rooms' => [
                    ['key' => 'nutri_visita_1', 'name' => 'Visita 1', 'order' => 1],
                    ['key' => 'nutri_visita_2', 'name' => 'Visita 2', 'order' => 2],
                    ['key' => 'nutri_bia', 'name' => 'Bioimpedenziometria', 'order' => 3],
                ],
            ],
            [
                'key' => 'sportlab_arena',
                'amb_id' => 201,
                'name' => 'SportLab Arena',
                'address' => 'Via Procaccini 18',
                'city' => 'Milano',
                'phone' => '0288000201',
                'rooms' => [
                    ['key' => 'sport_valutazione', 'name' => 'Valutazione', 'order' => 1],
                    ['key' => 'sport_terapia_1', 'name' => 'Terapia 1', 'order' => 2],
                    ['key' => 'sport_terapia_2', 'name' => 'Terapia 2', 'order' => 3],
                ],
            ],
        ],
        'staff' => $staff,
        'clients' => $clients,
        'staff_links' => [
            [
                'type' => 'segreteria',
                'staff_key' => 'frontdesk_nutri',
                'doctor_keys' => ['nutrition_lead', 'nutrition_collab', 'nutrition_team_3'],
            ],
            [
                'type' => 'segreteria',
                'staff_key' => 'frontdesk_sport',
                'doctor_keys' => ['sport_physio_1', 'sport_physio_2'],
            ],
        ],
        'agenda' => [
            [
                'doctor_key' => 'nutrition_lead',
                'room_key' => 'nutri_visita_1',
                'slots_per_day' => 9,
                'slot_minutes' => 30,
                'start_times' => ['09:00:00', '09:00:00', '09:00:00'],
                'booked_per_day' => [6, 5, 1],
                'blocked_slot_index' => [-1, -1, -1],
                'recurring_booked_per_weekday' => [1 => 5, 2 => 4, 3 => 4, 4 => 3, 5 => 2],
                'visit_reasons' => ['Prima visita nutrizionale', 'Controllo piano alimentare', 'Follow up composizione corporea', 'Revisione esami e diario alimentare'],
            ],
            [
                'doctor_key' => 'nutrition_collab',
                'room_key' => 'nutri_bia',
                'slots_per_day' => 7,
                'slot_minutes' => 45,
                'start_times' => ['14:00:00', '14:00:00', '14:00:00'],
                'booked_per_day' => [4, 4, 0],
                'blocked_slot_index' => [-1, -1, -1],
                'recurring_booked_per_weekday' => [1 => 3, 2 => 3, 3 => 2, 4 => 3, 5 => 1],
                'visit_reasons' => ['Bioimpedenziometria', 'Educazione alimentare', 'Controllo sportivo', 'Revisione diario alimentare'],
            ],
            [
                'doctor_key' => 'nutrition_team_3',
                'room_key' => 'nutri_visita_2',
                'slots_per_day' => 8,
                'slot_minutes' => 30,
                'start_times' => ['10:30:00', '10:30:00', '10:30:00'],
                'booked_per_day' => [5, 4, 1],
                'blocked_slot_index' => [-1, -1, -1],
                'recurring_booked_per_weekday' => [1 => 4, 2 => 4, 3 => 3, 4 => 3, 5 => 2],
                'visit_reasons' => ['Follow up nutrizione clinica', 'Rieducazione alimentare', 'Controllo intestino irritabile', 'Piano alimentare per turnisti'],
            ],
            [
                'doctor_key' => 'sport_physio_1',
                'room_key' => 'sport_terapia_1',
                'slots_per_day' => 7,
                'slot_minutes' => 45,
                'start_times' => ['08:30:00', '08:30:00', '08:30:00'],
                'booked_per_day' => [5, 4, 1],
                'blocked_slot_index' => [-1, -1, -1],
                'recurring_booked_per_weekday' => [1 => 4, 2 => 3, 3 => 3, 4 => 4, 5 => 2],
                'visit_reasons' => ['Seduta fisioterapia', 'Recupero caviglia', 'Valutazione postura', 'Mobilita articolare'],
            ],
            [
                'doctor_key' => 'sport_physio_2',
                'room_key' => 'sport_terapia_2',
                'slots_per_day' => 7,
                'slot_minutes' => 45,
                'start_times' => ['14:15:00', '14:15:00', '14:15:00'],
                'booked_per_day' => [4, 3, 0],
                'blocked_slot_index' => [-1, -1, -1],
                'recurring_booked_per_weekday' => [1 => 3, 2 => 2, 3 => 2, 4 => 3, 5 => 1],
                'visit_reasons' => ['Seduta osteopatica', 'Recupero schiena', 'Trattamento spalla', 'Valutazione funzionale'],
            ],
        ],
        'home_visits' => [
            [
                'doctor_key' => 'nutrition_lead',
                'client_key' => 'nutrition_06',
                'date' => $futureDays[1],
                'address' => 'Via Savona 110',
                'city' => 'Milano',
                'note' => 'Consulenza nutrizionale domiciliare demo',
                'legacy_id_vis' => 71001,
            ],
        ],
        'chat_threads' => [
            [
                'thread_type' => 'group',
                'group_key_prefix' => 'segreteria',
                'title' => 'Segreteria',
                'member_staff_keys' => ['nutrition_lead', 'frontdesk_nutri'],
                'messages' => [
                    ['sender_key' => 'frontdesk_nutri', 'body' => 'Ho confermato la prima visita di Laura Bianchi per domani mattina e le ho ricordato di portare gli esami.', 'created_at' => $futureDays[0] . ' 08:15:00'],
                    ['sender_key' => 'nutrition_lead', 'body' => 'Perfetto, tieni liberi anche gli ultimi slot prima di pranzo per nuovi inserimenti.', 'created_at' => $futureDays[0] . ' 08:19:00'],
                    ['sender_key' => 'frontdesk_nutri', 'body' => 'Ricevuto, lascio due spazi da 30 minuti disponibili per prenotazioni rapide.', 'created_at' => $futureDays[0] . ' 08:26:00'],
                ],
                'read_state' => [
                    'nutrition_lead' => $futureDays[0] . ' 08:19:30',
                    'frontdesk_nutri' => $futureDays[0] . ' 08:30:00',
                ],
            ],
            [
                'thread_type' => 'group',
                'group_key_prefix' => 'segreteria',
                'title' => 'Segreteria',
                'member_staff_keys' => ['nutrition_collab', 'frontdesk_nutri'],
                'messages' => [
                    ['sender_key' => 'frontdesk_nutri', 'body' => 'Chiara Marini chiede se puo anticipare la bioimpedenziometria di 15 minuti.', 'created_at' => $futureDays[0] . ' 08:40:00'],
                    ['sender_key' => 'nutrition_collab', 'body' => 'Va bene, anticipiamola e lasciamo la stanza libera per il controllo successivo.', 'created_at' => $futureDays[0] . ' 08:47:00'],
                ],
                'read_state' => [
                    'nutrition_collab' => $futureDays[0] . ' 08:50:00',
                    'frontdesk_nutri' => $futureDays[0] . ' 08:50:00',
                ],
            ],
            [
                'thread_type' => 'group',
                'group_key_prefix' => 'segreteria',
                'title' => 'Segreteria',
                'member_staff_keys' => ['nutrition_team_3', 'frontdesk_nutri'],
                'messages' => [
                    ['sender_key' => 'frontdesk_nutri', 'body' => 'Ho spostato Ilaria Monti alle 11:00 cosi la visita di follow up resta allineata con i nuovi slot condivisi.', 'created_at' => $futureDays[0] . ' 08:55:00'],
                    ['sender_key' => 'nutrition_team_3', 'body' => 'Perfetto, cosi nella vista team si vede subito anche il blocco di mezza mattina e il riordino degli appuntamenti.', 'created_at' => $futureDays[0] . ' 09:02:00'],
                ],
                'read_state' => [
                    'nutrition_team_3' => $futureDays[0] . ' 09:05:00',
                    'frontdesk_nutri' => $futureDays[0] . ' 09:05:00',
                ],
            ],
            [
                'thread_type' => 'group',
                'group_key_prefix' => 'segreteria',
                'title' => 'Segreteria',
                'member_staff_keys' => ['sport_physio_1', 'frontdesk_sport'],
                'messages' => [
                    ['sender_key' => 'frontdesk_sport', 'body' => 'Il nuovo atleta vuole concentrare le sedute tra le 17 e le 19.', 'created_at' => $futureDays[0] . ' 09:05:00'],
                    ['sender_key' => 'sport_physio_1', 'body' => 'Va bene, lasciamo due slot serali liberi per gli inserimenti rapidi.', 'created_at' => $futureDays[0] . ' 09:11:00'],
                ],
                'read_state' => [
                    'sport_physio_1' => $futureDays[0] . ' 09:15:00',
                    'frontdesk_sport' => $futureDays[0] . ' 09:15:00',
                ],
            ],
        ],
        'posta_threads' => [
            [
                'client_key' => 'nutrition_01',
                'doctor_key' => 'nutrition_lead',
                'messages' => [
                    [
                        'sender' => 'patient',
                        'body' => 'Buongiorno, per la prima visita devo portare anche gli esami del sangue e venire digiuna?',
                        'created_at' => $futureDays[0] . ' 09:10:00',
                    ],
                    [
                        'sender' => 'doctor',
                        'body' => 'Porta pure gli esami e il diario alimentare degli ultimi giorni. Non serve venire digiuna.',
                        'created_at' => $futureDays[0] . ' 09:22:00',
                    ],
                ],
                'mark_patient_read' => true,
                'mark_doctor_read' => false,
            ],
            [
                'client_key' => 'nutrition_08',
                'doctor_key' => 'nutrition_collab',
                'messages' => [
                    [
                        'sender' => 'patient',
                        'body' => 'Ho caricato il diario alimentare del weekend. Va bene se domani aggiungiamo anche la bioimpedenziometria?',
                        'created_at' => $futureDays[0] . ' 10:05:00',
                    ],
                    [
                        'sender' => 'doctor',
                        'body' => 'Si, lo inserisco nello stesso appuntamento cosi facciamo la revisione completa.',
                        'created_at' => $futureDays[0] . ' 10:19:00',
                    ],
                ],
                'mark_patient_read' => false,
                'mark_doctor_read' => true,
            ],
            [
                'client_key' => 'nutrition_12',
                'doctor_key' => 'nutrition_team_3',
                'messages' => [
                    [
                        'sender' => 'patient',
                        'body' => 'Ho visto che l appuntamento e stato spostato alle 11:00, confermo che per me va bene.',
                        'created_at' => $futureDays[0] . ' 10:32:00',
                    ],
                    [
                        'sender' => 'doctor',
                        'body' => 'Perfetto, ti aspetto alle 11:00 e rivediamo insieme anche il diario della settimana.',
                        'created_at' => $futureDays[0] . ' 10:46:00',
                    ],
                ],
                'mark_patient_read' => false,
                'mark_doctor_read' => true,
            ],
            [
                'client_key' => 'sport_02',
                'doctor_key' => 'sport_physio_1',
                'messages' => [
                    [
                        'sender' => 'patient',
                        'body' => 'Dopo la corsa lunga sento ancora fastidio alla caviglia destra.',
                        'created_at' => $futureDays[0] . ' 11:15:00',
                    ],
                    [
                        'sender' => 'doctor',
                        'body' => 'Porta anche le scarpe da allenamento, cosi facciamo una valutazione completa.',
                        'created_at' => $futureDays[0] . ' 11:31:00',
                    ],
                ],
                'mark_patient_read' => false,
                'mark_doctor_read' => true,
            ],
        ],
    ];
}

function clientFixture(
    string $key,
    string $firstName,
    string $lastName,
    string $doctorKey,
    int $legacyIdPaziente,
    string $pazSpec,
    string $email,
    string $mobile,
    string $phone,
    string $address,
    string $city,
    string $province,
    string $fiscalCode,
    ?string $portalUsername = null,
    ?string $portalPassword = null
): array {
    return [
        'key' => $key,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'doctor_key' => $doctorKey,
        'legacy_id_paziente' => $legacyIdPaziente,
        'paz_spec' => $pazSpec,
        'email' => $email,
        'mobile' => $mobile,
        'phone' => $phone,
        'address' => $address,
        'city' => $city,
        'province' => $province,
        'fiscal_code' => $fiscalCode,
        'portal_username' => $portalUsername,
        'portal_password' => $portalPassword,
    ];
}

function sqlLiteral(mysqli $db, string $value): string
{
    return "'" . $db->real_escape_string($value) . "'";
}

function sqlNullableLiteral(mysqli $db, ?string $value): string
{
    if ($value === null || $value === '') {
        return 'NULL';
    }

    return sqlLiteral($db, $value);
}

function ensureDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Impossibile creare la directory {$directory}");
    }
}

function writeReport(array $report): string
{
    $path = DEMO_SEED_REPORT_DIR . DIRECTORY_SEPARATOR . 'demo_seed_' . date('Ymd_His') . '.json';
    file_put_contents(
        $path,
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    return $path;
}

function safeRollback(mysqli $db): void
{
    try {
        $db->rollback();
    } catch (Throwable) {
    }
}
