<?php

namespace App\Controllers\Compose;

use App\Controllers\BaseController;
use App\Models\UsersModel;
use App\Models\ClientsModel;
use App\Models\ClientDoctorModel;
use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;
use App\Libraries\SmsSender;
use App\Models\AuthCodeModel;
use App\Models\ContatoreModel;
use App\Services\SessionNavigationService;


class ComposeController extends BaseController
{
    protected $db;
    protected $dbConfig;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->dbConfig = new DatabaseConfig();
        $this->dbConfig->setEncryptionConfig($this->db);
    }

 public function index()
{
    log_message('info', 'Accesso alla funzione index (compose)');

    // Verifica se l'utente è un paziente (tipoUser === 3)
    $tipoUser  = (int) (session()->get('tipoUser') ?? 0);
    $isPatient = ($tipoUser === 3);
    $funzioni  = [];

    if ($isPatient) {
        $builder = $this->db->table('dap13_function_select');
        $builder->select('id_funzione, nome');
        $builder->orderBy('id_funzione', 'ASC');
        $funzioni = $builder->get()->getResultArray();
    }

    // ✅ Leggo parametri GET (apertura bozza)
    $mode      = (string)($this->request->getGet('mode') ?? '');
    $idMessage = (int)($this->request->getGet('id_message') ?? 0);

    // dati default per la view
    $viewData = [
        'functionOptions' => $funzioni,
        'isPatient'       => $isPatient,

        // per la view/JS
        'draftId'   => 0,
        'draftData' => null,
    ];

    // ✅ Se è una bozza, carico e passo alla view
    if ($mode === 'draft' && $idMessage > 0) {

        // risolvo mittente come fai negli altri controller
    $utente = session()->get('utente_sess');
        if (!$utente) return redirect()->to('/login');

        if (($utente->tipo ?? null) == 3) { $mitt='C'; $id_mitt=(int)($utente->id_client ?? 0); }
        else if (($utente->tipo ?? null) == 2) { $mitt='P'; $id_mitt=(int)($utente->id_personale ?? 0); }
        else { $mitt='P'; $id_mitt=(int)($utente->id_user ?? 0); }

        $m = new \App\Models\DraftModel();

        // ⚠️ qui deve tornare testo/oggetto DECRIPTATI (quindi saveDraft deve cifrare!)
        $d = $m->getDraft($idMessage, $id_mitt, $mitt);
        
        if (!$d) {
            // bozza non trovata o non è tua
            return redirect()->to(site_url('bozze'))->with('error', 'Bozza non trovata');
        }

        // ✅ passo l'id reale bozza alla view (serve per attachments temp e save)
        $viewData['id_message'] = (int)$d['id_message'];
        $viewData['draftId']    = (int)$d['id_message'];

        // meta: tu la stai salvando in inoltrato (string JSON)
        $metaJson = (string)($d['inoltrato'] ?? '');
        $meta = null;
        if ($metaJson !== '') {
            $tmp = json_decode($metaJson, true);
            if (is_array($tmp)) $meta = $tmp;
        }

        $viewData['draftData'] = [
            'oggetto' => (string)($d['oggetto'] ?? ''),
            'testo'   => (string)($d['testo'] ?? ''),
            'meta'    => $meta,         // destinatari / function_select ecc.
            'metaRaw' => $metaJson,     // in caso ti serva così com’è
        ];

        log_message('debug', 'Compose/index: apertura bozza id_message={id}', ['id' => $viewData['id_message']]);

        return view('compose/compose', $viewData);
    }

    // ✅ Caso normale: nuova compose (come prima)
    $contatore  = new ContatoreModel();
    $newId      = $contatore->next('dap10_message');

    log_message('debug', 'Compose/index: pre-assegnato id_message={id} per nuova bozza', ['id' => $newId]);

    $viewData['id_message'] = $newId;

    return view('compose/compose', $viewData);
}



    public function checkOtp()
    {
        log_message('info', 'Accesso alla funzione checkOtp');
    
        $json = $this->request->getJSON();
        $authModel = new AuthCodeModel();
        $navigation = new SessionNavigationService();

        if (!$json) {
            log_message('error', 'Dati non validi ricevuti in checkOtp');
            return $this->response->setJSON(['error' => 'Dati non validi'])->setStatusCode(400);
        }
    
        $cellulare = session()->get('cellulare');
        $esito = $authModel->checkOtp($json->authCode, $cellulare);
    
        if (!$cellulare || !$esito) {
            log_message('error', 'Errore ricerca cellulare o codice OTP errato');
            return $this->response->setJSON(['error' => "Codice OTP inserito errato o scaduto"])->setStatusCode(500);
        }
    
        if (session()->get('isLoggedIn') === true) {

            session()->set('isLoggedInConfirmed', true);
            $navigation->refreshCurrentSession(true);

            log_message('info', "OTP verificato con successo per {$cellulare}");
            return $this->response->setJSON(['success' => true, 'redirectUrl' => '']);
        } else {
            log_message('info', "OTP verificato con successo per {$cellulare}");
            return $this->response->setJSON(['success' => true, 'redirectUrl' => 'cambio']);
        }
    }

    public function indexSMS()
    {
        log_message('info', 'Accesso alla funzione indexSMS');

        $cellulare = session()->get('cellulare');

        if (!$cellulare) {
            log_message('warning', 'Sessione scaduta, reindirizzamento a /reset');
            return redirect()->to('/reset')->with('error', 'Sessione scaduta, reinserire il codice fiscale.');
        }

        $otp = $this->generateRandomCode();
        session()->set('otp', $otp);
        log_message('info', "OTP generato per {$cellulare}: {$otp}");

        $this->inserisciAuthCode($cellulare, $otp);
        $this->inviaSMS($cellulare, $otp);

        return view('auth/auth', ['cellulare' => $cellulare]);
    }

    public function inserisciAuthCode($cellulare, $authCode)
    {
        log_message('info', "Inserimento codice OTP per {$cellulare}");

        $crypto_helper = new Crypto_helper();
        $sql = "INSERT INTO dap16_auth_code (cellulare, authCode, vector_id) 
                VALUES (" . $crypto_helper->encrypt($cellulare) . ",'" . $authCode . "', @init_vector)";

        $this->db->query($sql);

        $success = $this->db->affectedRows() > 0;
        if ($success) {
            log_message('info', "Codice OTP inserito correttamente per {$cellulare}");
        } else {
            log_message('error', "Errore nell'inserimento del codice OTP per {$cellulare}");
        }

        return $success;
    }

    private function inviaWA($numero, $otp)
    {
        log_message('info', "Invio OTP via WhatsApp a {$numero}");

        $smsSender = new SmsSender();
        $messaggio = "Ambulatori.Cloud - Il suo codice di accesso OTP è {$otp}. Non divulgare questo codice. Il codice rimarrà attivo solamente per 2 minuti.";
        $response = $smsSender->sendWA($numero, $messaggio);

        if ($response) {
            log_message('info', "Messaggio WhatsApp inviato con successo a {$numero}");
        } else {
            log_message('error', "Errore nell'invio del messaggio WhatsApp a {$numero}");
        }
    }

    private function inviaSMS($numero, $otp)
    {
        log_message('info', "Invio OTP via SMS a {$numero}");

        $smsSender = new SmsSender();
        $messaggio = "Ambulatori.Cloud - Il suo codice di accesso OTP è {$otp}. Non divulgare questo codice. Il codice rimarrà attivo solamente per 2 minuti.";
        $response = $smsSender->sendSMSIndex($numero, $messaggio);

        if ($response) {
            log_message('info', "SMS inviato con successo a {$numero}");
        } else {
            log_message('error', "Errore nell'invio dell'SMS a {$numero}");
        }
    }

    function generateRandomCode($length = 4)
    {
        $characters = '0123456789';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}
