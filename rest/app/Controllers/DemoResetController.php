<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DemoAccessService;
use App\Services\DemoDatasetResetService;

class DemoResetController extends BaseController
{
    public function run()
    {
        $demoAccess = new DemoAccessService();
        if (! $demoAccess->isDemoSiteEnabled() || ! $demoAccess->isDemoBootstrapEnabled()) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $configuredToken = trim((string) (env('DEMO_RESET_ACCESS_TOKEN') ?: env('CRON_ACCESS_TOKEN') ?: ''));
        $providedToken = trim((string) ($this->request->getGet('token') ?: $this->request->getHeaderLine('X-Cron-Token')));

        if ($configuredToken === '' || $providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
            return $this->response->setStatusCode(403)->setJSON([
                'ok' => false,
                'message' => 'Forbidden',
            ]);
        }

        $days = (int) ($this->request->getGet('days') ?? (env('DEMO_SEED_AGENDA_BUSINESS_DAYS') ?: 5));
        $days = max(2, min(14, $days));

        $startDate = trim((string) ($this->request->getGet('start_date') ?: date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            return $this->response->setStatusCode(400)->setJSON([
                'ok' => false,
                'message' => 'start_date non valido. Usa YYYY-MM-DD.',
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE && function_exists('session_write_close')) {
            @session_write_close();
        }

        try {
            $result = (new DemoDatasetResetService())->run([
                'agenda_start_date' => $startDate,
                'agenda_business_days' => $days,
            ]);

            if (($result['ok'] ?? false) !== true) {
                throw new \RuntimeException('Il reset demo non ha completato correttamente il seed.');
            }

            $report = (array) ($result['report'] ?? []);
            $summary = (array) ($report['summary'] ?? []);

            return $this->response->setJSON([
                'ok' => true,
                'message' => 'Reset dataset demo completato.',
                'summary' => [
                    'started_at' => $report['started_at'] ?? null,
                    'finished_at' => $report['finished_at'] ?? null,
                    'target_db' => $report['target_db'] ?? null,
                    'agenda_window' => $summary['agenda_window'] ?? [],
                    'agenda_slots' => (int) ($summary['agenda_slots'] ?? 0),
                    'agenda_appointments' => (int) ($summary['agenda_appointments'] ?? 0),
                    'report_path' => $result['report_path'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[DemoResetController::run] reset demo fallito: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
