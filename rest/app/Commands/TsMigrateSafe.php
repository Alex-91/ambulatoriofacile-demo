<?php

namespace App\Commands;

use App\Services\TsMigrationSafetyService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TsMigrateSafe extends BaseCommand
{
    protected $group = 'TS';
    protected $name = 'ts:migrate-safe';
    protected $description = 'Applica solo le migration del modulo TS con un pacchetto filtrato e controlli di drift.';
    protected $usage = 'ts:migrate-safe [options]';
    protected $options = [
        '--scope=' => 'Target da migrare: platform, tenant oppure all. Default: all.',
        '--tenant-id=' => 'ID tenant da usare per il database operativo. Se omesso usa il tenant runtime corrente.',
        '--allow-drift=' => 'Se impostato a 1 consente l esecuzione anche se nello stesso gruppo esistono migration App non TS pendenti.',
    ];

    public function run(array $params)
    {
        $service = new TsMigrationSafetyService();
        $scope = trim((string) ($this->readOptionValue($params, '--scope') ?? 'all'));
        $tenantId = (int) ($this->readOptionValue($params, '--tenant-id') ?? 0);
        $allowDrift = trim((string) ($this->readOptionValue($params, '--allow-drift') ?? '0')) === '1';

        try {
            $report = $service->migrateSafe($scope, $tenantId > 0 ? $tenantId : null, $allowDrift);
        } catch (\Throwable $e) {
            CLI::error('Migrazione TS sicura non riuscita: ' . $e->getMessage());
            return EXIT_ERROR;
        }

        foreach ((array) ($report['results'] ?? []) as $result) {
            $this->renderResult((array) $result);
            CLI::newLine();
        }

        $finalStatus = trim((string) ($report['status'] ?? 'error'));
        CLI::write(
            'Esito finale ts:migrate-safe: ' . strtoupper($finalStatus),
            $this->statusColor($finalStatus)
        );

        return in_array($finalStatus, ['error', 'blocked'], true) ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function renderResult(array $result): void
    {
        $status = trim((string) ($result['status'] ?? 'error'));
        $message = trim((string) ($result['message'] ?? ''));
        $before = (array) ($result['before'] ?? []);
        $after = (array) ($result['after'] ?? []);

        CLI::write($message !== '' ? $message : 'Risultato migration TS', $this->statusColor($status));

        foreach ((array) ($result['cli_messages'] ?? []) as $cliMessage) {
            CLI::write((string) $cliMessage);
        }

        if ($status === 'blocked') {
            foreach ((array) ($before['warnings'] ?? []) as $warning) {
                CLI::write('Avviso: ' . (string) $warning, 'yellow');
            }
            return;
        }

        if ((array) ($before['pending_ts_migrations'] ?? []) !== []) {
            CLI::write(
                'Migration TS pendenti prima: ' . $this->previewFiles((array) ($before['pending_ts_migrations'] ?? [])),
                'yellow'
            );
        }

        if ((array) ($after['pending_ts_migrations'] ?? []) === []) {
            CLI::write('Migration TS pendenti dopo: nessuna.', 'green');
        } else {
            CLI::write(
                'Migration TS pendenti dopo: ' . $this->previewFiles((array) ($after['pending_ts_migrations'] ?? [])),
                'yellow'
            );
        }

        foreach ((array) ($after['warnings'] ?? []) as $warning) {
            CLI::write('Avviso: ' . (string) $warning, 'yellow');
        }

        foreach ((array) ($after['errors'] ?? []) as $error) {
            CLI::error((string) $error);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $migrations
     */
    private function previewFiles(array $migrations): string
    {
        $files = [];
        foreach (array_slice($migrations, 0, 5) as $migration) {
            $file = trim((string) ($migration['file'] ?? ''));
            if ($file !== '') {
                $files[] = $file;
            }
        }

        $preview = implode(', ', $files);
        if (count($migrations) > 5) {
            $preview .= ' +' . (count($migrations) - 5) . ' altre';
        }

        return $preview;
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            'ok' => 'green',
            'warning', 'blocked' => 'yellow',
            default => 'red',
        };
    }

    private function readOptionValue(array $params, string $option): ?string
    {
        $normalizedOption = trim($option);
        $normalizedOption = ltrim($normalizedOption, '-');
        $normalizedOption = rtrim($normalizedOption, '=');

        $cliValue = CLI::getOption($normalizedOption);
        if (($cliValue === null || $cliValue === false) && str_contains($normalizedOption, '-')) {
            $cliValue = CLI::getOption(str_replace('-', '_', $normalizedOption));
        }
        if ($cliValue !== null && $cliValue !== false) {
            return is_string($cliValue) ? $cliValue : (string) $cliValue;
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
}
