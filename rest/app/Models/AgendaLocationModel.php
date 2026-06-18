<?php

namespace App\Models;

use CodeIgniter\Model;
use Exception;

class AgendaLocationModel extends Model
{
    protected $db;
    private ?bool $roomsTableExists = null;
    private ?bool $ambulatoriActiveColumnExists = null;
    private ?bool $ambulatoriOrderColumnExists = null;
    private ?bool $configFasceRoomColumnExists = null;
    private ?bool $slotRoomColumnExists = null;

    public function __construct()
    {
        parent::__construct();
        $this->db = \Config\Database::connect();
    }

    public function getCatalog(bool $onlyActive = true): array
    {
        if (!$this->db->tableExists('dap42_ambulatori')) {
            return [];
        }

        $ambBuilder = $this->db->table('dap42_ambulatori')
            ->select('id_amb_legacy, nome, indirizzo, citta, telefono', false);

        if ($this->ambulatoriHaveActiveColumn() && $onlyActive) {
            $ambBuilder->where('attiva', 1);
        }

        if ($this->ambulatoriHaveOrderColumn()) {
            $ambBuilder->orderBy('ordinamento', 'ASC');
        }
        $ambBuilder->orderBy('nome', 'ASC');

        $ambulatori = $ambBuilder->get()->getResultArray();
        if (empty($ambulatori)) {
            return [];
        }

        $catalog = [];
        $ids = [];
        foreach ($ambulatori as $row) {
            $id = (int)($row['id_amb_legacy'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $catalog[$id] = [
                'id_amb_legacy' => $id,
                'nome'          => trim((string)($row['nome'] ?? '')),
                'indirizzo'     => trim((string)($row['indirizzo'] ?? '')),
                'citta'         => trim((string)($row['citta'] ?? '')),
                'telefono'      => trim((string)($row['telefono'] ?? '')),
                'stanze'        => [],
            ];
            $ids[] = $id;
        }

        if (!empty($ids) && $this->roomsTableExists()) {
            $roomBuilder = $this->db->table('dap43_ambulatori_stanze')
                ->select('id_stanza, id_amb_legacy, nome', false)
                ->whereIn('id_amb_legacy', $ids);

            if ($onlyActive) {
                $roomBuilder->where('attiva', 1);
            }

            $roomBuilder
                ->orderBy('ordinamento', 'ASC')
                ->orderBy('nome', 'ASC');

            foreach ($roomBuilder->get()->getResultArray() as $row) {
                $idAmb = (int)($row['id_amb_legacy'] ?? 0);
                if (!isset($catalog[$idAmb])) {
                    continue;
                }

                $catalog[$idAmb]['stanze'][] = [
                    'id_stanza' => (int)($row['id_stanza'] ?? 0),
                    'nome'      => trim((string)($row['nome'] ?? '')),
                ];
            }
        }

        return array_values($catalog);
    }

    public function getAdminCatalog(): array
    {
        if (!$this->db->tableExists('dap42_ambulatori')) {
            return [];
        }

        $ambBuilder = $this->db->table('dap42_ambulatori')
            ->select('id_amb_legacy, nome, indirizzo, citta, telefono', false);

        if ($this->ambulatoriHaveActiveColumn()) {
            $ambBuilder->select('attiva', false);
        } else {
            $ambBuilder->select('1 AS attiva', false);
        }

        if ($this->ambulatoriHaveOrderColumn()) {
            $ambBuilder->select('ordinamento', false)
                ->orderBy('ordinamento', 'ASC');
        } else {
            $ambBuilder->select('0 AS ordinamento', false);
        }

        $ambBuilder->orderBy('nome', 'ASC');
        $ambulatori = $ambBuilder->get()->getResultArray();

        $catalog = [];
        $ids = [];
        foreach ($ambulatori as $row) {
            $id = (int)($row['id_amb_legacy'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $catalog[$id] = [
                'id_amb_legacy' => $id,
                'nome'          => trim((string)($row['nome'] ?? '')),
                'indirizzo'     => trim((string)($row['indirizzo'] ?? '')),
                'citta'         => trim((string)($row['citta'] ?? '')),
                'telefono'      => trim((string)($row['telefono'] ?? '')),
                'attiva'        => (int)($row['attiva'] ?? 1),
                'ordinamento'   => (int)($row['ordinamento'] ?? 0),
                'stanze'        => [],
            ];
            $ids[] = $id;
        }

        if (!empty($ids) && $this->roomsTableExists()) {
            $rows = $this->db->table('dap43_ambulatori_stanze')
                ->select('id_stanza, id_amb_legacy, nome, ordinamento, attiva', false)
                ->whereIn('id_amb_legacy', $ids)
                ->orderBy('id_amb_legacy', 'ASC')
                ->orderBy('ordinamento', 'ASC')
                ->orderBy('nome', 'ASC')
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $idAmb = (int)($row['id_amb_legacy'] ?? 0);
                if (!isset($catalog[$idAmb])) {
                    continue;
                }

                $catalog[$idAmb]['stanze'][] = [
                    'id_stanza'   => (int)($row['id_stanza'] ?? 0),
                    'id_amb_legacy' => $idAmb,
                    'nome'        => trim((string)($row['nome'] ?? '')),
                    'ordinamento' => (int)($row['ordinamento'] ?? 0),
                    'attiva'      => (int)($row['attiva'] ?? 1),
                ];
            }
        }

        return array_values($catalog);
    }

    public function getAmbulatorioById(int $idAmbLegacy): ?array
    {
        if ($idAmbLegacy <= 0 || !$this->db->tableExists('dap42_ambulatori')) {
            return null;
        }

        $builder = $this->db->table('dap42_ambulatori')
            ->select('id_amb_legacy, nome, indirizzo, citta, telefono', false)
            ->where('id_amb_legacy', $idAmbLegacy);

        if ($this->ambulatoriHaveActiveColumn()) {
            $builder->select('attiva', false);
        } else {
            $builder->select('1 AS attiva', false);
        }

        if ($this->ambulatoriHaveOrderColumn()) {
            $builder->select('ordinamento', false);
        } else {
            $builder->select('0 AS ordinamento', false);
        }

        $row = $builder->get()->getRowArray();
        if (!$row) {
            return null;
        }

        return [
            'id_amb_legacy' => (int)($row['id_amb_legacy'] ?? 0),
            'nome'          => trim((string)($row['nome'] ?? '')),
            'indirizzo'     => trim((string)($row['indirizzo'] ?? '')),
            'citta'         => trim((string)($row['citta'] ?? '')),
            'telefono'      => trim((string)($row['telefono'] ?? '')),
            'attiva'        => (int)($row['attiva'] ?? 1),
            'ordinamento'   => (int)($row['ordinamento'] ?? 0),
        ];
    }

    public function getStanzaById(int $idStanza): ?array
    {
        if ($idStanza <= 0 || !$this->roomsTableExists()) {
            return null;
        }

        $row = $this->db->table('dap43_ambulatori_stanze')
            ->select('id_stanza, id_amb_legacy, nome, ordinamento, attiva', false)
            ->where('id_stanza', $idStanza)
            ->get()
            ->getRowArray();

        if (!$row) {
            return null;
        }

        return [
            'id_stanza'     => (int)($row['id_stanza'] ?? 0),
            'id_amb_legacy' => (int)($row['id_amb_legacy'] ?? 0),
            'nome'          => trim((string)($row['nome'] ?? '')),
            'ordinamento'   => (int)($row['ordinamento'] ?? 0),
            'attiva'        => (int)($row['attiva'] ?? 1),
        ];
    }

    public function resolveSelection(array $payload): array
    {
        $idAmbLegacy = (int)($payload['id_amb_legacy'] ?? 0);
        $idStanza = (int)($payload['id_stanza'] ?? 0);
        $ambulatorio = trim((string)($payload['ambulatorio'] ?? ''));
        $stanza = trim((string)($payload['stanza'] ?? ''));

        if ($idStanza > 0) {
            $room = $this->getStanzaById($idStanza);
            if ($room === null) {
                throw new Exception('Stanza non valida.');
            }

            $idStanza = (int)$room['id_stanza'];
            $idAmbLegacy = (int)$room['id_amb_legacy'];
            $stanza = (string)$room['nome'];
        }

        if ($idAmbLegacy > 0) {
            $amb = $this->getAmbulatorioById($idAmbLegacy);
            if ($amb === null) {
                throw new Exception('Sede non valida.');
            }

            $idAmbLegacy = (int)$amb['id_amb_legacy'];
            $ambulatorio = (string)$amb['nome'];
        }

        return [
            'id_amb_legacy' => $idAmbLegacy > 0 ? $idAmbLegacy : null,
            'id_stanza'     => $idStanza > 0 ? $idStanza : null,
            'ambulatorio'   => $ambulatorio,
            'stanza'        => $stanza,
        ];
    }

    public function saveAmbulatorio(array $data): int
    {
        if (!$this->db->tableExists('dap42_ambulatori')) {
            throw new Exception('Tabella sedi non disponibile.');
        }

        $id = (int)($data['id_amb_legacy'] ?? 0);
        $nome = trim((string)($data['nome'] ?? ''));
        $indirizzo = trim((string)($data['indirizzo'] ?? ''));
        $citta = trim((string)($data['citta'] ?? ''));
        $telefono = trim((string)($data['telefono'] ?? ''));
        $attiva = !empty($data['attiva']) ? 1 : 0;
        $ordinamento = max(0, (int)($data['ordinamento'] ?? 0));

        if ($nome === '') {
            throw new Exception('Il nome della sede e obbligatorio.');
        }

        $payload = [
            'nome'       => $nome,
            'indirizzo'  => $indirizzo !== '' ? $indirizzo : null,
            'citta'      => $citta !== '' ? $citta : null,
            'telefono'   => $telefono !== '' ? $telefono : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->ambulatoriHaveActiveColumn()) {
            $payload['attiva'] = $attiva;
        }
        if ($this->ambulatoriHaveOrderColumn()) {
            $payload['ordinamento'] = $ordinamento;
        }

        if ($id > 0) {
            if ($this->getAmbulatorioById($id) === null) {
                throw new Exception('Sede non trovata.');
            }

            $this->db->table('dap42_ambulatori')
                ->where('id_amb_legacy', $id)
                ->update($payload);

            return $id;
        }

        $id = $this->generateNewAmbulatorioId();
        $payload['id_amb_legacy'] = $id;
        $payload['created_at'] = date('Y-m-d H:i:s');

        $this->db->table('dap42_ambulatori')->insert($payload);

        return $id;
    }

    public function saveStanza(array $data): int
    {
        if (!$this->roomsTableExists()) {
            throw new Exception('Tabella stanze non disponibile.');
        }

        $idStanza = (int)($data['id_stanza'] ?? 0);
        $idAmbLegacy = (int)($data['id_amb_legacy'] ?? 0);
        $nome = trim((string)($data['nome'] ?? ''));
        $attiva = !empty($data['attiva']) ? 1 : 0;
        $ordinamento = max(0, (int)($data['ordinamento'] ?? 0));

        if ($idAmbLegacy <= 0 || $this->getAmbulatorioById($idAmbLegacy) === null) {
            throw new Exception('Seleziona una sede valida.');
        }

        if ($nome === '') {
            throw new Exception('Il nome della stanza e obbligatorio.');
        }

        $existing = $this->db->table('dap43_ambulatori_stanze')
            ->select('id_stanza')
            ->where('id_amb_legacy', $idAmbLegacy)
            ->where('nome', $nome);

        if ($idStanza > 0) {
            $existing->where('id_stanza <>', $idStanza);
        }

        if ($existing->countAllResults() > 0) {
            throw new Exception('Esiste gia una stanza con questo nome per la sede selezionata.');
        }

        $payload = [
            'id_amb_legacy' => $idAmbLegacy,
            'nome'          => $nome,
            'ordinamento'   => $ordinamento,
            'attiva'        => $attiva,
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        if ($idStanza > 0) {
            if ($this->getStanzaById($idStanza) === null) {
                throw new Exception('Stanza non trovata.');
            }

            $this->db->table('dap43_ambulatori_stanze')
                ->where('id_stanza', $idStanza)
                ->update($payload);

            return $idStanza;
        }

        $payload['created_at'] = date('Y-m-d H:i:s');
        $this->db->table('dap43_ambulatori_stanze')->insert($payload);

        return (int)$this->db->insertID();
    }

    public function setAmbulatorioActive(int $idAmbLegacy, bool $active): bool
    {
        if ($idAmbLegacy <= 0 || !$this->ambulatoriHaveActiveColumn()) {
            return false;
        }

        return (bool)$this->db->table('dap42_ambulatori')
            ->where('id_amb_legacy', $idAmbLegacy)
            ->update([
                'attiva'     => $active ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function setStanzaActive(int $idStanza, bool $active): bool
    {
        if ($idStanza <= 0 || !$this->roomsTableExists()) {
            return false;
        }

        return (bool)$this->db->table('dap43_ambulatori_stanze')
            ->where('id_stanza', $idStanza)
            ->update([
                'attiva'     => $active ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function configFasceHaveRoomColumn(): bool
    {
        if ($this->configFasceRoomColumnExists === null) {
            $this->configFasceRoomColumnExists = $this->db->fieldExists('id_stanza', 'dap10_agenda_config_fasce');
        }

        return $this->configFasceRoomColumnExists;
    }

    public function slotTableHasRoomColumn(): bool
    {
        if ($this->slotRoomColumnExists === null) {
            $this->slotRoomColumnExists = $this->db->fieldExists('id_stanza', 'dap11_agenda_slot');
        }

        return $this->slotRoomColumnExists;
    }

    private function roomsTableExists(): bool
    {
        if ($this->roomsTableExists === null) {
            $this->roomsTableExists = $this->db->tableExists('dap43_ambulatori_stanze');
        }

        return $this->roomsTableExists;
    }

    private function ambulatoriHaveActiveColumn(): bool
    {
        if ($this->ambulatoriActiveColumnExists === null) {
            $this->ambulatoriActiveColumnExists = $this->db->fieldExists('attiva', 'dap42_ambulatori');
        }

        return $this->ambulatoriActiveColumnExists;
    }

    private function ambulatoriHaveOrderColumn(): bool
    {
        if ($this->ambulatoriOrderColumnExists === null) {
            $this->ambulatoriOrderColumnExists = $this->db->fieldExists('ordinamento', 'dap42_ambulatori');
        }

        return $this->ambulatoriOrderColumnExists;
    }

    private function generateNewAmbulatorioId(): int
    {
        $row = $this->db->table('dap42_ambulatori')
            ->selectMax('id_amb_legacy', 'max_id')
            ->get()
            ->getRowArray();

        $currentMax = (int)($row['max_id'] ?? 0);
        if ($currentMax < 99999) {
            return 100000;
        }

        return $currentMax + 1;
    }
}
