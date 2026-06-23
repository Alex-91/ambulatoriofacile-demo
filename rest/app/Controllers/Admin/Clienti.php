<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Crypto_helper;
use App\Models\ClientDoctorModel;
use App\Models\ClientsModel;
use App\Models\DoctorPatientSearchModel;
use App\Models\PushSubscriptionModel;
use App\Models\UsersModel;
use App\Services\NotificationService;
use CodeIgniter\Database\BaseConnection;

class Clienti extends BaseController
{
    private function ensureAdmin()
    {
        $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) {
            return redirect()->to('/login');
        }

        if (session()->get('is_admin') !== true && (int) ($me->tipo ?? 0) !== 1) {
            return redirect()->to('/');
        }

        return null;
    }

    private function ensureAdminApi()
    {
        $guard = $this->ensureAdmin();
        if ($guard !== null) {
            return $this->jsonWithCsrf([
                'ok' => false,
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
            'device_name' => (string) ($row['device_name'] ?? ''),
            'device_label' => (string) ($row['device_label'] ?? ''),
            'device_os' => (string) ($row['device_os'] ?? ''),
            'device_type' => (string) ($row['device_type'] ?? ''),
            'last_seen' => (string) ($row['last_seen'] ?? ''),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveMenuItems(): array
    {
        $result = session()->get('menuDataAdmin');
        return is_array($result['result'] ?? null) ? $result['result'] : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function doctorOptions(): array
    {
        $db = \Config\Database::connect();
        $crypto = new Crypto_helper();
        $dNome = $crypto->decryptSenzaAlias('p.nome');
        $dCog = $crypto->decryptSenzaAlias('p.cognome');

        $sql = "
            SELECT
                p.id_personale,
                {$dCog} AS cognome,
                {$dNome} AS nome
            FROM dap03_personale p
            WHERE p.titolare = 1
            ORDER BY {$dCog}, {$dNome}
        ";

        return array_map(static function (array $row): array {
            return [
                'id_personale' => (int) ($row['id_personale'] ?? 0),
                'label' => trim((string) (($row['cognome'] ?? '') . ' ' . ($row['nome'] ?? ''))),
            ];
        }, $db->query($sql)->getResultArray());
    }

    private function renderClientPage(bool $createMode)
    {
        return view('admin/clienti_modifica', [
            'menu_items' => $this->resolveMenuItems(),
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
            'create_mode' => $createMode,
            'doctor_options' => $this->doctorOptions(),
        ]);
    }

    public function index()
    {
        $guard = $this->ensureAdmin();
        if ($guard !== null) {
            return $guard;
        }

        return $this->renderClientPage(false);
    }

    public function create()
    {
        $guard = $this->ensureAdmin();
        if ($guard !== null) {
            return $guard;
        }

        return $this->renderClientPage(true);
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

            $nome = (string) ($this->request->getGet('nome') ?? '');
            $cognome = (string) ($this->request->getGet('cognome') ?? '');
            $cf = (string) ($this->request->getGet('cf') ?? '');

            $rows = $clients->searchClientsLike($nome, $cognome, $cf, 30);

            $out = array_map(static function ($r) {
                $label = trim((string) (($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? '')));
                $cfValue = strtoupper((string) ($r['codice_fiscale'] ?? ''));

                return [
                    'id_client' => (int) ($r['id_client'] ?? 0),
                    'label' => $label . ($cfValue !== '' ? ' - CF: ' . $cfValue : ''),
                ];
            }, $rows);

            return $this->jsonResponse([
                'ok' => true,
                'results' => $out,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Admin Clienti::search failed: ' . $e->getMessage(), [
                'nome' => (string) ($this->request->getGet('nome') ?? ''),
                'cognome' => (string) ($this->request->getGet('cognome') ?? ''),
                'cf' => (string) ($this->request->getGet('cf') ?? ''),
            ]);

            return $this->jsonResponse([
                'ok' => false,
                'error' => 'Errore durante l\'elaborazione della ricerca.',
            ], 500);
        }
    }

    /**
     * AJAX: dettaglio cliente + user + lista dottori + selectedDoctorId
     * CF mostrato = username (dap01_users).
     */
    public function get(int $idClient)
    {
        $guard = $this->ensureAdminApi();
        if ($guard !== null) {
            return $guard;
        }

        $clients = new ClientsModel();
        $users = new UsersModel();
        $db = \Config\Database::connect();

        $cli = $clients->getClientDecryptedByClientId($idClient);
        if (!$cli) {
            return $this->jsonResponse([
                'ok' => false,
                'error' => 'Cliente non trovato.',
            ], 404);
        }

        $userRow = null;
        if (!empty($cli['id_user'])) {
            $userRow = $users->select('id_user, username, password, datascadenza')
                ->where('id_user', (int) $cli['id_user'])
                ->first();
        }

        $cfFromUsername = '';
        if ($userRow && !empty($userRow['username'])) {
            $cfFromUsername = strtoupper((string) $userRow['username']);
        } elseif (!empty($cli['codice_fiscale'])) {
            $cfFromUsername = strtoupper((string) $cli['codice_fiscale']);
        }
        $cli['codice_fiscale'] = $cfFromUsername;

        $relRow = $db->table('dap09_client_doctor')
            ->select('id_client, id_dot')
            ->where('id_client', (int) $idClient)
            ->get()
            ->getRowArray();

        $idPersonaleClients = (int) ($cli['id_personale'] ?? 0);
        $idDotRel = (int) ($relRow['id_dot'] ?? 0);

        $selectedDoctorId = null;
        if ($idPersonaleClients > 0 && $idDotRel > 0 && $idPersonaleClients === $idDotRel) {
            $selectedDoctorId = $idPersonaleClients;
        }

        $activeDevice = $this->getActiveMobileForUser((int) ($cli['id_user'] ?? 0));

        return $this->jsonResponse([
            'ok' => true,
            'client' => $cli,
            'user' => $userRow ? [
                'id_user' => (int) ($userRow['id_user'] ?? 0),
                'username' => (string) ($userRow['username'] ?? ''),
                'datascadenza' => (string) ($userRow['datascadenza'] ?? ''),
            ] : null,
            'doctors' => $this->doctorOptions(),
            'selectedDoctorId' => $selectedDoctorId,
            'activeDevice' => $activeDevice,
            'debug' => [
                'id_personale_clients' => $idPersonaleClients,
                'id_dot_rel' => $idDotRel,
            ],
        ]);
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
        $users = new UsersModel();
        $rel = new ClientDoctorModel();
        $crypto = new Crypto_helper();

        $idClient = (int) $this->request->getPost('id_client');
        $idUser = (int) $this->request->getPost('id_user');
        $datascadenza = trim((string) $this->request->getPost('datascadenza'));

        if ($datascadenza !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datascadenza)) {
            return redirect()->back()->withInput()->with('errors', ['generic' => 'Formato scadenza non valido.']);
        }

        if ($idClient <= 0) {
            return $this->storeNewClient($clients, $users, $rel, $crypto, $datascadenza);
        }

        $username = strtoupper(trim((string) $this->request->getPost('username')));
        if ($username === '') {
            return redirect()->back()->withInput()->with('errors', ['generic' => 'Codice fiscale mancante.']);
        }

        $userConflict = $users->findOtherByUsernameInsensitive($username, $idUser);
        if ($userConflict) {
            return redirect()->back()->withInput()->with('errors', [
                'generic' => 'Esiste gia un altro utente con questo codice fiscale/username.',
            ]);
        }

        $idDot = (int) $this->request->getPost('id_personale');

        $dataClient = [
            'nome' => (string) $this->request->getPost('nome'),
            'cognome' => (string) $this->request->getPost('cognome'),
            'cellulare' => (string) $this->request->getPost('cellulare'),
            'email' => (string) $this->request->getPost('email'),
            'indirizzo' => (string) $this->request->getPost('indirizzo'),
            'citta' => (string) $this->request->getPost('citta'),
            'provincia' => (string) $this->request->getPost('provincia'),
            'codice_fiscale' => $username,
            'avviso_mail' => (int) $this->request->getPost('avviso_mail'),
        ];

        $okClient = $clients->updateClientEncrypted($idClient, $dataClient, $idDot);
        $okRel = $rel->setDoctorForClient($idClient, $idDot);

        $okUser = true;
        if ($idUser > 0) {
            $db = \Config\Database::connect();
            $password = (string) $this->request->getPost('password');
            $set = [
                'username=' . $db->escape($username),
                'datascadenza=' . ($datascadenza !== '' ? $db->escape($datascadenza . ' 00:00:00') : 'NULL'),
            ];

            if (trim($password) !== '') {
                $db->query('SET @init_vector = RANDOM_BYTES(16)');
                $set[] = 'password=' . $crypto->encrypt($password);
                $set[] = 'vector_id=@init_vector';
            }

            try {
                $sql = 'UPDATE dap01_users SET ' . implode(', ', $set)
                    . ' WHERE id_user=' . (int) $idUser . ' LIMIT 1';
                $okUser = (bool) $db->query($sql);
            } catch (\Throwable $e) {
                log_message('error', 'Update user error: ' . $e->getMessage());
                $okUser = false;
            }
        }

        if ($okClient && $okRel && $okUser) {
            return redirect()->to(site_url('admin/personale/modifica_cliente'))
                ->with('success', 'Cliente aggiornato con successo.');
        }

        return redirect()->back()->withInput()->with('errors', [
            'generic' => 'Errore salvataggio: client=' . (int) $okClient . ', rel=' . (int) $okRel . ', user=' . (int) $okUser,
        ]);
    }

    private function storeNewClient(
        ClientsModel $clients,
        UsersModel $users,
        ClientDoctorModel $rel,
        Crypto_helper $crypto,
        string $datascadenza
    ) {
        $username = strtoupper(trim((string) $this->request->getPost('username')));
        if ($username === '') {
            return redirect()->back()->withInput()->with('errors', ['generic' => 'Codice fiscale mancante.']);
        }

        $password = trim((string) $this->request->getPost('password'));
        if ($password === '') {
            return redirect()->back()->withInput()->with('errors', ['generic' => 'Per creare un nuovo cliente devi impostare una password.']);
        }

        if ($users->findByUsernameInsensitive($username)) {
            return redirect()->back()->withInput()->with('errors', [
                'generic' => 'Esiste gia un account con questo codice fiscale/username.',
            ]);
        }

        if ($clients->findClientByCodiceFiscaleInsensitive($username)) {
            return redirect()->back()->withInput()->with('errors', [
                'generic' => 'Esiste gia un cliente con questo codice fiscale. Apri "Modifica cliente" per aggiornarlo.',
            ]);
        }

        $idDot = (int) $this->request->getPost('id_personale');
        $dataClient = [
            'nome' => trim((string) $this->request->getPost('nome')),
            'cognome' => trim((string) $this->request->getPost('cognome')),
            'cellulare' => trim((string) $this->request->getPost('cellulare')),
            'email' => trim((string) $this->request->getPost('email')),
            'indirizzo' => trim((string) $this->request->getPost('indirizzo')),
            'citta' => trim((string) $this->request->getPost('citta')),
            'provincia' => trim((string) $this->request->getPost('provincia')),
            'codice_fiscale' => $username,
            'avviso_mail' => (int) $this->request->getPost('avviso_mail'),
        ];

        if ($dataClient['nome'] === '' || $dataClient['cognome'] === '' || $dataClient['cellulare'] === '') {
            return redirect()->back()->withInput()->with('errors', [
                'generic' => 'Per creare un cliente servono almeno nome, cognome, cellulare e codice fiscale.',
            ]);
        }

        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            $db->query('SET @init_vector = RANDOM_BYTES(16)');

            $userSql = "
                INSERT INTO dap01_users (username, password, datascadenza, tipo_user, vector_id)
                VALUES (
                    " . $db->escape($username) . ",
                    " . $crypto->encrypt($password) . ",
                    " . ($datascadenza !== '' ? $db->escape($datascadenza . ' 00:00:00') : 'NULL') . ",
                    3,
                    @init_vector
                )
            ";
            $db->query($userSql);

            $idUser = (int) $db->insertID();
            if ($idUser <= 0) {
                throw new \RuntimeException('Creazione account cliente non riuscita.');
            }

            $clientSql = "
                INSERT INTO dap02_clients (
                    id_user,
                    nome,
                    cognome,
                    email,
                    cellulare,
                    codice_fiscale,
                    indirizzo,
                    citta,
                    provincia,
                    avviso_mail,
                    id_personale,
                    vector_id
                ) VALUES (
                    {$idUser},
                    " . $crypto->encrypt($dataClient['nome']) . ",
                    " . $crypto->encrypt($dataClient['cognome']) . ",
                    " . $crypto->encrypt($dataClient['email']) . ",
                    " . $crypto->encrypt($dataClient['cellulare']) . ",
                    " . $crypto->encrypt($dataClient['codice_fiscale']) . ",
                    " . $crypto->encrypt($dataClient['indirizzo']) . ",
                    " . $crypto->encrypt($dataClient['citta']) . ",
                    " . $crypto->encrypt($dataClient['provincia']) . ",
                    " . (int) $dataClient['avviso_mail'] . ",
                    {$idDot},
                    @init_vector
                )
            ";
            $db->query($clientSql);

            $idClient = (int) $db->insertID();
            if ($idClient <= 0) {
                throw new \RuntimeException('Creazione anagrafica cliente non riuscita.');
            }

            if ($idDot > 0 && !$rel->setDoctorForClient($idClient, $idDot, false)) {
                throw new \RuntimeException('Collegamento cliente-dottore non riuscito.');
            }

            $this->assignDefaultClientSchede($db, $idUser);

            if (!$db->transStatus()) {
                throw new \RuntimeException('Salvataggio cliente non riuscito.');
            }

            $db->transCommit();
            (new DoctorPatientSearchModel())->syncClient($idClient);

            return redirect()->to(site_url('admin/personale/modifica_cliente'))
                ->with('success', 'Cliente creato con successo.');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Admin Clienti::storeNewClient failed: ' . $e->getMessage());

            return redirect()->back()->withInput()->with('errors', [
                'generic' => $e->getMessage(),
            ]);
        }
    }

    private function assignDefaultClientSchede(BaseConnection $db, int $idUser): void
    {
        if ($idUser <= 0 || !$db->tableExists('dap_menu_schede') || !$db->tableExists('dap_user_schede')) {
            return;
        }

        $postaScheda = $db->table('dap_menu_schede')
            ->select('id_scheda')
            ->where('codice', 'posta')
            ->orderBy('attiva', 'DESC')
            ->orderBy('id_scheda', 'ASC')
            ->get(1)
            ->getRowArray();

        if (!$postaScheda) {
            return;
        }

        $db->query("
            INSERT INTO dap_user_schede (id_user, id_scheda, can_view, can_access)
            VALUES (?, ?, 1, 1)
            ON DUPLICATE KEY UPDATE can_view = 1, can_access = 1
        ", [$idUser, (int) ($postaScheda['id_scheda'] ?? 0)]);
    }

    public function disconnectDevice()
    {
        $guard = $this->ensureAdminApi();
        if ($guard !== null) {
            return $guard;
        }

        $idUser = (int) ($this->request->getPost('id_user') ?? 0);
        $idClient = (int) ($this->request->getPost('id_client') ?? 0);

        if ($idUser <= 0 && $idClient > 0) {
            $cli = (new ClientsModel())->getClientDecryptedByClientId($idClient);
            $idUser = (int) ($cli['id_user'] ?? 0);
        }

        if ($idUser <= 0) {
            return $this->jsonWithCsrf([
                'ok' => false,
                'error' => 'Account utente del paziente non trovato.',
            ], 400);
        }

        try {
            $activeDevice = $this->getActiveMobileForUser($idUser);
            if ($activeDevice === null) {
                return $this->jsonWithCsrf([
                    'ok' => true,
                    'message' => 'Nessun dispositivo mobile attivo da disassociare.',
                    'activeDevice' => null,
                ]);
            }

            (new NotificationService())->disconnectUserMobiles($idUser);

            return $this->jsonWithCsrf([
                'ok' => true,
                'message' => 'Dispositivo disassociato con successo.',
                'activeDevice' => null,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Admin disconnect device error: ' . $e->getMessage(), [
                'id_user' => $idUser,
                'id_client' => $idClient,
            ]);

            return $this->jsonWithCsrf([
                'ok' => false,
                'error' => 'Errore durante la disassociazione del dispositivo.',
            ], 500);
        }
    }
}
