<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Libraries\Crypto_helper;
use App\Services\SessionNavigationService;

class SostituzioniController extends BaseController
{
    protected $db;
    protected $crypto;
    private SessionNavigationService $navigation;

    public function __construct()
    {
        $this->db     = \Config\Database::connect();
        $this->crypto = new Crypto_helper();
        $this->navigation = new SessionNavigationService();
    }

    private function resetNavigationState(): void
    {
        session()->remove([
            'forceOnlyPosta',
            'header_nav_items',
            'header_menu_items',
            'menuData',
            'schede_access_map',
            'schede_data',
            'nav_refresh_meta',
            'selectedDoctorId',
            'actorId',
        ]);
    }

    /**
     * Mostra la schermata scelta sostituzione (dopo OTP).
     * Se non ci sono sostituzioni attive -> rimanda alla home (schede).
     */
    public function index()
    {
        // Deve essere passato OTP
        if (!session()->get('isLoggedInConfirmed')) {
            return redirect()->to(base_url('login'));
        }

        // Solo medico/personale (tipoUser=2)
        if ((int)session()->get('tipoUser') !== 2) {
            return redirect()->to(base_url('/'));
        }

        $me = session()->get('utente_sess');
        if (!$me || !isset($me->id_personale)) {
            return redirect()->to(base_url('/'));
        }

        $idPersonaleMe = (int)$me->id_personale;
        if ($idPersonaleMe <= 0) {
            return redirect()->to(base_url('/'));
        }

        // Lista sostituzioni attive OGGI
        $sql = "SELECT DISTINCT
                    s.id_personale_da_sostituire AS id_personale,
                    " . $this->crypto->decrypt("p.nome") . " ,
                    " . $this->crypto->decrypt("p.cognome") . " ,
                    " . $this->crypto->decrypt("p.qualifica") . " ,
                    CONCAT(
                      " . $this->crypto->decrypt_concat("p.qualifica") . ", ' ',
                      " . $this->crypto->decrypt_concat("p.cognome") . ", ' ',
                      " . $this->crypto->decrypt_concat("p.nome") . "
                    ) AS nome_completo
                FROM dap18_sostituto s
                JOIN dap03_personale p ON p.id_personale = s.id_personale_da_sostituire
                WHERE s.id_personale = ?
                  AND CURDATE() BETWEEN s.data_inizio AND s.data_fine
                ORDER BY nome_completo";

        $opts = $this->db->query($sql, [$idPersonaleMe])->getResultArray();

        // Nessuna sostituzione attiva -> vai alle schede
        if (empty($opts)) {
            return redirect()->to(base_url('/'));
        }

        // Salvo in sessione la lista consentita (servirà per validare la scelta POST)
        session()->set('sost_opts', $opts);

        // ✅ View già creata prima
        return view('login/sceltaSost', [
            'me'   => $me,
            'opts' => $opts,  // per la select
        ]);
    }

