<?php

namespace App\Controllers;

use App\Models\SchedeModel;
use App\Services\SessionNavigationService;
use CodeIgniter\Controller;

class Home extends Controller
{
    private SessionNavigationService $navigation;

    public function __construct()
    {
        $this->navigation = new SessionNavigationService();
    }

    public function index()
    {
        helper(['portal', 'session_auth']);

        if ((bool)session()->get('isLoggedIn') && !$this->isLogged()) {
            if ((int)session()->get('forcePwdChange') === 1) {
                return redirect()->to(site_url('password/scaduta'));
            }

            return redirect()->to(site_url('auth'));
        }

        if (!$this->isLogged()) {
            if ($this->isVisibleAppEntryRequest()) {
                return redirect()->to(portal_public_access_url('login'));
            }

            return view('login/login');
        }

        $this->refreshHeaderSession();

        $session = session();
        $utente = $session->get('utente_sess');

        $idUser = (int)($session->get('id_user') ?? 0);
        if ($idUser <= 0 && is_object($utente) && isset($utente->id_user)) {
            $idUser = (int)$utente->id_user;
        }

        $badgePosta = (int)($session->get('badge_posta_unread') ?? 0);
        $badgeChat = (int)($session->get('badge_chat_unread') ?? 0);

        $schede = session()->get('schede_data');
        if (!is_array($schede)) {
            $schede = $idUser > 0
                ? (new SchedeModel())->getSchedeForUser($idUser, $badgePosta, $badgeChat)
                : [];
        }

        return view('index', [
            'schede'       => $schede,
            'chat_unread'  => $badgeChat,
            'posta_unread' => $badgePosta,
        ]);
    }

    public function posta()
    {
        if (!$this->isLogged()) {
            return redirect()->to('/login');
        }

        if (!$this->userCanAccess('posta')) {
            return redirect()->to('/')->with('error', 'Non sei autorizzato.');
        }

        $this->refreshHeaderSession();

        return view('posta', [
            'menu_items'   => session()->get('header_menu_items') ?? [],
            'chat_unread'  => session()->get('badge_chat_unread') ?? 0,
            'posta_unread' => session()->get('badge_posta_unread') ?? 0,
        ]);
    }

    public function agenda()
    {
        if (!$this->isLogged()) {
            return redirect()->to('/login');
        }

        if (!$this->userCanAccess('agenda')) {
            return redirect()->to('/')->with('error', 'Non sei autorizzato.');
        }

        $this->refreshHeaderSession();

        return view('agenda', [
            'menu_items'   => session()->get('header_menu_items') ?? [],
            'chat_unread'  => session()->get('badge_chat_unread') ?? 0,
            'posta_unread' => session()->get('badge_posta_unread') ?? 0,
        ]);
    }

    public function chat()
    {
        if (!$this->isLogged()) {
            return redirect()->to('/login');
        }

        if (!$this->userCanAccess('chat')) {
            return redirect()->to('/')->with('error', 'Non sei autorizzato.');
        }

        $this->refreshHeaderSession();

        return view('chat', [
            'menu_items'   => session()->get('header_menu_items') ?? [],
            'chat_unread'  => session()->get('badge_chat_unread') ?? 0,
            'posta_unread' => session()->get('badge_posta_unread') ?? 0,
        ]);
    }

    private function isLogged(): bool
    {
        return session_access_is_confirmed();
    }

    private function userCanAccess(string $codiceScheda): bool
    {
        $session = session();
        $accessMap = $session->get('schede_access_map');
        if (is_array($accessMap) && array_key_exists($codiceScheda, $accessMap)) {
            return (int)$accessMap[$codiceScheda] === 1;
        }

        $utente = $session->get('utente_sess');
        $idUser = (int)($session->get('id_user') ?? 0);
        if ($idUser <= 0 && is_object($utente) && isset($utente->id_user)) {
            $idUser = (int)$utente->id_user;
        }
        if ($idUser <= 0) {
            return false;
        }

        return (new SchedeModel())->userCanAccessCodice($idUser, $codiceScheda);
    }

    private function refreshHeaderSession(): void
    {
        $this->navigation->refreshCurrentSession();
    }

    private function isVisibleAppEntryRequest(): bool
    {
        $requestUri = (string) ($_SERVER['AF_ORIGINAL_REQUEST_URI'] ?? $_SERVER['REQUEST_URI'] ?? '');
        $path = trim((string) parse_url($requestUri, PHP_URL_PATH), '/');

        return $path === 'app';
    }
}
