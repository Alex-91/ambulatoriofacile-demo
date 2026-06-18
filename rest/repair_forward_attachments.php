<?php
set_time_limit(0);
ini_set('max_execution_time', '0');
ignore_user_abort(true);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Ripara gli allegati dei messaggi FORWARD quando i metadati allegato
 * (original_name / stored_name / storage_path) risultano corrotti o mancanti.
 *
 * Default: DRY RUN
 * Applica davvero solo con: --apply
 *
 * Filtri opzionali:
 *   --thread=326786
 *   --threads=326786,326790
 *   --message=404848
 *   --messages=404848,404850
 *   --limit=100
 *   --verbose
 *
 * Esempi:
 *   php repair_forward_attachments.php
 *   php repair_forward_attachments.php --thread=326786
 *   php repair_forward_attachments.php --messages=404848,404850 --apply
 *
 * Note:
 * - Lo script riallinea i record in msg_attachments partendo dalla catena
 *   del messaggio sorgente del forward.
 * - Per la riparazione legacy non copia fisicamente i file: riusa i metadati
 *   cifrati validi del messaggio sorgente.
 * - I forward futuri sono gestiti dal codice applicativo, non da questo script.
 */

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
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

        if (preg_match('/^--' . preg_quote($name, '/') . '=(.+)$/i', $arg, $m)) {
            return trim((string)$m[1]);
        }
    }

    return null;
}

