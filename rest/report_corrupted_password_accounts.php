<?php

declare(strict_types=1);

/**
 * Report read-only degli account con password probabilmente corrotte.
 *
 * Heuristica usata:
 * - la password decifrata risulta una stringa esadecimale di almeno 32 caratteri,
 *   tipico segnale di un vecchio valore gia cifrato salvato come se fosse plaintext.
 *
 * Uso:
 *   php report_corrupted_password_accounts.php
 *   php report_corrupted_password_accounts.php --csv=dist/corrupted-passwords.csv
 */

const DEFAULT_ENV_FILE = __DIR__ . DIRECTORY_SEPARATOR . '.env';

main($argv);

function main(array $argv): void
{
    $options = parseCliOptions($argv);
    $env = loadEnvFile(DEFAULT_ENV_FILE);

    $db = connectDatabase($env);
    try {
        configureEncryptionSession($db, $env);
        $rows = fetchCorruptedAccounts($db);
    } finally {
        $db->close();
    }

    printSummary($rows);

    if (!empty($options['csv'])) {
        writeCsvReport($options['csv'], $rows);
        echo PHP_EOL . 'CSV scritto in: ' . $options['csv'] . PHP_EOL;
    }
}

function parseCliOptions(array $argv): array
{
    $options = [
        'csv' => null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--csv=')) {
            $options['csv'] = substr($arg, 6);
        }
    }

    return $options;
}

function loadEnvFile(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('File .env non trovato: ' . $path);
    }

    $env = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Impossibile leggere il file .env');
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        if (($commentPos = strpos($value, '#')) !== false) {
            $candidate = trim(substr($value, 0, $commentPos));
            if ($candidate !== '') {
                $value = $candidate;
            }
        }

        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    return $env;
}

function connectDatabase(array $env): mysqli
{
    $host = (string)($env['database.default.hostname'] ?? 'localhost');
    $database = (string)($env['database.default.database'] ?? '');
    $username = (string)($env['database.default.username'] ?? '');
    $password = (string)($env['database.default.password'] ?? '');
    $port = (int)($env['database.default.port'] ?? 3306);

    $db = new mysqli($host, $username, $password, $database, $port);
    if ($db->connect_errno) {
        throw new RuntimeException('Connessione DB fallita: ' . $db->connect_error);
    }

    return $db;
}

function configureEncryptionSession(mysqli $db, array $env): void
{
    $key = (string)($env['DB_ENCRYPTION_KEY'] ?? '');
    $mode = (string)($env['DB_ENCRYPTION_MODE'] ?? 'aes-256-cbc');

    if ($key === '') {
        throw new RuntimeException('DB_ENCRYPTION_KEY mancante nel file .env');
    }

    $keyEscaped = $db->real_escape_string($key);
    $modeEscaped = $db->real_escape_string($mode);

    if (!$db->query("SET @key_str = SHA2('{$keyEscaped}', 512)")) {
        throw new RuntimeException('Errore SET @key_str: ' . $db->error);
    }
    if (!$db->query("SET NAMES latin1")) {
        throw new RuntimeException('Errore SET NAMES latin1: ' . $db->error);
    }
    if (!$db->query("SET block_encryption_mode = '{$modeEscaped}'")) {
        throw new RuntimeException('Errore SET block_encryption_mode: ' . $db->error);
    }
}

