<?php


namespace App\Controllers\Login;

use App\Controllers\BaseController;
use App\Libraries\Crypto_helper; // Importa la libreria
use App\Libraries\SystemUserMask;
use App\Models\ClientDoctorModel;
use App\Services\DemoAccessService;
use App\Services\LegacyTenantLoginService;
use App\Services\LegacyTenantSessionService;
use App\Services\PlatformAccessService;
use App\Services\PlatformAdminAccessService;
use App\Services\PlatformAuthService;
use App\Services\TenantLoginOtpService;
use App\Services\TenantAppSessionBootstrapService;

class LoginController extends BaseController
{
    private const PLATFORM_TENANT_SELECTION_SESSION_KEY = 'platform_pending_tenant_login';
    private const PLATFORM_TENANT_SELECTION_TTL_SECONDS = 600;
    private ?bool $legacyUsersTableAvailableCache = null;

    private function ensureLegacyCryptoSession(\CodeIgniter\Database\BaseConnection $db, string $charset = 'latin1'): void
    {
        if (isset($this->dbConfig) && $this->dbConfig instanceof \App\Libraries\DatabaseConfig) {
            $this->dbConfig->setEncryptionConfig($db, $charset);
            return;
        }

        (new \App\Libraries\DatabaseConfig())->setEncryptionConfig($db, $charset);
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<string, mixed> $context
     * @return \CodeIgniter\Database\BaseResult|\CodeIgniter\Database\Query|false
     */
    private function runQueryWithCryptoRecovery(
        \CodeIgniter\Database\BaseConnection $db,
        string $sql,
        array $params = [],
        array $context = [],
        string $charset = 'latin1'
    ) {
        $query = $db->query($sql, $params);
        if ($query !== false) {
            return $query;
        }

        $firstError = $db->error();
        $this->ensureLegacyCryptoSession($db, $charset);

        $query = $db->query($sql, $params);
        if ($query !== false) {
            return $query;
        }

        $secondError = $db->error();
        $this->logErrorLogin('Query database fallita anche dopo il ripristino della sessione crypto.', array_merge($context, [
            'db_error_code' => (string) ($firstError['code'] ?? ''),
            'db_error_message' => (string) ($firstError['message'] ?? ''),
            'retry_db_error_code' => (string) ($secondError['code'] ?? ''),
            'retry_db_error_message' => (string) ($secondError['message'] ?? ''),
        ]));

        return false;
    }

    private function storedPasswordLooksCorrupted(\CodeIgniter\Database\BaseConnection $db, int $idUser): bool
    {
        if ($idUser <= 0) {
            return false;
        }

        $sql = "SELECT CONVERT(CAST(AES_DECRYPT(UNHEX(password), @key_str, vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4) AS plain_pwd
                FROM dap01_users
                WHERE id_user = ?
                LIMIT 1";

        $query = $this->runQueryWithCryptoRecovery($db, $sql, [$idUser], [
            'query_name' => 'stored_password_plaintext_lookup',
            'id_user' => $idUser,
        ]);
        if ($query === false) {
            return false;
        }

        $row = $query->getRowArray();
        $plainPassword = trim((string)($row['plain_pwd'] ?? ''));
        if ($plainPassword === '') {
            return false;
        }

        return preg_match('/^[A-Fa-f0-9]{32,}$/', $plainPassword) === 1;
    }

    private function normalizeLegacyString($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    private function normalizeLegacyRowStrings(array $row, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $this->normalizeLegacyString($row[$key]);
            }
        }

        return $row;
    }

