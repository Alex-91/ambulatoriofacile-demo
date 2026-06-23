<?php

namespace App\Models;

use CodeIgniter\Model;

class UserAdminMenuVisibilityModel extends Model
{
    protected $table = 'dap_user_admin_menu';

    protected $allowedFields = [
        'id_user',
        'menu_key',
        'menu_link',
        'can_view',
        'updated_at',
    ];

    public function isAvailable(): bool
    {
        return $this->db->tableExists($this->table);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRowsForUser(int $idUser): array
    {
        if ($idUser <= 0 || !$this->isAvailable()) {
            return [];
        }

        $rows = $this->where('id_user', $idUser)->findAll();

        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }

            $row['id_user'] = (int) ($row['id_user'] ?? 0);
            $row['can_view'] = (int) ($row['can_view'] ?? 0);
            $row['menu_key'] = trim((string) ($row['menu_key'] ?? ''));
            $row['menu_link'] = trim((string) ($row['menu_link'] ?? ''));
        }
        unset($row);

        return array_values(array_filter($rows, static fn($row): bool => is_array($row)));
    }

    public function setVisibility(int $idUser, string $menuKey, string $menuLink, int $canView): bool
    {
        if ($idUser <= 0 || trim($menuKey) === '' || !$this->isAvailable()) {
            return false;
        }

        $payload = [
            'id_user' => $idUser,
            'menu_key' => trim($menuKey),
            'menu_link' => trim($menuLink),
            'can_view' => $canView === 1 ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $existing = $this->where('id_user', $idUser)
            ->where('menu_key', $payload['menu_key'])
            ->first();

        if (is_array($existing)) {
            return (bool) $this->where('id_user', $idUser)
                ->where('menu_key', $payload['menu_key'])
                ->set($payload)
                ->update();
        }

        return (bool) $this->insert($payload, false);
    }
}
