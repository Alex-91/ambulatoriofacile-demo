<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTsVatMetadataToDocuments extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('ts_documents')) {
            return;
        }

        $existingColumns = $this->resolveExistingColumns('ts_documents');
        $fields = [];

        if (!isset($existingColumns['document_type'])) {
            $fields['document_type'] = [
                'type' => 'VARCHAR',
                'constraint' => 1,
                'default' => 'F',
                'after' => 'payment_date',
            ];
        }

        if (!isset($existingColumns['vat_rate'])) {
            $fields['vat_rate'] = [
                'type' => 'DECIMAL',
                'constraint' => '5,2',
                'null' => true,
                'after' => 'amount_total',
            ];
        }

        if (!isset($existingColumns['vat_nature'])) {
            $fields['vat_nature'] = [
                'type' => 'VARCHAR',
                'constraint' => 10,
                'null' => true,
                'after' => 'vat_rate',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('ts_documents', $fields);
        }
    }

    public function down()
    {
        if (!$this->db->tableExists('ts_documents')) {
            return;
        }

        foreach (['vat_nature', 'vat_rate', 'document_type'] as $field) {
            if ($this->db->fieldExists($field, 'ts_documents')) {
                $this->forge->dropColumn('ts_documents', $field);
            }
        }
    }

    /**
     * @return array<string, true>
     */
    private function resolveExistingColumns(string $table): array
    {
        $columns = [];

        foreach ($this->db->getFieldData($table) as $field) {
            $name = strtolower(trim((string) ($field->name ?? '')));
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        return $columns;
    }
}
