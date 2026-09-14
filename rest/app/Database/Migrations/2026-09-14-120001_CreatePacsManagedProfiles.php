<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreatePacsManagedProfiles extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('dap02_clients')) return;
        if (!$this->db->tableExists('pacs_managed_profiles')) {
            $this->forge->addField([
                'tenant_id'=>['type'=>'INT','unsigned'=>true],
                'profile_id'=>['type'=>'VARCHAR','constraint'=>40],
                'config_enc'=>['type'=>'LONGTEXT'],
                'revision'=>['type'=>'INT','unsigned'=>true],
                'updated_by'=>['type'=>'INT','unsigned'=>true],
                'updated_at'=>['type'=>'DATETIME'],
            ]);
            $this->forge->addKey(['tenant_id','profile_id'],true);
            $this->forge->createTable('pacs_managed_profiles',true);
        }
        if (!$this->db->tableExists('clinical_setup_audit')) {
            $this->forge->addField([
                'id'=>['type'=>'INT','unsigned'=>true,'auto_increment'=>true],
                'tenant_id'=>['type'=>'INT','unsigned'=>true],
                'actor_id'=>['type'=>'INT','unsigned'=>true],
                'event'=>['type'=>'VARCHAR','constraint'=>40],
                'entity_id'=>['type'=>'VARCHAR','constraint'=>40],
                'revision'=>['type'=>'INT','unsigned'=>true],
                'created_at'=>['type'=>'DATETIME'],
            ]);
            $this->forge->addKey('id',true);
            $this->forge->addKey('tenant_id');
            $this->forge->createTable('clinical_setup_audit',true);
        }
    }
    public function down() { throw new \RuntimeException('La rimozione dei collegamenti richiede una procedura esplicita.'); }
}
