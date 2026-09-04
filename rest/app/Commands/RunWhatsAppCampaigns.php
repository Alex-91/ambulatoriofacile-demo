<?php

namespace App\Commands;

use App\Services\WhatsAppCampaignService;
use App\Services\WhatsAppSmsFallbackService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class RunWhatsAppCampaigns extends BaseCommand
{
    protected $group = 'WhatsApp';
    protected $name = 'whatsapp-campaigns:run';
    protected $description = 'Processa campagne WhatsApp e fallback SMS rispettando i limiti configurati per spazio.';

    public function run(array $params)
    {
        if (in_array('--diagnose-schema', $params, true)) {
            $db = Database::connect('platform');
            $tables = [
                'campaigns' => $db->tableExists('platform_whatsapp_campaigns'),
                'recipients' => $db->tableExists('platform_whatsapp_campaign_recipients'),
                'rate_limits' => $db->tableExists('platform_whatsapp_campaign_rate_limits'),
                'notification_policies' => $db->tableExists('platform_tenant_notification_policies'),
                'notification_rate_limits' => $db->tableExists('platform_notification_rate_limits'),
                'notification_fallbacks' => $db->tableExists('platform_notification_fallbacks'),
            ];

            CLI::write(json_encode(['ok' => !in_array(false, $tables, true), 'tables' => $tables], JSON_UNESCAPED_SLASHES) ?: '{"ok":false}', !in_array(false, $tables, true) ? 'green' : 'red');
            return;
        }

        $campaigns = new WhatsAppCampaignService();
        $startedAt = microtime(true);
        $deadline = $startedAt + 50.0;
        $items = [];
        $sent = 0;
        $failed = 0;
        $result = ['ok' => true, 'status' => 'idle'];

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $result = $campaigns->runOne();
            $items[] = $result;
            $status = (string) ($result['status'] ?? '');
            if ($status === 'sent') {
                $sent++;
            } elseif ($status === 'failed') {
                $failed++;
            }
            if (in_array($status, ['schema_missing', 'claim_failed'], true)) {
                break;
            }

            if ($status !== 'idle') {
                continue;
            }

            $nextDueAt = $campaigns->nextPendingDueAt();
            if ($nextDueAt === null || $nextDueAt === '') {
                break;
            }

            $waitSeconds = max(0, strtotime($nextDueAt) - time());
            if ($waitSeconds <= 0) {
                break;
            }
            if ((microtime(true) + $waitSeconds) > $deadline) {
                break;
            }
            usleep($waitSeconds * 1000000);
        }

        $fallbacks = (new WhatsAppSmsFallbackService())->reconcile(20);
        $result['window'] = [
            'attempts' => count($items),
            'sent' => $sent,
            'failed' => $failed,
            'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
            'items' => $items,
        ];
        $result['fallbacks'] = $fallbacks;
        CLI::write(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}', !empty($result['ok']) ? 'green' : 'red');
    }
}
