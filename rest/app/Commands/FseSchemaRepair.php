<?php

namespace App\Commands;

use App\Models\PlatformTenantsModel;
use App\Services\FseTenantSchemaService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class FseSchemaRepair extends BaseCommand
{
    protected $group = 'FSE 2.0';
    protected $name = 'fse:schema-repair';
    protected $description = 'Installa o verifica le tabelle FSE nel database di uno o più tenant.';
    protected $usage = 'fse:schema-repair [options]';
    protected $options = [
        '--tenant-id=' => 'ID tenant da riallineare. Se omesso usa il tenant runtime corrente.',
        '--all=' => 'Se impostato a 1 elabora tutti i tenant del catalogo platform.',
        '--active-only=' => 'Con --all=1 limita ai tenant attivi. Default: 1.',
    ];

    public function run(array $params)
    {
        $tenantId = (int) ($this->readOptionValue($params, '--tenant-id') ?? 0);
        $all = trim((string) ($this->readOptionValue($params, '--all') ?? '0')) === '1';
        $activeOnly = trim((string) ($this->readOptionValue($params, '--active-only') ?? '1')) !== '0';

        if ($all) {
            return $this->runAllTenants($activeOnly);
        }

        return $this->runOneTenant($tenantId);
    }

    private function runOneTenant(int $tenantId): int
    {
        $result = (new FseTenantSchemaService())->ensureTenantSchemaReady($tenantId, true);
        $status = trim((string) ($result['status'] ?? 'error'));
        $tenantLabel = $this->tenantLabel($result, $tenantId);
        CLI::write($tenantLabel . ': ' . (string) ($result['message'] ?? 'Esito non disponibile.'), $this->statusColor($status));
        foreach ((array) ($result['cli_messages'] ?? []) as $message) {
            CLI::write((string) $message);
        }

        return !empty($result['ready']) && $status !== 'error' ? EXIT_SUCCESS : EXIT_ERROR;
    }

    private function runAllTenants(bool $activeOnly): int
    {
        $model = new PlatformTenantsModel();
        $builder = $model->orderBy('is_active', 'DESC')->orderBy('tenant_name', 'ASC')->orderBy('id_tenant', 'ASC');
        if ($activeOnly) {
            $builder = $builder->where('is_active', 1);
        }
        $rows = $builder->findAll();
        $tenantIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id_tenant'] ?? 0),
            is_array($rows) ? $rows : []
        )));
        if ($tenantIds === []) {
            CLI::error('Nessun tenant disponibile per il riallineamento schema FSE.');
            return EXIT_ERROR;
        }

        $sparkPath = ROOTPATH . 'spark';
        if (!is_file($sparkPath)) {
            CLI::error('File spark non trovato.');
            return EXIT_ERROR;
        }

        $finalExit = EXIT_SUCCESS;
        foreach ($tenantIds as $tenantId) {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($sparkPath) . ' fse:schema-repair --tenant-id=' . $tenantId;
            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);
            foreach ($output as $line) {
                CLI::write((string) $line);
            }
            if ($exitCode !== EXIT_SUCCESS) {
                $finalExit = EXIT_ERROR;
            }
        }

        return $finalExit;
    }

    /** @param array<string, mixed> $result */
    private function tenantLabel(array $result, int $fallbackTenantId): string
    {
        $tenantId = (int) ($result['tenant_id'] ?? $fallbackTenantId);
        $tenantName = trim((string) ($result['tenant_name'] ?? ''));
        return 'Tenant ' . ($tenantId > 0 ? '#' . $tenantId : 'runtime') . ($tenantName !== '' ? ' ' . $tenantName : '');
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            'ok' => 'green',
            'warning' => 'yellow',
            default => 'red',
        };
    }

    private function readOptionValue(array $params, string $option): ?string
    {
        $normalized = rtrim(ltrim($option, '-'), '=');
        $cliValue = CLI::getOption($normalized);
        if (($cliValue === null || $cliValue === false) && str_contains($normalized, '-')) {
            $cliValue = CLI::getOption(str_replace('-', '_', $normalized));
        }
        if ($cliValue !== null && $cliValue !== false) {
            return is_string($cliValue) ? $cliValue : (string) $cliValue;
        }

        $prefix = $option . '=';
        foreach (array_merge($params, (array) ($_SERVER['argv'] ?? [])) as $param) {
            if (str_starts_with((string) $param, $prefix)) {
                return substr((string) $param, strlen($prefix));
            }
        }

        return null;
    }
}
