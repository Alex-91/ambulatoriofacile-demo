<?php

namespace App\Controllers\Posta;

use App\Controllers\BaseController;
use App\Libraries\DatabaseConfig;
use App\Services\MessageService;
use CodeIgniter\HTTP\ResponseInterface;

class InvioController extends BaseController
{
    protected MessageService $svc;

    public function __construct()
    {
        $db = \Config\Database::connect();
        $dbCfg = new DatabaseConfig();
        $this->svc = new MessageService($db, $dbCfg);
    }

    /**
     * Invia un nuovo messaggio o una risposta.
     *
     * Campi attesi (POST):
     * - subject (string, opzionale)
     * - message_text (string, HTML dal WYSIHTML5)
     * - draft (0|1)
     *
     * Per PERSONALE / DOTTORI:
     * - string_dest (",0" | ",10540" | ",12,45"...) SOLO per personale/dottori
     * - richiesta (0|1|2) 1=segreteria, 2=infermiera (se lo usi lato backend)
     *
     * Per PAZIENTI:
     * - id_funzione (int) dalla tabella dap13_function_select:
     *     1 = richiesta per segreteria  → dest='S', seg_flag=1
     *     2 = richiesta per infermiera  → dest='I', inf_flag=1
     *     3 = invio standard al dottore (dest/flag standard)
     *
     * Comuni:
     * - version ("desktop"|"mobile") per HTML risposta
     * - count_div (int) per layout alternato (desktop)
     * - id_message (string) vuoto = nuovo, valorizzato = reply
     */
    public function invia(): ResponseInterface
{
    // Utente di sessione
    $utente = session()->get('utente_sess');
    if (!$utente) {
        return $this->response->setStatusCode(401)->setJSON([
            'ok'       => false,
            'error'    => 'Non autenticato',
            'csrfName' => csrf_token(),
            'csrfHash' => csrf_hash(),
        ]);
    }

    // Tipo utente (3 = paziente)
    $tipoUser  = (int) (session()->get('tipoUser') ?? 0);
    $isPatient = ($tipoUser === 3);

    // Raccogli input grezzi
    $post = $this->request->getPost();

    try {
        $uploadedFiles = $this->request->getFileMultiple('attachment');
        if (is_array($uploadedFiles)) {
            $this->validateUploadedFilesMaxSize($uploadedFiles);
        }
    } catch (\RuntimeException $e) {
        $payload = [
            'ok'       => false,
            'error'    => $e->getMessage(),
            'csrfName' => csrf_token(),
            'csrfHash' => csrf_hash(),
        ];

        if ($this->request->isAJAX()) {
            return $this->response->setStatusCode(400)->setJSON($payload);
        }

        session()->setFlashdata('message_error', $e->getMessage());
        return redirect()->back()->withInput();
    }

    // 🔹 modalità: "new" | "reply" (default new per sicurezza)
    $mode    = (string)($post['mode'] ?? 'new');
    $isReply = ($mode === 'reply');

    // Base data comune a tutti
    $data = [
        'subject'      => (string)($post['subject']      ?? ''),
        'message_text' => (string)($post['message_text'] ?? ''),   // HTML dall’editor
        'draft'        => (int)   ($post['draft']        ?? 0),
        'richiesta'    => (int)   ($post['richiesta']    ?? 0),
        'string_dest'  => (string)($post['string_dest']  ?? ''),   // ",0" | ",10540" | ",12,45"
        'version'      => (string)($post['version']      ?? 'desktop'),
        'count_div'    => (int)   ($post['count_div']    ?? 0),
        // 🔹 come int, non string
        'id_message'   => isset($post['id_message']) ? (int)$post['id_message'] : 0,
    ];

    // Campi specifici per instradamento (nuovi)
    $data['dest']     = null;  // 'S' segreteria, 'I' infermiera, altrimenti standard
    $data['seg_flag'] = 0;
    $data['inf_flag'] = 0;

    // Se è un PAZIENTE e NON è una reply → usa id_funzione per decidere la destinazione
    if ($isPatient && !$isReply) {
        $idFunzione = (int) ($post['id_funzione'] ?? 0);

        // Per i pazienti non si usa string_dest dal form
        $data['string_dest'] = '';

        switch ($idFunzione) {
            case 1:
                // Segreteria
                $data['richiesta'] = 1;
                $data['dest']      = 'S';
                $data['seg_flag']  = 1;
                $data['inf_flag']  = 0;
                break;

            case 2:
                // Infermiera
                $data['richiesta'] = 2;
                $data['dest']      = 'I';
                $data['seg_flag']  = 0;
                $data['inf_flag']  = 1;
                break;

            case 3:
            default:
                // Invio standard al dottore principale del paziente
                $data['richiesta'] = 0;
                $data['dest']      = null;
                $data['seg_flag']  = 0;
                $data['inf_flag']  = 0;
                break;
        }
    }

    try {
        // Minimo controllo contenuto (solo se non bozza)
        if ($data['draft'] === 0 && trim(strip_tags($data['message_text'])) === '') {
            $msg = 'Il messaggio non può essere vuoto.';
            if ($this->request->isAJAX()) {
                return $this->response->setStatusCode(422)->setJSON([
                    'ok'       => false,
                    'error'    => $msg,
                    'csrfName' => csrf_token(),
                    'csrfHash' => csrf_hash(),
                ]);
            }
            session()->setFlashdata('message_error', $msg);
            return redirect()->back();
        }

        log_message('debug', $post['string_dest'] ?? '');

        // 🔹 Nuovo vs Reply deciso da $mode, NON più da id_message vuoto/pieno
        if (!$isReply) {
            // NUOVO messaggio (usa anche id_message pre-generato da compose)
            $result = $this->svc->inviaNuovo($utente, $data);
        } else {
            // REPLY → eredita il routing dal thread, id_message è l'id del thread / messaggio di riferimento
            $result = $this->svc->inviaReply($utente, $data);
        }

        // --- Risposta ---
        if ($this->request->isAJAX()) {
            return $this->response->setJSON(array_merge(
                [
                    'ok'                => true,
                    'showPatientNotice' => $isPatient,
                ],
                $result ?? [],
                [
                    'csrfName' => csrf_token(),
                    'csrfHash' => csrf_hash(),
                ]
            ));
        }

        // Fallback non-AJAX: redirect con flashdata
        session()->setFlashdata('message_sent', true);
        if ($isPatient) {
            session()->setFlashdata('message_sent_type', 'paziente_segreteria');
        } else {
            session()->setFlashdata('message_sent_type', 'standard');
        }

        // return redirect()->to(base_url('posta'));

    } catch (\Throwable $e) {
        log_message('error', 'Invio fallito: {msg}', ['msg' => $e->getMessage()]);

        if ($this->request->isAJAX()) {
            return $this->response->setStatusCode(500)->setJSON([
                'ok'       => false,
                'error'    => 'Errore durante l’invio',
                'csrfName' => csrf_token(),
                'csrfHash' => csrf_hash(),
            ]);
        }

        session()->setFlashdata('message_error', 'Errore durante l’invio');
        return redirect()->back();
    }
}


    /**
     * Sposta gli allegati da *_temp a definitivi (uso opzionale).
     * POST: id_message
     */
    public function spostaAllegati(): ResponseInterface
    {
        $utente = session()->get('utente_sess');
        if (!$utente) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok'       => false,
                'error'    => 'Non autenticato',
                'csrfName' => csrf_token(),
                'csrfHash' => csrf_hash(),
            ]);
        }

        $idMessage = (int)$this->request->getPost('id_message');
        if ($idMessage <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'ok'       => false,
                'error'    => 'id_message mancante',
                'csrfName' => csrf_token(),
                'csrfHash' => csrf_hash(),
            ]);
        }

        try {
            $moved = $this->svc->spostaAllegatiTempInDef($idMessage, null);
            return $this->response->setJSON([
                'ok'       => true,
                'moved'    => $moved,
                'csrfName' => csrf_token(),
                'csrfHash' => csrf_hash(),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Spostamento allegati fallito: {msg}', ['msg' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'ok'       => false,
                'error'    => 'Errore durante spostamento allegati',
                'csrfName' => csrf_token(),
                'csrfHash' => csrf_hash(),
            ]);
        }
    }

    
}
