<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLegacyBridgeToAgendaNotesAndDomiciliari extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('far15_agenda_note')) {
            $fields = [];

            if (!$this->db->fieldExists('id_client', 'far15_agenda_note')) {
                $fields['id_client'] = [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                    'after'      => 'id_paziente',
                ];
            }

            if (!$this->db->fieldExists('legacy_id_not_dot', 'far15_agenda_note')) {
                $fields['legacy_id_not_dot'] = [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                    'after'      => 'updated_at',
                ];
            }

            if (!$this->db->fieldExists('legacy_id_ope', 'far15_agenda_note')) {
                $fields['legacy_id_ope'] = [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                    'after'      => 'legacy_id_not_dot',
                ];
            }

            if ($fields !== []) {
                $this->forge->addColumn('far15_agenda_note', $fields);
            }

            if (!$this->indexExists('far15_agenda_note', 'ux_far15_agenda_note_legacy_id_not_dot')) {
                $this->db->query('CREATE UNIQUE INDEX ux_far15_agenda_note_legacy_id_not_dot ON far15_agenda_note (legacy_id_not_dot)');
            }

            if (!$this->indexExists('far15_agenda_note', 'idx_far15_agenda_note_id_client')) {
                $this->db->query('CREATE INDEX idx_far15_agenda_note_id_client ON far15_agenda_note (id_client)');
            }
        }

        if ($this->db->tableExists('far13_visite_domiciliari')) {
            $fields = [];

            if (!$this->db->fieldExists('id_client', 'far13_visite_domiciliari')) {
                $fields['id_client'] = [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                    'after'      => 'id_paziente',
                ];
            }

            if (!$this->db->fieldExists('giorno_visita', 'far13_visite_domiciliari')) {
                $fields['giorno_visita'] = [
                    'type'  => 'DATE',
                    'null'  => true,
                    'after' => 'id_client',
                ];
            }

            if (!$this->db->fieldExists('legacy_id_vis', 'far13_visite_domiciliari')) {
                $fields['legacy_id_vis'] = [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                    'after'      => 'stato',
                ];
            }

            if ($fields !== []) {
                $this->forge->addColumn('far13_visite_domiciliari', $fields);
            }

            if (!$this->indexExists('far13_visite_domiciliari', 'ux_far13_visite_domiciliari_legacy_id_vis')) {
                $this->db->query('CREATE UNIQUE INDEX ux_far13_visite_domiciliari_legacy_id_vis ON far13_visite_domiciliari (legacy_id_vis)');
            }

            if (!$this->indexExists('far13_visite_domiciliari', 'idx_far13_visite_domiciliari_id_client')) {
                $this->db->query('CREATE INDEX idx_far13_visite_domiciliari_id_client ON far13_visite_domiciliari (id_client)');
            }

            if (!$this->indexExists('far13_visite_domiciliari', 'idx_far13_visite_domiciliari_giorno_visita')) {
                $this->db->query('CREATE INDEX idx_far13_visite_domiciliari_giorno_visita ON far13_visite_domiciliari (giorno_visita)');
            }
        }
    }

    public function down()
    {
        if ($this->db->tableExists('far15_agenda_note')) {
            $this->dropIndexIfExists('far15_agenda_note', 'ux_far15_agenda_note_legacy_id_not_dot');
            $this->dropIndexIfExists('far15_agenda_note', 'idx_far15_agenda_note_id_client');

            $dropFields = [];
            foreach (['legacy_id_ope', 'legacy_id_not_dot', 'id_client'] as $field) {
                if ($this->db->fieldExists($field, 'far15_agenda_note')) {
                    $dropFields[] = $field;
                }
            }
            if ($dropFields !== []) {
                $this->forge->dropColumn('far15_agenda_note', $dropFields);
            }
        }

        if ($this->db->tableExists('far13_visite_domiciliari')) {
            $this->dropIndexIfExists('far13_visite_domiciliari', 'ux_far13_visite_domiciliari_legacy_id_vis');
            $this->dropIndexIfExists('far13_visite_domiciliari', 'idx_far13_visite_domiciliari_id_client');
            $this->dropIndexIfExists('far13_visite_domiciliari', 'idx_far13_visite_domiciliari_giorno_visita');

            $dropFields = [];
            foreach (['legacy_id_vis', 'giorno_visita', 'id_client'] as $field) {
                if ($this->db->fieldExists($field, 'far13_visite_domiciliari')) {
                    $dropFields[] = $field;
                }
            }
            if ($dropFields !== []) {
                $this->forge->dropColumn('far13_visite_domiciliari', $dropFields);
            }
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS c
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
        ", [$table, $indexName])->getRowArray();

        return (int)($row['c'] ?? 0) > 0;
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            $this->db->query("DROP INDEX {$indexName} ON {$table}");
        }
    }
}
