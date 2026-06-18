<?php

namespace App\Controllers\Prenotazioni;

use App\Controllers\BaseController;
use App\Models\PrenotazioniMmgModel;
use App\Models\MenuPrenotazioniModel;
use App\Models\PrenotazioniDataModel;

class MedicoFamigliaController extends BaseController
{
    private function requireLogin()
    {
        $utente = session()->get('utente_sess');
        if (!$utente) {
            return redirect()->to(base_url('login'));
        }
        return $utente;
    }

    private function getMenuSidebar(): array
    {
        $result = session()->get('menuData');
        return $result['result'] ?? [];
    }

    private function getMenuPrenotazioni(): array
    {
        $m = new MenuPrenotazioniModel();
        return $m->getMenuAttivo();
    }

    public function index()
    {
        $utente = $this->requireLogin();
        if (!is_object($utente)) return $utente; // redirect
            $this->response->setHeader('Content-Type', 'text/html; charset=UTF-8');
        return view('prenotazioni_mmg/index', [
            'menu_items'         => $this->getMenuSidebar(),
            'menu_prenotazioni'  => $this->getMenuPrenotazioni(),
        ]);
    }

    public function nuova()
    {
        $utente = $this->requireLogin();
        if (!is_object($utente)) return $utente;

        $mmg         = new PrenotazioniMmgModel();
        $bookingData = new PrenotazioniDataModel();

        // 1) identità paziente (CF decriptato)
        $idUser = (int)($utente->id_user ?? 0);
        if ($idUser <= 0) $idUser = (int)(session()->get('userId') ?? 0);

        $patient = $mmg->getPatientIdentityFromSession();
        $codFis  = $patient['cod_fisc'] ?? '';

        // 2) paziente su archivio prenotazioni
        $idPaz = $codFis ? $bookingData->getPazienteIdByCodFis($codFis) : 0;

        // 3) se ha già prenotazione futura -> blocco nuova
        $existing = null;
        if ($idPaz > 0) {
            $existing = $bookingData->getExistingFutureBooking($idPaz);
        }

        // 4) medico assegnato -> username -> id_medico prenotazioni
        $docUsername = '';
        if ($idUser > 0) {
            $docUsername = $mmg->getAssignedDoctorUsernameByPatientUserId($idUser);
        }

        $idMedico = $docUsername ? $bookingData->getDoctorIdByUsername($docUsername) : 0;

        // 5) ricerca slot liberi
        $from = $this->request->getGet('from');

// se non arriva, default: oggi (solo data)
if (!$from) {
    $from = date('Y-m-d'); // input type="date"
}

// forzo sempre le 00:00:00 per la query
$fromDb = $from . ' 00:00:00';

        $slots = [];
        if (!$existing && $idMedico > 0) {
            // $from è 'Y-m-d' dal form
            $slots = $bookingData->getFirstAvailableSlotsAuto($idMedico, 10, 120, true, $from);
        }

        return view('prenotazioni_mmg/nuova', [
            'menu_items'         => $this->getMenuSidebar(),
            'menu_prenotazioni'  => $this->getMenuPrenotazioni(),

            'from'        => $from,
            'existing'    => $existing,
            'doctor_user' => $docUsername,
            'id_medico'   => $idMedico,
            'slots'       => $slots,
        ]);
    }

