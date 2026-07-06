<?php

namespace App\Commands;

use App\Services\TsMigrationSafetyService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TsDoctor extends BaseCommand
{
    protected $group = 'TS';
    protected $name = 'ts:doctor';
    protected $description = 'Diagnostica schema, migrazioni e allineamento runtime del modulo Fatturazione TS.';
    protected $usage = 'ts:doctor [options]';
    protected $options = [
        '--tenant-id=' => 'ID tenant da ispezionare per il database operativo. Se omesso usa il tenant runtime corrente.',
        '--json=' => 'Se impostato a 1 stampa il report completo in JSON.',
    ];

    public function run(array $params)
    {
        $service = new TsMigrationSafetyService();
        $tenantId = (int) ($this->readOptionValue($params, '--tenant-id') ?? 0);
        $jsonOutput = trim((string) ($this->readOptionValue($params, '--json') ?? '0')) === '1';

        $report = $service->inspectAll($tenantId > 0 ? $tenantId : null);

        if ($jsonOutput) {
            CLI::write(
                (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            return ($report['status'] ?? '') === 'error' ? EXIT_ERROR : EXIT_SUCCESS;
        }

        $this->renderInspection((array) ($report['platform'] ?? []));
        CLI::newLine();
        $this->renderInspection((array) ($report['tenant'] ?? []));
        CLI::newLine();

        $finalStatus = trim((string) ($report['status'] ?? 'error'));
        CLI::write(
            'Esito complessivo: ' . strtoupper($finalStatus),
            $this->statusColor($finalStatus)
        );

        foreach ((array) ($report['errors'] ?? []) as $message) {
            CLI::error((string) $message);
        }

        foreach ((array) ($report['warnings'] ?? []) as $message) {
            CLI::write('Avviso: ' . (string) $message, 'yellow');
        }

        return $finalStatus === 'error' ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /**
     * @param array<string, mixed> $inspection
     */
    private function renderInspection(array $inspection): void
    {
        $label = trim((string) ($inspection['label'] ?? 'Target TS'));
        $status = trim((string) ($inspection['status'] ?? 'error'));
        $message = trim((string) ($inspection['message'] ?? ''));
        $connection = (array) ($inspection['connection'] ?? []);
        $tenant = (array) ($inspection['tenant'] ?? []);
        $pendingTs = (array) ($inspection['pending_ts_migrations'] ?? []);
        $pendingNonTs = (array) ($inspection['pending_non_ts_migrations'] ?? []);

        CLI::write($label . ': ' . strtoupper($status), $this->statusColor($status));
        if ($message !== '') {
            CLI::write($message);
        }

        if ((int) ($tenant['id_tenant'] ?? 0) > 0) {
            CLI::write(
                'Tenant: #'
                . (int) ($tenant['id_tenant'] ?? 0)
                . ' '
                . trim((string) ($tenant['tenant_name'] ?? $tenant['tenant_key'] ?? ''))
            );
        }

        if ($connection !== []) {
            CLI::write(
                'Connessione: '
                . trim((string) ($connection['group'] ?? ''))
                . ' | '
                . trim((string) ($connection['host'] ?? ''))
                . ':'
                . (int) ($connection['port'] ?? 0)
                . ' | '
                . trim((string) ($connection['database'] ?? ''))
            );
        }

        foreach ((array) ($inspection['ts_migrations'] ?? []) as $migration) {
            $applied = !empty($migration['applied']);
            CLI::write(
                'Migration TS: '
                . trim((string) ($migration['file'] ?? ''))
                . ' => '
                . ($applied ? 'APPLIED' : 'PENDING'),
                $applied ? 'green' : 'yellow'
            );
        }

        foreach ((array) ($inspection['schema_checks'] ?? []) as $check) {
            $checkStatus = trim((string) ($check['status'] ?? 'ok'));
            CLI::write(
                'Check: '
                . trim((string) ($check['label'] ?? 'schema'))
                . ' => '
                . trim((string) ($check['message'] ?? '')),
                $this->statusColor($checkStatus)
            );
        }

        foreach ((array) ($inspection['extra_checks'] ?? []) as $check) {
            $checkStatus = trim((string) ($check['status'] ?? 'ok'));
            CLI::write(
                'Check: '
                . trim((string) ($check['label'] ?? 'extra'))
                . ' => '
                . trim((string) ($check['message'] ?? '')),
                $this->statusColor($checkStatus)
            );
        }

        if ($pendingNonTs !== []) {
            CLI::write(
                'Migration non TS pendenti nello stesso gruppo: '
                . $this->previewFiles($pendingNonTs),
                'yellow'
            );
        }

        if ($pendingTs === [] && $pendingNonTs === [] && empty($inspection['errors'])) {
            CLI::write('Nessuna migration pendente rilevata per questo target.', 'green');
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
