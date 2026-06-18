<?php
/*****************************************************
 * SPOSTA MESSAGGI ARCHIVIO (3-10 mesi)
 * Sorgente:  Sql1688505_1
 * Destinaz.: Sql1688505_5
 *
 * - verifica/creazione tabelle su destinazione (identiche a sorgente)
 * - copia dati (messaggi + collegati + anagrafiche collegate)
 * - elimina dati dal sorgente (se DRY_RUN=false)
 *****************************************************/

// ====== CONFIG ======
$host     = "89.46.111.163";
$user     = "Sql1688505";
$pass     = "Tira74GL!#";

$dbSource = "Sql1688505_1"; // DB ORIGINE
$dbDest   = "Sql1688505_5"; // DB ARCHIVIO

// Finestra: [NOW()-10 mesi, NOW()-3 mesi)
$minMonthsOld = 12; // inclusi (>=)
$maxMonthsOld = 3;  // esclusi  (<)

$CHUNK_SIZE = 500;
$DRY_RUN    = true;  // true = SOLO COPIA | false = COPIA + DELETE

$logFile = __DIR__ . "/sposta_messaggi_archive.log";

// Tabelle richieste su destinazione (incluse dipendenze FK)
$tablesNeeded = [
    "dap04_type_users",
    "dap05_type_doctors",

    "dap01_users",
    "dap02_clients",
    "dap03_personale",
    "dap09_client_doctor",

    "dap10_message",
    "dap10_message_delete",
    "dap10_message_reply",
    "dap10_message_reply_delete",

    "dap11_attachments",
    "dap11_attachments_posticipato",
    "dap11_attachments_temp",
];

// ====== PDO ======
$dsn = "mysql:host=$host;charset=utf8";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_INIT_COMMAND =>
        "SET NAMES utf8,
         lc_time_names = 'it_IT',
         block_encryption_mode = 'aes-256-cbc',
         @key_str = SHA2('PartitaIVA22',512),
         @init_vector = RANDOM_BYTES(16)"
];

function logx($msg) {
    global $logFile;
    $line = "[" . date("Y-m-d H:i:s") . "] " . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
}

function q(PDO $pdo, string $sql, array $params = []) : PDOStatement {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st;
}

function existsTable(PDO $pdo, string $db, string $table) : bool {
    $st = q($pdo,
        "SELECT COUNT(*) 
         FROM information_schema.tables 
         WHERE table_schema = :db AND table_name = :t",
        ["db" => $db, "t" => $table]
    );
    return (int)$st->fetchColumn() > 0;
}

function createTableLikeSource(PDO $pdo, string $dbSource, string $dbDest, string $table) : void {
    $row = q($pdo, "SHOW CREATE TABLE `$dbSource`.`$table`")->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['Create Table'])) {
        throw new Exception("SHOW CREATE TABLE non disponibile per $dbSource.$table");
    }

    $createSql = $row['Create Table'];

    $createSql = preg_replace(
        '/^CREATE TABLE `'.preg_quote($table,'/').'`/i',
        "CREATE TABLE `$dbDest`.`$table`",
        $createSql
    );

    q($pdo, $createSql);
}

