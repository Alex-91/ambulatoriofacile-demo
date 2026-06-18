<?php

namespace App\Controllers\Prenotazioni;

use App\Controllers\BaseController;
use App\Models\MenuPrenotazioniModel;
use App\Models\PrenotazioniDataModel;
use App\Models\PrenotazioniSpecialistiModel;
use App\Models\PrenotazioniMmgModel; // per recuperare CF/nome/cognome/cell se già lo usi così

class MedicoSpecialistaController extends BaseController
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
        if (!is_object($utente)) return $utente;

        $this->response->setHeader('Content-Type', 'text/html; charset=UTF-8');

        return view('prenotazioni_spec/index', [
            'menu_items'        => $this->getMenuSidebar(),
            'menu_prenotazioni' => $this->getMenuPrenotazioni(),
        ]);
    }

    /**
     * STEP 1: scelta specializzazione
     */
    public function nuova()
    {
        $utente = $this->requireLogin();
        if (!is_object($utente)) return $utente;

        $specM = new PrenotazioniSpecialistiModel();
        $specs = $specM->getSpecializzazioni();

        $this->response->setHeader('Content-Type', 'text/html; charset=UTF-8');

        return view('prenotazioni_spec/scegli_spec', [
            'menu_items'        => $this->getMenuSidebar(),
            'menu_prenotazioni' => $this->getMenuPrenotazioni(),
            'specs'             => $specs,
        ]);
    }

    /**
     * STEP 2: lista medici per specializzazione
     */
