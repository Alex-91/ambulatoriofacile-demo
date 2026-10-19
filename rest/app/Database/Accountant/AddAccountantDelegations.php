<?php

namespace App\Database\Accountant;

use CodeIgniter\Database\Migration;

class AddAccountantDelegations extends Migration
{
    public function up()
    {
        $id = ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true];
        $integer = ['type' => 'INT', 'unsigned' => true];
        $date = ['type' => 'DATETIME', 'null' => true];
        $text = static fn(int $size) => ['type' => 'VARCHAR', 'constraint' => $size];
        $this->forge->addField([
            'id' => $id, 'tenant_id' => $integer, 'email' => $text(254),
            'tenant_label' => $text(190), 'from_date' => ['type' => 'DATE'],
            'status' => $text(16), 'token_hash' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'expires_at' => $date, 'created_at' => $date, 'accepted_at' => $date,
            'revoked_at' => $date, 'last_access_at' => $date,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'email'], false, true);
        $this->forge->addKey('token_hash', false, true);
        $this->forge->createTable('billing_accountant_delegations', true);

        $this->forge->addField([
            'id' => $id, 'email' => $text(254), 'token_hash' => $text(64),
            'created_at' => $date, 'expires_at' => $date, 'used_at' => $date,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('token_hash', false, true);
        $this->forge->addKey(['email', 'created_at']);
        $this->forge->createTable('billing_accountant_logins', true);

        $this->forge->addField([
            'id' => $id, 'delegation_id' => $integer, 'event' => $text(32),
            'detail' => ['type' => 'TEXT'], 'created_at' => $date,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['delegation_id', 'created_at']);
        $this->forge->createTable('billing_accountant_audit', true);
    }

    public function down()
    {
        $this->forge->dropTable('billing_accountant_audit', true);
        $this->forge->dropTable('billing_accountant_logins', true);
        $this->forge->dropTable('billing_accountant_delegations', true);
    }
}
