<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ClientsModel;
use App\Models\UsersModel;
use App\Models\ClientDoctorModel;
use App\Models\PushSubscriptionModel;
use App\Services\NotificationService;

class Clienti extends BaseController
{
    private function ensureAdmin()
    {
        $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) {
            return redirect()->to('/login');
        }

        if (session()->get('is_admin') !== true && (int)($me->tipo ?? 0) !== 1) {
            return redirect()->to('/');
        }

        return null;
    }

    private function ensureAdminApi()
    {
        $guard = $this->ensureAdmin();
        if ($guard !== null) {
            return $this->jsonWithCsrf([
                'ok'    => false,
                'error' => 'Accesso non autorizzato.',
            ], 403);
        }

        return null;
    }

    private function jsonWithCsrf(array $payload, int $statusCode = 200)
    {
        $payload['csrfName'] = csrf_token();
        $payload['csrfHash'] = csrf_hash();

        return $this->jsonResponse($payload, $statusCode);
    }

    private function jsonResponse(array $payload, int $statusCode = 200)
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            $statusCode = 500;
            $json = json_encode([
                'ok' => false,
                'error' => 'Errore encoding JSON',
            ], JSON_UNESCAPED_UNICODE);
        }

        return $this->response
            ->setStatusCode($statusCode)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setBody($json ?: '{}');
    }

    private function getActiveMobileForUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $row = (new PushSubscriptionModel())->getActiveByUser($userId, 'phone')[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return [
            'device_name'  => (string)($row['device_name'] ?? ''),
            'device_label' => (string)($row['device_label'] ?? ''),
            'device_os'    => (string)($row['device_os'] ?? ''),
            'device_type'  => (string)($row['device_type'] ?? ''),
            'last_seen'    => (string)($row['last_seen'] ?? ''),
        ];
    }

    public function index()
    {
        $guard = $this->ensureAdmin();
        if ($guard !== null) {
            return $guard;
        }

        $result = session()->get('menuDataAdmin');
        $menu_items = $result['result'] ?? [];

        return view('admin/clienti_modifica', [
            'menu_items' => $menu_items,
            'success'    => session()->getFlashdata('success'),
            'errors'     => session()->getFlashdata('errors') ?? [],
        ]);
    }

    /**
     * AJAX: ricerca LIKE su nome/cognome (dap02 decrypt) e CF su username (dap01)
     */
    public function search()
    {
        $guard = $this->ensureAdminApi();
        if ($guard !== null) {
            return $guard;
        }

        try {
        $clients = new ClientsModel();

        $nome    = (string)($this->request->getGet('nome') ?? '');
        $cognome = (string)($this->request->getGet('cognome') ?? '');
        $cf      = (string)($this->request->getGet('cf') ?? '');

        $rows = $clients->searchClientsLike($nome, $cognome, $cf, 30);

        $out = array_map(static function ($r) {
            $label = trim(($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? ''));
            $cf    = strtoupper((string)($r['codice_fiscale'] ?? ''));
            return [
                'id_client' => (int)$r['id_client'],
                'label'     => $label . ($cf ? " — CF: {$cf}" : ''),
            ];
        }, $rows);

        return $this->jsonResponse([
            'ok' => true,
            'results' => $out,
        ]);
        } catch (\Throwable $e) {
            log_message('error', 'Admin Clienti::search failed: ' . $e->getMessage(), [
                'nome' => (string)($this->request->getGet('nome') ?? ''),
                'cognome' => (string)($this->request->getGet('cognome') ?? ''),
                'cf' => (string)($this->request->getGet('cf') ?? ''),
            ]);

            return $this->jsonResponse([
                'ok' => false,
                'error' => 'Errore durante l\'elaborazione della ricerca.',
            ], 500);
        }
    }

    /**
     * AJAX: dettaglio cliente + user + lista dottori + selectedDoctorId (con regola mismatch)
     * CF mostrato = username (dap01_users).
     */
    public function get(int $idClient)
    {
        $guard = $this->ensureAdminApi();
        if ($guard !== null) {
            return $guard;
        }

        $clients = new ClientsModel();
        $users   = new UsersModel();
        $rel     = new ClientDoctorModel();
        $db      = \Config\Database::connect();

        $cli = $clients->getClientDecryptedByClientId($idClient);
        if (!$cli) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Cliente non trovato']);
        }

        // user (username = CF, NON cifrato)
        $userRow = null;
        if (!empty($cli['id_user'])) {
            $userRow = $users->select('id_user, username, password, datascadenza')
                             ->where('id_user', (int)$cli['id_user'])
                             ->first();
        }

        // CF: prima username se esiste un account, altrimenti fallback dal profilo cifrato di dap02
        $cfFromUsername = '';
        if ($userRow && !empty($userRow['username'])) {
            $cfFromUsername = strtoupper((string)$userRow['username']);
        } elseif (!empty($cli['codice_fiscale'])) {
            $cfFromUsername = strtoupper((string)$cli['codice_fiscale']);
        }
        $cli['codice_fiscale'] = $cfFromUsername;

        // lista dottori (dap03_personale) decriptati
        $crypto = new \App\Libraries\Crypto_helper();
        $dNome  = $crypto->decryptSenzaAlias('p.nome');
        $dCog   = $crypto->decryptSenzaAlias('p.cognome');

        $sqlDocs = "
            SELECT
                p.id_personale,
                {$dCog} AS cognome,
                {$dNome} AS nome
            FROM dap03_personale p
             WHERE p.titolare = 1
            ORDER BY {$dCog}, {$dNome}
        ";
        $docs = $db->query($sqlDocs)->getResultArray();

        $doctors = array_map(static function ($d) {
            return [
                'id_personale' => (int)$d['id_personale'],
                'label'        => trim(($d['cognome'] ?? '') . ' ' . ($d['nome'] ?? '')),
            ];
        }, $docs);

        // relazione dap09_client_doctor
        $relRow = $db->table('dap09_client_doctor')
                     ->select('id_client, id_dot')
                     ->where('id_client', (int)$idClient)
                     ->get()->getRowArray();

        $idPersonaleClients = (int)($cli['id_personale'] ?? 0);
        $idDotRel           = (int)($relRow['id_dot'] ?? 0);

        // REGOLA: se NON coincidono => select vuota
        $selectedDoctorId = null;
        if ($idPersonaleClients > 0 && $idDotRel > 0 && $idPersonaleClients === $idDotRel) {
            $selectedDoctorId = $idPersonaleClients;
        }

        $activeDevice = $this->getActiveMobileForUser((int)($cli['id_user'] ?? 0));

        $payload = [
    'ok' => true,
    'client' => $cli,
    'user'   => $userRow ? [
    'id_user'      => (int)$userRow['id_user'],
    'username'     => (string)$userRow['username'],
    'datascadenza' => (string)($userRow['datascadenza'] ?? ''), // YYYY-MM-DD
] : null,
    'doctors' => $doctors,
    'selectedDoctorId' => $selectedDoctorId,
    'activeDevice' => $activeDevice,
    'debug' => [
        'id_personale_clients' => $idPersonaleClients,
        'id_dot_rel' => $idDotRel,
    ],
];

