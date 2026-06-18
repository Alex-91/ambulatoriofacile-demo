<?php
set_time_limit(0);
ini_set('max_execution_time', '0');
ignore_user_abort(true);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * MIGRAZIONE MESSAGGI LEGACY -> NUOVO PORTALE
 *
 * Obiettivi:
 * - migrare thread, reply, flag e allegati dal legacy al nuovo modulo
 * - usare gli actor id del nuovo sistema:
 *   - pazienti  => dap02_clients.id_client
 *   - personale => dap03_personale.id_personale
 * - supportare uno o più dottori nello stesso run
 * - preservare la leggibilità dei thread per dottore, staff e pazienti
 *
 * Note:
 * - le mailbox del nuovo modulo lavorano su msg_threads/msg_messages/msg_user_flags
 * - per staff i flag di read/handled sono separati per ruolo sul contesto dottore
 * - gli allegati legacy vengono copiati nel layout del nuovo modulo:
 *   writable/uploads/messages/<id_message_nuovo>/
 */

// ========================
// CONFIG
// ========================
$DB = [
    'host' => '127.0.0.1',
    'user' => 'root',
    'pass' => 'root',
    'db'   => 'mail',
];

$DRY_RUN                = false;
$BATCH_SIZE             = 100;
$FILTER_ID_PERSONALE    = [8,23,35,55,60,65,66,72,73,75,76]; // supporta int, string "22,25" o array
$REENCRYPT_MESSAGE_BODY = false;
$DEBUG_ATTACHMENTS      = true;
$SKIP_MISSING_ATTACHMENTS = true;

const FLAG_SEG_OFFSET = 100000000;
const FLAG_INF_OFFSET = 200000000;

// Tabelle vecchie
$T_OLD_MSG   = 'dap10_message';
$T_OLD_REPLY = 'dap10_message_reply';
$T_OLD_DEL   = 'dap10_message_delete';
$T_OLD_RDEL  = 'dap10_message_reply_delete';
$T_OLD_ATT   = 'dap11_attachments';

// Tabelle nuove
$T_NEW_THREADS = 'msg_threads';
$T_NEW_MSG     = 'msg_messages';
$T_NEW_FLAGS   = 'msg_user_flags';
$T_NEW_ATT     = 'msg_attachments';

// ========================
// CONNESSIONE
// ========================
$db = new mysqli($DB['host'], $DB['user'], $DB['pass'], $DB['db']);
$db->set_charset('utf8mb4');

$db->query("SET NAMES utf8mb4");
$db->query("SET lc_time_names = 'it_IT'");
$db->query("SET SESSION block_encryption_mode = 'aes-256-cbc'");
$db->query("SET @key_str = SHA2('PartitaIVA22', 512)");
$db->query("SET @init_vector = RANDOM_BYTES(16)");

// ========================
// HELPERS GENERICI
// ========================
function nowUtc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function normalizePath(string $path): string
{
    $path = trim($path);
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $path = preg_replace('#' . preg_quote(DIRECTORY_SEPARATOR, '#') . '+#', DIRECTORY_SEPARATOR, $path);
    return rtrim((string)$path, DIRECTORY_SEPARATOR);
}

function normalizeSlashPath(string $path): string
{
    $path = trim($path);
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);
    return trim($path, '/');
}

function splitDirAndFile(string $maybePath): array
{
    $normalized = normalizeSlashPath($maybePath);
    if ($normalized === '' || strpos($normalized, '/') === false) {
        return ['', $normalized];
    }

    $dir = dirname($normalized);
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }

    return [$dir, basename($normalized)];
}

function randomVectorId(int $len = 16): string
{
    return random_bytes($len);
}

function debugValue($value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_string($value)) {
        $clean = preg_replace('/[^\P{C}\n\r\t]/u', '?', $value);
        return '"' . $clean . '"';
    }

    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function debugBlock(string $title, array $data): void
{
    if (empty($GLOBALS['DEBUG_ATTACHMENTS'])) {
        return;
    }

    echo "\n================ {$title} ================\n";
    foreach ($data as $k => $v) {
        echo str_pad($k, 36, ' ', STR_PAD_RIGHT) . ': ' . debugValue($v) . "\n";
    }
    echo "================================================================\n";
}

