<?php

namespace App\Models;

use CodeIgniter\Model;
use Exception;

class AgendaAppointmentModel extends Model
{
    protected $table = 'dap12_agenda_appuntamenti';
    protected $primaryKey = 'id_appuntamento';
    protected $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = \Config\Database::connect();
    }

    public function saveAppointment(array $data): int
    {
        $idSlot = (int)($data['id_slot'] ?? 0);
        $idDot  = (int)($data['id_dot'] ?? 0);
        $tokenLock = trim((string)($data['token_lock'] ?? ''));
        $createdBy = !empty($data['created_by']) ? (int)$data['created_by'] : 0;

        if (!$idSlot || !$idDot) {
            throw new Exception('Slot o dottore non valorizzati.');
        }

        if ($tokenLock === '') {
            throw new Exception('Lo slot non e piu disponibile. Riapri lo slot e riprova.');
        }

        (new AgendaLockModel())->cleanupExpiredLocks();

        $now = date('Y-m-d H:i:s');

        $lockBuilder = $this->db->table('dap14_agenda_lock')
            ->where('token_lock', $tokenLock)
            ->where('id_slot', $idSlot)
            ->where('stato', 'ATTIVO')
            ->where('expires_at >=', $now);

        if ($createdBy > 0) {
            $lockBuilder->where('id_ope', $createdBy);
        }

        $lock = $lockBuilder->get()->getRowArray();

        if (!$lock) {
            throw new Exception('Lo slot non e piu disponibile. Riapri lo slot e riprova.');
        }

        $slot = $this->db->table('dap11_agenda_slot')
            ->where('id_slot', $idSlot)
            ->get()
            ->getRowArray();

        if (!$slot) {
            throw new Exception('Slot non trovato.');
        }

        if (($slot['stato'] ?? '') === 'PRENOTATO') {
            throw new Exception('Lo slot e gia prenotato.');
        }

        if (($slot['stato'] ?? '') === 'CHIUSO') {
            throw new Exception('La giornata risulta bloccata.');
        }

        $hasAppointment = $this->db->table('dap12_agenda_appuntamenti')
            ->select('id_appuntamento')
            ->where('id_slot', $idSlot)
            ->where('stato <>', 'ANNULLATO')
            ->get(1)
            ->getRowArray();

        if ($hasAppointment) {
            throw new Exception('Lo slot e gia prenotato.');
        }

        $insert = [
            'id_slot'          => $idSlot,
            'id_dot'           => $idDot,
            'id_paziente'      => !empty($data['id_paziente']) ? (int)$data['id_paziente'] : null,
            'cognome'          => trim((string)($data['cognome'] ?? '')),
            'nome'             => trim((string)($data['nome'] ?? '')),
            'telefono'         => trim((string)($data['telefono'] ?? '')),
            'cellulare'        => trim((string)($data['cellulare'] ?? '')),
            'email'            => trim((string)($data['email'] ?? '')),
            'note'             => trim((string)($data['note'] ?? '')),
            'motivo_visita'    => trim((string)($data['motivo_visita'] ?? '')),
            'indirizzo_visita' => trim((string)($data['indirizzo_visita'] ?? '')),
            'comune_visita'    => trim((string)($data['comune_visita'] ?? '')),
            'stato'            => 'CONFERMATO',
            'created_by'       => $createdBy > 0 ? $createdBy : null,
            'created_at'       => $now,
        ];

        if ($this->db->fieldExists('id_client', 'dap12_agenda_appuntamenti')) {
            $insert['id_client'] = !empty($data['id_client'])
                ? (int)$data['id_client']
                : (!empty($data['id_paziente']) ? (int)$data['id_paziente'] : null);
        }

        if ($insert['cognome'] === '' || $insert['nome'] === '') {
            throw new Exception('Nome e cognome sono obbligatori.');
        }

        $this->db->transStart();

        $this->db->table('dap12_agenda_appuntamenti')->insert($insert);
        $id = (int)$this->db->insertID();

        $this->db->table('dap11_agenda_slot')
            ->where('id_slot', $idSlot)
            ->update([
                'stato'      => 'PRENOTATO',
                'updated_at' => $now,
            ]);

        $this->db->table('dap14_agenda_lock')
            ->where('token_lock', $tokenLock)
            ->where('stato', 'ATTIVO')
            ->update([
                'stato' => 'RILASCIATO'
            ]);

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new Exception('Errore durante il salvataggio della prenotazione.');
        }

        return $id;
    }

    public function updateAppointment(array $data): bool
    {
        $idAppuntamento = (int)($data['id_appuntamento'] ?? 0);

        if (!$idAppuntamento) {
            throw new Exception('ID appuntamento mancante.');
        }

        $update = [
            'id_paziente'      => !empty($data['id_paziente']) ? (int)$data['id_paziente'] : null,
            'cognome'          => trim((string)($data['cognome'] ?? '')),
            'nome'             => trim((string)($data['nome'] ?? '')),
            'telefono'         => trim((string)($data['telefono'] ?? '')),
            'cellulare'        => trim((string)($data['cellulare'] ?? '')),
            'email'            => trim((string)($data['email'] ?? '')),
            'note'             => trim((string)($data['note'] ?? '')),
            'motivo_visita'    => trim((string)($data['motivo_visita'] ?? '')),
            'indirizzo_visita' => trim((string)($data['indirizzo_visita'] ?? '')),
            'comune_visita'    => trim((string)($data['comune_visita'] ?? '')),
        ];

        if ($this->db->fieldExists('id_client', 'dap12_agenda_appuntamenti')) {
            $update['id_client'] = !empty($data['id_client'])
                ? (int)$data['id_client']
                : (!empty($data['id_paziente']) ? (int)$data['id_paziente'] : null);
        }

        return (bool)$this->db->table('dap12_agenda_appuntamenti')
            ->where('id_appuntamento', $idAppuntamento)
            ->update($update);
    }

    public function deleteAppointment(int $idAppuntamento, int $userId): bool
    {
        $row = $this->db->table('dap12_agenda_appuntamenti')
            ->where('id_appuntamento', $idAppuntamento)
            ->get()
            ->getRowArray();

        if (!$row) {
            throw new Exception('Appuntamento non trovato.');
        }

        $this->db->transStart();

        $this->db->table('dap12_agenda_appuntamenti')
            ->where('id_appuntamento', $idAppuntamento)
            ->update([
                'stato'      => 'ANNULLATO'
            ]);

        $this->db->table('dap11_agenda_slot')
            ->where('id_slot', (int)$row['id_slot'])
            ->update([
                'stato'      => 'LIBERO',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $this->db->transComplete();

        return (bool)$this->db->transStatus();
    }
}
