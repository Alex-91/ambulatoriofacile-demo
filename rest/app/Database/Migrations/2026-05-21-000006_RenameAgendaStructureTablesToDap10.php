<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RenameAgendaStructureTablesToDap10 extends Migration
{
    public function up()
    {
        $this->renameIfNeeded('far10_agenda_config', 'dap10_agenda_config');
        $this->renameIfNeeded('far10_agenda_config_giorni', 'dap10_agenda_config_giorni');
        $this->renameIfNeeded('far10_agenda_config_fasce', 'dap10_agenda_config_fasce');
    }

    public function down()
    {
        $this->renameIfNeeded('dap10_agenda_config_fasce', 'far10_agenda_config_fasce');
        $this->renameIfNeeded('dap10_agenda_config_giorni', 'far10_agenda_config_giorni');
        $this->renameIfNeeded('dap10_agenda_config', 'far10_agenda_config');
    }

    private function renameIfNeeded(string $from, string $to): void
    {
        $fromExists = $this->db->tableExists($from);
        $toExists = $this->db->tableExists($to);

        if ($fromExists && !$toExists) {
            $this->db->query(sprintf('RENAME TABLE `%s` TO `%s`', $from, $to));
        }
    }
}
