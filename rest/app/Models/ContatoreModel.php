<?php

namespace App\Models;

use CodeIgniter\Model;
use Config\Database;

class ContatoreModel extends Model
{
    protected $table = 'dap20_contatore';
    protected $primaryKey = 'id_contatore';
    protected $allowedFields = ['id_contatore', 'tabella'];

    /**
     * Genera un nuovo contatore e inserisce riga come nel legacy.
     * $label: 'dap10_message' | 'dap10_message_reply'
     */
    public function next(string $label): int
    {
        $db = Database::connect();

        // prendo max+1 in transazione per coerenza col legacy
        $db->transStart();

        $row = $db->table($this->table)->select('IFNULL(MAX(id_contatore)+1,1) AS id')->get()->getRowArray();
        $id = (int)$row['id'];

        $db->table($this->table)->insert(['id_contatore' => $id, 'tabella' => $label]);

        $db->transComplete();
        if ($db->transStatus() === false) {
            throw new \RuntimeException('Contatore non generato');
        }

        return $id;
    }
}
