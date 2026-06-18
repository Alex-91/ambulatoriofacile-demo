<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\PersonaleModel;
use App\Models\UsersModel;

class PersonaleModuleVisibility extends BaseController
{
    private function currentAdminUser()
    {
        $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) {
            return null;
        }

        return $me;
    }

    private function isAdminAuthorized(): bool
    {
        $me = $this->currentAdminUser();
        if (!$me) {
            return false;
        }

        return (int)(session()->get('admin') ?? 0) === 1
            || session()->get('is_admin') === true
            || (int)($me->tipo ?? 0) === 1;
    }

    private function ensureAdminPage()
    {
        if (!$this->currentAdminUser()) {
            return redirect()->to('/login');
        }

        if (!$this->isAdminAuthorized()) {
            return redirect()->to('/');
        }

        return null;
    }

    private function ensureAdminApi()
    {
        if (!$this->currentAdminUser()) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Sessione scaduta.']);
        }

        if (!$this->isAdminAuthorized()) {
            return $this->response
                ->setStatusCode(403)
                ->setJSON(['ok' => false, 'error' => 'Non autorizzato.']);
        }

        return null;
    }

    public function index()
    {
        if ($guard = $this->ensureAdminPage()) {
            return $guard;
        }

        $menuItems = session()->get('header_menu_items') ?? [];
        $result = session()->get('menuDataAdmin');
        if (!empty($result['result'])) {
            $menuItems = $result['result'];
        }

        return view('admin/personale_visibilita_moduli', [
            'menu_items' => $menuItems,
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
            'preselectedId' => (int)($this->request->getGet('id_personale') ?? 0),
        ]);
    }

    public function search()
    {
        if ($guard = $this->ensureAdminApi()) {
            return $guard;
        }

        $model = new PersonaleModel();

        $nome = (string)($this->request->getGet('nome') ?? '');
        $cognome = (string)($this->request->getGet('cognome') ?? '');
        $cf = (string)($this->request->getGet('cf') ?? '');

        $rows = $model->searchPersonaleLike($nome, $cognome, $cf, 30);

        $out = array_map(static function (array $row): array {
            $label = trim((string)($row['cognome'] ?? '') . ' ' . (string)($row['nome'] ?? ''));
            $cf = strtoupper((string)($row['codice_fiscale'] ?? ''));

            return [
                'id_personale' => (int)($row['id_personale'] ?? 0),
                'label' => $label . ($cf !== '' ? ' - Username: ' . $cf : ''),
            ];
        }, $rows);

        $json = json_encode(
            ['ok' => true, 'results' => $out],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $this->response
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setBody($json);
    }

    public function get(int $idPersonale)
    {
        if ($guard = $this->ensureAdminApi()) {
            return $guard;
        }

        $model = new PersonaleModel();
        $users = new UsersModel();

        $personale = $model->getPersonaleDecryptedById($idPersonale);
        if (!$personale) {
            return $this->response
                ->setStatusCode(404)
                ->setJSON(['ok' => false, 'error' => 'Personale non trovato.']);
        }

        $user = null;
        if (!empty($personale['id_user'])) {
            $user = $users->select('id_user, username')
                ->where('id_user', (int)$personale['id_user'])
                ->first();
        }

        return $this->response->setJSON([
            'ok' => true,
            'personale' => $personale,
            'user' => $user ? [
                'id_user' => (int)$user['id_user'],
                'username' => (string)$user['username'],
            ] : null,
        ]);
    }

    public function update()
    {
        if ($guard = $this->ensureAdminPage()) {
            return $guard;
        }

        $idPersonale = (int)($this->request->getPost('id_personale') ?? 0);
        if ($idPersonale <= 0) {
            return redirect()->to(site_url('admin/personale/visibilita-moduli'))
                ->with('errors', ['generic' => 'Seleziona un utente valido.']);
        }

        $model = new PersonaleModel();
        $personale = $model->getPersonaleDecryptedById($idPersonale);
        if (!$personale) {
            return redirect()->to(site_url('admin/personale/visibilita-moduli'))
                ->with('errors', ['generic' => 'Personale non trovato.']);
        }

        $flags = [
            'show_in_agenda' => (int)($this->request->getPost('show_in_agenda') ?? 0) === 1 ? 1 : 0,
            'show_in_posta' => (int)($this->request->getPost('show_in_posta') ?? 0) === 1 ? 1 : 0,
            'show_in_chat' => (int)($this->request->getPost('show_in_chat') ?? 0) === 1 ? 1 : 0,
        ];

        $redirectUrl = site_url('admin/personale/visibilita-moduli') . '?id_personale=' . $idPersonale;

        $ok = $model->updateModuleVisibilityFlags($idPersonale, $flags);
        if (!$ok) {
            return redirect()->to($redirectUrl)
                ->with('errors', ['generic' => 'Errore nel salvataggio dei flag. Verifica che le colonne siano presenti nel database.']);
        }

        return redirect()->to($redirectUrl)
            ->with('success', 'Flag modulo aggiornati con successo.');
    }
}
