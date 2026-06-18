<?php
set_time_limit(0);
ini_set('max_execution_time', '0');
ignore_user_abort(true);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * MIGRAZIONE MESSAGGI LEGACY -> NUOVO PORTALE
 *
 * Copre:
 * - dottore <-> paziente
 * - dottore <-> segreteria
 * - dottore <-> infermiere
 * - paziente <-> segreteria
 * - paziente <-> infermiere
 *
 * Limitazione:
 * - solo per la dottoressa filtrata ($FILTER_ID_PERSONALE)
 * - solo pazienti della dottoressa filtrata
 * - solo staff collegato alla dottoressa filtrata
 *
 * NOTE:
 * - id legacy client/personale vengono convertiti nei corrispondenti dap01_users.id_user
 * - i messaggi verso ROLE generano anche flags per gli utenti reali del ruolo
 * - la mappa di migrazione supporta più target per lo stesso old_id
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
$FILTER_ID_PERSONALE    = 62;
$REENCRYPT_MESSAGE_BODY = false;
$DEBUG_ATTACHMENTS      = true;

// Tabelle vecchie
$T_OLD_MSG      = 'dap10_message';
$T_OLD_REPLY    = 'dap10_message_reply';
$T_OLD_DEL      = 'dap10_message_delete';
$T_OLD_RDEL     = 'dap10_message_reply_delete';
$T_OLD_ATT      = 'dap11_attachments';
$T_CLIENTS      = 'dap02_clients';
$T_PERSONALE    = 'dap03_personale';
$T_SEG_DOT      = 'dap14_seg_dot';
$T_INF_DOT      = 'dap15_inf_dot';

// Tabelle nuove
$T_NEW_THREADS  = 'msg_threads';
$T_NEW_MSG      = 'msg_messages';
$T_NEW_FLAGS    = 'msg_user_flags';
$T_NEW_ATT      = 'msg_attachments';

// ========================
// CONNESSIONE
// ========================
$db = new mysqli($DB['host'], $DB['user'], $DB['pass'], $DB['db']);
$db->set_charset('utf8');

$db->query("SET NAMES utf8");
$db->query("SET lc_time_names = 'it_IT'");
$db->query("SET SESSION block_encryption_mode = 'aes-256-cbc'");
$db->query("SET @key_str = SHA2('PartitaIVA22', 512)");
$db->query("SET @init_vector = RANDOM_BYTES(16)");

// ========================
// TABELLE SUPPORTO
// ========================
// Mapping multiplo: stessa root legacy può generare più record nuovi
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

// ========================
// HELPERS GENERICI
// ========================
function nowUtc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function getCheckpoint(mysqli $db, string $k, string $default = '0'): string
{
    $stmt = $db->prepare("SELECT v FROM msg_migration_checkpoint WHERE k=?");
    $stmt->bind_param("s", $k);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (string)$row['v'] : $default;
}

