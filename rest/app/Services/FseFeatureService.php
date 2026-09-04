<?php

namespace App\Services;

use App\Config\Fse2;
use App\Libraries\TenantContext;

class FseFeatureService
{
    private TenantFeatureService $features;

    public function __construct(?TenantFeatureService $features = null)
    {
        $this->features = $features ?? new TenantFeatureService();
    }

    public function isEnabledForTenant(int $tenantId): bool
    {
        return $tenantId > 0
            && !empty($this->features->resolveEffectiveFeatureMapForTenant($tenantId)[Fse2::FEATURE_KEY]);
    }

    public function isEnabledForContext(?TenantContext $context): bool
    {
        return $context !== null && $context->isValid() && $context->allows(Fse2::FEATURE_KEY);
    }

    public function allowsLocalTestingBypass(?TenantContext $context): bool
    {
        if ($context === null || !$context->isValid() || (defined('ENVIRONMENT') && ENVIRONMENT === 'production')) {
            return false;
        }
        $host = strtolower((string) preg_replace('/:\d+$/', '', trim((string) ($_SERVER['HTTP_HOST'] ?? ''))));

        return in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true)
            && in_array(strtolower($context->tenantRole), ['tenant_master', 'tenant_admin'], true);
    }
}
