<?php

namespace App\Models;

use CodeIgniter\Model;
use App\Libraries\Crypto_helper;

class AttachmentTempModel extends Model
{
    protected $table      = 'dap11_attachments_temp';
    protected $primaryKey = 'id_attachments';
    protected $returnType = 'array';

    protected $allowedFields = [
        'id_attachments',
        'nome_real',
        'nome_vis',
        'id_message',
        'id_message_reply',
        'vector_id',
        'sessid',
    ];

    /**
     * Restituisce tutti gli allegati temporanei associati a una sessione.
     * Include i nomi reali e visuali decrittati e l'HEX del vettore.
     */
    public function getBySession(string $sessid): array
    {
        $crypto_helper = new Crypto_helper();
        $db = \Config\Database::connect();

        $sql = "
            SELECT 
                a.id_attachments,
                " . $crypto_helper->decryptSenzaAlias('a.nome_real') . " AS nome_real,
                " . $crypto_helper->decryptSenzaAlias('a.nome_vis') . " AS nome_vis,
                a.nome_real AS nome_real_enc,
                a.nome_vis  AS nome_vis_enc,
                a.id_message,
                a.id_message_reply,
                UPPER(HEX(a.vector_id)) AS vector_id_hex,
                a.sessid
            FROM {$this->table} a
            WHERE a.sessid = ?
        ";

        $query = $db->query($sql, [$sessid]);
        $result = $query->getResultArray();

        log_message('debug', 'Recuperati ' . count($result) . ' allegati temporanei per sessione ' . $sessid);

        return $result;
    }

    /**
     * Restituisce tutti gli allegati temporanei associati a un messaggio.
     */
    public function getByMessage(int $idMessage): array
    {
        $crypto_helper = new Crypto_helper();
        $db = \Config\Database::connect();

        $sql = "
            SELECT 
                a.id_attachments,
                " . $crypto_helper->decryptSenzaAlias('a.nome_real') . " AS nome_real,
                " . $crypto_helper->decryptSenzaAlias('a.nome_vis') . " AS nome_vis,
                a.nome_real AS nome_real_enc,
                a.nome_vis  AS nome_vis_enc,
                a.id_message,
                a.id_message_reply,
                UPPER(HEX(a.vector_id)) AS vector_id_hex,
                a.sessid
            FROM {$this->table} a
            WHERE a.id_message = ?
        ";

        return $db->query($sql, [$idMessage])->getResultArray();
    }

    /**
     * Cancella un allegato temporaneo per id.
     */
    public function deleteById(int $id): bool
    {
        log_message('info', 'Eliminazione allegato temporaneo ID: ' . $id);
        return (bool) $this->where('id_attachments', $id)->delete();
    }

    /**
     * Cancella tutti gli allegati temporanei associati a una sessione.
     */
    public function deleteBySession(string $sessid): int
    {
        $deleted = $this->where('sessid', $sessid)->delete();
        log_message('info', 'Eliminati ' . $deleted . ' allegati temporanei per sessione ' . $sessid);
        return $deleted;
    }

    /**
     * Inserisce un nuovo allegato temporaneo.
     * I campi nome_real e nome_vis devono essere già cifrati lato PHP o DB.
     */
   public function insertTemp(array $data): bool
{
    $db            = \Config\Database::connect();
    $crypto_helper = new Crypto_helper();

    $nomeReal = $data['nome_real'] ?? '';
    $nomeVis  = $data['nome_vis']  ?? '';
    $idMsg    = $data['id_message'] ?? null;
    $idReply  = $data['id_message_reply'] ?? null;
    $sessid   = $data['sessid'] ?? '';

    // Genero vettore casuale per questo record
    $vectorBin = random_bytes(16);
    $vectorHex = bin2hex($vectorBin);

    // Imposto @init_vector per AES_ENCRYPT (coerente con Crypto_helper->encrypt)
    $db->query("SET @init_vector = UNHEX('{$vectorHex}')");

    // Costruisco la INSERT usando le funzioni di cifratura
    // encrypt($value) -> "HEX(AES_ENCRYPT('value',@key_str,@init_vector))"
    $sql = "
        INSERT INTO {$this->table}
            (nome_real, nome_vis, id_message, id_message_reply, vector_id, sessid)
        VALUES (
            " . $crypto_helper->encrypt($nomeReal) . ",
            " . $crypto_helper->encrypt($nomeVis) . ",
            ?, ?, UNHEX('{$vectorHex}'), ?
        )
    ";

    return (bool) $db->query($sql, [
        $idMsg,
        $idReply,
        $sessid,
    ]);
}


    /**
     * Conta quanti allegati temporanei ci sono per la sessione corrente.
     */
    public function countBySession(string $sessid): int
    {
        return $this->where('sessid', $sessid)->countAllResults();
    }
}
    