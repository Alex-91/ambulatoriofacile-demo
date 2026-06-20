<?php

namespace App\Models;

use CodeIgniter\Model;

class PlatformPackageFeaturesModel extends Model
{
    protected $DBGroup = 'platform';
    protected $table = 'platform_package_features';
    protected $primaryKey = 'id_package_feature';
    protected $returnType = 'array';
    protected $allowedFields = [
        'id_package',
        'id_feature',
        'is_enabled',
        'config_json',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
