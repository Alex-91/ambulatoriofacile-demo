<?php
/**
 * clone_backup_db.php
 * - Clona DB sorgente -> DB destinazione (solo SQL via mysqli, NO mysqldump)
 * - Genera dump SQL in file (SHOW CREATE TABLE + INSERT batched)
 * - Salva file su cartella locale server
 * - Log dettagliato + mail con log allegato (SMTP Aruba con PHPMailer via Composer)
 */
if (!isset($_GET['token']) || $_GET['token'] !== 'nOyFfgv1gUMfZKrXgonpENfNEY4Nf6JM') {
    http_response_code(403);
    exit('Forbidden');
}
date_default_timezone_set('Europe/Rome');

// =========================
// COMPOSER AUTOLOAD (PHPMailer)
// =========================
// Se lo script è in una cartella tipo /cron e vendor/ è nella root del progetto,
// spesso serve: __DIR__ . '/../vendor/autoload.php'
// Se invece vendor/ è nella stessa cartella dello script: __DIR__ . '/vendor/autoload.php'
$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

$autoloadFound = false;
foreach ($autoloadCandidates as $cand) {
    if (file_exists($cand)) {
        require_once $cand;
        $autoloadFound = true;
        break;
    }
}
if (!$autoloadFound) {
    // Non posso loggare ancora (log dir non creato), quindi errore diretto
    die("ERRORE: vendor/autoload.php non trovato. Controlla il path.\n");
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =========================
// CONFIG (MANTENUTO COME DA TE)
// =========================
$config = [
    // MySQL
    'mysql_host' => '89.46.111.163',
    'mysql_port' => 3306,
    'mysql_user' => 'Sql1688505',
    'mysql_pass' => 'Tira74GL!#',
    'source_db'  => 'Sql1688505_1',
    'target_db'  => 'Sql1688505_4',

    // Cartelle locali su server
    'local_dump_dir' => __DIR__ . '/dumps',
    'local_log_dir'  => __DIR__ . '/logs',

    // Dump
    'dump_include_data' => true,     // se false: solo struttura
    'dump_batch_size'   => 500,      // righe per INSERT multiplo
    'dump_max_rows_per_table' => 0,  // 0 = nessun limite; se vuoi testare metti es. 1000

    // Clone
    'clone_drop_target' => true,     // DROP DATABASE target e ricrea
    'clone_use_insert_select' => true, // FAST: INSERT INTO target.t SELECT * FROM source.t (consigliato)
    // Se false: copia righe via PHP (più lento, ma più “compatibile”)
    'clone_batch_size'  => 2000,     // usato solo se clone_use_insert_select = false

    // Email
    'mail_to'       => 'bassiale91@hotmail.it',
    'mail_from'     => 'info@ambulatoriofacile.it',
    'mail_from_name'=> 'AmbulatorioFacile - Backup',
    'attach_dump'   => false,  // ATTENZIONE: il dump può essere enorme

    // SMTP Aruba
    'smtp' => [
        'host'     => 'smtps.aruba.it',
        'port'     => 465,
        'secure'   => 'ssl',   // ssl oppure tls
        'username' => 'info@ambulatoriofacile.it',
        'password' => 'Tira74GL!',
        'timeout'  => 30,
    ],
];

// =========================
// LOG
// =========================
@mkdir($config['local_dump_dir'], 0775, true);
@mkdir($config['local_log_dir'], 0775, true);

$runId   = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
$logFile = rtrim($config['local_log_dir'], '/\\') . DIRECTORY_SEPARATOR . "clone_backup_{$runId}.log";

function logLine($msg, $level='INFO'){
    global $logFile, $runId;
    $line = sprintf("[%s][%s][%s] %s\n", date('Y-m-d H:i:s'), $level, $runId, $msg);
    file_put_contents($logFile, $line, FILE_APPEND);
}

function mask($s){
    if ($s === null || $s === '') return '';
    return str_repeat('*', max(6, strlen($s)));
}

// =========================
// EMAIL via SMTP (PHPMailer)
// =========================
function sendMailWithAttachments($to, $subject, $body, $from, $fromName, array $attachments = []) {
    global $config;

    logLine("Invio email SMTP a {$to} - Subject: {$subject}", "INFO");
    logLine("SMTP: host={$config['smtp']['host']} port={$config['smtp']['port']} secure={$config['smtp']['secure']} user={$config['smtp']['username']} pass=" . mask($config['smtp']['password']), "DEBUG");

    $mail = new PHPMailer(true);

    try {
        // Server
        $mail->isSMTP();
        $mail->Host       = $config['smtp']['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp']['username'];
        $mail->Password   = $config['smtp']['password'];
        $mail->SMTPSecure = $config['smtp']['secure'];   // 'ssl' o 'tls'
        $mail->Port       = (int)$config['smtp']['port'];
        $mail->Timeout    = (int)$config['smtp']['timeout'];
        $mail->CharSet    = 'UTF-8';

        // Debug: log su file (non a video)
        $mail->SMTPDebug  = 0; // metti 2 solo per debug temporaneo
        $mail->Debugoutput = function ($str, $level) {
            logLine("SMTPDBG({$level}): {$str}", "DEBUG");
        };

        // Mittente / destinatario
        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);

        // Contenuto
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        // Allegati
        foreach ($attachments as $file) {
            if ($file && file_exists($file)) {
                $mail->addAttachment($file);
                logLine("Allegato aggiunto: {$file}", "DEBUG");
            } else {
                logLine("Allegato NON trovato (skip): {$file}", "WARN");
            }
        }

        $mail->send();
        logLine("Email inviata correttamente via SMTP", "INFO");
        return true;

    } catch (Exception $e) {
        logLine("ERRORE SMTP: " . $mail->ErrorInfo, "ERROR");
        return false;
    }
}

