<?php

namespace App\Services;

class TenantRuntimeBindingService
{
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
    }
}
