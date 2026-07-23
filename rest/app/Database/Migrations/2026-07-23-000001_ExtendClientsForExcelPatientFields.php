<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ExtendClientsForExcelPatientFields extends Migration
{
    private const TABLE = 'dap02_clients';

    private array $textFields = [
        'denominazione',
        'partita_iva',
        'email_pec',
        'banca',
        'condizioni_pagamento',
        'codice_destinatario',
        'note_cliente',
        'nr_civico',
        'indirizzo_secondario',
        'nr_civico_secondario',
        'comune_secondario',
        'cap_secondario',
        'provincia_secondaria',
    ];

    private array $flagFields = [
        'iva_differita' => 0,
        'cliente_attivo' => 1,
    ];

    public function up()
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return;
        }

        $fields = [];

        foreach ($this->textFields as $field) {
            if ($this->db->fieldExists($field, self::TABLE)) {
                continue;
            }

            $fields[$field] = [
                'type' => 'TEXT',
                'null' => true,
            ];
        }

        foreach ($this->flagFields as $field => $default) {
            if ($this->db->fieldExists($field, self::TABLE)) {
                continue;
            }

            $fields[$field] = [
                'type' => 'TINYINT',
                'constraint' => 1,
                'null' => false,
                'default' => $default,
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn(self::TABLE, $fields);
        }
    }

    public function down()
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return;
        }

        $dropFields = [];

        foreach (array_merge($this->textFields, array_keys($this->flagFields)) as $field) {
            if ($this->db->fieldExists($field, self::TABLE)) {
                $dropFields[] = $field;
            }
        }

        if ($dropFields !== []) {
            $this->forge->dropColumn(self::TABLE, $dropFields);
        }
    }
}
