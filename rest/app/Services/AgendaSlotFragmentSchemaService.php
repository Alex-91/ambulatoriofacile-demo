<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class AgendaSlotFragmentSchemaService
{
    public const TABLE = 'dap46_agenda_slot_frammenti';

    public static function create(BaseConnection $db): void
    {
        if ($db->tableExists(self::TABLE) || !$db->tableExists('dap11_agenda_slot')) {
            return;
        }

        $forge = Database::forge($db);
        $slotIdDefinition = self::resolveSlotIdDefinition($db);
        $forge->addField([
            'id_frammento' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_slot' => $slotIdDefinition,
            'gruppo_token' => [
                'type' => 'VARCHAR',
                'constraint' => 32,
            ],
            'id_slot_origine' => $slotIdDefinition,
            'ora_inizio_originale' => [
                'type' => 'DATETIME',
            ],
            'ora_fine_originale' => [
                'type' => 'DATETIME',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $forge->addKey('id_frammento', true);
        $forge->addUniqueKey('id_slot', 'uq_dap46_agenda_slot_frammento');
        $forge->addKey(
            ['gruppo_token', 'ora_inizio_originale'],
            false,
            false,
            'idx_dap46_agenda_gruppo'
        );
        $forge->addKey('id_slot_origine', false, false, 'idx_dap46_agenda_slot_origine');
        $forge->addForeignKey(
            'id_slot',
            'dap11_agenda_slot',
            'id_slot',
            'CASCADE',
            'CASCADE',
            'fk_dap46_agenda_slot'
        );
        $forge->createTable(self::TABLE, true);
    }

    /** @return array<string, mixed> */
    private static function resolveSlotIdDefinition(BaseConnection $db): array
    {
        foreach ($db->getFieldData('dap11_agenda_slot') as $field) {
            if (($field->name ?? '') !== 'id_slot') {
                continue;
            }

            $type = strtoupper((string) ($field->type ?? 'BIGINT'));
            $definition = [
                'type' => str_contains($type, 'BIGINT') ? 'BIGINT' : 'INT',
            ];
            if (!empty($field->max_length)) {
                $definition['constraint'] = (int) $field->max_length;
            }
            if (!empty($field->unsigned)) {
                $definition['unsigned'] = true;
            }

            return $definition;
        }

        return ['type' => 'BIGINT'];
    }
}