    /**
     * Riceve la scelta:
     * - mode=self -> vai alle schede
     * - mode=sost + id_personale -> "login come sostituito" (riscrive utente_sess) e vai alle schede abilitate
     */
    public function choose()
    {
        if (!session()->get('isLoggedInConfirmed')) {
            return $this->response->setJSON(['ok' => false, 'err' => 'not_confirmed'])->setStatusCode(401);
        }

        if ((int)session()->get('tipoUser') !== 2) {
            return $this->response->setJSON(['ok' => false, 'err' => 'not_doctor'])->setStatusCode(400);
        }

        $me = session()->get('utente_sess');
        if (!$me || !isset($me->id_personale)) {
            return $this->response->setJSON(['ok' => false, 'err' => 'no_session_user'])->setStatusCode(400);
        }

        $json = $this->request->getJSON(true) ?? [];
        $mode = (string)($json['mode'] ?? '');

        // pulisco eventuali flag vecchi
        session()->remove('acting_as_sostituto');
        $this->resetNavigationState();
        $this->navigation->invalidateCurrentSession();

        // ====== ENTRA COME ME STESSO -> schermata schede ======
        if ($mode === 'self') {
            session()->remove('sost_opts');
            session()->remove('original_user_sess'); // opzionale
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            return $this->response->setJSON(['ok' => true, 'redirectUrl' => '']);
        }

        // ====== ENTRA COME SOSTITUTO ======
        if ($mode !== 'sost') {
            return $this->response->setJSON(['ok' => false, 'err' => 'bad_mode'])->setStatusCode(400);
        }

        $idSel = (int)($json['id_personale'] ?? 0);
        if ($idSel <= 0) {
            return $this->response->setJSON(['ok' => false, 'err' => 'missing_id'])->setStatusCode(400);
        }

        // Valido che l'id selezionato sia tra quelli consentiti
        $opts = session()->get('sost_opts') ?? [];
        $allowedIds = array_map(fn($r) => (int)($r['id_personale'] ?? 0), $opts);

        if (!in_array($idSel, $allowedIds, true)) {
            return $this->response->setJSON(['ok' => false, 'err' => 'bad_selection'])->setStatusCode(400);
        }

        // Carico dati del medico sostituito e RISCRIVO utente_sess (come un login)
        $sqlDoc = "SELECT
                    a.tipo,
                    a.id_user,
                    a.id_personale,
                    " . $this->crypto->decrypt("a.nome") . " ,
                    " . $this->crypto->decrypt("a.cognome") . " ,
                    " . $this->crypto->decrypt("a.cellulare") . " ,
                    " . $this->crypto->decrypt("a.email") . " ,
                    " . $this->crypto->decrypt("a.qualifica") . " ,
                    CONCAT(
                      " . $this->crypto->decrypt_concat("a.qualifica") . ", ' ',
                      " . $this->crypto->decrypt_concat("a.cognome") . ", ' ',
                      " . $this->crypto->decrypt_concat("a.nome") . "
                    ) AS nome_completo
                   FROM dap03_personale a
                   WHERE a.id_personale = ?
                   LIMIT 1";

        $doc = $this->db->query($sqlDoc, [$idSel])->getRowArray();

        if (!$doc) {
            return $this->response->setJSON(['ok' => false, 'err' => 'doctor_not_found'])->setStatusCode(400);
        }

        // (opzionale) salvo l'utente originale per ripristino/debug
        session()->set('original_user_sess', $me);

        // Ricostruisco session user come il medico sostituito
        $obj = new \stdClass();
        $obj->id_user       = (int)($doc['id_user'] ?? 0);
        $obj->id_personale  = (int)$doc['id_personale'];
        $obj->id_utente     = (int)$doc['id_personale'];

        $obj->nome          = (string)($doc['nome'] ?? '');
        $obj->cognome       = (string)($doc['cognome'] ?? '');
        $obj->cellulare     = (string)($doc['cellulare'] ?? '');
        $obj->email         = (string)($doc['email'] ?? '');
        $obj->qualifica     = (string)($doc['qualifica'] ?? '');
        $obj->nome_completo = (string)($doc['nome_completo'] ?? '');

        // coerente col tuo sistema
        $obj->tipo      = 2;
        $obj->tipo_pers = (int)($doc['tipo'] ?? 1);
        $obj->tipo_stringa = match ((int)($doc['tipo'] ?? 1)) {
            1 => 'P',
            2 => 'I',
            3 => 'S',
            default => '',
        };
        $obj->da_dottore    = 0;
        $obj->tabella       = "dap10_message";
        $obj->tabella_reply = "dap10_message_reply";

        session()->set([
            'acting_as_sostituto' => 1,
            'utente_sess'         => $obj,
            'userId'              => $obj->id_user,
            'id_user'             => $obj->id_user,
            'actorId'             => $obj->id_personale,
            'tipoUser'            => 2,
            'nome_visualizzato'   => $obj->nome_completo,
            'cellulare'           => $obj->cellulare,
        ]);

        session()->remove('sost_opts');
        $this->navigation->refreshCurrentSession(true);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $this->response->setJSON(['ok' => true, 'redirectUrl' => '']);
    }
}
