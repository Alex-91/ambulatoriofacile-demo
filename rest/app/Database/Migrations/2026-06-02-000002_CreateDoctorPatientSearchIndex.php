<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDoctorPatientSearchIndex extends Migration
{
    private const TABLE = 'dap26_doctor_patient_search';

    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `dap26_doctor_patient_search` (
  `id_dot` int NOT NULL,
  `id_client` int NOT NULL,
  `cognome_norm` varchar(191) NOT NULL DEFAULT '',
  `nome_norm` varchar(191) NOT NULL DEFAULT '',
  `full_norm` varchar(191) NOT NULL DEFAULT '',
  `cf_norm` varchar(32) DEFAULT NULL,
  `tel_norm` varchar(32) DEFAULT NULL,
  `cell_norm` varchar(32) DEFAULT NULL,
  `email_norm` varchar(191) DEFAULT NULL,
  `paz_spec_norm` varchar(191) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_dot`, `id_client`),
  KEY `idx_dps_client` (`id_client`),
  KEY `idx_dps_cognome` (`id_dot`, `cognome_norm`, `nome_norm`, `id_client`),
  KEY `idx_dps_nome` (`id_dot`, `nome_norm`, `cognome_norm`, `id_client`),
  KEY `idx_dps_full` (`id_dot`, `full_norm`, `id_client`),
  KEY `idx_dps_cf` (`id_dot`, `cf_norm`, `id_client`),
  KEY `idx_dps_tel` (`id_dot`, `tel_norm`, `id_client`),
  KEY `idx_dps_cell` (`id_dot`, `cell_norm`, `id_client`),
  KEY `idx_dps_email` (`id_dot`, `email_norm`, `id_client`),
  KEY `idx_dps_paz_spec` (`id_dot`, `paz_spec_norm`, `id_client`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
SQL;

        $this->db->query($sql);
    }

    public function down()
    {
        if ($this->db->tableExists(self::TABLE)) {
            $this->forge->dropTable(self::TABLE, true);
        }
    }
}
