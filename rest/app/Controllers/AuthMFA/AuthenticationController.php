<?php

namespace App\Controllers\AuthMFA;

use App\Controllers\BaseController;
use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;
use App\Libraries\SmsSender;
use App\Models\AuthCodeModel;
use App\Models\ClientsModel;
use App\Models\MenuModel;
use App\Models\PersonaleModel;
use App\Services\LegacyTenantSessionService;
use App\Services\LegacyLoginHandoffService;
use App\Services\NotificationService;
use App\Services\OtpDeliveryLogService;
use App\Services\SessionNavigationService;
use App\Services\TenantLoginOtpService;

class AuthenticationController extends BaseController
{
    protected $db;
    protected $dbConfig;
    protected NotificationService $notifications;
    protected OtpDeliveryLogService $otpDeliveryLogs;

    public function __construct()
    {
        (new LegacyTenantSessionService())->bindPendingRuntimeIfAvailable();
        $this->db = \Config\Database::connect();
        $this->dbConfig = new DatabaseConfig();
        $this->dbConfig->setEncryptionConfig($this->db);
        $this->notifications = new NotificationService();
        $this->otpDeliveryLogs = new OtpDeliveryLogService();
    }

    public function index()
    {
        helper(['portal', 'session_auth']);
        (new TenantLoginOtpService())->syncCurrentSessionRequirement();
        $otpFromUrl = trim((string)$this->request->getGet('otp'));
        $codeFromUrl = trim((string)$this->request->getGet('code'));
        $openedFromPush = (string)$this->request->getGet('fromPush') === '1'
            || $otpFromUrl !== ''
            || $codeFromUrl !== '';

        $isPasswordExpiredFlow = (int)session()->get('pwd_expired_flow') === 1;
        $isResetFlow = (int)session()->get('reset_flow') === 1;
        $accessConfirmed = session_access_is_confirmed();

        if (!$isPasswordExpiredFlow && !$isResetFlow && $accessConfirmed) {
            $portalRedirect = portal_session_console_url();
            if ($portalRedirect !== null) {
                return redirect()->to($portalRedirect);
            }
        }

        $userId    = (int)(session()->get('userId') ?? 0);

        if ($userId <= 0) {
            return $this->sessionExpiredRedirect();
        }

        if (!$this->isOtpRequiredForCurrentSession()) {
            return redirect()->to($this->resolveAccessCompletionRedirectUrl());
        }

        $cellulare = trim((string)(session()->get('cellulare') ?? ''));
        $otpIdentity = $this->currentOtpIdentity();
        if ($otpIdentity === '') {
            return $this->sessionExpiredRedirect();
        }

        $isCurrentMobile = $this->isCurrentRequestMobile();
        $isDesktop = !$isCurrentMobile;
        $profile = $this->getCurrentProfileContext($userId);

        if ($this->shouldPreIssueOtpForCurrentSession()) {
            $this->issueOtpForCurrentSession(true);
        }

        $hasMobile = $this->notifications->hasActiveMobile($userId);
        $mobiles = $this->notifications->activeMobiles($userId);
        $preferCurrentDeviceOtp = $hasMobile && $isCurrentMobile && !$openedFromPush;

        $linkToken = null;

        if ($hasMobile && !$openedFromPush && !$preferCurrentDeviceOtp) {
            $sent = $this->sendOtpPushForCurrentSession();
            if (!$sent) {
                $hasMobile = false;
                $mobiles = [];
            }
        }

        if (!$hasMobile) {
            $linkToken = $this->createDeviceLinkToken($userId);
        }

        return view('auth/auth', [
            'cellulare'      => $cellulare,
            'hasMobile'      => $hasMobile,
            'isDesktop'      => $isDesktop,
            'linkToken'      => $linkToken,
            'mobiles'        => $mobiles,
            'preferCurrentDeviceOtp' => $preferCurrentDeviceOtp,
            'profileEmail'   => $profile['email'],
            'hasProfileEmail'=> $profile['email'] !== '',
            'maskedEmail'    => $this->maskEmail($profile['email']),
            'allowEmailOtpWithoutPassword' => $this->canSkipEmailPasswordConfirmationForOtp(),
            'allowEmailOtpProfileEdit' => $this->canEditProfileEmailForOtp(),
            'vapidPublicKey' => push_vapid_public_key(),
        ]);
    }

