<?php
set_time_limit(0);
ini_set('max_execution_time', '0');
ignore_user_abort(true);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * FASE 2 LOCALE - SELF CONTAINED
 *
 * - legge vecchi allegati da dap11_attachments
 * - usa msg_migration_map per trovare il nuovo id_attachment
 * - usa msg_attachments per trovare il nuovo id_message
 * - prende il file vecchio dalla copia locale
 * - decripta il contenuto vecchio
 * - ricripta col formato nuovo
 * - lo salva nella nuova cartella locale
 * - aggiorna msg_attachments
 *
 * Versione robusta:
 * - normalizzazione nomi/path
 * - ricerca file più tollerante
 * - fallback con scansione directory
 * - log errori senza bloccare tutto il batch
 */

// ========================
// CONFIG DB LOCALE
// ========================
$DB = [
    'host' => '127.0.0.1',
    'user' => 'root',
    'pass' => 'root',
    'db'   => 'mail',
];

$DRY_RUN             = false;
$DEBUG               = true;
$BATCH_SIZE          = 200;
$START_OLD_ATTACH_ID = 0;
$CALC_FILE_META      = true;

// ========================
// PERCORSI LOCALI
// ========================
$OLD_ATTACHMENTS_BASE_DIR = 'D:/FARMAWEB';
$NEW_ATTACHMENTS_BASE_DIR = 'D:/new_rest';

// ========================
// TABELLE
// ========================
$T_OLD_ATT     = 'dap11_attachments';
$T_OLD_FORWARD = 'dap17_inoltro_message';
$T_NEW_ATT     = 'msg_attachments';
$T_MAP         = 'msg_migration_map';

// ========================
// CRYPTO MYSQL PER METADATI
// ========================
const MYSQL_KEY_PASSPHRASE = 'PartitaIVA22';

// ========================
// CRYPTO FILE VECCHIO
// ========================
const OLD_FILE_ALGORITHM = 'AES-256-CBC';
const OLD_FILE_PASSWORD  = '123456';
const OLD_FILE_IV        = '12dasdq3g5b2434b';

// ========================
// CRYPTO FILE NUOVO
// ========================
const NEW_FILE_ALGORITHM = 'AES-256-CBC';
const NEW_FILE_PASSWORD  = '123456';
const NEW_FILE_IV        = '12dasdq3g5b2434b';

// ========================
// CONNESSIONE
// ========================
$db = new mysqli($DB['host'], $DB['user'], $DB['pass'], $DB['db']);
$db->set_charset('utf8');

$db->query("SET NAMES utf8");
$db->query("SET lc_time_names = 'it_IT'");
$db->query("SET SESSION block_encryption_mode = 'aes-256-cbc'");
$db->query("SET @key_str = SHA2('" . $db->real_escape_string(MYSQL_KEY_PASSPHRASE) . "', 512)");
$db->query("SET @init_vector = RANDOM_BYTES(16)");

// ========================
// HELPERS DEBUG
// ========================
function debugValue($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (is_string($value)) {
        $clean = preg_replace('/[^\P{C}\n\r\t]/u', '?', $value);
        return '"' . $clean . '"';
    }
    return (string) $value;
}

function debugBlock(string $title, array $data): void
{
    if (empty($GLOBALS['DEBUG'])) {
        return;
    }

    echo "\n================ {$title} ================\n";
    foreach ($data as $k => $v) {
        echo str_pad($k, 34, ' ', STR_PAD_RIGHT) . ': ' . debugValue($v) . "\n";
    }
    echo "===========================================================\n";
}

function logInfo(string $message): void
{
    echo $message . "\n";
}

function logError(string $message): void
{
    echo "[ERRORE] " . $message . "\n";
}

// ========================
// HELPERS PATH / FILE
// ========================
function normalizePath(string $p): string
{
    $p = trim($p);
    $p = str_replace('\\', '/', $p);
    $p = preg_replace('#/+#', '/', $p);
    return rtrim($p, '/');
}

function cleanText(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    return $value;
}

function cleanFileName(string $name): string
{
    $name = cleanText($name);
    $name = str_replace('\\', '', $name);
    $name = str_replace('/', '', $name);
    return $name;
}

