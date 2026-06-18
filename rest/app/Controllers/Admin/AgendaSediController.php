<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AgendaModel;
use App\Models\AgendaLocationModel;

class AgendaSediController extends BaseController
{
    private AgendaModel $agendaModel;
    private AgendaLocationModel $locationModel;

    public function __construct()
    {
        $this->agendaModel = new AgendaModel();
        $this->locationModel = new AgendaLocationModel();
    }

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

        $menuAgenda = method_exists($this->agendaModel, 'getMenuVisibleByUser')
            ? $this->agendaModel->getMenuVisibleByUser((int)($me->id_user ?? 0))
            : [];

        return (int)(session()->get('admin') ?? 0) === 1
            || session()->get('is_admin') === true
            || (int)($me->tipo_pers ?? 0) === 4
            || $this->agendaMenuHasRoute($menuAgenda, 'agenda/gestione-sedi');
    }

    private function agendaMenuHasRoute(array $nodes, string $route): bool
    {
        $route = trim($route, '/');

        foreach ($nodes as $node) {
            $nodeRoute = is_object($node)
                ? trim((string)($node->rotta ?? ''), '/')
                : trim((string)($node['rotta'] ?? ''), '/');

            if ($nodeRoute === $route) {
                return true;
            }

            $children = is_object($node)
                ? ($node->children ?? [])
                : ($node['children'] ?? []);

            if (is_array($children) && $this->agendaMenuHasRoute($children, $route)) {
                return true;
            }
        }

        return false;
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

    private function redirectToIndex(int $idAmbLegacy = 0): \CodeIgniter\HTTP\RedirectResponse
    {
        $url = site_url('agenda/gestione-sedi');
        if ($idAmbLegacy > 0) {
            $url .= '?id_amb_legacy=' . $idAmbLegacy;
        }

        return redirect()->to($url);
    }

    public function index()
    {
        if ($guard = $this->ensureAdminPage()) {
            return $guard;
        }

        $me = $this->currentAdminUser();
        $menuItems = session()->get('header_menu_items') ?? [];
        $result = session()->get('menuDataAdmin');
        if (!empty($result['result'])) {
            $menuItems = $result['result'];
        }

        $catalog = $this->locationModel->getAdminCatalog();
        $selectedAmbId = (int)($this->request->getGet('id_amb_legacy') ?? 0);
        $selectedStanzaId = (int)($this->request->getGet('id_stanza') ?? 0);

        $selectedAmbulatorio = $selectedAmbId > 0 ? $this->locationModel->getAmbulatorioById($selectedAmbId) : null;
        $editingStanza = $selectedStanzaId > 0 ? $this->locationModel->getStanzaById($selectedStanzaId) : null;

        if ($editingStanza && $selectedAmbId <= 0) {
            $selectedAmbId = (int)($editingStanza['id_amb_legacy'] ?? 0);
            $selectedAmbulatorio = $this->locationModel->getAmbulatorioById($selectedAmbId);
        }

        $activeSedi = count(array_filter($catalog, static fn(array $row): bool => !empty($row['attiva'])));
        $activeStanze = 0;
        $totalStanze = 0;
        foreach ($catalog as $row) {
            $totalStanze += count($row['stanze'] ?? []);
            $activeStanze += count(array_filter(
                $row['stanze'] ?? [],
                static fn(array $stanza): bool => !empty($stanza['attiva'])
            ));
        }

        return view('admin/agenda_sedi', [
            'menu_items'          => $menuItems,
            'menuAgenda'          => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                ? $this->agendaModel->getMenuVisibleByUser((int)($me->id_user ?? 0))
                : $this->agendaModel->getMenuVisible(),
            'baseRoute'           => 'agenda/gestione-sedi',
            'catalog'             => $catalog,
            'selectedAmbId'       => $selectedAmbId,
            'selectedAmbulatorio' => $selectedAmbulatorio,
            'editingStanza'       => $editingStanza,
            'success'             => session()->getFlashdata('success'),
            'errors'              => session()->getFlashdata('errors') ?? [],
            'activeSedi'          => $activeSedi,
            'totalSedi'           => count($catalog),
            'activeStanze'        => $activeStanze,
            'totalStanze'         => $totalStanze,
        ]);
    }

    public function saveAmbulatorio()
    {
        if ($guard = $this->ensureAdminPage()) {
            return $guard;
        }

        try {
            $id = $this->locationModel->saveAmbulatorio($this->request->getPost());

            return $this->redirectToIndex($id)
                ->with('success', 'Sede salvata correttamente.');
        } catch (\Throwable $e) {
            $id = (int)($this->request->getPost('id_amb_legacy') ?? 0);

            return $this->redirectToIndex($id)
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function saveStanza()
    {
        if ($guard = $this->ensureAdminPage()) {
            return $guard;
        }

        try {
            $post = $this->request->getPost();
            $idAmb = (int)($post['id_amb_legacy'] ?? 0);
            $this->locationModel->saveStanza($post);

            return $this->redirectToIndex($idAmb)
                ->with('success', 'Stanza salvata correttamente.');
        } catch (\Throwable $e) {
            $idAmb = (int)($this->request->getPost('id_amb_legacy') ?? 0);

            return $this->redirectToIndex($idAmb)
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function toggleAmbulatorio()
    {
        if ($guard = $this->ensureAdminPage()) {
            return $guard;
        }

        $id = (int)($this->request->getPost('id_amb_legacy') ?? 0);
        $active = (int)($this->request->getPost('attiva') ?? 0) === 1;

        if ($this->locationModel->setAmbulatorioActive($id, $active)) {
            return $this->redirectToIndex($id)
                ->with('success', $active ? 'Sede attivata.' : 'Sede disattivata.');
        }

        return $this->redirectToIndex($id)
            ->with('errors', ['generic' => 'Impossibile aggiornare lo stato della sede.']);
    }

    public function toggleStanza()
    {
        if ($guard = $this->ensureAdminPage()) {
            return $guard;
        }

        $idStanza = (int)($this->request->getPost('id_stanza') ?? 0);
        $idAmb = (int)($this->request->getPost('id_amb_legacy') ?? 0);
        $active = (int)($this->request->getPost('attiva') ?? 0) === 1;

        if ($this->locationModel->setStanzaActive($idStanza, $active)) {
            return $this->redirectToIndex($idAmb)
                ->with('success', $active ? 'Stanza attivata.' : 'Stanza disattivata.');
        }

        return $this->redirectToIndex($idAmb)
            ->with('errors', ['generic' => 'Impossibile aggiornare lo stato della stanza.']);
    }
}
