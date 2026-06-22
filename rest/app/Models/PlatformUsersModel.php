<?php

namespace App\Models;

use CodeIgniter\Model;

class PlatformUsersModel extends Model
{
    protected $DBGroup = 'platform';
    protected $table = 'platform_users';
    protected $primaryKey = 'id_platform_user';
    protected $returnType = 'array';
    protected $allowedFields = [
        'email',
        'password_hash',
        'first_name',
        'last_name',
        'is_platform_admin',
        'status',
        'must_reset_password',
        'email_verified_at',
        'last_login_at',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findByEmailInsensitive(string $email): ?array
    {
        $email = trim(strtolower($email));
        if ($email === '') {
            return null;
        }

        return $this->where('LOWER(email)', $email)->first();
    }
}
