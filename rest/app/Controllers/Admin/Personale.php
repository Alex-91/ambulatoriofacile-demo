<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\TypeDoctorsModel;
use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;
use App\Services\AgendaDoctorIdService;
use App\Services\StaffLocationCatalogService;
use App\Services\StaffDoctorLinkService;

class Personale extends BaseController
{

      protected $db;
    protected $dbConfig;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->dbConfig = new DatabaseConfig();
        $this->dbConfig->setEncryptionConfig($this->db);
    }

    public function create()
    {
        $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) return redirect()->to('/login');

        // solo admin
        if ((int)(session()->get('admin') ?? 0) !== 1 && (int)($me->tipo ?? 0) !== 1) {
            return redirect()->to('/');
        }

        $tM = new TypeDoctorsModel();
        $locationCatalog = new StaffLocationCatalogService($this->db);

        // menu admin in sessione
        $menuData = session()->get('menuDataAdmin');
        $menu_items = $menuData['result'] ?? [];

        return view('admin/personale_create', [
            'menu_items' => $menu_items,
            'pageTitle'  => 'Inserisci Personale',
            'gruppi'     => $locationCatalog->listSelectableLocations(),
            'tipi'       => $tM->orderBy('des_tipo', 'ASC')->findAll(),
            'errors'     => session()->getFlashdata('errors') ?? [],
            'old'        => session()->getFlashdata('old') ?? [],
            'success'    => session()->getFlashdata('success'),
        ]);
    }

    public function store()
    {
        // -----------------------
        // AUTH
        // -----------------------
        $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) return redirect()->to('/login');

        if ((int)(session()->get('admin') ?? 0) !== 1 && (int)($me->tipo ?? 0) !== 1) {
            return redirect()->to('/');
        }

        $post = $this->request->getPost() ?? [];

        // -----------------------
        // VALIDAZIONE (email opzionale)
        // -----------------------
        $rules = [
            'nome'      => 'required|min_length[2]',
            'cognome'   => 'required|min_length[2]',
            'username'  => 'required|min_length[3]',
            'qualifica' => 'required|min_length[2]',
            'password'  => 'required|min_length[6]',
            'password2' => 'required|matches[password]',
            'cellulare' => 'required|min_length[6]',
            'tipo'      => 'required|is_natural_no_zero',
            'email'     => 'permit_empty|valid_email',
        ];

        if (!$this->validate($rules)) {
            return redirect()->to(site_url('admin/personale/nuovo'))
                ->with('errors', $this->validator->getErrors())
                ->with('old', $post);
        }

        // -----------------------
        // PREPARO DATI
        // -----------------------
        $nome      = trim((string)$post['nome']);
        $cognome   = trim((string)$post['cognome']);
        $qualifica = trim((string)$post['qualifica']);

        $username  = trim((string)$post['username']);
        $username  = strtolower($username);
        $username  = preg_replace('/\s+/', '.', $username);
        $username  = preg_replace('/[^a-z0-9\._-]/', '', $username);
        $username  = preg_replace('/\.+/', '.', $username);
        $username  = trim($username, '.');

        if ($username === '') {
            return redirect()->to(site_url('admin/personale/nuovo'))
                ->with('errors', ['username' => 'Username non valido'])
                ->with('old', $post);
        }

        // unicità username (case-insensitive)
        $exists = $this->db->table('dap01_users')
            ->where('LOWER(username)', strtolower($username))
            ->countAllResults();

        if ($exists > 0) {
            return redirect()->to(site_url('admin/personale/nuovo'))
                ->with('errors', ['username' => 'Username già esistente'])
                ->with('old', $post);
        }

        $password  = (string)$post['password'];
        $cellulare = trim((string)$post['cellulare']);
        $email     = trim((string)($post['email'] ?? ''));

        // FK dap05_type_doctors
        $tipoDoc   = (int)$post['tipo'];

        $staffLinks = new StaffDoctorLinkService($this->db);
        $selectedLuoghi = $staffLinks->normalizeGroupIds($this->request->getPost('luoghi'), (int)($post['luogo'] ?? 0));
        if (empty($selectedLuoghi)) {
            return redirect()->to(site_url('admin/personale/nuovo'))
                ->with('errors', ['luoghi' => 'Seleziona almeno un luogo'])
                ->with('old', $post);
        }

        // dap03_personale.luogo resta un singolo valore: salvo il primo luogo scelto.
        $luogo = $staffLinks->primaryGroupId($selectedLuoghi);

        $sostituto = !empty($post['sostituto']) ? 1 : 0;
        $titolare  = !empty($post['titolare']) ? 1 : 0;
        $showInAgenda = !empty($post['show_in_agenda']) ? 1 : 0;
        $showInPosta  = !empty($post['show_in_posta']) ? 1 : 0;
        $showInChat   = !empty($post['show_in_chat']) ? 1 : 0;

        // tipo utente sempre 2 (personale)
        $tipoUser  = 2;

        // scadenza: se vuota => +1 anno
        $datascadenza = date('Y-m-d H:i:s', strtotime('+1 year'));

        // email in dap03_personale è NOT NULL: se non la vuoi obbligatoria, salva stringa vuota
        if ($email === '') $email = '';

        $crypto = new Crypto_helper();

        // -----------------------
        // TRANSAZIONE
        // -----------------------
        $this->db->transStart();

        try {
            // assicurati IV 16 byte anche durante la transazione
            $this->db->query("SET @init_vector = RANDOM_BYTES(16)");

            // 1) INSERT dap01_users (username plain, password cifrata)
            $sqlUser = "
                INSERT INTO dap01_users
                (username, password, datascadenza, tipo_user, privacy, is_active, vector_id)
                VALUES
                (?, {$crypto->encrypt_insert('?')}, ?, ?, 0, 1, @init_vector)
            ";

            $this->db->query($sqlUser, [
                $username,
                $password,
                $datascadenza,
                $tipoUser,
            ]);

            $idUser = (int)$this->db->insertID();
            if ($idUser <= 0) {
                throw new \RuntimeException('Insert dap01_users fallito');
            }

            // 2) INSERT dap03_personale (campi testo cifrati)
            $sqlPers = "
                INSERT INTO dap03_personale
                (
                    id_user,
                    nome,
                    cognome,
                    qualifica,
                    tipo,
                    email,
                    cellulare,
                    sostituto,
                    titolare,
                    luogo,
                    is_active,
                    show_in_agenda,
                    show_in_posta,
                    show_in_chat,
                    vector_id
                )
                VALUES
                (
                    ?,
                    {$crypto->encrypt_insert('?')},
                    {$crypto->encrypt_insert('?')},
                    {$crypto->encrypt_insert('?')},
                    ?,
                    {$crypto->encrypt_insert('?')},
                    {$crypto->encrypt_insert('?')},
                    ?,
                    ?,
                    ?,
                    1,
                    ?,
                    ?,
                    ?,
                    @init_vector
                )
            ";

            $this->db->query($sqlPers, [
                $idUser,
                $nome,
                $cognome,
                $qualifica,
                $tipoDoc,
                $email,
                $cellulare,
                $sostituto,
                $titolare,
                $luogo,
                $showInAgenda,
                $showInPosta,
                $showInChat,
            ]);

            $idPersonale = (int)$this->db->insertID();
            if ($idPersonale <= 0) {
                throw new \RuntimeException('Insert dap03_personale fallito');
            }

            $agendaDoctorId = (new AgendaDoctorIdService($this->db))->ensureForPersonale($idPersonale, $tipoDoc);
            if (in_array($tipoDoc, [1, 2], true) && $agendaDoctorId <= 0) {
                throw new \RuntimeException('Assegnazione identificativo agenda fallita');
            }

            if (!$staffLinks->syncForStaff($idPersonale, $tipoDoc, $selectedLuoghi)) {
                throw new \RuntimeException('Sincronizzazione abbinamenti personale-medici fallita');
            }

            if ($tipoDoc === 1 && !$staffLinks->resyncManagedStaffForGroups($selectedLuoghi)) {
                throw new \RuntimeException('Riallineamento segreterie/infermieri per il luogo del dottore fallito');
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Transazione fallita');
            }

            return redirect()->to(site_url('admin/personale/nuovo'))
                ->with('success', 'Personale inserito correttamente!');

        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'Errore inserimento personale: ' . $e->getMessage());

            return redirect()->to(site_url('admin/personale/nuovo'))
                ->with('errors', ['generic' => 'Errore durante il salvataggio (vedi log).'])
                ->with('old', $post);
        }
    }
}
