<?php

namespace App\Commands;

use App\Services\TenantProvisioningService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CreateTenantSpace extends BaseCommand
{
    protected $group = 'Platform';
    protected $name = 'tenant:create-space';
    protected $description = 'Crea lo scheletro piattaforma di un nuovo spazio cliente multi-tenant.';
    protected $usage = 'tenant:create-space <tenant-key> <tenant-name> <package-code> <master-email> [master-first-name] [master-last-name] [options]';
    protected $arguments = [
        'tenant-key' => 'Chiave tecnica del tenant, es: studio-verde.',
        'tenant-name' => 'Nome visualizzato del tenant.',
        'package-code' => 'Pacchetto iniziale: base, team, enterprise.',
        'master-email' => 'Email del tenant master.',
        'master-first-name' => 'Nome del tenant master (facoltativo).',
        'master-last-name' => 'Cognome del tenant master (facoltativo).',
    ];
    protected $options = [
        '--legal-name=' => 'Ragione sociale del cliente.',
        '--db-host=' => 'Host database del tenant.',
        '--db-port=' => 'Porta database del tenant.',
        '--db-name=' => 'Nome database del tenant.',
        '--db-user=' => 'Username database del tenant.',
        '--db-password-ref=' => 'Nome della env var che contiene la password DB del tenant.',
        '--db-driver=' => 'Driver DB del tenant, default MySQLi.',
        '--db-prefix=' => 'Prefisso tabelle tenant, se usato.',
        '--storage-key=' => 'Chiave storage custom, default uguale al tenant-key.',
        '--feature-profile=' => 'Profilo/verticale del tenant.',
        '--enable-features=' => 'Lista feature da abilitare come override, separate da virgola.',
        '--disable-features=' => 'Lista feature da disabilitare come override, separate da virgola.',
        '--status=' => 'Stato tenant, default draft.',
        '--onboarding-status=' => 'Stato onboarding, default draft.',
        '--master-password=' => 'Password iniziale del tenant master. Se omessa viene generata.',
        '--prepare-local-dirs' => 'Prepara anche le cartelle locali upload/writable del tenant.',
    ];

    public function run(array $params)
    {
        $tenantKey = trim((string) ($params[0] ?? ''));
        $tenantName = trim((string) ($params[1] ?? ''));
        $packageCode = trim((string) ($params[2] ?? ''));
        $masterEmail = trim((string) ($params[3] ?? ''));
        $masterFirstName = trim((string) ($params[4] ?? ''));
        $masterLastName = trim((string) ($params[5] ?? ''));

        if ($tenantKey === '') {
            $tenantKey = CLI::prompt('Tenant key');
        }
        if ($tenantName === '') {
            $tenantName = CLI::prompt('Tenant name');
        }
        if ($packageCode === '') {
            $packageCode = CLI::prompt('Package code', ['base', 'team', 'enterprise']);
        }
        if ($masterEmail === '') {
            $masterEmail = CLI::prompt('Master email');
        }

        $service = new TenantProvisioningService();

        $payload = [
            'tenant_key' => $tenantKey,
            'tenant_name' => $tenantName,
            'package_code' => $packageCode,
            'master_email' => $masterEmail,
            'master_first_name' => $masterFirstName,
            'master_last_name' => $masterLastName,
            'legal_name' => $this->readOptionValue($params, '--legal-name'),
            'db_host' => $this->readOptionValue($params, '--db-host'),
            'db_port' => (int) ($this->readOptionValue($params, '--db-port') ?: 3306),
            'db_name' => $this->readOptionValue($params, '--db-name'),
            'db_username' => $this->readOptionValue($params, '--db-user'),
            'db_password_ref' => $this->readOptionValue($params, '--db-password-ref'),
            'db_driver' => $this->readOptionValue($params, '--db-driver'),
            'db_prefix' => $this->readOptionValue($params, '--db-prefix'),
            'storage_key' => $this->readOptionValue($params, '--storage-key'),
            'feature_profile' => $this->readOptionValue($params, '--feature-profile'),
            'enabled_features' => $this->readOptionValue($params, '--enable-features'),
            'disabled_features' => $this->readOptionValue($params, '--disable-features'),
            'status' => $this->readOptionValue($params, '--status') ?: 'draft',
            'onboarding_status' => $this->readOptionValue($params, '--onboarding-status') ?: 'draft',
            'master_password' => $this->readOptionValue($params, '--master-password'),
            'is_active' => 1,
        ];

        try {
            $result = $service->createTenant($payload);

            CLI::write('Tenant creato con successo.', 'green');
            CLI::write('ID tenant: ' . (int) ($result['tenant']['id_tenant'] ?? 0));
            CLI::write('Tenant key: ' . (string) ($result['tenant']['tenant_key'] ?? ''));
            CLI::write('Pacchetto: ' . (string) ($result['package']['package_code'] ?? ''));
            CLI::write('Tenant master: ' . (string) ($result['platform_user']['email'] ?? ''));

            if (!empty($result['platform_user']['was_created'])) {
                CLI::write('Platform user creato: si');
            } else {
                CLI::write('Platform user creato: no, account piattaforma gia esistente');
            }

            if (!empty($result['platform_user']['temporary_password'])) {
                CLI::write('Password temporanea: ' . (string) $result['platform_user']['temporary_password'], 'yellow');
            }

            $runtime = (array) ($result['runtime'] ?? []);
            CLI::newLine();
            CLI::write('Blueprint runtime tenant', 'light_gray');
            CLI::write('- Upload: ' . (string) ($runtime['upload_path'] ?? ''));
            CLI::write('- Writable: ' . (string) ($runtime['writable_path'] ?? ''));
            CLI::write('- DB host: ' . (string) ($runtime['db_host'] ?? ''));
            CLI::write('- DB name: ' . (string) ($runtime['db_name'] ?? ''));
            CLI::write('- DB user: ' . (string) ($runtime['db_username'] ?? ''));
            CLI::write('- DB password ref: ' . (string) ($runtime['db_password_ref'] ?? ''));

            if ($this->hasFlag($params, '--prepare-local-dirs')) {
                $paths = $service->prepareLocalDirectories(
                    (string) ($result['tenant']['tenant_key'] ?? ''),
                    (string) ($result['tenant']['storage_key'] ?? '')
                );

                CLI::newLine();
                CLI::write('Cartelle locali create', 'light_gray');
                foreach ($paths as $label => $path) {
                    CLI::write('- ' . $label . ': ' . $path);
                }
            }
        } catch (\Throwable $e) {
            CLI::error('Creazione tenant fallita: ' . $e->getMessage());
        }
    }

    private function hasFlag(array $params, string $flag): bool
    {
        return in_array($flag, $params, true);
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