public function medici($idSpec = null)
{
    $utente = $this->requireLogin();
    if (!is_object($utente)) return $utente;

    $idSpec = (int)($idSpec ?? 0);
    if ($idSpec <= 0) {
        return redirect()->to(base_url('prenotazioni/specialisti/nuova'));
    }

    $specM  = new PrenotazioniSpecialistiModel();
    $medici = $specM->getMediciBySpec($idSpec);

    // ✅ Recupero nome specializzazione (query singola sul record)
    $specName = '';
    $specRow  = $specM->getSpecById($idSpec);
    if (is_array($specRow)) {
        // nel nuovo DB: dap41_spec ha 'titolo' e 'descr'
        $specName = trim((string)($specRow['titolo'] ?? $specRow['descr'] ?? ''));
    }

    // salvo in sessione la spec scelta (utile per tornare indietro)
    session()->set('spec_selected', $idSpec);

    $this->response->setHeader('Content-Type', 'text/html; charset=UTF-8');

    return view('prenotazioni_spec/medici', [
        'menu_items'        => $this->getMenuSidebar(),
        'menu_prenotazioni' => $this->getMenuPrenotazioni(),
        'id_spec'           => $idSpec,
        'spec_name'         => $specName,
        'medici'            => $medici,
    ]);
}


    /**
     * STEP 3: slot per un medico (id_dot)
     * Qui è come la MMG: usa PrenotazioniDataModel->getAvailableSlots(...)
     */
    public function slot($idDot = null)
    {
        $utente = $this->requireLogin();
        if (!is_object($utente)) return $utente;

        $idDot = (int)($idDot ?? 0);
        if ($idDot <= 0) {
            $idSpec = (int)(session()->get('spec_selected') ?? 0);
            return $idSpec > 0
                ? redirect()->to(base_url('prenotazioni/specialisti/medici/' . $idSpec))
                : redirect()->to(base_url('prenotazioni/specialisti/nuova'));
        }

        $bookingData = new PrenotazioniDataModel();
        $mmg      = new PrenotazioniMmgModel(); // solo per identity (CF/nome/cognome/cell), se già lo fai così

        $patient = $mmg->getPatientIdentityFromSession();
        $codFis  = $patient['cod_fisc'] ?? '';

        $idPaz = $codFis ? $bookingData->getPazienteIdByCodFis($codFis) : 0;

        // blocco se ha già prenotazione futura (se vuoi mantenerlo identico alla MMG)
        $existing = null;
        if ($idPaz > 0) {
            $existing = $bookingData->getExistingFutureBooking($idPaz);
        }

        $from = $this->request->getGet('from');
        if (!$from) $from = date('Y-m-d');

        $slots = $bookingData->getFirstAvailableSlotsAuto($idDot, 10, 364, true, $from);

        $specM = new PrenotazioniSpecialistiModel();
        $med   = $specM->getMedicoByIdDot($idDot);

        $this->response->setHeader('Content-Type', 'text/html; charset=UTF-8');

        return view('prenotazioni_spec/slot', [
            'menu_items'        => $this->getMenuSidebar(),
            'menu_prenotazioni' => $this->getMenuPrenotazioni(),
            'id_dot'            => $idDot,
            'medico'            => $med,
            'from'              => $from,
            'slots'             => $slots,
            'existing'          => $existing,
        ]);
    }

    /**
     * PRENOTA (uguale a MMG): usa PrenotazioniDataModel->bookSlot(...)
     */
    public function prenota()
    {
        log_message('error', '[SPEC::prenota] START');

        $utente = $this->requireLogin();
        if (!is_object($utente)) return $utente;

        $idDot   = (int)($this->request->getPost('id_medico') ?? 0);
        $slotIni = trim((string)($this->request->getPost('slot') ?? ''));
          $now = new \DateTime('now', new \DateTimeZone('Europe/Rome'));
          $giornoNow = $now->format('d/m/Y');
        $oraNow    = $now->format('H:i');
        $notaAuto = "App. tramite App alle ore {$oraNow} del {$giornoNow}";
            $noteUser = trim((string)($this->request->getPost('note') ?? ''));

            $parts = [$notaAuto];
            if ($noteUser !== '') $parts[] = $noteUser;

            $note = implode(' - ', $parts);

        log_message('error', "[SPEC::prenota] INPUT idDot={$idDot} slotIni={$slotIni} noteLen=" . strlen($note));

        if ($idDot <= 0 || $slotIni === '') {
            return redirect()->back()->with('message_error', 'Dati prenotazione mancanti.');
        }

        $bookingData = new PrenotazioniDataModel();
        $mmg      = new PrenotazioniMmgModel();

        $patient = $mmg->getPatientFullIdentityFromSession($idDot); // se ce l’hai già nel tuo model MMG
        $codFis  = $patient['cod_fisc'] ?? '';
        $nome    = $patient['nome'] ?? '';
        $cognome = $patient['cognome'] ?? '';
        $cell    = $patient['cellulare'] ?? '';

        if (!$codFis) {
            return redirect()->back()->with('message_error', 'Impossibile identificare il paziente.');
        }

        // id paziente su archivio prenotazioni (o creazione minima come fai già)
        $idPaz = $bookingData->getOrCreatePazienteId($codFis, $nome, $cognome, $cell, $idDot);

        if ($idPaz <= 0) {
            return redirect()->back()->with('message_error', 'Errore creazione/recupero paziente.');
        }

        $res = $bookingData->bookSlot($idDot, $idPaz, $slotIni, $note);

        if (empty($res['ok'])) {
            return redirect()->back()->with('message_error', 'Slot non disponibile o errore prenotazione.');
        }

        return redirect()->to(base_url('prenotazioni/specialisti/gestisci'))
            ->with('message_success', 'Prenotazione effettuata con successo!');
    }

    /**
     * Pagina gestione prenotazioni specialistiche (puoi copiarla 1:1 dalla MMG cambiando url)
     */
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
            return view('prenotazioni_spec/gestisci', [
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

        return view('prenotazioni_spec/gestisci', [
            'menu_items'        => $menu_items,
            'menu_prenotazioni' => $this->getMenuPrenotazioni(),
            'existing'          => $existing,
        ]);
    }

    /**
     * CANCELLA (uguale a MMG): riceve id_prenotazione hidden e fa:
     * - cerca su far08 l'id_appuntamento
     * - elimina da far06
     * - elimina da far08
     *
     * (PrenotazioniDataModel::deleteBookingByPrenotazioneId già esiste nel tuo file)
     */
    public function cancella()
    {
        $utente = $this->requireLogin();
        if (!is_object($utente)) return $utente;

        $idPren = (int)($this->request->getPost('id_prenotazione') ?? 0);
        if ($idPren <= 0) {
            return redirect()->back()->with('message_error', 'ID prenotazione mancante.');
        }

        $bookingData = new PrenotazioniDataModel();
        $res = $bookingData->deleteBookingByPrenotazioneId($idPren);

        return !empty($res['ok'])
            ? redirect()->back()->with('message_success', 'Prenotazione annullata.')
            : redirect()->back()->with('message_error', 'Errore annullamento prenotazione.');
    }
}
