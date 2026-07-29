<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddBulkAppointmentDeletionMenu extends Migration
{
    private const MENU_CODE = 'AGENDA_ELIMINA_APPUNTAMENTI_MASSIVO';
    private const ADMIN_ROLE_ID = 1;

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

        $menuPayload = [
            'id_menu_padre' => null,
            'tipo_voce' => 'ITEM',
            'label_menu' => 'Elimina appuntamenti',
            'icona' => 'fa fa-calendar-times-o',
            'rotta' => 'agenda/elimina-appuntamenti-massivo',
            'ordinamento' => 42,
            'attivo' => 1,
        ];

        if ($menuRow) {
            $menuId = (int) ($menuRow['id_menu'] ?? 0);
            $this->db->table('dap17_agenda_menu')
                ->where('id_menu', $menuId)
                ->update($menuPayload);
        } else {
            $this->db->table('dap17_agenda_menu')->insert(array_merge(
                ['codice' => self::MENU_CODE],
                $menuPayload
            ));
            $menuId = (int) $this->db->insertID();
        }

        if ($menuId <= 0 || !$this->db->tableExists('dap18_agenda_menu_permessi')) {
            return;
        }

        $permission = $this->db->table('dap18_agenda_menu_permessi')
            ->select('id_perm')
            ->where('id_menu', $menuId)
            ->where('id_ruo', self::ADMIN_ROLE_ID)
            ->where('id_ope IS NULL', null, false)
            ->get()
            ->getRowArray();

        if ($permission) {
            $this->db->table('dap18_agenda_menu_permessi')
                ->where('id_perm', (int) ($permission['id_perm'] ?? 0))
                ->update(['visibile' => 1]);
            return;
        }

        $this->db->table('dap18_agenda_menu_permessi')->insert([
            'id_menu' => $menuId,
            'id_ruo' => self::ADMIN_ROLE_ID,
            'id_ope' => null,
            'visibile' => 1,
        ]);
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
        $menuId = (int) ($menuRow['id_menu'] ?? 0);

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
