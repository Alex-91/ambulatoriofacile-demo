<?php

namespace App\Models;

use CodeIgniter\Model;

class PlatformUserAccessTokensModel extends Model
{
    protected $DBGroup = 'platform';
    protected $table = 'platform_user_access_tokens';
    protected $primaryKey = 'id_platform_user_access_token';
    protected $returnType = 'array';
    protected $allowedFields = [
        'id_platform_user',
        'id_tenant',
        'token_type',
        'token_hash',
        'email_to',
        'expires_at',
        'used_at',
        'metadata_json',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findValidByHash(string $tokenHash, ?string $type = null): ?array
    {
        $builder = $this->where('token_hash', trim($tokenHash))
            ->where('used_at', null)
            ->where('expires_at >=', date('Y-m-d H:i:s'));

        if ($type !== null && $type !== '') {
            $builder->where('token_type', $type);
        }

        return $builder->first();
    }
}