// JSON robusto (evita Malformed UTF-8)
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

if ($json === false) {
    // fallback estremo
    $json = json_encode(['ok' => false, 'error' => 'Errore encoding JSON'], JSON_UNESCAPED_UNICODE);
}

return $this->response
    ->setHeader('Content-Type', 'application/json; charset=utf-8')
    ->setBody($json);

    }

    /**
     * POST: salva cliente + user + relazione dottore
     * CF = username (sempre).
     */
    public function update()
    {
        $guard = $this->ensureAdmin();
        if ($guard !== null) {
            return $guard;
        }

        $clients = new ClientsModel();
        $users   = new UsersModel();
        $rel     = new ClientDoctorModel();
        $crypto  = new \App\Libraries\Crypto_helper();

        $idClient = (int)$this->request->getPost('id_client');
        $idUser   = (int)$this->request->getPost('id_user');
$datascadenza = trim((string)$this->request->getPost('datascadenza')); // YYYY-MM-DD
if ($datascadenza !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datascadenza)) {
    return redirect()->back()->with('errors', ['generic' => 'Formato scadenza non valido.']);
}
        if ($idClient <= 0) {
            return redirect()->back()->with('errors', ['generic' => 'ID cliente mancante.']);
        }

        // username = CF (sempre)
        $username = strtoupper(trim((string)$this->request->getPost('username')));
        if ($username === '') {
            return redirect()->back()->with('errors', ['generic' => 'Codice fiscale mancante.']);
        }

        $userConflict = $users->findOtherByUsernameInsensitive($username, $idUser);
        if ($userConflict) {
            return redirect()->back()->with('errors', [
                'generic' => 'Esiste gia un altro utente con questo codice fiscale/username.',
            ]);
        }

