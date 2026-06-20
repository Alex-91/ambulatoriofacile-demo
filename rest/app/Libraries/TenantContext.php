<?php

namespace App\Libraries;

class TenantContext
{
    public int $tenantId;
    public string $tenantKey;
    public string $tenantName;
    public string $tenantStatus;
    public string $onboardingStatus;
    public string $packageCode;
    public string $packageName;
    public string $tenantRole;
    public int $platformUserId;
    public int $appUserId;
    public string $storageKey;
    public string $featureProfile;

    /** @var array<string, bool> */
    public array $featureFlags;

    /**
     * @param array<string, bool> $featureFlags
     */
    public function __construct(
        int $tenantId = 0,
        string $tenantKey = '',
        string $tenantName = '',
        string $tenantStatus = '',
        string $onboardingStatus = '',
        string $packageCode = '',
        string $packageName = '',
        string $tenantRole = '',
        int $platformUserId = 0,
        int $appUserId = 0,
        string $storageKey = '',
        string $featureProfile = '',
        array $featureFlags = []
    ) {
        $this->tenantId = $tenantId;
        $this->tenantKey = $tenantKey;
        $this->tenantName = $tenantName;
        $this->tenantStatus = $tenantStatus;
        $this->onboardingStatus = $onboardingStatus;
        $this->packageCode = $packageCode;
        $this->packageName = $packageName;
        $this->tenantRole = $tenantRole;
        $this->platformUserId = $platformUserId;
        $this->appUserId = $appUserId;
        $this->storageKey = $storageKey;
        $this->featureProfile = $featureProfile;
        $this->featureFlags = $featureFlags;
    }

    public static function fromArray(array $data): self
    {
        $featureFlags = [];
        foreach ((array) ($data['feature_flags'] ?? []) as $featureKey => $enabled) {
            $featureFlags[(string) $featureKey] = (bool) $enabled;
        }

        return new self(
            (int) ($data['tenant_id'] ?? 0),
            trim((string) ($data['tenant_key'] ?? '')),
            trim((string) ($data['tenant_name'] ?? '')),
            trim((string) ($data['tenant_status'] ?? '')),
            trim((string) ($data['onboarding_status'] ?? '')),
            trim((string) ($data['package_code'] ?? '')),
            trim((string) ($data['package_name'] ?? '')),
            trim((string) ($data['tenant_role'] ?? '')),
            (int) ($data['platform_user_id'] ?? 0),
            (int) ($data['app_user_id'] ?? 0),
            trim((string) ($data['storage_key'] ?? '')),
            trim((string) ($data['feature_profile'] ?? '')),
            $featureFlags
        );
    }

    public function isValid(): bool
    {
        return $this->tenantId > 0 && $this->tenantKey !== '';
    }

    public function allows(string $featureKey): bool
    {
        $featureKey = trim($featureKey);
        if ($featureKey === '') {
            return false;
        }

        return (bool) ($this->featureFlags[$featureKey] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'tenant_key' => $this->tenantKey,
            'tenant_name' => $this->tenantName,
            'tenant_status' => $this->tenantStatus,
            'onboarding_status' => $this->onboardingStatus,
            'package_code' => $this->packageCode,
            'package_name' => $this->packageName,
            'tenant_role' => $this->tenantRole,
            'platform_user_id' => $this->platformUserId,
            'app_user_id' => $this->appUserId,
            'storage_key' => $this->storageKey,
            'feature_profile' => $this->featureProfile,
            'feature_flags' => $this->featureFlags,
        ];
    }
}
