<?php

namespace App\Models;

use CodeIgniter\Model;

class PlatformFeaturesModel extends Model
{
    protected $DBGroup = 'platform';
    protected $table = 'platform_features';
    protected $primaryKey = 'id_feature';
    protected $returnType = 'array';
    protected $allowedFields = [
        'feature_key',
        'feature_name',
        'feature_scope',
        'description',
        'default_enabled',
        'icon_class',
        'is_tenant_managed',
        'tenant_default_enabled',
        'sort_order',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findByKey(string $featureKey): ?array
    {
        $featureKey = trim(strtolower($featureKey));
        if ($featureKey === '') {
            return null;
        }

        return $this->where('LOWER(feature_key)', $featureKey)->first();
    }

    public function findByKeys(array $featureKeys): array
    {
        $normalized = [];
        foreach ($featureKeys as $featureKey) {
            $featureKey = trim(strtolower((string) $featureKey));
            if ($featureKey !== '' && !in_array($featureKey, $normalized, true)) {
                $normalized[] = $featureKey;
            }
        }

        if ($normalized === []) {
            return [];
        }

        return $this->whereIn('feature_key', $normalized)->findAll();
    }
}
