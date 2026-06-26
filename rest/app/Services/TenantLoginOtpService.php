<?php

namespace App\Services;

class TenantLoginOtpService
{
    public const FEATURE_KEY = 'tenant_login_otp';
    public const SESSION_KEY_REQUIRED = 'tenant_login_otp_required';

    private TenantCatalogService $catalog;
    private TenantFeatureService $features;

    public function __construct(
        ?TenantCatalogService $catalog = null,
        ?TenantFeatureService $features = null
    ) {
        $this->catalog = $catalog ?? new TenantCatalogService();
        $this->features = $features ?? new TenantFeatureService();
    }

    public function isOtpRequiredForTenant(int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }

        $featureMap = $this->features->resolveEffectiveFeatureMapForTenant($tenantId);

        return !empty($featureMap[self::FEATURE_KEY]);
    }

    public function isOtpRequiredForCurrentSession(): bool
    {
        return $this->isOtpRequiredForTenant($this->resolveTenantIdForCurrentSession());
    }

    public function syncCurrentSessionRequirement(?bool $required = null): bool
    {
        $required = $required ?? $this->isOtpRequiredForCurrentSession();

        if ($required) {
            session()->set(self::SESSION_KEY_REQUIRED, true);
        } else {
            session()->remove(self::SESSION_KEY_REQUIRED);
        }

        return $required;
    }

    public function resolveTenantIdForCurrentSession(): int
    {
        $tenantContext = session()->get(TenantContextService::SESSION_KEY);
        if (is_array($tenantContext)) {
            $tenantId = (int) ($tenantContext['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                return $tenantId;
            }
        }

        $pendingRuntime = session()->get(LegacyTenantSessionService::SESSION_KEY_PENDING_RUNTIME);
        if (is_array($pendingRuntime)) {
            $tenantId = (int) ($pendingRuntime['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                return $tenantId;
            }
        }

        $tenant = $this->catalog->resolveCurrentRuntimeTenant();
        return (int) ($tenant['id_tenant'] ?? 0);
    }
}
