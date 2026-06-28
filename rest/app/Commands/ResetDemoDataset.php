<?php
declare(strict_types=1);

namespace App\Commands;

use App\Services\DemoDatasetResetService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ResetDemoDataset extends BaseCommand
{
    protected $group = 'Demo';
    protected $name = 'demo:reset-dataset';
    protected $description = 'Resetta e ripopola il database demo con appuntamenti rolling.';
    protected $usage = 'demo:reset-dataset [--days=5] [--start-date=YYYY-MM-DD]';
    protected $options = [
        '--days=' => 'Numero di giorni lavorativi da popolare, default 5.',
        '--start-date=' => 'Data iniziale della finestra agenda, default oggi.',
    ];

    public function run(array $params)
    {
        $days = max(2, (int) ($this->readOptionValue($params, '--days') ?: 5));
        $startDate = trim((string) ($this->readOptionValue($params, '--start-date') ?: date('Y-m-d')));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            CLI::error('La data deve essere nel formato YYYY-MM-DD.');
            return;
        }

        try {
            CLI::write('Reset dataset demo in corso...', 'yellow');

            $result = (new DemoDatasetResetService())->run([
                'agenda_start_date' => $startDate,
                'agenda_business_days' => $days,
            ]);

            if (($result['ok'] ?? false) !== true) {
                throw new \RuntimeException('Il reset demo non ha restituito un esito valido.');
            }

            $report = (array) ($result['report'] ?? []);
            $summary = (array) ($report['summary'] ?? []);
            $window = (array) ($summary['agenda_window'] ?? []);

            CLI::write('Reset dataset demo completato.', 'green');
            CLI::write('Finestra agenda: ' . (string) ($window['start_date'] ?? '?') . ' -> ' . (string) ($window['end_date'] ?? '?'));
            CLI::write('Giorni lavorativi: ' . (int) ($window['business_days'] ?? 0));
            CLI::write('Slot agenda: ' . (int) ($summary['agenda_slots'] ?? 0));
            CLI::write('Appuntamenti: ' . (int) ($summary['agenda_appointments'] ?? 0));
            CLI::write('Report: ' . (string) ($result['report_path'] ?? ''));
        } catch (\Throwable $e) {
            CLI::error('Reset dataset demo fallito: ' . $e->getMessage());
        }
    }

    private function readOptionValue(array $params, string $option): ?string
    {
        $prefix = $option . '=';

        foreach ($params as $param) {
            if (str_starts_with((string) $param, $prefix)) {
                return substr((string) $param, strlen($prefix));
            }
        }

        return null;
    }
}
