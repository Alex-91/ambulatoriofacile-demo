<?php

namespace App\Models;

use CodeIgniter\Model;
use App\Libraries\Crypto_helper;
use App\Libraries\SystemUserMask;
use App\Services\StaffDoctorAccessService;
use Config\Database;

class MessagesModel extends Model
{
    protected $DBGroup = 'default';

    /* ==========================================================
     *                  HELPERS INTERI AL MODEL
     * ========================================================== */

    /**
     * Ritorna l'utente di sessione (oggetto) oppure null.
     */
    protected function getSessionUser()
    {
        return session()->get('utente_sess');
    }

    /**
     * Ritorna l'id del dottore selezionato (se segreteria/infermiera)
     * oppure null se non presente.
     */
    protected function getSelectedDoctorId(): ?int
    {
        $sel = session()->get('selectedDoctorId');
        return $sel ? (int) $sel : null;
    }

    protected function normalizeMailboxTipoPers(int $tipoPers): int
    {
        return $tipoPers === StaffDoctorAccessService::TIPO_ADMIN
            ? StaffDoctorAccessService::TIPO_SEGRETERIA
            : $tipoPers;
    }

    protected function applySystemClientMaskToNameParts(
        array $row,
        int $clientId,
        string $nomeKey = 'mittente_nome',
        string $cognomeKey = 'mittente_cognome'
    ): array {
        if (SystemUserMask::isMaskedClientId($clientId)) {
            $row[$nomeKey] = '';
            $row[$cognomeKey] = SystemUserMask::SYSTEM_USER_LABEL;
        }

        return $row;
    }

    /**
     * Risolve l'id "owner" della mailbox dato un $userId di input.
     *
     * Logica:
     *  - se non c'è utente in sessione -> usa semplicemente $userId
     *  - se utente.tipo == 3 (cliente) -> id_client
     *  - se utente.tipo == 2 (personale):
     *        - se tipo_pers in (2,3) (infermiera/segreteria) E selectedDoctorId presente
     *              -> usa selectedDoctorId (mailbox del dottore)
     *        - altrimenti -> id_personale
     *  - altrimenti -> id_user o $userId
     */
    protected function resolveMailboxOwnerId(int $userId): int
    {
        $utente = $this->getSessionUser();
        if (!$utente) {
            return $userId;
        }

        $tipo     = $utente->tipo ?? null;
        $tipoPers = $this->normalizeMailboxTipoPers((int)($utente->tipo_pers ?? 0));
        $selDoc   = $this->getSelectedDoctorId();

        if ($tipo == 3) {
            // Cliente
            return (int) ($utente->id_client ?? $userId);
        }

        if ($tipo == 2) {
            // Personale (dottore / infermiera / segreteria)
            if (in_array($tipoPers, [2, 3], true) && $selDoc) {
                // Infermiera / Segreteria che sta usando la mailbox del dottore selezionato
                return (int) $selDoc;
            }
            // Dottore (tipo_pers = 1) o personale generico
            return (int) ($utente->id_personale ?? $userId);
        }

        // Utenti vari (es. admin)
        return (int) ($utente->id_user ?? $userId);
    }

    /**
     * Risolve l'id e il "dest" per l'inbox UNREAD (countUnread).
     *
     * - Per cliente: dest = 'C'
     * - Per personale: dest = 'P'
     * - Per segreteria/infermiera che guarda mailbox del dottore: dest = 'S'
     */
    protected function resolveUnreadContext(int $userId): array
    {
        $utente   = $this->getSessionUser();
        $dest     = 'P';
        $ownerId  = $userId;

        if (!$utente) {
            return ['userId' => $ownerId, 'dest' => $dest];
        }

        $tipo     = $utente->tipo ?? null;
        $tipoPers = $this->normalizeMailboxTipoPers((int)($utente->tipo_pers ?? 0));
        $selDoc   = $this->getSelectedDoctorId();

        if ($tipo == 3) {
            // Cliente
            $ownerId = (int) ($utente->id_client ?? $userId);
            $dest    = 'C';
        } elseif ($tipo == 2) {
            // Personale
            if (in_array($tipoPers, [2, 3], true) && $selDoc) {
                // Segreteria / infermiera su mailbox del dottore
                $ownerId = (int) $selDoc;
                $dest    = 'S';
            } else {
                // Dottore personale normale
                $ownerId = (int) ($utente->id_personale ?? $userId);
                $dest    = 'P';
            }
        } else {
            // fallback generico
            $ownerId = (int) ($utente->id_user ?? $userId);
            $dest    = 'P';
        }

        return ['userId' => $ownerId, 'dest' => $dest];
    }

public function toggleGestitaMany(array $compoundIds): int
{
    $db       = Database::connect();
    $affected = 0;

    if (empty($compoundIds)) {
        return 0;
    }

    $db->transBegin();

    try {
        foreach ($compoundIds as $cid) {
            // cid è tipo "M:123" o "R:456"
            if (!preg_match('/^(M|R):(\d+)$/', (string)$cid, $m)) {
                continue;
            }

            $src = $m[1];          // 'M' o 'R'
            $id  = (int)$m[2];     // id numerico

            if ($src === 'M') {
                // MAIN: toggle su dap10_message.gestita
                $db->table('dap10_message')
                    ->where('id_message', $id)
                    ->set('gestita', 'IF(gestita = 1, 0, 1)', false) // false = niente escaping
                    ->update();
                $affected += $db->affectedRows();

            } elseif ($src === 'R') {
                // REPLY: toggle su dap10_message_reply.gestita
                $db->table('dap10_message_reply')
                    ->where('id_message', $id)
                    ->set('gestita', 'IF(gestita = 1, 0, 1)', false)
                    ->update();
                $affected += $db->affectedRows();
            }
        }

        if ($db->transStatus() === false) {
            throw new \RuntimeException('Errore transazione toggleGestitaMany');
        }

        $db->transCommit();
        return $affected;

    } catch (\Throwable $e) {
        $db->transRollback();
        log_message('error', 'toggleGestitaMany error: {m}', ['m' => $e->getMessage()]);
        return 0;
    }
}