    private function logErrorLogin(string $reason, array $context = []): void
    {
        $context = array_merge([
            'ip'         => $this->request->getIPAddress(),
            'user_agent' => substr((string) ($this->request->getServer('HTTP_USER_AGENT') ?? ''), 0, 255),
        ], $context);

        log_message(
            'error',
            '[LoginController::login] errorLogin - ' . $reason . ' | context=' .
            json_encode($context, JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @return array<string, string>
     */
    private function demoProfileLabels(): array
    {
        return [
            'medical' => 'Percorso dietistica',
            'sport-rehab' => 'Percorso sport rehab',
        ];
    }

    private function sanitizeDemoUsername(string $username): string
    {
        $username = trim($username);
        if ($username === '') {
            return '';
        }

        if (strlen($username) > 120) {
            return '';
        }

        return preg_match('/^[A-Za-z0-9._@>\\-]+$/', $username) === 1 ? $username : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLoginViewData(): array
    {
        $profileLabels = $this->demoProfileLabels();
        $requestedProfile = strtolower(trim((string) $this->request->getGet('profile')));
        $requestedProfile = preg_replace('/[^a-z0-9\\-]/', '', $requestedProfile) ?? '';
        $rawProfileSlug = array_key_exists($requestedProfile, $profileLabels) ? $requestedProfile : '';
        $rawPrefillUsername = $this->sanitizeDemoUsername((string) $this->request->getGet('u'));
        $demoModeRequested = $this->request->getGet('demo') === '1' || $rawPrefillUsername !== '' || $rawProfileSlug !== '';
        $demoAccess = new DemoAccessService();
        $demoMode = $demoAccess->isLoginPrefillEnabled() && $demoModeRequested;
        $profileSlug = $demoMode ? $rawProfileSlug : '';
        $prefillUsername = $demoMode ? $rawPrefillUsername : '';

        if ($demoMode && $prefillUsername !== '') {
            $this->preparePrefilledDemoAccount($prefillUsername);
        }

        $demoOtpHint = in_array($prefillUsername, ['demo.dietista', 'demo.segreteria'], true)
            || str_contains($prefillUsername, '->');

        return [
            'demoMode' => $demoMode,
            'prefillUsername' => $prefillUsername,
            'demoProfileSlug' => $profileSlug,
            'demoProfileLabel' => $profileLabels[$profileSlug] ?? 'Demo AmbulatorioFacile',
            'demoOtpHint' => $demoOtpHint,
            'loginSuccess' => session()->getFlashdata('login_success'),
            'loginError' => session()->getFlashdata('login_error'),
        ];
    }

    private function preparePrefilledDemoAccount(string $requestedUsername): void
    {
        try {
            (new DemoAccessService())->preparePresentationAccount($requestedUsername);
        } catch (\Throwable $e) {
            log_message('warning', '[LoginController] Preparazione account demo fallita: {message}', [
                'message' => $e->getMessage(),
                'username' => $requestedUsername,
            ]);
        }
    }

    private function legacyUserExistsByUsername(\CodeIgniter\Database\BaseConnection $db, string $username): bool
    {
        $username = trim($username);
        if ($username === '') {
            return false;
        }

        if (!$this->legacyUsersTableAvailable($db)) {
            return false;
        }

        try {
            $query = $db->table('dap01_users')
                ->select('id_user')
                ->where('username', $username)
                ->get(1);

            if ($query === false) {
                $error = $db->error();
                $this->logErrorLogin('Lookup utente legacy fallito durante il pre-check login.', [
                    'username' => $username,
                    'db_error_code' => (string) ($error['code'] ?? ''),
                    'db_error_message' => (string) ($error['message'] ?? ''),
                ]);

                return false;
            }

            return $query->getRowArray() !== null;
        } catch (\Throwable $e) {
            $this->logErrorLogin('Lookup utente legacy fallito durante il pre-check login.', [
                'username' => $username,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function legacyUsersTableAvailable(\CodeIgniter\Database\BaseConnection $db): bool
    {
        if ($this->legacyUsersTableAvailableCache !== null) {
            return $this->legacyUsersTableAvailableCache;
        }

        try {
            $this->legacyUsersTableAvailableCache = $db->tableExists('dap01_users');
        } catch (\Throwable $e) {
            $this->logErrorLogin('Verifica tabella utenti legacy non riuscita.', [
                'exception' => $e->getMessage(),
            ]);
            $this->legacyUsersTableAvailableCache = false;
        }

        return $this->legacyUsersTableAvailableCache;
    }

    private function clearPendingPlatformLogin(): void
    {
        session()->remove(self::PLATFORM_TENANT_SELECTION_SESSION_KEY);
        (new PlatformAccessService())->clearPendingPasswordSetup();
        (new LegacyTenantSessionService())->clearAllPending();
    }

    private function shouldAttemptPlatformLogin(string $username, bool $legacyUserExists): bool
    {
        if (!str_contains($username, '@') || str_contains($username, '->')) {
            return false;
        }

        if (!$legacyUserExists) {
            return true;
        }

        return $this->platformUserExistsByEmail($username);
    }

    private function platformUserExistsByEmail(string $username): bool
    {
        $username = strtolower(trim($username));
        if ($username === '' || !filter_var($username, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            return model(\App\Models\PlatformUsersModel::class)
                ->findByEmailInsensitive($username) !== null;
        } catch (\Throwable $e) {
            log_message('error', 'LoginController::platformUserExistsByEmail failed: ' . $e->getMessage());
            return false;
        }
    }

    private function handlePlatformLogin(string $username, string $password)
    {
        helper('portal');
        $this->clearPendingPlatformLogin();

        try {
            $result = (new PlatformAuthService())->authenticate($username, $password);
        } catch (\Throwable $e) {
            log_message('error', 'LoginController::handlePlatformLogin platform auth failed: {message}', [
                'message' => $e->getMessage(),
                'username' => $username,
            ]);

            return $this->response->setJSON([
                'resp' => 'KO',
                'success' => false,
                'message' => 'Login unico temporaneamente non disponibile. Riprova tra poco.',
            ])->setStatusCode(503);
        }

        if ($result === null) {
            return null;
        }

        $platformUser = (array) ($result['platform_user'] ?? []);
        $selectableTenants = (array) ($result['selectable_tenants'] ?? []);
        $platformUserId = (int) ($platformUser['id_platform_user'] ?? 0);

        if ($platformUserId <= 0) {
            return $this->response->setJSON([
                'resp' => 'KO',
                'success' => false,
                'message' => 'Account piattaforma non valido.',
            ])->setStatusCode(403);
        }

        if ((int) ($platformUser['must_reset_password'] ?? 0) === 1) {
            (new PlatformAccessService())->storePendingPasswordSetup($platformUser, $selectableTenants);

            return $this->response->setJSON([
                'resp' => 'PASSWORD_SETUP_REQUIRED',
                'success' => true,
                'redirectUrl' => portal_public_access_url('login/password-imposta'),
            ]);
        }

        $platformAdminAccess = new PlatformAdminAccessService();
        if ($platformAdminAccess->isPlatformAdmin($platformUser)) {
            $platformAdminAccess->bootstrapSession($platformUser, (array) ($result['memberships'] ?? []));

            return $this->response->setJSON([
                'resp' => 'OK',
                'success' => true,
                'redirectUrl' => portal_platform_url('spazi-clienti'),
            ]);
        }

        if ($selectableTenants === []) {
            return $this->response->setJSON([
                'resp' => 'KO',
                'success' => false,
                'message' => 'Nessuno spazio cliente disponibile per questo account.',
            ])->setStatusCode(403);
        }

        if (count($selectableTenants) === 1) {
            try {
                $tenantId = (int) ($selectableTenants[0]['id_tenant'] ?? 0);
                $bootstrap = (new TenantAppSessionBootstrapService())->bootstrap($platformUserId, $tenantId);
                return $this->response->setJSON([
                    'resp' => 'OK',
                    'success' => true,
                    'redirectUrl' => (string) ($bootstrap['redirectUrl'] ?? '/'),
                ]);
            } catch (\Throwable $e) {
                $this->clearPendingPlatformLogin();
                return $this->response->setJSON([
                    'resp' => 'KO',
                    'success' => false,
                    'message' => $e->getMessage(),
                ])->setStatusCode(403);
            }
        }

        session()->set(self::PLATFORM_TENANT_SELECTION_SESSION_KEY, [
            'platform_user_id' => $platformUserId,
            'platform_user_email' => (string) ($platformUser['email'] ?? ''),
            'tenant_ids' => array_values(array_map(static fn(array $tenant): int => (int) ($tenant['id_tenant'] ?? 0), $selectableTenants)),
            'created_at' => time(),
        ]);

        return $this->response->setJSON([
            'resp' => 'TENANT_SELECT',
            'success' => true,
            'message' => 'Seleziona lo spazio cliente.',
            'tenants' => $selectableTenants,
        ]);
    }

    public function selectTenant()
    {
        $payload = $this->request->getJSON();
        $tenantId = (int) (($payload->tenant_id ?? 0));
        $pending = session()->get(self::PLATFORM_TENANT_SELECTION_SESSION_KEY);

        if ($tenantId <= 0) {
            return $this->response->setJSON([
                'resp' => 'KO',
                'success' => false,
                'message' => 'Selezione studio non valida.',
            ])->setStatusCode(400);
        }

        if (!is_array($pending)) {
            try {
                $legacySelection = (new LegacyTenantLoginService())->completePendingSelection($tenantId);
                if ($legacySelection !== null) {
                    return $this->response->setJSON($legacySelection);
                }
            } catch (\Throwable $e) {
                (new LegacyTenantSessionService())->clearAllPending();

                return $this->response->setJSON([
                    'resp' => 'KO',
                    'success' => false,
                    'message' => $e->getMessage(),
                ])->setStatusCode(403);
            }

            return $this->response->setJSON([
                'resp' => 'KO',
                'success' => false,
                'message' => 'Selezione studio non valida.',
            ])->setStatusCode(400);
        }

        $createdAt = (int) ($pending['created_at'] ?? 0);
        if ($createdAt <= 0 || (time() - $createdAt) > self::PLATFORM_TENANT_SELECTION_TTL_SECONDS) {
            $this->clearPendingPlatformLogin();
            return $this->response->setJSON([
                'resp' => 'KO',
                'success' => false,
                'message' => 'La selezione dello spazio e scaduta. Effettua di nuovo il login.',
            ])->setStatusCode(440);
        }

        $allowedTenantIds = array_map('intval', (array) ($pending['tenant_ids'] ?? []));
        if (!in_array($tenantId, $allowedTenantIds, true)) {
            return $this->response->setJSON([
                'resp' => 'KO',
                'success' => false,
                'message' => 'Spazio cliente non autorizzato.',
            ])->setStatusCode(403);
        }

        try {
            $bootstrap = (new TenantAppSessionBootstrapService())->bootstrap(
                (int) ($pending['platform_user_id'] ?? 0),
                $tenantId
            );
            $this->clearPendingPlatformLogin();

            return $this->response->setJSON([
                'resp' => 'OK',
                'success' => true,
                'redirectUrl' => (string) ($bootstrap['redirectUrl'] ?? '/'),
            ]);
        } catch (\Throwable $e) {
            $this->clearPendingPlatformLogin();
            return $this->response->setJSON([
                'resp' => 'KO',
                'success' => false,
                'message' => $e->getMessage(),
            ])->setStatusCode(403);
        }
    }

    public function switchTenant(int $tenantId = 0)
    {
        helper('portal');
        $platformUserId = (int) (session()->get('platform_user_id') ?? 0);
        if ($platformUserId <= 0 || $tenantId <= 0) {
            return redirect()->to($this->postLoginFallbackUrl())->with('error', 'Cambio spazio non disponibile.');
        }

        try {
            $bootstrap = (new TenantAppSessionBootstrapService())->bootstrap($platformUserId, $tenantId);
            return redirect()->to(portal_resolve_redirect_url((string) ($bootstrap['redirectUrl'] ?? '/')));
        } catch (\Throwable $e) {
            return redirect()->to($this->postLoginFallbackUrl())->with('error', $e->getMessage());
        }
    }

    public function index()
    {
        // Controlla se la sessione è già attiva
    /*    if (session()->get('isLoggedIn')) {
            return redirect()->to('/');
        }*/

        // Mostra la pagina di login
        helper('portal');

        // /login deve restare sempre raggiungibile anche con sessione attiva:
        // serve per cambiare account e per evitare rimbalzi fuorvianti verso la home.

        return view('login/login', $this->buildLoginViewData());
    }

    public function login()
    {
        $username = '';

        try {
        $db = $this->db ?? \Config\Database::connect();
        $this->ensureLegacyCryptoSession($db);
        $credentials = $this->request->getJSON(); // Recupera le credenziali inviate tramite POST
        $crypto_helper = new Crypto_helper();
        $this->clearPendingPlatformLogin();
session()->remove('is_admin_arrow_login');
session()->remove('admin_arrow_username');
session()->remove('isLoggedIn');
session()->remove('isLoggedInConfirmed');
session()->remove('is_admin');
session()->remove('admin');
session()->remove('userId');
session()->remove('id_user');
session()->remove('username');
session()->remove('tipoUser');
session()->remove('utente_sess');
session()->remove('nome_visualizzato');
session()->remove('cellulare');
session()->remove('menuData');
session()->remove('menuAgenda');
session()->remove('menuDataAdmin');
session()->remove('tenant_app_admin');
session()->remove('header_nav_items');
session()->remove('header_menu_items');
session()->remove('badge_posta_unread');
session()->remove('badge_chat_unread');
session()->remove('nav_refresh_meta');
session()->remove('schede_access_map');
session()->remove('schede_data');
session()->remove('otp_identity');
session()->remove(\App\Services\TenantLoginOtpService::SESSION_KEY_REQUIRED);
session()->remove(\App\Services\TenantContextService::SESSION_KEY);
session()->remove('platform_user_id');
session()->remove('platform_user_email');
session()->remove('platform_is_admin');
session()->remove(\App\Services\TenantAppSessionBootstrapService::PLATFORM_SELECTABLE_TENANTS_SESSION_KEY);
session()->remove(\App\Services\PlatformAccessService::SESSION_KEY_PENDING_PASSWORD_SETUP);
        if (!$credentials || !isset($credentials->username) || !isset($credentials->password)) {
            $this->logErrorLogin('Richiesta login non valida: payload JSON assente o incompleto. Il frontend mostrera errorLogin perche non ci sono username/password utilizzabili.');
            return $this->response->setJSON([
                'resp'    => 'KO',
                'success' => false,
                'message' => 'Dati login non validi'
            ])->setStatusCode(400);
        }

        $username = trim((string) $credentials->username);
        $password = (string) $credentials->password;

        if ($username === '' || $password === '') {
            $this->logErrorLogin('Credenziali vuote: username o password non valorizzati. Il login viene bloccato prima della query.', [
                'username' => $username,
                'has_password' => ($password !== ''),
            ]);
            return $this->response->setJSON([
                'resp'    => 'KO',
                'success' => false,
                'message' => 'Username e password sono obbligatori'
            ])->setStatusCode(400);
        }

        $legacyUserExists = $this->legacyUserExistsByUsername($db, $username);
        $platformUserExists = $this->platformUserExistsByEmail($username);
        if ($this->shouldAttemptPlatformLogin($username, $legacyUserExists)) {
            $platformResponse = $this->handlePlatformLogin($username, $password);
            if ($platformResponse !== null) {
                return $platformResponse;
            }

            if ($platformUserExists) {
                return $this->response->setJSON([
                    'resp' => 'KO',
                    'success' => false,
                    'message' => 'Credenziali non valide per il login unico. Usa la password aggiornata del tuo account piattaforma.',
                ])->setStatusCode(401);
            }
        }

        if (strpos($username, '->') !== false) {
            session()->set('is_admin_arrow_login', true);
            session()->set('admin_arrow_username', $username);

            $usernames = explode('->', $username); 
            if (count($usernames) !== 2 || trim($usernames[0]) === '' || trim($usernames[1]) === '') {
                $this->logErrorLogin('Formato login delegato non valido: atteso "utenteAdmin->utente". Impossibile identificare correttamente utente amministratore e utente host.', [
                    'username' => $username,
                ]);
                return $this->response->setJSON([
                    'resp'    => 'KO',
                    'success' => false,
                    'message' => 'Formato username non valido'
                ])->setStatusCode(400);
            }

            $usernamesArray = array(
                'username_adm' => trim($usernames[0]),
                'username_host' => trim($usernames[1])
            );
        }
        else {
            session()->remove('is_admin_arrow_login');
            session()->remove('admin_arrow_username');
        }
        // La query SQL per verificare se l'utente esiste e la password corrisponde
        $sql = "SELECT a.*, 
               CASE WHEN datascadenza <= NOW() THEN 'SCADENZA' ELSE 'OK' END AS resp 
        FROM dap01_users a 
        WHERE username = ? 
        AND password = ".$crypto_helper->encrypt_select_login('?')."
        LIMIT 1";

            // Cripta la password usando il tuo helper
           // $encryptedPassword = $crypto_helper->encrypt_select($password);

            // Esegui la query con i parametri username e password criptata
            if (strpos($username, '->') !== false) {
                $query = $this->runQueryWithCryptoRecovery($db, $sql, [
                    $usernamesArray['username_adm'],  // Passa il parametro direttamente
                    $password                // Passa la password criptata
                ], [
                    'query_name' => 'legacy_login_credentials_check',
                    'login_mode' => 'admin_arrow',
                    'username' => $usernamesArray['username_adm'],
                ]);
            } else {
                $query = $this->runQueryWithCryptoRecovery($db, $sql, [
                    $username,                         // Passa il parametro direttamente
                    $password                    // Passa la password criptata
                ], [
                    'query_name' => 'legacy_login_credentials_check',
                    'login_mode' => 'standard',
                    'username' => $username,
                ]);
            }

            if ($query === false) {
                return $this->response->setJSON([
                    'resp'    => 'KO',
                    'success' => false,
                    'message' => 'Accesso momentaneamente non disponibile. Riprova tra poco.'
                ])->setStatusCode(503);
            }

            $user = $query->getRowArray();
 // Recupera il risultato della query
       // die( $sql   );
        // Verifica se l'utente è stato trovato

        if ($user) {
           
            session()->set('userId', $user['id_user']);
            session()->set('username', $user['username']);
            session()->set('tipoUser', $user['tipo_user']);
            if($user['resp']=="OK")
            {
                session()->set('isLoggedIn', true);
                session()->set('userId', $user['id_user']);
                session()->set('username', $user['username']);
                session()->set('tipoUser', $user['tipo_user']);

                if (strpos($username, '->') !== false) {
			
                    $sql_host="SELECT  a.* FROM  dap01_users a where a.username =:username: ";
                   
                    $query_host = $db->query($sql_host, [
                        'username' =>$usernamesArray['username_host'],
                    ]);

                    $user_host = $query_host->getRowArray(); // Recupera il risultato della query
                    
                    if($user_host)
                    {
                        session()->set('isLoggedIn', true);
                        session()->set('userId', $user_host['id_user']);
                        session()->set('username', $user_host['username']);
                        session()->set('tipoUser', $user_host['tipo_user']);
                    }
                               	
                    }
                    //die($user_host['tipo_user']);
                    if(session()->get('tipoUser')==1)
                    {
                        //AMMINISTRATORE
                        $sql_admin="SELECT  a.tipo,a.id_user,a.id_personale,".$crypto_helper->decrypt("a.nome")."
                        ,".$crypto_helper->decrypt("a.cognome")."
                        ,".$crypto_helper->decrypt("a.cellulare").",".$crypto_helper->decrypt("a.email")."
                        ,".$crypto_helper->decrypt("a.qualifica")."
                        ,CONCAT(".$crypto_helper->decrypt_concat("a.qualifica").",' ',".
                        $crypto_helper->decrypt_concat("a.cognome").",' ',".$crypto_helper->decrypt_concat("a.nome").") as nome_completo 
                        FROM  dap03_personale a where id_user=?";
                        $query = $db->query($sql_admin, [
                            session()->get('userId')
                        ]);
                        $admin = $query->getRowArray();
                        if (is_array($admin)) {
                            $admin = $this->normalizeLegacyRowStrings($admin, ['nome', 'cognome', 'cellulare', 'email', 'qualifica', 'nome_completo']);
                        }
                        if($admin)
                        {
                        $obj = new \stdClass();
                        $obj->id_user = $admin['id_user'];
                        $obj->id_personale = $admin['id_personale'];
                        $obj->id_utente = $admin['id_personale'];
                        $obj->nome = $admin['nome'];
                        $obj->cognome = $admin['cognome'];
                        $obj->cellulare = $admin['cellulare'];
                        $obj->email = $admin['email'];
                        $obj->qualifica = $admin['qualifica'];
                        $obj->nome_completo = $admin['nome_completo'];
                        $obj->tipo = session()->get('tipoUser');
                        $obj->tipo_pers = $admin['tipo'];
                        $obj->da_dottore = 0;
                        $obj->tabella = "dap10_message";
                        $obj->tabella_reply = "dap10_message_reply";
                        
                        session()->set('nome_visualizzato',  $obj->nome." ".$obj->cognome);
                        session()->set('cellulare',  $obj->cellulare);
                        session()->set('admin', 1);
                        session()->set('utente_sess', $obj);
                        if ((int)session()->get('tipoUser') === 1) {
                            session()->set('is_admin', true);

                            $adminMenuModel = new \App\Models\AdminMenuModel();
                            $menuAdmin = $adminMenuModel->getAdminMenu();

                            session()->set('menuDataAdmin', [
                                'result' => $menuAdmin,
                            ]);

                            session()->remove('requireDoctorSelection');
                            session()->remove('menuData');
                            session()->remove('menuAgenda');

                            $this->queueLegacyRuntimeTenantForCurrentDatabase(false);
                            $otpRequired = (new TenantLoginOtpService())->syncCurrentSessionRequirement();

                            if (!$otpRequired) {
                                session()->set('isLoggedInConfirmed', true);
                                (new LegacyTenantSessionService())->activatePendingRuntime();

                                return $this->response->setJSON([
                                    'resp'        => 'OK',
                                    'success'     => true,
                                    'redirectUrl' => $this->legacyAdminPostLoginRedirectUrl()
                                ]);
                            }

                            return $this->response->setJSON([
                                'resp'        => 'OK',
                                'success'     => true,
                                'redirectUrl' => 'auth'
                            ]);
                        }

                        }

                      
                    }
                    else  if(session()->get('tipoUser')==2)
                    {
                        $sql="SELECT  a.sostituto,a.tipo,CASE WHEN a.tipo=1 then 'P' WHEN a.tipo=2 then 'I' WHEN a.tipo=3 
                        then 'S' else '' end as tipo_stringa,a.id_user,a.id_personale,".$crypto_helper->decrypt("a.nome").",
                        ".$crypto_helper->decrypt("a.cognome")."
                            ,".$crypto_helper->decrypt("a.cellulare").",
                            ".$crypto_helper->decrypt("a.email").",".$crypto_helper->decrypt("a.qualifica")."
                            ,CONCAT(".$crypto_helper->decrypt_concat("a.qualifica").",' ',".$crypto_helper->decrypt_concat("a.cognome").",' ',
                            ".$crypto_helper->decrypt_concat("a.nome").") as nome_completo FROM  dap03_personale a where id_user=?
                                and (((titolare=1 and sostituto=0) or (titolare=0 and sostituto=1) or (titolare=1 and sostituto=1) and a.tipo=1) 
                            or ( a.tipo!=1 and titolare=0 and sostituto=0))";
                            
                            $query = $db->query($sql, [
                                session()->get('userId')
                            ]);
                            $doctor = $query->getRowArray();
                            if (!$doctor) {
                                $fallbackSql = "SELECT  a.sostituto,a.tipo,CASE WHEN a.tipo=1 then 'P' WHEN a.tipo=2 then 'I' WHEN a.tipo=3
                                then 'S' else '' end as tipo_stringa,a.id_user,a.id_personale,".$crypto_helper->decrypt("a.nome").",
                                ".$crypto_helper->decrypt("a.cognome")."
                                    ,".$crypto_helper->decrypt("a.cellulare").",
                                    ".$crypto_helper->decrypt("a.email").",".$crypto_helper->decrypt("a.qualifica")."
                                    ,CONCAT(".$crypto_helper->decrypt_concat("a.qualifica").",' ',".$crypto_helper->decrypt_concat("a.cognome").",' ',
                                    ".$crypto_helper->decrypt_concat("a.nome").") as nome_completo
                                FROM dap03_personale a
                                WHERE id_user=?
                                LIMIT 1";
                                $doctor = $db->query($fallbackSql, [
                                    session()->get('userId')
                                ])->getRowArray();
                            }
                            if (is_array($doctor)) {
                                $doctor = $this->normalizeLegacyRowStrings($doctor, ['nome', 'cognome', 'cellulare', 'email', 'qualifica', 'nome_completo']);
                            }
                            if($doctor)
                            {
                                $obj = new \stdClass();
                                $obj->id_user = $doctor['id_user'];
                                $obj->id_personale = $doctor['id_personale'];
                                $obj->id_utente = $doctor['id_personale'];
                                $obj->nome = $doctor['nome'];
                                $obj->cognome = $doctor['cognome'];
                                $obj->cellulare = $doctor['cellulare'];
                                $obj->email = $doctor['email'];
                                $obj->qualifica = $doctor['qualifica'];
                                $obj->nome_completo = $doctor['nome_completo'];
                                $obj->tipo = session()->get('tipoUser');
                                $obj->tipo_pers = $doctor['tipo'];
                                $obj->tipo_stringa = $doctor['tipo_stringa'];
                                $obj->sostituto = $doctor['sostituto'];
                                $obj->da_dottore = 0;
                                $obj->tabella = "dap10_message";
                                $obj->tabella_reply = "dap10_message_reply";
                                session()->set('nome_visualizzato',  $obj->nome_completo);
                                session()->set('cellulare',  $obj->cellulare);
                                session()->set('utente_sess', $obj);
                                log_message('error', 'LOGIN personale session built: sessionUserId={sessionUserId}, tipoUser={tipoUser}, idUser={idUser}, idPersonale={idPersonale}, idUtente={idUtente}, tipoPers={tipoPers}, tipoStringa={tipoStringa}, nome={nome}', [
                                    'sessionUserId' => (int)(session()->get('userId') ?? 0),
                                    'tipoUser'      => (int)(session()->get('tipoUser') ?? 0),
                                    'idUser'        => (int)($obj->id_user ?? 0),
                                    'idPersonale'   => (int)($obj->id_personale ?? 0),
                                    'idUtente'      => (int)($obj->id_utente ?? 0),
                                    'tipoPers'      => (int)($obj->tipo_pers ?? 0),
                                    'tipoStringa'   => (string)($obj->tipo_stringa ?? ''),
                                    'nome'          => (string)($obj->nome_completo ?? ''),
                                ]);
                            }

                    }
                    else  if(session()->get('tipoUser')==3)
                    {
                        $sql="SELECT  a.id_user,a.id_client,a.id_personale,".$crypto_helper->decrypt("a.nome").",
                        ".$crypto_helper->decrypt("a.cognome")."
                        ,".$crypto_helper->decrypt("a.cellulare").",".$crypto_helper->decrypt("a.email").",
                        ".$crypto_helper->decrypt("a.indirizzo")."
                        ,".$crypto_helper->decrypt("a.citta").",".$crypto_helper->decrypt("a.provincia").",
                        ".$crypto_helper->decrypt("a.codice_fiscale")."			
                        FROM dap02_clients a
                        WHERE a.id_user=?
                        LIMIT 1";
                        $query = $db->query($sql, [
                            session()->get('userId')
                        ]);
                        $client = $query->getRowArray();
                        if (is_array($client)) {
                            $client = $this->normalizeLegacyRowStrings($client, ['nome', 'cognome', 'cellulare', 'email', 'indirizzo', 'citta', 'provincia', 'codice_fiscale']);
                        }
                       
                        if($client)
                        {
                            $clientDoctorModel = new ClientDoctorModel();
                            $doctorLink = $clientDoctorModel->getPreferredDoctorLinkForClient(
                                (int)$client['id_client'],
                                (int)($client['id_personale'] ?? 0)
                            );
                            if ((int)($doctorLink['relation_count'] ?? 0) > 1) {
                                log_message('warning', '[LoginController::login] Relazioni duplicate in dap09_client_doctor per id_client={idClient}; uso id_dot={idDot} source={source}', [
                                    'idClient' => (int)$client['id_client'],
                                    'idDot' => (int)($doctorLink['id_dot'] ?? 0),
                                    'source' => (string)($doctorLink['source'] ?? 'none'),
                                ]);
                            }

                            $obj = new \stdClass();
                            $obj->id_user = $client['id_user'];
                            $obj->id_client = $client['id_client'];
                            $obj->nome = $client['nome'];
                            $obj->cognome = $client['cognome'];
                            $obj->cellulare = $client['cellulare'];
                            $obj->email = $client['email'];
                            $obj->indirizzo = $client['indirizzo'];
                            $obj->citta = $client['citta'];
                            $obj->provincia =$client['provincia'];
                            $obj->codice_fiscale = $client['codice_fiscale'];
                            $obj->id_doctor = (int)($doctorLink['id_dot'] ?? 0);
                            $obj->tipo = session()->get('tipoUser');
                            $obj->tabella = " dap10_message";
                            $obj->tabella_reply = " dap10_message_reply";
                            $obj->da_dottore = 1;
                            $obj->id_utente = $client['id_client'];
                            if (SystemUserMask::isMaskedClientId((int) $obj->id_client)) {
                                $obj->nome = SystemUserMask::SYSTEM_USER_LABEL;
                                $obj->cognome = '';
                                $obj->nome_completo = SystemUserMask::SYSTEM_USER_LABEL;
                            }
                            $displayName = SystemUserMask::getMaskedClientDisplayName(
                                (int) $obj->id_client,
                                trim($obj->nome . " " . $obj->cognome)
                            );
                            session()->set('nome_visualizzato',  $displayName);
                            session()->set('cellulare',  $obj->cellulare);
                            
                            session()->set('utente_sess', $obj);  
                        }
                    }
                    else  if(session()->get('tipoUser')==4)
                    {
                        $query="SELECT  a.*	FROM  dap13_seg a where id_user=:id_user";
                        $query = $db->query($sql, [
                            'id_user' =>session()->get('userId'),
                        ]);
                        $seg = $query->getRowArray(); 
                        if($seg)
                        {
                            $obj = new \stdClass();
                            $obj->id_user = $seg['id_user'];
                            $obj->nome =$seg['nome'];
                            $obj->cognome = $seg['cognome'];
                            $obj->id_inf = $seg['id_inf'];
                            $obj->tipo = session()->get('tipoUser');
                            $obj->tabella = " dap10_message_seg";
                            $obj->tabella_reply = " dap10_message_reply_seg";
                            $obj->da_dottore = 0;
                        
                            $query="SELECT id_dot as id_personale FROM  dap14_seg_dot where id_inf=:id_inf";
                            $query = $db->query($sql, [
                                'id_inf' =>$obj->id_inf,
                            ]);
                            $id_personale = "";

                            if ($query->getNumRows() > 0) {  // Verifica che ci siano risultati
                                foreach ($query->getResult() as $row) {  // usa getResult per ottenere i risultati
                                    if (!empty($id_personale)) {
                                        $id_personale .= ",";
                                    }
                                    $id_personale .= $row->id_personale;
                                }
                            }
                            $obj->id_personale = "(".substr($id_personale,1).")";
                            session()->set('utente_sess', $obj);  

                        }

                    }

                    if (strpos($username, '->') !== false) {
                        $obj = session()->get('utente_sess');
                        $queryAdminCellSql = "SELECT ".$crypto_helper->decrypt("b.cellulare")."
                        FROM dap01_users a, dap03_personale b
                        WHERE a.username = :username:
                          AND a.id_user = b.id_user";

                        $queryAdminCell = $db->query($queryAdminCellSql, [
                            'username' => $usernamesArray['username_adm'],
                        ]);
                        $client = $queryAdminCell->getRowArray();
                        if (is_array($client)) {
                            $client = $this->normalizeLegacyRowStrings($client, ['cellulare']);
                        }

                        if ($client && !empty($client['cellulare'])) {
                            if (is_object($obj)) {
                                $obj->cellulare = $client['cellulare'];
                                session()->set('utente_sess', $obj);
                            }

                            session()->set('cellulare', $client['cellulare']);
                        }
                        }

                        $this->queueLegacyRuntimeTenantForCurrentDatabase(false);
                        
                        return $this->response->setJSON([
                            'resp' => 'OK',
                            'success' => true,
                            'redirectUrl' => 'auth'
                        ]);
            }
            else if($user['resp']=="SCADENZA")
            {

                 session()->set('isLoggedIn', true);

                // forza cambio password
                session()->set('forcePwdChange', 1);
                session()->set('pwd_expired_flow', 1);
                session()->set('pwd_userId', (int)$user['id_user']);   // IMPORTANTISSIMO
                session()->set('pwd_username', (string)$user['username']);
                session()->remove('otp_ok_for_expired');
                session()->remove('otp');

                if (session()->get('tipoUser') == 3) {
                    log_message('info', 'Utente di tipo 3, ricerca in dap02_clients');
                    $sql = "SELECT ".$crypto_helper->decrypt("b.cellulare")." FROM dap02_clients b WHERE b.id_user = " . session()->get('userId') . " LIMIT 1";
                    log_message('info', 'Esecuzione query: ' . $sql);
                    $query = $this->db->query($sql);
                    $utentePresente = $query->getRow();
                    
                } else {
                    log_message('info', 'Utente di altro tipo, ricerca in dap03_personale');
                    $sql = "SELECT ".$crypto_helper->decrypt("b.cellulare")." FROM dap03_personale b WHERE b.id_user = " . session()->get('userId') . " LIMIT 1";
                    log_message('info', 'Esecuzione query: ' . $sql);
                    $query = $this->db->query($sql);
                    $utentePresente = $query->getRow();
                }
                session()->set('cellulare',  $utentePresente->cellulare);
                $this->queueLegacyRuntimeTenantForCurrentDatabase(false);
                   return $this->response->setJSON([
                            'resp'        => 'SCADENZA',
                            'success'     => true,
                            'redirectUrl' => 'auth'
                        ]);
            }
            else if($user['resp']=="SOST")
            {

                    return $this->response->setJSON([
                            'resp' => 'SOST',
                            'success' => true
                        ]);
            }
           

        } else {

             $loginUsername = (strpos($username, '->') !== false)
                ? ($usernamesArray['username_adm'] ?? $username)
                : $username;

            if (strpos($username, '->') === false) {
                $legacyTenantLogin = (new LegacyTenantLoginService())->authenticate($loginUsername, $password);
                if ($legacyTenantLogin !== null) {
                    return $this->response->setJSON($legacyTenantLogin);
                }
            }

             $existingUser = $db->table('dap01_users')
                ->select('id_user, tipo_user, datascadenza')
                ->where('username', $loginUsername)
                ->get(1)
                ->getRowArray();

             if ($existingUser) {
                 $storedPasswordCorrupted = $this->storedPasswordLooksCorrupted($db, (int) ($existingUser['id_user'] ?? 0));

                 if ($storedPasswordCorrupted) {
                     $this->logErrorLogin('Credenziali bloccate: la password salvata sembra un vecchio valore cifrato/da reimpostare. Suggerire reset password all utente.', [
                         'username'     => $loginUsername,
                         'id_user'      => (int) ($existingUser['id_user'] ?? 0),
                         'tipo_user'    => (int) ($existingUser['tipo_user'] ?? 0),
                         'datascadenza' => (string) ($existingUser['datascadenza'] ?? ''),
                     ]);

                     return $this->response->setJSON([
                        'resp'    => 'RESET_REQUIRED',
                        'success' => false,
                        'message' => 'La password di questo account deve essere reimpostata. Usa "Password Dimenticata?" oppure chiedi all amministratore di impostarne una nuova.'
                    ]);
                 }

                 $this->logErrorLogin('Credenziali non valide: username presente in dap01_users ma la password non corrisponde alla password cifrata salvata. Per il frontend il caso resta "username/password errati oppure utente non autorizzato".', [
                     'username'     => $loginUsername,
                     'id_user'      => (int) ($existingUser['id_user'] ?? 0),
                     'tipo_user'    => (int) ($existingUser['tipo_user'] ?? 0),
                     'datascadenza' => (string) ($existingUser['datascadenza'] ?? ''),
                 ]);
             } else {
                 $this->logErrorLogin('Credenziali non valide: username non trovato in dap01_users. Per il frontend il caso resta "username/password errati oppure utente non autorizzato".', [
                     'username' => $loginUsername,
                 ]);
             }

             return $this->response->setJSON([
                'resp'    => 'KO',
                'success' => false,
                'message' => 'Username o password errati oppure non autorizzati'
            ]);
          
        }
    }  catch (\Throwable $e) {
        $this->logErrorLogin('Eccezione durante il login: il frontend ricevera errore server e puo mostrare errorLogin. Dettaglio: ' . $e->getMessage(), [
            'username' => $username,
            'file'     => $e->getFile(),
            'line'     => $e->getLine(),
        ]);

        $payload = [
            'resp'    => 'KO',
            'success' => false,
            'message' => 'Errore temporaneo durante il login. Riprova tra poco.'
        ];

        if ((string) env('CI_ENVIRONMENT', '') === 'development') {
            $payload['error'] = $e->getMessage();
        }

        return $this->response->setJSON($payload)->setStatusCode(500);
    }
    }

    public function logout()
    {
        // Distruggi la sessione per fare il logout
        $this->clearPendingPlatformLogin();
        session()->destroy();
        return redirect()->to('/login');
    }

    private function queueLegacyRuntimeTenantForCurrentDatabase(bool $activateNow): void
    {
        $appUserId = (int) (session()->get('userId') ?? session()->get('id_user') ?? 0);
        $userType = (int) (session()->get('tipoUser') ?? 0);

        if ($appUserId <= 0 || $userType <= 0) {
            return;
        }

        try {
            (new LegacyTenantSessionService())->queueCurrentRuntimeTenantIfAvailable($appUserId, $userType, $activateNow);
        } catch (\Throwable $e) {
            log_message('warning', 'LoginController::queueLegacyRuntimeTenantForCurrentDatabase failed: {message}', [
                'message' => $e->getMessage(),
                'app_user_id' => $appUserId,
                'tipo_user' => $userType,
            ]);
        }
    }

    private function postLoginFallbackUrl(): string
    {
        helper('portal');

        if ((bool) (session()->get('platform_is_admin') ?? false) === true || $this->isLegacyPlatformBootstrapSession()) {
            return portal_platform_url('spazi-clienti');
        }

        return site_url('/');
    }

    private function legacyAdminPostLoginRedirectUrl(): string
    {
        helper(['portal', 'session_auth']);

        if ($this->shouldBootstrapLegacyAdminToPlatformConsole()) {
            return portal_platform_url('spazi-clienti');
        }

        if (session_should_open_agenda_first()) {
            return 'agenda';
        }

        return 'admin';
    }

    private function shouldBootstrapLegacyAdminToPlatformConsole(): bool
    {
        if ((int) (session()->get('tipoUser') ?? 0) !== 1) {
            return false;
        }

        if (session()->get(\App\Services\TenantContextService::SESSION_KEY)) {
            return false;
        }

        return $this->legacyPlatformBootstrapModeEnabled();
    }

    private function isLegacyPlatformBootstrapSession(): bool
    {
        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return false;
        }

        $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) {
            return false;
        }

        $isLegacyAdmin = session()->get('is_admin') === true
            || (int) (session()->get('admin') ?? 0) === 1
            || (int) ($me->tipo ?? 0) === 1
            || (int) (session()->get('tipoUser') ?? 0) === 1;

        if (!$isLegacyAdmin) {
            return false;
        }

        if (session()->get(\App\Services\TenantContextService::SESSION_KEY)) {
            return false;
        }

        return $this->legacyPlatformBootstrapModeEnabled();
    }

    private function legacyPlatformBootstrapModeEnabled(): bool
    {
        $platformAdminAccess = new PlatformAdminAccessService();
        return !$platformAdminAccess->hasPersistentPlatformAdmins();
    }
}
