<?php
namespace App\Services;

use App\Libraries\TenantContext;

/** Independent entitlement: never inherited from billing and never bypassed locally. */
class PolyclinicFeatureService extends BillingFeatureService
{
    public const FEATURE_KEY = 'polyclinic_billing';

    public function featureKey(): string { return self::FEATURE_KEY; }

    public function isEnabledForContext(?TenantContext $context): bool
    {
        return $context !== null && $context->isValid() && $this->isEnabledForTenant($context->tenantId);
    }

    public function allowsLocalTestingBypass(?TenantContext $context, ?string $featureKey = null): bool { return false; }
}