function normalizeDoctorIds($configured, array $argv): array
{
    $raw = $configured;

    if (PHP_SAPI !== 'cli' && isset($_GET['doctors'])) {
        $raw = (string)$_GET['doctors'];
    }

    foreach ($argv as $arg) {
        if (!is_string($arg)) {
            continue;
        }

        if (preg_match('/^--doctors?=(.+)$/i', $arg, $m)) {
            $raw = $m[1];
        }
    }

    if (is_int($raw)) {
        $raw = [$raw];
    } elseif (is_string($raw)) {
        $raw = preg_split('/[\s,;|]+/', trim($raw)) ?: [];
    } elseif (!is_array($raw)) {
        $raw = [];
    }

    $ids = [];
    foreach ($raw as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    ksort($ids);
    return array_values($ids);
}

function csvInts(array $ids): string
{
    return implode(',', array_map(static fn ($v) => (string)(int)$v, $ids));
}

function flagUserIdForRole(int $doctorId, string $role): int
{
    $role = strtoupper(trim($role));
    if ($role === 'SEGRETERIA') {
        return FLAG_SEG_OFFSET + $doctorId;
    }
    if ($role === 'INFERMIERE') {
        return FLAG_INF_OFFSET + $doctorId;
    }
    return $doctorId;
}

function arraySetAdd(array &$set, int $value): void
{
    if ($value > 0) {
        $set[$value] = $value;
    }
}

function relationAdd(array &$map, int $key, int $value): void
{
    if ($key <= 0 || $value <= 0) {
        return;
    }

    if (!isset($map[$key])) {
        $map[$key] = [];
    }
    $map[$key][$value] = $value;
}

function relationValues(array $map, int $key): array
{
    if (!isset($map[$key])) {
        return [];
    }
    return array_values($map[$key]);
}

// ========================
// TABELLE SUPPORTO MIGRAZIONE
// ========================
function tableHasColumn(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $found = (bool)$res->fetch_assoc();
    $stmt->close();
    return $found;
}

function primaryKeyColumns(mysqli $db, string $table): array
{
    $res = $db->query("SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'");
    $cols = [];
    while ($row = $res->fetch_assoc()) {
        $cols[(int)$row['Seq_in_index']] = (string)$row['Column_name'];
    }
    ksort($cols);
    return array_values($cols);
}

function ensureMigrationTables(mysqli $db): void
{
    $db->query("
        CREATE TABLE IF NOT EXISTS msg_migration_map (
          old_table   varchar(60) NOT NULL,
          old_id      bigint NOT NULL,
          map_key     varchar(190) NOT NULL,
          new_id      bigint NOT NULL,
          extra       varchar(255) DEFAULT NULL,
          PRIMARY KEY (old_table, old_id, map_key),
          KEY idx_new_id (new_id)
        ) ENGINE=InnoDB
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS msg_migration_checkpoint (
          k varchar(80) PRIMARY KEY,
          v varchar(255) NOT NULL
        ) ENGINE=InnoDB
    ");

    if (!tableHasColumn($db, 'msg_migration_map', 'map_key')) {
        $db->query("ALTER TABLE msg_migration_map ADD COLUMN map_key varchar(190) NOT NULL DEFAULT 'LEGACY' AFTER old_id");
    }

    if (!tableHasColumn($db, 'msg_migration_map', 'extra')) {
        $db->query("ALTER TABLE msg_migration_map ADD COLUMN extra varchar(255) DEFAULT NULL AFTER new_id");
    }

    $pk = primaryKeyColumns($db, 'msg_migration_map');
    if ($pk !== ['old_table', 'old_id', 'map_key']) {
        $db->query("ALTER TABLE msg_migration_map DROP PRIMARY KEY, ADD PRIMARY KEY (old_table, old_id, map_key)");
    }

    $res = $db->query("SHOW INDEX FROM msg_migration_map WHERE Key_name = 'idx_new_id'");
    if ($res->num_rows === 0) {
        $db->query("ALTER TABLE msg_migration_map ADD KEY idx_new_id (new_id)");
    }
}

function getCheckpoint(mysqli $db, string $k, string $default = '0'): string
{
    $stmt = $db->prepare("SELECT v FROM msg_migration_checkpoint WHERE k = ?");
    $stmt->bind_param("s", $k);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (string)$row['v'] : $default;
}

function setCheckpoint(mysqli $db, string $k, string $v): void
{
    $stmt = $db->prepare("
        INSERT INTO msg_migration_checkpoint(k, v)
        VALUES(?, ?)
        ON DUPLICATE KEY UPDATE v = VALUES(v)
    ");
    $stmt->bind_param("ss", $k, $v);
    $stmt->execute();
    $stmt->close();
}

function mapGet(mysqli $db, string $oldTable, int $oldId, string $mapKey): ?int
{
    $stmt = $db->prepare("
        SELECT new_id
        FROM msg_migration_map
        WHERE old_table = ?
          AND old_id = ?
          AND map_key = ?
        LIMIT 1
    ");
    $stmt->bind_param("sis", $oldTable, $oldId, $mapKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int)$row['new_id'] : null;
}

function mapPut(mysqli $db, string $oldTable, int $oldId, string $mapKey, int $newId, ?string $extra = null): void
{
    $stmt = $db->prepare("
        INSERT INTO msg_migration_map(old_table, old_id, map_key, new_id, extra)
        VALUES(?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            new_id = VALUES(new_id),
            extra  = VALUES(extra)
    ");
    $stmt->bind_param("sisis", $oldTable, $oldId, $mapKey, $newId, $extra);
    $stmt->execute();
    $stmt->close();
}

// ========================
// CACHE SCOPE DOTTORI
// ========================
function loadMigrationScope(mysqli $db, array $doctorIds): array
{
    $scope = [
        'doctor_ids'         => [],
        'patient_client_ids' => [],
        'patient_to_doctor'  => [],
        'seg_personale_ids'  => [],
        'inf_personale_ids'  => [],
        'doctor_to_seg'      => [],
        'doctor_to_inf'      => [],
        'seg_to_doctors'     => [],
        'inf_to_doctors'     => [],
    ];

    foreach ($doctorIds as $doctorId) {
        arraySetAdd($scope['doctor_ids'], $doctorId);
    }

    if (!$doctorIds) {
        return $scope;
    }

    $inDoctors = csvInts($doctorIds);

    $res = $db->query("
        SELECT id_client, id_dot AS id_personale
        FROM dap09_client_doctor
        WHERE id_dot IN ({$inDoctors})
    ");
    while ($row = $res->fetch_assoc()) {
        $clientId = (int)$row['id_client'];
        $doctorId = (int)$row['id_personale'];
        arraySetAdd($scope['patient_client_ids'], $clientId);
        if ($doctorId > 0) {
            $scope['patient_to_doctor'][$clientId] = $doctorId;
        }
    }

    $res = $db->query("
        SELECT sd.id_dot, p.id_personale
        FROM dap14_seg_dot sd
        INNER JOIN dap03_personale p ON p.id_personale = sd.id_seg
        WHERE sd.id_dot IN ({$inDoctors})
    ");
    while ($row = $res->fetch_assoc()) {
        $doctorId = (int)$row['id_dot'];
        $staffId  = (int)$row['id_personale'];
        arraySetAdd($scope['seg_personale_ids'], $staffId);
        relationAdd($scope['doctor_to_seg'], $doctorId, $staffId);
        relationAdd($scope['seg_to_doctors'], $staffId, $doctorId);
    }

    $res = $db->query("
        SELECT idt.id_dot, p.id_personale
        FROM dap15_inf_dot idt
        INNER JOIN dap03_personale p ON p.id_personale = idt.id_inf
        WHERE idt.id_dot IN ({$inDoctors})
    ");
    while ($row = $res->fetch_assoc()) {
        $doctorId = (int)$row['id_dot'];
        $staffId  = (int)$row['id_personale'];
        arraySetAdd($scope['inf_personale_ids'], $staffId);
        relationAdd($scope['doctor_to_inf'], $doctorId, $staffId);
        relationAdd($scope['inf_to_doctors'], $staffId, $doctorId);
    }

    return $scope;
}

function isDoctorActorId(int $actorId, array $scope): bool
{
    return $actorId > 0 && isset($scope['doctor_ids'][$actorId]);
}

function isPatientActorId(int $actorId, array $scope): bool
{
    return $actorId > 0 && isset($scope['patient_client_ids'][$actorId]);
}

function isSegActorId(int $actorId, array $scope): bool
{
    return $actorId > 0 && isset($scope['seg_personale_ids'][$actorId]);
}

function isInfActorId(int $actorId, array $scope): bool
{
    return $actorId > 0 && isset($scope['inf_personale_ids'][$actorId]);
}

function personaleExists(mysqli $db, int $personaleId): bool
{
    static $cache = [];
    if ($personaleId <= 0) {
        return false;
    }
    if (array_key_exists($personaleId, $cache)) {
        return $cache[$personaleId];
    }

    $stmt = $db->prepare("SELECT 1 FROM dap03_personale WHERE id_personale = ? LIMIT 1");
    $stmt->bind_param("i", $personaleId);
    $stmt->execute();
    $cache[$personaleId] = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $cache[$personaleId];
}

function clientExists(mysqli $db, int $clientId): bool
{
    static $cache = [];
    if ($clientId <= 0) {
        return false;
    }
    if (array_key_exists($clientId, $cache)) {
        return $cache[$clientId];
    }

    $stmt = $db->prepare("SELECT 1 FROM dap02_clients WHERE id_client = ? LIMIT 1");
    $stmt->bind_param("i", $clientId);
    $stmt->execute();
    $cache[$clientId] = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $cache[$clientId];
}

function detectLegacyActorType(mysqli $db, array $row, string $side, array $scope): string
{
    $field = $side === 'sender' ? 'id_mitt' : 'id_dest';
    $codeField = $side === 'sender' ? 'mitt' : 'dest';

    $id = (int)($row[$field] ?? 0);
    $code = strtoupper(trim((string)($row[$codeField] ?? '')));

    if ($id <= 0) {
        return 'UNKNOWN';
    }

    if (isPatientActorId($id, $scope)) {
        return 'PATIENT';
    }
    if (isDoctorActorId($id, $scope)) {
        return 'DOCTOR';
    }
    if (isSegActorId($id, $scope)) {
        return 'SEGRETERIA';
    }
    if (isInfActorId($id, $scope)) {
        return 'INFERMIERE';
    }

    if ($code === 'C') {
        return clientExists($db, $id) ? 'PATIENT' : 'UNKNOWN';
    }

    if ($code === 'S' && personaleExists($db, $id)) {
        return 'SEGRETERIA';
    }

    if ($code === 'I' && personaleExists($db, $id)) {
        return 'INFERMIERE';
    }

    if (in_array($code, ['P', 'D', 'M'], true) && personaleExists($db, $id)) {
        return 'DOCTOR';
    }

    if (clientExists($db, $id) && !personaleExists($db, $id)) {
        return 'PATIENT';
    }

    if (personaleExists($db, $id)) {
        return 'PERSONALE_OTHER';
    }

    if (clientExists($db, $id)) {
        return 'PATIENT';
    }

    return 'UNKNOWN';
}

function resolveLegacyActorId(mysqli $db, array $row, string $side, array $scope): ?int
{
    $field = $side === 'sender' ? 'id_mitt' : 'id_dest';
    $id = (int)($row[$field] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $type = detectLegacyActorType($db, $row, $side, $scope);
    if ($type === 'PATIENT' && clientExists($db, $id)) {
        return $id;
    }
    if (in_array($type, ['DOCTOR', 'SEGRETERIA', 'INFERMIERE', 'PERSONALE_OTHER'], true) && personaleExists($db, $id)) {
        return $id;
    }

    if (clientExists($db, $id)) {
        return $id;
    }
    if (personaleExists($db, $id)) {
        return $id;
    }

    return null;
}

function resolveDoctorContextIdForRow(mysqli $db, array $row, array $scope): int
{
    $candidates = [];

    $explicitDoctors = [
        (int)($row['dot_seg'] ?? 0),
        (int)($row['dot_inf'] ?? 0),
        (int)($row['id_mitt'] ?? 0),
        (int)($row['id_dest'] ?? 0),
    ];

    foreach ($explicitDoctors as $candidate) {
        if (isDoctorActorId($candidate, $scope)) {
            $candidates[$candidate] = $candidate;
        }
    }

    foreach (relationValues($scope['seg_to_doctors'], (int)($row['id_mitt'] ?? 0)) as $doctorId) {
        $candidates[$doctorId] = $doctorId;
    }
    foreach (relationValues($scope['seg_to_doctors'], (int)($row['id_dest'] ?? 0)) as $doctorId) {
        $candidates[$doctorId] = $doctorId;
    }
    foreach (relationValues($scope['inf_to_doctors'], (int)($row['id_mitt'] ?? 0)) as $doctorId) {
        $candidates[$doctorId] = $doctorId;
    }
    foreach (relationValues($scope['inf_to_doctors'], (int)($row['id_dest'] ?? 0)) as $doctorId) {
        $candidates[$doctorId] = $doctorId;
    }

    if (count($candidates) === 1) {
        return (int)array_values($candidates)[0];
    }

    return 0;
}

function rowBelongsToScope(mysqli $db, array $row, array $scope): bool
{
    return resolveDoctorContextIdForRow($db, $row, $scope) > 0;
}

function resolveRecipientDescriptor(mysqli $db, array $row, array $scope, int $doctorContextId): ?array
{
    $destCode = strtoupper(trim((string)($row['dest'] ?? '')));
    $destActorId = resolveLegacyActorId($db, $row, 'dest', $scope);

    if ($destCode === 'C') {
        $patientId = $destActorId;
        if ($patientId === null || !clientExists($db, $patientId)) {
            $patientId = resolveLegacyActorId($db, $row, 'sender', $scope);
            if ($patientId !== null && !clientExists($db, $patientId)) {
                $patientId = null;
            }
        }

        if ($patientId !== null && clientExists($db, $patientId)) {
            return ['type' => 'USER', 'user_id' => $patientId, 'role' => null];
        }

        return null;
    }

    if (in_array($destCode, ['P', 'D', 'M'], true)) {
        $doctorId = 0;
        if ($destActorId !== null && isDoctorActorId($destActorId, $scope)) {
            $doctorId = $destActorId;
        }
        if ($doctorId <= 0) {
            $doctorId = $doctorContextId;
        }
        if ($doctorId > 0) {
            return ['type' => 'USER', 'user_id' => $doctorId, 'role' => null];
        }
        if ($destActorId !== null) {
            return ['type' => 'USER', 'user_id' => $destActorId, 'role' => null];
        }
        return null;
    }

    if ($destCode === 'S') {
        if ($destActorId !== null && isSegActorId($destActorId, $scope)) {
            return ['type' => 'USER', 'user_id' => $destActorId, 'role' => null];
        }
        if ($doctorContextId > 0) {
            return ['type' => 'USER', 'user_id' => $doctorContextId, 'role' => 'SEGRETERIA'];
        }
        if ($destActorId !== null) {
            return ['type' => 'USER', 'user_id' => $destActorId, 'role' => 'SEGRETERIA'];
        }
        return null;
    }

    if ($destCode === 'I') {
        if ($destActorId !== null && isInfActorId($destActorId, $scope)) {
            return ['type' => 'USER', 'user_id' => $destActorId, 'role' => null];
        }
        if ($doctorContextId > 0) {
            return ['type' => 'USER', 'user_id' => $doctorContextId, 'role' => 'INFERMIERE'];
        }
        if ($destActorId !== null) {
            return ['type' => 'USER', 'user_id' => $destActorId, 'role' => 'INFERMIERE'];
        }
        return null;
    }

    if ($destActorId !== null) {
        return ['type' => 'USER', 'user_id' => $destActorId, 'role' => null];
    }

    return null;
}

function isForward(array $row): bool
{
    return trim((string)($row['inoltrato'] ?? '')) !== '';
}

function isOldThreadHandled(array $rootRow): bool
{
    return (int)($rootRow['gestita'] ?? 0) === 1;
}

function transformMessageBodyForNewDb(array $row, bool $reencrypt): array
{
    $oldBody = (string)($row['testo'] ?? '');
    $oldVector = $row['vector_id'] ?? null;

    if ($reencrypt) {
        return [
            'body_cipher_hex' => $oldBody,
            'vector_id'       => $oldVector ?: randomVectorId(16),
        ];
    }

    return [
        'body_cipher_hex' => $oldBody,
        'vector_id'       => $oldVector ?: randomVectorId(16),
    ];
}

function insertThread(mysqli $db, int $rootAuthorActorId, string $createdAt): int
{
    $stmt = $db->prepare("
        INSERT INTO msg_threads(root_message_id, root_author_user_id, created_at, updated_at)
        VALUES(NULL, ?, ?, ?)
    ");
    $stmt->bind_param("iss", $rootAuthorActorId, $createdAt, $createdAt);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

function insertMessage(mysqli $db, array $row): int
{
    $stmt = $db->prepare("
        INSERT INTO msg_messages(
          id_thread, message_type, status,
          root_message_id, parent_message_id, reply_to_user_id,
          sender_user_id,
          recipient_type, recipient_user_id, recipient_role,
          body_cipher_hex, vector_id,
          root_author_user_id,
          created_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
        "issiiississsis",
        $row['id_thread'],
        $row['message_type'],
        $row['status'],
        $row['root_message_id'],
        $row['parent_message_id'],
        $row['reply_to_user_id'],
        $row['sender_user_id'],
        $row['recipient_type'],
        $row['recipient_user_id'],
        $row['recipient_role'],
        $row['body_cipher_hex'],
        $row['vector_id'],
        $row['root_author_user_id'],
        $row['created_at']
    );

    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();

    return $id;
}

function updateThreadRoot(mysqli $db, int $threadId, int $rootMessageId, string $updatedAt): void
{
    $stmt = $db->prepare("UPDATE msg_threads SET root_message_id = ?, updated_at = ? WHERE id_thread = ?");
    $stmt->bind_param("isi", $rootMessageId, $updatedAt, $threadId);
    $stmt->execute();
    $stmt->close();
}

function touchThread(mysqli $db, int $threadId, string $updatedAt): void
{
    $stmt = $db->prepare("UPDATE msg_threads SET updated_at = ? WHERE id_thread = ?");
    $stmt->bind_param("si", $updatedAt, $threadId);
    $stmt->execute();
    $stmt->close();
}

function upsertFlag(mysqli $db, int $messageId, int $userId, array $flagData): void
{
    if ($userId <= 0) {
        return;
    }

    $isDeleted = (int)($flagData['is_deleted'] ?? 0);
    $deletedAt = $flagData['deleted_at'] ?? null;
    $isRead    = (int)($flagData['is_read'] ?? 0);
    $readAt    = $flagData['read_at'] ?? null;
    $isHandled = (int)($flagData['is_handled'] ?? 0);
    $handledAt = $flagData['handled_at'] ?? null;

    $stmt = $db->prepare("
        INSERT INTO msg_user_flags(
            id_message, user_id, is_deleted, deleted_at,
            is_read, read_at, is_handled, handled_at
        ) VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            is_deleted = VALUES(is_deleted),
            deleted_at = VALUES(deleted_at),
            is_read    = VALUES(is_read),
            read_at    = VALUES(read_at),
            is_handled = VALUES(is_handled),
            handled_at = VALUES(handled_at)
    ");

    $stmt->bind_param(
        "iiisisis",
        $messageId,
        $userId,
        $isDeleted,
        $deletedAt,
        $isRead,
        $readAt,
        $isHandled,
        $handledAt
    );
    $stmt->execute();
    $stmt->close();
}

function insertAttachmentMetadata(mysqli $db, array $attachment): int
{
    $stmt = $db->prepare("
        INSERT INTO msg_attachments(
          id_message,
          id_draft,
          uploaded_by_user_id,
          original_name,
          stored_name,
          mime_type,
          file_size,
          storage_path,
          created_at,
          vector_id
        ) VALUES (?,?,?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
        "iiisssisss",
        $attachment['id_message'],
        $attachment['id_draft'],
        $attachment['uploaded_by_user_id'],
        $attachment['original_name'],
        $attachment['stored_name'],
        $attachment['mime_type'],
        $attachment['file_size'],
        $attachment['storage_path'],
        $attachment['created_at'],
        $attachment['vector_id']
    );

    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();

    return $id;
}

function isIncomingToDoctorMailbox(array $row, array $recipient, int $doctorContextId): bool
{
    if ($doctorContextId <= 0) {
        return false;
    }

    $destCode = strtoupper(trim((string)($row['dest'] ?? '')));
    if (!in_array($destCode, ['P', 'S', 'I', 'D', 'M'], true)) {
        return false;
    }

    $recipientUserId = (int)($recipient['user_id'] ?? 0);
    $recipientRole   = strtoupper(trim((string)($recipient['role'] ?? '')));

    if ($recipientUserId === $doctorContextId) {
        return true;
    }

    return in_array($recipientRole, ['SEGRETERIA', 'INFERMIERE'], true);
}

function readFlagTargetIds(array $row, array $recipient, int $doctorContextId, array $scope): array
{
    $targets = [];

    $recipientUserId = (int)($recipient['user_id'] ?? 0);
    $recipientRole   = strtoupper(trim((string)($recipient['role'] ?? '')));

    if ($recipientUserId > 0 && $recipientRole === '') {
        $targets[$recipientUserId] = $recipientUserId;
    }

    if (isIncomingToDoctorMailbox($row, $recipient, $doctorContextId)) {
        $targets[$doctorContextId] = $doctorContextId;

        if (!empty($scope['doctor_to_seg'][$doctorContextId])) {
            $targets[flagUserIdForRole($doctorContextId, 'SEGRETERIA')] = flagUserIdForRole($doctorContextId, 'SEGRETERIA');
        }
        if (!empty($scope['doctor_to_inf'][$doctorContextId])) {
            $targets[flagUserIdForRole($doctorContextId, 'INFERMIERE')] = flagUserIdForRole($doctorContextId, 'INFERMIERE');
        }
    }

    return array_values($targets);
}

function handledFlagTargetIds(int $doctorContextId, array $scope): array
{
    if ($doctorContextId <= 0) {
        return [];
    }

    $targets = [$doctorContextId => $doctorContextId];
    if (!empty($scope['doctor_to_seg'][$doctorContextId])) {
        $targets[flagUserIdForRole($doctorContextId, 'SEGRETERIA')] = flagUserIdForRole($doctorContextId, 'SEGRETERIA');
    }
    if (!empty($scope['doctor_to_inf'][$doctorContextId])) {
        $targets[flagUserIdForRole($doctorContextId, 'INFERMIERE')] = flagUserIdForRole($doctorContextId, 'INFERMIERE');
    }

    return array_values($targets);
}

function applyLegacyFlags(
    mysqli $db,
    array $legacyRow,
    int $newMessageId,
    int $senderActorId,
    array $recipient,
    int $doctorContextId,
    bool $rootHandled,
    string $createdAt,
    array $scope
): void {
    $flags = [];

    $flags[$senderActorId] = [
        'is_read' => 1,
        'read_at' => $createdAt,
    ];

    $wasRead = (int)($legacyRow['letto'] ?? 0) === 1;
    if ($wasRead) {
        foreach (readFlagTargetIds($legacyRow, $recipient, $doctorContextId, $scope) as $flagUserId) {
            if ($flagUserId <= 0) {
                continue;
            }
            if (!isset($flags[$flagUserId])) {
                $flags[$flagUserId] = [];
            }
            $flags[$flagUserId]['is_read'] = 1;
            $flags[$flagUserId]['read_at'] = $createdAt;
        }
    }

    if ($rootHandled) {
        foreach (handledFlagTargetIds($doctorContextId, $scope) as $flagUserId) {
            if ($flagUserId <= 0) {
                continue;
            }
            if (!isset($flags[$flagUserId])) {
                $flags[$flagUserId] = [];
            }
            $flags[$flagUserId]['is_handled'] = 1;
            $flags[$flagUserId]['handled_at'] = $createdAt;
        }
    }

    foreach ($flags as $flagUserId => $flagData) {
        upsertFlag($db, $newMessageId, (int)$flagUserId, $flagData);
    }
}

function deleteFlagTargetIds(mysqli $db, int $oldUserId, int $doctorContextId, array $scope): array
{
    $targets = [];
    if ($oldUserId <= 0) {
        return [];
    }

    if (clientExists($db, $oldUserId) || personaleExists($db, $oldUserId)) {
        $targets[$oldUserId] = $oldUserId;
    }

    if ($doctorContextId > 0) {
        if ($oldUserId === $doctorContextId) {
            if (!empty($scope['doctor_to_seg'][$doctorContextId])) {
                $targets[flagUserIdForRole($doctorContextId, 'SEGRETERIA')] = flagUserIdForRole($doctorContextId, 'SEGRETERIA');
            }
            if (!empty($scope['doctor_to_inf'][$doctorContextId])) {
                $targets[flagUserIdForRole($doctorContextId, 'INFERMIERE')] = flagUserIdForRole($doctorContextId, 'INFERMIERE');
            }
        }
        if (isSegActorId($oldUserId, $scope)) {
            $targets[flagUserIdForRole($doctorContextId, 'SEGRETERIA')] = flagUserIdForRole($doctorContextId, 'SEGRETERIA');
        }
        if (isInfActorId($oldUserId, $scope)) {
            $targets[flagUserIdForRole($doctorContextId, 'INFERMIERE')] = flagUserIdForRole($doctorContextId, 'INFERMIERE');
        }
    }

    return array_values($targets);
}

function applyLegacyDeleteFlags(
    mysqli $db,
    string $deleteTable,
    int $oldMessageId,
    int $newMessageId,
    string $deletedAt,
    int $doctorContextId,
    array $scope
): void {
    $stmt = $db->prepare("
        SELECT id_utente
        FROM {$deleteTable}
        WHERE id_message = ?
          AND eliminato = 1
    ");
    $stmt->bind_param("i", $oldMessageId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $oldUserId = (int)$row['id_utente'];
        foreach (deleteFlagTargetIds($db, $oldUserId, $doctorContextId, $scope) as $flagUserId) {
            upsertFlag($db, $newMessageId, $flagUserId, [
                'is_deleted' => 1,
                'deleted_at' => $deletedAt,
            ]);
        }
    }

    $stmt->close();
}

// ========================
// ALLEGATI
// ========================
function decryptOldAttachmentRow(mysqli $db, int $oldAttachmentId): ?array
{
    $sql = "
        SELECT
            a.id_attachments,
            a.id_message,
            a.id_message_reply,
            a.vector_id,
            CAST(AES_DECRYPT(UNHEX(a.nome_real), @key_str, a.vector_id) AS CHAR(2500) CHARACTER SET utf8mb4) AS nome_real_plain,
            CAST(AES_DECRYPT(UNHEX(a.nome_vis),  @key_str, a.vector_id) AS CHAR(2500) CHARACTER SET utf8mb4) AS nome_vis_plain,
            a.nome_real AS nome_real_raw,
            a.nome_vis  AS nome_vis_raw
        FROM dap11_attachments a
        WHERE a.id_attachments = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $oldAttachmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function resolveOldAttachmentPlainValues(mysqli $db, int $oldAttachmentId): ?array
{
    $row = decryptOldAttachmentRow($db, $oldAttachmentId);
    if (!$row) {
        return null;
    }

    $nomeRealPlain = str_replace('\\', '/', (string)($row['nome_real_plain'] ?? ''));
    $nomeVisPlain  = trim((string)($row['nome_vis_plain'] ?? ''));

    [$storagePathPlain, $storedNamePlain] = splitDirAndFile($nomeRealPlain);

    $result = [
        'id_attachments'      => (int)$row['id_attachments'],
        'id_message'          => $row['id_message'] !== null ? (int)$row['id_message'] : null,
        'id_message_reply'    => $row['id_message_reply'] !== null ? (int)$row['id_message_reply'] : null,
        'old_vector_id'       => $row['vector_id'],
        'old_vector_id_hex'   => is_string($row['vector_id']) ? bin2hex($row['vector_id']) : null,
        'nome_real_raw'       => (string)($row['nome_real_raw'] ?? ''),
        'nome_vis_raw'        => (string)($row['nome_vis_raw'] ?? ''),
        'original_name_plain' => $nomeVisPlain !== '' ? $nomeVisPlain : basename($storedNamePlain),
        'nome_real_plain'     => $nomeRealPlain,
        'stored_name_plain'   => $storedNamePlain,
        'storage_path_plain'  => $storagePathPlain,
    ];

    debugBlock('ALLEGATO VECCHIO DECRYPT', [
        'id_attachments'      => $result['id_attachments'],
        'old_vector_id_hex'   => $result['old_vector_id_hex'],
        'nome_vis_raw'        => $result['nome_vis_raw'],
        'nome_real_raw'       => $result['nome_real_raw'],
        'nome_vis_plain'      => $result['original_name_plain'],
        'nome_real_plain'     => $result['nome_real_plain'],
        'stored_name_plain'   => $result['stored_name_plain'],
        'storage_path_plain'  => $result['storage_path_plain'],
    ]);

    return $result;
}

function encryptStringForNewDb(mysqli $db, string $plainText, string $vector): string
{
    $sql = "SELECT HEX(AES_ENCRYPT(?, @key_str, ?)) AS cipher_hex";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ss", $plainText, $vector);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (string)($row['cipher_hex'] ?? '');
}

function reEncryptAttachmentFields(mysqli $db, string $originalNamePlain, string $storedNamePlain, string $storagePathPlain): array
{
    $newVector = randomVectorId(16);

    $encrypted = [
        'vector_id'     => $newVector,
        'vector_id_hex' => bin2hex($newVector),
        'original_name' => encryptStringForNewDb($db, $originalNamePlain, $newVector),
        'stored_name'   => encryptStringForNewDb($db, $storedNamePlain, $newVector),
        'storage_path'  => encryptStringForNewDb($db, $storagePathPlain, $newVector),
    ];

    debugBlock('ALLEGATO NUOVO RE-ENCRYPT', [
        'original_name_plain' => $originalNamePlain,
        'stored_name_plain'   => $storedNamePlain,
        'storage_path_plain'  => $storagePathPlain,
        'new_vector_id_hex'   => $encrypted['vector_id_hex'],
        'original_name_hex'   => $encrypted['original_name'],
        'stored_name_hex'     => $encrypted['stored_name'],
        'storage_path_hex'    => $encrypted['storage_path'],
    ]);

    return $encrypted;
}

function guessMimeType(string $name, ?string $sourcePath = null): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    if (isset($map[$ext])) {
        return $map[$ext];
    }

    if ($sourcePath && is_file($sourcePath) && function_exists('mime_content_type')) {
        $mime = @mime_content_type($sourcePath);
        if (is_string($mime) && trim($mime) !== '') {
            return trim($mime);
        }
    }

    return 'application/octet-stream';
}

function buildNewAttachmentTargetPath(int $newMessageId, string $storedNamePlain): string
{
    $storedNamePlain = trim($storedNamePlain);
    if ($storedNamePlain === '') {
        $storedNamePlain = 'attachment.crypto';
    }
    if (!preg_match('/\.crypto$/i', $storedNamePlain)) {
        $storedNamePlain .= '.crypto';
    }

    $targetDir = normalizePath(
        __DIR__ . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'messages' . DIRECTORY_SEPARATOR . $newMessageId
    );

    return $targetDir . DIRECTORY_SEPARATOR . basename($storedNamePlain);
}

function createAttachmentMetadataPlaceholder(
    mysqli $db,
    array $plain,
    int $oldAttachmentId,
    int $newMessageId,
    int $uploadedByActorId,
    string $createdAt,
    string $mapKey,
    ?string $sourcePath = null
): int {
    $storedPlain = trim((string)($plain['stored_name_plain'] ?? ''));
    if ($storedPlain === '' && $sourcePath !== null) {
        $storedPlain = basename($sourcePath);
    }
    if ($storedPlain === '') {
        $storedPlain = 'attachment_' . $oldAttachmentId . '.crypto';
    }

    $targetPath = buildNewAttachmentTargetPath($newMessageId, $storedPlain);

    $origPlain = trim((string)($plain['original_name_plain'] ?? ''));
    if ($origPlain === '') {
        $origPlain = preg_replace('/\.crypto$/i', '', basename($storedPlain));
    }

    $mimeType = guessMimeType($origPlain, $sourcePath);
    $fileSize = ($sourcePath !== null && is_file($sourcePath)) ? (int)(@filesize($sourcePath) ?: 0) : 0;

    $enc = reEncryptAttachmentFields($db, $origPlain, basename($targetPath), $targetPath);

    $newAttachmentId = insertAttachmentMetadata($db, [
        'id_message'          => $newMessageId,
        'id_draft'            => null,
        'uploaded_by_user_id' => $uploadedByActorId,
        'original_name'       => $enc['original_name'],
        'stored_name'         => $enc['stored_name'],
        'mime_type'           => $mimeType,
        'file_size'           => $fileSize,
        'storage_path'        => $enc['storage_path'],
        'created_at'          => $createdAt,
        'vector_id'           => $enc['vector_id'],
    ]);

    mapPut($db, 'dap11_attachments', $oldAttachmentId, $mapKey, $newAttachmentId, 'attachment');

    return $newAttachmentId;
}

function resolveOldAttachmentSourcePath(array $plain): ?string
{
    $scriptRoot = __DIR__;
    $nomeReal   = (string)($plain['nome_real_plain'] ?? '');
    $storedName = (string)($plain['stored_name_plain'] ?? '');
    $oldRootId  = (int)($plain['id_message'] ?? 0);
    $oldReplyId = (int)($plain['id_message_reply'] ?? 0);

    $candidates = [];

    if ($nomeReal !== '') {
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $nomeReal) || str_starts_with($nomeReal, '/') || str_starts_with($nomeReal, '\\')) {
            $candidates[] = normalizePath($nomeReal);
        } else {
            $relative = normalizePath($nomeReal);
            $candidates[] = normalizePath($scriptRoot . DIRECTORY_SEPARATOR . $relative);
            $candidates[] = normalizePath($scriptRoot . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . $relative);
        }
    }

    if ($storedName !== '') {
        if ($oldRootId > 0) {
            $candidates[] = normalizePath($scriptRoot . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . $oldRootId . DIRECTORY_SEPARATOR . $storedName);
        }
        if ($oldReplyId > 0) {
            $candidates[] = normalizePath($scriptRoot . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . $oldReplyId . DIRECTORY_SEPARATOR . $storedName);
        }
    }

    $candidates = array_values(array_unique(array_filter($candidates, static fn ($v) => $v !== '')));

    debugBlock('ALLEGATO SOURCE CANDIDATES', [
        'stored_name_plain' => $storedName,
        'candidates'        => $candidates,
    ]);

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function migrateOneAttachment(
    mysqli $db,
    int $oldAttachmentId,
    int $newMessageId,
    int $uploadedByActorId,
    string $createdAt,
    string $mapKey
): ?int {
    if (($existing = mapGet($db, 'dap11_attachments', $oldAttachmentId, $mapKey)) !== null) {
        return $existing;
    }

    $plain = resolveOldAttachmentPlainValues($db, $oldAttachmentId);
    if (!$plain) {
        throw new RuntimeException("Allegato legacy {$oldAttachmentId} non trovato");
    }

    $sourcePath = resolveOldAttachmentSourcePath($plain);
    if ($sourcePath === null) {
        if (empty($GLOBALS['SKIP_MISSING_ATTACHMENTS'])) {
            throw new RuntimeException("File allegato legacy {$oldAttachmentId} non trovato su disco");
        }

        $newAttachmentId = createAttachmentMetadataPlaceholder(
            $db,
            $plain,
            $oldAttachmentId,
            $newMessageId,
            $uploadedByActorId,
            $createdAt,
            $mapKey,
            null
        );

        echo "   - warning allegato {$oldAttachmentId}: file non trovato su disco, creo mapping placeholder per fase locale\n";
        debugBlock('ALLEGATO PLACEHOLDER', [
            'old_attachment_id' => $oldAttachmentId,
            'new_attachment_id' => $newAttachmentId,
            'new_message_id'    => $newMessageId,
            'old_message_id'    => $plain['id_message'] ?? null,
            'old_reply_id'      => $plain['id_message_reply'] ?? null,
            'nome_real_plain'   => $plain['nome_real_plain'] ?? '',
            'nome_vis_plain'    => $plain['original_name_plain'] ?? '',
        ]);

        return $newAttachmentId;
    }

    $storedPlain = trim((string)$plain['stored_name_plain']);
    if ($storedPlain === '') {
        $storedPlain = basename($sourcePath);
    }
    $targetPath = buildNewAttachmentTargetPath($newMessageId, $storedPlain);
    $targetDir = dirname($targetPath);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
        throw new RuntimeException("Impossibile creare la cartella allegati {$targetDir}");
    }

    if (!@copy($sourcePath, $targetPath)) {
        throw new RuntimeException("Copia allegato fallita da {$sourcePath} a {$targetPath}");
    }

    $origPlain = trim((string)$plain['original_name_plain']);
    if ($origPlain === '') {
        $origPlain = preg_replace('/\.crypto$/i', '', basename($storedPlain));
    }

    debugBlock('ALLEGATO MIGRATION COPY', [
        'old_attachment_id' => $oldAttachmentId,
        'source_path'       => $sourcePath,
        'target_path'       => $targetPath,
    ]);

    $newAttachmentId = createAttachmentMetadataPlaceholder(
        $db,
        $plain,
        $oldAttachmentId,
        $newMessageId,
        $uploadedByActorId,
        $createdAt,
        $mapKey,
        $targetPath
    );

    return $newAttachmentId;
}

function migrateAttachmentsForLegacyRow(
    mysqli $db,
    string $attachmentsTable,
    string $field,
    int $legacyMessageId,
    int $newMessageId,
    int $uploadedByActorId,
    string $createdAt,
    string $mapKeyPrefix
): void {
    $stmt = $db->prepare("
        SELECT id_attachments
        FROM {$attachmentsTable}
        WHERE {$field} = ?
        ORDER BY id_attachments ASC
    ");
    $stmt->bind_param("i", $legacyMessageId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $oldAttachmentId = (int)$row['id_attachments'];
        migrateOneAttachment(
            $db,
            $oldAttachmentId,
            $newMessageId,
            $uploadedByActorId,
            $createdAt,
            $mapKeyPrefix
        );
    }

    $stmt->close();
}

// ========================
// AVVIO MIGRAZIONE
// ========================
ensureMigrationTables($db);

$doctorIds = normalizeDoctorIds($FILTER_ID_PERSONALE, $_SERVER['argv'] ?? []);
if (!$doctorIds) {
    throw new RuntimeException('Nessun dottore configurato per la migrazione');
}

$scope = loadMigrationScope($db, $doctorIds);
$doctorKey = implode(',', $doctorIds);
$checkpointKey = 'last_root_id:' . md5($doctorKey);
$lastRoot = (int)getCheckpoint($db, $checkpointKey, '0');

echo "== MIGRAZIONE LEGACY -> NUOVO PORTALE ==\n";
echo "Dottori scope       : {$doctorKey}\n";
echo "Checkpoint key      : {$checkpointKey}\n";
echo "Riparto da root >   : {$lastRoot}\n";
echo "DRY_RUN             : " . ($DRY_RUN ? 'true' : 'false') . "\n";
echo "DEBUG_ATTACHMENTS   : " . ($DEBUG_ATTACHMENTS ? 'true' : 'false') . "\n";
echo "Pazienti scope      : " . count($scope['patient_client_ids']) . "\n";
echo "Segreterie scope    : " . count($scope['seg_personale_ids']) . "\n";
echo "Infermieri scope    : " . count($scope['inf_personale_ids']) . "\n";

while (true) {
    echo "\nCerco batch legacy...\n";

    $sqlBatch = "
        SELECT m.*
        FROM {$T_OLD_MSG} m
        WHERE m.id_message > ?
        ORDER BY m.id_message ASC
        LIMIT {$BATCH_SIZE}
    ";

    $stmt = $db->prepare($sqlBatch);
    $stmt->bind_param("i", $lastRoot);
    $stmt->execute();
    $res = $stmt->get_result();

    echo "Root batch letti: " . $res->num_rows . "\n";

    if ($res->num_rows === 0) {
        $stmt->close();
        echo "Nessun altro record.\n";
        break;
    }

    if (!$DRY_RUN) {
        $db->begin_transaction();
    }

    try {
        while ($root = $res->fetch_assoc()) {
            $oldRootId = (int)$root['id_message'];
            $lastRoot = $oldRootId;

            if (!rowBelongsToScope($db, $root, $scope)) {
                echo " - skip root {$oldRootId}: fuori scope\n";
                continue;
            }

            if ((int)($root['draft'] ?? 0) === 1) {
                echo " - skip root {$oldRootId}: draft\n";
                continue;
            }

            if (mapGet($db, $T_OLD_MSG, $oldRootId, 'MESSAGE:CANON') !== null) {
                echo " - skip root {$oldRootId}: già migrato\n";
                continue;
            }

            $doctorContextId = resolveDoctorContextIdForRow($db, $root, $scope);
            if ($doctorContextId <= 0) {
                echo " - skip root {$oldRootId}: dottore contesto non risolto\n";
                continue;
            }

            $rootSender = resolveLegacyActorId($db, $root, 'sender', $scope);
            if ($rootSender === null) {
                echo " - skip root {$oldRootId}: sender non risolto\n";
                continue;
            }

            $rootRecipient = resolveRecipientDescriptor($db, $root, $scope, $doctorContextId);
            if ($rootRecipient === null) {
                echo " - skip root {$oldRootId}: destinatario non risolto\n";
                continue;
            }

            $createdAt = (string)($root['dataora'] ?? nowUtc());
            $rootHandled = isOldThreadHandled($root);
            $rootBody = transformMessageBodyForNewDb($root, $REENCRYPT_MESSAGE_BODY);

            echo "Processo root {$oldRootId} -> dottore {$doctorContextId}\n";

            if ($DRY_RUN) {
                echo "   DRY root sender={$rootSender} recipient=" . json_encode($rootRecipient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                continue;
            }

            $threadId = insertThread($db, $rootSender, $createdAt);

            $rootMsgId = insertMessage($db, [
                'id_thread'           => $threadId,
                'message_type'        => isForward($root) ? 'FORWARD' : 'ROOT',
                'status'              => 'SENT',
                'root_message_id'     => null,
                'parent_message_id'   => null,
                'reply_to_user_id'    => null,
                'sender_user_id'      => $rootSender,
                'recipient_type'      => 'USER',
                'recipient_user_id'   => $rootRecipient['user_id'],
                'recipient_role'      => $rootRecipient['role'],
                'body_cipher_hex'     => $rootBody['body_cipher_hex'],
                'vector_id'           => $rootBody['vector_id'],
                'root_author_user_id' => $rootSender,
                'created_at'          => $createdAt,
            ]);

            updateThreadRoot($db, $threadId, $rootMsgId, $createdAt);
            mapPut($db, $T_OLD_MSG, $oldRootId, 'THREAD:CANON', $threadId, 'thread');
            mapPut($db, $T_OLD_MSG, $oldRootId, 'MESSAGE:CANON', $rootMsgId, 'root');

            applyLegacyFlags(
                $db,
                $root,
                $rootMsgId,
                $rootSender,
                $rootRecipient,
                $doctorContextId,
                $rootHandled,
                $createdAt,
                $scope
            );

            applyLegacyDeleteFlags(
                $db,
                $T_OLD_DEL,
                $oldRootId,
                $rootMsgId,
                $createdAt,
                $doctorContextId,
                $scope
            );

            migrateAttachmentsForLegacyRow(
                $db,
                $T_OLD_ATT,
                'id_message',
                $oldRootId,
                $rootMsgId,
                $rootSender,
                $createdAt,
                'MESSAGE:CANON'
            );

            $replyStmt = $db->prepare("
                SELECT *
                FROM {$T_OLD_REPLY}
                WHERE id_message_ini = ?
                  AND COALESCE(draft, 0) = 0
                ORDER BY dataora ASC, id_message ASC
            ");
            $replyStmt->bind_param("i", $oldRootId);
            $replyStmt->execute();
            $replyRes = $replyStmt->get_result();

            $parentMessageId = $rootMsgId;
            $lastThreadAt = $createdAt;

            while ($reply = $replyRes->fetch_assoc()) {
                $oldReplyId = (int)$reply['id_message'];

                $replyDoctorContextId = resolveDoctorContextIdForRow($db, $reply, $scope);
                if ($replyDoctorContextId <= 0) {
                    $replyDoctorContextId = $doctorContextId;
                }

                $replySender = resolveLegacyActorId($db, $reply, 'sender', $scope);
                if ($replySender === null) {
                    echo "   - skip reply {$oldReplyId}: sender non risolto\n";
                    continue;
                }

                $replyRecipient = resolveRecipientDescriptor($db, $reply, $scope, $replyDoctorContextId);
                if ($replyRecipient === null) {
                    echo "   - skip reply {$oldReplyId}: destinatario non risolto\n";
                    continue;
                }

                $replyAt = (string)($reply['dataora'] ?? $createdAt);
                $replyBody = transformMessageBodyForNewDb($reply, $REENCRYPT_MESSAGE_BODY);

                $newReplyId = insertMessage($db, [
                    'id_thread'           => $threadId,
                    'message_type'        => isForward($reply) ? 'FORWARD' : 'REPLY',
                    'status'              => 'SENT',
                    'root_message_id'     => $rootMsgId,
                    'parent_message_id'   => $parentMessageId,
                    'reply_to_user_id'    => null,
                    'sender_user_id'      => $replySender,
                    'recipient_type'      => 'USER',
                    'recipient_user_id'   => $replyRecipient['user_id'],
                    'recipient_role'      => $replyRecipient['role'],
                    'body_cipher_hex'     => $replyBody['body_cipher_hex'],
                    'vector_id'           => $replyBody['vector_id'],
                    'root_author_user_id' => $rootSender,
                    'created_at'          => $replyAt,
                ]);

                mapPut($db, $T_OLD_REPLY, $oldReplyId, 'MESSAGE:CANON', $newReplyId, 'reply');

                applyLegacyFlags(
                    $db,
                    $reply,
                    $newReplyId,
                    $replySender,
                    $replyRecipient,
                    $replyDoctorContextId,
                    $rootHandled,
                    $replyAt,
                    $scope
                );

                applyLegacyDeleteFlags(
                    $db,
                    $T_OLD_RDEL,
                    $oldReplyId,
                    $newReplyId,
                    $replyAt,
                    $replyDoctorContextId,
                    $scope
                );

                migrateAttachmentsForLegacyRow(
                    $db,
                    $T_OLD_ATT,
                    'id_message_reply',
                    $oldReplyId,
                    $newReplyId,
                    $replySender,
                    $replyAt,
                    'MESSAGE:CANON'
                );

                $parentMessageId = $newReplyId;
                $lastThreadAt = $replyAt;
            }

            $replyStmt->close();
            touchThread($db, $threadId, $lastThreadAt);
        }

        $stmt->close();

        if (!$DRY_RUN) {
            setCheckpoint($db, $checkpointKey, (string)$lastRoot);
            $db->commit();
        }

        echo "Batch OK. checkpoint={$lastRoot}\n";
    } catch (Throwable $e) {
        $stmt->close();

        if (!$DRY_RUN) {
            $db->rollback();
        }

        echo "\nERRORE: " . $e->getMessage() . "\n";
        throw $e;
    }
}

echo "\nFINE. Ultimo root migrato: {$lastRoot}\n";