    public function handoff()
    {
        $payload = trim((string)($this->request->getGet('payload') ?? $this->request->getPost('payload') ?? ''));
        $signature = trim((string)($this->request->getGet('sig') ?? $this->request->getPost('sig') ?? ''));

        try {
            $result = (new LegacyLoginHandoffService())
                ->authenticateTransferredUser($payload, $signature);

            log_message('info', 'Auth handoff completato per tipoUser={type}, redirect={redirect}', [
                'type' => $result['userType'] ?? 0,
                'redirect' => $result['redirectUrl'] ?? 'auth',
            ]);

            return redirect()
                ->to(site_url((string)($result['redirectUrl'] ?? 'auth')))
                ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->setHeader('Pragma', 'no-cache');
        } catch (\Throwable $e) {
            log_message('error', 'Auth handoff fallito: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->response
                ->setStatusCode(403)
                ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->setHeader('Pragma', 'no-cache')
                ->setBody('Accesso trasferito non valido o scaduto.');
        }
    }

    public function link()
    {
        return view('auth/link', [
            'vapidPublicKey' => push_vapid_public_key(),
        ]);
    }

    public function deviceStatus()
    {
        $userId = (int)(session()->get('userId') ?? 0);
        if ($userId <= 0) {
            return $this->response->setJSON([
                'ok'        => false,
                'hasMobile' => false,
            ]);
        }

        return $this->response->setJSON([
            'ok'        => true,
            'hasMobile' => $this->notifications->hasActiveMobile($userId),
            'mobiles'   => $this->notifications->activeMobiles($userId),
        ]);
    }

    public function sendOtpPushNow()
    {
        $userId    = (int)(session()->get('userId') ?? 0);
        $otpIdentity = $this->currentOtpIdentity();

        if ($userId <= 0 || $otpIdentity === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'ok'      => false,
                'otpSent' => false,
                'msg'     => 'Sessione non valida',
            ]);
        }

        if (!$this->notifications->hasActiveMobile($userId)) {
            $this->logOtpDelivery('push', false, 'no_active_mobile');
            return $this->response->setJSON([
                'ok'      => true,
                'otpSent' => false,
                'msg'     => 'Nessun dispositivo mobile attivo',
            ]);
        }

        $sent = $this->sendOtpPushForCurrentSession();