   public function deleteMany(array $compoundIds, int $userId): int
{
    $db       = Database::connect();
    $affected = 0;
    log_message('error', 'ENTRO IN DELETE');
    if (empty($compoundIds)) {
        return 0;
    }
    log_message('error', 'PASSATO PRIMO IF');

    $db->transBegin();

    try {
        foreach ($compoundIds as $cid) {
                log_message('error', 'ENTRO NEL CICLO');

            // cid è tipo "M:123" oppure "R:456"
            if (!preg_match('/^(M|R):(\d+)$/', (string) $cid, $m)) {
                continue;
            }

            $src   = $m[1];        // 'M' o 'R'
            $idAny = (int) $m[2];  // id numerico

            if ($src === 'M') {
                                log_message('error', 'MESSAGGIO PRINCIPALE');

                // =============================
                // CASO MAIN: dap10_message
                // =============================

                // Verifico che esista il main
                $rowMain = $db->table('dap10_message')
                    ->select('id_message')
                    ->where('id_message', $idAny)
                    ->get(1)->getRowArray();

                if (!$rowMain) {
                    continue;
                }

                // 1) Segno eliminato=1 sul main
                $db->table('dap10_message')
                    ->where('id_message', $idAny)
                    ->set('eliminato', 1)
                    ->update();
                $affected += $db->affectedRows();
  log_message('error', 'ID_EMSSAGE'.$idAny.'E IDUTENTE'.$userId);
                // 2) Segno eliminato=1 in dap10_message_delete per questo utente
                $db->table('dap10_message_delete')
                    ->where('id_message', $idAny)
                    ->where('id_utente', $userId)
                    ->set('eliminato', 1)
                    ->update();
                $affected += $db->affectedRows();

            } elseif ($src === 'R') {

                                            log_message('error', 'MESSAGGIO RISPSOSTA');

                // =============================
                // CASO REPLY: dap10_message_reply
                // =============================

                // Prendo la reply selezionata
                $rowReply = $db->table('dap10_message_reply')
                    ->select('id_message, id_message_ini, dataora')
                    ->where('id_message', $idAny)
                    ->get(1)->getRowArray();

                if (!$rowReply) {
                    continue;
                }

                $rootId  = (int) $rowReply['id_message_ini'];   // id del main
                $refDate = $rowReply['dataora'];                // data della reply scelta

                // Tutte le reply del thread FINO a quella selezionata (compresa)
                $replies = $db->table('dap10_message_reply')
                    ->select('id_message')
                    ->where('id_message_ini', $rootId)
                    ->where('dataora <=', $refDate)
                    ->orderBy('dataora', 'ASC')
                    ->get()->getResultArray();

                if (empty($replies)) {
                    continue;
                }

                $replyIds = array_column($replies, 'id_message');

                // 1) Segno eliminato=1 in dap10_message_reply
                $db->table('dap10_message_reply')
                    ->whereIn('id_message', $replyIds)
                    ->set('eliminato', 1)
                    ->update();
                $affected += $db->affectedRows();

                // 2) Segno eliminato=1 in dap10_message_reply_delete per questo utente
                $db->table('dap10_message_reply_delete')
                    ->whereIn('id_message', $replyIds)
                    ->where('id_utente', $userId)
                    ->set('eliminato', 1)
                    ->update();
                $affected += $db->affectedRows();
            }
        }

        if ($db->transStatus() === false) {
            throw new \RuntimeException('Errore transazione deleteMany');
        }

        $db->transCommit();
        return $affected;

    } catch (\Throwable $e) {
        $db->transRollback();
        log_message('error', 'deleteMany error: {m}', ['m' => $e->getMessage()]);
        return 0;
    }
}



    /* ==========================================================
     *                      INBOX LATEST
     * ========================================================== */

