<?php

namespace App\Models;

use App\Services\AgendaVisitTypeSchemaService;
use CodeIgniter\Model;
use Exception;

class AgendaVisitTypeModel extends Model
{
    protected $table = 'dap44_agenda_tipi_visita';
    protected $primaryKey = 'id_tipo_visita';
    protected $returnType = 'array';
    protected $allowedFields = [
        'nome',
        'durata_minuti',
        'attivo',
        'ordinamento',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    private ?AgendaVisitTypeSchemaService $schemaService = null;

    public function listForAgenda(bool $includeInactive = true): array
    {
        try {
            $this->ensureSchemaReady();
            if (!$this->db->tableExists($this->table)) {
                return [];
            }

            $builder = $this->builder()
                ->orderBy('attivo', 'DESC')
                ->orderBy('ordinamento', 'ASC')
                ->orderBy('nome', 'ASC');

            if (!$includeInactive) {
                $builder->where('attivo', 1);
            }

            return array_map([$this, 'normalizeRow'], $builder->get()->getResultArray());
        } catch (\Throwable $e) {
            log_message('error', 'AgendaVisitTypeModel::listForAgenda failed: {message}', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function findType(int $idTipoVisita): ?array
    {
        if ($idTipoVisita <= 0) {
            return null;
        }

        try {
            $this->ensureSchemaReady();
            if (!$this->db->tableExists($this->table)) {
                return null;
            }

            $row = $this->find($idTipoVisita);
            return $row ? $this->normalizeRow($row) : null;
        } catch (\Throwable $e) {
            log_message('error', 'AgendaVisitTypeModel::findType failed: {message}', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function saveType(array $data, int $userId): int
    {
        $this->ensureSchemaReady();

        if (!$this->db->tableExists($this->table)) {
            throw new Exception('La tabella dei tipi visita non e disponibile.');
        }

        $idTipoVisita = (int) ($data['id_tipo_visita'] ?? 0);
        $nome = trim((string) ($data['nome'] ?? ''));
        $durataMinuti = (int) ($data['durata_minuti'] ?? 0);
        $attivo = array_key_exists('attivo', $data)
            ? ((int) $data['attivo'] === 1 ? 1 : 0)
            : 1;

        if ($nome === '') {
            throw new Exception('Il nome del tipo visita e obbligatorio.');
        }

        if ($durataMinuti <= 0) {
            throw new Exception('La durata del tipo visita deve essere maggiore di zero.');
        }

        if ($durataMinuti > 480) {
            throw new Exception('La durata del tipo visita non puo superare 480 minuti.');
        }

        $payload = [
            'nome' => $nome,
            'durata_minuti' => $durataMinuti,
            'attivo' => $attivo,
            'updated_by' => $userId > 0 ? $userId : null,
        ];

        if ($idTipoVisita > 0) {
            $existing = $this->find($idTipoVisita);
            if (!$existing) {
                throw new Exception('Tipo visita non trovato.');
            }

            $this->update($idTipoVisita, $payload);
            return $idTipoVisita;
        }

        $payload['ordinamento'] = $this->nextSortOrder();
        $payload['created_by'] = $userId > 0 ? $userId : null;

        $id = $this->insert($payload, true);
        if (!$id) {
            throw new Exception('Impossibile salvare il tipo visita.');
        }

        return (int) $id;
    }

    public function toggleActive(int $idTipoVisita, bool $active, int $userId): bool
    {
        if ($idTipoVisita <= 0) {
            throw new Exception('Tipo visita non valido.');
        }

        $this->ensureSchemaReady();

        if (!$this->db->tableExists($this->table)) {
            throw new Exception('Tipo visita non valido.');
        }

        $existing = $this->find($idTipoVisita);
        if (!$existing) {
            throw new Exception('Tipo visita non trovato.');
        }

        return (bool) $this->update($idTipoVisita, [
            'attivo' => $active ? 1 : 0,
            'updated_by' => $userId > 0 ? $userId : null,
        ]);
    }

    private function nextSortOrder(): int
    {
        $row = $this->builder()
            ->selectMax('ordinamento', 'max_ordinamento')
            ->get()
            ->getRowArray();

        return ((int) ($row['max_ordinamento'] ?? 0)) + 10;
    }

    private function normalizeRow(array $row): array
    {
        $row['id_tipo_visita'] = (int) ($row['id_tipo_visita'] ?? 0);
        $row['durata_minuti'] = (int) ($row['durata_minuti'] ?? 0);
        $row['attivo'] = (int) ($row['attivo'] ?? 0);
        $row['ordinamento'] = (int) ($row['ordinamento'] ?? 0);
        return $row;
    }

    private function ensureSchemaReady(): void
    {
        $this->schemaService ??= new AgendaVisitTypeSchemaService($this->db);
        $this->schemaService->ensureReady();
    }
}
