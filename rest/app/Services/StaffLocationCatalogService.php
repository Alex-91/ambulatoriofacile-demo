<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class StaffLocationCatalogService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array<int, array{id_gruppo:int, nome:string}>
     */
    public function listSelectableLocations(): array
    {
        $locations = $this->loadAgendaLocations();
        if ($locations !== []) {
            return $locations;
        }

        return $this->loadLegacyGroups();
    }

    /**
     * @return array<int>
     */
    public function selectableLocationIds(): array
    {
        return array_map(
            static fn(array $row): int => (int) ($row['id_gruppo'] ?? 0),
            $this->listSelectableLocations()
        );
    }

    /**
     * @return array<int, string>
     */
    public function selectableLocationNameMap(): array
    {
        $map = [];

        foreach ($this->listSelectableLocations() as $row) {
            $id = (int) ($row['id_gruppo'] ?? 0);
            $name = trim((string) ($row['nome'] ?? ''));

            if ($id > 0 && $name !== '') {
                $map[$id] = $name;
            }
        }

        return $map;
    }

    public function firstSelectableLocationId(): int
    {
        $ids = $this->selectableLocationIds();
        return (int) ($ids[0] ?? 0);
    }

    /**
     * @return array<int, array{id_gruppo:int, nome:string}>
     */
    private function loadAgendaLocations(): array
    {
        if (!$this->db->tableExists('dap42_ambulatori')) {
            return [];
        }

        $builder = $this->db->table('dap42_ambulatori')
            ->select('id_amb_legacy AS id_gruppo, nome');

        if ($this->db->fieldExists('attiva', 'dap42_ambulatori')) {
            $builder->where('attiva', 1);
        } elseif ($this->db->fieldExists('is_active', 'dap42_ambulatori')) {
            $builder->where('is_active', 1);
        }

        if ($this->db->fieldExists('ordinamento', 'dap42_ambulatori')) {
            $builder->orderBy('ordinamento', 'ASC');
        }

        $rows = $builder
            ->orderBy('nome', 'ASC')
            ->get()
            ->getResultArray();

        return $this->normalizeRows($rows, 'id_gruppo');
    }

    /**
     * @return array<int, array{id_gruppo:int, nome:string}>
     */
    private function loadLegacyGroups(): array
    {
        if (!$this->db->tableExists('dap21_gruppo')) {
            return [];
        }

        $rows = $this->db->table('dap21_gruppo')
            ->select('id_gruppo, nome')
            ->orderBy('nome', 'ASC')
            ->get()
            ->getResultArray();

        return $this->normalizeRows($rows, 'id_gruppo');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{id_gruppo:int, nome:string}>
     */
    private function normalizeRows(array $rows, string $idKey): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $id = (int) ($row[$idKey] ?? 0);
            $name = trim((string) ($row['nome'] ?? ''));

            if ($id <= 0 || $name === '') {
                continue;
            }

            $normalized[] = [
                'id_gruppo' => $id,
                'nome' => $name,
            ];
        }

        return $normalized;
    }
}