    /**
     * Inbox: un record per thread, contenente l'ultimo messaggio visibile all'utente.
     * Ricerca e paginazione su campi decrittati, mittente dinamico (clients/personale).
     *
     * $isDoctor:
     *  - false -> comportamento standard (cliente / personale)
     *  - true  -> segreteria/infermiera che guarda mailbox del dottore selezionato
     */
   public function getInboxLatest(int $userId, array $filters = [], int $perPage = 25, $isDoctor): array
{
    $db            = $this->db;
    $crypto_helper = new Crypto_helper();

    $utente = $this->getSessionUser();
    $dest   = 'P'; // default

    // 1) Mappa utente corrente -> userId reale e "dest" solo se NON sto aprendo come dottore dalla segreteria
    if ($utente && !$isDoctor) {
        $tipo = $utente->tipo ?? null;
        if ($tipo == 3) {                      // cliente
            $userId = (int) ($utente->id_client ?? 0);
            $dest   = 'C';
        } elseif ($tipo == 2) {                // personale
            $userId = (int) ($utente->id_personale ?? 0);
            $dest   = 'P';
        } else {
            $userId = (int) ($utente->id_user ?? $userId);
        }
    }

    // Se sto aprendo la posta della segreteria per un dottore selezionato: dest logico = 'S'
    if ($isDoctor) {
        $dest = 'S';
    }

    log_message('error', 'getInboxLatest START userId={u}, dest={d}, isDoctor={doc}', [
        'u'   => $userId,
        'd'   => $dest,
        'doc' => $isDoctor ? 1 : 0,
    ]);

    // 2) Stringhe di decrypt
    $DEC_C_NOME      = $crypto_helper->decrypt_concat('c.nome');
    $DEC_C_COGNOME   = $crypto_helper->decrypt_concat('c.cognome');
    $DEC_P_NOME      = $crypto_helper->decrypt_concat('p.nome');
    $DEC_P_COGNOME   = $crypto_helper->decrypt_concat('p.cognome');
    $DEC_P_QUALIFICA = $crypto_helper->decrypt_concat('p.qualifica');
    $DEC_M_OGGETTO   = $crypto_helper->decrypt_concat('m.oggetto');
    $DEC_M_TESTO     = $crypto_helper->decrypt_concat('m.testo');
    $DEC_R_OGGETTO   = $crypto_helper->decrypt_concat('r.oggetto');
    $DEC_R_TESTO     = $crypto_helper->decrypt_concat('r.testo');

    // CASE per data "umana" (main)
    $CASE_REL_TIME_M = "
        CASE
            WHEN TIMESTAMPDIFF(SECOND, m.dataora, NOW()) < 60 THEN 'pochi secondi fa'
            WHEN TIMESTAMPDIFF(SECOND, m.dataora, NOW()) < 120 THEN '1 minuto fa'
            WHEN TIMESTAMPDIFF(SECOND, m.dataora, NOW()) < 3600 THEN CONCAT(FLOOR(TIMESTAMPDIFF(SECOND, m.dataora, NOW())/60), ' minuti fa')
            WHEN TIMESTAMPDIFF(SECOND, m.dataora, NOW()) < 7200 THEN '1 ora fa'
            WHEN TIMESTAMPDIFF(SECOND, m.dataora, NOW()) < 86400 THEN CONCAT(FLOOR(TIMESTAMPDIFF(SECOND, m.dataora, NOW())/3600), ' ore fa')
            WHEN TIMESTAMPDIFF(SECOND, m.dataora, NOW()) < 172800 THEN 'ieri'
            WHEN TIMESTAMPDIFF(SECOND, m.dataora, NOW()) < 604800 THEN CONCAT(FLOOR(TIMESTAMPDIFF(SECOND, m.dataora, NOW())/86400), ' giorni fa')
            WHEN TIMESTAMPDIFF(WEEK, m.dataora, NOW()) = 1 THEN '1 settimana fa'
            WHEN TIMESTAMPDIFF(SECOND, m.dataora, NOW()) < 2592000 THEN CONCAT(TIMESTAMPDIFF(WEEK, m.dataora, NOW()), ' settimane fa')
            WHEN TIMESTAMPDIFF(MONTH, m.dataora, NOW()) = 1 THEN '1 mese fa'
            WHEN TIMESTAMPDIFF(SECOND, m.dataora, NOW()) < 31536000 THEN CONCAT(TIMESTAMPDIFF(MONTH, m.dataora, NOW()), ' mesi fa')
            WHEN TIMESTAMPDIFF(YEAR, m.dataora, NOW()) = 1 THEN '1 anno fa'
            ELSE CONCAT(TIMESTAMPDIFF(YEAR, m.dataora, NOW()), ' anni fa')
        END
    ";
    // stessa cosa per reply
    $CASE_REL_TIME_R = str_replace('m.dataora', 'r.dataora', $CASE_REL_TIME_M);

    // 🔹 filtro gestita lato SQL (0/1) – SOLO dap10_message.gestita
    $gestitaFilterRaw = $filters['gestita'] ?? null;
    $gestitaFilter    = null;
    if ($gestitaFilterRaw === 0 || $gestitaFilterRaw === '0') {
        $gestitaFilter = 0;
    } elseif ($gestitaFilterRaw === 1 || $gestitaFilterRaw === '1') {
        $gestitaFilter = 1;
    }

    $filterGestitaMain  = '';
    $filterGestitaReply = '';
    if ($gestitaFilter !== null) {
        $g = (int) $gestitaFilter;
        $filterGestitaMain  = " AND m.gestita = {$g} ";
        $filterGestitaReply = " AND m0.gestita = {$g} ";
    }

    // 3) Parametri paginazione e ricerca
    $request = service('request');

    $perPage = max(1, (int) ($perPage ?? 25));
    $page    = max(1, (int) ($request->getGet('page') ?? 1));
    $offset  = ($page - 1) * $perPage;

    $q = trim((string) ($filters['q'] ?? $request->getGet('q') ?? ''));
    if ($q !== '') {
        $filterGestitaMain  = '';
        $filterGestitaReply = '';
    }

    // Condizioni dinamiche per thread (MAIN/REPLY) – SENZA gestita (che aggiungiamo dopo)
    if ($isDoctor) {
        // segreteria che guarda la posta di un dottore selezionato
        $condLastM = "
            COALESCE(md.eliminato, 0) = 0
            AND m.draft   = 0
            AND m.id_dest = {$userId}
            AND m.dest    = 'S'
            AND m.seg_flag = 1
        ";
        $condLastR = "
            COALESCE(rd.eliminato, 0) = 0
            AND r.draft   = 0
            AND r.id_dest = {$userId}
            AND r.dest    = 'S'
            AND r.seg_flag = 1
        ";
    } else {
        // comportamento standard
        $condLastM = "
            COALESCE(md.eliminato, 0) = 0
            AND m.draft   = 0
            AND m.id_dest = {$userId}
            AND m.dest    = '{$dest}'
        ";
        $condLastR = "
            COALESCE(rd.eliminato, 0) = 0
            AND r.draft   = 0
            AND r.id_dest = {$userId}
            AND r.dest    = '{$dest}'
        ";
    }

    // ------ STEP 1: costruisco la lista THREAD (id + max_dataora) ------

    $sqlThreadsBase = "
        SELECT
            x.thread_id,
            MAX(x.dataora) AS max_dataora
        FROM (
            -- MAIN
            SELECT
                m.id_message AS thread_id,
                m.dataora
            FROM dap10_message m
            LEFT JOIN dap10_message_delete md
                ON md.id_message = m.id_message
               AND md.id_utente  = {$userId}
            WHERE {$condLastM} {$filterGestitaMain}

            UNION ALL

            -- REPLY (join su main per filtrare/sapere gestita)
            SELECT
                r.id_message_ini AS thread_id,
                r.dataora
            FROM dap10_message_reply r
            JOIN dap10_message m0
                ON m0.id_message = r.id_message_ini
            LEFT JOIN dap10_message_reply_delete rd
                ON rd.id_message = r.id_message
               AND rd.id_utente  = {$userId}
            WHERE {$condLastR} {$filterGestitaReply}
        ) AS x
        GROUP BY x.thread_id
        ORDER BY max_dataora DESC
    ";

    // ⭐ paginazione SQL SEMPRE quando q è vuoto (anche con gestita)
    $useDbPagination = ($q === '');

    $threadsSql = $sqlThreadsBase;
    if ($useDbPagination) {
        $threadsSql .= " LIMIT {$perPage} OFFSET {$offset}";
    }

    log_message('error', 'getInboxLatest THREADS SQL = {sql}', ['sql' => $threadsSql]);
    $threads = $db->query($threadsSql)->getResultArray();

    if (empty($threads)) {
        log_message('error', 'getInboxLatest: nessun thread trovato per userId={u}', ['u' => $userId]);
        return [
            'data'    => [],
            'start'   => 0,
            'end'     => 0,
            'total'   => 0,
            'page'    => $page,
            'perPage' => $perPage,
        ];
    }

    // ------ STEP 1b: COUNT totale thread (rispetta già gestita) ------

    $totalThreads = 0;
    if ($useDbPagination) {
        $sqlCount = "
            SELECT COUNT(*) AS c
            FROM (
                SELECT x.thread_id
                FROM (
                    SELECT m.id_message AS thread_id, m.dataora
                    FROM dap10_message m
                    LEFT JOIN dap10_message_delete md
                        ON md.id_message = m.id_message
                       AND md.id_utente  = {$userId}
                    WHERE {$condLastM} {$filterGestitaMain}

                    UNION ALL

                    SELECT r.id_message_ini AS thread_id, r.dataora
                    FROM dap10_message_reply r
                    JOIN dap10_message m0
                        ON m0.id_message = r.id_message_ini
                    LEFT JOIN dap10_message_reply_delete rd
                        ON rd.id_message = r.id_message
                       AND rd.id_utente  = {$userId}
                    WHERE {$condLastR} {$filterGestitaReply}
                ) AS x
                GROUP BY x.thread_id
            ) AS t
        ";
        //log_message('error', 'getInboxLatest COUNT SQL = {sql}', ['sql' => $sqlCount]);
        $totalThreads = (int) $db->query($sqlCount)->getRow('c');
    }

    // ------ STEP 2: per ogni thread, prendo l'ULTIMO messaggio ------

    $rows = [];

    $fetchLast = function (int $threadId) use (
        $db,
        $userId,
        $dest,
        $DEC_C_NOME,
        $DEC_C_COGNOME,
        $DEC_P_NOME,
        $DEC_P_COGNOME,
        $DEC_P_QUALIFICA,
        $DEC_M_OGGETTO,
        $DEC_M_TESTO,
        $DEC_R_OGGETTO,
        $DEC_R_TESTO,
        $CASE_REL_TIME_M,
        $CASE_REL_TIME_R
    ) {
        // 2a) ultima REPLY (incluso gestita dal main m0)
        $sqlReply = "
            SELECT
                r.id_message        AS uid,
                r.id_message_ini    AS thread_id,
                'R'                 AS src,
                r.id_mitt,
                r.id_dest,
                r.mitt,
                r.dest,
                r.dataora,
                r.letto,
                r.da_dottore,
                r.email,
                r.seg_flag,
                r.draft,
                r.dot_seg,
                r.dot_inf,
                r.inoltrato,
                CAST({$DEC_C_NOME}      AS CHAR) AS dec_c_nome,
                CAST({$DEC_C_COGNOME}   AS CHAR) AS dec_c_cognome,
                CAST({$DEC_P_NOME}      AS CHAR) AS dec_p_nome,
                CAST({$DEC_P_COGNOME}   AS CHAR) AS dec_p_cognome,
                CAST({$DEC_P_QUALIFICA} AS CHAR) AS dec_p_qualifica,
                CAST({$DEC_R_OGGETTO}   AS CHAR) AS oggetto,
                CAST({$DEC_R_TESTO}     AS CHAR) AS testo,
                {$CASE_REL_TIME_R}              AS dataora_human,
                m0.gestita                      AS gestita_thread
            FROM dap10_message_reply r
            JOIN dap10_message m0
                ON m0.id_message = r.id_message_ini
            LEFT JOIN dap10_message_reply_delete rd
                ON rd.id_message = r.id_message
               AND rd.id_utente  = {$userId}
            LEFT JOIN dap02_clients c
                ON c.id_client = r.id_mitt
               AND r.mitt      = 'C'
            LEFT JOIN dap03_personale p
                ON p.id_personale = r.id_mitt
               AND r.mitt IN ('P','I','S')
            WHERE
                COALESCE(rd.eliminato, 0) = 0
                AND r.draft = 0
                AND r.id_dest = {$userId}
                AND r.dest    = '{$dest}'
                AND r.id_message_ini = {$threadId}
            ORDER BY r.dataora DESC
            LIMIT 1
        ";

       /* log_message('error', 'getInboxLatest REPLY SQL (thread {t}) = {sql}', [
            't'   => $threadId,
            'sql' => $sqlReply,
        ]);*/

        $rowReply = $db->query($sqlReply)->getRowArray();
        if ($rowReply) {
            return $rowReply;
        }

        // 2b) altrimenti MAIN (gestita = m.gestita)
        $sqlMain = "
            SELECT
                m.id_message        AS uid,
                m.id_message        AS thread_id,
                'M'                 AS src,
                m.id_mitt,
                m.id_dest,
                m.mitt,
                m.dest,
                m.dataora,
                m.letto,
                m.da_dottore,
                m.email,
                m.seg_flag,
                m.draft,
                m.dot_seg,
                m.dot_inf,
                m.inoltrato,
                CAST({$DEC_C_NOME}      AS CHAR) AS dec_c_nome,
                CAST({$DEC_C_COGNOME}   AS CHAR) AS dec_c_cognome,
                CAST({$DEC_P_NOME}      AS CHAR) AS dec_p_nome,
                CAST({$DEC_P_COGNOME}   AS CHAR) AS dec_p_cognome,
                CAST({$DEC_P_QUALIFICA} AS CHAR) AS dec_p_qualifica,
                CAST({$DEC_M_OGGETTO}   AS CHAR) AS oggetto,
                CAST({$DEC_M_TESTO}     AS CHAR) AS testo,
                {$CASE_REL_TIME_M}              AS dataora_human,
                m.gestita                       AS gestita_thread
            FROM dap10_message m
            LEFT JOIN dap10_message_delete md
                ON md.id_message = m.id_message
               AND md.id_utente  = {$userId}
            LEFT JOIN dap02_clients c
                ON c.id_client = m.id_mitt
               AND m.mitt      = 'C'
            LEFT JOIN dap03_personale p
                ON p.id_personale = m.id_mitt
               AND m.mitt IN ('P','I','S')
            WHERE
                COALESCE(md.eliminato, 0) = 0
                AND m.draft   = 0
                AND m.id_dest = {$userId}
                AND m.dest    = '{$dest}'
                AND m.id_message = {$threadId}
            LIMIT 1
        ";

     log_message('error', 'getInboxLatest MAIN SQL (thread {t}) = {sql}', [
            't'   => $threadId,
            'sql' => $sqlMain,
        ]);

        $rowMain = $db->query($sqlMain)->getRowArray();
        return $rowMain ?: null;
    };

    // Eseguo le query per ogni thread (ordine già per max_dataora DESC)
    foreach ($threads as $t) {
        $threadId = (int) ($t['thread_id'] ?? 0);
        if ($threadId <= 0) {
            continue;
        }

        $row = $fetchLast($threadId);
        if (!$row) {
            continue;
        }

        // Mittente finale
        $mitt             = $row['mitt'] ?? '';
        $dec_c_nome       = trim($row['dec_c_nome'] ?? '');
        $dec_c_cognome    = trim($row['dec_c_cognome'] ?? '');
        $dec_p_nome       = trim($row['dec_p_nome'] ?? '');
        $dec_p_cognome    = trim($row['dec_p_cognome'] ?? '');
        $dec_p_qualifica  = trim($row['dec_p_qualifica'] ?? '');
        $mittente_nome    = '';
        $mittente_cognome = '';
        $mittente_qualifica = null;

        if ($mitt === 'C') {
            $mittente_nome    = $dec_c_nome;
            $mittente_cognome = $dec_c_cognome;
        } elseif (in_array($mitt, ['P', 'I', 'S'], true)) {
            $mittente_nome     = $dec_p_nome;
            $mittente_cognome  = $dec_p_cognome;
            $mittente_qualifica = $dec_p_qualifica;
        }

        // stato gestita dal main (m.gestita / m0.gestita)
        $gestitaThread = (int) ($row['gestita_thread'] ?? 0);

        $out = [
            'thread_id'          => (int) $row['thread_id'],
            'uid'                => (int) $row['uid'],
            'src'                => $row['src'],
            'mittente'           => $mitt,
            'id_mitt'            => (int) $row['id_mitt'],
            'id_dest'            => (int) $row['id_dest'],
            'mittente_nome'      => $mittente_nome,
            'mittente_cognome'   => $mittente_cognome,
            'mittente_qualifica' => $mittente_qualifica,
            'oggetto'            => $row['oggetto'] ?? '',
            'testo'              => $row['testo'] ?? '',
            'dataora_scritto'    => $row['dataora'] ?? '',
            'dataora'            => $row['dataora_human'] ?? '',
            'letto'              => (int) ($row['letto'] ?? 0),
            'da_dottore'         => (int) ($row['da_dottore'] ?? 0),
            'email'              => $row['email'] ?? '',
            'gestita'            => $gestitaThread,
            'inoltrato'          => $row['inoltrato'] ?? '',
        ];

        if ($mitt === 'C') {
            $out = $this->applySystemClientMaskToNameParts($out, (int)($row['id_mitt'] ?? 0));
        }

        $rows[] = $out;
    }

    log_message('error', 'getInboxLatest: rows BEFORE text filter = {n}', ['n' => count($rows)]);

    // ------ STEP 3: filtro ricerca testuale (solo q, gestita è già filtrata in SQL) ------

    if ($q !== '') {
        $qLower = mb_strtolower($q, 'UTF-8');
        $rows = array_values(array_filter($rows, function ($r) use ($qLower) {
            $fields = [
                (string) ($r['oggetto'] ?? ''),
                (string) ($r['testo'] ?? ''),
                (string) ($r['mittente_nome'] ?? ''),
                (string) ($r['mittente_cognome'] ?? ''),
            ];
            foreach ($fields as $f) {
                if ($f !== '' && mb_stripos($f, $qLower, 0, 'UTF-8') !== false) {
                    return true;
                }
            }
            return false;
        }));
        log_message('error', 'getInboxLatest: rows AFTER text filter = {n}', ['n' => count($rows)]);

        // in modalità ricerca, totale = dopo filtro
        $totalThreads = count($rows);
    }

    // ------ STEP 4: paginazione finale + start/end ------

    if ($useDbPagination) {
        // Caso senza ricerca (q vuoto): paginazione già fatta in SQL
        $start = $totalThreads ? $offset + 1 : 0;
        $end   = min($offset + count($rows), $totalThreads);
    } else {
        // Caso con ricerca testo: paginazione lato PHP
        $page    = max(1, (int) ($request->getGet('page') ?? 1));
        $perPage = max(1, (int) ($perPage ?? 25));
        $offset  = ($page - 1) * $perPage;

        if ($offset >= $totalThreads) {
            $page   = 1;
            $offset = 0;
        }

        $rows = array_slice($rows, $offset, $perPage);

        $start = $totalThreads ? $offset + 1 : 0;
        $end   = min($offset + count($rows), $totalThreads);
    }

    return [
        'data'    => $rows,
        'start'   => $start,
        'end'     => $end,
        'total'   => $totalThreads,
        'page'    => $page,
        'perPage' => $perPage,
    ];
}

