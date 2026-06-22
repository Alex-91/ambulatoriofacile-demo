<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RenameAgendaVisibilityTableToDap24 extends Migration
{
    private string $legacyTable = 'far24_agenda_visibilita';
    private string $newTable = 'dap24_agenda_visibilita';
    private string $lookupIndex = 'idx_dap24_agenda_vis_ope_dot';

    public function up()
    {
        $legacyExists = $this->db->tableExists($this->legacyTable);
        $newExists = $this->db->tableExists($this->newTable);

        if ($legacyExists && !$newExists) {
            $this->db->query(sprintf(
                'RENAME TABLE `%s` TO `%s`',
                $this->legacyTable,
                $this->newTable
            ));

            $legacyExists = false;
            $newExists = true;
        }

        if (!$newExists) {
            $this->forge->addField([
                'id_visibilita' => [
                    'type'           => 'BIGINT',
                    'auto_increment' => true,
                ],
                'id_ope' => [
                    'type' => 'INT',
                ],
                'id_dot' => [
                    'type' => 'INT',
                ],
                'created_by' => [
                    'type' => 'INT',
                    'null' => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);

            $this->forge->addKey('id_visibilita', true);
            $this->forge->createTable($this->newTable, true);
            $newExists = true;
        }

        if ($legacyExists && $newExists) {
            $this->mergeRows($this->legacyTable, $this->newTable);
            $this->forge->dropTable($this->legacyTable, true);
        }

        $this->ensureIndex($this->newTable, $this->lookupIndex, 'id_ope, id_dot');
    }

    public function down()
    {
        $legacyExists = $this->db->tableExists($this->legacyTable);
        $newExists = $this->db->tableExists($this->newTable);

        if ($newExists && !$legacyExists) {
            $this->db->query(sprintf(
                'RENAME TABLE `%s` TO `%s`',
                $this->newTable,
                $this->legacyTable
            ));
            return;
        }

        if ($newExists && $legacyExists) {
            $this->mergeRows($this->newTable, $this->legacyTable);
            $this->forge->dropTable($this->newTable, true);
        }
    }

    private function mergeRows(string $sourceTable, string $targetTable): void
    {
        $sourceColumns = $this->tableColumns($sourceTable);
        $targetColumns = $this->tableColumns($targetTable);
        $columns = array_values(array_intersect(
            ['id_ope', 'id_dot', 'created_by', 'created_at'],
            $sourceColumns,
            $targetColumns
        ));

        if (!in_array('id_ope', $columns, true) || !in_array('id_dot', $columns, true)) {
            return;
        }

        $quotedColumns = array_map(
            static fn(string $column): string => sprintf('`%s`', $column),
            $columns
        );
        $sourceColumns = array_map(
            static fn(string $column): string => sprintf('src.`%s`', $column),
            $columns
        );

        $sql = sprintf(
            'INSERT INTO `%s` (%s)
             SELECT %s
             FROM `%s` src
             LEFT JOIN `%s` dst
               ON dst.id_ope = src.id_ope
              AND dst.id_dot = src.id_dot
             WHERE dst.id_ope IS NULL',
            $targetTable,
            implode(', ', $quotedColumns),
            implode(', ', $sourceColumns),
            $sourceTable,
            $targetTable
        );

        $this->db->query($sql);
    }

    private function tableColumns(string $table): array
    {
        $query = $this->db->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        if (!$query) {
            return [];
        }

        $rows = $query->getResultArray();
        return array_map(static fn(array $row): string => (string)($row['Field'] ?? ''), $rows);
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
