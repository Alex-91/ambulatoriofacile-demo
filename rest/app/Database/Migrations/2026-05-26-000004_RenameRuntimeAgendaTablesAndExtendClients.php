<?php

namespace App\Database\Migrations;

use App\Libraries\DatabaseConfig;
use CodeIgniter\Database\Migration;

class RenameRuntimeAgendaTablesAndExtendClients extends Migration
{
    private array $clientEncryptedFields = [
        'telefono',
        'data_nascita',
        'comune_nascita',
        'provincia_nascita',
        'cap',
        'residenza_indirizzo',
        'residenza_comune',
        'residenza_cap',
        'residenza_provincia',
        'paz_spec',
    ];

    private array $runtimeRenames = [
        'far11_agenda_slot' => 'dap11_agenda_slot',
        'far12_agenda_appuntamenti' => 'dap12_agenda_appuntamenti',
        'far13_visite_domiciliari' => 'dap13_visite_domiciliari',
        'far14_agenda_lock' => 'dap14_agenda_lock',
        'far15_agenda_note' => 'dap15_agenda_note',
        'far17_agenda_menu' => 'dap17_agenda_menu',
        'far18_agenda_menu_permessi' => 'dap18_agenda_menu_permessi',
        'far19_agenda_backup' => 'dap19_agenda_backup',
        'far20_agenda_backup_dettaglio' => 'dap20_agenda_backup_dettaglio',
        'far21_agenda_giorni_bloccati' => 'dap21_agenda_giorni_bloccati',
        'far22_agenda_permessi_azioni' => 'dap22_agenda_permessi_azioni',
        'far23_agenda_nota_giorno' => 'dap23_agenda_nota_giorno',
        'far39_sms_dot' => 'dap39_sms_dot',
        'far41_spec' => 'dap41_spec',
        'far48_gio_ros' => 'dap48_gio_ros',
        'far49_dot_spec' => 'dap49_dot_spec',
    ];

    public function up()
    {
        $this->extendClientsTable();
        $this->backfillClientRuntimeFields();
        $this->renameRuntimeTables();
    }

    public function down()
    {
        $this->renameRuntimeTables(true);

        if ($this->db->tableExists('dap02_clients')) {
            $dropFields = [];
            foreach ($this->clientEncryptedFields as $field) {
                if ($this->db->fieldExists($field, 'dap02_clients')) {
                    $dropFields[] = $field;
                }
            }

            if ($this->db->fieldExists('bloccato', 'dap02_clients')) {
                $dropFields[] = 'bloccato';
            }

            if ($dropFields !== []) {
                $this->forge->dropColumn('dap02_clients', $dropFields);
            }
        }
    }

    private function extendClientsTable(): void
    {
        if (!$this->db->tableExists('dap02_clients')) {
            return;
        }

        $fields = [];
        foreach ($this->clientEncryptedFields as $field) {
            if ($this->db->fieldExists($field, 'dap02_clients')) {
                continue;
            }

            $fields[$field] = [
                'type' => 'TEXT',
                'null' => true,
            ];
        }

        if (!$this->db->fieldExists('bloccato', 'dap02_clients')) {
            $fields['bloccato'] = [
                'type' => 'INT',
                'constraint' => 11,
                'null' => false,
                'default' => 0,
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('dap02_clients', $fields);
        }
    }

    private function backfillClientRuntimeFields(): void
    {
        if (
            !$this->db->tableExists('dap02_clients')
            || !$this->db->tableExists('far05_pazienti')
        ) {
            return;
        }

        $dbCfg = new DatabaseConfig();
        $dbCfg->setEncryptionConfig($this->db);

        $this->db->query('UPDATE dap02_clients SET vector_id = RANDOM_BYTES(16) WHERE vector_id IS NULL');

        $assignments = [];
        foreach ($this->clientEncryptedFields as $field) {
            if (!$this->db->fieldExists($field, 'dap02_clients')) {
                continue;
            }

            $targetPlain = "CAST(AES_DECRYPT(UNHEX(c.{$field}), @key_str, c.vector_id) AS CHAR)";
            $assignments[] = "c.{$field} = CASE
                WHEN COALESCE(NULLIF({$targetPlain}, ''), '') = '' AND COALESCE(f.{$field}, '') <> ''
                    THEN HEX(AES_ENCRYPT(f.{$field}, @key_str, c.vector_id))
                ELSE c.{$field}
            END";
        }

        if ($this->db->fieldExists('bloccato', 'dap02_clients') && $this->db->fieldExists('bloccato', 'far05_pazienti')) {
            $assignments[] = "c.bloccato = CASE
                WHEN COALESCE(c.bloccato, 0) = 0 AND COALESCE(f.bloccato, 0) <> 0
                    THEN f.bloccato
                ELSE c.bloccato
            END";
        }

        if ($assignments === []) {
            return;
        }

        $sql = "
            UPDATE dap02_clients c
            INNER JOIN far05_pazienti f
                ON f.id_paziente = c.legacy_id_paziente
            SET " . implode(",\n                ", $assignments);

        $this->db->query($sql);
    }

    private function renameRuntimeTables(bool $reverse = false): void
    {
        $pairs = $reverse ? array_flip($this->runtimeRenames) : $this->runtimeRenames;

        foreach ($pairs as $source => $target) {
            if (!$this->db->tableExists($source) || $this->db->tableExists($target)) {
                continue;
            }

            $this->db->query("RENAME TABLE `{$source}` TO `{$target}`");
        }
    }
}
