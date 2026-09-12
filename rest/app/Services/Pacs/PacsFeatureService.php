<?php
namespace App\Services\Pacs;

use App\Services\TenantFeatureService;

class PacsFeatureService
{
    public const FEATURE_KEY = 'pacs_dicom';
    public function __construct(private ?TenantFeatureService $features = null) {}
    public function isEnabledForTenant(int $tenantId): bool
    {
        if ($tenantId <= 0) return false;
        try {
            $this->features ??= new TenantFeatureService();
            $map = $this->features->resolveEffectiveFeatureMapForTenant($tenantId);
            return !empty($map[self::FEATURE_KEY]) && !empty($map['clinical_records']);
        } catch (\Throwable) { return false; }
    }
    public function assertEnabled(int $tenantId): void
    {
        if (!$this->isEnabledForTenant($tenantId)) throw new PacsException('Modulo PACS/DICOM non attivo per questo spazio.');
    }
}
