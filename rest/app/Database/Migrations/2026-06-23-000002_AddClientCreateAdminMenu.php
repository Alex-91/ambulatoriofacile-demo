<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddClientCreateAdminMenu extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('dap06_mnu')) {
            return;
        }

        $existing = $this->db->table('dap06_mnu')
            ->select('id_mnu')
            ->groupStart()
                ->where('admin', 1)
                ->groupStart()
                    ->where('link2', 'personale/nuovo_cliente')
                    ->orWhere('link', 'personale/nuovo_cliente')
                    ->orWhere('link', 'nuovo_cliente')
                    ->orWhere('titolo_menu', 'Nuovo cliente')
                ->groupEnd()
            ->groupEnd()
            ->orderBy('id_mnu', 'ASC')
            ->get()
            ->getRowArray();

        $payload = [
            'titolo_menu' => 'Nuovo cliente',
            'link' => 'personale/nuovo_cliente',
            'link2' => 'personale/nuovo_cliente',
            'class' => '',
            'class_icon' => 'fa-user-plus',
            'admin' => 1,
            'ordinamento' => 200,
        ];

        if ($existing) {
            $this->db->table('dap06_mnu')
                ->where('id_mnu', (int) ($existing['id_mnu'] ?? 0))
                ->update($payload);
            return;
        }

        $this->db->table('dap06_mnu')->insert($payload);
    }

    public function down()
    {
        if (!$this->db->tableExists('dap06_mnu')) {
            return;
        }

        $this->db->table('dap06_mnu')
            ->groupStart()
                ->where('admin', 1)
                ->groupStart()
                    ->where('link2', 'personale/nuovo_cliente')
                    ->orWhere('link', 'personale/nuovo_cliente')
                    ->orWhere('link', 'nuovo_cliente')
                ->groupEnd()
            ->groupEnd()
            ->delete();
    }
}
