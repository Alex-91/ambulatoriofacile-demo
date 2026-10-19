<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateWhatsAppBookings extends Migration
{
    protected $DBGroup='platform';
    public function up()
    {
        $definitions=[
            'platform_whatsapp_booking_configs'=>['config_json'=>['type'=>'TEXT']],
            'platform_whatsapp_booking_sessions'=>['phone_key'=>['type'=>'VARCHAR','constraint'=>32], 'state_json'=>['type'=>'MEDIUMTEXT'], 'expires_at'=>['type'=>'DATETIME']],
            'platform_whatsapp_booking_receipts'=>['message_key'=>['type'=>'CHAR','constraint'=>64], 'result_json'=>['type'=>'MEDIUMTEXT']],
            'platform_whatsapp_booking_requests'=>['booking_key'=>['type'=>'CHAR','constraint'=>32], 'phone_key'=>['type'=>'VARCHAR','constraint'=>32], 'kind'=>['type'=>'VARCHAR','constraint'=>16], 'status'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'pending'], 'state_json'=>['type'=>'MEDIUMTEXT'], 'config_json'=>['type'=>'TEXT'], 'appointment_id'=>['type'=>'BIGINT','null'=>true], 'reply_text'=>['type'=>'TEXT','null'=>true], 'reply_sent_at'=>['type'=>'DATETIME','null'=>true], 'handled_by'=>['type'=>'INT','null'=>true]],
        ];
        foreach($definitions as $table=>$fields){
            if($this->db->tableExists($table))continue;
            $this->forge->addField(array_merge(['id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true], 'id_tenant'=>['type'=>'INT','constraint'=>11,'unsigned'=>true]],$fields,['created_at'=>['type'=>'DATETIME'],'updated_at'=>['type'=>'DATETIME']]));
            $this->forge->addKey('id',true);
            $unique=['id_tenant'];
            foreach(['phone_key','message_key','booking_key'] as $key) if(isset($fields[$key]))$unique=['id_tenant',$key];
            $this->forge->addUniqueKey($unique);
            $this->forge->addForeignKey('id_tenant','platform_tenants','id_tenant','CASCADE','CASCADE');
            $this->forge->createTable($table,true);
        }
    }
    public function down()
    {
        foreach(['requests','receipts','sessions','configs'] as $suffix)$this->forge->dropTable('platform_whatsapp_booking_'.$suffix,true);
    }
}
