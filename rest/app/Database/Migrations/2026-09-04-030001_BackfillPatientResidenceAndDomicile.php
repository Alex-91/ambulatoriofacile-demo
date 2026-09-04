<?php

namespace App\Database\Migrations;

use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;
use CodeIgniter\Database\Migration;

class BackfillPatientResidenceAndDomicile extends Migration
{
    private const TABLE = 'dap02_clients';

    /**
     * The existing secondary-address columns are retained as the physical
     * storage for the domicile during the compatibility phase.
     *
     * @var array<int, string>
     */
    private array $requiredEncryptedFields = [
        'nr_civico',
        'indirizzo_secondario',
        'nr_civico_secondario',
        'comune_secondario',
        'cap_secondario',
        'provincia_secondaria',
        'residenza_nr_civico',
    ];

    public function up()
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return;
        }

        $this->ensureRequiredFields();
        (new DatabaseConfig())->setEncryptionConfig($this->db);

        $mainFields = ['indirizzo', 'nr_civico', 'citta', 'cap', 'provincia'];
        $residenceFields = [
            'residenza_indirizzo',
            'residenza_nr_civico',
            'residenza_comune',
            'residenza_cap',
            'residenza_provincia',
        ];
        $domicileFields = [
            'indirizzo_secondario',
            'nr_civico_secondario',
            'comune_secondario',
            'cap_secondario',
            'provincia_secondaria',
        ];

        if (!$this->hasAllFields(array_merge($mainFields, $residenceFields, $domicileFields))) {
            return;
        }

        $mainPresent = $this->buildAddressPresentSql($mainFields);
        $residencePresent = $this->buildAddressPresentSql($residenceFields);
        $domicilePresent = $this->buildAddressPresentSql($domicileFields);
        $this->copyAddressGroup($residenceFields, $mainFields, $mainPresent, $residencePresent);
        $this->copyAddressGroup($domicileFields, $mainFields, $mainPresent, $domicilePresent);
    }

    public function down()
    {
        if (
            $this->db->tableExists(self::TABLE)
            && $this->db->fieldExists('residenza_nr_civico', self::TABLE)
        ) {
            $this->forge->dropColumn(self::TABLE, 'residenza_nr_civico');
        }
    }

    private function ensureRequiredFields(): void
    {
        $fields = [];
        foreach ($this->requiredEncryptedFields as $field) {
            if ($this->db->fieldExists($field, self::TABLE)) {
                continue;
            }

            $fields[$field] = [
                'type' => 'TEXT',
                'null' => true,
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn(self::TABLE, $fields);
        }
    }

    /**
     * @param array<int, string> $fields
     */
    private function hasAllFields(array $fields): bool
    {
        foreach ($fields as $field) {
            if (!$this->db->fieldExists($field, self::TABLE)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $fields
     */
    private function buildAddressPresentSql(array $fields): string
    {
        $crypto = new Crypto_helper();
        $checks = [];

        foreach ($fields as $field) {
            $decrypted = $crypto->decryptSenzaAlias('`' . self::TABLE . '`.`' . $field . '`');
            $checks[] = "COALESCE(TRIM({$decrypted}), '') <> ''";
        }

        return '(' . implode(' OR ', $checks) . ')';
    }

    /**
     * @param array<int, string> $targetFields
     * @param array<int, string> $sourceFields
     */
    private function copyAddressGroup(
        array $targetFields,
        array $sourceFields,
        string $sourcePresent,
        string $targetPresent
    ): void {
        $assignments = [];
        foreach (array_combine($targetFields, $sourceFields) as $target => $source) {
            $assignments[] = "`{$target}` = `{$source}`";
        }

        $this->db->query(
            'UPDATE `' . self::TABLE . '` SET ' . implode(', ', $assignments)
            . " WHERE ({$sourcePresent}) AND NOT ({$targetPresent})"
        );
    }
}
