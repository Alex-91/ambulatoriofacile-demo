<?php

namespace App\Controllers;

use App\Models\ClientsModel;
use App\Models\ClientDoctorModel;
use App\Models\PersonaleModel;
use App\Models\SchedeModel;
use App\Models\PushSubscriptionModel;
use App\Models\AuthCodeModel;
use App\Libraries\Crypto_helper;
use App\Libraries\SmsSender;
use App\Services\NotificationService;
use App\Services\OtpDeliveryLogService;
use App\Services\StaffLocationCatalogService;
class Profilo extends BaseController
{
    public function index()
{
    $me = session()->get('utente_sess');
    if (!$me || empty($me->id_user)) {
        return redirect()->to(base_url('/'));
    }

    $clients   = new ClientsModel();
    $personale = new PersonaleModel();
    $cd        = new ClientDoctorModel();
    $locationCatalog = new StaffLocationCatalogService();

    // =========================
    // 1) PROVO CLIENTE (PAZIENTE)
    // =========================
    $cliente = $clients->getClientDecryptedByUserId((int)$me->id_user);

    // =========================
    // 2) SE NON ÃƒË† CLIENTE, PROVO PERSONALE
    // =========================
    $personaleRow = null;
    $doctors = [];
    $selectedDoctorId = 0;
    $gruppi = [];

    if ($cliente) {
        $idClient = (int)$cliente['id_client'];

        // medico attualmente associato (solo pazienti)
        $sel = $cd->where('id_client', $idClient)->first();
        $selectedDoctorId = $sel ? (int)$sel['id_dot'] : 0;

        // lista dottori per select (solo pazienti)
        $doctors = $personale->getDoctorsListForSelect();
    } else {
        // profilo personale (segreteria/infermiere/dottore)
        $personaleRow = $personale->getPersonaleDecryptedByUserId((int)$me->id_user);
        if (!$personaleRow) {
            return redirect()->back()->with('error', 'Profilo non trovato.');
        }

        $gruppi = $locationCatalog->listSelectableLocations();
    }

    // =========================
    // DEVICE (1 solo attivo, SOLO MOBILE) - uguale a prima
    // =========================
    $userId = (int)(session()->get('userId') ?? 0);
    $pushModel = new PushSubscriptionModel();
    $activeMobile = $pushModel->getActiveByUser($userId, 'phone')[0] ?? null;
    $otpCellulare = trim((string)(
        ($cliente['cellulare'] ?? '')
        ?: ($personaleRow['cellulare'] ?? '')
        ?: (session()->get('cellulare') ?? '')
    ));

    if ($otpCellulare !== '') {
        session()->set('cellulare', $otpCellulare);
    }
    session()->set('userId', (int)$me->id_user);

    return view('profilo/index', [
        // paziente
        'cliente'          => $cliente ?: null,
        'doctors'          => $doctors,
        'selectedDoctorId' => $selectedDoctorId,
        'headerMenuItems'  => $cliente ? $this->buildHeaderMenuItemsForCurrentUser() : null,
        'disableHeaderMenuFallback' => (bool)$cliente,

        // personale
        'personale'        => $personaleRow ?: null,
        'gruppi'           => $gruppi,

        // device: la view usa $activeDevice Ã¢â€ â€™ alias senza cambiare view
        'activeMobile'     => $activeMobile ?: null,
        'hasMobile'        => !empty($activeMobile),
        'activeDevice'     => $activeMobile ?: null,

        'vapidPublicKey'   => env('VAPID_PUBLIC_KEY',''),
    ]);
}

private function buildHeaderMenuItemsForCurrentUser(): array
{
    $me = session()->get('utente_sess');

    $idUser = (int)(session()->get('id_user') ?? 0);
    if ($idUser <= 0 && is_object($me) && isset($me->id_user)) {
        $idUser = (int)$me->id_user;
    }

    if ($idUser <= 0) {
        return [];
    }

    $badgePosta = (int)(session()->get('badge_posta_unread') ?? 0);
    $badgeChat  = (int)(session()->get('badge_chat_unread') ?? 0);

    $schede = (new SchedeModel())->getSchedeForUser($idUser, $badgePosta, $badgeChat);

    $items = [];
    foreach ($schede as $scheda) {
        if ((int)($scheda['can_access'] ?? 0) !== 1) {
            continue;
        }

        $codice = (string)($scheda['codice'] ?? '');
        $items[] = [
            'titolo_menu' => (string)($scheda['titolo'] ?? ''),
            'link'        => (string)($scheda['url'] ?? ''),
            'conteggio'   => (int)($scheda['badge'] ?? 0),
            'class_icon'  => match ($codice) {
                'agenda' => 'fa-calendar',
                'posta'  => 'fa-envelope',
                'chat'   => 'fa-comments',
                default  => 'fa-circle-o',
            },
        ];
    }

    return $items;
}



