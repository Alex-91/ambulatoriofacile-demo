<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddModuleVisibilityFlagsToDap03Personale extends Migration
{
    private string $table = 'dap03_personale';

    public function up()
    {
        if (!$this->db->tableExists($this->table)) {
            return;
        }

        $fields = $this->db->getFieldData($this->table);
        $fieldMap = [];
        foreach ($fields as $field) {
            $fieldMap[strtolower((string)$field->name)] = true;
        }

        $definitions = [
            'show_in_agenda' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
            ],
            'show_in_posta' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
            ],
            'show_in_chat' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
            ],
        ];

        foreach ($definitions as $column => $definition) {
            if (!isset($fieldMap[$column])) {
                $this->forge->addColumn($this->table, [$column => $definition]);
            }
        }
    }

    public function down()
    {
        if (!$this->db->tableExists($this->table)) {
            return;
        }

        $fields = $this->db->getFieldData($this->table);
        $fieldMap = [];
        foreach ($fields as $field) {
            $fieldMap[strtolower((string)$field->name)] = true;
        }

        foreach (['show_in_chat', 'show_in_posta', 'show_in_agenda'] as $column) {
            if (isset($fieldMap[$column])) {
                $this->forge->dropColumn($this->table, $column);
            }
        }
    }
}