function fetchCorruptedAccounts(mysqli $db): array
{
    $decryptUserPassword = "CONVERT(CAST(AES_DECRYPT(UNHEX(u.password), @key_str, u.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    $decryptPersonaleNome = "CONVERT(CAST(AES_DECRYPT(UNHEX(p.nome), @key_str, p.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    $decryptPersonaleCognome = "CONVERT(CAST(AES_DECRYPT(UNHEX(p.cognome), @key_str, p.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    $decryptPersonaleCell = "CONVERT(CAST(AES_DECRYPT(UNHEX(p.cellulare), @key_str, p.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    $decryptPersonaleEmail = "CONVERT(CAST(AES_DECRYPT(UNHEX(p.email), @key_str, p.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    $decryptClientNome = "CONVERT(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    $decryptClientCognome = "CONVERT(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    $decryptClientCell = "CONVERT(CAST(AES_DECRYPT(UNHEX(c.cellulare), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    $decryptClientEmail = "CONVERT(CAST(AES_DECRYPT(UNHEX(c.email), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";

    $sql = "
        SELECT
            u.id_user,
            u.username,
            u.tipo_user,
            u.datascadenza,
            {$decryptUserPassword} AS decrypted_password,
            CASE
                WHEN u.tipo_user = 2 THEN {$decryptPersonaleNome}
                WHEN u.tipo_user = 3 THEN {$decryptClientNome}
                ELSE ''
            END AS nome,
            CASE
                WHEN u.tipo_user = 2 THEN {$decryptPersonaleCognome}
                WHEN u.tipo_user = 3 THEN {$decryptClientCognome}
                ELSE ''
            END AS cognome,
            CASE
                WHEN u.tipo_user = 2 THEN {$decryptPersonaleCell}
                WHEN u.tipo_user = 3 THEN {$decryptClientCell}
                ELSE ''
            END AS cellulare,
            CASE
                WHEN u.tipo_user = 2 THEN {$decryptPersonaleEmail}
                WHEN u.tipo_user = 3 THEN {$decryptClientEmail}
                ELSE ''
            END AS email,
            EXISTS(
                SELECT 1
                FROM push_subscriptions ps
                WHERE ps.user_id = u.id_user
                  AND ps.is_active = 1
                  AND ps.is_mobile = 1
            ) AS has_push_mobile
        FROM dap01_users u
        LEFT JOIN dap03_personale p ON p.id_user = u.id_user
        LEFT JOIN dap02_clients c ON c.id_user = u.id_user
        WHERE {$decryptUserPassword} REGEXP '^[A-Fa-f0-9]{32,}$'
        ORDER BY u.tipo_user, u.username
    ";

    $result = $db->query($sql);
    if ($result === false) {
        throw new RuntimeException('Errore query report: ' . $db->error);
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $decryptedPassword = (string)($row['decrypted_password'] ?? '');
        $row['decrypted_password_length'] = strlen($decryptedPassword);
        $row['nome_completo'] = trim(
            trim((string)($row['nome'] ?? '')) . ' ' . trim((string)($row['cognome'] ?? ''))
        );
        $row['cellulare'] = trim((string)($row['cellulare'] ?? ''));
        $row['email'] = trim((string)($row['email'] ?? ''));
        $row['has_push_mobile'] = (int)($row['has_push_mobile'] ?? 0);
        $row['suggested_remediation'] = remediationForRow($row);
        unset($row['decrypted_password']);
        $rows[] = $row;
    }

    $result->free();

    return $rows;
}

function remediationForRow(array $row): string
{
    if ((int)($row['has_push_mobile'] ?? 0) === 1) {
        return 'self_reset_push';
    }

    if ((string)($row['email'] ?? '') !== '') {
        return 'self_reset_email';
    }

    if ((string)($row['cellulare'] ?? '') !== '') {
        return 'self_reset_sms';
    }

    return 'admin_manual_reset';
}

function printSummary(array $rows): void
{
    echo 'Account sospetti trovati: ' . count($rows) . PHP_EOL;

    $byType = [];
    $byRemediation = [];

    foreach ($rows as $row) {
        $type = (string)($row['tipo_user'] ?? '');
        $remediation = (string)($row['suggested_remediation'] ?? '');

        $byType[$type] = ($byType[$type] ?? 0) + 1;
        $byRemediation[$remediation] = ($byRemediation[$remediation] ?? 0) + 1;
    }

    echo PHP_EOL . 'Per tipo utente:' . PHP_EOL;
    foreach ($byType as $type => $count) {
        echo '  tipo_user=' . $type . ' -> ' . $count . PHP_EOL;
    }

    echo PHP_EOL . 'Per canale di recupero:' . PHP_EOL;
    foreach ($byRemediation as $remediation => $count) {
        echo '  ' . $remediation . ' -> ' . $count . PHP_EOL;
    }

    echo PHP_EOL . 'Prime 10 righe:' . PHP_EOL;
    foreach (array_slice($rows, 0, 10) as $row) {
        echo '  [' . $row['id_user'] . '] '
            . $row['username']
            . ' | ' . ($row['nome_completo'] !== '' ? $row['nome_completo'] : '-')
            . ' | canale=' . $row['suggested_remediation']
            . ' | cell=' . ($row['cellulare'] !== '' ? 'SI' : 'NO')
            . ' | email=' . ($row['email'] !== '' ? 'SI' : 'NO')
            . ' | push=' . ((int)$row['has_push_mobile'] === 1 ? 'SI' : 'NO')
            . PHP_EOL;
    }
}

function writeCsvReport(string $path, array $rows): void
{
    $directory = dirname($path);
    if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossibile creare la directory: ' . $directory);
        }
    }

    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Impossibile aprire il file CSV: ' . $path);
    }

    $headers = [
        'id_user',
        'username',
        'tipo_user',
        'datascadenza',
        'nome_completo',
        'cellulare',
        'email',
        'has_push_mobile',
        'decrypted_password_length',
        'suggested_remediation',
    ];

    fputcsv($handle, $headers, ';');
    foreach ($rows as $row) {
        $record = [];
        foreach ($headers as $header) {
            $record[] = $row[$header] ?? '';
        }
        fputcsv($handle, $record, ';');
    }

    fclose($handle);
}
