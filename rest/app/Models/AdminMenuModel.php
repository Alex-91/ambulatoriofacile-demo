<?php

namespace App\Models;

use CodeIgniter\Model;

class AdminMenuModel extends Model
{
    protected $table      = 'dap06_mnu';
    protected $primaryKey = 'id_mnu';

    protected $allowedFields = ['titolo_menu','link','class','class_icon','admin','link2'];

    public function getAdminMenu(): array
    {
        if (!$this->db->tableExists($this->table)) {
            return [];
        }

        (new \App\Services\TenantAdminMenuService())->ensureDefaultMenuIfEmpty($this->db);

        $rows = $this->select("titolo_menu, class, class_icon, admin, link2 AS link")
            ->where('admin', 1)
            ->orderBy('ordinamento', 'ASC')
            ->orderBy('id_mnu', 'ASC')
            ->findAll();

        if ($rows !== []) {
            return $rows;
        }

        return $this->select("titolo_menu, class, class_icon, admin, link2 AS link")
            ->where('admin', 1)
            ->orderBy('ordinamento', 'ASC')
            ->orderBy('id_mnu', 'ASC')
            ->findAll();
    }

}