    /**
     * Lista dei messaggi inviati dall'owner della mailbox.
     *
     * Ritorna un array con:
     *  - data      => righe
     *  - start/end => range corrente
     *  - total     => totale
     *  - page      => pagina corrente
     *  - perPage   => righe per pagina
     *
     * NB: non usa la logica complessa di thread come l'inbox, ma
     *     è sufficiente per una vista "Posta inviata" classica.
     */
   public function getSentLatest(int $userId, array $filters = [], int $perPage = 25): array
{
    $db            = $this->db;
    $crypto_helper = new Crypto_helper();
    $request       = service('request');

    $ownerId = $this->resolveMailboxOwnerId($userId);

    // Paginazione
    $perPage = max(1, (int)($perPage ?? 25));
    $page    = max(1, (int)($filters['page'] ?? $request->getGet('page') ?? 1));
    $offset  = ($page - 1) * $perPage;

    // Filtro testo
    $q = trim((string)($filters['q'] ?? $request->getGet('q') ?? ''));

    // Decrypt pieces
    $DEC_C_NOME      = $crypto_helper->decrypt_concat('c.nome');
    $DEC_C_COGNOME   = $crypto_helper->decrypt_concat('c.cognome');
    $DEC_P_NOME      = $crypto_helper->decrypt_concat('p.nome');
    $DEC_P_COGNOME   = $crypto_helper->decrypt_concat('p.cognome');
    $DEC_P_QUALIFICA = $crypto_helper->decrypt_concat('p.qualifica');
    $DEC_M_OGGETTO   = $crypto_helper->decrypt_concat('m.oggetto');
    $DEC_M_TESTO     = $crypto_helper->decrypt_concat('m.testo');
    $DEC_R_OGGETTO   = $crypto_helper->decrypt_concat('r.oggetto');
    $DEC_R_TESTO     = $crypto_helper->decrypt_concat('r.testo');

    /* ===============================
     * 1) MAIN: dap10_message (src = 'M')
     * =============================== */
    $sqlMain = "
        SELECT
            'M' AS src,
            m.id_message        AS uid,
            m.id_message        AS id_message,
            m.id_mitt,
            m.id_dest,
            m.mitt,
            m.dest,
            {$DEC_M_OGGETTO}    AS oggetto,
            {$DEC_M_TESTO}      AS testo,
            m.dataora           AS dataora,
            COALESCE(m.letto,0)     AS letto,
            COALESCE(m.seg_flag,0)  AS is_starred,

            CASE
                WHEN m.dest = 'C' THEN {$DEC_C_NOME}
                ELSE {$DEC_P_NOME}
            END AS destinatario_nome,
            CASE
                WHEN m.dest = 'C' THEN {$DEC_C_COGNOME}
                ELSE {$DEC_P_COGNOME}
            END AS destinatario_cognome,
            CASE
                WHEN m.dest = 'C' THEN ''
                ELSE {$DEC_P_QUALIFICA}
            END AS destinatario_qualifica
        FROM dap10_message m
        LEFT JOIN dap10_message_delete md
            ON md.id_message = m.id_message
           AND md.id_utente  = ?
        LEFT JOIN dap02_clients c
            ON c.id_client = m.id_dest
        LEFT JOIN dap03_personale p
            ON p.id_personale = m.id_dest
        WHERE
            m.id_mitt = ?
            AND COALESCE(md.eliminato,0) = 0
            AND COALESCE(m.draft,0) = 0
    ";

    $bindMain = [$ownerId, $ownerId];

    if ($q !== '') {
        // NB: metto il LIKE con bind, e lo applico alle espressioni decrypt (che sono SQL)
        $sqlMain .= "
            AND (
                {$DEC_M_OGGETTO} LIKE ?
                OR {$DEC_M_TESTO} LIKE ?
                OR CAST((CASE WHEN m.dest = 'C' THEN {$DEC_C_NOME} ELSE {$DEC_P_NOME} END) AS CHAR) LIKE ?
                OR CAST((CASE WHEN m.dest = 'C' THEN {$DEC_C_COGNOME} ELSE {$DEC_P_COGNOME} END) AS CHAR) LIKE ?
                OR CONCAT(
                    CAST((CASE WHEN m.dest = 'C' THEN {$DEC_C_NOME} ELSE {$DEC_P_NOME} END) AS CHAR),
                    ' ',
                    CAST((CASE WHEN m.dest = 'C' THEN {$DEC_C_COGNOME} ELSE {$DEC_P_COGNOME} END) AS CHAR)
                ) LIKE ?
                OR CONCAT(
                    CAST((CASE WHEN m.dest = 'C' THEN {$DEC_C_COGNOME} ELSE {$DEC_P_COGNOME} END) AS CHAR),
                    ' ',
                    CAST((CASE WHEN m.dest = 'C' THEN {$DEC_C_NOME} ELSE {$DEC_P_NOME} END) AS CHAR)
                ) LIKE ?
            )
        ";
        $like = '%' . $q . '%';
        array_push($bindMain, $like, $like, $like, $like, $like, $like);
    }

    // log se vuoi
    // log_message('error', $sqlMain);

    $rowsMain = $db->query($sqlMain, $bindMain)->getResultArray();

    /* =====================================
     * 2) REPLY: dap10_message_reply (src='R')
     * ===================================== */
    $sqlReply = "
        SELECT
            'R' AS src,
            r.id_message        AS uid,
            r.id_message_ini    AS id_message,
            r.id_mitt,
            r.id_dest,
            r.mitt,
            r.dest,
            {$DEC_R_OGGETTO}    AS oggetto,
            {$DEC_R_TESTO}      AS testo,
            r.dataora           AS dataora,
            COALESCE(r.letto,0)    AS letto,
            COALESCE(r.seg_flag,0) AS is_starred,

            CASE
                WHEN r.dest = 'C' THEN {$DEC_C_NOME}
                ELSE {$DEC_P_NOME}
            END AS destinatario_nome,
            CASE
                WHEN r.dest = 'C' THEN {$DEC_C_COGNOME}
                ELSE {$DEC_P_COGNOME}
            END AS destinatario_cognome,
            CASE
                WHEN r.dest = 'C' THEN ''
                ELSE {$DEC_P_QUALIFICA}
            END AS destinatario_qualifica
        FROM dap10_message_reply r
        LEFT JOIN dap10_message_reply_delete rd
            ON rd.id_message = r.id_message
           AND rd.id_utente  = ?
        LEFT JOIN dap02_clients c
            ON c.id_client = r.id_dest
        LEFT JOIN dap03_personale p
            ON p.id_personale = r.id_dest
        WHERE
            r.id_mitt = ?
            AND COALESCE(rd.eliminato,0) = 0
            AND COALESCE(r.draft,0) = 0
    ";

    $bindReply = [$ownerId, $ownerId];

    if ($q !== '') {
        $sqlReply .= "
            AND (
                {$DEC_R_OGGETTO} LIKE ?
                OR {$DEC_R_TESTO} LIKE ?
                OR CAST((CASE WHEN r.dest = 'C' THEN {$DEC_C_NOME} ELSE {$DEC_P_NOME} END) AS CHAR) LIKE ?
                OR CAST((CASE WHEN r.dest = 'C' THEN {$DEC_C_COGNOME} ELSE {$DEC_P_COGNOME} END) AS CHAR) LIKE ?
                OR CONCAT(
                    CAST((CASE WHEN r.dest = 'C' THEN {$DEC_C_NOME} ELSE {$DEC_P_NOME} END) AS CHAR),
                    ' ',
                    CAST((CASE WHEN r.dest = 'C' THEN {$DEC_C_COGNOME} ELSE {$DEC_P_COGNOME} END) AS CHAR)
                ) LIKE ?
                OR CONCAT(
                    CAST((CASE WHEN r.dest = 'C' THEN {$DEC_C_COGNOME} ELSE {$DEC_P_COGNOME} END) AS CHAR),
                    ' ',
                    CAST((CASE WHEN r.dest = 'C' THEN {$DEC_C_NOME} ELSE {$DEC_P_NOME} END) AS CHAR)
                ) LIKE ?
            )
        ";
        $like = '%' . $q . '%';
        array_push($bindReply, $like, $like, $like, $like, $like, $like);
    }

    $rowsReply = $db->query($sqlReply, $bindReply)->getResultArray();

    /* ==========================
     * 3) Merge + sort + paginate
     * ========================== */
    $rows = array_merge($rowsMain, $rowsReply);

    usort($rows, function ($a, $b) {
        return strcmp($b['dataora'] ?? '', $a['dataora'] ?? '');
    });

    $total    = count($rows);
    $pageRows = array_slice($rows, $offset, $perPage);

    // Rimappo destinatario_* su mittente_* per UI
    foreach ($pageRows as &$row) {
        $row['mittente_nome']      = $row['destinatario_nome']      ?? '';
        $row['mittente_cognome']   = $row['destinatario_cognome']   ?? '';
        $row['mittente_qualifica'] = $row['destinatario_qualifica'] ?? '';
        if (($row['dest'] ?? '') === 'C') {
            $row = $this->applySystemClientMaskToNameParts($row, (int)($row['id_dest'] ?? 0));
        }
    }
    unset($row);

    return [
        'data'    => $pageRows,
        'start'   => $total > 0 ? $offset + 1 : 0,
        'end'     => min($offset + $perPage, $total),
        'total'   => $total,
        'page'    => $page,
        'perPage' => $perPage,
    ];
}





/**
 * Ritorna lo stato "gestita" per un thread:
 * - legge dap10_message.gestita (main)
 * - se esiste un record in dap20 per id_message_ini + id_utente,
 *   usa quello (ha la precedenza).
 *
 * @param int $threadId id_message del main (id_message_ini)
 * @param int $userId   proprietario mailbox (id_personale / id_client ecc.)
 */
/**
 * Calcola lo stato "gestita" partendo da un id che può essere:
 * - id_message (MAIN) in dap10_message
 * - id_message (REPLY) in dap10_message_reply
 *
 * Logica:
 *  - Se è MAIN: uso solo dap10_message.gestita
 *  - Se è REPLY: prendo r.gestita e m.gestita (main) e faccio OR logico
 */
/**
 * Calcola lo stato "gestita" partendo da un id che può essere:
 * - id_message (MAIN) in dap10_message
 * - id_message (REPLY) in dap10_message_reply
 *
 * Logica:
 *  - Se è MAIN: uso dap10_message.gestita
 *  - Se è REPLY: risalgo a id_message_ini e uso dap10_message.gestita del main
 */
protected function getGestitaForThread(int $idAny): int
{
    $db = Database::connect();

    // 1) Provo come MAIN
    $rowMain = $db->table('dap10_message')
        ->select('id_message, gestita')
        ->where('id_message', $idAny)
        ->get(1)
        ->getRowArray();

    if ($rowMain) {
        // È un main: ritorno il suo gestita
        return (int)($rowMain['gestita'] ?? 0);
    }

    // 2) Non è main → provo come REPLY
    $rowReply = $db->table('dap10_message_reply')
        ->select('id_message_ini')
        ->where('id_message', $idAny)
        ->get(1)
        ->getRowArray();

    if (!$rowReply) {
        // id non trovato né in main né in reply → considero non gestita
        return 0;
    }

    $rootId = (int)$rowReply['id_message_ini'];

    // 3) Leggo gestita dal MAIN del thread
    $rowMainRoot = $db->table('dap10_message')
        ->select('gestita')
        ->where('id_message', $rootId)
        ->get(1)
        ->getRowArray();

    return (int)($rowMainRoot['gestita'] ?? 0);
}





