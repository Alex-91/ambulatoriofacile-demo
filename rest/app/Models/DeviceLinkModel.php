<?php

namespace App\Models;

use CodeIgniter\Model;

class DeviceLinkModel extends Model
{
    protected $table      = 'device_links';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'user_id',
        'token',
        'expires_at',
        'consumed',
        'consumed_at',
        'created_at',
    ];

    protected $useTimestamps = false;
    private array $columnExistsCache = [];

    public function createToken(int $userId, int $ttlMinutes = 10): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable("+{$ttlMinutes} minutes"))->format('Y-m-d H:i:s');

        $this->insert([
            'user_id'    => $userId,
            'token'      => $token,
            'expires_at' => $expiresAt,
            'consumed'   => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    public function findValidToken(string $token): ?array
    {
        $row = $this->where('token', $token)
            ->where('consumed', 0)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->first();

        return $row ?: null;
    }

    public function markConsumed(int $id): void
    {
        $data = [
            'consumed' => 1,
        ];

        if ($this->hasColumn('consumed_at')) {
            $data['consumed_at'] = date('Y-m-d H:i:s');
        }

        $this->update($id, $data);
    }

    private function hasColumn(string $column): bool
    {
        if (!array_key_exists($column, $this->columnExistsCache)) {
            $this->columnExistsCache[$column] = $this->db->fieldExists($column, $this->table);
        }

        return $this->columnExistsCache[$column];
    }
}
