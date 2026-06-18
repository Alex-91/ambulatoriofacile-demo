<?php
set_time_limit(0);
ini_set('max_execution_time', '0');
ignore_user_abort(true);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Merge di due record paziente duplicati.
 *
 * Default: DRY RUN
 * Applica davvero solo con: --apply
 *
 * Opzioni:
 *   --source-client=44
 *   --target-client=43
 *   --keep-credentials=target|source
 *   --apply
 *
 * Note:
 * - Il merge sposta i riferimenti di dominio paziente e i riferimenti account.
 * - Se i due account hanno username diversi, in APPLY e' obbligatorio specificare
 *   quale set di credenziali conservare sul target con --keep-credentials.
 */

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

function requiredPositiveIntOption(array $argv, string $name): int
{
    $raw = optionValue($argv, $name);
    $value = (int)$raw;
    if ($value <= 0) {
        throw new RuntimeException("Opzione obbligatoria mancante o non valida: --{$name}=ID");
    }

    return $value;
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

function sqlIntList(array $ids): string
{
    $ids = array_values(array_unique(array_map(static fn ($v) => (int)$v, $ids)));
    $ids = array_values(array_filter($ids, static fn ($v) => $v > 0));
    if (!$ids) {
        return '0';
    }

    return implode(',', $ids);
}

function normalizeKeepCredentials(?string $value): ?string
{
    $value = strtolower(trim((string)$value));
    if ($value === '') {
        return null;
    }
    if (!in_array($value, ['target', 'source'], true)) {
        throw new RuntimeException('Valore non valido per --keep-credentials: usa target oppure source');
    }

    return $value;
}

function fetchClientBundle(mysqli $db, int $clientId): ?array
{
    $sql = "
        SELECT
            c.id_client,
            c.id_user,
            c.id_personale,
            c.avviso_mail,
            u.username,
            u.password,
            u.datascadenza,
            u.tipo_user,
            u.vector_id,
            u.privacy,
            u.data_privacy,
            u.is_active
        FROM dap02_clients c
        LEFT JOIN dap01_users u
          ON u.id_user = c.id_user
        WHERE c.id_client = ?
        LIMIT 1
    ";

    $row = $db->execute_query($sql, [$clientId])->fetch_assoc();
    if (!$row) {
        return null;
    }

    $doctorIds = [];
    $res = $db->execute_query(
        "SELECT id_dot FROM dap09_client_doctor WHERE id_client = ? ORDER BY id_dot ASC",
        [$clientId]
    );
    while ($doctor = $res->fetch_assoc()) {
        $doctorId = (int)($doctor['id_dot'] ?? 0);
        if ($doctorId > 0) {
            $doctorIds[] = $doctorId;
        }
    }

    $fallbackDoctorId = (int)($row['id_personale'] ?? 0);
    if ($fallbackDoctorId > 0) {
        $doctorIds[] = $fallbackDoctorId;
    }

    $row['doctor_ids'] = array_values(array_unique($doctorIds));
    return $row;
}

function buildTempScope(mysqli $db, int $sourceClientId, array $sourceDoctorIds): void
{
    $db->query("DROP TEMPORARY TABLE IF EXISTS tmp_merge_legacy_main");
    $db->query("DROP TEMPORARY TABLE IF EXISTS tmp_merge_legacy_reply");
    $db->query("DROP TEMPORARY TABLE IF EXISTS tmp_merge_threads");
    $db->query("DROP TEMPORARY TABLE IF EXISTS tmp_merge_messages");
    $db->query("DROP TEMPORARY TABLE IF EXISTS tmp_merge_owner_drafts");
    $db->query("DROP TEMPORARY TABLE IF EXISTS tmp_merge_recipient_drafts");
    $db->query("DROP TEMPORARY TABLE IF EXISTS tmp_merge_patient_attachments");

    $db->query("
        CREATE TEMPORARY TABLE tmp_merge_legacy_main (
            id_message INT NOT NULL PRIMARY KEY
        ) ENGINE=Memory
    ");
    $db->query("
        INSERT IGNORE INTO tmp_merge_legacy_main (id_message)
        SELECT m.id_message
        FROM dap10_message m
        WHERE (m.mitt = 'C' AND m.id_mitt = {$sourceClientId})
           OR (m.dest = 'C' AND m.id_dest = {$sourceClientId})
    ");

    $db->query("
        CREATE TEMPORARY TABLE tmp_merge_legacy_reply (
            id_message INT NOT NULL PRIMARY KEY
        ) ENGINE=Memory
    ");
    $db->query("
        INSERT IGNORE INTO tmp_merge_legacy_reply (id_message)
        SELECT r.id_message
        FROM dap10_message_reply r
        WHERE (r.mitt = 'C' AND r.id_mitt = {$sourceClientId})
           OR (r.dest = 'C' AND r.id_dest = {$sourceClientId})
    ");

    $db->query("
        CREATE TEMPORARY TABLE tmp_merge_threads (
            id_thread BIGINT UNSIGNED NOT NULL PRIMARY KEY
        ) ENGINE=Memory
    ");

    $doctorListSql = sqlIntList($sourceDoctorIds);
    $db->query("
        INSERT IGNORE INTO tmp_merge_threads (id_thread)
        SELECT DISTINCT m.id_thread
        FROM msg_messages m
        WHERE EXISTS (
            SELECT 1
            FROM msg_messages md_client
            WHERE md_client.id_thread = m.id_thread
              AND {$sourceClientId} IN (md_client.sender_user_id, COALESCE(md_client.recipient_user_id, -1))
        )
          AND EXISTS (
            SELECT 1
            FROM msg_messages md_doctor
            WHERE md_doctor.id_thread = m.id_thread
              AND (
                md_doctor.sender_user_id IN ({$doctorListSql})
                OR COALESCE(md_doctor.recipient_user_id, -1) IN ({$doctorListSql})
              )
        )
    ");

    $db->query("
        CREATE TEMPORARY TABLE tmp_merge_messages (
            id_message BIGINT UNSIGNED NOT NULL PRIMARY KEY
        ) ENGINE=Memory
    ");
    $db->query("
        INSERT IGNORE INTO tmp_merge_messages (id_message)
        SELECT m.id_message
        FROM msg_messages m
        INNER JOIN tmp_merge_threads t ON t.id_thread = m.id_thread
    ");

    $db->query("
        CREATE TEMPORARY TABLE tmp_merge_owner_drafts (
            id_draft BIGINT UNSIGNED NOT NULL PRIMARY KEY
        ) ENGINE=Memory
    ");
    $db->query("
        INSERT IGNORE INTO tmp_merge_owner_drafts (id_draft)
        SELECT d.id_draft
        FROM msg_drafts d
        WHERE d.owner_user_id = {$sourceClientId}
          AND d.recipient_type = 'PATIENT_TARGET'
    ");

    $db->query("
        CREATE TEMPORARY TABLE tmp_merge_recipient_drafts (
            id_draft BIGINT UNSIGNED NOT NULL PRIMARY KEY
        ) ENGINE=Memory
    ");
    $db->query("
        INSERT IGNORE INTO tmp_merge_recipient_drafts (id_draft)
        SELECT d.id_draft
        FROM msg_drafts d
        WHERE d.recipient_type = 'USER'
          AND d.recipient_role IS NULL
          AND d.recipient_user_id = {$sourceClientId}
          AND d.owner_user_id IN ({$doctorListSql})
    ");

    $db->query("
        CREATE TEMPORARY TABLE tmp_merge_patient_attachments (
            id_attachment BIGINT UNSIGNED NOT NULL PRIMARY KEY
        ) ENGINE=Memory
    ");
    $db->query("
        INSERT IGNORE INTO tmp_merge_patient_attachments (id_attachment)
        SELECT a.id_attachment
        FROM msg_attachments a
        WHERE a.uploaded_by_user_id = {$sourceClientId}
          AND (
            a.id_message IN (SELECT id_message FROM tmp_merge_messages)
            OR a.id_draft IN (SELECT id_draft FROM tmp_merge_owner_drafts)
          )
    ");
}

function countQuery(mysqli $db, string $sql): int
{
    $row = $db->query($sql)->fetch_assoc();
    return (int)($row['c'] ?? 0);
}

function loadImpactCounts(mysqli $db, int $sourceClientId, int $sourceUserId): array
{
    return [
        'legacy_main_sender' => countQuery($db, "SELECT COUNT(*) AS c FROM dap10_message WHERE mitt='C' AND id_mitt={$sourceClientId}"),
        'legacy_main_recipient' => countQuery($db, "SELECT COUNT(*) AS c FROM dap10_message WHERE dest='C' AND id_dest={$sourceClientId}"),
        'legacy_main_delete_flags' => countQuery($db, "
            SELECT COUNT(*) AS c
            FROM dap10_message_delete d
            INNER JOIN tmp_merge_legacy_main lm ON lm.id_message = d.id_message
            WHERE d.id_utente = {$sourceClientId}
        "),
        'legacy_reply_sender' => countQuery($db, "SELECT COUNT(*) AS c FROM dap10_message_reply WHERE mitt='C' AND id_mitt={$sourceClientId}"),
        'legacy_reply_recipient' => countQuery($db, "SELECT COUNT(*) AS c FROM dap10_message_reply WHERE dest='C' AND id_dest={$sourceClientId}"),
        'legacy_reply_delete_flags' => countQuery($db, "
            SELECT COUNT(*) AS c
            FROM dap10_message_reply_delete d
            INNER JOIN tmp_merge_legacy_reply lr ON lr.id_message = d.id_message
            WHERE d.id_utente = {$sourceClientId}
        "),
        'new_threads' => countQuery($db, "SELECT COUNT(*) AS c FROM tmp_merge_threads"),
        'new_messages_sender' => countQuery($db, "
            SELECT COUNT(*) AS c
            FROM msg_messages m
            INNER JOIN tmp_merge_messages mm ON mm.id_message = m.id_message
            WHERE m.sender_user_id = {$sourceClientId}
        "),
        'new_messages_recipient' => countQuery($db, "
            SELECT COUNT(*) AS c
            FROM msg_messages m
            INNER JOIN tmp_merge_messages mm ON mm.id_message = m.id_message
            WHERE m.recipient_user_id = {$sourceClientId}
        "),
        'new_messages_reply_to' => countQuery($db, "
            SELECT COUNT(*) AS c
            FROM msg_messages m
            INNER JOIN tmp_merge_messages mm ON mm.id_message = m.id_message
            WHERE m.reply_to_user_id = {$sourceClientId}
        "),
        'new_messages_root_author' => countQuery($db, "
            SELECT COUNT(*) AS c
            FROM msg_messages m
            INNER JOIN tmp_merge_messages mm ON mm.id_message = m.id_message
            WHERE m.root_author_user_id = {$sourceClientId}
        "),
        'new_threads_root_author' => countQuery($db, "
            SELECT COUNT(*) AS c
            FROM msg_threads t
            INNER JOIN tmp_merge_threads tt ON tt.id_thread = t.id_thread
            WHERE t.root_author_user_id = {$sourceClientId}
        "),
        'new_flags' => countQuery($db, "
            SELECT COUNT(*) AS c
            FROM msg_user_flags f
            INNER JOIN tmp_merge_messages mm ON mm.id_message = f.id_message
            WHERE f.user_id = {$sourceClientId}
        "),
        'new_owner_drafts' => countQuery($db, "SELECT COUNT(*) AS c FROM tmp_merge_owner_drafts"),
        'new_recipient_drafts' => countQuery($db, "SELECT COUNT(*) AS c FROM tmp_merge_recipient_drafts"),
        'new_patient_attachments' => countQuery($db, "SELECT COUNT(*) AS c FROM tmp_merge_patient_attachments"),
        'user_schede' => $sourceUserId > 0 ? countQuery($db, "SELECT COUNT(*) AS c FROM dap_user_schede WHERE id_user = {$sourceUserId}") : 0,
        'device_links' => $sourceUserId > 0 ? countQuery($db, "SELECT COUNT(*) AS c FROM device_links WHERE user_id = {$sourceUserId}") : 0,
        'push_subscriptions' => $sourceUserId > 0 ? countQuery($db, "SELECT COUNT(*) AS c FROM push_subscriptions WHERE user_id = {$sourceUserId}") : 0,
        'push_delivery_logs' => $sourceUserId > 0 ? countQuery($db, "SELECT COUNT(*) AS c FROM push_delivery_logs WHERE user_id = {$sourceUserId}") : 0,
        'chat_thread_user' => $sourceUserId > 0 ? countQuery($db, "SELECT COUNT(*) AS c FROM dap_chat_thread_user WHERE id_user = {$sourceUserId}") : 0,
    ];
}

function mergeUserRows(mysqli $db, array $source, array $target, string $keepCredentials): void
{
    $keepSource = $keepCredentials === 'source';

    $username = $keepSource ? (string)($source['username'] ?? '') : (string)($target['username'] ?? '');
    $password = $keepSource ? (string)($source['password'] ?? '') : (string)($target['password'] ?? '');
    $vectorId = $keepSource ? ($source['vector_id'] ?? null) : ($target['vector_id'] ?? null);
    $datascadenza = $keepSource ? ($source['datascadenza'] ?? null) : ($target['datascadenza'] ?? null);

    if (!$datascadenza || (($target['datascadenza'] ?? null) && strcmp((string)$target['datascadenza'], (string)$datascadenza) > 0)) {
        $datascadenza = $target['datascadenza'] ?? $datascadenza;
    }
    if (($source['datascadenza'] ?? null) && (!$datascadenza || strcmp((string)$source['datascadenza'], (string)$datascadenza) > 0)) {
        $datascadenza = $source['datascadenza'];
    }

    $privacy = max((int)($target['privacy'] ?? 0), (int)($source['privacy'] ?? 0));
    $dataPrivacy = $target['data_privacy'] ?: $source['data_privacy'];
    if (($source['data_privacy'] ?? null) && ($target['data_privacy'] ?? null)) {
        $dataPrivacy = strcmp((string)$source['data_privacy'], (string)$target['data_privacy']) < 0
            ? $source['data_privacy']
            : $target['data_privacy'];
    }
    $isActive = max((int)($target['is_active'] ?? 0), (int)($source['is_active'] ?? 0));
    $tipoUser = (int)($target['tipo_user'] ?? $source['tipo_user'] ?? 3);

    $sql = "
        UPDATE dap01_users
        SET username = ?,
            password = ?,
            datascadenza = ?,
            tipo_user = ?,
            vector_id = ?,
            privacy = ?,
            data_privacy = ?,
            is_active = ?
        WHERE id_user = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    $stmt->bind_param(
        'sssisisii',
        $username,
        $password,
        $datascadenza,
        $tipoUser,
        $vectorId,
        $privacy,
        $dataPrivacy,
        $isActive,
        $target['id_user']
    );
    $stmt->execute();
    $stmt->close();
}

function applyMerge(
    mysqli $db,
    array $sourceClient,
    array $targetClient,
    string $keepCredentials
): void {
    $sourceClientId = (int)$sourceClient['id_client'];
    $targetClientId = (int)$targetClient['id_client'];
    $sourceUserId = (int)($sourceClient['id_user'] ?? 0);
    $targetUserId = (int)($targetClient['id_user'] ?? 0);

    $db->begin_transaction();

    try {
        $db->query("UPDATE dap10_message SET id_mitt = {$targetClientId} WHERE mitt = 'C' AND id_mitt = {$sourceClientId}");
        $db->query("UPDATE dap10_message SET id_dest = {$targetClientId} WHERE dest = 'C' AND id_dest = {$sourceClientId}");
        $db->query("
            UPDATE dap10_message_delete d
            INNER JOIN tmp_merge_legacy_main lm ON lm.id_message = d.id_message
            SET d.id_utente = {$targetClientId}
            WHERE d.id_utente = {$sourceClientId}
        ");

        $db->query("UPDATE dap10_message_reply SET id_mitt = {$targetClientId} WHERE mitt = 'C' AND id_mitt = {$sourceClientId}");
        $db->query("UPDATE dap10_message_reply SET id_dest = {$targetClientId} WHERE dest = 'C' AND id_dest = {$sourceClientId}");
        $db->query("
            UPDATE dap10_message_reply_delete d
            INNER JOIN tmp_merge_legacy_reply lr ON lr.id_message = d.id_message
            SET d.id_utente = {$targetClientId}
            WHERE d.id_utente = {$sourceClientId}
        ");

        $db->query("
            INSERT INTO dap09_client_doctor (id_client, id_dot)
            SELECT {$targetClientId}, d.id_dot
            FROM dap09_client_doctor d
            WHERE d.id_client = {$sourceClientId}
              AND NOT EXISTS (
                SELECT 1
                FROM dap09_client_doctor t
                WHERE t.id_client = {$targetClientId}
                  AND t.id_dot = d.id_dot
              )
        ");
        $db->query("DELETE FROM dap09_client_doctor WHERE id_client = {$sourceClientId}");

        $db->query("
            UPDATE dap02_clients t
            INNER JOIN dap02_clients s ON s.id_client = {$sourceClientId}
            SET t.id_personale = CASE
                    WHEN COALESCE(t.id_personale, 0) > 0 THEN t.id_personale
                    ELSE s.id_personale
                END,
                t.avviso_mail = GREATEST(COALESCE(t.avviso_mail, 0), COALESCE(s.avviso_mail, 0))
            WHERE t.id_client = {$targetClientId}
        ");

        $db->query("
            UPDATE msg_messages m
            INNER JOIN tmp_merge_messages mm ON mm.id_message = m.id_message
            SET m.sender_user_id = {$targetClientId}
            WHERE m.sender_user_id = {$sourceClientId}
        ");
        $db->query("
            UPDATE msg_messages m
            INNER JOIN tmp_merge_messages mm ON mm.id_message = m.id_message
            SET m.recipient_user_id = {$targetClientId}
            WHERE m.recipient_user_id = {$sourceClientId}
        ");
        $db->query("
            UPDATE msg_messages m
            INNER JOIN tmp_merge_messages mm ON mm.id_message = m.id_message
            SET m.reply_to_user_id = {$targetClientId}
            WHERE m.reply_to_user_id = {$sourceClientId}
        ");
        $db->query("
            UPDATE msg_messages m
            INNER JOIN tmp_merge_messages mm ON mm.id_message = m.id_message
            SET m.root_author_user_id = {$targetClientId}
            WHERE m.root_author_user_id = {$sourceClientId}
        ");
        $db->query("
            UPDATE msg_threads t
            INNER JOIN tmp_merge_threads tt ON tt.id_thread = t.id_thread
            SET t.root_author_user_id = {$targetClientId}
            WHERE t.root_author_user_id = {$sourceClientId}
        ");

        $db->query("
            INSERT INTO msg_user_flags (id_message, user_id, is_deleted, deleted_at, is_read, read_at, is_handled, handled_at)
            SELECT
                f.id_message,
                {$targetClientId},
                f.is_deleted,
                f.deleted_at,
                f.is_read,
                f.read_at,
                f.is_handled,
                f.handled_at
            FROM msg_user_flags f
            INNER JOIN tmp_merge_messages mm ON mm.id_message = f.id_message
            WHERE f.user_id = {$sourceClientId}
            ON DUPLICATE KEY UPDATE
                is_deleted = GREATEST(msg_user_flags.is_deleted, VALUES(is_deleted)),
                deleted_at = COALESCE(msg_user_flags.deleted_at, VALUES(deleted_at)),
                is_read = GREATEST(msg_user_flags.is_read, VALUES(is_read)),
                read_at = COALESCE(msg_user_flags.read_at, VALUES(read_at)),
                is_handled = GREATEST(msg_user_flags.is_handled, VALUES(is_handled)),
                handled_at = COALESCE(msg_user_flags.handled_at, VALUES(handled_at))
        ");
        $db->query("
            DELETE f
            FROM msg_user_flags f
            INNER JOIN tmp_merge_messages mm ON mm.id_message = f.id_message
            WHERE f.user_id = {$sourceClientId}
        ");

        $db->query("
            UPDATE msg_drafts d
            INNER JOIN tmp_merge_owner_drafts od ON od.id_draft = d.id_draft
            SET d.owner_user_id = {$targetClientId}
            WHERE d.owner_user_id = {$sourceClientId}
        ");
        $db->query("
            UPDATE msg_drafts d
            INNER JOIN tmp_merge_recipient_drafts rd ON rd.id_draft = d.id_draft
            SET d.recipient_user_id = {$targetClientId}
            WHERE d.recipient_user_id = {$sourceClientId}
        ");

        $db->query("
            UPDATE msg_attachments a
            INNER JOIN tmp_merge_patient_attachments pa ON pa.id_attachment = a.id_attachment
            SET a.uploaded_by_user_id = {$targetClientId}
            WHERE a.uploaded_by_user_id = {$sourceClientId}
        ");

        if ($sourceUserId > 0 && $targetUserId > 0 && $sourceUserId !== $targetUserId) {
            mergeUserRows($db, $sourceClient, $targetClient, $keepCredentials);

            $db->query("
                INSERT INTO dap_user_schede (id_user, id_scheda, can_view, can_access)
                SELECT {$targetUserId}, s.id_scheda, s.can_view, s.can_access
                FROM dap_user_schede s
                WHERE s.id_user = {$sourceUserId}
                ON DUPLICATE KEY UPDATE
                    can_view = GREATEST(dap_user_schede.can_view, VALUES(can_view)),
                    can_access = GREATEST(dap_user_schede.can_access, VALUES(can_access))
            ");
            $db->query("DELETE FROM dap_user_schede WHERE id_user = {$sourceUserId}");

            $db->query("UPDATE device_links SET user_id = {$targetUserId} WHERE user_id = {$sourceUserId}");
            $db->query("UPDATE push_subscriptions SET user_id = {$targetUserId} WHERE user_id = {$sourceUserId}");
            $db->query("UPDATE push_delivery_logs SET user_id = {$targetUserId} WHERE user_id = {$sourceUserId}");

            $db->query("
                INSERT IGNORE INTO dap_chat_thread_user (id_thread, id_user, last_read_at, cleared_at)
                SELECT id_thread, {$targetUserId}, last_read_at, cleared_at
                FROM dap_chat_thread_user
                WHERE id_user = {$sourceUserId}
            ");
            $db->query("DELETE FROM dap_chat_thread_user WHERE id_user = {$sourceUserId}");
        }

        $db->query("DELETE FROM dap02_clients WHERE id_client = {$sourceClientId} LIMIT 1");

        if ($sourceUserId > 0 && $targetUserId > 0 && $sourceUserId !== $targetUserId) {
            $db->query("DELETE FROM dap01_users WHERE id_user = {$sourceUserId} LIMIT 1");
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

$argv = $_SERVER['argv'] ?? [];
$apply = hasFlag($argv, '--apply');
$sourceClientId = requiredPositiveIntOption($argv, 'source-client');
$targetClientId = requiredPositiveIntOption($argv, 'target-client');
$keepCredentials = normalizeKeepCredentials(optionValue($argv, 'keep-credentials'));

if ($sourceClientId === $targetClientId) {
    throw new RuntimeException('source-client e target-client devono essere diversi');
}

$env = loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');
$db = connectDb(dbConfigFromEnv($env));

$sourceClient = fetchClientBundle($db, $sourceClientId);
$targetClient = fetchClientBundle($db, $targetClientId);

if (!$sourceClient) {
    throw new RuntimeException("Cliente sorgente non trovato: {$sourceClientId}");
}
if (!$targetClient) {
    throw new RuntimeException("Cliente target non trovato: {$targetClientId}");
}

buildTempScope($db, $sourceClientId, $sourceClient['doctor_ids']);
$counts = loadImpactCounts($db, $sourceClientId, (int)($sourceClient['id_user'] ?? 0));

$sourceUsername = (string)($sourceClient['username'] ?? '');
$targetUsername = (string)($targetClient['username'] ?? '');
$usernamesDiffer = $sourceUsername !== '' && $targetUsername !== '' && strcasecmp($sourceUsername, $targetUsername) !== 0;

echo "== MERGE DUPLICATE PATIENT ==\n";
echo "Modalita             : " . ($apply ? 'APPLY' : 'DRY RUN') . "\n";
echo "Source client        : {$sourceClientId} (id_user=" . (int)($sourceClient['id_user'] ?? 0) . ", username={$sourceUsername})\n";
echo "Target client        : {$targetClientId} (id_user=" . (int)($targetClient['id_user'] ?? 0) . ", username={$targetUsername})\n";
echo "Source doctors       : " . implode(',', $sourceClient['doctor_ids']) . "\n";
echo "Target doctors       : " . implode(',', $targetClient['doctor_ids']) . "\n";
echo "\n";
echo "Legacy main mitt     : {$counts['legacy_main_sender']}\n";
echo "Legacy main dest     : {$counts['legacy_main_recipient']}\n";
echo "Legacy main delete   : {$counts['legacy_main_delete_flags']}\n";
echo "Legacy reply mitt    : {$counts['legacy_reply_sender']}\n";
echo "Legacy reply dest    : {$counts['legacy_reply_recipient']}\n";
echo "Legacy reply delete  : {$counts['legacy_reply_delete_flags']}\n";
echo "New threads          : {$counts['new_threads']}\n";
echo "New msg sender       : {$counts['new_messages_sender']}\n";
echo "New msg recipient    : {$counts['new_messages_recipient']}\n";
echo "New msg reply_to     : {$counts['new_messages_reply_to']}\n";
echo "New msg root_author  : {$counts['new_messages_root_author']}\n";
echo "New thread root_auth : {$counts['new_threads_root_author']}\n";
echo "New user flags       : {$counts['new_flags']}\n";
echo "Patient drafts owner : {$counts['new_owner_drafts']}\n";
echo "Patient drafts recip : {$counts['new_recipient_drafts']}\n";
echo "Patient attachments  : {$counts['new_patient_attachments']}\n";
echo "User schede          : {$counts['user_schede']}\n";
echo "Device links         : {$counts['device_links']}\n";
echo "Push subscriptions   : {$counts['push_subscriptions']}\n";
echo "Push delivery logs   : {$counts['push_delivery_logs']}\n";
echo "Chat memberships     : {$counts['chat_thread_user']}\n";
echo "\n";

if ($usernamesDiffer) {
    echo "ATTENZIONE: gli username dei due account sono diversi.\n";
    echo "  source: {$sourceUsername}\n";
    echo "  target: {$targetUsername}\n";
    echo "  per APPLY serve: --keep-credentials=source oppure --keep-credentials=target\n";
    echo "\n";
}

if (!$apply) {
    echo "Dry run completato. Nessuna modifica applicata.\n";
    echo "Per applicare: php merge_duplicate_patient.php --source-client={$sourceClientId} --target-client={$targetClientId}";
    if ($usernamesDiffer) {
        echo " --keep-credentials=target";
    }
    echo " --apply\n";
    exit(0);
}

if ($usernamesDiffer && $keepCredentials === null) {
    throw new RuntimeException('In APPLY devi specificare --keep-credentials=target oppure --keep-credentials=source');
}

if ($keepCredentials === null) {
    $keepCredentials = 'target';
}

applyMerge($db, $sourceClient, $targetClient, $keepCredentials);

echo "Merge completato con successo.\n";
