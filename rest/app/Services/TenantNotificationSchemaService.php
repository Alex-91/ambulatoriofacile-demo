<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class TenantNotificationSchemaService
{
    public const POLICY_TABLE = 'platform_tenant_notification_policies';
    public const RATE_LIMIT_TABLE = 'platform_notification_rate_limits';
    public const FALLBACK_TABLE = 'platform_notification_fallbacks';
    public const FK_POLICY_TENANT = 'fk_tnp_tenant';
    public const FK_POLICY_USER = 'fk_tnp_user';
    public const FK_RATE_LIMIT_TENANT = 'fk_nrl_tenant';
    public const FK_FALLBACK_TENANT = 'fk_nf_tenant';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect('platform');
    }

    public function ensureReady(): void
    {
        $this->createPolicies();
        $this->addSmtpPasswordColumn();
        $this->createRateLimits();
        $this->createFallbacks();
        $this->db->resetDataCache();

        $missing = $this->missingComponents();
        if ($missing !== []) {
            throw new \RuntimeException(
                'Schema delle notifiche di piattaforma incompleto dopo il riallineamento: '
                . implode(', ', $missing) . '.'
            );
        }
    }

    public function isReady(): bool
    {
        return $this->missingComponents() === [];
    }

    /** @return array<int, string> */
    private function missingComponents(): array
    {
        $missing = [];
        if (!$this->db->tableExists(self::POLICY_TABLE)) {
            $missing[] = self::POLICY_TABLE;
        } elseif (!$this->db->fieldExists('smtp_password_encrypted', self::POLICY_TABLE)) {
            $missing[] = self::POLICY_TABLE . '.smtp_password_encrypted';
        }
        if (!$this->db->tableExists(self::RATE_LIMIT_TABLE)) {
            $missing[] = self::RATE_LIMIT_TABLE;
        }
        if (!$this->db->tableExists(self::FALLBACK_TABLE)) {
            $missing[] = self::FALLBACK_TABLE;
        }

        return $missing;
    }

    private function createPolicies(): void
    {
        if ($this->db->tableExists(self::POLICY_TABLE)) {
            return;
        }

        $forge = Database::forge($this->db);
        $forge->addField([
            'id_tenant_notification_policy' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'id_tenant' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'config_json' => ['type' => 'LONGTEXT'],
            'smtp_password_encrypted' => ['type' => 'LONGTEXT', 'null' => true],
            'updated_by_platform_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $forge->addKey('id_tenant_notification_policy', true);
        $forge->addUniqueKey('id_tenant', 'uq_tenant_notification_policy');
        $forge->addForeignKey(
            'id_tenant',
            'platform_tenants',
            'id_tenant',
            'CASCADE',
            'CASCADE',
            $this->foreignKeyName(self::FK_POLICY_TENANT)
        );
        $forge->addForeignKey(
            'updated_by_platform_user_id',
            'platform_users',
            'id_platform_user',
            'CASCADE',
            'SET NULL',
            $this->foreignKeyName(self::FK_POLICY_USER)
        );
        $forge->createTable(self::POLICY_TABLE, true);
    }

    private function addSmtpPasswordColumn(): void
    {
        if (!$this->db->tableExists(self::POLICY_TABLE)
            || $this->db->fieldExists('smtp_password_encrypted', self::POLICY_TABLE)) {
            return;
        }

        Database::forge($this->db)->addColumn(self::POLICY_TABLE, [
            'smtp_password_encrypted' => [
                'type' => 'LONGTEXT',
                'null' => true,
                'after' => 'config_json',
            ],
        ]);
    }

    private function createRateLimits(): void
    {
        if ($this->db->tableExists(self::RATE_LIMIT_TABLE)) {
            return;
        }

        $forge = Database::forge($this->db);
        $forge->addField([
            'id_notification_rate_limit' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'id_tenant' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'channel' => ['type' => 'VARCHAR', 'constraint' => 16],
            'next_allowed_at' => ['type' => 'DATETIME', 'null' => true],
            'counter_date' => ['type' => 'DATE', 'null' => true],
            'sent_today' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $forge->addKey('id_notification_rate_limit', true);
        $forge->addUniqueKey(['id_tenant', 'channel'], 'uq_notification_rate_limit_tenant_channel');
        $forge->addForeignKey(
            'id_tenant',
            'platform_tenants',
            'id_tenant',
            'CASCADE',
            'CASCADE',
            $this->foreignKeyName(self::FK_RATE_LIMIT_TENANT)
        );
        $forge->createTable(self::RATE_LIMIT_TABLE, true);
    }

    private function createFallbacks(): void
    {
        if ($this->db->tableExists(self::FALLBACK_TABLE)) {
            return;
        }

        $forge = Database::forge($this->db);
        $forge->addField([
            'id_notification_fallback' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'id_tenant' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'fallback_key' => ['type' => 'CHAR', 'constraint' => 64],
            'source_type' => ['type' => 'VARCHAR', 'constraint' => 40],
            'source_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'message_type' => ['type' => 'VARCHAR', 'constraint' => 64],
            'recipient_phone' => ['type' => 'VARCHAR', 'constraint' => 32],
            'message_text' => ['type' => 'TEXT'],
            'whatsapp_provider_id' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'whatsapp_status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'sent'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'pending'],
            'due_at' => ['type' => 'DATETIME'],
            'checked_at' => ['type' => 'DATETIME', 'null' => true],
            'sms_provider_id' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'sms_sent_at' => ['type' => 'DATETIME', 'null' => true],
            'error_text' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $forge->addKey('id_notification_fallback', true);
        $forge->addUniqueKey('fallback_key', 'uq_notification_fallback_key');
        $forge->addKey(['id_tenant', 'status', 'due_at'], false, false, 'idx_notification_fallback_due');
        $forge->addForeignKey(
            'id_tenant',
            'platform_tenants',
            'id_tenant',
            'CASCADE',
            'CASCADE',
            $this->foreignKeyName(self::FK_FALLBACK_TENANT)
        );
        $forge->createTable(self::FALLBACK_TABLE, true);
    }

    private function foreignKeyName(string $name): string
    {
        return $this->db->DBDriver === 'SQLite3' ? '' : $name;
    }
}
