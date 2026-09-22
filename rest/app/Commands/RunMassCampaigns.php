<?php

namespace App\Commands;

use App\Services\MassCampaignService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class RunMassCampaigns extends BaseCommand
{
    protected $group = 'Comunicazioni';
    protected $name = 'mass-campaigns:run';
    protected $description = 'Processa campagne email e SMS: un tentativo al minuto per spazio.';

    public function run(array $params)
    {
        if (in_array('--diagnose-schema', $params, true)) {
            $db = Database::connect('platform');
            $tables = [
                'campaigns' => $db->tableExists('platform_mass_campaigns'),
                'recipients' => $db->tableExists('platform_mass_campaign_recipients'),
                'rate_limits' => $db->tableExists('platform_mass_campaign_rate_limits'),
            ];

            CLI::write(json_encode(['ok' => !in_array(false, $tables, true), 'tables' => $tables], JSON_UNESCAPED_SLASHES) ?: '{"ok":false}', !in_array(false, $tables, true) ? 'green' : 'red');
            return;
        }

        $campaigns = new MassCampaignService();
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

        $result['window'] = [
            'attempts' => count($items),
            'sent' => $sent,
            'failed' => $failed,
            'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
            'items' => $items,
        ];
        CLI::write(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}', !empty($result['ok']) ? 'green' : 'red');
    }
}
