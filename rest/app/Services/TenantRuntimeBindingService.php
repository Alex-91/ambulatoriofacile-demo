<?php

namespace App\Services;

class TenantRuntimeBindingService
{
    private const DEFAULT_GROUP = 'default';
    private const TENANT_RUNTIME_GROUP = 'tenantRuntime';

    /**
     * @param array<string, mixed> $config
     */
    public function bindConnectionConfig(array $config): void
    {
        $dbConfig = config(\Config\Database::class);
        $dbConfig->default = $config;
        $dbConfig->tenantRuntime = $config;
        $dbConfig->defaultGroup = self::TENANT_RUNTIME_GROUP;

        $this->resetSharedConnection(self::DEFAULT_GROUP);
        $this->resetSharedConnection(self::TENANT_RUNTIME_GROUP);
    }

    private function resetSharedConnection(string $group): void
    {
        $reflection = new \ReflectionClass(\CodeIgniter\Database\Config::class);
        $instancesProperty = $reflection->getProperty('instances');
        $instancesProperty->setAccessible(true);

        $instances = $instancesProperty->getValue();
        if (!is_array($instances) || !isset($instances[$group])) {
            return;
        }

        $connection = $instances[$group];
        if (is_object($connection) && method_exists($connection, 'close')) {
            try {
                $connection->close();
            } catch (\Throwable $e) {
                log_message('debug', 'TenantRuntimeBindingService close skipped: ' . $e->getMessage(), [
                    'group' => $group,
                ]);
            }
        }

        unset($instances[$group]);
        $instancesProperty->setValue(null, $instances);
    }
}
