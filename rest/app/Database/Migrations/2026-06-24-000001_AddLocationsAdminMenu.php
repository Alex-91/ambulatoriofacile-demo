<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLocationsAdminMenu extends Migration
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
                    ->where('link2', 'agenda/gestione-sedi')
                    ->orWhere('link', 'agenda/gestione-sedi')
                    ->orWhere('link', 'agenda/sedi')
                    ->orWhere('link', 'anagrafica/sedi')
                    ->orWhere('titolo_menu', 'Gestione sedi')
                ->groupEnd()
            ->groupEnd()
            ->orderBy('id_mnu', 'ASC')
            ->get()
            ->getRowArray();

        $payload = [
            'titolo_menu' => 'Gestione sedi',
            'link' => 'agenda/gestione-sedi',
            'link2' => 'agenda/gestione-sedi',
            'class' => '',
            'class_icon' => 'fa-map-marker',
            'admin' => 1,
            'ordinamento' => 450,
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
                    ->where('link2', 'agenda/gestione-sedi')
                    ->orWhere('link', 'agenda/gestione-sedi')
                    ->orWhere('link', 'agenda/sedi')
                    ->orWhere('link', 'anagrafica/sedi')
                ->groupEnd()
            ->groupEnd()
            ->delete();
    }
}
