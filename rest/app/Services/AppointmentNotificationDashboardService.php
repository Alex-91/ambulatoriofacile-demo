<?php

namespace App\Services;

use Config\Database;

class AppointmentNotificationDashboardService
{
    private \CodeIgniter\Database\BaseConnection $platformDb;
    private AppointmentNotificationSettingsService $settingsService;
    private AppointmentNotificationLogService $logService;

    public function __construct()
    {
        $this->platformDb = Database::connect('platform');
        $this->settingsService = new AppointmentNotificationSettingsService();
        $this->logService = new AppointmentNotificationLogService();
    }

    /**
     * @param array<string, mixed> $tenant
     * @param array<string, mixed>|null $settings
     * @return array<string, mixed>
     */
    public function buildTenantDashboard(array $tenant, ?array $settings = null, int $days = 30, int $recentLimit = 50): array
    {
        $settings = $settings ?? $this->settingsService->resolveTenantSettings((int) ($tenant['id_tenant'] ?? 0));
        $entriesAll = $this->logService->listEntriesForTenant($tenant, 3650, 5000);
        $entriesRecent = $this->filterByDays($entriesAll, $days);

        return [
            'summary' => [
                'total_sent' => $this->countSent($entriesAll),
                'recent_sent' => $this->countSent($entriesRecent),
                'sms_total' => $this->countSent($entriesAll, 'sms'),
                'wa_total' => $this->countSent($entriesAll, 'wa'),
                'sms_recent' => $this->countSent($entriesRecent, 'sms'),
                'wa_recent' => $this->countSent($entriesRecent, 'wa'),
                'last_sent_at' => $this->lastSentAt($entriesAll),
            ],
            'by_type' => $this->buildCountsByType($entriesRecent),
            'recent_rows' => array_slice($entriesRecent, 0, max(1, $recentLimit)),
            'settings' => $settings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPlatformDashboard(int $days = 30, int $recentLimit = 80): array
    {
        $tenantRows = $this->platformDb->table('platform_tenants t')
            ->select('t.id_tenant, t.tenant_key, t.tenant_name, t.storage_key, t.status, t.onboarding_status, t.is_active, p.package_code, p.package_name')
            ->join('platform_packages p', 'p.id_package = t.id_package', 'left')
            ->where('t.is_active', 1)
            ->whereNotIn('t.status', ['archived'])
            ->orderBy('t.tenant_name', 'ASC')
            ->get()
            ->getResultArray();

        $rows = [];
        $recentRows = [];
        $summary = [
            'tenant_count' => count($tenantRows),
            'module_enabled_count' => 0,
            'sms_enabled_count' => 0,
            'wa_enabled_count' => 0,
            'recent_sent' => 0,
            'recent_sms_sent' => 0,
            'recent_wa_sent' => 0,
        ];

        foreach ($tenantRows as $tenant) {
            $tenantId = (int) ($tenant['id_tenant'] ?? 0);
            if ($tenantId <= 0) {
                continue;
            }

            $settings = $this->settingsService->resolveTenantSettings($tenantId);
            $dashboard = $this->buildTenantDashboard($tenant, $settings, $days, 12);

            $moduleEnabled = (bool) ($settings['module']['available'] ?? false);
            $smsEnabled = (bool) (($settings['available_channels']['sms'] ?? false) === true);
            $waEnabled = (bool) (($settings['available_channels']['wa'] ?? false) === true);

            if ($moduleEnabled) {
                $summary['module_enabled_count']++;
            }
            if ($smsEnabled) {
                $summary['sms_enabled_count']++;
            }
            if ($waEnabled) {
                $summary['wa_enabled_count']++;
            }

            $summary['recent_sent'] += (int) ($dashboard['summary']['recent_sent'] ?? 0);
            $summary['recent_sms_sent'] += (int) ($dashboard['summary']['sms_recent'] ?? 0);
            $summary['recent_wa_sent'] += (int) ($dashboard['summary']['wa_recent'] ?? 0);

            $rows[] = [
                'tenant' => $tenant,
                'settings' => $settings,
                'summary' => $dashboard['summary'],
                'by_type' => $dashboard['by_type'],
            ];

            foreach ((array) ($dashboard['recent_rows'] ?? []) as $entry) {
                $entry['tenant_name'] = (string) ($tenant['tenant_name'] ?? '');
                $entry['tenant_id'] = $tenantId;
                $recentRows[] = $entry;
            }
        }

        usort($recentRows, static function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        });

        return [
            'summary' => $summary,
            'tenant_rows' => $rows,
            'recent_rows' => array_slice($recentRows, 0, max(1, $recentLimit)),
            'days' => $days,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function filterByDays(array $entries, int $days): array
    {
        $cutoff = strtotime('-' . max(1, $days) . ' day');

        return array_values(array_filter($entries, static function (array $entry) use ($cutoff): bool {
            $timestamp = strtotime((string) ($entry['created_at'] ?? ''));
            return $timestamp !== false && $timestamp >= $cutoff;
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function countSent(array $entries, ?string $channel = null): int
    {
        $count = 0;

        foreach ($entries as $entry) {
            if (($entry['status'] ?? '') !== 'sent') {
                continue;
            }

            if ($channel !== null && (string) ($entry['channel'] ?? '') !== $channel) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, array<string, int>>
     */
    private function buildCountsByType(array $entries): array
    {
        $counts = [];

        foreach ($entries as $entry) {
            if (($entry['status'] ?? '') !== 'sent') {
                continue;
            }

            $type = (string) ($entry['message_type'] ?? 'unknown');
            $channel = (string) ($entry['channel'] ?? 'unknown');

            if (!isset($counts[$type])) {
                $counts[$type] = ['total' => 0, 'sms' => 0, 'wa' => 0];
            }

            $counts[$type]['total']++;
            if (isset($counts[$type][$channel])) {
                $counts[$type][$channel]++;
            }
        }

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function lastSentAt(array $entries): string
    {
        foreach ($entries as $entry) {
            if (($entry['status'] ?? '') === 'sent' && trim((string) ($entry['created_at'] ?? '')) !== '') {
                return (string) ($entry['created_at'] ?? '');
            }
        }

        return '';
    }
}
