<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\PersonaleModel;
use App\Models\UsersModel;
use App\Services\DoctorDeletionService;
use App\Services\StaffLocationCatalogService;
use App\Services\StaffDoctorLinkService;

class PersonaleEdit extends BaseController
{
    private function guardAdmin()
    {
        $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) {
            return redirect()->to('/login');
        }

        if ((int)(session()->get('admin') ?? 0) !== 1 && session()->get('is_admin') !== true && (int)($me->tipo ?? 0) !== 1) {
            return redirect()->to('/');
        }

        return null;
    }

    public function index()
    {
        if ($redirect = $this->guardAdmin()) {
            return $redirect;
        }

        // fallback menu
        $menu_items = session()->get('header_menu_items') ?? [];
        $result = session()->get('menuDataAdmin');
        if (!empty($result['result'])) {
            $menu_items = $result['result'];
        }

        return view('admin/personale_modifica', [
            'menu_items' => $menu_items,
            'success'    => session()->getFlashdata('success'),
            'errors'     => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function search()
    {
        if ($redirect = $this->guardAdmin()) {
            return $redirect;
        }

        $model = new PersonaleModel();

        $nome    = (string)($this->request->getGet('nome') ?? '');
        $cognome = (string)($this->request->getGet('cognome') ?? '');
        $cf      = (string)($this->request->getGet('cf') ?? '');

        $rows = $model->searchPersonaleLike($nome, $cognome, $cf, 30);

        $out = array_map(static function($r){
            $label = trim(($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? ''));
            $cf    = strtoupper((string)($r['codice_fiscale'] ?? ''));
            return [
                'id_personale' => (int)$r['id_personale'],
                'label' => $label . ($cf ? " — Username: {$cf}" : ''),
            ];
        }, $rows);

        // JSON robusto (evita Malformed UTF-8)
        $payload = ['ok' => true, 'results' => $out];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $this->response->setHeader('Content-Type','application/json; charset=utf-8')->setBody($json);
    }

    public function get(int $idPersonale)
    {
        if ($redirect = $this->guardAdmin()) {
            return $redirect;
        }

        $model = new PersonaleModel();
        $users = new UsersModel();
        $db    = \Config\Database::connect();

        $p = $model->getPersonaleDecryptedById($idPersonale);
        if (!$p) {
            $json = json_encode(['ok'=>false,'error'=>'Personale non trovato'], JSON_UNESCAPED_UNICODE);
            return $this->response->setHeader('Content-Type','application/json; charset=utf-8')->setBody($json);
        }

        // user credentials
        $userRow = null;
        if (!empty($p['id_user'])) {
            $userRow = $users->select('id_user, username, password, datascadenza')
                             ->where('id_user', (int)$p['id_user'])
                             ->first();
        }

        // options TIPO (dap05)
        // Se il PK non è id_tipo ma id, cambia qui.
        $tipi = $db->table('dap05_type_doctors')
            ->select('id_type_doctors, des_tipo')
            ->orderBy('des_tipo', 'ASC')
            ->get()->getResultArray();

        $tipiOut = array_map(static function($t){
            return [
                'id' => (int)$t['id_type_doctors'],
                'label'   => (string)$t['des_tipo'],
            ];
        }, $tipi);

        $gruppi = (new StaffLocationCatalogService($db))->listSelectableLocations();

        $gruppiOut = array_map(static function($g){
            return [
                'id' => (int)$g['id_gruppo'],
                'label'     => (string)$g['nome'],
            ];
        }, $gruppi);

        $staffLinks = new StaffDoctorLinkService($db);
        $selectedLuoghi = $staffLinks->selectedGroupIdsForStaff(
            $idPersonale,
            (int)($p['tipo'] ?? 0),
            (int)($p['luogo'] ?? 0)
        );

        $payload = [
            'ok' => true,
            'personale' => $p,
            'user' => $userRow ? [
                'id_user' => (int)$userRow['id_user'],
                'username'=> (string)$userRow['username'],
                'datascadenza' => (string)($userRow['datascadenza'] ?? ''),
            ] : null,
            'tipi'   => $tipiOut,
            'gruppi' => $gruppiOut,
            'selected_luoghi' => $selectedLuoghi,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $this->response->setHeader('Content-Type','application/json; charset=utf-8')->setBody($json);
    }

    public function update()
    {
        if ($redirect = $this->guardAdmin()) {
            return $redirect;
        }

        $model  = new PersonaleModel();
        $db     = \Config\Database::connect();
        $crypto = new \App\Libraries\Crypto_helper();

        $idPersonale = (int)$this->request->getPost('id_personale');
        $idUser      = (int)$this->request->getPost('id_user');
$datascadenza = trim((string)$this->request->getPost('datascadenza')); // YYYY-MM-DD
if ($datascadenza !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datascadenza)) {
    return redirect()->back()->with('errors', ['generic' => 'Formato scadenza non valido (usa YYYY-MM-DD).']);
}
        if ($idPersonale <= 0) {
            return redirect()->back()->with('errors', ['generic' => 'ID personale mancante.']);
        }

        $existing = $model->getPersonaleDecryptedById($idPersonale);
        if (!$existing) {
            return redirect()->back()->with('errors', ['generic' => 'Personale non trovato.']);
        }

        $data = [
            'nome'      => (string)$this->request->getPost('nome'),
            'cognome'   => (string)$this->request->getPost('cognome'),
            'qualifica' => (string)$this->request->getPost('qualifica'),
            'id_tipo'   => (int)$this->request->getPost('tipo'),
            'email'     => (string)$this->request->getPost('email'),
            'cellulare' => (string)$this->request->getPost('cellulare'),
            'titolare'  => $this->request->getPost('titolare') ? 1 : 0,
            'sostituto' => $this->request->getPost('sostituto') ? 1 : 0,
            'show_in_agenda' => $this->request->getPost('show_in_agenda') ? 1 : 0,
            'show_in_posta'  => $this->request->getPost('show_in_posta') ? 1 : 0,
            'show_in_chat'   => $this->request->getPost('show_in_chat') ? 1 : 0,
        ];
        $staffLinks = new StaffDoctorLinkService($db);
        $selectedLuoghi = $staffLinks->normalizeGroupIds($this->request->getPost('luoghi'), (int)$this->request->getPost('id_gruppo'));
        if (empty($selectedLuoghi)) {
            return redirect()->back()->with('errors', ['generic' => 'Seleziona almeno un luogo.']);
        }
        $data['id_gruppo'] = $staffLinks->primaryGroupId($selectedLuoghi);

        $okPers = $model->updatePersonaleEncrypted($idPersonale, $data);

        // aggiorno credenziali (username non cifrato, password cifrata se compilata)
        $okUser = true;
        if ($idUser > 0) {
            $username = trim((string)$this->request->getPost('username'));
            $password = (string)$this->request->getPost('password');
            $set = [];
            $set[] = "username=" . $db->escape($username);
            if ($datascadenza !== '') {
                $set[] = "datascadenza=" . $db->escape($datascadenza . ' 00:00:00');
            } else {
                $set[] = "datascadenza=NULL";
            }

            if (trim($password) !== '') {
                // query diretta: qui l'espressione AES viene eseguita dal DB (non salvata come stringa)
                $db->query("SET @init_vector = RANDOM_BYTES(16)");
                $set[] = "password=" . $crypto->encrypt($password);
                $set[] = "vector_id=@init_vector";
            }

            $sql = "UPDATE dap01_users SET " . implode(', ', $set)
                 . " WHERE id_user=" . (int)$idUser . " LIMIT 1";

            try {
                $okUser = (bool)$db->query($sql);
            } catch (\Throwable $e) {
                log_message('error', 'Update user personale error: '.$e->getMessage().' SQL='.$sql);
                $okUser = false;
            }
        }

        $okLinks = true;
        if ($okPers && $okUser) {
            $okLinks = $staffLinks->syncForStaff(
                $idPersonale,
                (int)$this->request->getPost('tipo'),
                $selectedLuoghi
            );

            if ($okLinks) {
                $oldTipo = (int)($existing['tipo'] ?? 0);
                $newTipo = (int)$this->request->getPost('tipo');
                $affectedGroups = array_values(array_unique(array_filter([
                    (int)($existing['luogo'] ?? 0),
                    (int)$data['id_gruppo'],
                ], static fn(int $id): bool => $id > 0)));

                if (($oldTipo === 1 || $newTipo === 1) && !$staffLinks->resyncManagedStaffForGroups($affectedGroups)) {
                    $okLinks = false;
                }
            }
        }

        if ($okPers && $okUser && $okLinks) {
            return redirect()->to(site_url('admin/personale/modifica_personale'))
                ->with('success', 'Personale aggiornato con successo.');
        }

        return redirect()->back()->with('errors', [
            'generic' => 'Errore salvataggio: personale='.(int)$okPers.', user='.(int)$okUser.', abbinamenti='.(int)$okLinks
        ]);
    }

    public function deleteDoctor()
    {
        if ($redirect = $this->guardAdmin()) {
            return $redirect;
        }

        $me = session()->get('utente_sess');

        $idPersonale = (int)($this->request->getPost('id_personale') ?? 0);
        if ($idPersonale <= 0) {
            return redirect()->to(site_url('admin/personale/modifica_personale'))
                ->with('errors', ['generic' => 'Seleziona un dottore valido da eliminare.']);
        }

        try {
            $service = new DoctorDeletionService();
            $result = $service->deleteDoctor(
                $idPersonale,
                (int)($me->id_personale ?? 0),
                (int)($me->id_user ?? 0)
            );

            if ((int)(session()->get('selectedDoctorId') ?? 0) === $idPersonale) {
                session()->remove('selectedDoctorId');
            }

            $message = 'Dottore eliminato correttamente. Rimossi agenda, appuntamenti, slot, memo, note e messaggi collegati.'
                . ' Pazienti sganciati: ' . (int)($result['patients_detached'] ?? 0)
                . '. Messaggi rimossi: '
                . ((int)($result['legacy_messages_deleted'] ?? 0) + (int)($result['new_messages_deleted'] ?? 0))
                . '.';

            return redirect()->to(site_url('admin/personale/modifica_personale'))
                ->with('success', $message);
        } catch (\Throwable $e) {
            log_message('error', 'Delete doctor admin error: ' . $e->getMessage());

            return redirect()->to(site_url('admin/personale/modifica_personale'))
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }
}