function setCheckpoint(mysqli $db, string $k, string $v): void
{
    $stmt = $db->prepare("
        INSERT INTO msg_migration_checkpoint(k,v)
        VALUES(?,?)
        ON DUPLICATE KEY UPDATE v=VALUES(v)
    ");
    $stmt->bind_param("ss", $k, $v);
    $stmt->execute();
    $stmt->close();
}

function mapPut(mysqli $db, string $oldTable, int $oldId, string $mapKey, int $newId, ?string $extra = null): void
{
    $stmt = $db->prepare("
        INSERT INTO msg_migration_map(old_table, old_id, map_key, new_id, extra)
        VALUES(?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            new_id=VALUES(new_id),
            extra=VALUES(extra)
    ");
    $stmt->bind_param("sssis", $oldTable, $oldId, $mapKey, $newId, $extra);
    $stmt->execute();
    $stmt->close();
}

function normalizePath(string $p): string
{
    $p = trim($p);
    $p = str_replace('\\', '/', $p);
    $p = preg_replace('#/+#', '/', $p);
    return rtrim($p, '/');
}

function splitDirAndFile(string $maybePath): array
{
    $p = normalizePath($maybePath);

    if ($p === '' || strpos($p, '/') === false) {
        return ['', $p];
    }

    $dir = dirname($p);
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }

    $file = basename($p);

    return [normalizePath($dir), $file];
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

// ========================
// CACHE RISORSE DOTTORESSA
// ========================
function loadDoctorScope(mysqli $db, int $doctorId): array
{
    $scope = [
        'doctor_id_personale' => $doctorId,
        'doctor_user_id'      => null,
        'patient_client_ids'  => [],
        'patient_user_ids'    => [],
        'seg_personale_ids'   => [],
        'seg_user_ids'        => [],
        'inf_personale_ids'   => [],
        'inf_user_ids'        => [],
    ];

    // user del dottore
    $stmt = $db->prepare("SELECT id_user FROM dap03_personale WHERE id_personale = ? LIMIT 1");
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $scope['doctor_user_id'] = $row ? (int)$row['id_user'] : null;

    // pazienti della dottoressa
    $res = $db->query("
        SELECT id_client, id_user
        FROM dap02_clients
        WHERE id_personale = " . (int)$doctorId . "
    ");
    while ($r = $res->fetch_assoc()) {
        $scope['patient_client_ids'][(int)$r['id_client']] = (int)$r['id_client'];
        if (!empty($r['id_user'])) {
            $scope['patient_user_ids'][(int)$r['id_user']] = (int)$r['id_user'];
        }
    }

    // segreterie collegate
    $res = $db->query("
        SELECT p.id_personale, p.id_user
        FROM dap14_seg_dot sd
        INNER JOIN dap03_personale p ON p.id_personale = sd.id_seg
        WHERE sd.id_dot = " . (int)$doctorId . "
    ");
    while ($r = $res->fetch_assoc()) {
        $scope['seg_personale_ids'][(int)$r['id_personale']] = (int)$r['id_personale'];
        if (!empty($r['id_user'])) {
            $scope['seg_user_ids'][(int)$r['id_user']] = (int)$r['id_user'];
        }
    }

    // infermieri collegati
    $res = $db->query("
        SELECT p.id_personale, p.id_user
        FROM dap15_inf_dot idt
        INNER JOIN dap03_personale p ON p.id_personale = idt.id_inf
        WHERE idt.id_dot = " . (int)$doctorId . "
    ");
    while ($r = $res->fetch_assoc()) {
        $scope['inf_personale_ids'][(int)$r['id_personale']] = (int)$r['id_personale'];
        if (!empty($r['id_user'])) {
            $scope['inf_user_ids'][(int)$r['id_user']] = (int)$r['id_user'];
        }
    }

    return $scope;
}

$scope = loadDoctorScope($db, $FILTER_ID_PERSONALE);

// ========================
// IDENTIFICAZIONE ATTORI LEGACY
// ========================
function legacyClientToUserId(mysqli $db, int $clientId): ?int
{
    static $cache = [];

    if ($clientId <= 0) return null;
    if (array_key_exists($clientId, $cache)) return $cache[$clientId];

    $stmt = $db->prepare("SELECT id_user FROM dap02_clients WHERE id_client = ? LIMIT 1");
    $stmt->bind_param("i", $clientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cache[$clientId] = $row && !empty($row['id_user']) ? (int)$row['id_user'] : null;
    return $cache[$clientId];
}

function legacyPersonaleToUserId(mysqli $db, int $personaleId): ?int
{
    static $cache = [];

    if ($personaleId <= 0) return null;
    if (array_key_exists($personaleId, $cache)) return $cache[$personaleId];

    $stmt = $db->prepare("SELECT id_user FROM dap03_personale WHERE id_personale = ? LIMIT 1");
    $stmt->bind_param("i", $personaleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cache[$personaleId] = $row && !empty($row['id_user']) ? (int)$row['id_user'] : null;
    return $cache[$personaleId];
}

function detectLegacyActorType(array $row, string $side, array $scope): string
{
    $id = (int)($row[$side === 'sender' ? 'id_mitt' : 'id_dest'] ?? 0);
    $mittFlag = strtoupper(trim((string)($row['mitt'] ?? '')));
    $destFlag = strtoupper(trim((string)($row['dest'] ?? '')));
    $flag = $side === 'sender' ? $mittFlag : $destFlag;

    if ($id <= 0) {
        return 'UNKNOWN';
    }

    if (isset($scope['patient_client_ids'][$id])) {
        return 'PATIENT';
    }
    if ((int)($row['dot_seg'] ?? 0) > 0 || isset($scope['seg_personale_ids'][$id])) {
        if (isset($scope['seg_personale_ids'][$id])) {
            return 'SEGRETERIA';
        }
    }
    if ((int)($row['dot_inf'] ?? 0) > 0 || isset($scope['inf_personale_ids'][$id])) {
        if (isset($scope['inf_personale_ids'][$id])) {
            return 'INFERMIERE';
        }
    }
    if ($id === (int)$scope['doctor_id_personale']) {
        return 'DOCTOR';
    }

    // fallback su mitt/dest
    if ($flag === 'P' || $flag === 'C') return 'PATIENT';
    if ($flag === 'S') return 'SEGRETERIA';
    if ($flag === 'I') return 'INFERMIERE';
    if ($flag === 'D' || $flag === 'M') return 'DOCTOR';

    // fallback ultimo: se esiste in personale
    if (legacyPersonaleToUserId($GLOBALS['db'], $id) !== null) {
        if (isset($scope['seg_personale_ids'][$id])) return 'SEGRETERIA';
        if (isset($scope['inf_personale_ids'][$id])) return 'INFERMIERE';
        if ($id === (int)$scope['doctor_id_personale']) return 'DOCTOR';
        return 'PERSONALE_OTHER';
    }

    if (legacyClientToUserId($GLOBALS['db'], $id) !== null) {
        return 'PATIENT';
    }

    return 'UNKNOWN';
}

function resolveLegacyActorToNewUserId(mysqli $db, array $row, string $side, array $scope): ?int
{
    $id = (int)($row[$side === 'sender' ? 'id_mitt' : 'id_dest'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $type = detectLegacyActorType($row, $side, $scope);

    switch ($type) {
        case 'PATIENT':
            return legacyClientToUserId($db, $id);

        case 'DOCTOR':
        case 'SEGRETERIA':
        case 'INFERMIERE':
        case 'PERSONALE_OTHER':
            return legacyPersonaleToUserId($db, $id);

        default:
            // prova client
            $u = legacyClientToUserId($db, $id);
            if ($u !== null) return $u;

            // prova personale
            $u = legacyPersonaleToUserId($db, $id);
            if ($u !== null) return $u;

            return null;
    }
}

function getRoleUsersForDoctor(array $scope, string $role): array
{
    if ($role === 'SEGRETERIA') {
        return array_values($scope['seg_user_ids']);
    }
    if ($role === 'INFERMIERE') {
        return array_values($scope['inf_user_ids']);
    }
    return [];
}

function computeRootAuthorUserId(mysqli $db, array $m, array $scope): int
{
    $dotSeg = (int)($m['dot_seg'] ?? 0);
    $dotInf = (int)($m['dot_inf'] ?? 0);

    if ($dotSeg > 0) {
        $u = legacyPersonaleToUserId($db, $dotSeg);
        if ($u !== null) return $u;
    }

    if ($dotInf > 0) {
        $u = legacyPersonaleToUserId($db, $dotInf);
        if ($u !== null) return $u;
    }

    if (!empty($scope['doctor_user_id'])) {
        return (int)$scope['doctor_user_id'];
    }

    $u = resolveLegacyActorToNewUserId($db, $m, 'sender', $scope);
    if ($u !== null) return $u;

    throw new RuntimeException("Impossibile determinare root_author_user_id per old id_message " . (int)$m['id_message']);
}

function isForward(array $m): bool
{
    return trim((string)($m['inoltrato'] ?? '')) !== '';
}

function isOldMessageHandled(array $row): bool
{
    return (int)($row['gestita'] ?? 0) === 1;
}

// ========================
// LOGICA DESTINATARI
// ========================
function computeRecipients(mysqli $db, array $m, array $scope): array
{
    $recipients = [];

    $seg = (int)($m['seg_flag'] ?? 0) === 1;
    $inf = (int)($m['inf_flag'] ?? 0) === 1;

    if ($seg) {
        $recipients[] = [
            'type' => 'ROLE',
            'user_id' => null,
            'role' => 'SEGRETERIA',
            'flag_users' => getRoleUsersForDoctor($scope, 'SEGRETERIA'),
            'map_key' => 'ROLE:SEGRETERIA'
        ];
    }

    if ($inf) {
        $recipients[] = [
            'type' => 'ROLE',
            'user_id' => null,
            'role' => 'INFERMIERE',
            'flag_users' => getRoleUsersForDoctor($scope, 'INFERMIERE'),
            'map_key' => 'ROLE:INFERMIERE'
        ];
    }

    if (!$seg && !$inf) {
        $destUserId = resolveLegacyActorToNewUserId($db, $m, 'dest', $scope);

        if ($destUserId !== null) {
            $recipients[] = [
                'type' => 'USER',
                'user_id' => $destUserId,
                'role' => null,
                'flag_users' => [$destUserId],
                'map_key' => 'USER:' . $destUserId
            ];
        }
    }

    return $recipients;
}

// ========================
// BODY MESSAGE
// ========================
function transformMessageBodyForNewDb(array $row, bool $reencrypt): array
{
    $oldBody     = (string)($row['testo'] ?? '');
    $oldVectorId = $row['vector_id'] ?? null;

    if (!$reencrypt) {
        return [
            'body_cipher_hex' => $oldBody,
            'vector_id'       => $oldVectorId ?: randomVectorId(16),
        ];
    }

    return [
        'body_cipher_hex' => $oldBody,
        'vector_id'       => $oldVectorId ?: randomVectorId(16),
    ];
}

// ========================
// ALLEGATI VECCHI: DECRYPT METADATI
// ========================
function decryptOldAttachmentRow(mysqli $db, int $oldAttachmentId): ?array
{
    $sql = "
        SELECT
            a.id_attachments,
            a.id_message,
            a.id_message_reply,
            a.vector_id,
            CAST(AES_DECRYPT(UNHEX(a.nome_real), @key_str, a.vector_id) AS CHAR(2500) CHARACTER SET utf8) AS nome_real_plain,
            CAST(AES_DECRYPT(UNHEX(a.nome_vis),  @key_str, a.vector_id) AS CHAR(2500) CHARACTER SET utf8) AS nome_vis_plain,
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
    $nomeVisPlain  = (string)($row['nome_vis_plain'] ?? '');

    [$storagePathPlain, $storedNamePlain] = splitDirAndFile($nomeRealPlain);

    $result = [
        'id_attachments'      => (int)$row['id_attachments'],
        'id_message'          => $row['id_message'] !== null ? (int)$row['id_message'] : null,
        'id_message_reply'    => $row['id_message_reply'] !== null ? (int)$row['id_message_reply'] : null,
        'old_vector_id'       => $row['vector_id'],
        'old_vector_id_hex'   => is_string($row['vector_id']) ? bin2hex($row['vector_id']) : null,
        'nome_real_raw'       => (string)($row['nome_real_raw'] ?? ''),
        'nome_vis_raw'        => (string)($row['nome_vis_raw'] ?? ''),
        'original_name_plain' => $nomeVisPlain,
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

// ========================
// CRIPTAZIONE METADATI ALLEGATI NUOVI
// ========================
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
        'vector_id'      => $newVector,
        'vector_id_hex'  => bin2hex($newVector),
        'original_name'  => encryptStringForNewDb($db, $originalNamePlain, $newVector),
        'stored_name'    => encryptStringForNewDb($db, $storedNamePlain, $newVector),
        'storage_path'   => encryptStringForNewDb($db, $storagePathPlain, $newVector),
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

// ========================
// INSERT HELPERS NUOVO DB
// ========================
function insertThread(mysqli $db, int $rootAuthorUserId, string $createdAt): int
{
    $stmt = $db->prepare("
        INSERT INTO msg_threads(root_message_id, root_author_user_id, created_at, updated_at)
        VALUES(NULL, ?, ?, ?)
    ");
    $stmt->bind_param("iss", $rootAuthorUserId, $createdAt, $createdAt);
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
    $stmt = $db->prepare("UPDATE msg_threads SET root_message_id=?, updated_at=? WHERE id_thread=?");
    $stmt->bind_param("isi", $rootMessageId, $updatedAt, $threadId);
    $stmt->execute();
    $stmt->close();
}

function upsertFlag(mysqli $db, int $messageId, int $userId, array $f): void
{
    if ($userId <= 0) {
        return;
    }

    $is_deleted = (int)($f['is_deleted'] ?? 0);
    $deleted_at = $f['deleted_at'] ?? null;
    $is_read    = (int)($f['is_read'] ?? 0);
    $read_at    = $f['read_at'] ?? null;
    $is_handled = (int)($f['is_handled'] ?? 0);
    $handled_at = $f['handled_at'] ?? null;

    $stmt = $db->prepare("
        INSERT INTO msg_user_flags(
            id_message, user_id, is_deleted, deleted_at,
            is_read, read_at, is_handled, handled_at
        ) VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            is_deleted=VALUES(is_deleted),
            deleted_at=VALUES(deleted_at),
            is_read=VALUES(is_read),
            read_at=VALUES(read_at),
            is_handled=VALUES(is_handled),
            handled_at=VALUES(handled_at)
    ");

    $stmt->bind_param(
        "iiisisis",
        $messageId,
        $userId,
        $is_deleted,
        $deleted_at,
        $is_read,
        $read_at,
        $is_handled,
        $handled_at
    );
    $stmt->execute();
    $stmt->close();
}

function insertAttachmentMetadata(mysqli $db, array $a): int
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
        $a['id_message'],
        $a['id_draft'],
        $a['uploaded_by_user_id'],
        $a['original_name'],
        $a['stored_name'],
        $a['mime_type'],
        $a['file_size'],
        $a['storage_path'],
        $a['created_at'],
        $a['vector_id']
    );

    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();

    return $id;
}

// ========================
// DELETE LEGACY -> USER_ID NUOVO
// ========================
function resolveDeleteLegacyUserToNewUserId(mysqli $db, int $oldUserId): ?int
{
    $u = legacyClientToUserId($db, $oldUserId);
    if ($u !== null) return $u;

    $u = legacyPersonaleToUserId($db, $oldUserId);
    if ($u !== null) return $u;

    return null;
}

// ========================
// MIGRAZIONE ALLEGATO SOLO DB
// ========================
function migrateOneAttachmentMetadata(
    mysqli $db,
    int $oldAttachmentId,
    int $newMessageId,
    int $uploadedByUserId,
    string $createdAt,
    string $mapKey
): int {
    $plain = resolveOldAttachmentPlainValues($db, $oldAttachmentId);
    if (!$plain) {
        throw new RuntimeException("Allegato vecchio {$oldAttachmentId} non trovato");
    }

    $origPlain   = (string)$plain['original_name_plain'];
    $storedPlain = (string)$plain['stored_name_plain'];
    $pathPlain   = (string)$plain['storage_path_plain'];

    if (trim($storedPlain) === '') {
        throw new RuntimeException("stored_name_plain vuoto per allegato {$oldAttachmentId}");
    }

    $futureStoredName = $storedPlain;
    if (!preg_match('/\.crypto$/i', $futureStoredName)) {
        $futureStoredName .= '.crypto';
    }

    $futureStoragePath = '';

    debugBlock('ALLEGATO FASE1 SOLO DB', [
        'old_attachment_id'    => $oldAttachmentId,
        'new_message_id'       => $newMessageId,
        'original_name_plain'  => $origPlain,
        'stored_name_plain'    => $futureStoredName,
        'storage_path_plain'   => $futureStoragePath,
    ]);

    $enc = reEncryptAttachmentFields($db, $origPlain, $futureStoredName, $futureStoragePath);

    $newAttachmentId = insertAttachmentMetadata($db, [
        'id_message'          => $newMessageId,
        'id_draft'            => null,
        'uploaded_by_user_id' => $uploadedByUserId,
        'original_name'       => $enc['original_name'],
        'stored_name'         => $enc['stored_name'],
        'mime_type'           => 'application/octet-stream',
        'file_size'           => 0,
        'storage_path'        => $enc['storage_path'],
        'created_at'          => $createdAt,
        'vector_id'           => $enc['vector_id'],
    ]);

    mapPut($db, 'dap11_attachments', $oldAttachmentId, $mapKey, $newAttachmentId, 'attachment');

    debugBlock('ALLEGATO FASE1 DB INSERITO', [
        'old_attachment_id' => $oldAttachmentId,
        'new_attachment_id' => $newAttachmentId,
        'vector_id_hex'     => $enc['vector_id_hex'],
    ]);

    return $newAttachmentId;
}

// ========================
// QUALIFICA SE IL MESSAGGIO RIENTRA NELLO SCOPE
// ========================
function rowBelongsToDoctorScope(mysqli $db, array $row, array $scope): bool
{
    $idMitt = (int)($row['id_mitt'] ?? 0);
    $idDest = (int)($row['id_dest'] ?? 0);

    $dotSeg = (int)($row['dot_seg'] ?? 0);
    $dotInf = (int)($row['dot_inf'] ?? 0);

    if ($dotSeg === (int)$scope['doctor_id_personale'] || $dotInf === (int)$scope['doctor_id_personale']) {
        return true;
    }

    if ($idMitt === (int)$scope['doctor_id_personale'] || $idDest === (int)$scope['doctor_id_personale']) {
        return true;
    }

    if (isset($scope['patient_client_ids'][$idMitt]) || isset($scope['patient_client_ids'][$idDest])) {
        return true;
    }

    if (isset($scope['seg_personale_ids'][$idMitt]) || isset($scope['seg_personale_ids'][$idDest])) {
        return true;
    }

    if (isset($scope['inf_personale_ids'][$idMitt]) || isset($scope['inf_personale_ids'][$idDest])) {
        return true;
    }

    // messaggi verso ruolo collegati alla dottoressa
    if ((int)($row['seg_flag'] ?? 0) === 1 && !empty($scope['seg_user_ids'])) {
        return true;
    }
    if ((int)($row['inf_flag'] ?? 0) === 1 && !empty($scope['inf_user_ids'])) {
        return true;
    }

    return false;
}

// ========================
// MIGRAZIONE PRINCIPALE
// ========================
echo "== MIGRAZIONE LEGACY -> NUOVO PORTALE ==\n";

$lastRoot = (int)getCheckpoint($db, 'last_root_id', '0');

echo "Riparto da root id_message > {$lastRoot}\n";
echo "DRY_RUN=" . ($DRY_RUN ? 'true' : 'false') . "\n";
echo "DEBUG_ATTACHMENTS=" . ($DEBUG_ATTACHMENTS ? 'true' : 'false') . "\n";
echo "DOTTORESSA FILTRATA=" . $FILTER_ID_PERSONALE . "\n";
echo "doctor_user_id=" . debugValue($scope['doctor_user_id']) . "\n";
echo "patients=" . count($scope['patient_client_ids']) . "\n";
echo "segreterie=" . count($scope['seg_personale_ids']) . "\n";
echo "infermieri=" . count($scope['inf_personale_ids']) . "\n";

while (true) {
    echo "Cerco batch...\n";

    // Qui recupero in modo largo e filtro in PHP.
    // È più robusto per non perderci casistiche legacy strane.
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
        while ($m = $res->fetch_assoc()) {
            $oldRootId = (int)$m['id_message'];
            $lastRoot  = $oldRootId;

            if (!rowBelongsToDoctorScope($db, $m, $scope)) {
                echo "\nSkip root {$oldRootId}: fuori scope\n";
                continue;
            }

            echo "\nProcesso root {$oldRootId}\n";

            if ((int)($m['draft'] ?? 0) === 1) {
                echo " - salto: draft root\n";
                continue;
            }

            $sender = resolveLegacyActorToNewUserId($db, $m, 'sender', $scope);
            if ($sender === null) {
                echo " - salto: sender non risolto\n";
                continue;
            }

            $rootAuthor  = computeRootAuthorUserId($db, $m, $scope);
            $createdAt   = (string)($m['dataora'] ?? nowUtc());
            $rootHandled = isOldMessageHandled($m);

            $msgBodyData = transformMessageBodyForNewDb($m, $REENCRYPT_MESSAGE_BODY);
            $bodyCipher  = $msgBodyData['body_cipher_hex'];
            $msgVector   = $msgBodyData['vector_id'];

            $recipients = computeRecipients($db, $m, $scope);
            if (!$recipients) {
                echo " - salto: nessun destinatario risolto\n";
                continue;
            }

            foreach ($recipients as $r) {
                if ($DRY_RUN) {
                    echo "   DRY root #{$oldRootId} -> {$r['map_key']}\n";
                    continue;
                }

                $threadId = insertThread($db, $rootAuthor, $createdAt);

                $rootMsgId = insertMessage($db, [
                    'id_thread'           => $threadId,
                    'message_type'        => isForward($m) ? 'FORWARD' : 'ROOT',
                    'status'              => 'SENT',
                    'root_message_id'     => null,
                    'parent_message_id'   => null,
                    'reply_to_user_id'    => null,
                    'sender_user_id'      => $sender,
                    'recipient_type'      => $r['type'],
                    'recipient_user_id'   => $r['type'] === 'USER' ? $r['user_id'] : null,
                    'recipient_role'      => $r['type'] === 'ROLE' ? $r['role'] : null,
                    'body_cipher_hex'     => $bodyCipher,
                    'vector_id'           => $msgVector,
                    'root_author_user_id' => $rootAuthor,
                    'created_at'          => $createdAt,
                ]);

                updateThreadRoot($db, $threadId, $rootMsgId, $createdAt);
                mapPut($db, $T_OLD_MSG, $oldRootId, $r['map_key'], $rootMsgId, $r['type'] . ':' . ($r['role'] ?? $r['user_id']));

                // sender
                upsertFlag($db, $rootMsgId, $sender, [
                    'is_read' => 1,
                    'read_at' => $createdAt
                ]);

                // destinatari reali
                foreach ($r['flag_users'] as $destUserId) {
                    if ($destUserId === $sender) {
                        continue;
                    }

                    $letto = (int)($m['letto'] ?? 0) === 1;

                    upsertFlag($db, $rootMsgId, $destUserId, [
                        'is_read'    => $letto ? 1 : 0,
                        'read_at'    => $letto ? $createdAt : null,
                        'is_handled' => $rootHandled ? 1 : 0,
                        'handled_at' => $rootHandled ? $createdAt : null,
                    ]);
                }

                // delete root legacy
                $qd = $db->prepare("
                    SELECT id_utente
                    FROM {$T_OLD_DEL}
                    WHERE id_message = ?
                      AND eliminato = 1
                ");
                $qd->bind_param("i", $oldRootId);
                $qd->execute();
                $rd = $qd->get_result();

                while ($d = $rd->fetch_assoc()) {
                    $u = resolveDeleteLegacyUserToNewUserId($db, (int)$d['id_utente']);
                    if ($u !== null) {
                        upsertFlag($db, $rootMsgId, $u, [
                            'is_deleted' => 1,
                            'deleted_at' => $createdAt
                        ]);
                    }
                }
                $qd->close();

                // allegati root
                $qa = $db->prepare("
                    SELECT id_attachments
                    FROM {$T_OLD_ATT}
                    WHERE id_message = ?
                    ORDER BY id_attachments ASC
                ");
                $qa->bind_param("i", $oldRootId);
                $qa->execute();
                $ra = $qa->get_result();

                while ($a = $ra->fetch_assoc()) {
                    $oldAttachmentId = (int)$a['id_attachments'];
                    migrateOneAttachmentMetadata(
                        $db,
                        $oldAttachmentId,
                        $rootMsgId,
                        $sender,
                        $createdAt,
                        'ROOT:' . $r['map_key']
                    );
                }
                $qa->close();

                // replies
                $qr = $db->prepare("
                    SELECT *
                    FROM {$T_OLD_REPLY}
                    WHERE id_message_ini = ?
                    ORDER BY id_message ASC
                ");
                $qr->bind_param("i", $oldRootId);
                $qr->execute();
                $rr = $qr->get_result();

                $parentId = $rootMsgId;

                while ($rep = $rr->fetch_assoc()) {
                    if ((int)($rep['draft'] ?? 0) === 1) {
                        continue;
                    }

                    $oldRepId   = (int)$rep['id_message'];
                    $repSender  = resolveLegacyActorToNewUserId($db, $rep, 'sender', $scope);

                    if ($repSender === null) {
                        echo "   - skip reply {$oldRepId}: sender non risolto\n";
                        continue;
                    }

                    $repCreated = (string)($rep['dataora'] ?? $createdAt);

                    $repBodyData = transformMessageBodyForNewDb($rep, $REENCRYPT_MESSAGE_BODY);
                    $repBody     = $repBodyData['body_cipher_hex'];
                    $repVector   = $repBodyData['vector_id'];

                    $newRepId = insertMessage($db, [
                        'id_thread'           => $threadId,
                        'message_type'        => isForward($rep) ? 'FORWARD' : 'REPLY',
                        'status'              => 'SENT',
                        'root_message_id'     => $rootMsgId,
                        'parent_message_id'   => $parentId,
                        'reply_to_user_id'    => null,
                        'sender_user_id'      => $repSender,
                        'recipient_type'      => $r['type'],
                        'recipient_user_id'   => $r['type'] === 'USER' ? $r['user_id'] : null,
                        'recipient_role'      => $r['type'] === 'ROLE' ? $r['role'] : null,
                        'body_cipher_hex'     => $repBody,
                        'vector_id'           => $repVector,
                        'root_author_user_id' => $rootAuthor,
                        'created_at'          => $repCreated,
                    ]);

                    mapPut($db, $T_OLD_REPLY, $oldRepId, $r['map_key'], $newRepId, 'reply');

                    // sender reply
                    upsertFlag($db, $newRepId, $repSender, [
                        'is_read' => 1,
                        'read_at' => $repCreated
                    ]);

                    foreach ($r['flag_users'] as $destUserId) {
                        if ($destUserId === $repSender) {
                            continue;
                        }

                        $letto = (int)($rep['letto'] ?? 0) === 1;

                        upsertFlag($db, $newRepId, $destUserId, [
                            'is_read'    => $letto ? 1 : 0,
                            'read_at'    => $letto ? $repCreated : null,
                            'is_handled' => $rootHandled ? 1 : 0,
                            'handled_at' => $rootHandled ? $repCreated : null,
                        ]);
                    }

                    // delete reply legacy
                    $qdr = $db->prepare("
                        SELECT id_utente
                        FROM {$T_OLD_RDEL}
                        WHERE id_message = ?
                          AND eliminato = 1
                    ");
                    $qdr->bind_param("i", $oldRepId);
                    $qdr->execute();
                    $rdr = $qdr->get_result();

                    while ($d = $rdr->fetch_assoc()) {
                        $u = resolveDeleteLegacyUserToNewUserId($db, (int)$d['id_utente']);
                        if ($u !== null) {
                            upsertFlag($db, $newRepId, $u, [
                                'is_deleted' => 1,
                                'deleted_at' => $repCreated
                            ]);
                        }
                    }
                    $qdr->close();

                    // allegati reply
                    $qar = $db->prepare("
                        SELECT id_attachments
                        FROM {$T_OLD_ATT}
                        WHERE id_message_reply = ?
                        ORDER BY id_attachments ASC
                    ");
                    $qar->bind_param("i", $oldRepId);
                    $qar->execute();
                    $rar = $qar->get_result();

                    while ($a = $rar->fetch_assoc()) {
                        $oldAttachmentId = (int)$a['id_attachments'];
                        migrateOneAttachmentMetadata(
                            $db,
                            $oldAttachmentId,
                            $newRepId,
                            $repSender,
                            $repCreated,
                            'REPLY:' . $r['map_key']
                        );
                    }
                    $qar->close();

                    // opzionale: reply chain vera
                    // $parentId = $newRepId;
                }

                $qr->close();
            }
        }

        $stmt->close();

        if (!$DRY_RUN) {
            setCheckpoint($db, 'last_root_id', (string)$lastRoot);
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