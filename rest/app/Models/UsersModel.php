<?php

namespace App\Models;

use CodeIgniter\Model;

class UsersModel extends Model
{
    protected $table      = 'dap01_users';
    protected $primaryKey = 'id_user';

    protected $allowedFields = ['username', 'password', 'datascadenza', 'tipo_user','vector_id', 'privacy','data_privacy'];

   // protected $useTimestamps = true;
    //protected $createdField  = 'created_at';
   // protected $updatedField  = 'updated_at';

    public function findByUsernameInsensitive(string $username): ?array
    {
        $username = trim((string)$username);
        if ($username === '') {
            return null;
        }

        return $this->where('LOWER(username)', strtolower($username))->first();
    }

    public function findOtherByUsernameInsensitive(string $username, int $excludeUserId): ?array
    {
        $username = trim((string)$username);
        if ($username === '') {
            return null;
        }

        $builder = $this->where('LOWER(username)', strtolower($username));
        if ($excludeUserId > 0) {
            $builder = $builder->where('id_user !=', $excludeUserId);
        }

        return $builder->first();
    }
}
