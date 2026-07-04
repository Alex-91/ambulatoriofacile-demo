<?php

namespace App\Services;

use App\Helpers\admin_menu_pretty_title;
use App\Models\UserAdminMenuVisibilityModel;
use Config\Database;

class AdminMenuVisibilityService
{
    private UserAdminMenuVisibilityModel $visibilityModel;
    private TenantAdminMenuService $tenantAdminMenu;
    private MenuRegistryService $menuRegistry;
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct(
        ?UserAdminMenuVisibilityModel $visibilityModel = null,
        ?TenantAdminMenuService $tenantAdminMenu = null,
        ?MenuRegistryService $menuRegistry = null
    ) {
        helper('admin_menu');
        $this->visibilityModel = $visibilityModel ?? new UserAdminMenuVisibilityModel();
        $this->tenantAdminMenu = $tenantAdminMenu ?? new TenantAdminMenuService();
        $this->menuRegistry = $menuRegistry ?? new MenuRegistryService();
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
                'route_prefixes' => $this->normalizeRoutePrefixes((array) ($item['route_prefixes'] ?? $this->routePrefixesForLink($normalizedLink))),
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
        $items = [];
        foreach ($this->menuRegistry->tenantContextCatalog() as $item) {
            $menuKey = trim((string) ($item['key'] ?? ''));
            if ($menuKey === '') {
                continue;
            }

            $items[] = [
                'menu_key' => $menuKey,
                'menu_link' => trim((string) ($item['link'] ?? $menuKey)),
                'titolo' => trim((string) ($item['title'] ?? '')) ?: admin_menu_pretty_title('', $menuKey),
                'descrizione' => trim((string) ($item['description'] ?? '')),
                'gruppo' => trim((string) ($item['group'] ?? 'Console spazio')) ?: 'Console spazio',
                'ordine' => (int) ($item['order'] ?? 0),
                'route_prefixes' => $this->normalizeRoutePrefixes((array) ($item['route_prefixes'] ?? [])),
            ];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function routePrefixesForLink(string $normalizedLink): array
    {
        $agendaLegacyPrefixes = match ($normalizedLink) {
            'agenda/gestione-ferie' => [
                'agenda/gestione-ferie',
                'agenda/salva-ferie-periodo',
            ],
            'agenda/elenco-ferie' => [
                'agenda/elenco-ferie',
                'agenda/elimina-giorno-ferie',
                'agenda/elimina-giorni-ferie-selezionati',
            ],
            'agenda/slot-bloccati' => [
                'agenda/slot-bloccati',
                'slot-bloccati',
                'agenda/sblocca-slot-bloccato',
            ],
            'agenda/gestione-tipi-visita' => [
                'agenda/gestione-tipi-visita',
                'agenda/tipi-visita',
                'agenda/salva-tipo-visita',
                'agenda/toggle-tipo-visita',
            ],
            default => [],
        };

        if ($agendaLegacyPrefixes !== []) {
            return $this->normalizeRoutePrefixes($agendaLegacyPrefixes);
        }

        $registryItem = $this->menuRegistry->findTenantAdminItem($normalizedLink);
        if (is_array($registryItem)) {
            return $this->normalizeRoutePrefixes((array) ($registryItem['route_prefixes'] ?? []));
        }

        return ['admin/' . trim($normalizedLink, '/')];
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

    /**
     * @param list<string> $prefixes
     * @return list<string>
     */
    private function normalizeRoutePrefixes(array $prefixes): array
    {
        $normalized = [];
        foreach ($prefixes as $prefix) {
            $prefix = $this->normalizeRequestPath((string) $prefix);
            if ($prefix !== '' && !in_array($prefix, $normalized, true)) {
                $normalized[] = $prefix;
            }
        }

        return $normalized;
    }
}