function splitDirAndFile(string $maybePath): array
{
    $p = normalizePath($maybePath);

    if ($p === '' || strpos($p, '/') === false) {
        return ['', cleanFileName($p)];
    }

    $dir = dirname($p);
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }

    $file = basename($p);

    return [normalizePath($dir), cleanFileName($file)];
}

function ensureDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Impossibile creare directory: {$dir}");
        }
    }
}

function buildUniqueTargetPath(string $targetDir, string $storedName): string
{
    $targetDir  = normalizePath($targetDir);
    $storedName = cleanFileName($storedName);

    $baseName = pathinfo($storedName, PATHINFO_FILENAME);
    $ext      = pathinfo($storedName, PATHINFO_EXTENSION);

    $candidate = $targetDir . '/' . $storedName;
    if (!file_exists($candidate)) {
        return $candidate;
    }

    $i = 1;
    while (true) {
        $newName = $ext !== ''
            ? ($baseName . '_' . $i . '.' . $ext)
            : ($baseName . '_' . $i);

        $candidate = $targetDir . '/' . $newName;
        if (!file_exists($candidate)) {
            return $candidate;
        }
        $i++;
    }
}

function randomVectorId(int $len = 16): string
{
    return random_bytes($len);
}

function fileMetaMaybeFromFile(string $fullPath, bool $enabled): array
{
    if (!$enabled) {
        return ['application/octet-stream', 0, null];
    }

    if (is_file($fullPath)) {
        $size = (int) filesize($fullPath);
        $mime = function_exists('mime_content_type')
            ? (mime_content_type($fullPath) ?: 'application/octet-stream')
            : 'application/octet-stream';

        return [$mime, $size, true];
    }

    return ['application/octet-stream', 0, false];
}

function dumpCandidateChecks(array $candidates): void
{
    if (empty($GLOBALS['DEBUG'])) {
        return;
    }

    foreach ($candidates as $c) {
        echo "\nCHECK PATH: {$c}\n";
        echo " - dirname: " . dirname($c) . "\n";
        echo " - basename: " . basename($c) . "\n";
        echo " - file_exists: " . (file_exists($c) ? 'true' : 'false') . "\n";
        echo " - is_file: " . (is_file($c) ? 'true' : 'false') . "\n";
        echo " - dirname_exists: " . (is_dir(dirname($c)) ? 'true' : 'false') . "\n";
    }
}

function listDirectoryFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $items = @scandir($dir);
    if ($items === false) {
        return [];
    }

    $files = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = normalizePath($dir . '/' . $item);
        if (is_file($full)) {
            $files[] = $full;
        }
    }

    return $files;
}

// ========================
// CRYPTO FILE NUOVO
// ========================
function newFileAlgo(): string
{
    return getenv('FILE_CRYPT_ALGO') ?: NEW_FILE_ALGORITHM;
}

function newFileKey(): string
{
    return getenv('FILE_CRYPT_KEY') ?: NEW_FILE_PASSWORD;
}

function newFileIv(): string
{
    $iv = getenv('FILE_CRYPT_IV') ?: NEW_FILE_IV;
    return substr(str_pad($iv, 16, "\0"), 0, 16);
}

function encryptBytesForNewSystem(string $plainBytes): string
{
    $encryptedBytes = openssl_encrypt(
        $plainBytes,
        newFileAlgo(),
        newFileKey(),
        OPENSSL_RAW_DATA,
        newFileIv()
    );

    if ($encryptedBytes === false || $encryptedBytes === null) {
        throw new RuntimeException('Errore encrypt nuovo file');
    }

    return $encryptedBytes;
}

function decryptStoredPayloadNewSystem(string $payload): string|false
{
    $plain = openssl_decrypt(
        $payload,
        newFileAlgo(),
        newFileKey(),
        OPENSSL_RAW_DATA,
        newFileIv()
    );
    if ($plain !== false) {
        return $plain;
    }

    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        return false;
    }

    return openssl_decrypt(
        $decoded,
        newFileAlgo(),
        newFileKey(),
        OPENSSL_RAW_DATA,
        newFileIv()
    );
}

