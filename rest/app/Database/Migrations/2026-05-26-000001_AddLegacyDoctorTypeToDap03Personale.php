<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLegacyDoctorTypeToDap03Personale extends Migration
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

        if (!isset($fieldMap['legacy_dot_tipo_id'])) {
            $this->forge->addColumn($this->table, [
                'legacy_dot_tipo_id' => [
                    'type' => 'INT',
                    'null' => true,
                    'after' => 'f_dom',
                ],
            ]);
        }

        $this->ensureIndex($this->table, 'idx_dap03_legacy_dot_tipo_id', 'legacy_dot_tipo_id');
    }

    public function down()
    {
        if (!$this->db->tableExists($this->table)) {
            return;
        }

        $this->dropIndexIfExists($this->table, 'idx_dap03_legacy_dot_tipo_id');

        $fields = $this->db->getFieldData($this->table);
        $fieldMap = [];
        foreach ($fields as $field) {
            $fieldMap[strtolower((string)$field->name)] = true;
        }

        if (isset($fieldMap['legacy_dot_tipo_id'])) {
            $this->forge->dropColumn($this->table, 'legacy_dot_tipo_id');
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
