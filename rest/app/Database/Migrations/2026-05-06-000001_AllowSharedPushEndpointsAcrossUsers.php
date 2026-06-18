<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AllowSharedPushEndpointsAcrossUsers extends Migration
{
    private string $table = 'push_subscriptions';

    public function up()
    {
        if (!$this->db->tableExists($this->table)) {
            return;
        }

        if (
            !$this->db->fieldExists('user_id', $this->table)
            || !$this->db->fieldExists('endpoint_hash', $this->table)
        ) {
            return;
        }

        if ($this->indexExists('uq_push_endpoint_hash')) {
            $this->db->query("ALTER TABLE `{$this->table}` DROP INDEX `uq_push_endpoint_hash`");
        }

        if (!$this->indexExists('idx_push_endpoint_hash')) {
            $this->db->query("ALTER TABLE `{$this->table}` ADD INDEX `idx_push_endpoint_hash` (`endpoint_hash`)");
        }

        if (!$this->indexExists('uq_push_user_endpoint_hash')) {
            $this->db->query(
                "ALTER TABLE `{$this->table}` ADD UNIQUE INDEX `uq_push_user_endpoint_hash` (`user_id`, `endpoint_hash`)"
            );
        }
    }

    public function down()
    {
        if (!$this->db->tableExists($this->table)) {
            return;
        }

        if ($this->indexExists('uq_push_user_endpoint_hash')) {
            $this->db->query("ALTER TABLE `{$this->table}` DROP INDEX `uq_push_user_endpoint_hash`");
        }

        if ($this->indexExists('idx_push_endpoint_hash')) {
            $this->db->query("ALTER TABLE `{$this->table}` DROP INDEX `idx_push_endpoint_hash`");
        }

        if (!$this->indexExists('uq_push_endpoint_hash')) {
            try {
                $this->db->query("ALTER TABLE `{$this->table}` ADD UNIQUE INDEX `uq_push_endpoint_hash` (`endpoint_hash`)");
            } catch (\Throwable $e) {
                // If multiple users now share the same endpoint, the legacy unique index cannot be restored safely.
            }
        }
    }

    private function indexExists(string $name): bool
    {
        try {
            $row = $this->db
                ->query("SHOW INDEX FROM `{$this->table}` WHERE Key_name = ?", [$name])
                ->getRowArray();

            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
