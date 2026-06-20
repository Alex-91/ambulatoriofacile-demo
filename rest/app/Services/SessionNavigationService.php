<?php

namespace App\Services;

use App\Libraries\TenantFeatureRegistry;
use App\Config\MessageRoles;
use App\Libraries\DatabaseConfig;
use App\Models\MenuModel;
use App\Models\SchedeModel;
use Config\Database;

class SessionNavigationService
{
    private const REFRESH_TTL_SECONDS = 8;

    private \CodeIgniter\Database\BaseConnection $db;
    private MenuModel $menuModel;
    private SchedeModel $schedeModel;
    private MessageService $messageService;
    private StaffDoctorAccessService $staffAccessService;
    private TenantContextService $tenantContext;

    public function __construct()
    {
        $this->db = Database::connect();
        $this->menuModel = new MenuModel();
        $this->schedeModel = new SchedeModel();
        $this->messageService = new MessageService($this->db, new DatabaseConfig());
        $this->staffAccessService = new StaffDoctorAccessService($this->db);
        $this->tenantContext = new TenantContextService();
    }

    public function refreshCurrentSession(bool $force = false): void
    {
        $session = session();
        $utente = $session->get('utente_sess');

        if (!is_object($utente)) {
            return;
        }

        $idUser = $this->resolveSessionUserId($utente, $session);
        if ($idUser <= 0) {
            return;
        }

        $fingerprint = $this->buildFingerprint($session, $utente, $idUser);
        if (!$force && $this->hasFreshState($session, $fingerprint)) {
            return;
        }

        $badgeChat = $this->countChatUnread($idUser);

        if ((int)($session->get('forceOnlyPosta') ?? 0) === 1) {
            $badgePosta = $this->countDirectPostaUnread($utente);
            $menuData = $this->applyBadgeToForceOnlyPostaMenu($session->get('menuData'), $badgePosta);

            $session->set([
                'menuData'            => $menuData,
                'badge_posta_unread'  => $badgePosta,
                'badge_chat_unread'   => $badgeChat,
                'header_nav_items'    => $this->buildForceOnlyPostaNavItems($badgePosta),
                'header_menu_items'   => $this->buildForceOnlyPostaHeaderMenuItems($badgePosta),
                'schede_access_map'   => ['posta' => 1],
                'schede_data'         => [[
                    'id_scheda'   => 0,
                    'codice'      => 'posta',
                    'titolo'      => 'Posta',
                    'url'         => 'posta',
                    'ordine'      => 0,
                    'badge_tipo'  => 'posta',
                    'icon_svg'    => null,
                    'aria_label'  => 'Vai a Posta',
                    'can_view'    => 1,
                    'can_access'  => 1,
                    'badge'       => $badgePosta,
                ]],
                'nav_refresh_meta'    => [
                    'fingerprint'  => $fingerprint,
                    'refreshed_at' => time(),
                ],
            ]);

            return;
        }

        $menuData = $this->menuModel->getMenuForUser($idUser, $utente);
        if (!is_array($menuData)) {
            return;
        }

        $menuData = $this->applyTenantFeatureVisibilityToMenu($menuData);
        $badgePosta = $this->extractInboxPostaBadge($menuData, (int)($session->get('badge_posta_unread') ?? 0));
        $schede = $this->schedeModel->getSchedeForUser($idUser, $badgePosta, $badgeChat);
        $schede = $this->applyTenantFeatureVisibilityToSchede($schede);
        $header = $this->buildHeaderArtifacts($schede);

        $session->set([
            'menuData'           => $menuData,
            'badge_posta_unread' => $badgePosta,
            'badge_chat_unread'  => $badgeChat,
            'header_nav_items'   => $header['nav_items'],
            'header_menu_items'  => $header['menu_items'],
            'schede_access_map'  => $header['access_map'],
            'schede_data'        => $schede,
            'nav_refresh_meta'   => [
                'fingerprint'  => $fingerprint,
                'refreshed_at' => time(),
            ],
        ]);
    }

    public function invalidateCurrentSession(): void
    {
        session()->remove('nav_refresh_meta');
    }

    private function hasFreshState($session, string $fingerprint): bool
    {
        $meta = $session->get('nav_refresh_meta');
        if (!is_array($meta)) {
            return false;
        }

        if (($meta['fingerprint'] ?? '') !== $fingerprint) {
            return false;
        }

        $refreshedAt = (int)($meta['refreshed_at'] ?? 0);
        if ($refreshedAt <= 0 || (time() - $refreshedAt) > self::REFRESH_TTL_SECONDS) {
            return false;
        }

        if (!is_array($session->get('header_nav_items')) || !is_array($session->get('header_menu_items'))) {
            return false;
        }

        if ($session->get('badge_posta_unread') === null || $session->get('badge_chat_unread') === null) {
            return false;
        }

        if ((int)($session->get('forceOnlyPosta') ?? 0) === 1) {
            return is_array($session->get('menuData'));
        }

        return is_array($session->get('menuData')) && is_array($session->get('schede_access_map'));
    }

