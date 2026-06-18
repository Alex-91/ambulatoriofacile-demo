<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePushSubscriptions extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type'=>'INT','auto_increment'=>true],
            'user_id'     => ['type'=>'INT','null'=>true],
            'device_id'   => ['type'=>'VARCHAR','constraint'=>191,'null'=>true],
            'device_name' => ['type'=>'VARCHAR','constraint'=>191,'null'=>true],
            'endpoint'    => ['type'=>'TEXT','null'=>false],
            'p256dh'      => ['type'=>'VARCHAR','constraint'=>255,'null'=>false],
            'auth'        => ['type'=>'VARCHAR','constraint'=>255,'null'=>false],
            'ua'          => ['type'=>'VARCHAR','constraint'=>255,'null'=>true],
            'created_at'  => ['type'=>'DATETIME','null'=>true],
            'updated_at'  => ['type'=>'DATETIME','null'=>true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id','device_id']);
        $this->forge->createTable('push_subscriptions');
    }

    public function down()
    {
        $this->forge->dropTable('push_subscriptions');
    }
}