    /* ==========================================================
     *                      COUNT UNREAD
     * ========================================================== */

    /**
     * Conta i non letti (rispettando eliminazioni per-utente e draft).
     * Se segreteria/infermiera ha selezionato un dottore, conta i non letti
     * della mailbox del dottore (dest = 'S').
     */
    public function countUnread(int $userId): int
    {
        $db = $this->db;

        $ctx   = $this->resolveUnreadContext($userId);
        $uid   = $ctx['userId'];
        $dest  = $ctx['dest'];

        $sql = "
            SELECT
                (
                  SELECT COUNT(*)
                  FROM dap10_message m
                  LEFT JOIN dap10_message_delete md
                         ON md.id_message = m.id_message AND md.id_utente = :uid:
                  WHERE COALESCE(md.eliminato,0) = 0
                    AND m.draft = 0
                    AND m.id_dest = :uid:
                    AND m.dest = :dest:
                    AND COALESCE(m.letto,0) = 0
                )
              + (
                  SELECT COUNT(*)
                  FROM dap10_message_reply r
                  LEFT JOIN dap10_message_reply_delete rd
                         ON rd.id_message = r.id_message AND rd.id_utente = :uid:
                  WHERE COALESCE(rd.eliminato,0) = 0
                    AND r.draft = 0
                    AND r.id_dest = :uid:
                    AND r.dest = :dest:
                    AND COALESCE(r.letto,0) = 0
                ) AS c
        ";
        return (int) $db->query($sql, ['uid' => $uid, 'dest' => $dest])->getRow('c');
    }

