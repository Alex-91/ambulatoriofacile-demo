<?php

namespace App\Commands;

use App\Services\AppointmentReminderScheduledRunService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class RunAppointmentReminders extends BaseCommand
{
    protected $group = 'Notifications';
    protected $name = 'appointment-reminders:run';
    protected $description = 'Avvia dalle 08:00 Europe/Rome il batch giornaliero dei reminder appuntamenti.';
    protected $usage = 'appointment-reminders:run [--dry-run] [--force] [--reference-date=YYYY-MM-DD]';
    protected $options = [
        '--dry-run' => 'Mostra il piano di invio senza spedire e senza chiudere il batch giornaliero.',
        '--force' => 'Esegue anche prima delle 08:00 o se il batch giornaliero risulta già completato.',
        '--reference-date=' => 'Usa una data di riferimento esplicita per calcolare i giorni di anticipo.',
    ];

    public function run(array $params)
    {
        try {
            $result = (new AppointmentReminderScheduledRunService())->run([
                'dry_run' => $this->hasFlag($params, 'dry-run'),
                'force' => $this->hasFlag($params, 'force'),
                'reference_date' => $this->readOptionValue($params, '--reference-date'),
            ]);

            $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            CLI::write(is_string($json) ? $json : '{"ok":false,"status":"json_error"}', !empty($result['ok']) ? 'green' : 'yellow');

            return !empty($result['ok']) ? EXIT_SUCCESS : EXIT_ERROR;
        } catch (\Throwable $e) {
            CLI::error('Scheduler reminder fallito: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }

    private function readOptionValue(array $params, string $option): ?string
    {
        $normalizedOption = rtrim(ltrim($option, '-'), '=');
        $cliValue = CLI::getOption($normalizedOption);
        if ($cliValue !== null && $cliValue !== false && $cliValue !== true) {
            return trim((string) $cliValue);
        }

        $prefix = $option . '=';
        foreach ($params as $param) {
            if (str_starts_with((string) $param, $prefix)) {
                return substr((string) $param, strlen($prefix));
            }
        }

        foreach ((array) ($_SERVER['argv'] ?? []) as $param) {
            if (str_starts_with((string) $param, $prefix)) {
                return substr((string) $param, strlen($prefix));
            }
        }

        return null;
    }

    private function hasFlag(array $params, string $option): bool
    {
        if (CLI::getOption($option) !== null) {
            return true;
        }

        $flag = '--' . ltrim($option, '-');
        if (in_array($flag, $params, true)) {
            return true;
        }

        return in_array($flag, (array) ($_SERVER['argv'] ?? []), true);
    }
}
