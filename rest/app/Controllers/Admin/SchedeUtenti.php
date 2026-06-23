<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\UserAdminMenuVisibilityModel;
use App\Models\UsersModel;
use App\Models\UserSchedeModel;
use App\Services\AdminMenuVisibilityService;

class SchedeUtenti extends BaseController
{
    public function index()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        return view('admin/schede_utenti/index');
    }

    // POST: username => ritorna id_user + username
    public function cercaUtente()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $username = trim((string)$this->request->getPost('username'));
        if ($username === '') {
            return $this->response->setJSON(['ok' => false, 'error' => 'Username mancante']);
        }

        $users = new UsersModel();
        $row = $users->where('username', $username)->first();

        if (!$row) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Utente non trovato']);
        }

        return $this->response->setJSON([
            'ok' => true,
            'user' => [
                'id_user'  => (int)$row['id_user'],
                'username' => (string)$row['username'],
            ]
        ]);
    }

    // GET: id_user => lista schede con flags
    public function schedeUtente()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $idUser = (int)($this->request->getGet('id_user') ?? 0);
        if ($idUser <= 0) {
            return $this->response->setJSON(['ok' => false, 'error' => 'id_user non valido']);
        }

        $m = new UserSchedeModel();
        $rows = $m->getSchedeWithUserFlags($idUser);

        return $this->response->setJSON(['ok' => true, 'schede' => $rows]);
    }

    // POST: id_user, id_scheda, field, value
    public function toggle()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $idUser   = (int)$this->request->getPost('id_user');
        $idScheda = (int)$this->request->getPost('id_scheda');
        $field    = (string)$this->request->getPost('field');
        $value    = (int)$this->request->getPost('value');

        if ($idUser <= 0 || $idScheda <= 0) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Parametri non validi']);
        }

        $m = new UserSchedeModel();
        $ok = $m->setFlag($idUser, $idScheda, $field, $value);

        if (!$ok) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Campo non valido']);
        }

        // Ritorno lo stato aggiornato della riga (così la UI si riallinea)
        $rows = $m->getSchedeWithUserFlags($idUser);
        $updated = null;
        foreach ($rows as $r) {
            if ((int)$r['id_scheda'] === $idScheda) { $updated = $r; break; }
        }

        return $this->response->setJSON(['ok' => true, 'updated' => $updated]);
    }

    public function menuAdminUtente()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $idUser = (int)($this->request->getGet('id_user') ?? 0);
        if ($idUser <= 0) {
            return $this->response->setJSON(['ok' => false, 'error' => 'id_user non valido']);
        }

        $service = new AdminMenuVisibilityService();
        if (!$service->isAvailable()) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Prima applica la migration del menu admin per utente.',
            ]);
        }

        return $this->response->setJSON([
            'ok' => true,
            'menu_admin' => $service->getCatalogWithUserFlags($idUser),
        ]);
    }

    public function toggleMenuAdmin()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $idUser = (int)$this->request->getPost('id_user');
        $menuKey = trim((string)$this->request->getPost('menu_key'));
        $value = (int)$this->request->getPost('value');

        if ($idUser <= 0 || $menuKey === '') {
            return $this->response->setJSON(['ok' => false, 'error' => 'Parametri non validi']);
        }

        $service = new AdminMenuVisibilityService(new UserAdminMenuVisibilityModel());
        if (!$service->isAvailable()) {
            return $this->response->setJSON([
                'ok' => false,
                'error' => 'Prima applica la migration del menu admin per utente.',
            ]);
        }

        if (!$service->setUserVisibility($idUser, $menuKey, $value)) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Voce menu non valida']);
        }

        return $this->response->setJSON([
            'ok' => true,
            'updated' => $service->getUserVisibilityItem($idUser, $menuKey),
        ]);
    }

    private function ensureAllowed()
    {
        $isLegacyAdmin = session()->get('is_admin') === true
            || (int)(session()->get('admin') ?? 0) === 1
            || (int)(session()->get('tipoUser') ?? 0) === 1;

        $isPlatformAdmin = (bool)(session()->get('platform_is_admin') ?? false) === true;

        if ($isLegacyAdmin || $isPlatformAdmin) {
            return null;
        }

        return $this->response->setStatusCode(403)->setJSON([
            'ok' => false,
            'error' => 'Non sei autorizzato a gestire i menu utente.',
        ]);
    }
}