    private function resolveSessionUserId(object $utente, $session): int
    {
        $idUser = (int)($session->get('id_user') ?? 0);
        if ($idUser > 0) {
            return $idUser;
        }

        return (int)($utente->id_user ?? 0);
    }

    private function buildFingerprint($session, object $utente, int $idUser): string
    {
        return implode(':', [
            $idUser,
            (int)($session->get('tipoUser') ?? 0),
            (int)($utente->tipo_pers ?? 0),
            (int)($session->get('acting_as_sostituto') ?? 0),
            (int)($session->get('forceOnlyPosta') ?? 0),
        ]);
    }

    private function buildHeaderArtifacts(array $schede): array
    {
        $navItems = [];
        $menuItems = [];
        $accessMap = [];

        foreach ($schede as $scheda) {
            if (!is_array($scheda)) {
                continue;
            }

            $codice = (string)($scheda['codice'] ?? '');
            if ($codice !== '') {
                $accessMap[$codice] = (int)($scheda['can_access'] ?? 0) === 1 ? 1 : 0;
            }

            if ((int)($scheda['can_access'] ?? 0) !== 1) {
                continue;
            }

            $faIcon = match ($codice) {
                'agenda' => 'fa-calendar',
                'posta'  => 'fa-envelope',
                'chat'   => 'fa-comments',
                default  => 'fa-circle',
            };

            $badge = (int)($scheda['badge'] ?? 0);
            $titolo = (string)($scheda['titolo'] ?? '');
            $link = (string)($scheda['url'] ?? '');

            $navItems[] = [
                'codice'  => $codice,
                'titolo'  => $titolo,
                'link'    => $link,
                'badge'   => $badge,
                'fa_icon' => $faIcon,
            ];

            $menuItems[] = [
                'titolo_menu' => $titolo,
                'link'        => $link,
                'conteggio'   => $badge,
                'class'       => '',
                'icon'        => $faIcon,
            ];
        }

        return [
            'nav_items'  => $navItems,
            'menu_items' => $menuItems,
            'access_map' => $accessMap,
        ];
    }

    private function buildForceOnlyPostaNavItems(int $badgePosta): array
    {
        return [[
            'codice'  => 'posta',
            'titolo'  => 'Posta',
            'link'    => 'posta',
            'badge'   => max(0, $badgePosta),
            'fa_icon' => 'fa-envelope',
        ]];
    }

    private function buildForceOnlyPostaHeaderMenuItems(int $badgePosta): array
    {
        return [[
            'titolo_menu' => 'Posta',
            'link'        => 'posta',
            'conteggio'   => max(0, $badgePosta),
            'class'       => '',
            'icon'        => 'fa-envelope',
        ]];
    }

    private function applyBadgeToForceOnlyPostaMenu($menuData, int $badgePosta): array
    {
        if (!is_array($menuData)) {
            $menuData = [
                'result'       => [],
                'cont_dottori' => null,
                'dottori'      => null,
                'resultLogout' => null,
            ];
        }

        $rows = $menuData['result'] ?? [];
        if (!is_array($rows) || $rows === []) {
            $rows = [
                [
                    'titolo_menu' => 'Posta in Arrivo',
                    'link'        => 'messaggi/inbox',
                    'conteggio'   => 0,
                    'class'       => '',
                    'icon'        => 'fa-inbox',
                ],
                [
                    'titolo_menu' => 'Posta in Uscita',
                    'link'        => 'messaggi/inviati',
                    'conteggio'   => 0,
                    'class'       => '',
                    'icon'        => 'fa-paper-plane-o',
                ],
                [
                    'titolo_menu' => 'Bozze',
                    'link'        => 'messaggi/bozze',
                    'conteggio'   => 0,
                    'class'       => '',
                    'icon'        => 'fa-file-text-o',
                ],
                [
                    'titolo_menu' => 'Logout',
                    'link'        => 'logout',
                    'conteggio'   => 0,
                    'class'       => 'logout',
                    'icon'        => 'fa-sign-out',
                ],
            ];
        }

        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }

            $link = strtolower(trim((string)($row['link'] ?? '')));
            $titolo = strtolower(trim((string)($row['titolo_menu'] ?? '')));
            $isInbox = str_contains($link, 'messaggi/inbox')
                || str_starts_with($link, 'posta')
                || str_contains($titolo, 'posta in arrivo')
                || $titolo === 'posta';