// ========================
// DECRYPT METADATI VECCHI
// ========================
function fetchOldAttachmentPlain(mysqli $db, int $oldAttachmentId): ?array
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

    if (!$row) {
        return null;
    }

    $nomeRealPlain = str_replace('\\', '/', (string) ($row['nome_real_plain'] ?? ''));
    $nomeRealPlain = cleanText($nomeRealPlain);

    $nomeVisPlain = (string) ($row['nome_vis_plain'] ?? '');
    $nomeVisPlain = cleanText($nomeVisPlain);

    [$oldDirPlain, $storedNamePlain] = splitDirAndFile($nomeRealPlain);

    return [
        'id_attachments'      => (int) $row['id_attachments'],
        'id_message'          => $row['id_message'] !== null ? (int) $row['id_message'] : null,
        'id_message_reply'    => $row['id_message_reply'] !== null ? (int) $row['id_message_reply'] : null,
        'old_vector_id_hex'   => is_string($row['vector_id']) ? bin2hex($row['vector_id']) : null,
        'nome_real_raw'       => (string) ($row['nome_real_raw'] ?? ''),
        'nome_vis_raw'        => (string) ($row['nome_vis_raw'] ?? ''),
        'nome_real_plain'     => $nomeRealPlain,
        'original_name_plain' => $nomeVisPlain,
        'stored_name_plain'   => $storedNamePlain,
        'old_dir_plain'       => $oldDirPlain,
    ];
}

// ========================
// RICERCA FILE VECCHIO
// ========================
function buildOldFileCandidates(mysqli $db, array $old): array
{
    $base = normalizePath($GLOBALS['OLD_ATTACHMENTS_BASE_DIR']);
    $candidates = [];

    $stored        = cleanFileName((string) ($old['stored_name_plain'] ?? ''));
    $nomeRealPlain = normalizePath((string) ($old['nome_real_plain'] ?? ''));
    $oldDirPlain   = normalizePath((string) ($old['old_dir_plain'] ?? ''));
    $idMessage     = (int) ($old['id_message'] ?? 0);
    $idReply       = (int) ($old['id_message_reply'] ?? 0);

    // 1. path completo decriptato relativo alla base
    if ($nomeRealPlain !== '') {
        $candidates[] = normalizePath($base . '/' . ltrim($nomeRealPlain, '/'));
    }

    // 2. old_dir_plain + file
    if ($oldDirPlain !== '' && $stored !== '') {
        $candidates[] = normalizePath($base . '/' . $oldDirPlain . '/' . $stored);
    }

    // 3. base/file
    if ($stored !== '') {
        $candidates[] = normalizePath($base . '/' . $stored);
    }

    // 4. base/id_message/file
    if ($stored !== '' && $idMessage > 0) {
        $candidates[] = normalizePath($base . '/' . $idMessage . '/' . $stored);
    }

    // 5. base/id_message_reply/file
    if ($stored !== '' && $idReply > 0) {
        $candidates[] = normalizePath($base . '/' . $idReply . '/' . $stored);
    }

    // 6. lookup eventuale inoltro
    if ($idMessage > 0) {
        $stmt = $db->prepare("SELECT id_message FROM dap17_inoltro_message WHERE id_message_new = ? LIMIT 1");
        $stmt->bind_param("i", $idMessage);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $oldForwardMessageId = (int) ($row['id_message'] ?? 0);
        if ($stored !== '' && $oldForwardMessageId > 0) {
            $candidates[] = normalizePath($base . '/' . $oldForwardMessageId . '/' . $stored);
        }
    }

    return array_values(array_unique(array_filter($candidates)));
}

function resolveExistingOldFile(array $candidates): ?string
{
    // 1. match diretto
    foreach ($candidates as $path) {
        $path = normalizePath($path);
        if (is_file($path)) {
            return $path;
        }
    }

    // 2. match per nome pulito scandendo la cartella
    foreach ($candidates as $path) {
        $path = normalizePath($path);
        $dir  = dirname($path);
        $file = cleanFileName(basename($path));

        if (!is_dir($dir)) {
            continue;
        }

        $items = @scandir($dir);
        if ($items === false) {
            continue;
        }

        $wanted = strtolower($file);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemClean = strtolower(cleanFileName($item));
            if ($itemClean === $wanted) {
                $full = normalizePath($dir . '/' . $item);
                if (is_file($full)) {
                    return $full;
                }
            }
        }
    }

    // 3. fallback: se nella directory candidata esiste un solo file .crypto, usalo
    foreach ($candidates as $path) {
        $path = normalizePath($path);
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            continue;
        }

        $files = listDirectoryFiles($dir);
        $cryptoFiles = [];

        foreach ($files as $f) {
            if (preg_match('/\.crypto$/i', basename($f))) {
                $cryptoFiles[] = $f;
            }
        }

        if (count($cryptoFiles) === 1) {
            return $cryptoFiles[0];
        }
    }

    return null;
}

