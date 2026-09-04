<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class InstallWhatsAppCampaignSchema extends BaseCommand
{
    protected $group = 'WhatsApp';
    protected $name = 'whatsapp-campaigns:install-schema';
    protected $description = 'Crea esclusivamente le tabelle tecniche delle campagne WhatsApp.';

    public function run(array $params)
    {
        $db = Database::connect('platform');
        $statements = [
            "CREATE TABLE IF NOT EXISTS platform_whatsapp_campaigns (id_whatsapp_campaign BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, id_tenant INT UNSIGNED NOT NULL, audience_type VARCHAR(32) NOT NULL, appointment_date DATE NULL, message_text TEXT NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'queued', total_recipients INT UNSIGNED NOT NULL DEFAULT 0, pending_recipients INT UNSIGNED NOT NULL DEFAULT 0, sent_recipients INT UNSIGNED NOT NULL DEFAULT 0, failed_recipients INT UNSIGNED NOT NULL DEFAULT 0, created_by_platform_user_id INT UNSIGNED NULL, started_at DATETIME NULL, completed_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id_whatsapp_campaign), KEY idx_wa_campaign_tenant_status (id_tenant, status), CONSTRAINT fk_wa_campaign_tenant FOREIGN KEY (id_tenant) REFERENCES platform_tenants (id_tenant) ON DELETE CASCADE ON UPDATE CASCADE, CONSTRAINT fk_wa_campaign_creator FOREIGN KEY (created_by_platform_user_id) REFERENCES platform_users (id_platform_user) ON DELETE SET NULL ON UPDATE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS platform_whatsapp_campaign_recipients (id_whatsapp_campaign_recipient BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, id_whatsapp_campaign BIGINT UNSIGNED NOT NULL, id_tenant INT UNSIGNED NOT NULL, id_client INT UNSIGNED NULL, id_appointment INT UNSIGNED NULL, patient_name VARCHAR(255) NULL, recipient_phone VARCHAR(32) NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'pending', attempt_count INT UNSIGNED NOT NULL DEFAULT 0, provider_message_id VARCHAR(128) NULL, error_text TEXT NULL, sent_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id_whatsapp_campaign_recipient), KEY idx_wa_campaign_recipient_queue (id_whatsapp_campaign, status), UNIQUE KEY uq_wa_campaign_recipient_phone (id_whatsapp_campaign, recipient_phone), CONSTRAINT fk_wa_recipient_campaign FOREIGN KEY (id_whatsapp_campaign) REFERENCES platform_whatsapp_campaigns (id_whatsapp_campaign) ON DELETE CASCADE ON UPDATE CASCADE, CONSTRAINT fk_wa_recipient_tenant FOREIGN KEY (id_tenant) REFERENCES platform_tenants (id_tenant) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS platform_whatsapp_campaign_rate_limits (id_tenant INT UNSIGNED NOT NULL, next_allowed_at DATETIME NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id_tenant), CONSTRAINT fk_wa_rate_limit_tenant FOREIGN KEY (id_tenant) REFERENCES platform_tenants (id_tenant) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];

        foreach ($statements as $statement) {
            $db->query($statement);
        }

        foreach (['platform_whatsapp_campaigns', 'platform_whatsapp_campaign_recipients', 'platform_whatsapp_campaign_rate_limits'] as $table) {
            if (!$db->tableExists($table)) {
                throw new \RuntimeException('Schema campagne WhatsApp non disponibile sul database di piattaforma.');
            }
        }

        CLI::write('Schema campagne WhatsApp disponibile.', 'green');
    }
}
