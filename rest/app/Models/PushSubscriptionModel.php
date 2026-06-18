<?php

namespace App\Models;

use CodeIgniter\Model;

class PushSubscriptionModel extends Model
{
    protected $table      = 'push_subscriptions';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'p256dh',
        'auth',
        'device_id',
        'device_name',
        'device_label',
        'device_brand',
        'device_model',
        'device_type',
        'device_os',
        'browser',
        'ua',
        'is_mobile',
        'is_active',
        'last_seen',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    private array $columnExistsCache = [];
    private ?bool $browserIsNumeric = null;

    public function endpointHash(string $endpoint): string
    {
        return hash('sha256', trim($endpoint));
    }

    public function upsertByEndpoint(array $data)
    {
        $endpoint = trim((string)($data['endpoint'] ?? ''));
        if ($endpoint === '') {
            throw new \InvalidArgumentException('endpoint is required');
        }

        $data['endpoint'] = $endpoint;
        $hash = $this->endpointHash($endpoint);
        if ($this->hasColumn('endpoint_hash')) {
            $data['endpoint_hash'] = $hash;
        } else {
            unset($data['endpoint_hash']);
        }

        if (!array_key_exists('last_seen', $data)) {
            $data['last_seen'] = date('Y-m-d H:i:s');
        }
        if (!$this->hasColumn('last_seen')) {
            unset($data['last_seen']);
        }

        $data = $this->adaptLegacyTypes($data);
        $userId = (int)($data['user_id'] ?? 0);

        if ($userId > 0) {
            $builder = $this->where('user_id', $userId);
            if ($this->hasColumn('endpoint_hash')) {
                $existing = $builder->where('endpoint_hash', $hash)->first();
            } else {
                $existing = $builder->where('endpoint', $endpoint)->first();
            }
        } elseif ($this->hasColumn('endpoint_hash')) {
            $existing = $this->where('endpoint_hash', $hash)->first();
        } else {
            $existing = $this->where('endpoint', $endpoint)->first();
        }

        if ($existing) {
            $data['id'] = $existing['id'];
            $this->save($data);
            return (int)$existing['id'];
        }

        $this->insert($data);
        return (int)$this->getInsertID();
    }

    public function keepOnlyMobileEndpointActive(int $userId, string $endpoint): void
    {
        $hash = $this->endpointHash($endpoint);

        $builder = $this->where('user_id', $userId)
            ->where('is_mobile', 1);

        if ($this->hasColumn('endpoint_hash')) {
            $builder->where('endpoint_hash !=', $hash);
        } else {
            $builder->where('endpoint !=', $endpoint);
        }

        $builder->set(['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')])
            ->update();
    }

    public function getActiveByUser(int $userId, ?string $deviceType = null): array
    {
        $b = $this->db->table($this->table)
            ->where('user_id', $userId)
            ->where('is_active', 1);

        if ($deviceType !== null) {
            $b->where('is_mobile', 1);
        }

        return $b->orderBy('last_seen', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();
    }

    public function userHasActiveMobile(int $userId): bool
    {
        return $this->where('user_id', $userId)
            ->where('is_active', 1)
            ->where('is_mobile', 1)
            ->countAllResults() > 0;
    }

    public function getActiveMobiles(int $userId): array
    {
        return $this->select('device_name, device_label')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->where('is_mobile', 1)
            ->orderBy('last_seen', 'DESC')
            ->findAll();
    }

    public function findByUserAndEndpoint(int $userId, string $endpoint): ?array
    {
        $userId = (int) $userId;
        $endpoint = trim($endpoint);

        if ($userId <= 0 || $endpoint === '') {
            return null;
        }

        $builder = $this->where('user_id', $userId);
        if ($this->hasColumn('endpoint_hash')) {
            $builder->where('endpoint_hash', $this->endpointHash($endpoint));
        } else {
            $builder->where('endpoint', $endpoint);
        }

        $row = $builder
            ->orderBy('is_active', 'DESC')
            ->orderBy('last_seen', 'DESC')
            ->orderBy('id', 'DESC')
            ->first();

        return $row ?: null;
    }

    public function findByEndpoint(string $endpoint): ?array
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return null;
        }

        if ($this->hasColumn('endpoint_hash')) {
            $row = $this->where('endpoint_hash', $this->endpointHash($endpoint))
                ->orderBy('is_active', 'DESC')
                ->orderBy('last_seen', 'DESC')
                ->orderBy('id', 'DESC')
                ->first();
        } else {
            $row = $this->where('endpoint', $endpoint)
                ->orderBy('is_active', 'DESC')
                ->orderBy('last_seen', 'DESC')
                ->orderBy('id', 'DESC')
                ->first();
        }
        return $row ?: null;
    }

    public function deactivateByEndpointHash(string $endpointHash): void
    {
        if (!$this->hasColumn('endpoint_hash')) {
            return;
        }

        $this->where('endpoint_hash', $endpointHash)
             ->set(['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')])
             ->update();
    }

    public function deactivateByEndpoint(string $endpoint, ?int $userId = null): void
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return;
        }

        if ($this->hasColumn('endpoint_hash')) {
            $this->where('endpoint_hash', $this->endpointHash($endpoint));
        } else {
            $this->where('endpoint', $endpoint);
        }

        if ($userId !== null && $userId > 0) {
            $this->where('user_id', $userId);
        }

        $this->set(['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')])
            ->update();
    }

    public function deactivateAllByUser(int $userId): void
    {
        $this->where('user_id', $userId)
            ->set(['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')])
            ->update();
    }

    private function hasColumn(string $column): bool
    {
        if (!array_key_exists($column, $this->columnExistsCache)) {
            $this->columnExistsCache[$column] = $this->db->fieldExists($column, $this->table);
        }

        return $this->columnExistsCache[$column];
    }

    private function adaptLegacyTypes(array $data): array
    {
        if (array_key_exists('browser', $data) && $this->isBrowserNumericColumn()) {
            $browser = trim((string)$data['browser']);
            $data['browser'] = ($browser !== '' && ctype_digit($browser)) ? (int)$browser : null;
        }

        return $data;
    }

    private function isBrowserNumericColumn(): bool
    {
        if ($this->browserIsNumeric !== null) {
            return $this->browserIsNumeric;
        }

        $this->browserIsNumeric = false;
        try {
            $row = $this->db->query("SHOW COLUMNS FROM `{$this->table}` LIKE 'browser'")->getRowArray();
            $type = strtolower((string)($row['Type'] ?? ''));
            $this->browserIsNumeric = $type !== '' && (
                str_contains($type, 'int')
                || str_contains($type, 'decimal')
                || str_contains($type, 'numeric')
            );
        } catch (\Throwable $e) {
            $this->browserIsNumeric = false;
        }

        return $this->browserIsNumeric;
    }
}
