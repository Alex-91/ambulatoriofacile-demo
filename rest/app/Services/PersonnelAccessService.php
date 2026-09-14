<?php
namespace App\Services;
use CodeIgniter\Database\BaseConnection;

/** Persistent, tenant-scoped denial; password changes never remove this block. */
final class PersonnelAccessService
{
    public function __construct(private BaseConnection $db, private int $tenantId) {}
    public function blocked(int $userId): bool
    {
        return $this->tenantId>0 && $userId>0 && $this->db->tableExists('personnel_access_blocks')
            && $this->db->table('personnel_access_blocks')->where('tenant_id',$this->tenantId)->where('user_id',$userId)->countAllResults()>0;
    }
    public function assertActive(int $userId): void
    {
        if ($this->blocked($userId)) throw new \RuntimeException('Account disattivato. Rivolgersi al responsabile dello spazio.');
    }
    public function disable(int $actorId,int $staffId): void
    {
        $this->assertActive($actorId);
        if ((new ClinicalAccessPolicy($this->db,$actorId,$this->tenantId))->actor()['role']!==4) {
            throw new \RuntimeException('Disattivazione riservata al responsabile dello spazio.');
        }
        if (!$this->db->tableExists('personnel_access_blocks')) throw new \RuntimeException('Gestione accessi da inizializzare.');
        $staff=$this->db->table('dap03_personale')->where('id_personale',$staffId)->get()->getRowArray();
        $id=(int)($staff['id_user'] ?? 0);
        $user=$this->db->table('dap01_users')->where('id_user',$id)->get()->getRowArray();
        if (!$staff || !$user || $id===$actorId || (int)$staff['tipo']===4 || (int)$user['tipo_user']===1) {
            throw new \RuntimeException('Non è possibile disattivare questo account da questa pagina.');
        }
        if ($this->blocked($id)) return;
        // The unique tenant/user key also prevents concurrent duplicate blocks.
        if (!$this->db->table('personnel_access_blocks')->insert([
            'tenant_id'=>$this->tenantId,'user_id'=>$id,'blocked_by'=>$actorId,'blocked_at'=>gmdate('Y-m-d H:i:s'),
        ])) throw new \RuntimeException('Account non disattivato.');
    }
    public static function assertLoginAllowed(BaseConnection $db,int $userId,?int $tenantId=null): void
    {
        if (!$db->tableExists('personnel_access_blocks')) return;
        if ($tenantId===null) {
            $pending=session()->get(LegacyTenantSessionService::SESSION_KEY_PENDING_RUNTIME);
            $tenantId=(int)($pending['tenant_id'] ?? 0);
            if ($tenantId<=0) $tenantId=(int)((new TenantCatalogService())->resolveCurrentRuntimeTenant()['id_tenant'] ?? 0);
        }
        if ($tenantId<=0) throw new \RuntimeException('Spazio di accesso non verificabile.');
        (new self($db,$tenantId))->assertActive($userId);
    }
}
