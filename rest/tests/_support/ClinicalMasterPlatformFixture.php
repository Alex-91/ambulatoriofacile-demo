<?php
namespace Tests\Support;

/** Isolated platform membership database; restores the previously shared connection. */
final class ClinicalMasterPlatformFixture
{
    public \CodeIgniter\Database\BaseConnection $db;
    private \ReflectionProperty $connections;
    private $previous;
    public function __construct(int $tenant = 42, int $appUser = 6)
    {
        $this->connections = new \ReflectionProperty(\CodeIgniter\Database\Config::class,'instances');
        $this->previous = $this->connections->getValue()['platform'] ?? null;
        $this->db = \Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        $connections = $this->connections->getValue(); $connections['platform']=$this->db;
        $this->connections->setValue(null,$connections);
        $this->db->query('CREATE TABLE platform_users (id_platform_user INTEGER PRIMARY KEY,status TEXT)');
        $this->db->query('CREATE TABLE platform_tenants (id_tenant INTEGER PRIMARY KEY,is_active INTEGER)');
        $this->db->query('CREATE TABLE platform_user_tenants (id_platform_user INTEGER,id_tenant INTEGER,app_user_id INTEGER,tenant_role TEXT,invitation_status TEXT)');
        $this->db->table('platform_users')->insert(['id_platform_user'=>60,'status'=>'active']);
        $this->db->table('platform_tenants')->insert(['id_tenant'=>$tenant,'is_active'=>1]);
        $this->db->table('platform_user_tenants')->insert(['id_platform_user'=>60,'id_tenant'=>$tenant,'app_user_id'=>$appUser,'tenant_role'=>'tenant_master','invitation_status'=>'accepted']);
    }
    public function close(): void
    {
        $connections=$this->connections->getValue();
        if ($this->previous) $connections['platform']=$this->previous; else unset($connections['platform']);
        $this->connections->setValue(null,$connections);
        $this->db->close();
    }
}
