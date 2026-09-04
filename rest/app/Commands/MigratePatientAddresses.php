<?php

namespace App\Commands;

use App\Models\PlatformTenantsModel;
use App\Services\PatientAddressSchemaService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class MigratePatientAddresses extends BaseCommand
{
    protected $group = 'Patients';
    protected $name = 'patients:address-migrate';
    protected $description = 'Prepara residenza e domicilio per uno o tutti gli spazi senza eliminare i campi legacy.';
    protected $usage = 'patients:address-migrate [options]';
    protected $options = [
        '--tenant-id=' => 'ID dello spazio da migrare.',
        '--all=' => 'Se impostato a 1 elabora tutti gli spazi.',
        '--active-only=' => 'Con --all=1 limita agli spazi attivi. Default: 0 (tutti gli spazi).',
        '--dry-run=' => 'Con valore 1 analizza schema e conteggi senza apportare modifiche.',
    ];

    public function run(array $params)
    {
        $tenantId = (int) ($this->option($params, '--tenant-id') ?? 0);
        $all = ($this->option($params, '--all') ?? '0') === '1';
        $activeOnly = ($this->option($params, '--active-only') ?? '0') !== '0';
        $dryRun = ($this->option($params, '--dry-run') ?? '0') === '1';

        if ($all) {
            return $this->runAllTenants($activeOnly, $dryRun);
        }

        if ($tenantId <= 0) {
            CLI::error('Indica --tenant-id oppure --all=1.');
            return EXIT_ERROR;
        }

        $service = new PatientAddressSchemaService();
        $result = $dryRun
            ? $service->inspectTenant($tenantId)
            : $service->ensureTenantReady($tenantId);
        $label = 'Spazio #' . (int) ($result['tenant_id'] ?? $tenantId);
        if (trim((string) ($result['tenant_name'] ?? '')) !== '') {
            $label .= ' ' . trim((string) $result['tenant_name']);
        }

        $ready = !empty($result['ready']);
        $message = trim((string) ($result['message'] ?? 'Esito non disponibile.'));
        if ($dryRun && $ready) {
            $message .= ' Totale=' . (int) ($result['total'] ?? 0)
                . ', solo principale=' . (int) ($result['main_only'] ?? 0)
                . ', principale+domicilio=' . (int) ($result['main_and_domicile'] ?? 0)
                . ', residenza presente=' . (int) ($result['residence_present'] ?? 0)
                . ', domicilio presente=' . (int) ($result['domicile_present'] ?? 0)
                . ', principale=residenza=' . (int) ($result['main_equals_residence'] ?? 0)
                . ', principale=domicilio=' . (int) ($result['main_equals_domicile'] ?? 0)
                . ', campi-residenza=' . implode('/', (array) ($result['residence_field_matches'] ?? []))
                . ', campi-domicilio=' . implode('/', (array) ($result['domicile_field_matches'] ?? []))
                . ', schema=' . (!empty($result['schema_ready']) ? 'pronto' : 'da migrare') . '.';
        }
        CLI::write($label . ': ' . $message, $ready ? 'green' : 'red');

        return $ready ? EXIT_SUCCESS : EXIT_ERROR;
    }

    private function runAllTenants(bool $activeOnly, bool $dryRun): int
    {
        $tenantIds = $this->allTenantIds($activeOnly);
        if ($tenantIds === []) {
            CLI::error('Nessuno spazio disponibile per la migrazione indirizzi.');
            return EXIT_ERROR;
        }

        $sparkPath = ROOTPATH . 'spark';
        if (!is_file($sparkPath)) {
            CLI::error('File spark non trovato.');
            return EXIT_ERROR;
        }

        $failed = false;
        foreach ($tenantIds as $tenantId) {
            CLI::write('Avvio spazio #' . $tenantId . '...', 'yellow');
            $command = escapeshellarg(PHP_BINARY)
                . ' '
                . escapeshellarg($sparkPath)
                . ' patients:address-migrate --tenant-id=' . $tenantId
                . ($dryRun ? ' --dry-run=1' : '');
            $output = [];
            $exitCode = EXIT_ERROR;
            exec($command, $output, $exitCode);

            foreach ($output as $line) {
                CLI::write((string) $line);
            }

            $failed = $failed || $exitCode !== EXIT_SUCCESS;
            CLI::newLine();
        }

        CLI::write(
            'Esito finale ' . ($dryRun ? 'analisi' : 'migrazione') . ' indirizzi: '
                . ($failed ? 'ERRORE' : 'OK'),
            $failed ? 'red' : 'green'
        );

        return $failed ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function allTenantIds(bool $activeOnly): array
    {
        $model = new PlatformTenantsModel();
        $builder = $model->orderBy('id_tenant', 'ASC');
        if ($activeOnly) {
            $builder = $builder->where('is_active', 1);
        }

        return array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id_tenant'] ?? 0),
            $builder->findAll()
        )));
    }

    private function option(array $params, string $option): ?string
    {
        $normalizedOption = rtrim(trim($option), '=');
        $name = ltrim($normalizedOption, '-');
        $value = CLI::getOption($name);
        if (($value === null || $value === false) && str_contains($name, '-')) {
            $value = CLI::getOption(str_replace('-', '_', $name));
        }
        if ($value !== null && $value !== false) {
            return (string) $value;
        }

        $prefix = $normalizedOption . '=';
        foreach (array_merge($params, (array) ($_SERVER['argv'] ?? [])) as $param) {
            if (str_starts_with((string) $param, $prefix)) {
                return substr((string) $param, strlen($prefix));
            }
        }

        return null;
    }
}