function normalizeIdListOption(array $argv, string $name): array
{
    $raw = optionValue($argv, $name);
    if ($raw === null || $raw === '') {
        return [];
    }

    $values = preg_split('/[\s,;|]+/', trim($raw)) ?: [];
    $ids = [];
    foreach ($values as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    ksort($ids);
    return array_values($ids);
}

function intOption(array $argv, string $name, int $default = 0): int
{
    $raw = optionValue($argv, $name);
    if ($raw === null || $raw === '') {
        return $default;
    }

    $value = (int)$raw;
    return $value > 0 ? $value : $default;
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

function dbConfigFromEnv(array $env): array
{
    return [
        'host' => (string)($env['database.default.hostname'] ?? '127.0.0.1'),
        'user' => (string)($env['database.default.username'] ?? 'root'),
        'pass' => (string)($env['database.default.password'] ?? 'root'),
        'db'   => (string)($env['database.default.database'] ?? 'mail'),
        'port' => (int)($env['database.default.port'] ?? 3306),
    ];
}

function connectDb(array $cfg): mysqli
{
    $db = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db'], (int)$cfg['port']);
    $db->set_charset('utf8mb4');
    return $db;
}

function setCryptoSession(mysqli $db, array $env): void
{
    $key = (string)($env['DB_ENCRYPTION_KEY'] ?? '');
    if ($key === '') {
        throw new RuntimeException('DB_ENCRYPTION_KEY non trovato nel file .env');
    }

    $mode = (string)($env['DB_ENCRYPTION_MODE'] ?? 'aes-256-cbc');

    $db->query("SET NAMES utf8mb4");
    $db->query("SET block_encryption_mode = '" . $db->real_escape_string($mode) . "'");
    $db->query("SET @key_str = SHA2('" . $db->real_escape_string($key) . "', 512)");
}

function sqlIntList(array $ids): string
{
    $ids = array_values(array_unique(array_map(static fn ($v) => (int)$v, $ids)));
    $ids = array_values(array_filter($ids, static fn ($v) => $v > 0));
    if (!$ids) {
        return '0';
    }

    return implode(',', $ids);
}

function normalizeAttachmentText(?string $value): string
{
    $value = (string)($value ?? '');
    $value = str_replace("\0", '', $value);
    return trim($value);
}

function attachmentSignature(array $row): string
{
    return strtolower(normalizeAttachmentText($row['stored_name_plain'] ?? ''))
        . '|'
        . strtolower(normalizeAttachmentText($row['original_name_plain'] ?? ''))
        . '|'
        . strtolower(normalizeAttachmentText($row['mime_type'] ?? ''))
        . '|'
        . (int)($row['file_size'] ?? 0);
}

function isUsableAttachmentRow(array $row): bool
{
    return normalizeAttachmentText($row['stored_name_plain'] ?? '') !== ''
        && normalizeAttachmentText($row['storage_path_plain'] ?? '') !== ''
        && normalizeAttachmentText($row['vector_hex'] ?? '') !== ''
        && normalizeAttachmentText($row['stored_name'] ?? '') !== ''
        && normalizeAttachmentText($row['storage_path'] ?? '') !== '';
}

function fetchMessage(mysqli $db, int $messageId): ?array
{
    $stmt = $db->prepare("
        SELECT
            id_message,
            id_thread,
            message_type,
            parent_message_id,
            sender_user_id,
            recipient_user_id,
            recipient_role,
            created_at
        FROM msg_messages
        WHERE id_message = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $messageId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function fetchMessageAttachments(mysqli $db, int $messageId): array
{
    $stmt = $db->prepare("
        SELECT
            a.id_attachment,
            a.id_message,
            a.uploaded_by_user_id,
            a.original_name,
            a.stored_name,
            a.mime_type,
            a.file_size,
            a.storage_path,
            HEX(a.vector_id) AS vector_hex,
            a.created_at,

            CAST(AES_DECRYPT(UNHEX(a.original_name), @key_str, a.vector_id) AS CHAR(4096) CHARACTER SET utf8mb4) AS original_name_plain,
            CAST(AES_DECRYPT(UNHEX(a.stored_name), @key_str, a.vector_id) AS CHAR(4096) CHARACTER SET utf8mb4) AS stored_name_plain,
            CAST(AES_DECRYPT(UNHEX(a.storage_path), @key_str, a.vector_id) AS CHAR(4096) CHARACTER SET utf8mb4) AS storage_path_plain
        FROM msg_attachments a
        WHERE a.id_message = ?
        ORDER BY a.created_at ASC, a.id_attachment ASC
    ");
    $stmt->bind_param('i', $messageId);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function mergeAttachmentTemplates(array $base, array $incoming): array
{
    $seen = [];
    foreach ($base as $row) {
        $seen[attachmentSignature($row)] = true;
    }

    foreach ($incoming as $row) {
        $signature = attachmentSignature($row);
        if (isset($seen[$signature])) {
            continue;
        }

        $base[] = $row;
        $seen[$signature] = true;
    }

    return $base;
}

function collectVisibleAttachmentTemplates(mysqli $db, int $messageId, array &$memo = [], array $trail = []): array
{
    if ($messageId <= 0) {
        return [];
    }

    if (array_key_exists($messageId, $memo)) {
        return $memo[$messageId];
    }

    if (isset($trail[$messageId])) {
        return [];
    }
    $trail[$messageId] = true;

    $message = fetchMessage($db, $messageId);
    if (!$message) {
        return $memo[$messageId] = [];
    }

    $attachments = fetchMessageAttachments($db, $messageId);
    $valid = [];
    foreach ($attachments as $attachment) {
        if (!isUsableAttachmentRow($attachment)) {
            continue;
        }

        $valid[] = $attachment;
    }

    if (strtoupper((string)($message['message_type'] ?? '')) !== 'FORWARD') {
        return $memo[$messageId] = $valid;
    }

    $parentMessageId = (int)($message['parent_message_id'] ?? 0);
    if ($parentMessageId > 0) {
        $valid = mergeAttachmentTemplates(
            $valid,
            collectVisibleAttachmentTemplates($db, $parentMessageId, $memo, $trail)
        );
    }

    return $memo[$messageId] = $valid;
}

function signaturesByRow(array $rows): array
{
    $signatures = [];
    foreach ($rows as $row) {
        $signatures[attachmentSignature($row)] = true;
    }

    ksort($signatures);
    return array_keys($signatures);
}

function determineRepairState(mysqli $db, array $forward, array &$templateMemo = []): array
{
    $messageId = (int)$forward['id_message'];
    $parentMessageId = (int)($forward['parent_message_id'] ?? 0);

    $currentRows = fetchMessageAttachments($db, $messageId);
    $desiredRows = $parentMessageId > 0
        ? collectVisibleAttachmentTemplates($db, $parentMessageId, $templateMemo)
        : [];

    $currentValidRows = [];
    $hasInvalidCurrentRows = false;
    foreach ($currentRows as $row) {
        if (isUsableAttachmentRow($row)) {
            $currentValidRows[] = $row;
        } else {
            $hasInvalidCurrentRows = true;
        }
    }

    $desiredSignatures = signaturesByRow($desiredRows);
    $currentSignatures = signaturesByRow($currentValidRows);

    $needsRepair = false;
    $reasonParts = [];

    if ($desiredRows && !$currentRows) {
        $needsRepair = true;
        $reasonParts[] = 'missing-attachments';
    }
    if ($hasInvalidCurrentRows) {
        $needsRepair = true;
        $reasonParts[] = 'invalid-current';
    }
    if (count($desiredSignatures) !== count($currentSignatures) || $desiredSignatures !== $currentSignatures) {
        if (!empty($desiredSignatures) || !empty($currentSignatures)) {
            $needsRepair = true;
            $reasonParts[] = 'signature-mismatch';
        }
    }
    if (!$desiredRows && $currentRows) {
        $reasonParts[] = 'source-without-attachments';
    }

    return [
        'current_rows'           => $currentRows,
        'current_valid_rows'     => $currentValidRows,
        'desired_rows'           => $desiredRows,
        'current_signatures'     => $currentSignatures,
        'desired_signatures'     => $desiredSignatures,
        'has_invalid_current'    => $hasInvalidCurrentRows,
        'needs_repair'           => $needsRepair && !empty($desiredRows),
        'reason'                 => implode(',', array_unique($reasonParts)),
    ];
}

function repairForwardAttachments(mysqli $db, array $forward, array $desiredRows): int
{
    $messageId = (int)$forward['id_message'];
    $senderUserId = (int)($forward['sender_user_id'] ?? 0);
    $createdAt = (string)($forward['created_at'] ?? date('Y-m-d H:i:s'));

    $deleteStmt = $db->prepare("DELETE FROM msg_attachments WHERE id_message = ?");
    $deleteStmt->bind_param('i', $messageId);
    $deleteStmt->execute();
    $deleteStmt->close();

    $insertStmt = $db->prepare("
        INSERT INTO msg_attachments
            (id_message, id_draft, uploaded_by_user_id, original_name, stored_name, mime_type, file_size, storage_path, created_at, vector_id)
        VALUES
            (?, NULL, ?, ?, ?, ?, ?, ?, ?, UNHEX(?))
    ");

    $inserted = 0;
    foreach ($desiredRows as $row) {
        $originalName = (string)$row['original_name'];
        $storedName = (string)$row['stored_name'];
        $mimeType = (string)$row['mime_type'];
        $fileSize = (int)$row['file_size'];
        $storagePath = (string)$row['storage_path'];
        $vectorHex = (string)$row['vector_hex'];

        $insertStmt->bind_param(
            'iisssisss',
            $messageId,
            $senderUserId,
            $originalName,
            $storedName,
            $mimeType,
            $fileSize,
            $storagePath,
            $createdAt,
            $vectorHex
        );
        $insertStmt->execute();
        $inserted++;
    }

    $insertStmt->close();
    return $inserted;
}

function fetchForwardCandidates(mysqli $db, array $threadIds, array $messageIds, int $limit): array
{
    $where = [
        "m.message_type = 'FORWARD'",
        "m.parent_message_id IS NOT NULL",
    ];

    if ($threadIds) {
        $where[] = 'm.id_thread IN (' . sqlIntList($threadIds) . ')';
    }

    if ($messageIds) {
        $where[] = 'm.id_message IN (' . sqlIntList($messageIds) . ')';
    }

    $sql = "
        SELECT
            m.id_message,
            m.id_thread,
            m.message_type,
            m.parent_message_id,
            m.sender_user_id,
            m.recipient_user_id,
            m.recipient_role,
            m.created_at
        FROM msg_messages m
        WHERE " . implode(' AND ', $where) . "
        ORDER BY m.created_at ASC, m.id_message ASC
    ";

    if ($limit > 0) {
        $sql .= " LIMIT " . (int)$limit;
    }

    $res = $db->query($sql);
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

$argv = $_SERVER['argv'] ?? [];
$apply = hasFlag($argv, '--apply');
$verbose = hasFlag($argv, '--verbose');
$threadIds = normalizeIdListOption($argv, 'threads');
if (!$threadIds) {
    $threadIds = normalizeIdListOption($argv, 'thread');
}
$messageIds = normalizeIdListOption($argv, 'messages');
if (!$messageIds) {
    $messageIds = normalizeIdListOption($argv, 'message');
}
$limit = intOption($argv, 'limit', 0);

$env = loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');
$dbCfg = dbConfigFromEnv($env);

out('repair_forward_attachments.php');
out('Mode: ' . ($apply ? 'APPLY' : 'DRY RUN'));
out('DB: ' . $dbCfg['host'] . ':' . $dbCfg['port'] . '/' . $dbCfg['db']);
if ($threadIds) {
    out('Filter threads: ' . implode(',', $threadIds));
}
if ($messageIds) {
    out('Filter messages: ' . implode(',', $messageIds));
}
if ($limit > 0) {
    out('Limit: ' . $limit);
}
out();

$db = connectDb($dbCfg);
setCryptoSession($db, $env);

$forwards = fetchForwardCandidates($db, $threadIds, $messageIds, $limit);
if (!$forwards) {
    out('Nessun forward trovato con i filtri richiesti.');
    exit(0);
}

$templateMemo = [];
$scanned = 0;
$repairable = 0;
$applied = 0;
$skipped = 0;
$errors = 0;

foreach ($forwards as $forward) {
    $scanned++;
    $messageId = (int)$forward['id_message'];
    $threadId = (int)$forward['id_thread'];

    try {
        $state = determineRepairState($db, $forward, $templateMemo);

        $line = sprintf(
            '[thread %d] msg %d parent=%d current=%d valid=%d desired=%d',
            $threadId,
            $messageId,
            (int)($forward['parent_message_id'] ?? 0),
            count($state['current_rows']),
            count($state['current_valid_rows']),
            count($state['desired_rows'])
        );

        if ($state['needs_repair']) {
            $repairable++;
            $line .= ' -> REPAIR';
            if ($state['reason'] !== '') {
                $line .= ' (' . $state['reason'] . ')';
            }
            out($line);

            if ($verbose) {
                out('  desired: ' . implode(' || ', $state['desired_signatures']));
                out('  current: ' . implode(' || ', $state['current_signatures']));
            }

            if ($apply) {
                $db->begin_transaction();
                try {
                    $inserted = repairForwardAttachments($db, $forward, $state['desired_rows']);
                    $db->commit();
                    $applied++;
                    out("  applied: recreated {$inserted} attachment row(s)");
                } catch (Throwable $e) {
                    $db->rollback();
                    $errors++;
                    out('  ERROR apply: ' . $e->getMessage());
                }
            }

            continue;
        }

        $skipped++;
        $line .= ' -> OK';
        if ($state['reason'] !== '') {
            $line .= ' (' . $state['reason'] . ')';
        }
        out($line);

        if ($verbose && (!empty($state['desired_signatures']) || !empty($state['current_signatures']))) {
            out('  desired: ' . implode(' || ', $state['desired_signatures']));
            out('  current: ' . implode(' || ', $state['current_signatures']));
        }
    } catch (Throwable $e) {
        $errors++;
        out(sprintf('[thread %d] msg %d -> ERROR %s', $threadId, $messageId, $e->getMessage()));
    }
}

out();
out('Summary');
out('  scanned: ' . $scanned);
out('  repairable: ' . $repairable);
out('  skipped/ok: ' . $skipped);
out('  applied: ' . $applied);
out('  errors: ' . $errors);

exit($errors > 0 ? 1 : 0);
