<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\UsersModel;
use App\Models\UserSchedeModel;

class SchedeUtenti extends BaseController
{
    public function index()
    {
        return view('admin/schede_utenti/index');
    }

    // POST: username => ritorna id_user + username
    public function cercaUtente()
    {
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
}