$datascadenza = trim((string)$this->request->getPost('datascadenza')); // YYYY-MM-DD oppure vuoto
        // dottore selezionato
        $idDot = (int)$this->request->getPost('id_personale');

        // dati cliente in chiaro
        $dataClient = [
            'nome'           => (string)$this->request->getPost('nome'),
            'cognome'        => (string)$this->request->getPost('cognome'),
            'cellulare'      => (string)$this->request->getPost('cellulare'),
            'email'          => (string)$this->request->getPost('email'),
            'indirizzo'      => (string)$this->request->getPost('indirizzo'),
            'citta'          => (string)$this->request->getPost('citta'),
            'provincia'      => (string)$this->request->getPost('provincia'),
            'codice_fiscale' => $username, // 👈 CF sempre da username
            'avviso_mail'    => (int)$this->request->getPost('avviso_mail'),
        ];

        // aggiorno dap02 (criptando) e id_personale
        $okClient = $clients->updateClientEncrypted($idClient, $dataClient, $idDot);

        // aggiorno relazione dap09
        $okRel = $rel->setDoctorForClient($idClient, $idDot);

        // aggiorno dap01_users: username sempre, password solo se compilata
        $okUser = true;
        if ($idUser > 0) {
            $db = \Config\Database::connect();
            $password = (string)$this->request->getPost('password'); // nuova password (facoltativa)
            $set = [
                "username=" . $db->escape($username),
                "datascadenza=" . ($datascadenza !== '' ? $db->escape($datascadenza . ' 00:00:00') : 'NULL'),
            ];

            if (trim($password) !== '') {
                $db->query("SET @init_vector = RANDOM_BYTES(16)");
                $set[] = "password=" . $crypto->encrypt($password);
                $set[] = "vector_id=@init_vector";
            }

            try {
                $sql = "UPDATE dap01_users SET " . implode(', ', $set)
                    . " WHERE id_user=" . (int)$idUser . " LIMIT 1";
                $okUser = (bool)$db->query($sql);
            } catch (\Throwable $e) {
                log_message('error', 'Update user error: '.$e->getMessage());
                $okUser = false;
            }
        }

        if ($okClient && $okRel && $okUser) {
    return redirect()->to(site_url('admin/personale/modifica_cliente'))
        ->with('success', 'Cliente aggiornato con successo.');
}


        return redirect()->back()->with('errors', [
            'generic' => 'Errore salvataggio: client='.(int)$okClient.', rel='.(int)$okRel.', user='.(int)$okUser
        ]);
    }

    public function disconnectDevice()
    {
        $guard = $this->ensureAdminApi();
        if ($guard !== null) {
            return $guard;
        }

        $idUser = (int)($this->request->getPost('id_user') ?? 0);
        $idClient = (int)($this->request->getPost('id_client') ?? 0);

        if ($idUser <= 0 && $idClient > 0) {
            $cli = (new ClientsModel())->getClientDecryptedByClientId($idClient);
            $idUser = (int)($cli['id_user'] ?? 0);
        }

        if ($idUser <= 0) {
            return $this->jsonWithCsrf([
                'ok'    => false,
                'error' => 'Account utente del paziente non trovato.',
            ], 400);
        }

        try {
            $activeDevice = $this->getActiveMobileForUser($idUser);
            if ($activeDevice === null) {
                return $this->jsonWithCsrf([
                    'ok'          => true,
                    'message'     => 'Nessun dispositivo mobile attivo da disassociare.',
                    'activeDevice'=> null,
                ]);
            }

            (new NotificationService())->disconnectUserMobiles($idUser);

            return $this->jsonWithCsrf([
                'ok'           => true,
                'message'      => 'Dispositivo disassociato con successo.',
                'activeDevice' => null,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Admin disconnect device error: ' . $e->getMessage(), [
                'id_user' => $idUser,
                'id_client' => $idClient,
            ]);

            return $this->jsonWithCsrf([
                'ok'    => false,
                'error' => 'Errore durante la disassociazione del dispositivo.',
            ], 500);
        }
    }
}
