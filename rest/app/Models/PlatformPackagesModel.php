<?php

namespace App\Models;

use CodeIgniter\Model;

class PlatformPackagesModel extends Model
{
    protected $DBGroup = 'platform';
    protected $table = 'platform_packages';
    protected $primaryKey = 'id_package';
    protected $returnType = 'array';
    protected $allowedFields = [
        'package_code',
        'package_name',
        'description',
        'max_users',
        'is_active',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findByCode(string $packageCode): ?array
    {
        $packageCode = trim(strtolower($packageCode));
        if ($packageCode === '') {
            return null;
        }

        return $this->where('LOWER(package_code)', $packageCode)->first();
    }
}
