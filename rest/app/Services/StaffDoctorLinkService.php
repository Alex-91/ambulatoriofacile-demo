<?php

namespace App\Services;

use App\Libraries\Crypto_helper;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class StaffDoctorLinkService
{
    public const TIPO_INFERMIERE = 2;
    public const TIPO_SEGRETERIA = 3;
    public const ALL_GROUPS_VALUE = '__all__';

    private BaseConnection $db;
    private Crypto_helper $crypto;
    private StaffLocationCatalogService $locationCatalog;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->crypto = new Crypto_helper();
        $this->locationCatalog = new StaffLocationCatalogService($this->db);
    }

    public function isManagedRole(int $tipo): bool
    {
        return in_array($tipo, [self::TIPO_INFERMIERE, self::TIPO_SEGRETERIA], true);
    }

    public function allGroupIds(): array
    {
        return $this->locationCatalog->selectableLocationIds();
    }

    public function normalizeGroupIds($raw, int $fallbackGroupId = 0): array
    {
        $values = is_array($raw) ? $raw : [$raw];
        $values = array_map(static fn($value): string => trim((string)$value), $values);

        $allGroupIds = $this->allGroupIds();
        if (in_array(self::ALL_GROUPS_VALUE, $values, true)) {
            return $allGroupIds;
        }

        $ids = [];
        foreach ($values as $value) {
            if ($value === '' || !ctype_digit($value)) {
                continue;
            }

            $id = (int)$value;
            if ($id > 0 && in_array($id, $allGroupIds, true)) {
                $ids[] = $id;
            }
        }

        if (empty($ids) && $fallbackGroupId > 0 && in_array($fallbackGroupId, $allGroupIds, true)) {
            $ids[] = $fallbackGroupId;
        }

        return array_values(array_unique($ids));
    }

    public function primaryGroupId(array $groupIds): int
    {
        return (int)($groupIds[0] ?? 0);
    }

    public function selectedGroupIdsForStaff(int $staffId, int $tipo, int $fallbackGroupId = 0): array
    {
        if ($staffId <= 0 || !$this->isManagedRole($tipo)) {
            return $fallbackGroupId > 0 ? [$fallbackGroupId] : [];
        }

        if ($tipo === self::TIPO_SEGRETERIA) {
            $sql = "
                SELECT DISTINCT p.luogo
                FROM dap14_seg_dot sd
                JOIN dap03_personale p ON p.id_personale = sd.id_dot
                WHERE sd.id_seg = ?
                  AND p.luogo > 0
                ORDER BY p.luogo
            ";
        } else {
            $sql = "
                SELECT DISTINCT p.luogo
                FROM dap15_inf_dot inf
                JOIN dap03_personale p ON p.id_personale = inf.id_dot
                WHERE inf.id_inf = ?
                  AND p.luogo > 0
                ORDER BY p.luogo
            ";
        }

        $rows = $this->db->query($sql, [$staffId])->getResultArray();
        $ids = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['luogo'], $rows)));

        if (empty($ids) && $fallbackGroupId > 0) {
            $ids[] = $fallbackGroupId;
        }

        return $ids;
    }

    public function syncForStaff(int $staffId, int $tipo, array $groupIds): bool
    {
        if ($staffId <= 0) {
            return false;
        }

        try {
            $this->clearStaffLinks($staffId);

            if (!$this->isManagedRole($tipo)) {
                return true;
            }

            $doctorIds = $this->doctorIdsForGroups($groupIds);
            if (empty($doctorIds)) {
                return true;
            }

            if ($tipo === self::TIPO_SEGRETERIA) {
                $rows = array_map(static fn(int $doctorId): array => [
                    'id_seg' => $staffId,
                    'id_dot' => $doctorId,
                ], $doctorIds);

                $this->db->table('dap14_seg_dot')->insertBatch($rows);
                return true;
            }

            $rows = array_map(static fn(int $doctorId): array => [
                'id_inf' => $staffId,
                'id_dot' => $doctorId,
            ], $doctorIds);

            $this->db->table('dap15_inf_dot')->insertBatch($rows);
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'StaffDoctorLinkService syncForStaff ERROR: ' . $e->getMessage());
            return false;
        }
    }

    public function resyncManagedStaffForGroups(array $groupIds): bool
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn(int $id): bool => $id > 0)));
        if (empty($groupIds)) {
            return true;
        }

        try {
            $rows = $this->db->table('dap03_personale')
                ->select('id_personale, tipo, luogo')
                ->whereIn('tipo', [self::TIPO_INFERMIERE, self::TIPO_SEGRETERIA])
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $staffId = (int)($row['id_personale'] ?? 0);
                $tipo = (int)($row['tipo'] ?? 0);
                $fallbackGroupId = (int)($row['luogo'] ?? 0);

                if ($staffId <= 0 || !$this->isManagedRole($tipo)) {
                    continue;
                }

                $selectedGroupIds = $this->selectedGroupIdsForStaff($staffId, $tipo, $fallbackGroupId);
                if (empty($selectedGroupIds) || empty(array_intersect($selectedGroupIds, $groupIds))) {
                    continue;
                }

                if (!$this->syncForStaff($staffId, $tipo, $selectedGroupIds)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            log_message('error', 'StaffDoctorLinkService resyncManagedStaffForGroups ERROR: ' . $e->getMessage());
            return false;
        }
    }

    public function getSecretariesForSelect(): array
    {
        return $this->getStaffForSelectByTipo(self::TIPO_SEGRETERIA, 'getSecretariesForSelect');
    }

    public function getNursesForSelect(): array
    {
        return $this->getStaffForSelectByTipo(self::TIPO_INFERMIERE, 'getNursesForSelect');
    }

    public function getLinkedDoctorIdsForSecretary(int $staffId): array
    {
        return $this->getLinkedDoctorIdsByTable('dap14_seg_dot', 'id_seg', $staffId);
    }

    public function getLinkedDoctorIdsForNurse(int $staffId): array
    {
        return $this->getLinkedDoctorIdsByTable('dap15_inf_dot', 'id_inf', $staffId);
    }

    public function getDoctorsForDap14Grid(int $staffId = 0): array
    {
        $selectedIds = $this->getLinkedDoctorIdsForSecretary($staffId);
        return $this->buildDoctorsGrid($selectedIds, 'getDoctorsForDap14Grid');
    }

    public function getDoctorsForDap15Grid(int $staffId = 0): array
    {
        $selectedIds = $this->getLinkedDoctorIdsForNurse($staffId);
        return $this->buildDoctorsGrid($selectedIds, 'getDoctorsForDap15Grid');
    }

    public function replaceSecretaryDoctorLinks(int $staffId, array $doctorIds): bool
    {
        return $this->replaceStaffDoctorLinksByTable(
            $staffId,
            $doctorIds,
            self::TIPO_SEGRETERIA,
            'dap14_seg_dot',
            'id_seg',
            'replaceSecretaryDoctorLinks'
        );
    }

    public function replaceNurseDoctorLinks(int $staffId, array $doctorIds): bool
    {
        return $this->replaceStaffDoctorLinksByTable(
            $staffId,
            $doctorIds,
            self::TIPO_INFERMIERE,
            'dap15_inf_dot',
            'id_inf',
            'replaceNurseDoctorLinks'
        );
    }

    private function getStaffForSelectByTipo(int $tipo, string $methodName): array
    {
        $decNome = $this->crypto->decryptSenzaAlias('p.nome');
        $decCognome = $this->crypto->decryptSenzaAlias('p.cognome');
        $decQualifica = $this->crypto->decryptSenzaAlias('p.qualifica');

        $query = $this->db->query("
            SELECT
                p.id_personale,
                p.id_user,
                {$decNome} AS nome,
                {$decCognome} AS cognome,
                {$decQualifica} AS qualifica
            FROM dap03_personale p
            WHERE p.tipo = ?
            ORDER BY cognome ASC, nome ASC
        ", [$tipo]);

        if ($query === false) {
            log_message('error', 'StaffDoctorLinkService ' . $methodName . ' ERROR: query personale fallita');
            return [];
        }

        $rows = $query->getResultArray();
        $userIds = array_values(array_unique(array_filter(
            array_map(static fn(array $row): int => (int)($row['id_user'] ?? 0), $rows),
            static fn(int $id): bool => $id > 0
        )));

        $usernameMap = [];
        if (!empty($userIds)) {
            $userRows = $this->db->table('dap01_users')
                ->select('id_user, username')
                ->whereIn('id_user', $userIds)
                ->get()
                ->getResultArray();

            foreach ($userRows as $userRow) {
                $usernameMap[(int)($userRow['id_user'] ?? 0)] = trim((string)($userRow['username'] ?? ''));
            }
        }

        return array_map(static function (array $row) use ($usernameMap): array {
            $nome = trim((string)($row['nome'] ?? ''));
            $cognome = trim((string)($row['cognome'] ?? ''));
            $qualifica = trim((string)($row['qualifica'] ?? ''));
            $idUser = (int)($row['id_user'] ?? 0);

            return [
                'id_personale' => (int)($row['id_personale'] ?? 0),
                'id_user' => $idUser,
                'nome' => $nome,
                'cognome' => $cognome,
                'qualifica' => $qualifica,
                'username' => $usernameMap[$idUser] ?? '',
                'label' => trim(($qualifica !== '' ? $qualifica . ' ' : '') . $cognome . ' ' . $nome),
            ];
        }, $rows);
    }

    private function getLinkedDoctorIdsByTable(string $table, string $staffField, int $staffId): array
    {
        if ($staffId <= 0) {
            return [];
        }

        $rows = $this->db->table($table)
            ->select('id_dot')
            ->where($staffField, $staffId)
            ->get()
            ->getResultArray();

        $doctorIds = array_map(static fn(array $row): int => (int)($row['id_dot'] ?? 0), $rows);
        $doctorIds = array_values(array_unique(array_filter($doctorIds, static fn(int $id): bool => $id > 0)));
        sort($doctorIds);

        return $doctorIds;
    }

    private function buildDoctorsGrid(array $selectedIds, string $methodName): array
    {
        $selectedMap = array_fill_keys($selectedIds, true);
        $locationNameMap = $this->locationCatalog->selectableLocationNameMap();

        $decNome = $this->crypto->decryptSenzaAlias('p.nome');
        $decCognome = $this->crypto->decryptSenzaAlias('p.cognome');
        $decQualifica = $this->crypto->decryptSenzaAlias('p.qualifica');

        $query = $this->db->query("
            SELECT
                p.id_personale,
                {$decNome} AS nome,
                {$decCognome} AS cognome,
                {$decQualifica} AS qualifica,
                p.luogo,
                p.sostituto,
                p.titolare,
                p.is_active
            FROM dap03_personale p
            WHERE p.tipo = 1
        ");

        if ($query === false) {
            log_message('error', 'StaffDoctorLinkService ' . $methodName . ' ERROR: query medici fallita');
            return [];
        }

        $rows = array_map(static function (array $row) use ($selectedMap, $locationNameMap): array {
            $doctorId = (int)($row['id_personale'] ?? 0);
            $nome = trim((string)($row['nome'] ?? ''));
            $cognome = trim((string)($row['cognome'] ?? ''));
            $qualifica = trim((string)($row['qualifica'] ?? ''));
            $luogo = (int)($row['luogo'] ?? 0);
            $sedeNome = trim((string)($locationNameMap[$luogo] ?? ''));
            $missingLocation = $luogo <= 0 || $sedeNome === '';

            return [
                'id_personale' => $doctorId,
                'nome' => $nome,
                'cognome' => $cognome,
                'qualifica' => $qualifica,
                'label' => trim(($qualifica !== '' ? $qualifica . ' ' : '') . $cognome . ' ' . $nome),
                'luogo' => $luogo,
                'sede_nome' => $sedeNome,
                'missing_location' => $missingLocation,
                'selected' => isset($selectedMap[$doctorId]),
                'sostituto' => (int)($row['sostituto'] ?? 0),
                'titolare' => (int)($row['titolare'] ?? 0),
                'is_active' => (int)($row['is_active'] ?? 0),
            ];
        }, $query->getResultArray());

        usort($rows, static function (array $left, array $right): int {
            $byMissing = ((int) $left['missing_location']) <=> ((int) $right['missing_location']);
            if ($byMissing !== 0) {
                return $byMissing;
            }

            $byLocation = strcasecmp((string) ($left['sede_nome'] ?? ''), (string) ($right['sede_nome'] ?? ''));
            if ($byLocation !== 0) {
                return $byLocation;
            }

            $bySurname = strcasecmp((string) ($left['cognome'] ?? ''), (string) ($right['cognome'] ?? ''));
            if ($bySurname !== 0) {
                return $bySurname;
            }

            return strcasecmp((string) ($left['nome'] ?? ''), (string) ($right['nome'] ?? ''));
        });

        return $rows;
    }

    private function replaceStaffDoctorLinksByTable(
        int $staffId,
        array $doctorIds,
        int $requiredTipo,
        string $table,
        string $staffField,
        string $methodName
    ): bool
    {
        if ($staffId <= 0) {
            return false;
        }

        $doctorIds = array_values(array_unique(array_filter(array_map('intval', $doctorIds), static fn(int $id): bool => $id > 0)));
        $transactionStarted = false;

        try {
            $validStaff = (int)($this->db->table('dap03_personale')
                ->select('id_personale')
                ->where('id_personale', $staffId)
                ->where('tipo', $requiredTipo)
                ->countAllResults()) > 0;

            if (!$validStaff) {
                return false;
            }

            if (!empty($doctorIds)) {
                $rows = $this->db->table('dap03_personale')
                    ->select('id_personale')
                    ->where('tipo', 1)
                    ->whereIn('id_personale', $doctorIds)
                    ->get()
                    ->getResultArray();

                $doctorIds = array_map(static fn(array $row): int => (int)($row['id_personale'] ?? 0), $rows);
                $doctorIds = array_values(array_unique(array_filter($doctorIds, static fn(int $id): bool => $id > 0)));
            }

            $this->db->transStart();
            $transactionStarted = true;

            $this->db->table($table)->where($staffField, $staffId)->delete();

            if (!empty($doctorIds)) {
                $rowsToInsert = array_map(
                    static fn(int $doctorId): array => [
                        $staffField => $staffId,
                        'id_dot' => $doctorId,
                    ],
                    $doctorIds
                );

                $this->db->table($table)->insertBatch($rowsToInsert);
            }

            $this->db->transComplete();
            $transactionStarted = false;

            return $this->db->transStatus() !== false;
        } catch (\Throwable $e) {
            log_message('error', 'StaffDoctorLinkService ' . $methodName . ' ERROR: ' . $e->getMessage());
            if ($transactionStarted) {
                $this->db->transRollback();
            }
            return false;
        }
    }

    private function clearStaffLinks(int $staffId): void
    {
        $this->db->table('dap14_seg_dot')->where('id_seg', $staffId)->delete();
        $this->db->table('dap15_inf_dot')->where('id_inf', $staffId)->delete();
    }

    private function doctorIdsForGroups(array $groupIds): array
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn(int $id): bool => $id > 0)));
        if (empty($groupIds)) {
            return [];
        }

        $rows = $this->db->table('dap03_personale')
            ->select('id_personale')
            ->where('tipo', 1)
            ->where('titolare', 1)
            ->whereNotIn('id_user', [15, 41])
            ->whereIn('luogo', $groupIds)
            ->orderBy('id_personale', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(static fn(array $row): int => (int)$row['id_personale'], $rows);
    }
}