function fetchColumnAll(PDO $pdo, string $sql, array $params = []) : array {
    return q($pdo, $sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

function placeholders(int $n) : string {
    return implode(",", array_fill(0, $n, "?"));
}

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    logx("=== Avvio script. DRY_RUN=" . ($DRY_RUN ? "true" : "false") . " ===");

    /***********************
     * 1) Ensure tables on DEST
     ***********************/
    foreach ($tablesNeeded as $t) {
        if (!existsTable($pdo, $dbDest, $t)) {
            logx("Tabella mancante su $dbDest: $t -> creo da $dbSource");
            createTableLikeSource($pdo, $dbSource, $dbDest, $t);
            logx("Creata: $dbDest.$t");
        }
    }

    /***********************
     * 2) Date window
     ***********************/
    $fromDate = q($pdo, "SELECT DATE_SUB(NOW(), INTERVAL $minMonthsOld MONTH) AS d")->fetch(PDO::FETCH_ASSOC)['d'];
    $toDate   = q($pdo, "SELECT DATE_SUB(NOW(), INTERVAL $maxMonthsOld MONTH) AS d")->fetch(PDO::FETCH_ASSOC)['d'];
    logx("Finestra: [{$fromDate}] <= dataora < [{$toDate}]");

    /***********************
     * 3) Count candidates
     ***********************/
    $count = (int)q($pdo,
        "SELECT COUNT(*)
         FROM `$dbSource`.dap10_message m
         WHERE m.dataora >= :fromd AND m.dataora < :tod",
        ["fromd" => $fromDate, "tod" => $toDate]
    )->fetchColumn();

    logx("Messaggi candidati: $count");

    if ($count === 0) {
        logx("Nessun messaggio da spostare. Fine.");
        echo "Nessun messaggio da spostare.\n";
        exit;
    }

    /***********************
     * 4) Chunk loop
     ***********************/
    $offset = 0;

    while (true) {
        $ids = fetchColumnAll($pdo,
            "SELECT m.id_message
             FROM `$dbSource`.dap10_message m
             WHERE m.dataora >= :fromd AND m.dataora < :tod
             ORDER BY m.id_message ASC
             LIMIT $CHUNK_SIZE OFFSET $offset",
            ["fromd" => $fromDate, "tod" => $toDate]
        );

        if (!$ids) break;

        $offset += $CHUNK_SIZE;

        $phM  = placeholders(count($ids));
        $ids2 = array_merge($ids, $ids); // FIX: serve per query con 2x IN($phM)

        try {
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            $pdo->beginTransaction();

            logx("Chunk: " . count($ids) . " messaggi (offset " . ($offset - $CHUNK_SIZE) . ")");

            /***********************
             * A) Anagrafiche collegate ai messaggi del chunk
             ***********************/
            // A1) id_mitt / id_dest (FIX HY093: query contiene 2 IN($phM), quindi params = $ids2)
            $usersFromMsg = q($pdo,
                "SELECT DISTINCT x.idu
                 FROM (
                    SELECT id_mitt AS idu FROM `$dbSource`.dap10_message WHERE id_message IN ($phM) AND id_mitt IS NOT NULL
                    UNION
                    SELECT id_dest AS idu FROM `$dbSource`.dap10_message WHERE id_message IN ($phM) AND id_dest IS NOT NULL
                 ) x
                 WHERE x.idu IS NOT NULL",
                $ids2
            )->fetchAll(PDO::FETCH_COLUMN);

            if ($usersFromMsg) {
                $phU = placeholders(count($usersFromMsg));
                $u1  = $usersFromMsg;
                $u2  = array_merge($usersFromMsg, $usersFromMsg); // FIX per doppio IN($phU)

                // A2) users
                q($pdo,
                    "INSERT IGNORE INTO `$dbDest`.dap01_users
                     SELECT * FROM `$dbSource`.dap01_users
                     WHERE id_user IN ($phU)",
                    $u1
                );

                // A3) clients per id_user
                q($pdo,
                    "INSERT IGNORE INTO `$dbDest`.dap02_clients
                     SELECT * FROM `$dbSource`.dap02_clients
                     WHERE id_user IN ($phU)",
                    $u1
                );

                // A4) personale per id_user
                q($pdo,
                    "INSERT IGNORE INTO `$dbDest`.dap03_personale
                     SELECT * FROM `$dbSource`.dap03_personale
                     WHERE id_user IN ($phU)",
                    $u1
                );

                // A5) copertura extra (se id_mitt/id_dest fossero id_client o id_personale)
                q($pdo,
                    "INSERT IGNORE INTO `$dbDest`.dap02_clients
                     SELECT * FROM `$dbSource`.dap02_clients
                     WHERE id_client IN ($phU)",
                    $u1
                );

                q($pdo,
                    "INSERT IGNORE INTO `$dbDest`.dap03_personale
                     SELECT * FROM `$dbSource`.dap03_personale
                     WHERE id_personale IN ($phU)",
                    $u1
                );

                // A6) recupera client e dottori coinvolti (FIX HY093: due IN => param duplicati)
                $clientIds = fetchColumnAll($pdo,
                    "SELECT DISTINCT id_client
                     FROM `$dbSource`.dap02_clients
                     WHERE id_user IN ($phU) OR id_client IN ($phU)",
                    $u2
                );

                $dotIds = fetchColumnAll($pdo,
                    "SELECT DISTINCT id_personale
                     FROM `$dbSource`.dap03_personale
                     WHERE id_user IN ($phU) OR id_personale IN ($phU)",
                    $u2
                );

                // A7) copia dap09_client_doctor collegati
                $conds = [];
                $p = [];

                if ($clientIds) {
                    $conds[] = "id_client IN (" . placeholders(count($clientIds)) . ")";
                    $p = array_merge($p, $clientIds);
                }
                if ($dotIds) {
                    $conds[] = "id_dot IN (" . placeholders(count($dotIds)) . ")";
                    $p = array_merge($p, $dotIds);
                }

                if ($conds) {
                    q($pdo,
                        "INSERT IGNORE INTO `$dbDest`.dap09_client_doctor
                         SELECT * FROM `$dbSource`.dap09_client_doctor
                         WHERE " . implode(" OR ", $conds),
                        $p
                    );
                }
            }

            /***********************
             * B) Messaggi e collegati
             ***********************/
            q($pdo,
                "INSERT IGNORE INTO `$dbDest`.dap10_message
                 SELECT * FROM `$dbSource`.dap10_message
                 WHERE id_message IN ($phM)",
                $ids
            );

            q($pdo,
                "INSERT IGNORE INTO `$dbDest`.dap10_message_delete
                 SELECT * FROM `$dbSource`.dap10_message_delete
                 WHERE id_message IN ($phM)",
                $ids
            );

            $replyIds = fetchColumnAll($pdo,
                "SELECT r.id_message
                 FROM `$dbSource`.dap10_message_reply r
                 WHERE r.id_message_ini IN ($phM)",
                $ids
            );

            q($pdo,
                "INSERT IGNORE INTO `$dbDest`.dap10_message_reply
                 SELECT * FROM `$dbSource`.dap10_message_reply
                 WHERE id_message_ini IN ($phM)",
                $ids
            );

            if ($replyIds) {
                $phR = placeholders(count($replyIds));
                q($pdo,
                    "INSERT IGNORE INTO `$dbDest`.dap10_message_reply_delete
                     SELECT * FROM `$dbSource`.dap10_message_reply_delete
                     WHERE id_message IN ($phR)",
                    $replyIds
                );
            }

            q($pdo,
                "INSERT IGNORE INTO `$dbDest`.dap11_attachments
                 SELECT * FROM `$dbSource`.dap11_attachments
                 WHERE id_message IN ($phM)",
                $ids
            );

            if ($replyIds) {
                $phR = placeholders(count($replyIds));
                q($pdo,
                    "INSERT IGNORE INTO `$dbDest`.dap11_attachments
                     SELECT * FROM `$dbSource`.dap11_attachments
                     WHERE id_message_reply IN ($phR)",
                    $replyIds
                );
            }

            q($pdo,
                "INSERT IGNORE INTO `$dbDest`.dap11_attachments_posticipato
                 SELECT * FROM `$dbSource`.dap11_attachments_posticipato
                 WHERE id_message IN ($phM)",
                $ids
            );
            if ($replyIds) {
                $phR = placeholders(count($replyIds));
                q($pdo,
                    "INSERT IGNORE INTO `$dbDest`.dap11_attachments_posticipato
                     SELECT * FROM `$dbSource`.dap11_attachments_posticipato
                     WHERE id_message_reply IN ($phR)",
                    $replyIds
                );
            }

            q($pdo,
                "INSERT IGNORE INTO `$dbDest`.dap11_attachments_temp
                 SELECT * FROM `$dbSource`.dap11_attachments_temp
                 WHERE id_message IN ($phM)",
                $ids
            );
            if ($replyIds) {
                $phR = placeholders(count($replyIds));
                q($pdo,
                    "INSERT IGNORE INTO `$dbDest`.dap11_attachments_temp
                     SELECT * FROM `$dbSource`.dap11_attachments_temp
                     WHERE id_message_reply IN ($phR)",
                    $replyIds
                );
            }

            /***********************
             * C) Delete dal sorgente (solo se DRY_RUN=false)
             ***********************/
            if (!$DRY_RUN) {
                q($pdo, "DELETE FROM `$dbSource`.dap11_attachments_temp WHERE id_message IN ($phM)", $ids);
                q($pdo, "DELETE FROM `$dbSource`.dap11_attachments_posticipato WHERE id_message IN ($phM)", $ids);
                q($pdo, "DELETE FROM `$dbSource`.dap11_attachments WHERE id_message IN ($phM)", $ids);

                if ($replyIds) {
                    $phR = placeholders(count($replyIds));
                    q($pdo, "DELETE FROM `$dbSource`.dap11_attachments_temp WHERE id_message_reply IN ($phR)", $replyIds);
                    q($pdo, "DELETE FROM `$dbSource`.dap11_attachments_posticipato WHERE id_message_reply IN ($phR)", $replyIds);
                    q($pdo, "DELETE FROM `$dbSource`.dap11_attachments WHERE id_message_reply IN ($phR)", $replyIds);

                    q($pdo, "DELETE FROM `$dbSource`.dap10_message_reply_delete WHERE id_message IN ($phR)", $replyIds);
                    q($pdo, "DELETE FROM `$dbSource`.dap10_message_reply WHERE id_message IN ($phR)", $replyIds);
                }

                q($pdo, "DELETE FROM `$dbSource`.dap10_message_delete WHERE id_message IN ($phM)", $ids);
                q($pdo, "DELETE FROM `$dbSource`.dap10_message WHERE id_message IN ($phM)", $ids);
            }

            $pdo->commit();
            logx("Chunk OK (commit). Reply collegate: " . (int)count($replyIds));

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            logx("ERRORE chunk: " . $e->getMessage());
            throw $e;
        }
    }

    logx("=== Fine script OK ===");
    echo "Operazione completata. Controlla log: $logFile\n";
    if ($DRY_RUN) {
        echo "ATTENZIONE: DRY_RUN=true, non è stato cancellato nulla dal DB sorgente.\n";
    }

} catch (Throwable $e) {
    logx("FATALE: " . $e->getMessage());
    echo "Errore: " . $e->getMessage() . "\n";
}
