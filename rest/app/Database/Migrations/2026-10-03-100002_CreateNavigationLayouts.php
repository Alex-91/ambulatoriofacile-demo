<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class CreateNavigationLayouts extends Migration
{
    protected $DBGroup = 'platform';
    public function up()
    {
        if (!$this->db->tableExists('platform_tenants')) return;
        $this->forge->addField([
            'scope_id'=>['type'=>'INT','unsigned'=>true],
            'version'=>['type'=>'INT','unsigned'=>true,'default'=>1],
            'nodes_json'=>['type'=>'LONGTEXT'],
            'previous_json'=>['type'=>'LONGTEXT','null'=>true],
            'updated_by'=>['type'=>'INT','unsigned'=>true,'null'=>true],
            'updated_at'=>['type'=>'DATETIME'],
        ]);
        $this->forge->addKey('scope_id',true);
        $this->forge->createTable('platform_navigation_layouts',true);
    }
    public function down() { $this->forge->dropTable('platform_navigation_layouts',true); }
}