 public function salva()
{
    $me = session()->get('utente_sess');
    if (!$me || empty($me->id_user)) {
        return $this->response->setStatusCode(401)->setBody('Unauthorized');
    }

    $db      = \Config\Database::connect();
    $clients = new \App\Models\ClientsModel();
    $cd      = new \App\Models\ClientDoctorModel(); // usato per setDoctorForClient
    $users   = new \App\Models\UsersModel();

    // provo a capire se ÃƒÂ¨ paziente
    $cliente = $clients->getClientDecryptedByUserId((int)$me->id_user);

    // =========================
    // PAZIENTE (logica identica)
    // =========================
    if ($cliente) {

        $idClient = (int)$cliente['id_client'];

        $rules = [
            'nome'           => 'required|min_length[2]',
            'cognome'        => 'required|min_length[2]',
            'codice_fiscale' => 'required|min_length[8]',
            'cellulare'      => 'required|min_length[5]',
            'id_dot'         => 'required|integer|greater_than[0]', // medico obbligatorio
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Controlla i campi: alcuni valori non sono validi.')
                ->with('validation', $this->validator->getErrors());
        }

        $dataPlain = [
            'nome'           => (string)$this->request->getPost('nome'),
            'cognome'        => (string)$this->request->getPost('cognome'),
            'email'          => (string)$this->request->getPost('email'),
            'cellulare'      => (string)$this->request->getPost('cellulare'),
            'codice_fiscale' => strtoupper(trim((string)$this->request->getPost('codice_fiscale'))),
            'provincia'      => (string)$this->request->getPost('provincia'),
            'citta'          => (string)$this->request->getPost('citta'),
            'indirizzo'      => (string)$this->request->getPost('indirizzo'),
            'avviso_mail'    => (int)($this->request->getPost('avviso_mail') ?? 0),
        ];

        if ($dataPlain['codice_fiscale'] === '') {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Codice fiscale mancante.');
        }

        $userConflict = $users->findOtherByUsernameInsensitive($dataPlain['codice_fiscale'], (int)$me->id_user);
        if ($userConflict) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Esiste gia un altro account con questo codice fiscale.');
        }

        $idDot = (int)($this->request->getPost('id_dot') ?? 0);
        if ($idDot <= 0) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Devi selezionare un medico.');
        }

        $db->transStart();

        // 1) aggiorno dap02_clients (criptati + id_personale)
        $ok1 = $clients->updateClientEncrypted($idClient, $dataPlain, $idDot);

        // 2) aggiorno tabella associazione (metodo tuo)
        $ok2 = $cd->setDoctorForClient($idClient, $idDot);

        // 3) riallineo sempre lo username dell'account al codice fiscale del profilo
        $ok3 = (bool)$db->table('dap01_users')
            ->where('id_user', (int)$me->id_user)
            ->update(['username' => $dataPlain['codice_fiscale']]);

        $db->transComplete();

