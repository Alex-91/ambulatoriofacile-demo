<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Libraries\Crypto_helper;

class PasswordController extends BaseController
{
    public function index()
    {
        // devo essere passato dal login scaduto
        if (!session()->get('isLoggedIn') || (int)session()->get('forcePwdChange') !== 1) {
            return redirect()->to(site_url('login'));
        }

        if ((int)session()->get('otp_ok_for_expired') !== 1) {
            session()->set('pwd_expired_flow', 1);
            return redirect()->to(site_url('auth'))->with('error', 'Verifica OTP richiesta.');
        }

        return view('password/scaduta'); // la view che ti scrivo sotto
    }

    public function update()
    {
        if (!session()->get('isLoggedIn') || (int)session()->get('forcePwdChange') !== 1) {
            return $this->response->setJSON(['ok' => false, 'err' => 'not_allowed'])->setStatusCode(403);
        }

        if ((int)session()->get('otp_ok_for_expired') !== 1) {
            session()->set('pwd_expired_flow', 1);
            return $this->response->setJSON([
                'ok' => false,
                'err' => 'otp_required',
                'redirectUrl' => 'auth',
            ])->setStatusCode(403);
        }

        $idUser = (int)(session()->get('pwd_userId') ?? 0);
        if ($idUser <= 0) {
            return $this->response->setJSON(['ok' => false, 'err' => 'missing_user'])->setStatusCode(400);
        }

        $json = $this->request->getJSON(true) ?? [];
        $p1 = (string)($json['password'] ?? '');
        $p2 = (string)($json['password2'] ?? '');

        // 1) match
        if ($p1 === '' || $p2 === '' || $p1 !== $p2) {
            return $this->response->setJSON(['ok' => false, 'err' => 'password_mismatch'])->setStatusCode(400);
        }

        // 2) regole: >=8, 1 maiuscola, 1 minuscola, 1 speciale
        $hasLen = (strlen($p1) >= 8);
        $hasUp  = (bool)preg_match('/[A-Z]/', $p1);
        $hasLo  = (bool)preg_match('/[a-z]/', $p1);
        $hasSp  = (bool)preg_match('/[^A-Za-z0-9]/', $p1);

        if (!$hasLen || !$hasUp || !$hasLo || !$hasSp) {
            return $this->response->setJSON([
                'ok' => false,
                'err' => 'rules',
                'rules' => [
                    'length'    => $hasLen,
                    'uppercase' => $hasUp,
                    'lowercase' => $hasLo,
                    'special'   => $hasSp,
                ]
            ])->setStatusCode(400);
        }

        $db = \Config\Database::connect();
        $crypto = new Crypto_helper();

        // password escapata per inserirla nella funzione encrypt_select_login(...)
        $pwdEsc = $db->escape($p1);

        // 3) controllo "non uguale a quella scaduta"
        // confronto l'espressione AES con quella salvata, SENZA decrypt
        $sqlSame = "
            SELECT 1
            FROM dap01_users
            WHERE id_user = ?
              AND password = " . $crypto->encrypt_select_login($pwdEsc) . "
            LIMIT 1
        ";
        $same = $db->query($sqlSame, [$idUser])->getRowArray();
        if ($same) {
            return $this->response->setJSON(['ok' => false, 'err' => 'same_as_old'])->setStatusCode(400);
        }

        // 4) aggiorno password + nuova scadenza (esempio: 90 giorni)
        $sqlUpd = "
            UPDATE dap01_users
            SET password = " . $crypto->encrypt_select_login($pwdEsc) . ",
                datascadenza = DATE_ADD(CURDATE(), INTERVAL 90 DAY)
            WHERE id_user = ?
            LIMIT 1
        ";
        $db->query($sqlUpd, [$idUser]);

        if ($db->affectedRows() <= 0) {
            return $this->response->setJSON(['ok' => false, 'err' => 'update_failed'])->setStatusCode(500);
        }

        // 5) tolgo forzatura e riporto l'utente al login con la nuova password.
        session()->remove('forcePwdChange');
        session()->remove('pwd_userId');
        session()->remove('pwd_username');
        session()->remove('pwd_expired_flow');
        session()->remove('otp_ok_for_expired');
        session()->remove('otp');
        session()->remove('isLoggedIn');
        session()->remove('isLoggedInConfirmed');
        session()->remove('userId');
        session()->remove('id_user');
        session()->remove('username');
        session()->remove('tipoUser');
        session()->remove('cellulare');
        session()->remove('utente_sess');

        return $this->response->setJSON(['ok' => true, 'redirectUrl' => 'login']);
    }
}