// =========================
// MYSQLI helpers
// =========================
function dbConnect($dbName = null){
    global $config;

    $mysqli = new mysqli(
        $config['mysql_host'],
        $config['mysql_user'],
        $config['mysql_pass'],
        $dbName ?? '',
        (int)$config['mysql_port']
    );

    if ($mysqli->connect_errno) {
        throw new Exception("Connessione MySQL fallita: {$mysqli->connect_error}");
    }

    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function q($mysqli, $sql){
    logLine("SQL: " . $sql, "DEBUG");
    $res = $mysqli->query($sql);
    if ($res === false) {
        throw new Exception("Errore SQL: {$mysqli->error} | Query: {$sql}");
    }
    return $res;
}

function fetchAllCol($mysqli, $sql, $col=0){
    $res = q($mysqli, $sql);
    $out = [];
    while ($row = $res->fetch_array(MYSQLI_NUM)) {
        $out[] = $row[$col];
    }
    $res->free();
    return $out;
}

function escapeIdent($name){
    return '`' . str_replace('`', '``', $name) . '`';
}

// =========================
// DUMP generator
// =========================
function writeDumpFile($src, $dumpPath){
    global $config;

    $sourceDb = $config['source_db'];
    $batch    = (int)$config['dump_batch_size'];
    $maxRows  = (int)$config['dump_max_rows_per_table'];

    $fh = fopen($dumpPath, 'wb');
    if (!$fh) throw new Exception("Impossibile creare dump: {$dumpPath}");

    $header = "-- Dump generated by PHP (no mysqldump)\n";
    $header .= "-- RunID: {$GLOBALS['runId']}\n";
    $header .= "-- Date: " . date('c') . "\n\n";
    $header .= "SET NAMES utf8mb4;\n";
    $header .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $header .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
    fwrite($fh, $header);

    // Tabelle (solo BASE TABLE)
    $tables = fetchAllCol($src,
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA='" . $src->real_escape_string($sourceDb) . "'
           AND TABLE_TYPE='BASE TABLE'
         ORDER BY TABLE_NAME"
    );

    logLine("Dump: trovate " . count($tables) . " tabelle base", "INFO");

    foreach ($tables as $t) {
        $tEsc = escapeIdent($t);

        // Struttura
        $res = q($src, "SHOW CREATE TABLE " . escapeIdent($sourceDb) . ".{$tEsc}");
        $row = $res->fetch_assoc();
        $res->free();
        $create = $row['Create Table'] ?? null;
        if (!$create) throw new Exception("SHOW CREATE TABLE vuoto per {$t}");

        fwrite($fh, "\n-- ----------------------------\n");
        fwrite($fh, "-- Table structure for {$t}\n");
        fwrite($fh, "-- ----------------------------\n");
        fwrite($fh, "DROP TABLE IF EXISTS {$tEsc};\n");
        fwrite($fh, $create . ";\n\n");

        if (!$config['dump_include_data']) {
            continue;
        }

        // Dati
        fwrite($fh, "-- ----------------------------\n");
        fwrite($fh, "-- Data for {$t}\n");
        fwrite($fh, "-- ----------------------------\n");

        $limitSql = "";
        if ($maxRows > 0) $limitSql = " LIMIT {$maxRows}";

        $result = q($src, "SELECT * FROM " . escapeIdent($sourceDb) . ".{$tEsc}{$limitSql}");
        $numFields = $result->field_count;

        // colonne
        $fields = [];
        $meta = $result->fetch_fields();
        foreach ($meta as $f) $fields[] = escapeIdent($f->name);
        $colList = implode(',', $fields);

        $rowsInBatch = 0;
        $valuesChunk = [];

        while ($r = $result->fetch_row()) {
            $vals = [];
            for ($i=0; $i<$numFields; $i++){
                if ($r[$i] === null) {
                    $vals[] = "NULL";
                } else {
                    $vals[] = "'" . $src->real_escape_string($r[$i]) . "'";
                }
            }
            $valuesChunk[] = "(" . implode(',', $vals) . ")";
            $rowsInBatch++;

            if ($rowsInBatch >= $batch) {
                fwrite($fh, "INSERT INTO {$tEsc} ({$colList}) VALUES\n" . implode(",\n", $valuesChunk) . ";\n");
                $valuesChunk = [];
                $rowsInBatch = 0;
            }
        }

        if ($rowsInBatch > 0) {
            fwrite($fh, "INSERT INTO {$tEsc} ({$colList}) VALUES\n" . implode(",\n", $valuesChunk) . ";\n");
        }

        $result->free();
    }

    fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    if (!file_exists($dumpPath) || filesize($dumpPath) === 0) {
        throw new Exception("Dump creato ma vuoto: {$dumpPath}");
    }

    logLine("Dump creato: {$dumpPath} (" . number_format(filesize($dumpPath)) . " bytes)", "INFO");
}

// =========================
// CLONE DB (SQL only)
// =========================
function cloneDatabase(){
    global $config;

    $srcDb = $config['source_db'];
    $tgtDb = $config['target_db'];

    $admin = dbConnect(); // senza db selezionato

    logLine("Clone: host={$config['mysql_host']} user={$config['mysql_user']} pass=" . mask($config['mysql_pass']), "DEBUG");

    if ($config['clone_drop_target']) {
        logLine("Clone: DROP/CREATE database target {$tgtDb}", "INFO");
        q($admin, "DROP DATABASE IF EXISTS " . escapeIdent($tgtDb));
        q($admin, "CREATE DATABASE " . escapeIdent($tgtDb) . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    $src = dbConnect($srcDb);
    $tgt = dbConnect($tgtDb);

    // Prendo tabelle base
    $tables = fetchAllCol($src,
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA='" . $src->real_escape_string($srcDb) . "'
           AND TABLE_TYPE='BASE TABLE'
         ORDER BY TABLE_NAME"
    );

    logLine("Clone: tabelle base da copiare: " . count($tables), "INFO");

    // Per evitare errori di FK durante creazione/copia
    q($tgt, "SET FOREIGN_KEY_CHECKS=0");
    q($tgt, "SET UNIQUE_CHECKS=0");
    q($tgt, "SET AUTOCOMMIT=1");

    foreach ($tables as $t) {
        $tEsc = escapeIdent($t);

        // 1) Struttura
        logLine("Clone: creo tabella {$tgtDb}.{$t}", "INFO");
        $res = q($src, "SHOW CREATE TABLE " . escapeIdent($srcDb) . ".{$tEsc}");
        $row = $res->fetch_assoc();
        $res->free();

        $create = $row['Create Table'] ?? null;
        if (!$create) throw new Exception("SHOW CREATE TABLE vuoto per {$t}");

        // Ricreo nel target (solo target, MAI sorgente)
        q($tgt, "DROP TABLE IF EXISTS {$tEsc}");
        q($tgt, $create);

        // 2) Dati
        logLine("Clone: copio dati {$t}...", "INFO");
        if ($config['clone_use_insert_select']) {
            $sql = "INSERT INTO " . escapeIdent($tgtDb) . ".{$tEsc} SELECT * FROM " . escapeIdent($srcDb) . ".{$tEsc}";
            q($tgt, $sql);
        } else {
            $batchSize = (int)$config['clone_batch_size'];

            $result = q($src, "SELECT * FROM " . escapeIdent($srcDb) . ".{$tEsc}");
            $numFields = $result->field_count;

            $fields = [];
            $meta = $result->fetch_fields();
            foreach ($meta as $f) $fields[] = escapeIdent($f->name);
            $colList = implode(',', $fields);

            $valuesChunk = [];

            while ($r = $result->fetch_row()) {
                $vals = [];
                for ($i=0; $i<$numFields; $i++){
                    if ($r[$i] === null) $vals[] = "NULL";
                    else $vals[] = "'" . $tgt->real_escape_string($r[$i]) . "'";
                }
                $valuesChunk[] = "(" . implode(',', $vals) . ")";

                if (count($valuesChunk) >= $batchSize) {
                    q($tgt, "INSERT INTO {$tEsc} ({$colList}) VALUES " . implode(',', $valuesChunk));
                    $valuesChunk = [];
                }
            }

            if (!empty($valuesChunk)) {
                q($tgt, "INSERT INTO {$tEsc} ({$colList}) VALUES " . implode(',', $valuesChunk));
            }

            $result->free();
        }
    }

    // Views (opzionale)
    $views = fetchAllCol($src,
        "SELECT TABLE_NAME
         FROM information_schema.VIEWS
         WHERE TABLE_SCHEMA='" . $src->real_escape_string($srcDb) . "'
         ORDER BY TABLE_NAME"
    );

    if (!empty($views)) {
        logLine("Clone: trovate " . count($views) . " view (le ricreo nel target)", "INFO");
        foreach ($views as $v) {
            $vEsc = escapeIdent($v);
            $res = q($src, "SHOW CREATE VIEW " . escapeIdent($srcDb) . ".{$vEsc}");
            $row = $res->fetch_assoc();
            $res->free();

            $create = $row['Create View'] ?? null;
            if (!$create) {
                logLine("Clone: impossibile leggere CREATE VIEW per {$v} (skip)", "WARN");
                continue;
            }

            q($tgt, "DROP VIEW IF EXISTS {$vEsc}");
            q($tgt, $create);
        }
    } else {
        logLine("Clone: nessuna VIEW trovata", "DEBUG");
    }

    q($tgt, "SET FOREIGN_KEY_CHECKS=1");
    q($tgt, "SET UNIQUE_CHECKS=1");

    $src->close();
    $tgt->close();
    $admin->close();

    logLine("Clone: completato con successo", "INFO");
}

// =========================
// MAIN
// =========================
$okAll = true;
$errors = [];
$start = microtime(true);

logLine("==== INIZIO JOB CLONE+BACKUP (NO mysqldump) ====", "INFO");
logLine("Sorgente={$config['source_db']} Destinazione={$config['target_db']}", "INFO");

$dumpPath = rtrim($config['local_dump_dir'], '/\\') . DIRECTORY_SEPARATOR . "dump_{$config['source_db']}_{$runId}.sql";

try {
    // 1) CLONE DB
    logLine("Step 1: CLONE DB", "INFO");
    cloneDatabase();

    // 2) GENERA DUMP SQL (sorgente) e salva su server
    logLine("Step 2: GENERA DUMP FILE", "INFO");
    $srcConn = dbConnect($config['source_db']);
    writeDumpFile($srcConn, $dumpPath);
    $srcConn->close();

    logLine("JOB COMPLETATO OK", "INFO");

} catch (Throwable $e) {
    $okAll = false;
    $errors[] = $e->getMessage();
    logLine("ERRORE: " . $e->getMessage(), "ERROR");
    logLine("TRACE:\n" . $e->getTraceAsString(), "ERROR");
}

$elapsed = round(microtime(true) - $start, 3);

$subject = $okAll
    ? "✅ OK Backup/Clone DB - {$config['source_db']} -> {$config['target_db']} - {$runId}"
    : "⚠️ ATTENZIONE Backup/Clone DB - APRI MAIL - {$runId}";

$body  = "Esito job: " . ($okAll ? "OK" : "KO") . "\n";
$body .= "Run ID: {$runId}\n";
$body .= "Sorgente: {$config['source_db']}\n";
$body .= "Destinazione: {$config['target_db']}\n";
$body .= "Dump: {$dumpPath}\n";
$body .= "Tempo: {$elapsed} sec\n";
if (!$okAll) {
    $body .= "\nErrori:\n- " . implode("\n- ", $errors) . "\n";
}
$body .= "\nAllegato: log completo.\n";

$attachments = [$logFile];
if ($config['attach_dump'] && file_exists($dumpPath)) {
    $attachments[] = $dumpPath;
}

sendMailWithAttachments(
    $config['mail_to'],
    $subject,
    $body,
    $config['mail_from'],
    $config['mail_from_name'],
    $attachments
);

logLine("==== FINE JOB (elapsed {$elapsed}s) ====", "INFO");
exit($okAll ? 0 : 1);
