<?php

namespace App\Models;

use CodeIgniter\Model;

class ClientDoctorModel extends Model
{
    protected $table = 'dap09_client_doctor';
    protected $primaryKey = 'id_users_doctor';
    protected $allowedFields = ['id_client', 'id_dot'];
    protected $useTimestamps = false;
    private array $doctorMetaCache = [];
    private array $clientPrimaryDoctorCache = [];

    public function setDoctorForClient(int $idClient, int $idDot, bool $syncSearch = true): bool
    {
        if ($idClient <= 0) {
            log_message('error', 'setDoctorForClient ERROR: id_client non valido');
            return false;
        }

        $db = $this->db ?? \Config\Database::connect();

        try {
            $snapshot = $this->getPreferredDoctorLinkForClient($idClient, $idDot);
            if (($snapshot['relation_count'] ?? 0) > 1) {
                log_message(
                    'warning',
                    'setDoctorForClient: trovate relazioni duplicate per id_client={idClient}; count={count}, distinct={distinct}. Eseguo riallineamento a id_dot={idDot}',
                    [
                        'idClient' => $idClient,
                        'count' => (int)($snapshot['relation_count'] ?? 0),
                        'distinct' => (int)($snapshot['distinct_doctor_count'] ?? 0),
                        'idDot' => $idDot,
                    ]
                );
            }

            $db->transBegin();

            $builder = $db->table($this->table);
            $existingDoctorIds = $this->getDoctorIdsForClient($idClient);

            if ($idDot <= 0) {
                $builder->where('id_client', $idClient)->delete();
                log_message('debug', 'DELETE dap09_client_doctor => id_client=' . $idClient . ' (reset completo)');
            } else {
                $targetIsFamilyDoctor = $this->isFamilyDoctor($idDot);
                $hasTargetLink = in_array($idDot, $existingDoctorIds, true);

                if ($targetIsFamilyDoctor) {
                    $familyDoctorIdsToRemove = [];
                    foreach ($existingDoctorIds as $existingDoctorId) {
                        if ($existingDoctorId !== $idDot && $this->isFamilyDoctor($existingDoctorId)) {
                            $familyDoctorIdsToRemove[] = $existingDoctorId;
                        }
                    }

                    if ($familyDoctorIdsToRemove !== []) {
                        $builder
                            ->where('id_client', $idClient)
                            ->whereIn('id_dot', $familyDoctorIdsToRemove)
                            ->delete();

                        log_message(
                            'debug',
                            'DELETE dap09_client_doctor => id_client={idClient}, removed_family_doctors={doctorIds}',
                            [
                                'idClient' => $idClient,
                                'doctorIds' => implode(',', $familyDoctorIdsToRemove),
                            ]
                        );
                    }
                }

                if (!$hasTargetLink) {
                    $builder->insert([
                        'id_client' => $idClient,
                        'id_dot' => $idDot,
                    ]);
                    log_message('debug', 'INSERT dap09_client_doctor => id_client=' . $idClient . ', id_dot=' . $idDot);
                } else {
                    log_message('debug', 'KEEP dap09_client_doctor => id_client=' . $idClient . ', id_dot=' . $idDot . ' (link gia presente)');
                }
            }

            if (!$db->transStatus()) {
                $db->transRollback();
                return false;
            }

            $db->transCommit();

            if ($syncSearch) {
                (new DoctorPatientSearchModel())->syncClient($idClient);
            }

            return true;
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'setDoctorForClient ERROR: ' . $e->getMessage());
            return false;
        }
    }

    public function getDoctorIdsForClient(int $idClient): array
    {
        if ($idClient <= 0) {
            return [];
        }

        $rows = ($this->db ?? \Config\Database::connect())
            ->table($this->table)
            ->select('id_users_doctor, id_dot')
            ->where('id_client', $idClient)
            ->orderBy('id_users_doctor', 'DESC')
            ->get()
            ->getResultArray();

        $doctorIds = [];
        foreach ($rows as $row) {
            $doctorId = (int)($row['id_dot'] ?? 0);
            if ($doctorId > 0 && !in_array($doctorId, $doctorIds, true)) {
                $doctorIds[] = $doctorId;
            }
        }

        return $doctorIds;
    }

    public function getPreferredDoctorLinkForClient(int $idClient, int $preferredDoctorId = 0): array
    {
        $fallbackDoctorId = $preferredDoctorId > 0 ? $preferredDoctorId : 0;

        if ($idClient <= 0) {
            return [
                'id_dot' => $fallbackDoctorId,
                'relation_count' => 0,
                'distinct_doctor_count' => $fallbackDoctorId > 0 ? 1 : 0,
                'matched_preferred' => $fallbackDoctorId > 0,
                'source' => $fallbackDoctorId > 0 ? 'client_fallback' : 'none',
                'primary_id_dot' => $fallbackDoctorId,
                'doctor_ids' => $fallbackDoctorId > 0 ? [$fallbackDoctorId] : [],
                'family_doctor_ids' => $fallbackDoctorId > 0 && $this->isFamilyDoctor($fallbackDoctorId) ? [$fallbackDoctorId] : [],
                'specialist_doctor_ids' => $fallbackDoctorId > 0 && !$this->isFamilyDoctor($fallbackDoctorId) ? [$fallbackDoctorId] : [],
            ];
        }

        $rows = ($this->db ?? \Config\Database::connect())
            ->table($this->table)
            ->select('id_users_doctor, id_dot')
            ->where('id_client', $idClient)
            ->orderBy('id_users_doctor', 'DESC')
            ->get()
            ->getResultArray();

        $clientPrimaryDoctorId = $this->getPrimaryDoctorIdFromClient($idClient);
        $distinctDoctorIds = [];
        $familyDoctorIds = [];
        $specialistDoctorIds = [];
        $selected = null;
        $selectedPrimary = null;
        $selectedFamily = null;

        foreach ($rows as $row) {
            $doctorId = (int)($row['id_dot'] ?? 0);
            if ($doctorId > 0) {
                $distinctDoctorIds[$doctorId] = $doctorId;
                if ($this->isFamilyDoctor($doctorId)) {
                    $familyDoctorIds[$doctorId] = $doctorId;
                    if ($selectedFamily === null) {
                        $selectedFamily = $row;
                    }
                } else {
                    $specialistDoctorIds[$doctorId] = $doctorId;
                }
            }

            if ($selected === null && $preferredDoctorId > 0 && $doctorId === $preferredDoctorId) {
                $selected = $row;
            }

            if ($selectedPrimary === null && $clientPrimaryDoctorId > 0 && $doctorId === $clientPrimaryDoctorId) {
                $selectedPrimary = $row;
            }
        }

        if ($selected === null) {
            if ($selectedPrimary !== null) {
                $selected = $selectedPrimary;
            } elseif ($selectedFamily !== null) {
                $selected = $selectedFamily;
            } elseif (!empty($rows)) {
                $selected = $rows[0];
            }
        }

        $resolvedDoctorId = $selected ? (int)($selected['id_dot'] ?? 0) : 0;
        $matchedPreferred = $preferredDoctorId > 0 && $resolvedDoctorId === $preferredDoctorId;
        $source = 'relation';

        if ($resolvedDoctorId <= 0 && $fallbackDoctorId > 0) {
            $resolvedDoctorId = $fallbackDoctorId;
            $matchedPreferred = true;
            $source = 'client_fallback';
        } elseif (empty($rows) && $fallbackDoctorId > 0) {
            $resolvedDoctorId = $fallbackDoctorId;
            $matchedPreferred = true;
            $source = 'client_fallback';
        } elseif (empty($rows)) {
            $source = 'none';
        }

        return [
            'id_dot' => $resolvedDoctorId,
            'relation_count' => count($rows),
            'distinct_doctor_count' => count($distinctDoctorIds),
            'matched_preferred' => $matchedPreferred,
            'source' => $source,
            'primary_id_dot' => $clientPrimaryDoctorId,
            'doctor_ids' => array_values($distinctDoctorIds),
            'family_doctor_ids' => array_values($familyDoctorIds),
            'specialist_doctor_ids' => array_values($specialistDoctorIds),
        ];
    }

    private function getPrimaryDoctorIdFromClient(int $idClient): int
    {
        if ($idClient <= 0) {
            return 0;
        }

        if (array_key_exists($idClient, $this->clientPrimaryDoctorCache)) {
            return $this->clientPrimaryDoctorCache[$idClient];
        }

        $row = ($this->db ?? \Config\Database::connect())
            ->table('dap02_clients')
            ->select('COALESCE(id_personale, 0) AS id_personale')
            ->where('id_client', $idClient)
            ->get(1)
            ->getRowArray();

        $this->clientPrimaryDoctorCache[$idClient] = (int)($row['id_personale'] ?? 0);
        return $this->clientPrimaryDoctorCache[$idClient];
    }

    private function isFamilyDoctor(int $doctorId): bool
    {
        if ($doctorId <= 0) {
            return false;
        }

        if (!array_key_exists($doctorId, $this->doctorMetaCache)) {
            $row = ($this->db ?? \Config\Database::connect())
                ->table('dap03_personale')
                ->select('COALESCE(legacy_dot_tipo_id, 0) AS legacy_dot_tipo_id, COALESCE(f_dom, 0) AS f_dom')
                ->where('id_personale', $doctorId)
                ->get(1)
                ->getRowArray();

            $this->doctorMetaCache[$doctorId] = [
                'legacy_dot_tipo_id' => (int)($row['legacy_dot_tipo_id'] ?? 0),
                'f_dom' => (int)($row['f_dom'] ?? 0),
            ];
        }

        $legacyTypeId = (int)($this->doctorMetaCache[$doctorId]['legacy_dot_tipo_id'] ?? 0);
        if ($legacyTypeId > 0) {
            return $legacyTypeId === 1;
        }

        return (int)($this->doctorMetaCache[$doctorId]['f_dom'] ?? 0) === 1;
    }
}
