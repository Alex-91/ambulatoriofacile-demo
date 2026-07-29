<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class FixBulkAppointmentDeletionMenuIcon extends Migration
{
    private const AGENDA_MENU_CODE = 'AGENDA_ELIMINA_APPUNTAMENTI_MASSIVO';
    private const ADMIN_MENU_ROUTE = 'agenda/elimina-appuntamenti-massivo';

    public function up()
    {
        $this->updateIcons('fa fa-trash-o', 'fa-trash-o');
    }

    public function down()
    {
        $this->updateIcons('fa fa-calendar-times-o', 'fa-calendar-times-o');
    }

    private function updateIcons(string $agendaIcon, string $adminIcon): void
    {
        if ($this->db->tableExists('dap17_agenda_menu')) {
            $this->db->table('dap17_agenda_menu')
                ->where('codice', self::AGENDA_MENU_CODE)
                ->update(['icona' => $agendaIcon]);
        }

        if ($this->db->tableExists('dap06_mnu')) {
            $this->db->table('dap06_mnu')
                ->where('admin', 1)
                ->groupStart()
                    ->where('link', self::ADMIN_MENU_ROUTE)
                    ->orWhere('link2', self::ADMIN_MENU_ROUTE)
                ->groupEnd()
                ->update(['class_icon' => $adminIcon]);
        }
    }
}