// ========================
// DECRYPT FILE VECCHIO
// ========================
function decryptOldFileBytes(string $encryptedBytes): string
{
    $plain = openssl_decrypt(
        $encryptedBytes,
        OLD_FILE_ALGORITHM,
        OLD_FILE_PASSWORD,
        OPENSSL_RAW_DATA,
        OLD_FILE_IV
    );

    if ($plain === false) {
        throw new RuntimeException('Errore decrypt del vecchio file');
    }

    return $plain;
}

// ========================
// CRIPTAZIONE METADATI NUOVI
// ========================
function encryptStringForNewDb(mysqli $db, string $plainText, string $vector): string
{
    $sql = "SELECT HEX(AES_ENCRYPT(?, @key_str, ?)) AS cipher_hex";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ss", $plainText, $vector);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (string) ($row['cipher_hex'] ?? '');
}

// ========================
// AGGIORNA msg_attachments
// ========================
function updateNewAttachmentMetadata(
    mysqli $db,
    int $newAttachmentId,
    string $originalNamePlain,
    string $storedNamePlain,
    string $storagePathPlain,
    string $mimeType,
    int $fileSize
): void {
    $newVector = randomVectorId(16);

    $originalNamePlain = cleanText($originalNamePlain);
    $storedNamePlain   = cleanFileName($storedNamePlain);
    $storagePathPlain  = normalizePath($storagePathPlain);

    $encOriginal = encryptStringForNewDb($db, $originalNamePlain, $newVector);
    $encStored   = encryptStringForNewDb($db, $storedNamePlain, $newVector);
    $encPath     = encryptStringForNewDb($db, $storagePathPlain, $newVector);

    $stmt = $db->prepare("
        UPDATE msg_attachments
        SET
            original_name = ?,
            stored_name   = ?,
            mime_type     = ?,
            file_size     = ?,
            storage_path  = ?,
            vector_id     = ?
        WHERE id_attachment = ?
        LIMIT 1
    ");
    $stmt->bind_param(
        "sssissi",
        $encOriginal,
        $encStored,
        $mimeType,
        $fileSize,
        $encPath,
        $newVector,
        $newAttachmentId
    );
    $stmt->execute();
    $stmt->close();

    debugBlock('AGGIORNAMENTO DB NUOVO', [
        'new_attachment_id'   => $newAttachmentId,
        'original_name_plain' => $originalNamePlain,
        'stored_name_plain'   => $storedNamePlain,
        'storage_path_plain'  => $storagePathPlain,
        'mime_type'           => $mimeType,
        'file_size'           => $fileSize,
        'vector_id_hex'       => bin2hex($newVector),
    ]);
}

// ========================
// QUERY BATCH
// ========================
function fetchBatch(mysqli $db, int $startOldId, int $limit): array
{
    $sql = "
        SELECT
            mm.old_id AS old_attachment_id,
            mm.new_id AS new_attachment_id,
            na.id_message AS new_message_id
        FROM msg_migration_map mm
        INNER JOIN msg_attachments na
            ON na.id_attachment = mm.new_id
        WHERE mm.old_table = 'dap11_attachments'
          AND mm.old_id > ?
        ORDER BY mm.old_id ASC
        LIMIT {$limit}
    ";

    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $startOldId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

// ========================
// MAIN
// ========================
echo "== FASE 2 LOCALE - FILE ALLEGATI ==\n";
echo "DB: {$DB['host']} / {$DB['db']}\n";
echo "DRY_RUN=" . ($DRY_RUN ? 'true' : 'false') . "\n";
echo "OLD_ATTACHMENTS_BASE_DIR=" . $OLD_ATTACHMENTS_BASE_DIR . "\n";
echo "NEW_ATTACHMENTS_BASE_DIR=" . $NEW_ATTACHMENTS_BASE_DIR . "\n";

$lastOldAttachmentId = (int) $START_OLD_ATTACH_ID;
$totalProcessed = 0;
$totalOk = 0;
$totalErrors = 0;

while (true) {
    $batch = fetchBatch($db, $lastOldAttachmentId, $BATCH_SIZE);

    echo "\nBatch trovati: " . count($batch) . "\n";
    if (!$batch) {
        break;
    }

    foreach ($batch as $row) {
        $oldAttachmentId = (int) $row['old_attachment_id'];
        $newAttachmentId = (int) $row['new_attachment_id'];
        $newMessageId    = (int) $row['new_message_id'];

        $lastOldAttachmentId = $oldAttachmentId;
        $totalProcessed++;

        echo "\nProcesso allegato vecchio {$oldAttachmentId} -> nuovo {$newAttachmentId} / msg {$newMessageId}\n";

        try {
            $old = fetchOldAttachmentPlain($db, $oldAttachmentId);
            if (!$old) {
                throw new RuntimeException("Allegato vecchio {$oldAttachmentId} non trovato in dap11_attachments");
            }

            debugBlock('METADATI VECCHI', $old);

            $candidates  = buildOldFileCandidates($db, $old);
            $oldFilePath = resolveExistingOldFile($candidates);

            debugBlock('RICERCA FILE VECCHIO', [
                'old_attachment_id' => $oldAttachmentId,
                'candidates'        => $candidates,
                'resolved_path'     => $oldFilePath,
            ]);

            dumpCandidateChecks($candidates);

            if (!$oldFilePath || !is_file($oldFilePath)) {
                throw new RuntimeException("File vecchio non trovato per allegato {$oldAttachmentId}");
            }

            $oldEncryptedBytes = @file_get_contents($oldFilePath);
            if ($oldEncryptedBytes === false) {
                throw new RuntimeException("Impossibile leggere il file vecchio {$oldFilePath}");
            }

            $plainBytes = decryptOldFileBytes($oldEncryptedBytes);

            $targetDir = normalizePath($NEW_ATTACHMENTS_BASE_DIR) . '/' . $newMessageId;
            ensureDirectory($targetDir);

            $newStoredName = cleanFileName((string) $old['stored_name_plain']);
            if ($newStoredName === '') {
                throw new RuntimeException("stored_name_plain vuoto per allegato {$oldAttachmentId}");
            }

            if (!preg_match('/\.crypto$/i', $newStoredName)) {
                $newStoredName .= '.crypto';
            }

            $newFullPath   = buildUniqueTargetPath($targetDir, $newStoredName);
            $newStoredName = basename($newFullPath);

            debugBlock('SCRITTURA FILE NUOVO', [
                'old_attachment_id' => $oldAttachmentId,
                'new_attachment_id' => $newAttachmentId,
                'source_path'       => $oldFilePath,
                'target_dir'        => $targetDir,
                'target_path'       => $newFullPath,
                'stored_name'       => $newStoredName,
                'plain_size'        => strlen($plainBytes),
            ]);

            if (!$DRY_RUN) {
                $payload = encryptBytesForNewSystem($plainBytes);
                if (@file_put_contents($newFullPath, $payload) === false) {
                    throw new RuntimeException("Impossibile scrivere il nuovo file {$newFullPath}");
                }
            }

            [$mimeType, $fileSize, $found] = fileMetaMaybeFromFile($newFullPath, (bool) $GLOBALS['CALC_FILE_META']);

            if (!$CALC_FILE_META) {
                $mimeType = 'application/octet-stream';
                $fileSize = $DRY_RUN ? 0 : (int) @filesize($newFullPath);
            }

            debugBlock('FILE NUOVO SALVATO', [
                'new_attachment_id' => $newAttachmentId,
                'found'             => $found,
                'mime_type'         => $mimeType,
                'file_size'         => $fileSize,
            ]);

            if (!$DRY_RUN) {
                updateNewAttachmentMetadata(
                    $db,
                    $newAttachmentId,
                    (string) $old['original_name_plain'],
                    $newStoredName,
                    $newFullPath,
                    $mimeType,
                    $fileSize
                );
            }

            $totalOk++;
        } catch (Throwable $e) {
            $totalErrors++;

            logError("Allegato old={$oldAttachmentId}, new={$newAttachmentId}, msg={$newMessageId}: " . $e->getMessage());

            // continua con il prossimo record
            continue;
        }
    }
}

echo "\nFINE. Ultimo old_attachment_id processato: {$lastOldAttachmentId}\n";
echo "Totale processati: {$totalProcessed}\n";
echo "Totale OK: {$totalOk}\n";
echo "Totale errori: {$totalErrors}\n";