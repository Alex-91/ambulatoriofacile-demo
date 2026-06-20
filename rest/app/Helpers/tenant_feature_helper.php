<?php

use App\Services\TenantContextService;

if (!function_exists('tenant_feature_enabled')) {
    function tenant_feature_enabled(string $featureKey, bool $defaultWhenNoTenant = true): bool
    {
        $featureKey = trim($featureKey);
        if ($featureKey === '') {
            return false;
        }

        $service = new TenantContextService();
        if (!$service->hasCurrentTenant()) {
            return $defaultWhenNoTenant;
        }

        return $service->currentTenantAllows($featureKey);
    }
}