        if (!$db->transStatus() || !$ok1 || !$ok2 || !$ok3) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Errore durante il salvataggio del profilo.');
        }

        return redirect()->to(base_url('profilo'))
            ->with('success', 'Profilo aggiornato con successo.');
    }

    // =========================
    // PERSONALE (dap03_personale criptata)
    // =========================
    $personaleM  = new \App\Models\PersonaleModel();
    $personaleRow = $personaleM->getPersonaleDecryptedByUserId((int)$me->id_user);

    if (!$personaleRow) {
        return redirect()->back()->with('error', 'Profilo personale non trovato.');
    }

    $rulesP = [
        'nome'      => 'required|min_length[2]',
        'cognome'   => 'required|min_length[2]',
        'qualifica' => 'required|min_length[2]',
        'cellulare' => 'required|min_length[5]',
        'email'     => 'permit_empty|valid_email',
        'id_gruppo' => 'required|integer|greater_than[0]',
    ];

    if (!$this->validate($rulesP)) {
        return redirect()->back()
            ->withInput()
            ->with('error', 'Controlla i campi: alcuni valori non sono validi.')
            ->with('validation', $this->validator->getErrors());
    }

    $idPersonale = (int)$personaleRow['id_personale'];
    $idGruppo    = (int)($this->request->getPost('id_gruppo') ?? 0);

    $dataPlainP = [
        'nome'      => (string)$this->request->getPost('nome'),
        'cognome'   => (string)$this->request->getPost('cognome'),
        'qualifica' => (string)$this->request->getPost('qualifica'),
        'email'     => (string)$this->request->getPost('email'),
        'cellulare' => (string)$this->request->getPost('cellulare'),

        // updatePersonaleEncrypted:
        // - id_gruppo -> finisce su "luogo"
        // - id_tipo   -> lo manteniamo invariato
        'id_gruppo'  => $idGruppo,
        'id_tipo'    => (int)($personaleRow['tipo'] ?? 0),
    ];

    $db->transStart();
    $okP = $personaleM->updatePersonaleEncrypted($idPersonale, $dataPlainP);
    $db->transComplete();

    if (!$db->transStatus() || !$okP) {
        return redirect()->back()
            ->withInput()
            ->with('error', 'Errore durante il salvataggio del profilo.');
    }

    return redirect()->to(base_url('profilo'))
        ->with('success', 'Profilo aggiornato con successo.');
}


public function sendPasswordOtp()
{
    $me = session()->get('utente_sess');
    if (!$me || empty($me->id_user)) {
        return $this->response->setStatusCode(401)->setJSON([
            'ok' => false,
            'msg' => 'Unauthorized',
        ]);
    }

    $channel = strtolower(trim((string)($this->request->getPost('channel') ?? 'push')));
    if (!in_array($channel, ['push', 'sms', 'email'], true)) {
        return $this->response->setStatusCode(400)->setJSON([
            'ok' => false,
            'msg' => 'Canale OTP non valido.',
        ]);
    }

    $context = $this->getPasswordOtpContext($me);
    $userId = (int)$context['userId'];
    $cellulare = trim((string)$context['cellulare']);
    $email = trim((string)$context['email']);

    if ($userId <= 0 || $cellulare === '') {
        return $this->response->setStatusCode(400)->setJSON([
            'ok' => false,
            'msg' => 'Cellulare non disponibile per inviare OTP.',
        ]);
    }

    $notifications = null;
    if ($channel === 'push') {
        $notifications = new NotificationService();
        if (!$notifications->hasActiveMobile($userId)) {
            $this->logPasswordOtpDelivery($userId, 'push', false, 'no_active_mobile');
            return $this->response->setStatusCode(400)->setJSON([
                'ok' => false,
                'msg' => 'Nessun dispositivo mobile attivo per le notifiche push.',
            ]);
        }
    }

    if ($channel === 'email' && $email === '') {
        $this->logPasswordOtpDelivery($userId, 'email', false, 'no_profile_email');
        return $this->response->setStatusCode(400)->setJSON([
            'ok' => false,
            'msg' => 'Email non presente nel profilo.',
        ]);
    }

    $otp = $this->issuePasswordOtp($cellulare);
    if ($otp === null) {
        if ($channel === 'push' || $channel === 'email') {
            $this->logPasswordOtpDelivery($userId, $channel, false, 'otp_generation_failed');
        }
        return $this->response->setStatusCode(500)->setJSON([
            'ok' => false,
            'msg' => 'Impossibile generare OTP.',
        ]);
    }

    try {
        if ($channel === 'push') {
            $send = $notifications->sendOtpPush($userId, $otp);
            if (empty($send['ok'])) {
                $error = trim((string)($send['error'] ?? ''));
                if ($error === '' && isset($send['status']) && (string)$send['status'] !== '200') {
                    $error = 'push_status_' . (string)$send['status'];
                }
                $this->logPasswordOtpDelivery($userId, 'push', false, $error !== '' ? $error : 'push_send_failed');
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'msg' => 'Invio push non riuscito.',
                ]);
            }

            $this->logPasswordOtpDelivery($userId, 'push', true, null, [
                'source' => 'profilo',
            ]);
            return $this->response->setJSON([
                'ok' => true,
                'msg' => 'OTP inviato al dispositivo collegato.',
            ]);
        }

        if ($channel === 'email') {
            if (!$this->sendPasswordOtpEmail($email, $otp)) {
                $this->logPasswordOtpDelivery($userId, 'email', false, 'email_send_failed');
                return $this->response->setStatusCode(500)->setJSON([
                    'ok' => false,
                    'msg' => 'Invio email non riuscito.',
                ]);
            }

            $this->logPasswordOtpDelivery($userId, 'email', true, null, [
                'masked_email' => $this->maskEmailForStats($email),
                'source' => 'profilo',
            ]);
            return $this->response->setJSON([
                'ok' => true,
                'msg' => 'OTP inviato via email.',
            ]);
        }

        $message = "AmbulatorioFacile - Il suo codice OTP e' {$otp}. Non divulgarlo.";
        (new SmsSender())->sendSMSIndex($cellulare, $message);

        return $this->response->setJSON([
            'ok' => true,
            'msg' => 'OTP inviato via SMS.',
        ]);
    } catch (\Throwable $e) {
        log_message('error', 'sendPasswordOtp ERROR: ' . $e->getMessage());
        if ($channel === 'push' || $channel === 'email') {
            $this->logPasswordOtpDelivery($userId, $channel, false, mb_substr($e->getMessage(), 0, 255));
        }
        return $this->response->setStatusCode(500)->setJSON([
            'ok' => false,
            'msg' => 'Errore durante invio OTP.',
        ]);
    }
}


