<?php
namespace App\Services;

/** Platform entitlement, independent from staff permissions and clinical schema installation. */
class ClinicalFeatureService
{
    public const FEATURE_KEY = 'clinical_records';
    public function __construct(private ?TenantFeatureService $features = null) {}
    public function isEnabledForTenant(int $tenantId): bool
    {
        if ($tenantId <= 0) return false;
        try {
            $this->features ??= new TenantFeatureService();
            return !empty($this->features->resolveEffectiveFeatureMapForTenant($tenantId)[self::FEATURE_KEY]);
        } catch (\Throwable) { return false; }
    }
    public function isEnabledForCurrentTenant(): bool
    {
        try {
            $catalog = new TenantCatalogService();
            $context = (new TenantContextService($catalog))->getCurrentTenant();
            $tenant = $context ? $catalog->getTenantById($context->tenantId) : $catalog->resolveCurrentRuntimeTenant();
            return $tenant && !empty($tenant['is_active']) && $this->isEnabledForTenant((int)$tenant['id_tenant']);
        } catch (\Throwable) { return false; }
    }
    public function assertEnabledForTenant(int $tenantId): void
    {
        if (!$this->isEnabledForTenant($tenantId)) throw new \RuntimeException('Modulo Cartella clinica non attivo per questo spazio.');
    }
}
