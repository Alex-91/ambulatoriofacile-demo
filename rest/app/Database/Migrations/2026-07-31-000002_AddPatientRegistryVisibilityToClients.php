<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPatientRegistryVisibilityToClients extends Migration
{
    private const TABLE = 'dap02_clients';
    private const COLUMN = 'visibile_in_anagrafica';

    public function up()
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return;
        }

        if (!$this->db->fieldExists(self::COLUMN, self::TABLE)) {
            $this->forge->addColumn(self::TABLE, [
                self::COLUMN => [
                    'type' => 'TINYINT',
                    'constraint' => 1,
                    'null' => false,
                    'default' => 1,
                    'after' => 'cliente_attivo',
                ],
            ]);
        }

        // I pazienti gia presenti devono restare sempre disponibili in anagrafica.
        $this->db->table(self::TABLE)
            ->where(self::COLUMN, null)
            ->update([self::COLUMN => 1]);
    }

    public function down()
    {
        if (
            $this->db->tableExists(self::TABLE)
            && $this->db->fieldExists(self::COLUMN, self::TABLE)
        ) {
            $this->forge->dropColumn(self::TABLE, self::COLUMN);
        }
    }
}
