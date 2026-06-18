<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAgendaBridgeFieldsToDap03Personale extends Migration
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

        if (!isset($fieldMap['legacy_id_ope'])) {
            $this->forge->addColumn($this->table, [
                'legacy_id_ope' => [
                    'type' => 'INT',
                    'null' => true,
                    'after' => 'id_user',
                ],
            ]);
        }

        if (!isset($fieldMap['legacy_id_dot'])) {
            $this->forge->addColumn($this->table, [
                'legacy_id_dot' => [
                    'type' => 'INT',
                    'null' => true,
                    'after' => 'legacy_id_ope',
                ],
            ]);
        }

        if (!isset($fieldMap['f_dom'])) {
            $this->forge->addColumn($this->table, [
                'f_dom' => [
                    'type' => 'TINYINT',
                    'constraint' => 1,
                    'default' => 0,
                    'after' => 'is_dot',
                ],
            ]);
        }

        $this->ensureIndex($this->table, 'idx_dap03_legacy_id_ope', 'legacy_id_ope');
        $this->ensureIndex($this->table, 'idx_dap03_legacy_id_dot', 'legacy_id_dot');
    }

    public function down()
    {
        if (!$this->db->tableExists($this->table)) {
            return;
        }

        $this->dropIndexIfExists($this->table, 'idx_dap03_legacy_id_ope');
        $this->dropIndexIfExists($this->table, 'idx_dap03_legacy_id_dot');

        $fields = $this->db->getFieldData($this->table);
        $fieldMap = [];
        foreach ($fields as $field) {
            $fieldMap[strtolower((string)$field->name)] = true;
        }

        foreach (['f_dom', 'legacy_id_dot', 'legacy_id_ope'] as $column) {
            if (isset($fieldMap[$column])) {
                $this->forge->dropColumn($this->table, $column);
            }
        }
    }

    private function ensureIndex(string $table, string $indexName, string $columns): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }

        $this->db->query(sprintf(
            'ALTER TABLE `%s` ADD INDEX `%s` (%s)',
            $table,
            $indexName,
            $columns
        ));
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!$this->indexExists($table, $indexName)) {
            return;
        }

        $this->db->query(sprintf(
            'ALTER TABLE `%s` DROP INDEX `%s`',
            $table,
            $indexName
        ));
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $row = $this->db->query(
            'SELECT 1
             FROM information_schema.statistics
             WHERE table_schema = ?
               AND table_name = ?
               AND index_name = ?
             LIMIT 1',
            [$this->db->database, $table, $indexName]
        )->getRowArray();

        return !empty($row);
    }
}
