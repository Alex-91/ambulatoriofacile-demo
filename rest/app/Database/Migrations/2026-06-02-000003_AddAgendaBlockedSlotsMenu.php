<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAgendaBlockedSlotsMenu extends Migration
{
    private const MENU_CODE = 'AGENDA_SLOT_BLOCCATI';
    private const SOURCE_MENU_CODE = 'ELENCO_FERIE_AGENDA';

    public function up()
    {
        if (!$this->db->tableExists('dap17_agenda_menu')) {
            return;
        }

        $menuRow = $this->db->table('dap17_agenda_menu')
            ->select('id_menu')
            ->where('codice', self::MENU_CODE)
            ->get()
            ->getRowArray();

        if (!$menuRow) {
            $this->db->table('dap17_agenda_menu')->insert([
                'id_menu_padre' => null,
                'codice'        => self::MENU_CODE,
                'tipo_voce'     => 'ITEM',
                'label_menu'    => 'Slot bloccati',
                'icona'         => 'fa fa-unlock-alt',
                'rotta'         => 'agenda/slot-bloccati',
                'ordinamento'   => 41,
                'attivo'        => 1,
            ]);

            $menuId = (int)$this->db->insertID();
        } else {
            $menuId = (int)($menuRow['id_menu'] ?? 0);

            $this->db->table('dap17_agenda_menu')
                ->where('id_menu', $menuId)
                ->update([
                    'label_menu'  => 'Slot bloccati',
                    'icona'       => 'fa fa-unlock-alt',
                    'rotta'       => 'agenda/slot-bloccati',
                    'ordinamento' => 41,
                    'attivo'      => 1,
                ]);
        }

        if ($menuId <= 0 || !$this->db->tableExists('dap18_agenda_menu_permessi')) {
            return;
        }

        $hasPermissions = $this->db->table('dap18_agenda_menu_permessi')
            ->where('id_menu', $menuId)
            ->countAllResults() > 0;

        if ($hasPermissions) {
            return;
        }

        $sourceMenu = $this->db->table('dap17_agenda_menu')
            ->select('id_menu')
            ->where('codice', self::SOURCE_MENU_CODE)
            ->get()
            ->getRowArray();

        if (!$sourceMenu) {
            return;
        }

        $sourceMenuId = (int)($sourceMenu['id_menu'] ?? 0);
        if ($sourceMenuId <= 0) {
            return;
        }

        $rows = $this->db->table('dap18_agenda_menu_permessi')
            ->where('id_menu', $sourceMenuId)
            ->get()
            ->getResultArray();

        if (empty($rows)) {
            return;
        }

        $insert = [];
        foreach ($rows as $row) {
            $insert[] = [
                'id_menu'  => $menuId,
                'id_ruo'   => isset($row['id_ruo']) ? (int)$row['id_ruo'] : null,
                'id_ope'   => isset($row['id_ope']) && $row['id_ope'] !== null ? (int)$row['id_ope'] : null,
                'visibile' => isset($row['visibile']) ? (int)$row['visibile'] : 1,
            ];
        }

        if (!empty($insert)) {
            $this->db->table('dap18_agenda_menu_permessi')->insertBatch($insert);
        }
    }

    public function down()
    {
        if (!$this->db->tableExists('dap17_agenda_menu')) {
            return;
        }

        $menuRow = $this->db->table('dap17_agenda_menu')
            ->select('id_menu')
            ->where('codice', self::MENU_CODE)
            ->get()
            ->getRowArray();

        $menuId = (int)($menuRow['id_menu'] ?? 0);
        if ($menuId <= 0) {
            return;
        }

        if ($this->db->tableExists('dap18_agenda_menu_permessi')) {
            $this->db->table('dap18_agenda_menu_permessi')
                ->where('id_menu', $menuId)
                ->delete();
        }

        $this->db->table('dap17_agenda_menu')
            ->where('id_menu', $menuId)
            ->delete();
    }
}
