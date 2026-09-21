<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** No platform-admin shortcut grants access to clinical contents. */
final class ClinicalAccessPolicy
{
    public function __construct(private BaseConnection $db, private int $userId, private int $tenantId = 0) {}
    public function actor(): array
    {
        (new PersonnelAccessService($this->db,$this->tenantId))->assertActive($this->userId);
        if ($this->userId <= 0 || !$this->db->tableExists('dap03_personale')) throw new \RuntimeException('Accesso riservato al personale della struttura.');
        $user = $this->db->table('dap01_users')->where('id_user', $this->userId)->get()->getRowArray();
        if (!$user || (array_key_exists('is_active', $user) && !(int) $user['is_active'])) throw new \RuntimeException('Utente non attivo.');
        $staff = $this->db->table('dap03_personale')->where('id_user', $this->userId)->get()->getRowArray();
        if (!$staff || (isset($staff['is_active']) && !(int)$staff['is_active'])) throw new \RuntimeException('Profilo non abilitato alla cartella clinica.');
        $role = (int)$staff['tipo'];
        if (!in_array($role,[1,2,3],true) && !($role === 4 && $this->isTenantMaster())) throw new \RuntimeException('Profilo non abilitato alla cartella clinica.');
        return ['user_id'=>$this->userId,'staff_id'=>(int) $staff['id_personale'],'role'=>(int) $staff['tipo']];
    }
    /** The administrative role is tenant-scoped and checked live, not inferred from session admin flags. */
    private function isTenantMaster(): bool
    {
        if ($this->tenantId <= 0) return false;
        try {
            return \Config\Database::connect('platform')->table('platform_user_tenants m')
                ->join('platform_users u','u.id_platform_user = m.id_platform_user')
                ->join('platform_tenants t','t.id_tenant = m.id_tenant')
                ->where('m.id_tenant',$this->tenantId)->where('m.app_user_id',$this->userId)
                ->where('m.tenant_role','tenant_master')->where('m.invitation_status','accepted')
                ->where('u.status','active')->where('t.is_active',1)->countAllResults() > 0;
        } catch (\Throwable) { return false; }
    }
    public function assertPatient(int $patientId, bool $clinical = true): array
    {
        $actor = $this->actor();
        if ($patientId <= 0 || !$this->db->table('dap02_clients')->where('id_client',$patientId)->countAllResults()) throw new \RuntimeException('Paziente non disponibile.');
        if ($clinical && $actor['role'] === 3) throw new \RuntimeException('La segreteria non è abilitata ai contenuti clinici.');
        if ($actor['role'] === 4) {
            if ($clinical) throw new \RuntimeException('Il master gestisce consensi e attività operative. Documenti clinici e referti sono riservati ai professionisti abilitati.');
            return $actor;
        }
        $doctors = [$actor['staff_id']];
        if ($actor['role'] !== 1) {
            $table = $actor['role'] === 3 ? 'dap14_seg_dot' : 'dap15_inf_dot';
            $column = $actor['role'] === 3 ? 'id_seg' : 'id_inf';
            $doctors = $this->db->tableExists($table)
                ? array_column($this->db->table($table)->select('id_dot')->where($column,$actor['staff_id'])->get()->getResultArray(),'id_dot') : [];
        }
        $linked = $doctors !== [] && $this->db->tableExists('dap09_client_doctor')
            && $this->db->table('dap09_client_doctor')->where('id_client',$patientId)->whereIn('id_dot',$doctors)->countAllResults() > 0;
        // A scheduled encounter also establishes the operational care relationship.
        if (!$linked && $doctors !== [] && $this->db->tableExists('dap12_agenda_appuntamenti')
            && $this->db->fieldExists('id_client','dap12_agenda_appuntamenti')) {
            $agendaIds=$this->agendaDoctorIds($doctors);
            $linked = $agendaIds !== [] && $this->db->table('dap12_agenda_appuntamenti')->where('id_client',$patientId)
                ->whereIn('id_dot',$agendaIds)->where('stato !=','ANNULLATO')->countAllResults() > 0;
        }
        if (!$linked) throw new \RuntimeException('Paziente non disponibile per il profilo corrente.');
        return $actor;
    }
    /** Patient relations use staff IDs; appointments use legacy agenda IDs. */
    public function agendaDoctorIds(array $staffIds): array
    {
        if (!$staffIds) return [];
        if (!$this->db->fieldExists('legacy_id_dot','dap03_personale')) return array_map('intval',$staffIds);
        $rows=$this->db->table('dap03_personale')->select('legacy_id_dot')->whereIn('id_personale',$staffIds)->get()->getResultArray();
        return array_values(array_unique(array_filter(array_map(static fn($row)=>(int)($row['legacy_id_dot'] ?? 0),$rows),static fn($id)=>$id>0)));
    }
    public function assertDoctor(int $patientId): array
    {
        $actor = $this->assertPatient($patientId);
        if ($actor['role'] !== 1) throw new \RuntimeException('La refertazione è riservata al medico.');
        return $actor;
    }
}
