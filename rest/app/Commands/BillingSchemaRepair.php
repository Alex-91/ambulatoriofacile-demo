<?php

namespace App\Commands;

use App\Models\PlatformTenantsModel;
use App\Services\BillingTenantSchemaService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class BillingSchemaRepair extends BaseCommand
{
    protected $group = 'Billing';
    protected $name = 'billing:schema-repair';
    protected $description = 'Allinea lo schema Fatturazione sul tenant corrente, su un tenant specifico o su tutti i tenant platform.';
    protected $usage = 'billing:schema-repair [options]';
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

        $service = new BillingTenantSchemaService();
        $targets = [$tenantId];

        if ($targets === []) {
            CLI::error('Nessun tenant disponibile per il riallineamento schema Fatturazione.');
            return EXIT_ERROR;
        }

        $finalStatus = 'ok';
        foreach ($targets as $targetTenantId) {
            $result = $service->ensureTenantSchemaReady($targetTenantId, true);
            $status = trim((string) ($result['status'] ?? 'error'));
            $ready = !empty($result['ready']);
            $label = $this->buildTenantLabel($result, $targetTenantId);
            $message = trim((string) ($result['message'] ?? ''));

            CLI::write(
                $label . ': ' . ($message !== '' ? $message : 'Esito non disponibile.'),
                $this->statusColor($status)
            );

            foreach ((array) ($result['cli_messages'] ?? []) as $cliMessage) {
                CLI::write((string) $cliMessage);
            }

            if (!$ready || $status === 'error') {
                $finalStatus = 'error';
            } elseif ($status === 'warning' && $finalStatus !== 'error') {
                $finalStatus = 'warning';
            }

            CLI::newLine();
        }

        CLI::write(
            'Esito finale billing:schema-repair: ' . strtoupper($finalStatus),
            $this->statusColor($finalStatus)
        );

        return $finalStatus === 'error' ? EXIT_ERROR : EXIT_SUCCESS;
    }

    private function runAllTenants(bool $activeOnly): int
    {
        $targets = $this->resolveAllTenantIds($activeOnly);
        if ($targets === []) {
            CLI::error('Nessun tenant disponibile per il riallineamento schema Fatturazione.');
            return EXIT_ERROR;
        }

        $sparkPath = ROOTPATH . 'spark';
        if (!is_file($sparkPath)) {
            CLI::error('File spark non trovato per il rilancio tenant-per-tenant.');
            return EXIT_ERROR;
        }

        $finalStatus = 'ok';
        foreach ($targets as $targetTenantId) {
            CLI::write('Avvio tenant #' . $targetTenantId . '...', 'yellow');
            $command = escapeshellarg(PHP_BINARY)
                . ' '
                . escapeshellarg($sparkPath)
                . ' billing:schema-repair --tenant-id=' . $targetTenantId;

            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);

            foreach ($output as $line) {
                CLI::write((string) $line);
            }

            if ($exitCode !== EXIT_SUCCESS) {
                $finalStatus = 'error';
            }

            CLI::newLine();
        }

        CLI::write(
            'Esito finale billing:schema-repair: ' . strtoupper($finalStatus),
            $this->statusColor($finalStatus)
        );

        return $finalStatus === 'error' ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function resolveAllTenantIds(bool $activeOnly): array
    {
        $model = new PlatformTenantsModel();
        $builder = $model->orderBy('is_active', 'DESC')
            ->orderBy('tenant_name', 'ASC')
            ->orderBy('id_tenant', 'ASC');

        if ($activeOnly) {
            $builder = $builder->where('is_active', 1);
        }

        $rows = $builder->findAll();

        return array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id_tenant'] ?? 0),
            is_array($rows) ? $rows : []
        )));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function buildTenantLabel(array $result, int $fallbackTenantId): string
    {
        $tenantId = (int) ($result['tenant_id'] ?? 0);
        $tenantName = trim((string) ($result['tenant_name'] ?? ''));

        if ($tenantId > 0 && $tenantName !== '') {
            return 'Tenant #' . $tenantId . ' ' . $tenantName;
        }

        if ($tenantId > 0) {
            return 'Tenant #' . $tenantId;
        }

        return $fallbackTenantId > 0 ? 'Tenant #' . $fallbackTenantId : 'Tenant runtime';
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
