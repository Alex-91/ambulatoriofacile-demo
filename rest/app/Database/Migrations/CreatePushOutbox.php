<?php
// app/Database/Migrations/2025-08-28-000002_CreatePushOutbox.php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePushOutbox extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type'=>'INT','auto_increment'=>true],
            'endpoint'   => ['type'=>'TEXT','null'=>false],
            'user_id'    => ['type'=>'INT','null'=>true],
            'device_id'  => ['type'=>'VARCHAR','constraint'=>191,'null'=>true],
            'title'      => ['type'=>'VARCHAR','constraint'=>255,'null'=>false],
            'body'       => ['type'=>'TEXT','null'=>false],
            'url'        => ['type'=>'VARCHAR','constraint'=>255,'null'=>false],
            'consumed'   => ['type'=>'TINYINT','constraint'=>1,'default'=>0],
            'created_at' => ['type'=>'DATETIME','null'=>true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['device_id']);
        $this->forge->createTable('push_outbox');
    }

    public function down()
    {
        $this->forge->dropTable('push_outbox');
    }
}
