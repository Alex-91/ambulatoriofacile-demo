<?php

namespace App\Services;

use Config\Database;

class TenantDatabaseConnector
{
    /**
     * @return array<string, mixed>
     */
    public function buildConnectionConfig(array $tenant): array
    {
        $dbHost = trim((string) ($tenant['db_host'] ?? ''));
        $dbName = trim((string) ($tenant['db_name'] ?? ''));
        $dbUser = trim((string) ($tenant['db_username'] ?? ''));
        $dbPasswordRef = trim((string) ($tenant['db_password_ref'] ?? ''));

        if ($dbHost === '' || $dbName === '' || $dbUser === '' || $dbPasswordRef === '') {
            throw new \RuntimeException('Configurazione database tenant incompleta.');
        }

        $password = (string) env($dbPasswordRef, '');
        if ($password === '') {
            throw new \RuntimeException('Variabile ambiente tenant non trovata: ' . $dbPasswordRef);
        }

        $databaseConfig = new \Config\Database();
        $base = $databaseConfig->platform;

        $base['hostname'] = $dbHost;
        $base['database'] = $dbName;
        $base['username'] = $dbUser;
        $base['password'] = $password;
        $base['port'] = (int) ($tenant['db_port'] ?? 3306);
        $base['DBDriver'] = trim((string) ($tenant['db_driver'] ?? '')) !== ''
            ? trim((string) ($tenant['db_driver'] ?? ''))
            : ($base['DBDriver'] ?? 'MySQLi');
        $base['DBPrefix'] = (string) ($tenant['db_prefix'] ?? '');

        return $base;
    }

    public function connect(array $tenant): \CodeIgniter\Database\BaseConnection
    {
        return Database::connect($this->buildConnectionConfig($tenant), false);
    }
}
