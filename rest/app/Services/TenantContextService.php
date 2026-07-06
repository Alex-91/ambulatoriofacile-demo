<?php

namespace App\Services;

use App\Libraries\TenantContext;

class TenantContextService
{
    public const SESSION_KEY = 'tenant_context';

    private TenantCatalogService $catalog;
    private bool $resolvedCurrentTenant = false;
    private ?TenantContext $currentTenant = null;

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
        if ($this->resolvedCurrentTenant) {
            return $this->currentTenant;
        }

        $this->resolvedCurrentTenant = true;

        $raw = session()->get(self::SESSION_KEY);
        $context = is_array($raw) && $raw !== []
            ? TenantContext::fromArray($raw)
            : null;

        if ($context !== null && !$context->isValid()) {
            $context = null;
        }

        $platformUserId = (int) (session()->get('platform_user_id') ?? 0);
        if ($platformUserId > 0) {
            if ($context !== null) {
                $membership = $this->catalog->getTenantMembership($platformUserId, $context->tenantId);
                if ($membership !== null) {
                    $context = $this->catalog->buildTenantContext($membership);
                    $this->setCurrentTenant($context);
                    return $this->currentTenant = $context;
                }
            }

            $context = $this->restoreTenantContextFromSession($platformUserId);
            if ($context !== null) {
                return $this->currentTenant = $context;
            }
        }

        return $this->currentTenant = $context;
    }

    public function setCurrentTenant(TenantContext $context): void
    {
        if (!$context->isValid()) {
            $this->clearCurrentTenant();
            return;
        }

        session()->set(self::SESSION_KEY, $context->toArray());
        $this->currentTenant = $context;
        $this->resolvedCurrentTenant = true;
    }

    public function clearCurrentTenant(): void
    {
        session()->remove(self::SESSION_KEY);
        $this->currentTenant = null;
        $this->resolvedCurrentTenant = true;
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
        $featureKey = trim($featureKey);
        if ($featureKey === '') {
            return false;
        }

        $context = $this->getCurrentTenant();
        if ($context === null) {
            return false;
        }

        if ($context->allows($featureKey)) {
            return true;
        }

        if ($context->tenantId <= 0) {
            return false;
        }

        try {
            $featureMap = $this->catalog->resolveFeatureMapForTenant($context->tenantId);
        } catch (\Throwable $e) {
            log_message('warning', '[TenantContextService] refresh feature map fallita | tenant_id={tenantId} | feature={featureKey} | error={error}', [
                'tenantId' => $context->tenantId,
                'featureKey' => $featureKey,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($featureMap === []) {
            return false;
        }

        $context->featureFlags = $featureMap;
        $this->setCurrentTenant($context);

        return (bool) ($featureMap[$featureKey] ?? false);
    }

    private function restoreTenantContextFromSession(int $platformUserId): ?TenantContext
    {
        $tenants = (array) (session()->get(TenantAppSessionBootstrapService::PLATFORM_SELECTABLE_TENANTS_SESSION_KEY) ?? []);
        if (count($tenants) === 1) {
            $tenantId = (int) ($tenants[0]['id_tenant'] ?? 0);
            if ($tenantId > 0) {
                return $this->activateTenantForPlatformUser($platformUserId, $tenantId);
            }
        }

        $appUserId = (int) (session()->get('userId') ?? session()->get('id_user') ?? 0);
        if ($appUserId > 0) {
            $membership = $this->catalog->findTenantMembershipByAppUser($platformUserId, $appUserId);
            if ($membership !== null) {
                $context = $this->catalog->buildTenantContext($membership);
                $this->setCurrentTenant($context);
                return $context;
            }
        }

        return null;
    }
}
