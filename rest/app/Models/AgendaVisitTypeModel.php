<?php

namespace App\Models;

use App\Services\AgendaVisitTypeSchemaService;
use CodeIgniter\Model;
use Exception;

class AgendaVisitTypeModel extends Model
{
    /** @var array<int, string> */
    private const DEFAULT_COLORS = [
        '#3C8DBC',
        '#16A085',
        '#5E72E4',
        '#EB6B56',
        '#8E44AD',
        '#F39C12',
        '#27AE60',
        '#C0392B',
        '#2C82C9',
        '#D35400',
    ];

    protected $table = 'dap44_agenda_tipi_visita';
    protected $primaryKey = 'id_tipo_visita';
    protected $returnType = 'array';
    protected $allowedFields = [
        'nome',
        'durata_minuti',
        'colore',
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
        $coloreInput = trim((string) ($data['colore'] ?? ''));
        $attivo = array_key_exists('attivo', $data)
            ? ((int) $data['attivo'] === 1 ? 1 : 0)
            : 1;
        $existing = null;

        if ($nome === '') {
            throw new Exception('Il nome del tipo visita e obbligatorio.');
        }

        if ($durataMinuti <= 0) {
            throw new Exception('La durata del tipo visita deve essere maggiore di zero.');
        }

        if ($durataMinuti > 480) {
            throw new Exception('La durata del tipo visita non puo superare 480 minuti.');
        }

        if ($coloreInput !== '' && $this->normalizeStoredColor($coloreInput) === '') {
            throw new Exception('Seleziona un colore valido per il tipo visita.');
        }

        if ($idTipoVisita > 0) {
            $existing = $this->find($idTipoVisita);
            if (!$existing) {
                throw new Exception('Tipo visita non trovato.');
            }
        }

        $payload = [
            'nome' => $nome,
            'durata_minuti' => $durataMinuti,
            'colore' => $this->resolveColorValue($coloreInput, $existing),
            'attivo' => $attivo,
            'updated_by' => $userId > 0 ? $userId : null,
        ];

        if ($idTipoVisita > 0) {
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
        $row['colore'] = $this->resolveNormalizedRowColor($row);
        return $row;
    }

    private function resolveColorValue(string $colorInput, ?array $existingRow = null): string
    {
        $normalizedInput = $this->normalizeStoredColor($colorInput);
        if ($normalizedInput !== '') {
            return $normalizedInput;
        }

        $existingColor = $this->normalizeStoredColor((string) ($existingRow['colore'] ?? ''));
        if ($existingColor !== '') {
            return $existingColor;
        }

        return $this->pickDefaultColor($existingRow);
    }

    private function resolveNormalizedRowColor(array $row): string
    {
        $normalized = $this->normalizeStoredColor((string) ($row['colore'] ?? ''));
        if ($normalized !== '') {
            return $normalized;
        }

        return $this->pickDefaultColor($row);
    }

    private function pickDefaultColor(?array $row = null): string
    {
        $paletteSize = count(self::DEFAULT_COLORS);
        if ($paletteSize === 0) {
            return '#3C8DBC';
        }

        $idTipoVisita = (int) ($row['id_tipo_visita'] ?? 0);
        if ($idTipoVisita > 0) {
            return self::DEFAULT_COLORS[($idTipoVisita - 1) % $paletteSize];
        }

        $count = 0;
        if ($this->db->tableExists($this->table)) {
            $count = (int) $this->builder()->countAllResults();
        }

        return self::DEFAULT_COLORS[$count % $paletteSize];
    }

    private function normalizeStoredColor(string $value): string
    {
        $normalized = strtoupper(trim($value));
        if (preg_match('/^#[0-9A-F]{6}$/', $normalized) === 1) {
            return $normalized;
        }

        return '';
    }

    private function ensureSchemaReady(): void
    {
        $this->schemaService ??= new AgendaVisitTypeSchemaService($this->db);
        $this->schemaService->ensureReady();
    }
}