public function salvaPassword()
{
    $me = session()->get('utente_sess');
    if (!$me || empty($me->id_user)) {
        return $this->response->setStatusCode(401)->setBody('Unauthorized');
    }

    $db = \Config\Database::connect();
    $crypto = new \App\Libraries\Crypto_helper();

    $password  = (string)($this->request->getPost('password_new') ?? '');
    $password2 = (string)($this->request->getPost('password_new2') ?? '');

    // 1) match
    if ($password === '' || $password2 === '') {
        return redirect()->back()->with('error', 'Inserisci password e conferma password.');
    }
    if ($password !== $password2) {
        return redirect()->back()->with('error', 'Le password non coincidono.');
    }

    // 2) regole come registrazione
    // - >= 8
    // - 1 maiuscola
    // - 1 minuscola
    // - 1 speciale
    $okLen  = (strlen($password) >= 8);
    $okUp   = (bool)preg_match('/[A-Z]/', $password);
    $okLow  = (bool)preg_match('/[a-z]/', $password);
    $okSpec = (bool)preg_match('/[^A-Za-z0-9]/', $password);

    if (!($okLen && $okUp && $okLow && $okSpec)) {
        return redirect()->back()->with('error',
            'Password non valida: min 8 caratteri, 1 maiuscola, 1 minuscola, 1 speciale.'
        );
    }

    $otpCode = trim((string)($this->request->getPost('otp_code') ?? ''));
    if (!preg_match('/^[0-9]{4,8}$/', $otpCode)) {
        return redirect()->back()->with('error', 'Inserisci il codice OTP ricevuto.');
    }

    $otpContext = $this->getPasswordOtpContext($me);
    $otpCellulare = trim((string)$otpContext['cellulare']);
    if ($otpCellulare === '') {
        return redirect()->back()->with('error', 'Cellulare non disponibile per verificare OTP.');
    }

    if (!(new AuthCodeModel())->checkOtp($otpCode, $otpCellulare)) {
        return redirect()->back()->with('error', 'Codice OTP errato o scaduto.');
    }

    // 3) aggiorno dap01_users con la tua tecnica (SQL scritto)
    $date = date_create(date("Y-m-d H:i:s"));
    date_add($date, date_interval_create_from_date_string("365 days"));
    $password_scad = date_format($date, "Y-m-d H:i:s");

    // encrypt() nel tuo progetto restituisce un'espressione SQL pronta (come nel tuo esempio)
    $passwordEncExpr = $crypto->encrypt($password);

    $sql = "UPDATE dap01_users
            SET datascadenza='" . $password_scad . "',
                password=" . $passwordEncExpr . ",
                vector_id=@init_vector
            WHERE id_user=" . (int)$me->id_user;

    log_message('debug', 'CambioPassword PROFILO => ' . $sql);

    try {
        $db->transStart();
        $db->query($sql);
        $db->transComplete();

        if (!$db->transStatus()) {
            return redirect()->back()->with('error', 'Errore durante il cambio password.');
        }

        session()->remove(['profile_pwd_otp', 'profile_pwd_otp_cellulare']);

        return redirect()->to(base_url('profilo'))->with('success', 'Password aggiornata con successo.');
    } catch (\Throwable $e) {
        log_message('error', 'salvaPassword ERROR: ' . $e->getMessage());
        return redirect()->back()->with('error', 'Errore durante il cambio password.');
    }
}