    /* ==========================================================
     *                      TOGGLE STAR
     * ========================================================== */

    /**
     * Toggle segnalibro (seg_flag) su M/R del destinatario corrente.
     * Se segreteria/infermiera + dottore selezionato -> usa id del dottore.
     */
    public function toggleStar(string $src, int $idMessage, int $userId): bool
    {
        $db  = $this->db;
        $uid = $this->resolveMailboxOwnerId($userId);

        $tab = ($src === 'R') ? 'dap10_message_reply' : 'dap10_message';

        $row = $db->query(
            "SELECT seg_flag FROM {$tab} WHERE id_message = ? AND id_dest = ? AND COALESCE(eliminato,0)=0",
            [$idMessage, $uid]
        )->getRowArray();
        if (!$row) return false;

        $new = (int)!((int)($row['seg_flag'] ?? 0));
        $db->query("UPDATE {$tab} SET seg_flag = ? WHERE id_message = ? AND id_dest = ?", [$new, $idMessage, $uid]);

        return $db->affectedRows() > 0;
    }

    /* ==========================================================
     *                      DELETE MANY
     * ========================================================== */

    /**
     * Sposta nel cestino (per-utente) una lista di messaggi 'M:123' / 'R:456'.
     * Anche qui, se segreteria/infermiera con dottore selezionato, usa id del dottore.
     */
   

