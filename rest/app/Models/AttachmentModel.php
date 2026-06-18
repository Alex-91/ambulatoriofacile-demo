<?php

namespace App\Models;

use CodeIgniter\Model;
use Config\Database;

class AttachmentModel extends Model
{
    protected $table      = 'dap11_attachments';
    protected $primaryKey = 'id_attachments';
    protected $returnType = 'array';

    protected $allowedFields = [
        'id_attachments',
        'nome_real',
        'nome_vis',
        'id_message',
        'id_message_reply',
        'vector_id',
    ];

    /**
     * Inserisce un allegato definitivo.
     * $vectorIdHex: stringa esadecimale (senza 0x).
     * Se $idMessageReply è null, va a NULL.
     */
  public function insertDefinitivo(
    string $nomeRealEnc,   // in realtà: PLAIN TEXT (path relativo)
    string $nomeVisEnc,    // in realtà: PLAIN TEXT (nome visibile)
    int $idMessage,
    ?int $idMessageReply,
    string $vectorIdHex    // non lo usiamo, generiamo nuovo vettore
): void {
    $db            = Database::connect();
    $crypto_helper = new \App\Libraries\Crypto_helper();

    // Genero un nuovo vettore per il record definitivo
    $vectorBin = random_bytes(16);
    $vectorHex = bin2hex($vectorBin);

    // Imposto @init_vector da usare in AES_ENCRYPT
    $db->query("SET @init_vector = UNHEX('{$vectorHex}')");

    // Costruisco la INSERT cifrando nome_real e nome_vis in SQL,
    // esattamente come in AttachmentTempModel::insertTemp()
    $sql = "
        INSERT INTO {$this->table}
            (nome_real, nome_vis, id_message, id_message_reply, vector_id)
        VALUES (
            " . $crypto_helper->encrypt($nomeRealEnc) . ",
            " . $crypto_helper->encrypt($nomeVisEnc) . ",
            {id_message}, {id_message_reply}, UNHEX(:vector_hex:)
        )
    ";

    $sql = strtr($sql, [
        '{id_message}'       => (string) $idMessage,
        '{id_message_reply}' => $idMessageReply ? (string) $idMessageReply : 'NULL',
    ]);

    $db->query($sql, [
        'vector_hex' => strtoupper($vectorHex),
    ]);
}

}
