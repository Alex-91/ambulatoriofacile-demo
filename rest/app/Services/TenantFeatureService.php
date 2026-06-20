<?php

namespace App\Services;

use App\Models\PlatformFeaturesModel;
use App\Models\PlatformTenantFeaturePreferencesModel;
use Config\Database;

class TenantFeatureService
{
    private \CodeIgniter\Database\BaseConnection $db;
    private PlatformFeaturesModel $featuresModel;
    private PlatformTenantFeaturePreferencesModel $preferencesModel;

    public function __construct()
    {
        $this->db = Database::connect('platform');
        $this->featuresModel = new PlatformFeaturesModel();
        $this->preferencesModel = new PlatformTenantFeaturePreferencesModel();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listFeatureStatesForTenant(int $tenantId): array
    {
        if ($tenantId <= 0) {
            return [];
        }

        $sql = "
            SELECT
                f.id_feature,
                f.feature_key,
                f.feature_name,
                f.feature_scope,
                f.description,
                f.default_enabled,
                f.icon_class,
                f.is_tenant_managed,
                f.tenant_default_enabled,
                f.sort_order,
                COALESCE(tf.is_enabled, pf.is_enabled, f.default_enabled, 0) AS entitlement_enabled,
                pref.is_enabled AS tenant_preference_enabled,
                pref.updated_by_platform_user,
                pref.updated_at AS tenant_preference_updated_at
            FROM platform_features f
            LEFT JOIN platform_tenants t
                ON t.id_tenant = ?
            LEFT JOIN platform_package_features pf
                ON pf.id_feature = f.id_feature
               AND pf.id_package = t.id_package
            LEFT JOIN platform_tenant_features tf
                ON tf.id_feature = f.id_feature
               AND tf.id_tenant = t.id_tenant
            LEFT JOIN platform_tenant_feature_preferences pref
                ON pref.id_feature = f.id_feature
               AND pref.id_tenant = t.id_tenant
            ORDER BY f.sort_order ASC, f.feature_scope ASC, f.feature_name ASC
        ";

        $rows = $this->db->query($sql, [$tenantId])->getResultArray();

        foreach ($rows as &$row) {
            $entitled = (int) ($row['entitlement_enabled'] ?? 0) === 1;
            $tenantManaged = (int) ($row['is_tenant_managed'] ?? 0) === 1;
            $tenantDefaultEnabled = (int) ($row['tenant_default_enabled'] ?? 1) === 1;
            $tenantPreferenceEnabled = $row['tenant_preference_enabled'] !== null
                ? ((int) $row['tenant_preference_enabled'] === 1)
                : null;

            $effective = $entitled;
            if ($effective && $tenantManaged) {
                $effective = $tenantPreferenceEnabled ?? $tenantDefaultEnabled;
            }

            $row['entitlement_enabled'] = $entitled;
            $row['is_tenant_managed'] = $tenantManaged;
            $row['tenant_default_enabled'] = $tenantDefaultEnabled;
            $row['tenant_preference_enabled'] = $tenantPreferenceEnabled;
            $row['effective_enabled'] = $effective;
            $row['icon_class'] = trim((string) ($row['icon_class'] ?? '')) !== ''
                ? trim((string) ($row['icon_class'] ?? ''))
                : 'fa-toggle-on';
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array<string, bool>
     */
    public function resolveEffectiveFeatureMapForTenant(int $tenantId): array
    {
        $map = [];
        foreach ($this->listFeatureStatesForTenant($tenantId) as $row) {
            $featureKey = trim((string) ($row['feature_key'] ?? ''));
            if ($featureKey === '') {
                continue;
            }

            $map[$featureKey] = (bool) ($row['effective_enabled'] ?? false);
        }

        return $map;
    }

    /**
     * @param array<int, string> $enabledFeatureKeys
     * @return array<string, mixed>
     */
    public function saveTenantManagedFeatures(int $tenantId, array $enabledFeatureKeys, int $updatedByPlatformUserId = 0): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Tenant non valido.');
        }

        $enabledFeatureKeys = $this->normalizeFeatureKeys($enabledFeatureKeys);
        $states = $this->listFeatureStatesForTenant($tenantId);
        if ($states === []) {
            return ['saved' => 0, 'feature_keys' => []];
        }

        $this->db->transBegin();

        try {
            $savedKeys = [];

            foreach ($states as $state) {
                $featureId = (int) ($state['id_feature'] ?? 0);
                $featureKey = trim((string) ($state['feature_key'] ?? ''));
                $tenantManaged = (bool) ($state['is_tenant_managed'] ?? false);
                $entitled = (bool) ($state['entitlement_enabled'] ?? false);

                if ($featureId <= 0 || $featureKey === '' || !$tenantManaged) {
                    continue;
                }

                if (!$entitled) {
                    $this->preferencesModel
                        ->where('id_tenant', $tenantId)
                        ->where('id_feature', $featureId)
                        ->delete();
                    continue;
                }

                $enabled = in_array($featureKey, $enabledFeatureKeys, true);
                $this->preferencesModel->setPreference(
                    $tenantId,
                    $featureId,
                    $enabled,
                    $updatedByPlatformUserId > 0 ? $updatedByPlatformUserId : null
                );
                $savedKeys[] = $featureKey;
            }

            if (!$this->db->transStatus()) {
                throw new \RuntimeException('Salvataggio preferenze feature tenant non riuscito.');
            }

            $this->db->transCommit();

            return [
                'saved' => count($savedKeys),
                'feature_keys' => $savedKeys,
            ];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPlatformFeatures(): array
    {
        return $this->featuresModel
            ->orderBy('sort_order', 'ASC')
            ->orderBy('feature_scope', 'ASC')
            ->orderBy('feature_name', 'ASC')
            ->findAll();
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeFeatureKeys($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        $normalized = [];
        foreach ((array) $value as $featureKey) {
            $featureKey = trim(strtolower((string) $featureKey));
            if ($featureKey !== '' && !in_array($featureKey, $normalized, true)) {
                $normalized[] = $featureKey;
            }
        }

        return $normalized;
    }
}
