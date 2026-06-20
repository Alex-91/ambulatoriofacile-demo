<?php

namespace App\Services;

use App\Libraries\TenantContext;

class TenantContextService
{
    public const SESSION_KEY = 'tenant_context';

    private TenantCatalogService $catalog;

    public function __construct(?TenantCatalogService $catalog = null)
    {
        $this->catalog = $catalog ?? new TenantCatalogService();
    }

    public function hasCurrentTenant(): bool
    {
        $context = $this->getCurrentTenant();
        return $context !== null && $context->isValid();
    }

    public function getCurrentTenant(): ?TenantContext
    {
        $raw = session()->get(self::SESSION_KEY);
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $context = TenantContext::fromArray($raw);
        return $context->isValid() ? $context : null;
    }

    public function setCurrentTenant(TenantContext $context): void
    {
        if (!$context->isValid()) {
            $this->clearCurrentTenant();
            return;
        }

        session()->set(self::SESSION_KEY, $context->toArray());
    }

    public function clearCurrentTenant(): void
    {
        session()->remove(self::SESSION_KEY);
    }

    public function activateTenantForPlatformUser(int $platformUserId, int $tenantId): ?TenantContext
    {
        $membership = $this->catalog->getTenantMembership($platformUserId, $tenantId);
        if (!$membership) {
            $this->clearCurrentTenant();
            return null;
        }

        $context = $this->catalog->buildTenantContext($membership);
        $this->setCurrentTenant($context);

        return $context;
    }

    public function currentTenantAllows(string $featureKey): bool
    {
        $context = $this->getCurrentTenant();
        if ($context === null) {
            return false;
        }

        return $context->allows($featureKey);
    }
}
