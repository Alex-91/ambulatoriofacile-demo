<?php

namespace App\Models;

use CodeIgniter\Model;
use Config\Database;

class DraftModel extends Model
{
    protected $table      = 'dap10_message';
    protected $primaryKey = 'id_message';

    /**
     * Crea una bozza (record dap10_message con draft=1)
     */
    public function createDraft(array $data): int
    {
        $db = Database::connect();

        // campi minimi + default
        $sql = "
            INSERT INTO dap10_message
            (id_mitt, id_dest, testo, dataora, letto, eliminato, oggetto, vector_id,
             da_dottore, farmaci, gestita, mitt, dest, seg_flag, inf_flag, email, draft, inoltrato, dot_seg, dot_inf)
            VALUES
            (?, NULL, ?, NOW(), 0, 0, ?, ?, ?, 0, 0, ?, NULL, ?, ?, 0, 1, ?, ?, ?)
        ";

        // testo/oggetto eventualmente già criptati nel controller? (dipende dal tuo flusso)
        // qui inserisco quello che mi passi così com’è.
        $params = [
            (int)($data['id_mitt'] ?? 0),
            (string)($data['testo'] ?? ''),
            (string)($data['oggetto'] ?? ''),
            $data['vector_id'] ?? null, // se la gestisci tu
            (int)($data['da_dottore'] ?? 1),
            (string)($data['mitt'] ?? 'P'),
            (int)($data['seg_flag'] ?? 0),
            (int)($data['inf_flag'] ?? 0),
            (string)($data['inoltrato'] ?? ''),
            (int)($data['dot_seg'] ?? 0),
            (int)($data['dot_inf'] ?? 0),
        ];

        $db->query($sql, $params);

        return (int)$db->insertID();
    }

    /**
     * Salva una bozza esistente (solo se appartiene all’utente)
     */
public function saveDraft(int $draftId, int $id_mitt, string $mitt, array $data): bool
{
    $db     = Database::connect();
    $crypto = new \App\Libraries\Crypto_helper();

    // =========================
    // 1) Valori in chiaro
    // =========================
    $testoPlain   = (string)($data['testo'] ?? '');
    $oggettoPlain = (string)($data['oggetto'] ?? '');

    $id_dest = (isset($data['id_dest']) && $data['id_dest'] !== '' && $data['id_dest'] !== null)
        ? (int)$data['id_dest'] : null;

    $dest = (isset($data['dest']) && $data['dest'] !== '' && $data['dest'] !== null)
        ? (string)$data['dest'] : null;

    // =========================
    // 2) Cifratura (espressioni SQL)
    // =========================
    $encTesto   = $crypto->encrypt($testoPlain);
    $encOggetto = $crypto->encrypt($oggettoPlain);

    // =========================
    // 3) Normalizzazione SQL
    // =========================
    $draftIdSql = (int)$draftId;
    $idMittSql  = (int)$id_mitt;

    $mittSql   = $db->escape($mitt);                 // 'P' / 'C' ecc
    $destSql   = ($dest === null) ? "NULL" : $db->escape($dest);
    $idDestSql = ($id_dest === null) ? "NULL" : (string)((int)$id_dest);

    // =========================
    // 4) Query: DELETE + INSERT (stesso id)
    // =========================
    $sqlDelete = "
        DELETE FROM dap10_message
        WHERE
            id_message = {$draftIdSql}
            AND id_mitt = {$idMittSql}
            AND mitt = {$mittSql}
            AND draft = 1
            AND eliminato = 0
        LIMIT 1
    ";

    $sqlInsert = "
        INSERT INTO dap10_message
            (id_message, id_mitt, mitt, id_dest, dest, oggetto, testo, draft, eliminato, letto, dataora, vector_id)
        VALUES
            (
                {$draftIdSql},
                {$idMittSql},
                {$mittSql},
                {$idDestSql},
                {$destSql},
                {$encOggetto},
                {$encTesto},
                1,
                0,
                0,
                NOW(),
                @init_vector
            )
    ";

    // =========================
    // 5) Esecuzione in transazione + LOG
    // =========================
    $db->transBegin();

    log_message('debug', "DraftModel::saveDraft DELETE SQL:\n" . $sqlDelete);
    $db->query($sqlDelete);

    $err = $db->error();
    if (!empty($err['code'])) {
        $db->transRollback();
        log_message('error', 'DraftModel::saveDraft DELETE DB ERROR: {code} - {message}', [
            'code'    => $err['code'],
            'message' => $err['message'] ?? '',
        ]);
        return false;
    }

    log_message('debug', "DraftModel::saveDraft INSERT SQL:\n" . $sqlInsert);
    $db->query($sqlInsert);

    $err = $db->error();
    if (!empty($err['code'])) {
        $db->transRollback();
        log_message('error', 'DraftModel::saveDraft INSERT DB ERROR: {code} - {message}', [
            'code'    => $err['code'],
            'message' => $err['message'] ?? '',
        ]);
        return false;
    }

    $db->transCommit();
    return true;
}





