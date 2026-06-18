<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class OptimizeMessagePerformance extends Migration
{
    public function up()
    {
        $this->ensureIndex('msg_messages', 'idx_msg_inbox_user_role_thread_msg', 'recipient_type, recipient_user_id, recipient_role, id_thread, id_message');
        $this->ensureIndex('msg_messages', 'idx_msg_thread_sender_user', 'id_thread, sender_user_id');
        $this->ensureIndex('msg_messages', 'idx_msg_thread_recipient_user', 'id_thread, recipient_user_id');

        $this->ensureIndex('dap09_client_doctor', 'idx_cd_client_dot', 'id_client, id_dot');
        $this->ensureIndex('dap09_client_doctor', 'idx_cd_dot_client', 'id_dot, id_client');

        $this->ensureIndex('dap14_seg_dot', 'idx_seg_dot_lookup', 'id_seg, id_dot');
        $this->ensureIndex('dap15_inf_dot', 'idx_inf_dot_lookup', 'id_inf, id_dot');

        $this->ensureIndex('dap18_sostituto', 'idx_sost_personale_dates', 'id_personale, data_inizio, data_fine');
        $this->ensureIndex('dap18_sostituto', 'idx_sost_target_dates', 'id_personale_da_sostituire, data_inizio, data_fine');
    }

    public function down()
    {
        $this->dropIndexIfExists('msg_messages', 'idx_msg_inbox_user_role_thread_msg');
        $this->dropIndexIfExists('msg_messages', 'idx_msg_thread_sender_user');
        $this->dropIndexIfExists('msg_messages', 'idx_msg_thread_recipient_user');

        $this->dropIndexIfExists('dap09_client_doctor', 'idx_cd_client_dot');
        $this->dropIndexIfExists('dap09_client_doctor', 'idx_cd_dot_client');

        $this->dropIndexIfExists('dap14_seg_dot', 'idx_seg_dot_lookup');
        $this->dropIndexIfExists('dap15_inf_dot', 'idx_inf_dot_lookup');

        $this->dropIndexIfExists('dap18_sostituto', 'idx_sost_personale_dates');
        $this->dropIndexIfExists('dap18_sostituto', 'idx_sost_target_dates');
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
