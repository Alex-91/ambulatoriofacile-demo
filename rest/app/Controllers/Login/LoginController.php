<?php


namespace App\Controllers\Login;

use App\Controllers\BaseController;
use App\Libraries\Crypto_helper; // Importa la libreria
use App\Libraries\SystemUserMask;
use App\Models\ClientDoctorModel;

class LoginController extends BaseController
{
    private function storedPasswordLooksCorrupted(\CodeIgniter\Database\BaseConnection $db, int $idUser): bool
    {
        if ($idUser <= 0) {
            return false;
        }

        $sql = "SELECT CONVERT(CAST(AES_DECRYPT(UNHEX(password), @key_str, vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4) AS plain_pwd
                FROM dap01_users
                WHERE id_user = ?
                LIMIT 1";

        $row = $db->query($sql, [$idUser])->getRowArray();
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
            'medical' => 'Percorso medical',
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
        $profileSlug = array_key_exists($requestedProfile, $profileLabels) ? $requestedProfile : '';
        $prefillUsername = $this->sanitizeDemoUsername((string) $this->request->getGet('u'));
        $demoMode = $this->request->getGet('demo') === '1' || $prefillUsername !== '' || $profileSlug !== '';

        return [
            'demoMode' => $demoMode,
            'prefillUsername' => $prefillUsername,
            'demoProfileSlug' => $profileSlug,
            'demoProfileLabel' => $profileLabels[$profileSlug] ?? 'Percorso demo',
            'demoOtpHint' => in_array($prefillUsername, ['alessio2', 'demo.admin->demo.frontdesk.med'], true),
        ];
    }

    public function index()
    {
        // Controlla se la sessione è già attiva
    /*    if (session()->get('isLoggedIn')) {
            return redirect()->to('/');
        }*/

        // Mostra la pagina di login
        return view('login/login', $this->buildLoginViewData());
    }

    public function login()
    {
        $username = '';

        try {
        $db = \Config\Database::connect(); // Connessione al database
        $credentials = $this->request->getJSON(); // Recupera le credenziali inviate tramite POST
        $crypto_helper = new Crypto_helper();
session()->remove('is_admin_arrow_login');
session()->remove('admin_arrow_username');
session()->remove('nav_refresh_meta');
session()->remove('schede_access_map');
session()->remove('schede_data');
session()->remove('otp_identity');
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
                $query = $db->query($sql, [
                    $usernamesArray['username_adm'],  // Passa il parametro direttamente
                    $password                // Passa la password criptata
                ]);
            } else {
                $query = $db->query($sql, [
                    $username,                         // Passa il parametro direttamente
                    $password                    // Passa la password criptata
                ]);
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
                        // ✅ Admin: bypass OTP + bypass scelta schede
                            if ((int)session()->get('tipoUser') === 1) {

                                session()->set('is_admin', true);
                                session()->set('isLoggedInConfirmed', true); // così sei già "confermato"

                                // carico menu admin da dap06_mnu (admin=1)
                                $adminMenuModel = new \App\Models\AdminMenuModel();
                                $menuAdmin = $adminMenuModel->getAdminMenu();

                                // salvo in sessione (così header/sidebar lo leggono)
                                session()->set('menuDataAdmin', [
                                    'result' => $menuAdmin,
                                ]);

                                // IMPORTANTISSIMO: se hai roba vecchia in sessione che forzerebbe scelta schede/otp:
                                session()->remove('requireDoctorSelection');
                                session()->remove('menuData');      // menu normale
                                session()->remove('menuAgenda');    // se vuoi

                                return $this->response->setJSON([
                                    'resp'        => 'OK',
                                    'success'     => true,
                                    'redirectUrl' => 'admin'
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

        return $this->response->setJSON([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()  // Aggiungi la traccia dello stack per il debug
        ])->setStatusCode(500);
    }
    }

    public function logout()
    {
        // Distruggi la sessione per fare il logout
        session()->destroy();
        return redirect()->to('/login');
    }
}
