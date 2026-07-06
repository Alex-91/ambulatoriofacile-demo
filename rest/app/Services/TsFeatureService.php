<?php

namespace App\Services;

use App\Config\TsBilling;
use App\Libraries\TenantContext;

class TsFeatureService
{
    private TenantFeatureService $tenantFeatures;
    private TsBilling $config;

    public function __construct(
        ?TenantFeatureService $tenantFeatures = null,
        ?TsBilling $config = null
    ) {
        $this->tenantFeatures = $tenantFeatures ?? new TenantFeatureService();
        $this->config = $config ?? config(TsBilling::class);
    }

    public function featureKey(): string
    {
        return TsBilling::FEATURE_KEY;
    }

    public function isEnabledForTenant(int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }

        $featureMap = $this->tenantFeatures->resolveEffectiveFeatureMapForTenant($tenantId);

        return !empty($featureMap[$this->featureKey()]);
    }

    public function isEnabledForContext(?TenantContext $context): bool
    {
        if ($context === null || !$context->isValid()) {
            return false;
        }

        return $context->allows($this->featureKey());
    }

    public function allowsLocalTestingBypass(?TenantContext $context, ?string $featureKey = null): bool
    {
        if ($context === null || !$context->isValid()) {
            return false;
        }

        $requestedFeature = trim((string) ($featureKey ?? $this->featureKey()));
        if ($requestedFeature !== $this->featureKey()) {
            return false;
        }

        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            return false;
        }

        if (!$this->isLocalRequest()) {
            return false;
        }

        $tenantRole = strtolower(trim((string) $context->tenantRole));

        return in_array($tenantRole, ['tenant_master', 'tenant_admin'], true);
    }

    private function isLocalRequest(): bool
    {
        $forwardedHost = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        $host = $forwardedHost !== '' ? trim((string) explode(',', $forwardedHost)[0]) : trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = strtolower((string) preg_replace('/:\d+$/', '', $host));

        return in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true);
    }
}