            if ($isInbox) {
                $row['conteggio'] = max(0, $badgePosta);
            }
        }
        unset($row);

        $menuData['result'] = $rows;
        $menuData['cont_dottori'] = $menuData['cont_dottori'] ?? null;
        $menuData['dottori'] = $menuData['dottori'] ?? null;
        $menuData['resultLogout'] = $menuData['resultLogout'] ?? null;

        return $menuData;
    }

    private function extractInboxPostaBadge(array $menuData, int $fallback = 0): int
    {
        $rows = $menuData['result'] ?? [];
        if (!is_array($rows)) {
            return $fallback;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $link = strtolower(trim((string)($row['link'] ?? '')));
            $titolo = strtolower(trim((string)($row['titolo_menu'] ?? '')));
            $exclude = str_contains($link, 'inviata')
                || str_contains($link, 'bozze')
                || str_contains($link, 'draft')
                || str_contains($link, 'scrivi')
                || str_contains($link, 'compose')
                || str_contains($titolo, 'inviata')
                || str_contains($titolo, 'bozze')
                || str_contains($titolo, 'scrivi');

            if ($exclude) {
                continue;
            }

            $isInbox = str_contains($link, 'messaggi/inbox')
                || str_starts_with($link, 'posta')
                || str_contains($titolo, 'posta in arrivo')
                || $titolo === 'posta';

            if ($isInbox) {
                return (int)($row['conteggio'] ?? 0);
            }
        }

        return $fallback;
    }

    private function applyTenantFeatureVisibilityToMenu(array $menuData): array
    {
        $rows = $menuData['result'] ?? [];
        if (!is_array($rows) || !$this->tenantContext->hasCurrentTenant()) {
            return $menuData;
        }

        $filtered = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $featureKey = $this->resolveFeatureKeyFromLink((string) ($row['link'] ?? ''));
            if ($featureKey !== null && !$this->tenantContext->currentTenantAllows($featureKey)) {
                continue;
            }

            $filtered[] = $row;
        }

        $menuData['result'] = $filtered;
        return $menuData;
    }

    /**
     * @param array<int, array<string, mixed>> $schede
     * @return array<int, array<string, mixed>>
     */
    private function applyTenantFeatureVisibilityToSchede(array $schede): array
    {
        if (!$this->tenantContext->hasCurrentTenant()) {
            return $schede;
        }

        foreach ($schede as &$scheda) {
            if (!is_array($scheda)) {
                continue;
            }

            $featureKey = $this->resolveFeatureKeyFromCodice((string) ($scheda['codice'] ?? ''));
            if ($featureKey === null || $this->tenantContext->currentTenantAllows($featureKey)) {
                continue;
            }

            $scheda['can_view'] = 0;
            $scheda['can_access'] = 0;
            $scheda['badge'] = 0;
        }
        unset($scheda);

        return array_values(array_filter($schede, static fn(array $scheda): bool => (int) ($scheda['can_view'] ?? 0) === 1));
    }

    private function resolveFeatureKeyFromCodice(string $codice): ?string
    {
        return TenantFeatureRegistry::resolveFeatureKeyFromSchedaCode($codice);
    }

    private function resolveFeatureKeyFromLink(string $link): ?string
    {
        return TenantFeatureRegistry::resolveFeatureKeyFromMenuLink($link);
    }

    private function countDirectPostaUnread(object $utente): int
    {
        $tipoUser = (int)(session()->get('tipoUser') ?? ($utente->tipo ?? 0));
        if ($tipoUser === 3) {
            return $this->messageService->countUnreadInboxThreads(
                (int)($utente->id_utente ?? $utente->id_client ?? 0),
                MessageRoles::ROLE_PATIENT
            );
        }

        $tipoPers = $this->staffAccessService->normalizeMailboxStaffTipo((int)($utente->tipo_pers ?? 0));
        if ($tipoPers === StaffDoctorAccessService::TIPO_DOTTORE) {
            return $this->messageService->countUnreadInboxThreads(
                (int)($utente->id_personale ?? 0),
                MessageRoles::ROLE_DOCTOR
            );
        }

        if ($tipoPers === StaffDoctorAccessService::TIPO_SEGRETERIA || $tipoPers === StaffDoctorAccessService::TIPO_INFERMIERE) {
            $doctorIds = $this->staffAccessService->getDoctorPersonaleIdsForStaff(
                (int)($utente->id_personale ?? 0),
                $tipoPers,
                'posta'
            );

            $staffRole = $tipoPers === StaffDoctorAccessService::TIPO_SEGRETERIA
                ? MessageRoles::ROLE_SEGR
                : MessageRoles::ROLE_INFERM;

            return array_sum($this->messageService->countUnreadInboxThreadsByDoctorForStaff($staffRole, $doctorIds));
        }

        return 0;
    }

    private function countChatUnread(int $idUser): int
    {
        if ($idUser <= 0) {
            return 0;
        }

        return (new \App\Models\ChatModel())->getTotalUnreadForUser($idUser);
    }
}
