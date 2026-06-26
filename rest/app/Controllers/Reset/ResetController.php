<?php

namespace App\Controllers\Reset;

use App\Controllers\BaseController;
use App\Models\UsersModel;
use App\Models\ClientsModel;
use App\Models\DoctorModel;
use App\Models\ClientDoctorModel;
use App\Libraries\Crypto_helper; // Importa la libreria
use App\Libraries\DatabaseConfig;
use App\Models\AuthCodeModel;
use App\Services\LegacyTenantSessionService;
use App\Services\TenantDatabaseConnector;

class ResetController extends BaseController
{
    protected $db;
    protected $dbConfig;

    private function logErrorLogin(string $method, string $reason, array $context = []): void
    {
        $context = array_merge([
            'ip'         => $this->request->getIPAddress(),
            'user_agent' => substr((string) ($this->request->getServer('HTTP_USER_AGENT') ?? ''), 0, 255),
        ], $context);

        log_message(
            'error',
            '[ResetController::' . $method . '] errorLogin - ' . $reason . ' | context=' .
            json_encode($context, JSON_UNESCAPED_SLASHES)
        );
    }

    public function __construct()
    {
        (new LegacyTenantSessionService())->bindPendingRuntimeIfAvailable();
        $this->db = \Config\Database::connect(); // Assegna alla proprietà della classe
        $this->dbConfig = new DatabaseConfig();
        $this->dbConfig->setEncryptionConfig($this->db);
    }
    
    public function index()
    {
        return view('reset/reset');
    }
  
   public function cambio()
{
    if ((int)session()->get('reset_flow') !== 1) {
        $this->logErrorLogin('cambio', 'Accesso alla pagina cambio password reset senza sessione reset_flow valida. L utente viene rimandato al login.', [
            'userId' => (int) (session()->get('userId') ?? 0),
        ]);
        return redirect()->to(base_url('login'));
    }

    if ((int)session()->get('otp_ok_for_reset') !== 1) {
        $this->logErrorLogin('cambio', 'Accesso alla pagina cambio password reset senza OTP verificato. L utente viene rimandato alla verifica OTP.', [
            'userId' => (int) (session()->get('userId') ?? 0),
        ]);
        return redirect()->to(base_url('auth'))->with('error', 'Verifica OTP richiesta.');
    }

    return view('reset/cambio');
}