    /* ==========================================================
     *                      GET ONE (READ VIEW)
     * ========================================================== */

    /**
     * Ritorna un messaggio singolo (read view) con decrypt via Crypto_helper.
     * Usa l'id "owner" della mailbox (dottore se segreteria/infermiera con dottore selezionato).
     */
   public function getOne(string $box, string $src, int $idMessage, int $userId): ?array
{
    $db            = $this->db;
    $crypto_helper = new Crypto_helper();

    $uid = $this->resolveMailboxOwnerId($userId);

    // Normalizza box
    $box = in_array($box, ['inbox','sent'], true) ? $box : 'inbox';

    $isReply = ($src === 'R');
    $tab     = $isReply ? 'dap10_message_reply' : 'dap10_message';
    $tabDel  = $isReply ? 'dap10_message_reply_delete' : 'dap10_message_delete';

    // lato mailbox
    $whereSide = ($box === 'sent') ? 'a.id_mitt = ?' : 'a.id_dest = ?';

    // thread id (serve in read() per rootId)
    $selectThread = $isReply ? "a.id_message_ini AS id_message_ini," : "NULL AS id_message_ini,";

    // decrypt
    $DEC_TESTO   = $crypto_helper->decrypt('a.testo',   'testo');
    $DEC_OGGETTO = $crypto_helper->decrypt('a.oggetto', 'oggetto');

    $DEC_C_NOME      = $crypto_helper->decrypt_concat('c.nome');
    $DEC_C_COGNOME   = $crypto_helper->decrypt_concat('c.cognome');
    $DEC_P_NOME      = $crypto_helper->decrypt_concat('p.nome');
    $DEC_P_COGNOME   = $crypto_helper->decrypt_concat('p.cognome');
    $DEC_P_QUALIFICA = $crypto_helper->decrypt_concat('p.qualifica');

    $sql = "
        SELECT
            a.id_message,
            {$selectThread}
            a.id_mitt,
            a.id_dest,
            '{$box}' AS box,

            {$DEC_OGGETTO},
            {$DEC_TESTO},

            a.dataora,
            COALESCE(a.letto,0)     AS letto,
            COALESCE(a.seg_flag,0)  AS seg_flag,
            COALESCE(a.inf_flag,0)  AS inf_flag,
            COALESCE(a.draft,0)     AS draft,
            COALESCE(a.inoltrato,0) AS inoltrato,
            COALESCE(a.dot_seg,0)   AS dot_seg,
            COALESCE(a.dot_inf,0)   AS dot_inf,
            a.vector_id,
            a.da_dottore,
            a.mitt,
            a.dest,
            a.email,

            -- Mittente (tua logica)
            CASE
                WHEN a.mitt = 'C' THEN CONCAT(
                    CAST({$DEC_C_COGNOME} AS CHAR), ' ',
                    CAST({$DEC_C_NOME}    AS CHAR)
                )
                WHEN a.mitt = 'S' THEN CONCAT(
                    'Da parte della Segreteria per conto di ',
                    CAST({$DEC_P_COGNOME} AS CHAR), ' ',
                    CAST({$DEC_P_NOME}    AS CHAR)
                )
                WHEN a.mitt = 'I' THEN CONCAT(
                    'Da parte dell''infermiere per conto di ',
                    CAST({$DEC_P_COGNOME} AS CHAR), ' ',
                    CAST({$DEC_P_NOME}    AS CHAR)
                )
                WHEN a.mitt = 'P'
                     AND COALESCE(a.dot_seg,0) = 0
                     AND COALESCE(a.dot_inf,0) = 0
                THEN CONCAT(
                    CAST({$DEC_P_COGNOME} AS CHAR), ' ',
                    CAST({$DEC_P_NOME}    AS CHAR)
                )
                WHEN a.mitt = 'P'
                     AND (COALESCE(a.dot_seg,0) = 1 OR COALESCE(a.dot_inf,0) = 1)
                THEN CONCAT(
                    'Da parte del medico per conto di ',
                    CAST(AES_DECRYPT(UNHEX(cl.cognome), @key_str, cl.vector_id) AS CHAR), ' ',
                    CAST(AES_DECRYPT(UNHEX(cl.nome),    @key_str, cl.vector_id) AS CHAR)
                )
                ELSE ''
            END AS mittente_cognome,

            CASE
                WHEN a.mitt IN ('P','I','S') THEN {$DEC_P_QUALIFICA}
                ELSE ''
            END AS mittente_qualifica,

            CASE
                WHEN a.mitt IN ('P','I','S') THEN {$DEC_P_NOME}
                WHEN a.mitt = 'C' THEN {$DEC_C_NOME}
                ELSE ''
            END AS mittente_nome

        FROM {$tab} a
        LEFT JOIN {$tabDel} d
            ON d.id_message = a.id_message
           AND d.id_utente  = ?

        LEFT JOIN dap02_clients   c  ON c.id_client    = a.id_mitt AND a.mitt = 'C'
        LEFT JOIN dap03_personale p  ON p.id_personale = a.id_mitt AND a.mitt IN ('P','I','S')

        LEFT JOIN dap02_clients cl
            ON cl.id_client = a.id_mitt
           AND a.mitt = 'P'
           AND (COALESCE(a.dot_seg,0) = 1 OR COALESCE(a.dot_inf,0) = 1)

        WHERE
            a.id_message = ?
            AND {$whereSide}
            AND COALESCE(d.eliminato,0) = 0
        LIMIT 1
    ";

    // binds: deleteUid, idMessage, sideUid
    $binds = [$uid, $idMessage, $uid];

    return $db->query($sql, $binds)->getRowArray() ?: null;
}





    /* ==========================================================
     *                      MARK READ
     * ========================================================== */

    /**
     * Segna letto.
     * Usa sempre l'id dell'owner mailbox (dottore se segreteria/infermiera + selectedDoctorId).
     */
    public function markRead(string $src, int $idMessage, int $userId): void
    {
        $db  = $this->db;
        $tab = ($src === 'R') ? 'dap10_message_reply' : 'dap10_message';

        $uid = $this->resolveMailboxOwnerId($userId);

        $db->query("UPDATE {$tab} SET letto = 1 WHERE id_message = ? AND id_dest = ?", [$idMessage, $uid]);
    }
}
