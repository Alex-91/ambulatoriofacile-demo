<?php

namespace App\Services;

use App\Config\BillingModule;
use App\Libraries\TenantContext;

class BillingFeatureService
{
    private TenantFeatureService $tenantFeatures;

    public function __construct(?TenantFeatureService $tenantFeatures = null)
    {
        $this->tenantFeatures = $tenantFeatures ?? new TenantFeatureService();
    }

    public function featureKey(): string
    {
        return BillingModule::FEATURE_KEY;
    }

    public function isEnabledForTenant(int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }

        $featureMap = $this->tenantFeatures->resolveEffectiveFeatureMapForTenant($tenantId);
        if (!empty($featureMap[$this->featureKey()])) {
            return true;
        }

        $context = $this->resolveCurrentTenantContextForBypass($tenantId);

        return $this->allowsLocalTestingBypass($context);
    }

    public function isEnabledForContext(?TenantContext $context): bool
    {
        if ($context === null || !$context->isValid()) {
            return false;
        }

        return $context->allows($this->featureKey())
            || $this->allowsLocalTestingBypass($context);
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

    private function resolveCurrentTenantContextForBypass(int $tenantId): ?TenantContext
    {
        try {
            $context = (new TenantContextService())->getCurrentTenant();
        } catch (\Throwable) {
            return null;
        }

        if ($context === null || !$context->isValid()) {
            return null;
        }

        return $context->tenantId === $tenantId ? $context : null;
    }

    private function isLocalRequest(): bool
    {
        $forwardedHost = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        $host = $forwardedHost !== '' ? trim((string) explode(',', $forwardedHost)[0]) : trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = strtolower((string) preg_replace('/:\d+$/', '', $host));

        return in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true);
    }
}