private function getPasswordOtpContext($me): array
{
    $userId = (int)($me->id_user ?? session()->get('userId') ?? 0);
    $fallbackCellulare = trim((string)(session()->get('cellulare') ?? ''));
    $fallbackEmail = is_object($me) ? trim((string)($me->email ?? '')) : '';

    if ($userId <= 0) {
        return [
            'userId' => 0,
            'cellulare' => $fallbackCellulare,
            'email' => $fallbackEmail,
        ];
    }

    $cliente = (new ClientsModel())->getClientDecryptedByUserId($userId);
    if ($cliente) {
        return [
            'userId' => $userId,
            'cellulare' => trim((string)(($cliente['cellulare'] ?? '') ?: $fallbackCellulare)),
            'email' => trim((string)(($cliente['email'] ?? '') ?: $fallbackEmail)),
        ];
    }

    $personale = (new PersonaleModel())->getPersonaleDecryptedByUserId($userId);
    if ($personale) {
        return [
            'userId' => $userId,
            'cellulare' => trim((string)(($personale['cellulare'] ?? '') ?: $fallbackCellulare)),
            'email' => trim((string)(($personale['email'] ?? '') ?: $fallbackEmail)),
        ];
    }

    return [
        'userId' => $userId,
        'cellulare' => $fallbackCellulare,
        'email' => $fallbackEmail,
    ];
}

private function issuePasswordOtp(string $cellulare): ?string
{
    $cellulare = trim($cellulare);
    if ($cellulare === '') {
        return null;
    }

    $otp = $this->getForcedOtpForCurrentSession() ?? $this->generateRandomCode();
    $crypto = new Crypto_helper();

    $this->db->query("SET @init_vector = RANDOM_BYTES(16)");
    $sql = "INSERT INTO dap16_auth_code (cellulare, authCode, vector_id)
            VALUES (" . $crypto->encrypt($cellulare) . ", ?, @init_vector)";
    $this->db->query($sql, [$otp]);

    if ($this->db->affectedRows() <= 0) {
        return null;
    }

    session()->set('profile_pwd_otp', $otp);
    session()->set('profile_pwd_otp_cellulare', $cellulare);

    return $otp;
}

private function getForcedOtpForCurrentSession(): ?string
{
    $username = trim((string)(session()->get('username') ?? ''));
    if (strcasecmp($username, 'demo.dietista') === 0) {
        return '2510';
    }

    return null;
}

private function sendPasswordOtpEmail(string $emailAddress, string $otp): bool
{
    $config = null;
    try {
        $mailer = \Config\Services::email(null, false);
        $config = config('Email');

        $fromEmail = trim((string)($config->fromEmail ?? ''));
        $fromName = trim((string)($config->fromName ?? ''));

        if ($fromEmail === '') {
            $fromEmail = (string)(env('email.fromEmail') ?: 'noreply@ambulatoriofacile.it');
        }

        if ($fromName === '') {
            $fromName = (string)(env('email.fromName') ?: 'AmbulatorioFacile');
        }

        $message = "Il tuo codice OTP e' {$otp}.\n"
            . "Non condividerlo con nessuno.\n"
            . "Il codice rimane valido per circa 2 minuti.";

        $mailer->clear(true);
        $mailer->setFrom($fromEmail, $fromName);
        $mailer->setTo($emailAddress);
        $mailer->setSubject('Codice OTP AmbulatorioFacile');
        $mailer->setMessage($message);

        if (!$mailer->send()) {
            $debugMessage = trim(strip_tags((string)$mailer->printDebugger(['headers', 'subject'])));
            log_message('error', 'sendPasswordOtpEmail failed for {email}. protocol={protocol} host={host} port={port} crypto={crypto} from={from}. Debugger: {debugger}', [
                'email' => $emailAddress,
                'protocol' => (string)($config->protocol ?? ''),
                'host' => (string)($config->SMTPHost ?? ''),
                'port' => (string)($config->SMTPPort ?? ''),
                'crypto' => (string)($config->SMTPCrypto ?? ''),
                'from' => $fromEmail,
                'debugger' => $debugMessage !== '' ? $debugMessage : 'n/a',
            ]);
            return false;
        }

        return true;
    } catch (\Throwable $e) {
        log_message('error', 'sendPasswordOtpEmail ERROR for {email}. protocol={protocol} host={host} port={port} crypto={crypto}. Exception: {message}', [
            'email' => $emailAddress,
            'protocol' => (string)($config->protocol ?? ''),
            'host' => (string)($config->SMTPHost ?? ''),
            'port' => (string)($config->SMTPPort ?? ''),
            'crypto' => (string)($config->SMTPCrypto ?? ''),
            'message' => $e->getMessage(),
        ]);
        return false;
    }
}

