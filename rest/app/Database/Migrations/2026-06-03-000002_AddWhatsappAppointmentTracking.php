<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddWhatsappAppointmentTracking extends Migration
{
    public function up()
    {
        if (
            $this->db->tableExists('dap12_agenda_appuntamenti')
            && !$this->db->fieldExists('esitato', 'dap12_agenda_appuntamenti')
        ) {
            $this->db->query(
                "ALTER TABLE dap12_agenda_appuntamenti
                 ADD COLUMN esitato INT(11) NOT NULL DEFAULT 0 AFTER stato"
            );
        }

        if (!$this->db->tableExists('dap47_sms_app_multipli')) {
            $this->db->query(
                "CREATE TABLE dap47_sms_app_multipli (
                    id_sms_app_multiplo INT(11) NOT NULL AUTO_INCREMENT,
                    cellulare VARCHAR(32) NOT NULL,
                    indice_menu INT(11) NOT NULL,
                    id_appuntamento INT(11) NOT NULL,
                    data DATETIME NULL DEFAULT NULL,
                    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id_sms_app_multiplo),
                    UNIQUE KEY uq_dap47_cell_index_app (cellulare, indice_menu, id_appuntamento),
                    KEY idx_dap47_cell_index (cellulare, indice_menu),
                    KEY idx_dap47_app (id_appuntamento)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    }

    public function down()
    {
        if ($this->db->tableExists('dap47_sms_app_multipli')) {
            $this->db->query('DROP TABLE dap47_sms_app_multipli');
        }

        if (
            $this->db->tableExists('dap12_agenda_appuntamenti')
            && $this->db->fieldExists('esitato', 'dap12_agenda_appuntamenti')
        ) {
            $this->db->query(
                "ALTER TABLE dap12_agenda_appuntamenti
                 DROP COLUMN esitato"
            );
        }
    }
}
