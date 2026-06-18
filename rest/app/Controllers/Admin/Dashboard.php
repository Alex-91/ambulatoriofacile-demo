<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class Dashboard extends BaseController
{
    public function index()
    {
        $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) return redirect()->to('/login');

        // solo admin
        if (session()->get('is_admin') !== true && (int)($me->tipo ?? 0) !== 1) {
            return redirect()->to('/');
        }

        $menuAdmin = session()->get('menuDataAdmin');
        $menu_items = $menuAdmin['result'] ?? [];

        return view('admin/dashboard', [
            'menu_items' => $menu_items,
            'pageTitle'  => 'Admin',
        ]);
    }
}
