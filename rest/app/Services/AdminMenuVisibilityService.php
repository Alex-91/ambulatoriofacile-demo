<?php

namespace App\Services;

use App\Helpers\admin_menu_pretty_title;
use App\Models\UserAdminMenuVisibilityModel;
use Config\Database;

class AdminMenuVisibilityService
{
    private UserAdminMenuVisibilityModel $visibilityModel;
    private TenantAdminMenuService $tenantAdminMenu;
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct(
        ?UserAdminMenuVisibilityModel $visibilityModel = null,
        ?TenantAdminMenuService $tenantAdminMenu = null
    ) {
        helper('admin_menu');
        $this->visibilityModel = $visibilityModel ?? new UserAdminMenuVisibilityModel();
        $this->tenantAdminMenu = $tenantAdminMenu ?? new TenantAdminMenuService();
        $this->db = Database::connect();
    }

    public function isAvailable(): bool
    {
        return $this->visibilityModel->isAvailable();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        $items = [];

        foreach ($this->tenantAdminMenu->catalog() as $item) {
            $normalizedLink = $this->normalizeMenuLink((string) ($item['link'] ?? ''));
            if ($normalizedLink === '') {
                continue;
            }

            $items[$normalizedLink] = [
                'menu_key' => $normalizedLink,
                'menu_link' => $normalizedLink,
                'titolo' => trim((string) ($item['title'] ?? '')) ?: admin_menu_pretty_title('', $normalizedLink),
                'descrizione' => trim((string) ($item['description'] ?? '')),
                'gruppo' => 'Menu operativo',
                'ordine' => (int) ($item['order'] ?? 0),
                'route_prefixes' => $this->routePrefixesForLink($normalizedLink),
            ];
        }

        foreach ($this->contextCatalog() as $item) {
            $items[$item['menu_key']] = $item;
        }

        $currentMenuRows = $this->loadCurrentAdminMenuRows();
        foreach ($currentMenuRows as $row) {
            $normalizedLink = $this->normalizeMenuLink($this->resolveRowLink($row));
            if ($normalizedLink === '' || isset($items[$normalizedLink])) {
                continue;
            }

            $title = trim((string) ($row['titolo_menu'] ?? ''));
            $items[$normalizedLink] = [
                'menu_key' => $normalizedLink,
                'menu_link' => $normalizedLink,
                'titolo' => $title !== '' ? $title : admin_menu_pretty_title('', $normalizedLink),
                'descrizione' => 'Voce menu operativa configurata nel tenant.',
                'gruppo' => 'Menu operativo',
                'ordine' => 5000,
                'route_prefixes' => $this->routePrefixesForLink($normalizedLink),
            ];
        }

        $items = array_values($items);
        usort($items, static function (array $left, array $right): int {
            $groupCompare = strcmp((string) ($left['gruppo'] ?? ''), (string) ($right['gruppo'] ?? ''));
            if ($groupCompare !== 0) {
                return $groupCompare;
            }

            $orderCompare = ((int) ($left['ordine'] ?? 0)) <=> ((int) ($right['ordine'] ?? 0));
            if ($orderCompare !== 0) {
                return $orderCompare;
            }

            return strcmp((string) ($left['titolo'] ?? ''), (string) ($right['titolo'] ?? ''));
        });

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCatalogWithUserFlags(int $idUser): array
    {
        $visibilityMap = $this->getVisibilityMapForUser($idUser);
        $items = $this->catalog();

        foreach ($items as &$item) {
            $menuKey = (string) ($item['menu_key'] ?? '');
            $item['can_view'] = array_key_exists($menuKey, $visibilityMap)
                ? (int) $visibilityMap[$menuKey]
                : 1;
        }
        unset($item);

        return $items;
    }

    public function canUserSeeMenuLink(int $idUser, string $link): bool
    {
        $menuKey = $this->resolveKeyFromMenuLink($link);
        if ($menuKey === null || $idUser <= 0 || !$this->isAvailable()) {
            return true;
        }

        $visibilityMap = $this->getVisibilityMapForUser($idUser);
        return !array_key_exists($menuKey, $visibilityMap) || (int) $visibilityMap[$menuKey] === 1;
    }

    public function canUserSeeMenuKey(int $idUser, string $menuKey): bool
    {
        $menuKey = trim($menuKey);
        if ($menuKey === '' || $idUser <= 0 || !$this->isAvailable()) {
            return true;
        }

        $visibilityMap = $this->getVisibilityMapForUser($idUser);
        return !array_key_exists($menuKey, $visibilityMap) || (int) $visibilityMap[$menuKey] === 1;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function filterMenuRowsForUser(array $rows, int $idUser): array
    {
        if ($idUser <= 0 || !$this->isAvailable() || $rows === []) {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($this->canUserSeeMenuLink($idUser, $this->resolveRowLink($row))) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @return list<array<string, mixed>>
     */
    public function filterContextActionsForUser(array $actions, int $idUser): array
    {
        if ($idUser <= 0 || !$this->isAvailable() || $actions === []) {
            return $actions;
        }

        $filtered = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $link = (string) ($action['link'] ?? $action['href'] ?? '');
            if ($this->canUserSeeMenuLink($idUser, $link)) {
                $filtered[] = $action;
            }
        }

        return $filtered;
    }

    public function resolveManagedKeyForRequestPath(string $path): ?string
    {
        $normalizedPath = $this->normalizeRequestPath($path);
        if ($normalizedPath === '') {
            return null;
        }

        foreach ($this->catalog() as $item) {
            foreach ((array) ($item['route_prefixes'] ?? []) as $prefix) {
                $prefix = $this->normalizeRequestPath((string) $prefix);
                if ($prefix === '') {
                    continue;
                }

                if ($normalizedPath === $prefix || str_starts_with($normalizedPath, $prefix . '/')) {
                    return (string) ($item['menu_key'] ?? '');
                }
            }
        }

        return null;
    }

    public function setUserVisibility(int $idUser, string $menuKey, int $canView): bool
    {
        $menuKey = trim($menuKey);
        if ($menuKey === '' || !$this->isAvailable()) {
            return false;
        }

        foreach ($this->catalog() as $item) {
            if ((string) ($item['menu_key'] ?? '') !== $menuKey) {
                continue;
            }

            return $this->visibilityModel->setVisibility(
                $idUser,
                $menuKey,
                (string) ($item['menu_link'] ?? $menuKey),
                $canView === 1 ? 1 : 0
            );
        }

        return false;
    }

    public function getUserVisibilityItem(int $idUser, string $menuKey): ?array
    {
        foreach ($this->getCatalogWithUserFlags($idUser) as $item) {
            if ((string) ($item['menu_key'] ?? '') === trim($menuKey)) {
                return $item;
            }
        }

        return null;
    }

    public function normalizeMenuLink(string $link): string
    {
        $normalized = trim((string) $link);
        if ($normalized === '' || $normalized === '#') {
            return '';
        }

        $parsedPath = parse_url($normalized, PHP_URL_PATH);
        if (is_string($parsedPath) && $parsedPath !== '') {
            $normalized = $parsedPath;
        }

        $normalized = trim(str_replace('\\', '/', $normalized), '/');
        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, 'login/')) {
            $normalized = substr($normalized, strlen('login/'));
        }

        if (str_starts_with($normalized, 'admin/')) {
            $normalized = substr($normalized, strlen('admin/'));
        }

        $normalized = trim($normalized, '/');
        if ($normalized === '') {
            return '';
        }

        $normalized = match ($normalized) {
            'personale/logs' => 'logs',
            'personale/sostituti' => 'sostituti',
            'schede-utenti' => 'personale/schede-utenti',
            default => $normalized,
        };

        return $this->tenantAdminMenu->normalizeLink($normalized);
    }

    private function resolveKeyFromMenuLink(string $link): ?string
    {
        $normalizedLink = $this->normalizeMenuLink($link);
        if ($normalizedLink === '') {
            return null;
        }

        foreach ($this->catalog() as $item) {
            if ((string) ($item['menu_key'] ?? '') === $normalizedLink) {
                return $normalizedLink;
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    private function getVisibilityMapForUser(int $idUser): array
    {
        $map = [];
        foreach ($this->visibilityModel->getRowsForUser($idUser) as $row) {
            $menuKey = trim((string) ($row['menu_key'] ?? ''));
            if ($menuKey === '') {
                continue;
            }

            $map[$menuKey] = (int) ($row['can_view'] ?? 0);
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contextCatalog(): array
    {
        return [
            [
                'menu_key' => 'spazio/utenti',
                'menu_link' => 'spazio/utenti',
                'titolo' => 'Gestisci utenti dello spazio',
                'descrizione' => 'Voce rapida della console spazio per utenti e inviti.',
                'gruppo' => 'Console spazio',
                'ordine' => 2000,
                'route_prefixes' => [
                    'spazio/utenti',
                    'spazio/utenti/save',
                    'spazio/utenti/accesso',
                    'login/spazio/utenti',
                    'login/spazio/utenti/save',
                    'login/spazio/utenti/accesso',
                ],
            ],
            [
                'menu_key' => 'spazio/funzioni',
                'menu_link' => 'spazio/funzioni',
                'titolo' => 'Gestisci funzioni dello spazio',
                'descrizione' => 'Voce rapida della console spazio per attivare o disattivare feature.',
                'gruppo' => 'Console spazio',
                'ordine' => 2100,
                'route_prefixes' => [
                    'spazio/funzioni',
                    'spazio/funzioni/save',
                    'login/spazio/funzioni',
                    'login/spazio/funzioni/save',
                ],
            ],
            [
                'menu_key' => 'spazio/notifiche-appuntamenti',
                'menu_link' => 'spazio/notifiche-appuntamenti',
                'titolo' => 'Gestisci notifiche appuntamenti',
                'descrizione' => 'Voce rapida della console spazio per reminder e notifiche operative.',
                'gruppo' => 'Console spazio',
                'ordine' => 2200,
                'route_prefixes' => [
                    'spazio/notifiche-appuntamenti',
                    'spazio/notifiche-appuntamenti/save',
                    'login/spazio/notifiche-appuntamenti',
                    'login/spazio/notifiche-appuntamenti/save',
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function routePrefixesForLink(string $normalizedLink): array
    {
        return match ($normalizedLink) {
            'personale/nuovo' => ['admin/personale/nuovo'],
            'personale/nuovo_cliente' => ['admin/personale/nuovo_cliente'],
            'personale/modifica_personale' => [
                'admin/personale/modifica_personale',
                'admin/personale/search',
                'admin/personale/get',
                'admin/personale/update',
                'admin/personale/elimina-dottore',
            ],
            'personale/modifica_cliente' => [
                'admin/personale/modifica_cliente',
                'admin/clienti/search',
                'admin/clienti/get',
                'admin/clienti/update',
                'admin/clienti/device/disconnect',
            ],
            'agenda/gestione-sedi' => [
                'agenda/gestione-sedi',
                'admin/anagrafica/sedi',
                'admin/anagrafica/sedi/save',
                'admin/anagrafica/sedi/toggle',
                'admin/anagrafica/sedi/stanza/save',
                'admin/anagrafica/sedi/stanza/toggle',
            ],
            'personale/visibilita-moduli' => [
                'admin/personale/visibilita-moduli',
                'admin/personale/visibilita-moduli/search',
                'admin/personale/visibilita-moduli/get',
                'admin/personale/visibilita-moduli/update',
            ],
            'personale/dap14' => [
                'admin/personale/dap14',
                'admin/personale/dap14/update',
            ],
            'personale/dap15' => [
                'admin/personale/dap15',
                'admin/personale/dap15/update',
            ],
            'personale/schede-utenti' => [],
            'sostituti' => [
                'admin/personale/sostituti',
                'admin/sostituti/salva',
                'admin/sostituti/elimina',
            ],
            'otp-statistiche' => [
                'admin/otp-statistiche',
                'admin/otp-statistiche/csv',
            ],
            'whatsapp-reminders' => [
                'admin/whatsapp-reminders',
                'admin/whatsapp-reminders/launch',
                'admin/whatsapp-reminders/run',
            ],
            'logs' => [
                'admin/personale/logs',
                'admin/logs/read',
                'admin/logs/download',
                'admin/logs/list',
            ],
            default => ['admin/' . trim($normalizedLink, '/')],
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveRowLink(array $row): string
    {
        $link2 = trim((string) ($row['link2'] ?? ''));
        if ($link2 !== '' && $link2 !== '#') {
            return $link2;
        }

        return trim((string) ($row['link'] ?? ''));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCurrentAdminMenuRows(): array
    {
        if (!$this->db->tableExists('dap06_mnu')) {
            return [];
        }

        return $this->db->table('dap06_mnu')
            ->select('titolo_menu, link, link2, class_icon, ordinamento')
            ->where('admin', 1)
            ->orderBy('ordinamento', 'ASC')
            ->orderBy('id_mnu', 'ASC')
            ->get()
            ->getResultArray();
    }

    private function normalizeRequestPath(string $path): string
    {
        $normalized = trim((string) $path);
        if ($normalized === '') {
            return '';
        }

        $parsedPath = parse_url($normalized, PHP_URL_PATH);
        if (is_string($parsedPath) && $parsedPath !== '') {
            $normalized = $parsedPath;
        }

        return trim(str_replace('\\', '/', $normalized), '/');
    }
}