    public function gestisci()
    {
        log_message('error', '[MMG::gestisci] START');

        $utente = $this->requireLogin();
        if (!is_object($utente)) return $utente;

        $menu_items = $this->getMenuSidebar();

        // ===== DEBUG SESSION UTENTE =====
        log_message('error', '[MMG::gestisci] utente_sess=' . print_r($utente, true));

        // ===== DATI PAZIENTE (da sessione) =====
        $codFis  = strtoupper(trim((string)($utente->codice_fiscale ?? '')));
        $nome    = trim((string)($utente->nome ?? ''));
        $cognome = trim((string)($utente->cognome ?? ''));
        $cell    = trim((string)($utente->cellulare ?? ''));

        $doctorUserId = (int)($utente->id_doctor ?? 0);

        log_message('error', "[MMG::gestisci] patient(session) codFis={$codFis} nome={$nome} cognome={$cognome} cell={$cell} doctorUserId={$doctorUserId}");

        $bookingData = new PrenotazioniDataModel();

        // ===== 1) archivio prenotazioni: id_paziente per CF =====
        $idPaz = 0;
        if ($codFis !== '') {
            try {
                log_message('error', "[MMG::gestisci] Try getPazienteIdByCodFis({$codFis})");
                $idPaz = (int)$bookingData->getPazienteIdByCodFis($codFis);
                log_message('error', "[MMG::gestisci] getPazienteIdByCodFis RESULT idPaz={$idPaz}");
            } catch (\Throwable $e) {
                log_message('error', '[MMG::gestisci] ERROR getPazienteIdByCodFis EX=' . $e->getMessage());
            }
        }

        // ===== 2) fallback: triade + id_dot prenotazioni =====
        if ($idPaz <= 0) {
            $idDotPrenotazioni = 0;

            try {
                log_message('error', "[MMG::gestisci] Resolve booking id_dot from doctorUserId={$doctorUserId}");
                $idDotPrenotazioni = (int)$bookingData->resolveDoctorIdFromMainDoctorUserId($doctorUserId);
                log_message('error', "[MMG::gestisci] resolveDoctorIdFromMainDoctorUserId RESULT idDotPrenotazioni={$idDotPrenotazioni}");
            } catch (\Throwable $e) {
                log_message('error', '[MMG::gestisci] ERROR resolveDoctorIdFromMainDoctorUserId EX=' . $e->getMessage());
            }

            if ($idDotPrenotazioni > 0 && $nome !== '' && $cognome !== '' && $cell !== '') {
                try {
                    log_message('error', "[MMG::gestisci] Fallback triade+idDotPrenotazioni nome={$nome} cognome={$cognome} cell={$cell} idDotPrenotazioni={$idDotPrenotazioni}");
                    $idPaz = (int)$bookingData->getPazienteIdByTriadeAndDot($nome, $cognome, $cell, $idDotPrenotazioni);
                    log_message('error', "[MMG::gestisci] Fallback RESULT idPaz={$idPaz}");
                } catch (\Throwable $e) {
                    log_message('error', '[MMG::gestisci] ERROR getPazienteIdByTriadeAndDot EX=' . $e->getMessage());
                }
            }
        }

        // ===== 3) se ancora 0 -> niente prenotazioni =====
        if ($idPaz <= 0) {
            log_message('error', '[MMG::gestisci] idPaz=0 -> existing NULL');
            return view('prenotazioni_mmg/gestisci', [
                'menu_items'        => $menu_items,
                'menu_prenotazioni' => $this->getMenuPrenotazioni(),
                'existing'          => null,
            ]);
        }

        // ===== 4) prenotazione futura =====
        try {
            log_message('error', "[MMG::gestisci] Calling getExistingFutureBooking(idPaz={$idPaz})");
            $existing = $bookingData->getExistingFutureBooking($idPaz);
            log_message('error', "[MMG::gestisci] getExistingFutureBooking -> " . (empty($existing) ? 'EMPTY' : 'FOUND'));
        } catch (\Throwable $e) {
            log_message('error', '[MMG::gestisci] ERROR getExistingFutureBooking EX=' . $e->getMessage());
            $existing = null;
        }

        log_message('error', '[MMG::gestisci] END');

        return view('prenotazioni_mmg/gestisci', [
            'menu_items'        => $menu_items,
            'menu_prenotazioni' => $this->getMenuPrenotazioni(),
            'existing'          => $existing,
        ]);
    }

