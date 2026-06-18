<?php

namespace App\Models;

use CodeIgniter\Model;
use Exception;

class VisiteDomiciliariModel extends Model
{
    protected $table = 'dap13_visite_domiciliari';
    protected $primaryKey = 'id_visita';
    protected $returnType = 'array';
    protected $allowedFields = [
        'id_dot',
        'id_paziente',
        'giorno_visita',
        'cognome',
        'nome',
        'telefono',
        'cellulare',
        'indirizzo',
        'citta',
        'note',
        'data_modifica',
        'stato'
    ];

    public function getListaByDottore(int $idDot, ?string $giornoVisita = null): array
    {
        $builder = $this->db->table($this->table)
            ->where('id_dot', $idDot)
            ->where('stato', 'ATTIVA');

        $giornoVisita = $this->normalizeDateValue((string)$giornoVisita);
        if ($giornoVisita !== null) {
            $builder->where('giorno_visita', $giornoVisita);
        }

        return $builder
            ->orderBy('cognome', 'ASC')
            ->orderBy('nome', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function getDettaglio(int $idVisita): ?array
    {
        return $this->db->table($this->table)
            ->where('id_visita', $idVisita)
            ->where('stato', 'ATTIVA')
            ->get()
            ->getRowArray();
    }

    public function salvaVisita(array $data): int
    {
        $cognome = trim((string)($data['cognome'] ?? ''));
        $nome = trim((string)($data['nome'] ?? ''));
        $indirizzo = trim((string)($data['indirizzo'] ?? ''));
        $citta = trim((string)($data['citta'] ?? ''));

        if ($cognome === '' || $nome === '' || $indirizzo === '' || $citta === '') {
            throw new Exception('Compila cognome, nome, indirizzo e città.');
        }

        $this->insert([
            'id_dot'      => (int)$data['id_dot'],
            'id_paziente' => !empty($data['id_paziente']) ? (int)$data['id_paziente'] : null,
            'giorno_visita' => $this->normalizeDateValue((string)($data['giorno_visita'] ?? ($data['data_agenda'] ?? ''))),
            'cognome'     => $cognome,
            'nome'        => $nome,
            'telefono'    => trim((string)($data['telefono'] ?? '')),
            'cellulare'   => trim((string)($data['cellulare'] ?? '')),
            'indirizzo'   => $indirizzo,
            'citta'       => $citta,
            'note'        => trim((string)($data['note'] ?? '')),
        ]);

        return (int)$this->insertID();
    }

    public function aggiornaVisita(array $data): bool
    {
        $idVisita = (int)($data['id_visita'] ?? 0);
        if ($idVisita <= 0) {
            throw new Exception('Visita non valida.');
        }

        return (bool)$this->update($idVisita, [
            'id_paziente'   => !empty($data['id_paziente']) ? (int)$data['id_paziente'] : null,
            'giorno_visita' => $this->normalizeDateValue((string)($data['giorno_visita'] ?? ($data['data_agenda'] ?? ''))),
            'cognome'       => trim((string)($data['cognome'] ?? '')),
            'nome'          => trim((string)($data['nome'] ?? '')),
            'telefono'      => trim((string)($data['telefono'] ?? '')),
            'cellulare'     => trim((string)($data['cellulare'] ?? '')),
            'indirizzo'     => trim((string)($data['indirizzo'] ?? '')),
            'citta'         => trim((string)($data['citta'] ?? '')),
            'note'          => trim((string)($data['note'] ?? '')),
            'data_modifica' => date('Y-m-d H:i:s'),
        ]);
    }

    public function eliminaVisita(int $idVisita): bool
    {
        return (bool)$this->update($idVisita, [
            'stato' => 'ANNULLATA',
            'data_modifica' => date('Y-m-d H:i:s'),
        ]);
    }

    private function normalizeDateValue(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $dt = \DateTime::createFromFormat('Y-m-d', $value);
        return ($dt instanceof \DateTime && $dt->format('Y-m-d') === $value) ? $value : null;
    }
}
