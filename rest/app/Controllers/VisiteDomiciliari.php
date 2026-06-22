<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\AgendaModel;
use App\Models\VisiteDomiciliariModel;
use App\Models\PazientiModel;
use Exception;

class VisiteDomiciliari extends BaseController
{
    protected $agendaModel;
    protected $visiteModel;
    protected $pazientiModel;

    public function __construct()
    {
        $this->agendaModel = new AgendaModel();
        $this->visiteModel = new VisiteDomiciliariModel();
        $this->pazientiModel = new PazientiModel();
    }

    public function lista($idDot)
    {
        $giornoVisita = $this->resolveAgendaDateFromPayload($this->request->getGet(), date('Y-m-d'));
        if ($giornoVisita === '') {
            $giornoVisita = date('Y-m-d');
        }

        return $this->response->setJSON([
            'status' => true,
            'rows'   => $this->visiteModel->getListaByDottore((int)$idDot, $giornoVisita)
        ]);
    }

    public function dettaglio($idVisita)
    {
        $row = $this->visiteModel->getDettaglio((int)$idVisita);

        return $this->response->setJSON([
            'status' => $row ? true : false,
            'row'    => $row
        ]);
    }

    public function salva()
    {
        try {
            $payload = $this->request->getPost();
            $idDot = (int)($payload['id_dot'] ?? 0);

            if ($idDot <= 0) {
                throw new Exception('Dottore non valido.');
            }

            $this->assertDomiciliareActionAllowed(
                $idDot,
                $this->resolveAgendaDateFromPayload($payload)
            );

            $idPaziente = $this->pazientiModel->savePatientAndLink([
                'id_paziente' => $payload['id_paziente'] ?? null,
                'cognome'     => $payload['cognome'] ?? '',
                'nome'        => $payload['nome'] ?? '',
                'telefono'    => $payload['telefono'] ?? '',
                'cellulare'   => $payload['cellulare'] ?? '',
                'indirizzo'   => $payload['indirizzo'] ?? '',
                'citta'       => $payload['citta'] ?? '',
            ], $idDot, $this->getCurrentUserId());

            $payload['id_paziente'] = $idPaziente;

            $idVisita = $this->visiteModel->salvaVisita($payload);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Visita domiciliare salvata correttamente.',
                'id_visita' => $idVisita
            ]);
        } catch (Exception $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function aggiorna()
    {
        try {
            $payload = $this->request->getPost();
            $idDot = (int)($payload['id_dot'] ?? 0);
            if ($idDot <= 0) {
                throw new Exception('Dottore non valido.');
            }

            $this->assertDomiciliareActionAllowed(
                $idDot,
                $this->resolveAgendaDateFromPayload($payload)
            );

            $idPaziente = $this->pazientiModel->savePatientAndLink([
                'id_paziente' => $payload['id_paziente'] ?? null,
                'cognome'     => $payload['cognome'] ?? '',
                'nome'        => $payload['nome'] ?? '',
                'telefono'    => $payload['telefono'] ?? '',
                'cellulare'   => $payload['cellulare'] ?? '',
                'indirizzo'   => $payload['indirizzo'] ?? '',
                'citta'       => $payload['citta'] ?? '',
            ], $idDot, $this->getCurrentUserId());

            $payload['id_paziente'] = $idPaziente;

            $this->visiteModel->aggiornaVisita($payload);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Visita domiciliare aggiornata correttamente.'
            ]);
        } catch     (Exception $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function elimina()
    {
        try {
            $idVisita = (int)$this->request->getPost('id_visita');
            if ($idVisita <= 0) {
                throw new Exception('Visita non valida.');
            }

            $row = $this->visiteModel->getDettaglio($idVisita);
            if (!$row) {
                throw new Exception('Visita non valida.');
            }

            $idDot = (int)($row['id_dot'] ?? 0);
            if ($idDot <= 0) {
                throw new Exception('Dottore non valido.');
            }

            $this->assertDomiciliareActionAllowed(
                $idDot,
                $this->resolveAgendaDateFromPayload($this->request->getPost(), (string)($row['giorno_visita'] ?? ''))
            );

            $this->visiteModel->eliminaVisita($idVisita);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Visita domiciliare eliminata.'
            ]);
        } catch (Exception $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function resolveAgendaDateFromPayload(array $payload, string $fallback = ''): string
    {
        $value = trim((string)($payload['data_agenda'] ?? ($payload['giorno_visita'] ?? $fallback)));
        if ($value === '') {
            return '';
        }

        $dt = \DateTime::createFromFormat('Y-m-d', $value);
        return ($dt instanceof \DateTime && $dt->format('Y-m-d') === $value) ? $value : '';
    }

    private function assertDomiciliareActionAllowed(int $idDot, string $agendaData): void
    {
        if ($agendaData === '') {
            return;
        }

        if ($this->agendaModel->isGiornoBloccato($idDot, $agendaData)) {
            throw new Exception('La giornata agenda e bloccata: anche le domiciliari non sono modificabili.');
        }

        // Domiciliary actions are blocked only by the dedicated domiciliary day lock.
        if ($this->agendaModel->isDomiciliareGiornoBloccato($idDot, $agendaData)) {
            throw new Exception('Il giorno selezionato e bloccato per le domiciliari.');
        }
    }
}