    public function prenota()
    {
        // ✅ QUI NON CAMBIO NULLA della tua logica,
        // aggiungo solo lo scheletro così com’è già.
        // (È identica alla tua, la lascio intatta.)

        log_message('error', '[MMG::prenota] START');

        $utente = $this->requireLogin();
        if (!is_object($utente)) return $utente;

        $idMedico = (int)($this->request->getPost('id_medico') ?? 0);
        $slotIni  = trim((string)($this->request->getPost('slot') ?? ''));
        $now = new \DateTime('now', new \DateTimeZone('Europe/Rome'));
        $giornoNow = $now->format('d/m/Y');
        $oraNow    = $now->format('H:i');
      $notaAuto = "App. tramite App alle ore {$oraNow} del {$giornoNow}";
            $noteUser = trim((string)($this->request->getPost('note') ?? ''));

            $parts = [$notaAuto];
            if ($noteUser !== '') $parts[] = $noteUser;

            $note = implode(' - ', $parts);

        log_message('error', "[MMG::prenota] INPUT idMedico={$idMedico} slotIni={$slotIni} noteLen=" . strlen($note));

        if ($idMedico <= 0 || $slotIni === '') {
            log_message('error', '[MMG::prenota] VALIDATION FAIL -> missing idMedico/slotIni');
            return redirect()->back()->with('message_error', 'Dati prenotazione mancanti.');
        }

        $mmg         = new PrenotazioniMmgModel();
        $bookingData = new PrenotazioniDataModel();

        try {
            log_message('error', '[MMG::prenota] Calling getPatientFullIdentityFromSession()');
            $p = $mmg->getPatientFullIdentityFromSession();
        } catch (\Throwable $e) {
            return redirect()->back()->with('message_error', 'Impossibile recuperare dati paziente: ' . $e->getMessage());
        }

        $codFis  = strtoupper(trim((string)($p['cod_fisc'] ?? '')));
        $nome    = trim((string)($p['nome'] ?? ''));
        $cognome = trim((string)($p['cognome'] ?? ''));
        $cell    = trim((string)($p['cellulare'] ?? ''));

        if ($codFis === '') {
            return redirect()->back()->with('message_error', 'Codice fiscale non disponibile.');
        }

        try {
            $idPaz = (int)$bookingData->getOrCreatePazienteId($codFis, $nome, $cognome, $cell, $idMedico);
        } catch (\Throwable $e) {
            return redirect()->back()->with('message_error', 'Errore nel recupero/creazione paziente prenotazioni: ' . $e->getMessage());
        }

        if ($idPaz <= 0) {
            return redirect()->back()->with('message_error', 'Impossibile creare o trovare il paziente in archivio prenotazioni (far05).');
        }

        try {
            $existing = $bookingData->getExistingFutureBooking($idPaz);
        } catch (\Throwable $e) {
            return redirect()->back()->with('message_error', 'Errore controllo prenotazione esistente: ' . $e->getMessage());
        }

        if (!empty($existing)) {
            return redirect()->to(site_url('prenotazioni/mmg/gestisci'))
                ->with('message_error', 'Hai già una prenotazione attiva. Cancella prima quella esistente.');
        }

        try {
            $res = $bookingData->bookSlot($idMedico, $idPaz, $slotIni, $note, null);
        } catch (\Throwable $e) {
            return redirect()->back()->with('message_error', 'Errore prenotazione: ' . $e->getMessage());
        }

        if (empty($res['ok'])) {
            $err = $res['err'] ?? 'errore';
            $msg = ($err === 'slot_occupato')
                ? 'Lo slot è stato appena prenotato da un altro utente. Seleziona un altro orario.'
                : 'Errore prenotazione: ' . $err;

            return redirect()->back()->with('message_error', $msg);
        }

        $idPren = (int)($res['id_prenotazione'] ?? 0);
        $idApp  = (int)($res['id_appuntamento'] ?? 0);

        if ($idPren > 0) session()->set('mmg_last_id_prenotazione', $idPren);
        if ($idApp > 0)  session()->set('mmg_last_id_appuntamento', $idApp);
        session()->set('mmg_last_id_paziente', $idPaz);

        return redirect()->to(site_url('prenotazioni/mmg/gestisci'))
            ->with('message_success', 'Prenotazione confermata: ' . ($res['data_ora_ini'] ?? $slotIni));
    }

    public function cancella()
    {
        log_message('error', '[MMG::cancella] START');

        $utente = $this->requireLogin();
        if (!is_object($utente)) return $utente;

        $idPren = (int)($this->request->getPost('id_prenotazione') ?? 0);
        log_message('error', "[MMG::cancella] INPUT id_prenotazione={$idPren}");

        if ($idPren <= 0) {
            log_message('error', '[MMG::cancella] FAIL id_prenotazione missing');
            return redirect()->to(site_url('prenotazioni/mmg/gestisci'))
                ->with('message_error', 'ID prenotazione mancante.');
        }

        $bookingData = new PrenotazioniDataModel();

        $res = $bookingData->deleteBookingByPrenotazioneId($idPren);

        log_message('error', '[MMG::cancella] RESULT ' . json_encode($res));

        if (empty($res['ok'])) {
            return redirect()->to(site_url('prenotazioni/mmg/gestisci'))
                ->with('message_error', $res['msg'] ?? 'Errore durante la cancellazione.');
        }

        return redirect()->to(site_url('prenotazioni/mmg/gestisci'))
            ->with('message_success', 'Prenotazione cancellata correttamente.');
    }
}
