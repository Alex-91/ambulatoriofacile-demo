<?php
namespace App\Services;

/** Delegation is to one billing space, never to a legacy staff ID. */
final class AccountantTenantAccess
{
    public function enabled(int $tenantId): bool
    {
        if ($tenantId <= 0) { return false; }
        $db = \Config\Database::connect('platform');
        $tenant = $db->table('platform_tenants')->where('id_tenant', $tenantId)->get()->getRowArray();
        if (!$tenant || (int) $tenant['is_active'] !== 1 || $tenant['status'] !== 'active') { return false; }
        // Read entitlements without local-development bypasses or schema provisioning.
        $row = $db->table('platform_features f')->select('f.default_enabled, f.is_tenant_managed, f.tenant_default_enabled, tf.is_enabled AS tenant_enabled, pf.is_enabled AS package_enabled, pref.is_enabled AS preference_enabled')
            ->join('platform_tenant_features tf', 'tf.id_feature = f.id_feature AND tf.id_tenant = ' . $tenantId, 'left')
            ->join('platform_package_features pf', 'pf.id_feature = f.id_feature AND pf.id_package = ' . (int) ($tenant['id_package'] ?? 0), 'left')
            ->join('platform_tenant_feature_preferences pref', 'pref.id_feature = f.id_feature AND pref.id_tenant = ' . $tenantId, 'left')
            ->where('f.feature_key', \App\Config\BillingModule::FEATURE_KEY)->get()->getRowArray();
        if (!$row) { return false; }
        $entitled = (int) ($row['tenant_enabled'] ?? $row['package_enabled'] ?? $row['default_enabled']) === 1;
        $preferred = !(int) $row['is_tenant_managed'] || (int) ($row['preference_enabled'] ?? $row['tenant_default_enabled']) === 1;
        return $entitled && $preferred;
    }
}