private function generateRandomCode(int $length = 4): string
{
    $otp = '';
    for ($i = 0; $i < $length; $i++) {
        $otp .= (string)random_int(0, 9);
    }

    return $otp;
}

private function logPasswordOtpDelivery(int $userId, string $channel, bool $success, ?string $errorMessage = null, array $meta = []): void
{
    if (!in_array($channel, ['push', 'email'], true)) {
        return;
    }

    $me = session()->get('utente_sess');
    $userType = (is_object($me) && isset($me->tipo)) ? (int)$me->tipo : null;

    (new OtpDeliveryLogService())->record(
        OtpDeliveryLogService::PURPOSE_PASSWORD_CHANGE,
        $channel,
        $success,
        $userId > 0 ? $userId : null,
        ($userType !== null && $userType > 0) ? $userType : null,
        $errorMessage,
        $meta
    );
}

private function maskEmailForStats(string $email): string
{
    $email = trim($email);
    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }

    [$local, $domain] = explode('@', $email, 2);
    $localLen = mb_strlen($local);

    if ($localLen <= 2) {
        $maskedLocal = mb_substr($local, 0, 1) . '*';
    } else {
        $maskedLocal = mb_substr($local, 0, 2) . str_repeat('*', max(1, $localLen - 2));
    }

    return $maskedLocal . '@' . $domain;
}

public function disconnectDevice()
{
    $me = session()->get('utente_sess');
    if (!$me || empty($me->id_user)) {
        return $this->response->setStatusCode(401)->setBody('Unauthorized');
    }

    $userId = (int)(session()->get('userId') ?? 0);
    try {
        $notifications = new NotificationService();
        $notifications->disconnectUserMobiles($userId);
        return redirect()->to(base_url('profilo'))->with('success', 'Telefono disassociato.');
    } catch (\Throwable $e) {
        log_message('error', 'disconnectDevice ERROR => ' . $e->getMessage());
        return redirect()->back()->with('error', 'Errore durante la disassociazione.');
    }
}

public function registerDeviceHere()
{
    $me = session()->get('utente_sess');
    if (!$me || empty($me->id_user)) {
        return $this->response->setStatusCode(401)->setJSON(['ok'=>false,'msg'=>'Unauthorized']);
    }

    $userId = (int)$me->id_user;

    // POST urlencoded
    $endpoint = (string)($this->request->getPost('endpoint') ?? '');
    $p256dh   = (string)($this->request->getPost('p256dh') ?? '');
    $auth     = (string)($this->request->getPost('auth') ?? '');
    $label    = (string)($this->request->getPost('device_label') ?? $this->request->getPost('device_name') ?? 'Dispositivo');

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return $this->response->setStatusCode(400)->setJSON(['ok'=>false,'msg'=>'Dati subscription mancanti']);
    }

    // user agent detection semplice (puoi anche copiare la tua logica completa dal PushController)
    $ua = $this->request->getUserAgent();
    $uaString = $ua->getAgentString();
    $platform = $ua->getPlatform();
    $browser  = $ua->getBrowser();

    $s = strtolower($uaString);
    $isMobile = (preg_match('/iphone|ipod|android.*mobile|mobi/', $s) === 1) ? 1 : 0;
    $deviceType = $isMobile ? 'phone' : 'desktop';

    try {
        $notifications = new NotificationService();
        $id = $notifications->registerSubscription(
            $userId,
            $endpoint,
            $p256dh,
            $auth,
            [
                'ua'           => $uaString,
                'device_type'  => $deviceType,
                'device_os'    => $platform ?: 'Unknown',
                'browser'      => $browser ?: '',
                'is_mobile'    => $isMobile,
                'device_name'  => mb_substr($label, 0, 100),
                'device_label' => mb_substr($label, 0, 120),
            ],
            true
        );

        return $this->response->setJSON(['ok'=>true,'id'=>$id]);

    } catch (\Throwable $e) {
        log_message('error', 'registerDeviceHere ERROR => ' . $e->getMessage());
        return $this->response->setStatusCode(500)->setJSON(['ok'=>false,'msg'=>'Server error']);
    }
}


}