    public function checkUsername()
    {
        try {
            log_message('info', 'Avvio checkUsername');

            $tenantSession = new LegacyTenantSessionService();
            $tenantSession->clearAllPending();
            $userModel = new UsersModel();

            $json = $this->request->getJSON();
            if (!$json) {
                $this->logErrorLogin('checkUsername', 'Payload JSON assente o non valido durante il recupero password. Il box errorLogin della pagina reset deve segnalare che i dati inviati non sono utilizzabili.');
                return $this->response->setJSON(['error' => 'Dati non validi'])->setStatusCode(400);
            }

            if (!isset($json->username) || trim((string) $json->username) === '') {
                $this->logErrorLogin('checkUsername', 'Username mancante o vuoto nel recupero password. Non e possibile cercare l utente in dap01_users.');
                return $this->response->setJSON(['error' => 'Username mancante'])->setStatusCode(400);
            }
            
            $codice_fiscale = strtoupper($json->username);
            log_message('info', 'Ricerca utente con codice fiscale: ' . $codice_fiscale);

            $utentePresente = $userModel->where('username', $codice_fiscale)->first();
            $lookupDb = $this->db;
            $matchedTenant = null;

            if (!$utentePresente) {
                $tenantMatch = $this->findTenantUserByUsername($codice_fiscale);
                if ($tenantMatch !== null) {
                    $utentePresente = (array) ($tenantMatch['user'] ?? []);
                    $lookupDb = $tenantMatch['db'];
                    $matchedTenant = (array) ($tenantMatch['tenant'] ?? []);
                    $tenantSession->queuePendingRuntime(
                        $matchedTenant,
                        (int) ($utentePresente['id_user'] ?? 0),
                        (int) ($utentePresente['tipo_user'] ?? 0)
                    );
                } else {
                    $this->logErrorLogin('checkUsername', 'Utente non trovato in dap01_users durante il recupero password. Il frontend mostra errorLogin perche il codice fiscale/username non e registrato.', [
                        'username' => $codice_fiscale,
                    ]);
                    return $this->response->setJSON(['error' => "Utente non trovato."])->setStatusCode(500);
                }
            } else {
                $tenantSession->queueCurrentRuntimeTenantIfAvailable(
                    (int) ($utentePresente['id_user'] ?? 0),
                    (int) ($utentePresente['tipo_user'] ?? 0),
                    false
                );
            }

            $cellulareData = $this->resolveCellulareForResetUser(
                $lookupDb,
                (int) ($utentePresente['id_user'] ?? 0),
                (int) ($utentePresente['tipo_user'] ?? 0)
            );

            if (!$cellulareData) {
                $this->logErrorLogin('checkUsername', 'Cellulare non recuperato per utente esistente. Il reset non puo proseguire perche manca il recapito da usare nel flusso OTP/reset.', [
                    'username'  => $codice_fiscale,
                    'id_user'   => (int) ($utentePresente['id_user'] ?? 0),
                    'tipo_user' => (int) ($utentePresente['tipo_user'] ?? 0),
                    'tenant_id' => (int) ($matchedTenant['id_tenant'] ?? 0),
                ]);
                return $this->response->setJSON(['error' => "Errore ricerca cellulare."])->setStatusCode(500);
            }
            
          session()->set('cellulare', $cellulareData);
session()->set('userId', (int)$utentePresente['id_user']);
session()->set('tipo_user', (int)$utentePresente['tipo_user']);

// ✅ Flusso reset password
session()->set('reset_flow', 1);
session()->remove('pwd_expired_flow');
session()->remove('otp_ok_for_reset');
session()->remove('otp');
session()->remove('otp_identity');

// ✅ (consigliato) pulizia stato login normale
session()->remove('isLoggedIn');
session()->remove('isLoggedInConfirmed');
session()->remove('utente_sess');
session()->remove('tipoUser');


return $this->response->setJSON(['success' => true]);
        } catch (\Throwable $e) {
            $this->logErrorLogin('checkUsername', 'Eccezione durante il recupero password: il frontend ricevera errore e puo mostrare errorLogin. Dettaglio: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $this->response->setJSON(['error' => $e->getMessage()])->setStatusCode(500);
        }
    }

    public function cambioPassword()
    {
        try {
            log_message('info', 'Avvio cambioPassword');
            
            $userModel = new UsersModel();
            $crypto_helper = new Crypto_helper();
            $json = $this->request->getJSON();
            if (!$json) {
                $this->logErrorLogin('cambioPassword', 'Payload JSON assente o non valido durante il cambio password reset. Il frontend mostra errorLogin per dati non utilizzabili.');
                return $this->response->setJSON(['error' => 'Dati non validi'])->setStatusCode(400);
            }

            if ((int) session()->get('reset_flow') !== 1) {
                $this->logErrorLogin('cambioPassword', 'Cambio password reset richiesto senza reset_flow valido in sessione. Possibile sessione scaduta o accesso diretto all endpoint.', [
                    'userId' => (int) (session()->get('userId') ?? 0),
                ]);
                return $this->response->setJSON(['error' => 'Sessione reset non valida'])->setStatusCode(403);
            }

            if ((int) session()->get('otp_ok_for_reset') !== 1) {
                $this->logErrorLogin('cambioPassword', 'Cambio password reset richiesto senza OTP verificato. La password non viene aggiornata.', [
                    'userId' => (int) (session()->get('userId') ?? 0),
                ]);
                return $this->response->setJSON([
                    'error' => 'OTP non verificato',
                    'redirectUrl' => base_url('auth'),
                ])->setStatusCode(403);
            }

            $idUser = (int) (session()->get('userId') ?? 0);
            if ($idUser <= 0) {
                $this->logErrorLogin('cambioPassword', 'Cambio password reset senza userId valido in sessione. Non e possibile aggiornare dap01_users in modo sicuro.');
                return $this->response->setJSON(['error' => 'Utente reset non valido'])->setStatusCode(400);
            }

            $plainPassword = (string) ($json->password ?? '');
            $plainPasswordRip = isset($json->password_rip)
                ? (string) $json->password_rip
                : (isset($json->password2) ? (string) $json->password2 : null);

            if ($plainPassword === '') {
                $this->logErrorLogin('cambioPassword', 'Password nuova mancante nel cambio password reset. Il box errorLogin deve indicare che la password non e valorizzata.', [
                    'userId' => $idUser,
                ]);
                return $this->response->setJSON(['error' => 'Password mancante'])->setStatusCode(400);
            }

            if ($plainPasswordRip !== null && $plainPassword !== $plainPasswordRip) {
                $this->logErrorLogin('cambioPassword', 'Password e conferma password non coincidono nel cambio password reset. Nessuna password viene salvata.', [
                    'userId' => $idUser,
                ]);
                return $this->response->setJSON(['error' => 'Password non coincidono'])->setStatusCode(400);
            }

            $hasLen = strlen($plainPassword) >= 8;
            $hasUp  = (bool) preg_match('/[A-Z]/', $plainPassword);
            $hasLo  = (bool) preg_match('/[a-z]/', $plainPassword);
            $hasSp  = (bool) preg_match('/[^A-Za-z0-9]/', $plainPassword);

            if (!$hasLen || !$hasUp || !$hasLo || !$hasSp) {
                $this->logErrorLogin('cambioPassword', 'Password nuova non conforme alle regole minime del reset. Richiesti almeno 8 caratteri, una maiuscola, una minuscola e un carattere speciale.', [
                    'userId' => $idUser,
                    'rules'  => [
                        'length'    => $hasLen,
                        'uppercase' => $hasUp,
                        'lowercase' => $hasLo,
                        'special'   => $hasSp,
                    ],
                ]);
                return $this->response->setJSON([
                    'error' => 'Password non soddisfa i requisiti',
                    'rules' => [
                        'length'    => $hasLen,
                        'uppercase' => $hasUp,
                        'lowercase' => $hasLo,
                        'special'   => $hasSp,
                    ],
                ])->setStatusCode(400);
            }
            
            $cellulare = session()->get('cellulare');
            log_message('info', 'Tentativo di aggiornamento password per cellulare: ' . $cellulare);

           /* if (session()->get('tipoUser') == 3) {
                log_message('info', 'Utente di tipo 3, ricerca in dap02_clients');
                $sql = "SELECT * FROM dap02_clients WHERE cellulare = " . $crypto_helper->encrypt_select_pulito($cellulare) . " LIMIT 10";
                log_message('debug', 'Esecuzione query: ' . $sql);
                $query = $this->db->query($sql);
                $utentePresente = $query->getRow();
            } else {
                log_message('info', 'Utente di altro tipo, ricerca in dap03_personale');
                $sql = "SELECT * FROM dap03_personale WHERE cellulare = " . $crypto_helper->encrypt_select_pulito($cellulare) . " LIMIT 10";
                log_message('debug', 'Esecuzione query: ' . $sql);
                $query = $this->db->query($sql);
                $utentePresente = $query->getRow();
            }
            
            if (!$utentePresente) {
                log_message('error', 'Utente non presente con cellulare: ' . $cellulare);
                return $this->response->setJSON(['error' => 'Utente non presente'])->setStatusCode(400);
            }
            print_r($utentePresente);*/
            $this->db->transStart();
            $this->db->query("SET @init_vector = RANDOM_BYTES(16)");

            $password = $crypto_helper->encrypt($plainPassword);
            $date = date_create(date("Y-m-d H:i:s"));
            date_add($date, date_interval_create_from_date_string("365 days"));
            $password_scad = date_format($date, "Y-m-d H:i:s");
            
            $password_query = " datascadenza='" . $password_scad . "',password=" . $password . ", vector_id=@init_vector";
            $update_query = "UPDATE dap01_users SET " . $password_query . " WHERE id_user=" . $idUser;
            log_message('debug', 'Esecuzione query: ' . $update_query);
            $this->db->query($update_query);
            
            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                $this->logErrorLogin('cambioPassword', 'Transazione fallita durante aggiornamento password reset su dap01_users. La password non e stata aggiornata.', [
                    'userId' => $idUser,
                ]);
                return $this->response->setJSON(['error' => 'Errore durante la modifica.'])->setStatusCode(500);
            }

            log_message('info', 'Password modificata con successo per utente ID: ' .$idUser);
        session()->remove('cellulare');
        session()->remove('tipo_user');
        session()->remove('reset_flow');
        session()->remove('pwdResetFlow');
        session()->remove('pwd_expired_flow');
        session()->remove('otp_ok_for_reset');
        session()->remove('otp');
        session()->remove('platform_user_id');
        session()->remove('platform_user_email');
        session()->remove('platform_is_admin');
        session()->remove('loginSource');
        session()->remove(\App\Services\TenantContextService::SESSION_KEY);
        (new LegacyTenantSessionService())->clearAllPending();

        //log_message('info', 'Password modificata con successo per utente ID: ' . $userId);

        return $this->response->setJSON([
            'success'     => true,
            'message'     => 'Password aggiornata',
            'redirectUrl' => base_url('/')   // ✅ TORNA SEMPRE QUI
        ]);
        } catch (\Throwable $e) {
            $this->logErrorLogin('cambioPassword', 'Eccezione durante il cambio password reset: il frontend ricevera errore e puo mostrare errorLogin. Dettaglio: ' . $e->getMessage(), [
                'userId' => (int) (session()->get('userId') ?? 0),
                'file'   => $e->getFile(),
                'line'   => $e->getLine(),
            ]);
            return $this->response->setJSON(['error' => $e->getMessage()])->setStatusCode(500);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTenantUserByUsername(string $username): ?array
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        $platformDb = \Config\Database::connect('platform');
        $connector = new TenantDatabaseConnector();
        $config = new DatabaseConfig();

        $tenants = $platformDb->table('platform_tenants t')
            ->select('t.*, p.package_code, p.package_name')
            ->join('platform_packages p', 'p.id_package = t.id_package', 'left')
            ->where('t.is_active', 1)
            ->orderBy('t.tenant_name', 'ASC')
            ->get()
            ->getResultArray();

        $matches = [];

        foreach ($tenants as $tenant) {
            $status = strtolower(trim((string) ($tenant['status'] ?? 'active')));
            if (in_array($status, ['archived', 'suspended'], true)) {
                continue;
            }

            try {
                $tenantDb = $connector->connect($tenant);
                $config->setEncryptionConfig($tenantDb);
            } catch (\Throwable $e) {
                log_message('warning', 'ResetController tenant connect failed: ' . $e->getMessage(), [
                    'tenant_id' => (int) ($tenant['id_tenant'] ?? 0),
                    'tenant_key' => (string) ($tenant['tenant_key'] ?? ''),
                ]);
                continue;
            }

            if (!$tenantDb->tableExists('dap01_users')) {
                continue;
            }

            $user = $tenantDb->table('dap01_users')
                ->select('id_user, username, tipo_user')
                ->where('username', $username)
                ->get(1)
                ->getRowArray();

            if (!$user) {
                continue;
            }

            $matches[] = [
                'tenant' => $tenant,
                'db' => $tenantDb,
                'user' => $user,
            ];
        }

        if (count($matches) !== 1) {
            if (count($matches) > 1) {
                $this->logErrorLogin('checkUsername', 'Username presente in piu spazi tenant durante il recupero password. Richiesta non gestita automaticamente per evitare reset sullo spazio sbagliato.', [
                    'username' => $username,
                    'tenant_ids' => array_map(static fn(array $match): int => (int) ($match['tenant']['id_tenant'] ?? 0), $matches),
                ]);
            }

            return null;
        }

        return $matches[0];
    }

    private function resolveCellulareForResetUser(\CodeIgniter\Database\BaseConnection $db, int $userId, int $userType): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $crypto = new Crypto_helper();
        if ($userType === 3) {
            $sql = "SELECT " . $crypto->decrypt("b.cellulare") . " FROM dap02_clients b WHERE b.id_user = ? LIMIT 1";
        } else {
            $sql = "SELECT " . $crypto->decrypt("b.cellulare") . " FROM dap03_personale b WHERE b.id_user = ? LIMIT 1";
        }

        $row = $db->query($sql, [$userId])->getRowArray();
        if (!$row) {
            return null;
        }

        $value = reset($row);
        return trim((string) $value) ?: null;
    }
}
