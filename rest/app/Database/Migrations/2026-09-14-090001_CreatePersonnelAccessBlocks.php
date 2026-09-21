<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreatePersonnelAccessBlocks extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('dap01_users') || $this->db->tableExists('personnel_access_blocks')) return;
        $this->forge->addField([
            'tenant_id'=>['type'=>'BIGINT'], 'user_id'=>['type'=>'BIGINT'],
            'blocked_by'=>['type'=>'BIGINT'], 'blocked_at'=>['type'=>'DATETIME'],
        ]);
        $this->forge->addKey(['tenant_id','user_id'],true);
        $this->forge->createTable('personnel_access_blocks',true);
        unset($this->db->dataCache['table_names']);
    }
    public function down()
    { throw new \RuntimeException('La rimozione dei blocchi accesso richiede un piano esplicito.'); }
}
