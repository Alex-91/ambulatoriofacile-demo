<?php

namespace App\Commands;

use App\Services\TenantCatalogService;
use App\Services\TsHealthcheckService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TsHealthcheck extends BaseCommand
{
    protected $group = 'TS';
    protected $name = 'ts:healthcheck';
    protected $description = 'Esegue l healthcheck locale TS per un tenant e aggiorna lo stato salvato del profilo.';
    protected $usage = 'ts:healthcheck [options]';
    protected $options = [
        '--tenant-id=' => 'ID tenant da usare. Se omesso usa il tenant runtime corrente risolto dal DB locale.',
        '--json=' => 'Se impostato a 1 stampa il risultato completo in JSON.',
    ];

    public function run(array $params)
    {
        $tenantCatalog = new TenantCatalogService();
        $service = new TsHealthcheckService();

        $tenantId = (int) ($this->readOptionValue($params, '--tenant-id') ?? 0);
        $jsonOutput = trim((string) ($this->readOptionValue($params, '--json') ?? '0')) === '1';
        $tenant = $tenantId > 0
            ? $tenantCatalog->getTenantById($tenantId)
            : $tenantCatalog->resolveCurrentRuntimeTenant();

        if (!is_array($tenant) || (int) ($tenant['id_tenant'] ?? 0) <= 0) {
            CLI::error('Tenant runtime non risolto. Passa --tenant-id oppure controlla il mapping platform_tenants -> db_name.');
            return EXIT_ERROR;
        }

        $tenantId = (int) ($tenant['id_tenant'] ?? 0);

        try {
            $result = $service->runForTenant($tenantId);
        } catch (\Throwable $e) {
            CLI::error('Healthcheck TS non riuscito: ' . $e->getMessage());
            return EXIT_ERROR;
        }

        if ($jsonOutput) {
            CLI::write(
                (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            return ($result['status'] ?? '') === 'error' ? EXIT_ERROR : EXIT_SUCCESS;
        }

        CLI::write(
            'Tenant: '
            . trim((string) ($tenant['tenant_name'] ?? $tenant['tenant_key'] ?? $tenantId)),
            'green'
        );
        CLI::write(
            'Esito healthcheck TS: ' . strtoupper(trim((string) ($result['status'] ?? 'error'))),
            $this->statusColor((string) ($result['status'] ?? 'error'))
        );
        CLI::write((string) ($result['message'] ?? ''));

        $supportLog = is_array($result['support_log'] ?? null) ? $result['support_log'] : [];
        $traceId = trim((string) ($supportLog['trace_id'] ?? ''));
        if ($traceId !== '') {
            CLI::write('Trace supporto: ' . $traceId);
        }

        foreach ((array) ($result['checks'] ?? []) as $check) {
            $label = trim((string) ($check['label'] ?? 'Controllo'));
            $status = trim((string) ($check['status'] ?? 'warning'));
            $message = trim((string) ($check['message'] ?? ''));
            CLI::write(
                'Check: ' . $label . ' => ' . $message,
                $this->statusColor($status)
            );
        }

        foreach ((array) ($result['errors'] ?? []) as $message) {
            CLI::error((string) $message);
        }

        foreach ((array) ($result['warnings'] ?? []) as $message) {
            CLI::write('Avviso: ' . (string) $message, 'yellow');
        }

        return ($result['status'] ?? '') === 'error' ? EXIT_ERROR : EXIT_SUCCESS;
    }

    private function statusColor(string $status): string
    {
        return match (trim($status)) {
            'ok' => 'green',
            'warning' => 'yellow',
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