    /**
     * Prende UNA bozza con oggetto/testo decriptati
     */
    public function getDraft(int $draftId, int $id_mitt, string $mitt): ?array
    {
        $db = Database::connect();

        // decrypt con alias oggetto/testo come vuole la view
        $sql = "
            SELECT
                m.*,
                CAST(AES_DECRYPT(UNHEX(m.oggetto), @key_str, m.vector_id) AS CHAR) AS oggetto,
                CAST(AES_DECRYPT(UNHEX(m.testo),   @key_str, m.vector_id) AS CHAR) AS testo
            FROM dap10_message m
            WHERE
                m.id_message = ?
                AND m.id_mitt = ?
                AND m.mitt = ?
                AND m.draft = 1
                AND m.eliminato = 0
            LIMIT 1
        ";

  log_message('error', 'DraftModel::getDraft SQL: {sql} | params={params}', [
        'sql'    => trim($sql),
        'params' => json_encode([$draftId, $id_mitt, $mitt], JSON_UNESCAPED_UNICODE),
    ]);

    $row = $db->query($sql, [$draftId, $id_mitt, $mitt])->getRowArray();

    // ✅ log risultato (dopo)
    log_message('error', 'DraftModel::getDraft RESULT: {row}', [
        'row' => $row ? json_encode($row, JSON_UNESCAPED_UNICODE) : 'NULL',
    ]);
        return $row ?: null;
    }

    /**
     * Lista bozze paginata + ricerca su decrypt oggetto/testo
     */
    public function getDraftsLatest(int $id_mitt, string $mitt, array $filters, int $perPage, int $page): array
    {
        $db = Database::connect();

        $offset = max(0, ($page - 1) * $perPage);

        $where = "
            WHERE
                m.draft = 1
                AND m.eliminato = 0
                AND m.id_mitt = ?
                AND m.mitt = ?
        ";
        $paramsBase = [$id_mitt, $mitt];

        $q = trim((string)($filters['q'] ?? ''));
        $likeSql = "";
        $likeParams = [];
        if ($q !== '') {
            // LIKE su decrypt (non puoi usare alias in WHERE)
            $likeSql = "
                AND (
                    CAST(AES_DECRYPT(UNHEX(m.oggetto), @key_str, m.vector_id) AS CHAR) LIKE ?
                    OR CAST(AES_DECRYPT(UNHEX(m.testo), @key_str, m.vector_id) AS CHAR) LIKE ?
                )
            ";
            $likeParams = ["%{$q}%", "%{$q}%"];
        }

        // TOTAL
        $sqlTotal = "SELECT COUNT(*) AS tot FROM dap10_message m {$where} {$likeSql}";
        $totRow = $db->query($sqlTotal, array_merge($paramsBase, $likeParams))->getRowArray();
        $total = (int)($totRow['tot'] ?? 0);

        // ROWS
        $sqlRows = "
            SELECT
                m.id_message AS uid,
                'M' AS src,
                m.dataora,
                m.id_dest,
                m.dest,
                m.inoltrato,

                CAST(AES_DECRYPT(UNHEX(m.oggetto), @key_str, m.vector_id) AS CHAR) AS oggetto,
                CAST(AES_DECRYPT(UNHEX(m.testo),   @key_str, m.vector_id) AS CHAR) AS testo

            FROM dap10_message m
            {$where}
            {$likeSql}
            ORDER BY m.dataora DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $rows = $db->query($sqlRows, array_merge($paramsBase, $likeParams))->getResultArray();

        // compat con mailbox view
        foreach ($rows as &$r) {
            $r['mittente_nome']      = 'Bozza';
            $r['mittente_cognome']   = '';
            $r['mittente_qualifica'] = '';
            $r['letto']              = 1;
        }

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Aggancia allegati temp alla bozza (sessid)
     */
    public function linkTempAttachmentsToDraft(string $sessid, int $draftId): void
    {
        $db = Database::connect();

        $sql = "
            UPDATE dap11_attachments_temp
            SET id_message = ?
            WHERE sessid = ?
              AND id_message IS NULL
        ";
        $db->query($sql, [$draftId, $sessid]);
    }

    /**
     * Elimina bozza + allegati + file fisici
     */
    public function deleteDraftCascade(int $draftId, int $id_mitt, string $mitt, callable $fileDeleter): bool
    {
        $db = Database::connect();

        // 1) prendo lista file reali
        $sqlAtt = "SELECT nome_real FROM dap11_attachments WHERE id_message = ?";
        $atts = $db->query($sqlAtt, [$draftId])->getResultArray();

        foreach ($atts as $a) {
            $nr = (string)($a['nome_real'] ?? '');
            if ($nr !== '') $fileDeleter($nr);
        }

        // 2) delete attachments reali
        $db->query("DELETE FROM dap11_attachments WHERE id_message = ?", [$draftId]);

        // 3) delete attachments temp legati a quel draft
        $db->query("DELETE FROM dap11_attachments_temp WHERE id_message = ?", [$draftId]);

        // 4) delete bozza (solo se dell’utente)
        $sqlMsg = "
            DELETE FROM dap10_message
            WHERE id_message = ?
              AND id_mitt = ?
              AND mitt = ?
              AND draft = 1
        ";
        $db->query($sqlMsg, [$draftId, $id_mitt, $mitt]);

        return ($db->affectedRows() > 0);
    }
}
