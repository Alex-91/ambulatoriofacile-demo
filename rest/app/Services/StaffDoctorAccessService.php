<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class StaffDoctorAccessService
{
    public const TIPO_DOTTORE = 1;
    public const TIPO_INFERMIERE = 2;
    public const TIPO_SEGRETERIA = 3;
    public const TIPO_ADMIN = 4;

    private BaseConnection $db;
    private array $globalMailboxAdminByPersonaleCache = [];
    private array $globalMailboxAdminByUserCache = [];
    private ?array $globalMailboxAdminUserIdsCache = null;
    private array $personaleFieldExistsCache = [];

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function normalizeMailboxStaffTipo(int $tipoPers): int
    {
        return $tipoPers === self::TIPO_ADMIN
            ? self::TIPO_SEGRETERIA
            : $tipoPers;
    }

    public function isStaffRole(int $tipoPers): bool
    {
        $tipoPers = $this->normalizeMailboxStaffTipo($tipoPers);
        return in_array($tipoPers, [self::TIPO_INFERMIERE, self::TIPO_SEGRETERIA], true);
    }

    public function getPersonaleIdByUserId(int $userId, int $tipoPers = 0): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $builder = $this->db->table('dap03_personale')
            ->select('id_personale')
            ->where('id_user', $userId);

        if ($tipoPers > 0) {
            $builder->where('tipo', $tipoPers);
        }

        $row = $builder
            ->orderBy('id_personale', 'ASC')
            ->get()
            ->getRowArray();

        return (int)($row['id_personale'] ?? 0);
    }

    public function getDoctorPersonaleIdByUserId(int $doctorUserId): int
    {
        return $this->getPersonaleIdByUserId($doctorUserId, self::TIPO_DOTTORE);
    }

    public function getDoctorPersonaleIdsForStaff(int $staffPersonaleId, int $staffTipoPers, ?string $module = null): array
    {
        $staffTipoPers = $this->normalizeMailboxStaffTipo($staffTipoPers);
        if ($staffPersonaleId <= 0 || !$this->isStaffRole($staffTipoPers)) {
            return [];
        }

        // Temporary forced workaround: in posta segreterie can see all doctors
        // until dap14_seg_dot is fully realigned in production.
        if ($this->hasForcedFullPostaVisibility($staffTipoPers, $module)) {
            return $this->getAllDoctorPersonaleIds($module);
        }

        if ($this->isGlobalMailboxAdminPersonaleId($staffPersonaleId, $staffTipoPers)) {
            return $this->getAllDoctorPersonaleIds($module);
        }

        if ($staffTipoPers === self::TIPO_SEGRETERIA) {
            $rows = $this->db->table('dap14_seg_dot')
                ->select('id_dot')
                ->where('id_seg', $staffPersonaleId)
                ->get()
                ->getResultArray();
        } else {
            $rows = $this->db->table('dap15_inf_dot')
                ->select('id_dot')
                ->where('id_inf', $staffPersonaleId)
                ->get()
                ->getResultArray();
        }

        $ids = array_map(static fn(array $row): int => (int)($row['id_dot'] ?? 0), $rows);
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        $ids = $this->filterDoctorPersonaleIdsByModule($ids, $module);
        sort($ids);

        return $ids;
    }

    public function getDoctorUserIdsForStaffUser(int $staffUserId, int $staffTipoPers, ?string $module = null): array
    {
        $staffTipoPers = $this->normalizeMailboxStaffTipo($staffTipoPers);

        if ($staffTipoPers === self::TIPO_SEGRETERIA && $this->isGlobalMailboxAdminUserId($staffUserId)) {
            return $this->getUserIdsForPersonaleIds($this->getAllDoctorPersonaleIds($module), self::TIPO_DOTTORE);
        }

        $staffPersonaleId = $this->getPersonaleIdByUserId($staffUserId, $staffTipoPers);
        if ($staffPersonaleId <= 0) {
            return [];
        }

        $doctorPersonaleIds = $this->getDoctorPersonaleIdsForStaff($staffPersonaleId, $staffTipoPers, $module);
        return $this->getUserIdsForPersonaleIds($doctorPersonaleIds, self::TIPO_DOTTORE);
    }

    public function canStaffAccessDoctor(int $staffPersonaleId, int $staffTipoPers, int $doctorPersonaleId, ?string $module = null): bool
    {
        $staffTipoPers = $this->normalizeMailboxStaffTipo($staffTipoPers);
        if ($staffPersonaleId <= 0 || $doctorPersonaleId <= 0 || !$this->isStaffRole($staffTipoPers)) {
            return false;
        }

        if ($this->hasForcedFullPostaVisibility($staffTipoPers, $module)) {
            return $this->isDoctorPersonaleVisibleForModule($doctorPersonaleId, $module);
        }

        if ($this->isGlobalMailboxAdminPersonaleId($staffPersonaleId, $staffTipoPers)) {
            return in_array($doctorPersonaleId, $this->getAllDoctorPersonaleIds($module), true);
        }

        if ($staffTipoPers === self::TIPO_SEGRETERIA) {
            $row = $this->db->table('dap14_seg_dot')
                ->select('1')
                ->where('id_seg', $staffPersonaleId)
                ->where('id_dot', $doctorPersonaleId)
                ->get()
                ->getRowArray();
        } else {
            $row = $this->db->table('dap15_inf_dot')
                ->select('1')
                ->where('id_inf', $staffPersonaleId)
                ->where('id_dot', $doctorPersonaleId)
                ->get()
                ->getRowArray();
        }

        return !empty($row) && $this->isDoctorPersonaleVisibleForModule($doctorPersonaleId, $module);
    }

    public function canStaffUserAccessDoctorUser(int $staffUserId, int $staffTipoPers, int $doctorUserId, ?string $module = null): bool
    {
        $staffTipoPers = $this->normalizeMailboxStaffTipo($staffTipoPers);

        if ($staffTipoPers === self::TIPO_SEGRETERIA && $this->isGlobalMailboxAdminUserId($staffUserId)) {
            return in_array($doctorUserId, $this->getDoctorUserIdsForStaffUser($staffUserId, $staffTipoPers, $module), true);
        }

        $staffPersonaleId = $this->getPersonaleIdByUserId($staffUserId, $staffTipoPers);
        $doctorPersonaleId = $this->getDoctorPersonaleIdByUserId($doctorUserId);

        return $this->canStaffAccessDoctor($staffPersonaleId, $staffTipoPers, $doctorPersonaleId, $module);
    }

    public function getStaffUserIdsForDoctorUser(int $doctorUserId, int $staffTipoPers, ?string $module = null): array
    {
        $staffTipoPers = $this->normalizeMailboxStaffTipo($staffTipoPers);
        $doctorPersonaleId = $this->getDoctorPersonaleIdByUserId($doctorUserId);
        if (
            $doctorPersonaleId <= 0
            || !$this->isStaffRole($staffTipoPers)
            || !$this->isDoctorPersonaleVisibleForModule($doctorPersonaleId, $module)
        ) {
            return [];
        }

        if ($staffTipoPers === self::TIPO_SEGRETERIA) {
            $rows = $this->db->query("
                SELECT p.id_user
                FROM dap14_seg_dot sd
                JOIN dap03_personale p ON p.id_personale = sd.id_seg
                WHERE sd.id_dot = ?
                  AND p.id_user IS NOT NULL
            ", [$doctorPersonaleId])->getResultArray();
        } else {
            $rows = $this->db->query("
                SELECT p.id_user
                FROM dap15_inf_dot inf
                JOIN dap03_personale p ON p.id_personale = inf.id_inf
                WHERE inf.id_dot = ?
                  AND p.id_user IS NOT NULL
            ", [$doctorPersonaleId])->getResultArray();
        }

        $ids = array_map(static fn(array $row): int => (int)($row['id_user'] ?? 0), $rows);
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));

        if ($staffTipoPers === self::TIPO_SEGRETERIA) {
            $ids = array_merge($ids, $this->getGlobalMailboxAdminUserIds());
        }

        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    private function getUserIdsForPersonaleIds(array $personaleIds, int $tipoPers = 0): array
    {
        $personaleIds = array_values(array_unique(array_filter(array_map('intval', $personaleIds), static fn(int $id): bool => $id > 0)));
        if (empty($personaleIds)) {
            return [];
        }

        $builder = $this->db->table('dap03_personale')
            ->select('id_user')
            ->whereIn('id_personale', $personaleIds)
            ->where('id_user IS NOT NULL', null, false);

        if ($tipoPers > 0) {
            $builder->where('tipo', $tipoPers);
        }

        $rows = $builder->get()->getResultArray();

        $ids = array_map(static fn(array $row): int => (int)($row['id_user'] ?? 0), $rows);
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    public function isGlobalMailboxAdminPersonaleId(int $staffPersonaleId, int $staffTipoPers): bool
    {
        $staffTipoPers = $this->normalizeMailboxStaffTipo($staffTipoPers);
        if ($staffPersonaleId <= 0 || $staffTipoPers !== self::TIPO_SEGRETERIA) {
            return false;
        }

        if (array_key_exists($staffPersonaleId, $this->globalMailboxAdminByPersonaleCache)) {
            return $this->globalMailboxAdminByPersonaleCache[$staffPersonaleId];
        }

        $row = $this->db->query(
            "
                SELECT p.tipo
                FROM dap03_personale p
                WHERE p.id_personale = ?
                LIMIT 1
            ",
            [$staffPersonaleId]
        )->getRowArray();

        $isMatch = (int)($row['tipo'] ?? 0) === self::TIPO_ADMIN;
        $this->globalMailboxAdminByPersonaleCache[$staffPersonaleId] = $isMatch;

        return $isMatch;
    }

    public function isGlobalMailboxAdminUserId(int $staffUserId): bool
    {
        if ($staffUserId <= 0) {
            return false;
        }

        if (array_key_exists($staffUserId, $this->globalMailboxAdminByUserCache)) {
            return $this->globalMailboxAdminByUserCache[$staffUserId];
        }

        $row = $this->db->query(
            "
                SELECT 1
                FROM dap03_personale p
                WHERE p.id_user = ?
                  AND p.tipo = ?
                LIMIT 1
            ",
            [$staffUserId, self::TIPO_ADMIN]
        )->getRowArray();

        $isMatch = !empty($row);
        $this->globalMailboxAdminByUserCache[$staffUserId] = $isMatch;

        return $isMatch;
    }

    public function getAllDoctorPersonaleIds(?string $module = null): array
    {
        $builder = $this->db->table('dap03_personale')
            ->select('id_personale')
            ->where('tipo', self::TIPO_DOTTORE)
            ->where('titolare', 1)
            ->whereNotIn('id_user', [15, 41]);

        $visibilityColumn = $this->getVisibilityColumnForModule($module);
        if ($visibilityColumn !== null && $this->hasPersonaleField($visibilityColumn)) {
            $builder->where("COALESCE({$visibilityColumn}, 1) = 1", null, false);
        }

        $rows = $builder->get()->getResultArray();

        $ids = array_map(static fn(array $row): int => (int)($row['id_personale'] ?? 0), $rows);
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    private function getGlobalMailboxAdminUserIds(): array
    {
        if ($this->globalMailboxAdminUserIdsCache !== null) {
            return $this->globalMailboxAdminUserIdsCache;
        }

        $rows = $this->db->query(
            "
                SELECT DISTINCT p.id_user
                FROM dap03_personale p
                WHERE p.tipo = ?
                  AND p.id_user IS NOT NULL
            ",
            [self::TIPO_ADMIN]
        )->getResultArray();

        $ids = array_map(static fn(array $row): int => (int)($row['id_user'] ?? 0), $rows);
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        sort($ids);

        $this->globalMailboxAdminUserIdsCache = $ids;
        return $this->globalMailboxAdminUserIdsCache;
    }

    private function getVisibilityColumnForModule(?string $module): ?string
    {
        return match (strtolower(trim((string)$module))) {
            'agenda' => 'show_in_agenda',
            'posta', 'messaggi', 'mailbox' => 'show_in_posta',
            'chat' => 'show_in_chat',
            default => null,
        };
    }

    private function hasForcedFullPostaVisibility(int $staffTipoPers, ?string $module): bool
    {
        $staffTipoPers = $this->normalizeMailboxStaffTipo($staffTipoPers);
        $module = strtolower(trim((string)$module));

        return $staffTipoPers === self::TIPO_SEGRETERIA
            && in_array($module, ['posta', 'messaggi', 'mailbox'], true);
    }

    private function hasPersonaleField(string $field): bool
    {
        if (array_key_exists($field, $this->personaleFieldExistsCache)) {
            return $this->personaleFieldExistsCache[$field];
        }

        try {
            $exists = $this->db->fieldExists($field, 'dap03_personale');
        } catch (\Throwable $e) {
            $exists = false;
        }

        $this->personaleFieldExistsCache[$field] = $exists;
        return $exists;
    }

    private function isDoctorPersonaleVisibleForModule(int $doctorPersonaleId, ?string $module): bool
    {
        if ($doctorPersonaleId <= 0) {
            return false;
        }

        $visibilityColumn = $this->getVisibilityColumnForModule($module);
        if ($visibilityColumn === null || !$this->hasPersonaleField($visibilityColumn)) {
            return true;
        }

        $row = $this->db->table('dap03_personale')
            ->select('1')
            ->where('id_personale', $doctorPersonaleId)
            ->where('tipo', self::TIPO_DOTTORE)
            ->where("COALESCE({$visibilityColumn}, 1) = 1", null, false)
            ->get()
            ->getRowArray();

        return !empty($row);
    }

    private function filterDoctorPersonaleIdsByModule(array $doctorIds, ?string $module): array
    {
        $doctorIds = array_values(array_unique(array_filter(array_map('intval', $doctorIds), static fn(int $id): bool => $id > 0)));
        if (empty($doctorIds)) {
            return [];
        }

        $visibilityColumn = $this->getVisibilityColumnForModule($module);
        if ($visibilityColumn === null || !$this->hasPersonaleField($visibilityColumn)) {
            sort($doctorIds);
            return $doctorIds;
        }

        $rows = $this->db->table('dap03_personale')
            ->select('id_personale')
            ->whereIn('id_personale', $doctorIds)
            ->where('tipo', self::TIPO_DOTTORE)
            ->where("COALESCE({$visibilityColumn}, 1) = 1", null, false)
            ->get()
            ->getResultArray();

        $visibleIds = array_map(static fn(array $row): int => (int)($row['id_personale'] ?? 0), $rows);
        $visibleIds = array_values(array_unique(array_filter($visibleIds, static fn(int $id): bool => $id > 0)));
        sort($visibleIds);

        return $visibleIds;
    }
}