        return $this->response->setJSON([
            'ok'      => true,
            'otpSent' => $sent,
            'msg'     => $sent ? null : 'Invio push non riuscito',
        ]);
    }

    public function qrcode()
    {
        $token = trim((string)$this->request->getGet('token'));
        if ($token === '') {
            return $this->response->setStatusCode(400)->setBody('Missing token');
        }

        $url = base_url('auth/link?token=' . rawurlencode($token));
        $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . rawurlencode($url);

        return redirect()->to($qr);
    }

    public function linkComplete()
    {
        $token    = trim((string)$this->request->getPost('token'));
        $endpoint = trim((string)$this->request->getPost('endpoint'));
        $p256dh   = trim((string)$this->request->getPost('p256dh'));
        $auth     = trim((string)$this->request->getPost('auth'));
        $device   = trim((string)$this->request->getPost('device_name'));

        if ($token === '' || $endpoint === '' || $p256dh === '' || $auth === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'ok'  => false,
                'msg' => 'Parametri mancanti',
            ]);
        }

        try {
            $meta = $this->buildDeviceMeta($device);
            $this->notifications->completeLinkToken($token, $endpoint, $p256dh, $auth, $meta);

            return $this->response->setJSON([
                'ok'      => true,
                'otpSent' => false,
                'msg'     => 'Dispositivo collegato. Torna sul PC: OTP push automatico.',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'ok'  => false,
                'msg' => $e->getMessage(),
            ]);
        }
    }

    public function registerDeviceDirect()
    {
        $userId    = (int)(session()->get('userId') ?? 0);
        $cellulare = (string)(session()->get('cellulare') ?? '');

        if ($userId <= 0 || $cellulare === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'ok'      => false,
                'msg'     => 'Sessione non valida',
                'details' => 'userId o cellulare mancanti',
            ]);
        }

        $endpoint = trim((string)$this->request->getPost('endpoint'));
        $p256dh   = trim((string)$this->request->getPost('p256dh'));
        $auth     = trim((string)$this->request->getPost('auth'));
        $device   = trim((string)$this->request->getPost('device_name'));

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'ok'  => false,
                'msg' => 'Parametri mancanti',
            ]);
        }

        $meta = $this->buildDeviceMeta($device);
        $this->notifications->registerSubscription($userId, $endpoint, $p256dh, $auth, $meta, true);

        $sent = $this->sendOtpPushForCurrentSession($endpoint, [
            'client_mode' => trim((string)$this->request->getPost('client_mode')),
        ]);

        return $this->response->setJSON([
            'ok'      => true,
            'otpSent' => $sent,
        ]);
    }

    public function sendOtpWa()
    {
        $cellulare = trim((string)(session()->get('cellulare') ?? ''));
        if ($cellulare === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'Numero non in sessione',
            ]);
        }

        $otp = $this->issueOtpForCurrentSession($this->shouldPreIssueOtpForCurrentSession());
        if ($otp === null) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Impossibile generare il codice OTP',
            ]);
        }

        $messaggio = "AmbulatorioFacile - Il suo codice OTP e' {$otp}. Non divulgarlo.";

        $sms = new SmsSender();
        $result = $sms->sendWA($cellulare, $messaggio);

        return $this->response->setJSON([
            'success'  => true,
            'response' => $result,
        ]);
    }

    public function sendOtpSms()
    {
        $cellulare = trim((string)(session()->get('cellulare') ?? ''));
        if ($cellulare === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'Numero non in sessione',
            ]);
        }

        $otp = $this->issueOtpForCurrentSession($this->shouldPreIssueOtpForCurrentSession());
        if ($otp === null) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Impossibile generare il codice OTP',
            ]);
        }

        $messaggio = "AmbulatorioFacile - Il suo codice OTP e' {$otp}. Non divulgarlo.";

        $sms = new SmsSender();
        $result = $sms->sendSMSIndex($cellulare, $messaggio);

        return $this->response->setJSON([
            'success'  => true,
            'response' => $result,
        ]);
    }

    public function sendOtpEmail()
    {
        $userId = (int)(session()->get('userId') ?? 0);
        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'Sessione non valida',
            ]);
        }

        $profile = $this->getCurrentProfileContext($userId);
        $email = trim((string)($profile['email'] ?? ''));

        if ($email === '') {
            $this->logOtpDelivery('email', false, 'no_profile_email');
            return $this->response->setJSON([
                'success'      => false,
                'requireEmail' => $this->canEditProfileEmailForOtp(),
                'message'      => $this->canEditProfileEmailForOtp()
                    ? 'Nessun indirizzo email presente nel profilo.'
                    : 'Nessun indirizzo email presente nel profilo. Nel recupero password puoi usare solo una mail gia salvata.',
            ]);
        }

        $otp = $this->issueOtpForCurrentSession($this->shouldPreIssueOtpForCurrentSession());
        if ($otp === null) {
            $this->logOtpDelivery('email', false, 'otp_generation_failed');
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Impossibile generare il codice OTP',
            ]);
        }

        $send = $this->sendOtpEmailMessage($email, $otp, $profile);
        if (!$send['ok']) {
            $this->logOtpDelivery('email', false, (string)($send['error'] ?? 'email_send_failed'));
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Invio email non riuscito. Riprova tra poco.',
            ]);
        }

        $this->logOtpDelivery('email', true, null, [
            'masked_email' => $this->maskEmail($email),
            'email_address' => $this->normalizeEmailForStats($email),
            'email_hash'   => $this->emailHashForStats($email),
        ]);

        return $this->response->setJSON([
            'success'     => true,
            'maskedEmail' => $this->maskEmail($email),
            'message'     => 'OTP inviato via email. Controlla anche la cartella Spam.',
        ]);
    }

    public function saveEmailAndSendOtp()
    {
        $userId = (int)(session()->get('userId') ?? 0);
        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'Sessione non valida',
            ]);
        }

        $email = trim((string)$this->request->getPost('email'));
        $password = (string)$this->request->getPost('password');
        $skipPasswordConfirmation = $this->canSkipEmailPasswordConfirmationForOtp();

        if (!$this->canEditProfileEmailForOtp()) {
            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => 'Nel recupero password non e possibile modificare l email da questa schermata.',
            ]);
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'Inserisci un indirizzo email valido.',
            ]);
        }

        if (!$skipPasswordConfirmation) {
            if ($password === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'Inserisci la password di accesso per confermare.',
                ]);
            }

            if (!$this->verifyCurrentLoginPassword($password)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'success' => false,
                    'message' => 'Password non corretta.',
                ]);
            }
        }

        if (!$this->updateCurrentProfileEmail($userId, $email)) {
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Salvataggio email non riuscito.',
            ]);
        }

        $this->syncSessionEmail($email);

        $otp = $this->issueOtpForCurrentSession($this->shouldPreIssueOtpForCurrentSession());
        if ($otp === null) {
            $this->logOtpDelivery('email', false, 'otp_generation_failed');
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Email salvata, ma non e stato possibile generare l OTP.',
            ]);
        }

        $profile = $this->getCurrentProfileContext($userId);
        $send = $this->sendOtpEmailMessage($email, $otp, $profile);
        if (!$send['ok']) {
            $this->logOtpDelivery('email', false, (string)($send['error'] ?? 'email_send_failed'));
            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Email salvata, ma l invio dell OTP non e riuscito.',
            ]);
        }

        $this->logOtpDelivery('email', true, null, [
            'masked_email' => $this->maskEmail($email),
            'email_address' => $this->normalizeEmailForStats($email),
            'email_hash'   => $this->emailHashForStats($email),
            'email_saved'  => true,
        ]);

        return $this->response->setJSON([
            'success'     => true,
            'emailSaved'  => true,
            'maskedEmail' => $this->maskEmail($email),
            'message'     => 'Email salvata nel profilo e OTP inviato. Controlla anche la cartella Spam.',
        ]);
    }

    public function checkOtp()
    {
        helper('session_auth');

        $json      = $this->request->getJSON();
        $authModel = new AuthCodeModel();
        $menuModel = new MenuModel();
        $navigation = new SessionNavigationService();

        if (!$json || !isset($json->authCode)) {
            return $this->response->setJSON(['error' => 'Dati non validi'])->setStatusCode(400);
        }

        $otpIdentity = $this->currentOtpIdentity();
        if ($otpIdentity === '') {
            return $this->response->setJSON(['error' => 'Sessione scaduta'])->setStatusCode(401);
        }

        $esito = $authModel->checkOtp($json->authCode, $otpIdentity);
        if (!$esito) {
            return $this->response->setJSON([
                'error' => 'Codice OTP inserito errato o scaduto',
            ])->setStatusCode(400);
        }

        session()->remove('is_admin_arrow_login');
        session()->remove('admin_arrow_username');

        return $this->response->setJSON([
            'success'     => true,
            'redirectUrl' => $this->completeAccessConfirmation($menuModel, $navigation),
        ]);
    }

    public function cryptoCheck()
    {
        return $this->response->setJSON([
            'ok'      => true,
            'message' => 'Crypto session attiva',
        ]);
    }

    public function inserisciAuthCode($cellulare, $authCode)
    {
        $crypto = new Crypto_helper();
        $sql = "INSERT INTO dap16_auth_code (cellulare, authCode, vector_id)
                VALUES (" . $crypto->encrypt($cellulare) . ", '" . $authCode . "', @init_vector)";

        $this->db->query($sql);
        return $this->db->affectedRows() > 0;
    }

    private function sendOtpPushForCurrentSession(?string $preferredEndpoint = null, array $deliveryContext = []): bool
    {
        $userId    = (int)(session()->get('userId') ?? 0);
        $otpIdentity = $this->currentOtpIdentity();
        if ($userId <= 0 || $otpIdentity === '') {
            $this->logOtpDelivery('push', false, 'invalid_session');
            return false;
        }

        $otp = $this->issueOtpForCurrentSession($this->shouldPreIssueOtpForCurrentSession());
        if ($otp === null) {
            $this->logOtpDelivery('push', false, 'otp_generation_failed');
            return false;
        }

        $clientMode = strtolower(trim((string)($deliveryContext['client_mode'] ?? '')));
        $targetUrl = base_url('auth?fromPush=1');
        if ($clientMode === 'standalone') {
            $targetUrl .= '&app=1';
        }

        $payload = [
            'type'  => 'otp',
            'title' => "OTP {$otp}",
            'body'  => "Codice di accesso: {$otp}",
            'tag'   => 'otp-login-' . $otp,
            'icon'  => NotificationService::notificationIconUrl(),
            'badge' => NotificationService::notificationBadgeUrl(),
            'silent' => true,
            'renotify' => false,
            'requireInteraction' => false,
            'data'  => [
                'url' => $targetUrl
                    . '&otp=' . rawurlencode($otp)
                    . '&ts=' . rawurlencode((string)time()),
                'otp' => $otp,
                'clientMode' => $clientMode,
            ],
        ];

        $meta = [];

        if ($preferredEndpoint !== null && trim($preferredEndpoint) !== '') {
            $meta['preferred_endpoint'] = true;
            $preferred = $this->notifications->sendToEndpoint(
                trim($preferredEndpoint),
                $payload,
                'otp',
                ['TTL' => 300, 'urgency' => 'high']
            );
            if (!empty($preferred['ok'])) {
                $this->logOtpDelivery('push', true, null, ['delivery_mode' => 'preferred_endpoint'] + $meta);
                return true;
            }

            $preferredError = $this->extractPushDeliveryError($preferred);
            if ($preferredError !== null) {
                $meta['preferred_error'] = $preferredError;
            }
        }

        $result = $this->notifications->sendToUser(
            $userId,
            $payload,
            'otp',
            ['TTL' => 300, 'urgency' => 'high']
        );

        $ok = !empty($result['ok']);
        $this->logOtpDelivery(
            'push',
            $ok,
            $ok ? null : $this->extractPushDeliveryError($result),
            ['delivery_mode' => 'user'] + $meta
        );

        return $ok;
    }

    private function issueOtpForCurrentSession(bool $reuseExisting = false): ?string
    {
        $otpIdentity = $this->currentOtpIdentity();
        if ($otpIdentity === '') {
            return null;
        }

        $isAdminArrowLogin = $this->isAdminArrowLogin();
        $forcedOtp = $this->getForcedOtpForCurrentSession();
        $requiredOtp = $isAdminArrowLogin ? '2510' : $forcedOtp;
        $hasFixedOtp = $requiredOtp !== null;

        if ($reuseExisting) {
            $authModel = new AuthCodeModel();
            $existingOtp = $authModel->getLatestValidOtp($otpIdentity);
            if ($existingOtp !== null) {
                if (!$hasFixedOtp || $existingOtp === $requiredOtp) {
                    session()->set('otp', $existingOtp);
                    return $existingOtp;
                }
            }
        }

        $otp = $requiredOtp ?? $this->generateRandomCode();

        if (!$this->inserisciAuthCode($otpIdentity, $otp)) {
            return null;
        }

        session()->set('otp', $otp);
        return $otp;
    }

    private function getForcedOtpForCurrentSession(): ?string
    {
        $username = trim((string)(session()->get('username') ?? ''));
        if (in_array(strtolower($username), ['demo.dietista', 'demo.segreteria'], true)) {
            return '2510';
        }

        return null;
    }

    private function isAdminArrowLogin(): bool
    {
        $flag = session()->get('is_admin_arrow_login');
        if (!in_array($flag, [true, 1, '1'], true)) {
            return false;
        }

        $arrowUsername = trim((string)(session()->get('admin_arrow_username') ?? ''));
        if ($arrowUsername === '' || !str_contains($arrowUsername, '->')) {
            return false;
        }

        $parts = array_values(array_filter(array_map('trim', explode('->', $arrowUsername))));
        if (count($parts) !== 2) {
            return false;
        }

        return $parts[0] !== '' && $parts[1] !== '';
    }

    private function shouldPreIssueOtpForCurrentSession(): bool
    {
        $tipoUser = $this->getCurrentSessionUserType();
        return $tipoUser > 0;
    }

    private function isOtpRequiredForCurrentSession(): bool
    {
        return (new TenantLoginOtpService())->syncCurrentSessionRequirement();
    }

    private function resolveAccessCompletionRedirectUrl(): string
    {
        $redirectUrl = $this->completeAccessConfirmation(new MenuModel(), new SessionNavigationService());
        $redirectUrl = trim($redirectUrl);

        if ($redirectUrl === '') {
            return site_url('/');
        }

        if (preg_match('#^https?://#i', $redirectUrl) === 1) {
            return $redirectUrl;
        }

        return site_url(ltrim($redirectUrl, '/'));
    }

    private function completeAccessConfirmation(MenuModel $menuModel, SessionNavigationService $navigation): string
    {
        session()->remove('is_admin_arrow_login');
        session()->remove('admin_arrow_username');
        session()->remove(TenantLoginOtpService::SESSION_KEY_REQUIRED);

        if ((int)session()->get('reset_flow') === 1) {
            session()->set('otp_ok_for_reset', 1);
            return 'reset/cambio';
        }

        if ((int)session()->get('pwd_expired_flow') === 1) {
            session()->set('otp_ok_for_expired', 1);
            return 'password/scaduta';
        }

        if (session()->get('isLoggedIn') !== true) {
            return '';
        }

        $menuAgenda = $menuModel->getMenuAgenda();
        session()->set('menuAgenda', $menuAgenda);
        session()->set('isLoggedInConfirmed', true);
        (new LegacyTenantSessionService())->activatePendingRuntime();
        $navigation->refreshCurrentSession(true);

        $redirectUrl = '';
        $utente = session()->get('utente_sess');

        if (
            (int)session()->get('tipoUser') === 2
            && $utente
            && isset($utente->id_personale)
        ) {
            $db = \Config\Database::connect();

            $row = $db->query("
                SELECT COUNT(*) AS totale
                FROM dap18_sostituto
                WHERE id_personale = ?
                  AND CURDATE() BETWEEN data_inizio AND data_fine
            ", [(int)$utente->id_personale])->getRowArray();

            if ((int)($row['totale'] ?? 0) > 0) {
                $redirectUrl = 'sostituzioni';
            }
        }

        if ($redirectUrl === '') {
            if (session_should_open_agenda_first()) {
                $redirectUrl = 'agenda';
            } elseif (session_has_operational_profile_access()) {
                $redirectUrl = 'admin';
            }
        }

        return $redirectUrl;
    }

    private function canSkipEmailPasswordConfirmationForOtp(): bool
    {
        return (int)(session()->get('pwd_expired_flow') ?? 0) === 1
            && (int)(session()->get('forcePwdChange') ?? 0) === 1
            && session()->get('isLoggedIn') === true;
    }

    private function canEditProfileEmailForOtp(): bool
    {
        return (int)(session()->get('reset_flow') ?? 0) !== 1;
    }

    private function currentOtpDeliveryPurpose(): string
    {
        if ((int)session()->get('reset_flow') === 1) {
            return OtpDeliveryLogService::PURPOSE_PASSWORD_RESET;
        }

        if ((int)session()->get('pwd_expired_flow') === 1) {
            return OtpDeliveryLogService::PURPOSE_PASSWORD_EXPIRED;
        }

        return OtpDeliveryLogService::PURPOSE_LOGIN_MFA;
    }

    private function logOtpDelivery(string $channel, bool $success, ?string $errorMessage = null, array $meta = []): void
    {
        $this->otpDeliveryLogs->record(
            $this->currentOtpDeliveryPurpose(),
            $channel,
            $success,
            (int)(session()->get('userId') ?? 0) ?: null,
            $this->getCurrentSessionUserType() ?: null,
            $errorMessage,
            $meta
        );
    }

    private function extractPushDeliveryError(array $result): ?string
    {
        $error = trim((string)($result['error'] ?? ''));
        if ($error !== '') {
            return mb_substr($error, 0, 255);
        }

        $status = isset($result['status']) ? (string)$result['status'] : '';
        if ($status !== '' && $status !== '200') {
            return 'push_status_' . $status;
        }

        $rows = $result['results'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $inner = is_array($row['result'] ?? null) ? $row['result'] : [];
                $innerError = trim((string)($inner['error'] ?? ''));
                if ($innerError !== '') {
                    return mb_substr($innerError, 0, 255);
                }

                $innerStatus = isset($inner['status']) ? (string)$inner['status'] : '';
                if ($innerStatus !== '' && $innerStatus !== '200') {
                    return 'push_status_' . $innerStatus;
                }
            }
        }

        return 'push_send_failed';
    }

    private function getCurrentSessionUserType(): int
    {
        $tipoUser = (int)(session()->get('tipoUser') ?? 0);
        if ($tipoUser > 0) {
            return $tipoUser;
        }

        $resetTipoUser = (int)(session()->get('tipo_user') ?? 0);
        if ($resetTipoUser > 0) {
            return $resetTipoUser;
        }

        $user = session()->get('utente_sess');
        if (is_object($user) && isset($user->tipo)) {
            return (int)$user->tipo;
        }

        return 0;
    }

    private function currentOtpIdentity(): string
    {
        $identity = trim((string)(session()->get('otp_identity') ?? ''));
        if ($identity !== '') {
            return $identity;
        }

        $cellulare = trim((string)(session()->get('cellulare') ?? ''));
        if ($cellulare !== '') {
            session()->set('otp_identity', $cellulare);
            return $cellulare;
        }

        $userId = (int)(session()->get('userId') ?? 0);
        if ($userId > 0) {
            $identity = 'uid:' . $userId;
            session()->set('otp_identity', $identity);
            return $identity;
        }

        return '';
    }

    private function sendOtpEmailMessage(string $emailAddress, string $otp, array $profileContext = []): array
    {
        $config = null;
        $fromEmail = '';
        $fromName = '';
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

            $viewData = $this->buildOtpEmailViewData($otp, $profileContext, $fromName);
            $messageText = $this->buildOtpEmailPlainText($viewData);
            $messageHtml = '';

            try {
                $messageHtml = view('emails/otp_email', $viewData);
                $renderedText = trim((string) view('emails/otp_email_text', $viewData));
                if ($renderedText !== '') {
                    $messageText = $renderedText;
                }
            } catch (\Throwable $viewError) {
                error_log('[OTP email] template render fallback: ' . $viewError->getMessage());
            }

            $mailer->clear(true);
            $mailer->setFrom($fromEmail, $fromName);
            $mailer->setTo($emailAddress);
            $mailer->setSubject('Codice OTP AmbulatorioFacile');
            $mailer->setMailType('html');
            $mailer->setMessage($messageHtml !== '' ? $messageHtml : nl2br(esc($messageText)));
            $mailer->setAltMessage($messageText);

            if (!$mailer->send()) {
                $htmlDebugMessage = trim(strip_tags((string) $mailer->printDebugger(['headers', 'subject'])));

                // Fallback robusto: alcuni server accettano il testo semplice anche se il multipart HTML fallisce.
                $mailer->clear(true);
                $mailer->setFrom($fromEmail, $fromName);
                $mailer->setTo($emailAddress);
                $mailer->setSubject('Codice OTP AmbulatorioFacile');
                $mailer->setMailType('text');
                $mailer->setMessage($messageText);

                if ($mailer->send()) {
                    error_log('[OTP email] HTML send failed, text fallback succeeded for ' . $emailAddress . '. html_debug=' . ($htmlDebugMessage !== '' ? $htmlDebugMessage : 'n/a'));
                    return ['ok' => true, 'fallback' => 'text'];
                }

                $textDebugMessage = trim(strip_tags((string) $mailer->printDebugger(['headers', 'subject'])));
                $combinedDebug = 'html=' . ($htmlDebugMessage !== '' ? $htmlDebugMessage : 'n/a')
                    . ' | text=' . ($textDebugMessage !== '' ? $textDebugMessage : 'n/a');

                log_message('error', 'sendOtpEmailMessage failed for {email}. protocol={protocol} host={host} port={port} crypto={crypto} from={from}. Debugger: {debugger}', [
                    'email'    => $emailAddress,
                    'protocol' => (string)($config->protocol ?? ''),
                    'host'     => (string)($config->SMTPHost ?? ''),
                    'port'     => (string)($config->SMTPPort ?? ''),
                    'crypto'   => (string)($config->SMTPCrypto ?? ''),
                    'from'     => $fromEmail,
                    'debugger' => $combinedDebug,
                ]);
                error_log('[OTP email] send failure for ' . $emailAddress . ' | protocol=' . (string)($config->protocol ?? '') . ' host=' . (string)($config->SMTPHost ?? '') . ' port=' . (string)($config->SMTPPort ?? '') . ' crypto=' . (string)($config->SMTPCrypto ?? '') . ' | ' . $combinedDebug);
                return ['ok' => false, 'error' => $combinedDebug];
            }

            return ['ok' => true];
        } catch (\Throwable $e) {
            log_message('error', 'sendOtpEmailMessage ERROR for {email}. protocol={protocol} host={host} port={port} crypto={crypto} from={from}. Exception: {message}', [
                'email'    => $emailAddress,
                'protocol' => (string)($config->protocol ?? ''),
                'host'     => (string)($config->SMTPHost ?? ''),
                'port'     => (string)($config->SMTPPort ?? ''),
                'crypto'   => (string)($config->SMTPCrypto ?? ''),
                'from'     => $fromEmail ?? '',
                'message'  => $e->getMessage(),
            ]);
            error_log('[OTP email] exception for ' . $emailAddress . ' | protocol=' . (string)($config->protocol ?? '') . ' host=' . (string)($config->SMTPHost ?? '') . ' port=' . (string)($config->SMTPPort ?? '') . ' crypto=' . (string)($config->SMTPCrypto ?? '') . ' from=' . ($fromEmail ?? '') . ' | ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function buildOtpEmailPlainText(array $viewData): string
    {
        $lines = array_filter([
            trim((string) ($viewData['brandName'] ?? 'AmbulatorioFacile')),
            trim((string) ($viewData['notificationTitle'] ?? 'Codice OTP')),
            '',
            trim((string) ($viewData['greeting'] ?? 'Gentile utente,')),
            '',
            trim((string) ($viewData['notificationMessage'] ?? '')),
            '',
            trim((string) ($viewData['otpLabel'] ?? 'Il tuo codice OTP')) . ': ' . trim((string) ($viewData['otp'] ?? '')),
            trim((string) ($viewData['otpSecurityNote'] ?? '')),
            trim((string) ($viewData['otpValidityNote'] ?? '')),
            '',
            trim((string) ($viewData['ctaCaption'] ?? '')),
            '',
            trim((string) ($viewData['footerNote'] ?? '')),
        ], static fn(string $line): bool => $line !== '');

        return implode("\n", $lines);
    }

    private function buildOtpEmailViewData(string $otp, array $profileContext, string $fromName): array
    {
        $type = (string)($profileContext['type'] ?? '');
        $row = is_array($profileContext['row'] ?? null) ? $profileContext['row'] : [];
        $fullName = $this->composeOtpEmailFullName($row);
        $isClient = $type === 'client';

        $greeting = 'Gentile utente,';
        if ($isClient) {
            $greeting = $fullName !== '' ? "Gentile {$fullName}," : 'Gentile paziente,';
        } elseif ($fullName !== '') {
            $greeting = "Gentile {$fullName},";
        }

        return [
            'brandName' => trim($fromName) !== '' ? trim($fromName) : 'AmbulatorioFacile',
            'greeting' => $greeting,
            'notificationTitle' => 'Attiva le notifiche per ricevere gli OTP piu rapidamente',
            'notificationMessage' => $isClient
                ? 'Abbiamo notato che stai richiedendo il codice OTP via email. Ti invitiamo ad attivare le notifiche sul tuo smartphone: in questo modo potrai ricevere i prossimi codici direttamente come notifica, in modo piu rapido e immediato, e potrai anche ricevere un avviso quando il team risponde ai tuoi messaggi.'
                : 'Abbiamo notato che stai richiedendo il codice OTP via email. Per rendere i prossimi accessi piu rapidi e immediati, ti consigliamo di attivare le notifiche sul dispositivo che utilizzi abitualmente.',
            'ctaCaption' => "Per assistenza o per concordare l'attivazione, puoi rivolgerti direttamente alla struttura di riferimento.",
            'otpLabel' => 'Il tuo codice OTP',
            'otp' => trim($otp),
            'otpSecurityNote' => 'Non condividere questo codice con nessuno.',
            'otpValidityNote' => 'Il codice rimane valido per circa 2 minuti.',
            'footerNote' => 'Se non hai richiesto tu questo accesso, puoi ignorare questa email.',
        ];
    }

    private function composeOtpEmailFullName(array $row): string
    {
        $parts = array_filter([
            trim((string)($row['nome'] ?? '')),
            trim((string)($row['cognome'] ?? '')),
        ], static fn(string $value): bool => $value !== '');

        return trim(implode(' ', $parts));
    }

    private function verifyCurrentLoginPassword(string $password): bool
    {
        $userId = (int)(session()->get('userId') ?? 0);
        if ($userId <= 0 || $password === '') {
            return false;
        }

        $crypto = new Crypto_helper();
        $sql = "SELECT id_user
                FROM dap01_users
                WHERE id_user = ?
                  AND password = " . $crypto->encrypt_select_login('?') . "
                LIMIT 1";

        $row = $this->db->query($sql, [$userId, $password])->getRowArray();
        return !empty($row);
    }

    private function getCurrentProfileContext(int $userId): array
    {
        $clientModel = new ClientsModel();
        $client = $clientModel->getClientDecryptedByUserId($userId);
        if ($client) {
            return [
                'type'  => 'client',
                'row'   => $client,
                'email' => trim((string)($client['email'] ?? '')),
            ];
        }

        $personaleModel = new PersonaleModel();
        $personale = $personaleModel->getPersonaleDecryptedByUserId($userId);
        if ($personale) {
            return [
                'type'  => 'personale',
                'row'   => $personale,
                'email' => trim((string)($personale['email'] ?? '')),
            ];
        }

        return [
            'type'  => null,
            'row'   => null,
            'email' => '',
        ];
    }

    private function updateCurrentProfileEmail(int $userId, string $email): bool
    {
        $profile = $this->getCurrentProfileContext($userId);
        if ($profile['type'] === 'client') {
            return (new ClientsModel())->updateEmailByUserId($userId, $email);
        }

        if ($profile['type'] === 'personale') {
            return (new PersonaleModel())->updateEmailByUserId($userId, $email);
        }

        return false;
    }

    private function syncSessionEmail(string $email): void
    {
        $user = session()->get('utente_sess');
        if (is_object($user)) {
            $user->email = $email;
            session()->set('utente_sess', $user);
        }
    }

    private function maskEmail(string $email): string
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

    private function emailHashForStats(string $email): string
    {
        $email = $this->normalizeEmailForStats($email);
        if ($email === '') {
            return '';
        }

        return hash('sha256', $email);
    }

    private function normalizeEmailForStats(string $email): string
    {
        return strtolower(trim($email));
    }

    private function createDeviceLinkToken(int $userId): string
    {
        return $this->notifications->createLinkToken($userId, 10);
    }

    private function isCurrentRequestMobile(): bool
    {
        $ua = $this->request->getUserAgent();
        if ($ua->isMobile()) {
            return true;
        }

        $uaString = strtolower((string) $ua->getAgentString());
        if ($uaString !== '') {
            if (preg_match('/iphone|ipod|ipad|android.*mobile|mobile safari|mobi|opera mini|blackberry|windows phone|iemobile/', $uaString) === 1) {
                return true;
            }

            if (str_contains($uaString, 'macintosh') && str_contains($uaString, 'mobile')) {
                return true;
            }
        }

        $clientHintMobile = strtolower(trim((string) ($this->request->getServer('HTTP_SEC_CH_UA_MOBILE') ?? '')));

        return in_array($clientHintMobile, ['?1', '1', 'true'], true);
    }

    private function buildDeviceMeta(string $preferredLabel = ''): array
    {
        $ua = $this->request->getUserAgent();
        $uaString = $ua->getAgentString();
        $platform = (string)($ua->getPlatform() ?? '');
        $browser = (string)($ua->getBrowser() ?? '');
        $s = strtolower($uaString);

        $isTablet = preg_match('/ipad|tablet|sm\\-t|kindle|silk|playbook/', $s) === 1
            || (str_contains($s, 'android') && !str_contains($s, 'mobile'));
        $isPhone = !$isTablet && preg_match('/iphone|ipod|android.*mobile|mobile safari|mobi/', $s) === 1;
        $isMobile = $isPhone || $isTablet;

        $deviceName = trim($preferredLabel);
        if ($deviceName === '') {
            $deviceName = $isMobile ? 'Smartphone' : 'Browser';
        }

        return [
            'ua'           => $uaString,
            'browser'      => $browser,
            'device_os'    => $platform !== '' ? $platform : ($isMobile ? 'Mobile' : 'Desktop'),
            'device_type'  => $isPhone ? 'phone' : ($isTablet ? 'tablet' : 'desktop'),
            'is_mobile'    => $isMobile ? 1 : 0,
            'device_name'  => mb_substr($deviceName, 0, 100),
            'device_label' => mb_substr($deviceName, 0, 120),
            'device_brand' => null,
            'device_model' => null,
        ];
    }

    public function generateRandomCode($length = 4)
    {
        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= (string)random_int(0, 9);
        }
        return $otp;
    }
}
