<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class CreateNavigationPreferences extends Migration
{
    protected $DBGroup = 'platform';
    public function up()
    {
        if (!$this->db->tableExists('platform_tenants')) return;
        $this->forge->addField([
            'id_tenant'=>['type'=>'INT','unsigned'=>true],
            'id_platform_user'=>['type'=>'INT','unsigned'=>true],
            'home_url'=>['type'=>'VARCHAR','constraint'=>1024],
            'updated_at'=>['type'=>'DATETIME'],
        ]);
        $this->forge->addKey(['id_tenant','id_platform_user'],true);
        $this->forge->createTable('platform_navigation_preferences',true);
    }
    public function down() { $this->forge->dropTable('platform_navigation_preferences',true); }
}
