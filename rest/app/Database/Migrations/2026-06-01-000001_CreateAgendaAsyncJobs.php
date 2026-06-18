<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAgendaAsyncJobs extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('dap25_agenda_job')) {
            $this->forge->addField([
                'id_job' => [
                    'type'           => 'INT',
                    'constraint'     => 10,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'token' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                ],
                'job_type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 50,
                ],
                'status' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'QUEUED',
                ],
                'requested_by' => [
                    'type'       => 'INT',
                    'constraint' => 10,
                ],
                'id_dot' => [
                    'type'       => 'INT',
                    'constraint' => 10,
                ],
                'id_config' => [
                    'type'       => 'INT',
                    'constraint' => 10,
                    'null'       => true,
                ],
                'payload_json' => [
                    'type' => 'MEDIUMTEXT',
                    'null' => true,
                ],
                'progress_percent' => [
                    'type'       => 'SMALLINT',
                    'constraint' => 5,
                    'unsigned'   => true,
                    'default'    => 0,
                ],
                'progress_message' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                ],
                'backup_file_name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                ],
                'backup_file_path' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 500,
                    'null'       => true,
                ],
                'backup_file_format' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 16,
                    'null'       => true,
                ],
                'inserted_slots' => [
                    'type'       => 'INT',
                    'constraint' => 10,
                    'default'    => 0,
                ],
                'result_json' => [
                    'type' => 'MEDIUMTEXT',
                    'null' => true,
                ],
                'error_message' => [
                    'type'       => 'TEXT',
                    'null'       => true,
                ],
                'notify_push_sent' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                ],
                'started_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'finished_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'heartbeat_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);

            $this->forge->addKey('id_job', true);
            $this->forge->createTable('dap25_agenda_job', true);
        }

        $this->createIndexIfMissing('dap25_agenda_job', 'uq_dap25_agenda_job_token', 'UNIQUE', '(token)');
        $this->createIndexIfMissing('dap25_agenda_job', 'idx_dap25_agenda_job_status', '', '(status, created_at)');
        $this->createIndexIfMissing('dap25_agenda_job', 'idx_dap25_agenda_job_dot_status', '', '(id_dot, status, created_at)');
        $this->createIndexIfMissing('dap25_agenda_job', 'idx_dap25_agenda_job_user_status', '', '(requested_by, status, created_at)');
    }

    public function down()
    {
        if ($this->db->tableExists('dap25_agenda_job')) {
            $this->forge->dropTable('dap25_agenda_job', true);
        }
    }

    private function createIndexIfMissing(string $table, string $indexName, string $prefix, string $columnsSql): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }

        $prefixSql = $prefix !== '' ? trim($prefix) . ' ' : '';
        $this->db->query("CREATE {$prefixSql}INDEX {$indexName} ON {$table} {$columnsSql}");
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS c
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
        ", [$table, $indexName])->getRowArray();

        return (int)($row['c'] ?? 0) > 0;
    }
}
