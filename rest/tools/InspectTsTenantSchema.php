<?php

declare(strict_types=1);

function parseEnvFile(string $path): array
{
    $vars = [];
    $rows = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($rows === false) {
        return $vars;
    }

    foreach ($rows as $row) {
        $line = trim((string) $row);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim((string) $parts[0]);
        $value = trim((string) $parts[1]);
        if ($key === '') {
            continue;
        }

        if (
            $value !== ''
            && (
                ($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        $vars[$key] = $value;
    }

    return $vars;
}

function resolvePassword(array $env, string $reference): string
{
    $reference = trim($reference);
    if ($reference === '') {
        return '';
    }

    $aliases = [
        'DB_PASSWORD' => ['database.default.password'],
        'database.default.password' => ['DB_PASSWORD'],
        'PLATFORM_DB_PASSWORD' => ['database.platform.password'],
        'database.platform.password' => ['PLATFORM_DB_PASSWORD'],
    ];

    $candidates = array_unique(array_filter(array_merge([$reference], $aliases[$reference] ?? [])));
    foreach ($candidates as $candidate) {
        if (isset($env[$candidate]) && trim((string) $env[$candidate]) !== '') {
            return trim((string) $env[$candidate]);
        }
    }

    return '';
}

function hasTable(mysqli $db, string $table): bool
{
    $escaped = $db->real_escape_string($table);
    $result = $db->query("SHOW TABLES LIKE '{$escaped}'");
    if (!$result instanceof mysqli_result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->free();

    return $exists;
}

$env = parseEnvFile(__DIR__ . '/../.env');
$platformHost = trim((string) ($env['database.platform.hostname'] ?? ''));
$platformPort = (int) ($env['database.platform.port'] ?? 3306);
$platformDb = trim((string) ($env['database.platform.database'] ?? ''));
$platformUser = trim((string) ($env['database.platform.username'] ?? ''));
$platformPass = trim((string) ($env['database.platform.password'] ?? ''));

if ($platformHost === '' || $platformDb === '' || $platformUser === '' || $platformPass === '') {
    fwrite(STDERR, "Platform DB config incompleta nel file .env locale.\n");
    exit(2);
}

$platform = @new mysqli($platformHost, $platformUser, $platformPass, $platformDb, $platformPort);
if ($platform->connect_errno) {
    fwrite(STDERR, "Platform connect error: {$platform->connect_error}\n");
    exit(3);
}

$platform->set_charset('utf8mb4');
$sql = 'SELECT id_tenant, tenant_key, tenant_name, db_host, db_port, db_name, db_username, db_password_ref, is_active
        FROM platform_tenants
        ORDER BY is_active DESC, tenant_name ASC, id_tenant ASC';
$result = $platform->query($sql);
if (!$result instanceof mysqli_result) {
    fwrite(STDERR, "Platform query error: {$platform->error}\n");
    $platform->close();
    exit(4);
}

$rows = [];
while ($row = $result->fetch_assoc()) {
    $host = trim((string) ($row['db_host'] ?? '')) !== ''
        ? trim((string) $row['db_host'])
        : trim((string) ($env['database.default.hostname'] ?? ''));
    $port = (int) ($row['db_port'] ?? 0) > 0
        ? (int) $row['db_port']
        : (int) ($env['database.default.port'] ?? 3306);
    $dbName = trim((string) ($row['db_name'] ?? ''));
    $user = trim((string) ($row['db_username'] ?? '')) !== ''
        ? trim((string) $row['db_username'])
        : trim((string) ($env['database.default.username'] ?? ''));
    $password = resolvePassword($env, trim((string) ($row['db_password_ref'] ?? '')));

    $status = [
        'id_tenant' => (int) ($row['id_tenant'] ?? 0),
        'tenant_key' => (string) ($row['tenant_key'] ?? ''),
        'tenant_name' => (string) ($row['tenant_name'] ?? ''),
        'db_name' => $dbName,
        'is_active' => (int) ($row['is_active'] ?? 0),
        'ts_documents' => false,
        'ts_document_events' => false,
        'ts_document_receipts' => false,
        'connect_error' => '',
    ];

    if ($dbName === '' || $user === '' || $password === '') {
        $status['connect_error'] = 'config_incomplete';
        $rows[] = $status;
        continue;
    }

    $tenantDb = @new mysqli($host, $user, $password, $dbName, $port);
    if ($tenantDb->connect_errno) {
        $status['connect_error'] = $tenantDb->connect_error;
        $rows[] = $status;
        continue;
    }

    $tenantDb->set_charset('utf8mb4');
    $status['ts_documents'] = hasTable($tenantDb, 'ts_documents');
    $status['ts_document_events'] = hasTable($tenantDb, 'ts_document_events');
    $status['ts_document_receipts'] = hasTable($tenantDb, 'ts_document_receipts');
    $tenantDb->close();

    $rows[] = $status;
}

$result->free();
$platform->close();

$missing = array_values(array_filter($rows, static function (array $row): bool {
    return !$row['ts_documents']
        || !$row['ts_document_events']
        || !$row['ts_document_receipts']
        || $row['connect_error'] !== '';
}));

echo json_encode([
    'total' => count($rows),
    'missing_count' => count($missing),
    'missing' => $missing,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
