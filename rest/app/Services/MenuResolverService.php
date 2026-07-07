<?php

namespace App\Services;

use App\Libraries\TenantContext;

class MenuResolverService
{
    private AdminMenuVisibilityService $adminMenuVisibility;
    private MenuRegistryService $menuRegistry;

    public function __construct(
        ?AdminMenuVisibilityService $adminMenuVisibility = null,
        ?MenuRegistryService $menuRegistry = null
    ) {
        $this->adminMenuVisibility = $adminMenuVisibility ?? new AdminMenuVisibilityService();
        $this->menuRegistry = $menuRegistry ?? new MenuRegistryService();
    }

    /**
     * @param list<array<string, mixed>> $menuItems
     * @return array<string, mixed>
     */
    public function resolveAdminSidebar(array $menuItems = []): array
    {
        helper(['admin_menu', 'portal']);

        $session = session();
        if ($menuItems === []) {
            $menuDataAdmin = $session->get('menuDataAdmin');
            $menuItems = is_array($menuDataAdmin['result'] ?? null) ? $menuDataAdmin['result'] : [];
        }

        $currentMenuUserId = $this->resolveCurrentMenuUserId();
        if ($currentMenuUserId > 0) {
            $menuItems = $this->adminMenuVisibility->filterMenuRowsForUser($menuItems, $currentMenuUserId);
        }

        $context = $this->resolveRuntimeContext();

        $primaryAction = null;
        if ($context['is_tenant_operational_console_session'] && $context['tenant_agenda_url'] !== null) {
            $primaryAction = [
                'href' => $context['tenant_agenda_url'],
                'label' => 'Vai all\'agenda',
                'icon' => 'fa-calendar',
                'active' => $this->isLinkActive((string) $context['tenant_agenda_url'], (string) $context['current_path']),
            ];
        }

        $secondaryPrimaryAction = null;
        if ($context['can_open_agenda'] && !$context['is_tenant_operational_console_session']) {
            $agendaUrl = site_url('agenda');
            $secondaryPrimaryAction = [
                'href' => $agendaUrl,
                'label' => 'Vai in agenda',
                'icon' => 'fa-calendar',
                'active' => $this->isLinkActive($agendaUrl, (string) $context['current_path']),
            ];
        }

        $contextActions = [];
        $accountActions = [];

        if ($context['is_tenant_operational_console_session']) {
            $contextActions = array_merge(
                $this->buildTenantSwitchActions($context),
                $this->buildDemoSwitchActions($context),
                $this->buildManagedTenantContextActions($context)
            );

            if ($context['active_impersonation'] !== null) {
                $contextActions[] = [
                    'key' => 'piattaforma/impersonificazione',
                    'href' => $this->resolveCatalogItemHref(
                        $this->menuRegistry->findPlatformConsoleItem('piattaforma/impersonificazione') ?? []
                    ),
                    'label' => 'Sessione delegata attiva',
                    'icon' => 'fa-shield',
                    'active' => false,
                    'disabled' => false,
                ];
            }

            if (!$context['is_platform_console_session'] && !$context['is_tenant_master_operational']) {
                $profileUrl = base_url('profilo');
                $accountActions[] = [
                    'href' => $profileUrl,
                    'label' => 'Profilo',
                    'icon' => 'fa-user',
                    'active' => $this->isLinkActive($profileUrl, (string) $context['current_path']),
                ];
            }

            $accountActions[] = [
                'href' => base_url('logout'),
                'label' => 'Logout',
                'icon' => 'fa-sign-out',
                'active' => false,
            ];
        }

        if ($currentMenuUserId > 0) {
            $contextActions = $this->adminMenuVisibility->filterContextActionsForUser($contextActions, $currentMenuUserId);
        }

        return [
            'tenant_name' => (string) $context['tenant_name'],
            'menu_items' => $this->injectFeatureAwareAdminMenus($menuItems, (int) $context['tenant_id']),
            'primary_action' => $primaryAction,
            'secondary_primary_action' => $secondaryPrimaryAction,
            'context_actions' => $contextActions,
            'account_actions' => $accountActions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePlatformSidebar(array $platformMasterEmails = []): array
    {
        helper('portal');

        $currentPath = $this->normalizedCurrentPath();
        $items = [];

        foreach ($this->menuRegistry->platformConsoleCatalog() as $item) {
            $items[] = [
                'key' => (string) ($item['key'] ?? ''),
                'href' => $this->resolveCatalogItemHref($item),
                'label' => (string) ($item['title'] ?? 'Voce'),
                'icon' => (string) ($item['icon'] ?? 'fa-circle-o'),
                'active' => $this->isCatalogItemActive($item, $currentPath),
            ];
        }

        $items[] = [
            'key' => 'logout',
            'href' => site_url('logout'),
            'label' => 'Logout',
            'icon' => 'fa-sign-out',
            'active' => false,
        ];

        return [
            'title' => 'Console piattaforma',
            'items' => $items,
            'platform_master_emails' => is_array($platformMasterEmails) ? $platformMasterEmails : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePortalHeaderSections(): array
    {
        $context = $this->resolveRuntimeContext();
        $currentMenuUserId = $this->resolveCurrentMenuUserId();

        $sections = [];

        $tenantSwitchActions = $this->buildTenantSwitchActions($context);
        if ($tenantSwitchActions !== []) {
            $sections[] = [
                'title' => count((array) ($context['platform_tenants'] ?? [])) > 1 ? 'Cambia spazio' : 'Apri spazio',
                'items' => $tenantSwitchActions,
            ];
        }

        $demoSwitchActions = $this->buildDemoSwitchActions($context);
        if ($demoSwitchActions !== []) {
            $sections[] = [
                'title' => 'Cambia ruolo demo',
                'items' => $demoSwitchActions,
            ];
        }

        $managedActions = $this->buildManagedTenantContextActions($context);
        if ($currentMenuUserId > 0) {
            $managedActions = $this->adminMenuVisibility->filterContextActionsForUser($managedActions, $currentMenuUserId);
        }

        if ($managedActions !== []) {
            $sections[] = [
                'title' => null,
                'items' => $managedActions,
            ];
        }

        return [
            'sections' => $sections,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRuntimeContext(): array
    {
        helper('portal');

        $session = session();
        $tenantContext = $session->get('tenant_context');
        $tenantContext = is_array($tenantContext) ? $tenantContext : [];
        $tenantFeatureFlags = (array) ($tenantContext['feature_flags'] ?? []);
        $tenantId = (int) ($tenantContext['tenant_id'] ?? 0);
        $tenantRole = trim((string) ($tenantContext['tenant_role'] ?? ''));
        $tenantContextObject = $tenantContext !== [] ? TenantContext::fromArray($tenantContext) : null;
        $billingFeatureService = new BillingFeatureService();
        $tsFeatureService = new TsFeatureService();
        $billingAccessible = !empty($tenantFeatureFlags['billing'])
            || $billingFeatureService->isEnabledForContext($tenantContextObject);
        $tsBillingAccessible = !empty($tenantFeatureFlags['ts_billing'])
            || $tsFeatureService->allowsLocalTestingBypass($tenantContextObject);
        $canAccessPlatformConsole = (bool) ($session->get('platform_is_admin') ?? false) === true;
        $currentSessionUsername = trim((string) ($session->get('username') ?? ''));
        $demoCurrentAccount = $session->get(\App\Services\DemoAccessService::SESSION_KEY_CURRENT);
        $demoSwitchAccounts = $session->get(\App\Services\DemoAccessService::SESSION_KEY_SWITCH_ACCOUNTS);
        $demoCurrentSessionUsername = is_array($demoCurrentAccount)
            ? trim((string) ($demoCurrentAccount['session_username'] ?? $demoCurrentAccount['username'] ?? ''))
            : '';

        $showDemoRoleSwitch = (bool) ($session->get(\App\Services\DemoAccessService::SESSION_KEY_ACTIVE) ?? false)
            && is_array($demoCurrentAccount)
            && is_array($demoSwitchAccounts)
            && $currentSessionUsername !== ''
            && $demoCurrentSessionUsername !== ''
            && strcasecmp($currentSessionUsername, $demoCurrentSessionUsername) === 0;

        return [
            'current_path' => $this->normalizedCurrentPath(),
            'tenant_name' => trim((string) ($tenantContext['tenant_name'] ?? '')),
            'tenant_id' => $tenantId,
            'tenant_role' => $tenantRole,
            'tenant_feature_flags' => $tenantFeatureFlags,
            'is_tenant_operational_console_session' => $tenantId > 0 && in_array($tenantRole, ['tenant_master', 'tenant_admin'], true),
            'is_tenant_master_operational' => $tenantId > 0 && $tenantRole === 'tenant_master',
            'can_access_platform_console' => $canAccessPlatformConsole,
            'is_platform_console_session' => $canAccessPlatformConsole
                && (string) ($session->get('loginSource') ?? '') === 'platform_console',
            'can_manage_tenant_features' => $tenantId > 0
                && $tenantRole === 'tenant_master'
                && (int) ($session->get('platform_user_id') ?? 0) > 0,
            'can_manage_billing' => $tenantId > 0
                && $tenantRole === 'tenant_master'
                && (int) ($session->get('platform_user_id') ?? 0) > 0
                && $billingAccessible,
            'can_manage_ts_billing' => $tenantId > 0
                && $tenantRole === 'tenant_master'
                && (int) ($session->get('platform_user_id') ?? 0) > 0
                && $tsBillingAccessible,
            'can_manage_appointment_notifications' => $tenantId > 0
                && $tenantRole === 'tenant_master'
                && (int) ($session->get('platform_user_id') ?? 0) > 0
                && !empty($tenantFeatureFlags['appointment_notifications']),
            'can_manage_otp_devices' => $tenantId > 0
                && in_array($tenantRole, ['tenant_master', 'tenant_admin'], true)
                && (int) ($session->get('platform_user_id') ?? 0) > 0,
            'can_manage_tenant_users' => $tenantId > 0
                && in_array($tenantRole, ['tenant_master', 'tenant_admin'], true)
                && !empty($tenantFeatureFlags['staff_management']),
            'can_open_agenda' => $tenantId > 0
                || $session->get('is_admin') === true
                || (int) ($session->get('admin') ?? 0) === 1,
            'tenant_agenda_url' => $tenantId > 0 ? portal_tenant_agenda_url() : null,
            'platform_tenants' => is_array($session->get('platform_selectable_tenants'))
                ? $session->get('platform_selectable_tenants')
                : [],
            'show_demo_role_switch' => $showDemoRoleSwitch,
            'demo_access_url' => $showDemoRoleSwitch
                ? trim((string) ($demoCurrentAccount['access_url'] ?? site_url('access')))
                : '',
            'demo_switch_accounts' => is_array($demoSwitchAccounts) ? $demoSwitchAccounts : [],
            'active_impersonation' => is_array($session->get(\App\Services\PlatformImpersonationService::SESSION_KEY))
                ? $session->get(\App\Services\PlatformImpersonationService::SESSION_KEY)
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private function buildTenantSwitchActions(array $context): array
    {
        $actions = [];
        $tenantAgendaUrl = (string) ($context['tenant_agenda_url'] ?? '');
        $tenantId = (int) ($context['tenant_id'] ?? 0);

        foreach ((array) ($context['platform_tenants'] ?? []) as $availableTenant) {
            if (!is_array($availableTenant)) {
                continue;
            }

            $availableTenantId = (int) ($availableTenant['id_tenant'] ?? 0);
            if ($availableTenantId <= 0) {
                continue;
            }

            $tenantLabel = trim((string) ($availableTenant['tenant_name'] ?? $availableTenant['tenant_key'] ?? 'Spazio cliente'));
            $isCurrentTenant = $availableTenantId === $tenantId;
            $actions[] = [
                'key' => 'tenant-switch:' . $availableTenantId,
                'href' => $isCurrentTenant && $tenantAgendaUrl !== ''
                    ? $tenantAgendaUrl
                    : portal_tenant_switch_url($availableTenantId),
                'label' => $isCurrentTenant ? $tenantLabel . ' (attivo)' : 'Apri spazio: ' . $tenantLabel,
                'icon' => 'fa-exchange',
                'active' => $isCurrentTenant,
                'disabled' => false,
                'locked' => $isCurrentTenant,
            ];
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private function buildDemoSwitchActions(array $context): array
    {
        if (empty($context['show_demo_role_switch'])) {
            return [];
        }

        $actions = [];
        $demoAccessUrl = trim((string) ($context['demo_access_url'] ?? ''));
        if ($demoAccessUrl !== '') {
            $actions[] = [
                'key' => 'demo-access-selector',
                'href' => $demoAccessUrl,
                'label' => 'Apri selettore ruoli demo',
                'icon' => 'fa-random',
                'active' => false,
                'disabled' => false,
                'locked' => false,
            ];
        }

        foreach ((array) ($context['demo_switch_accounts'] ?? []) as $demoSwitchAccount) {
            if (!is_array($demoSwitchAccount)) {
                continue;
            }

            $demoSwitchLabel = trim((string) ($demoSwitchAccount['role'] ?? $demoSwitchAccount['label'] ?? 'Ruolo demo'));
            $demoSwitchDetail = trim((string) ($demoSwitchAccount['label'] ?? ''));
            $demoSwitchUrl = trim((string) ($demoSwitchAccount['entry_url'] ?? ''));
            $demoSwitchCurrent = (bool) ($demoSwitchAccount['is_current'] ?? false);
            $disabled = $demoSwitchCurrent || $demoSwitchUrl === '';

            $actions[] = [
                'key' => 'demo-switch:' . md5($demoSwitchLabel . '|' . $demoSwitchDetail . '|' . $demoSwitchUrl),
                'href' => $disabled ? '#' : $demoSwitchUrl,
                'label' => $demoSwitchLabel
                    . ($demoSwitchDetail !== '' ? ' - ' . $demoSwitchDetail : '')
                    . ($demoSwitchCurrent ? ' (attivo)' : ''),
                'icon' => 'fa-user-secret',
                'active' => $demoSwitchCurrent,
                'disabled' => $disabled,
                'locked' => $disabled,
            ];
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private function buildManagedTenantContextActions(array $context): array
    {
        $actions = [];
        $currentPath = (string) ($context['current_path'] ?? '');

        $platformQuickLinkKeys = [
            'piattaforma/impersonificazione',
            'piattaforma/spazi-clienti',
        ];

        if (!empty($context['can_access_platform_console'])) {
            foreach ($platformQuickLinkKeys as $key) {
                $item = $this->menuRegistry->findPlatformConsoleItem($key);
                if (!is_array($item)) {
                    continue;
                }

                $actions[] = [
                    'key' => $key,
                    'href' => $this->resolveCatalogItemHref($item),
                    'label' => $key === 'piattaforma/spazi-clienti'
                        ? 'Console piattaforma'
                        : (string) ($item['title'] ?? 'Voce'),
                    'icon' => (string) ($item['icon'] ?? 'fa-circle-o'),
                    'active' => $this->isCatalogItemActive($item, $currentPath),
                    'disabled' => false,
                    'locked' => false,
                ];
            }
        }

        $tenantContextFlags = [
            'spazio/utenti' => !empty($context['can_manage_tenant_users']),
            'spazio/dispositivi-otp' => !empty($context['can_manage_otp_devices']),
            'spazio/funzioni' => !empty($context['can_manage_tenant_features']),
            'spazio/fatturazione' => !empty($context['can_manage_billing']),
            'spazio/fatturazione-ts' => !empty($context['can_manage_ts_billing']),
            'spazio/notifiche-appuntamenti' => !empty($context['can_manage_appointment_notifications']),
        ];

        foreach ($tenantContextFlags as $key => $enabled) {
            if (!$enabled) {
                continue;
            }

            $item = $this->menuRegistry->findTenantContextItem($key);
            if (!is_array($item)) {
                continue;
            }

            $actions[] = [
                'key' => $key,
                'href' => $this->resolveCatalogItemHref($item),
                'label' => (string) ($item['title'] ?? 'Voce'),
                'icon' => (string) ($item['icon'] ?? 'fa-circle-o'),
                'active' => $this->isCatalogItemActive($item, $currentPath),
                'disabled' => false,
                'locked' => false,
            ];
        }

        return $actions;
    }

    /**
     * @param list<array<string, mixed>> $menuItems
     * @return list<array<string, mixed>>
     */
    private function injectFeatureAwareAdminMenus(array $menuItems, int $tenantId): array
    {
        $menuItems = $this->injectVisitTypesMenu($menuItems, $tenantId);
        $menuItems = $this->injectBillingMenu($menuItems, $tenantId);
        $menuItems = $this->injectBillingDocumentsMenu($menuItems, $tenantId);
        $menuItems = $this->injectTsBillingMenu($menuItems, $tenantId);
        $menuItems = $this->injectBillingDocumentSettingsMenu($menuItems, $tenantId);

        return $this->reorderOperationalMenuItems($menuItems);
    }

    /**
     * @param list<array<string, mixed>> $menuItems
     * @return list<array<string, mixed>>
     */
    private function injectVisitTypesMenu(array $menuItems, int $tenantId): array
    {
        if (!$this->isAdminVisitTypesFeatureEnabled($tenantId)) {
            return $menuItems;
        }

        foreach ($menuItems as $menuRow) {
            $menuLink = strtolower($this->normalizePath((string) ($menuRow['link'] ?? '')));
            if ($menuLink === 'agenda/gestione-tipi-visita') {
                return $menuItems;
            }
        }

        $menuItems[] = [
            'titolo_menu' => 'Tipi visita',
            'link' => 'agenda/gestione-tipi-visita',
            'class_icon' => 'fa-list-alt',
        ];

        return $menuItems;
    }

    /**
     * @param list<array<string, mixed>> $menuItems
     * @return list<array<string, mixed>>
     */
    private function injectTsBillingMenu(array $menuItems, int $tenantId): array
    {
        if (!$this->isAdminTsBillingFeatureEnabled($tenantId)) {
            return $menuItems;
        }

        foreach ($menuItems as $menuRow) {
            $menuLink = strtolower($this->normalizePath((string) ($menuRow['link'] ?? '')));
            if ($menuLink === 'sistema-ts' || $menuLink === 'fatturazione-ts') {
                return $menuItems;
            }
        }

        $menuItems[] = [
            'titolo_menu' => 'Sistema TS',
            'link' => 'sistema-ts',
            'class_icon' => 'fa-file-text-o',
        ];

        return $menuItems;
    }

    /**
     * @param list<array<string, mixed>> $menuItems
     * @return list<array<string, mixed>>
     */
    private function injectBillingMenu(array $menuItems, int $tenantId): array
    {
        if (!$this->isAdminBillingFeatureEnabled($tenantId)) {
            return $menuItems;
        }

        foreach ($menuItems as $menuRow) {
            $menuLink = strtolower($this->normalizePath((string) ($menuRow['link'] ?? '')));
            if ($menuLink === 'fatturazione') {
                return $menuItems;
            }
        }

        $menuItems[] = [
            'titolo_menu' => 'Fatturazione',
            'link' => 'fatturazione',
            'class_icon' => 'fa-calculator',
        ];

        return $menuItems;
    }

    /**
     * @param list<array<string, mixed>> $menuItems
     * @return list<array<string, mixed>>
     */
    private function injectBillingDocumentSettingsMenu(array $menuItems, int $tenantId): array
    {
        if (!$this->isAdminBillingFeatureEnabled($tenantId)) {
            return $menuItems;
        }

        foreach ($menuItems as $menuRow) {
            $menuLink = strtolower($this->normalizePath((string) ($menuRow['link'] ?? '')));
            if ($menuLink === 'fatturazione-documento') {
                return $menuItems;
            }
        }

        $menuItems[] = [
            'titolo_menu' => 'Documento fatturazione',
            'link' => 'fatturazione-documento',
            'class_icon' => 'fa-file-text-o',
        ];

        return $menuItems;
    }

    /**
     * @param list<array<string, mixed>> $menuItems
     * @return list<array<string, mixed>>
     */
    private function injectBillingDocumentsMenu(array $menuItems, int $tenantId): array
    {
        if (!$this->isAdminBillingFeatureEnabled($tenantId)) {
            return $menuItems;
        }

        foreach ($menuItems as $menuRow) {
            $menuLink = strtolower($this->normalizePath((string) ($menuRow['link'] ?? '')));
            if ($menuLink === 'fatturazione-documenti') {
                return $menuItems;
            }
        }

        $menuItems[] = [
            'titolo_menu' => 'Lista fatture',
            'link' => 'fatturazione-documenti',
            'class_icon' => 'fa-folder-open-o',
        ];

        return $menuItems;
    }

    /**
     * @param list<array<string, mixed>> $menuItems
     * @return list<array<string, mixed>>
     */
    private function reorderOperationalMenuItems(array $menuItems): array
    {
        $catalogByLink = [];
        foreach ($this->menuRegistry->tenantAdminCatalog() as $item) {
            $catalogLink = $this->adminMenuVisibility->normalizeMenuLink((string) ($item['link'] ?? ''));
            if ($catalogLink !== '' && !isset($catalogByLink[$catalogLink])) {
                $catalogByLink[$catalogLink] = $item;
            }
        }

        $orderedItems = [];
        foreach ($menuItems as $index => $menuItem) {
            if (!is_array($menuItem)) {
                continue;
            }

            $normalizedLink = $this->adminMenuVisibility->normalizeMenuLink((string) ($menuItem['link'] ?? ''));
            $catalogItem = $normalizedLink !== '' ? ($catalogByLink[$normalizedLink] ?? null) : null;

            if (is_array($catalogItem)) {
                $menuItem['titolo_menu'] = (string) ($catalogItem['title'] ?? ($menuItem['titolo_menu'] ?? ''));
                $menuItem['class_icon'] = (string) ($catalogItem['icon'] ?? ($menuItem['class_icon'] ?? ''));
                $menuItem['link'] = (string) ($catalogItem['link'] ?? ($menuItem['link'] ?? ''));
                $menuItem['_runtime_order'] = (int) ($catalogItem['order'] ?? 5000);
            } elseif ($normalizedLink === 'agenda/gestione-tipi-visita') {
                $menuItem['titolo_menu'] = 'Tipi visita';
                $menuItem['class_icon'] = 'fa-list-alt';
                $menuItem['_runtime_order'] = 500;
            } else {
                $menuItem['_runtime_order'] = 5000 + $index;
            }

            $menuItem['_runtime_index'] = $index;
            $orderedItems[] = $menuItem;
        }

        usort($orderedItems, static function (array $left, array $right): int {
            $orderCompare = ((int) ($left['_runtime_order'] ?? 0)) <=> ((int) ($right['_runtime_order'] ?? 0));
            if ($orderCompare !== 0) {
                return $orderCompare;
            }

            return ((int) ($left['_runtime_index'] ?? 0)) <=> ((int) ($right['_runtime_index'] ?? 0));
        });

        foreach ($orderedItems as &$menuItem) {
            unset($menuItem['_runtime_order'], $menuItem['_runtime_index']);
        }
        unset($menuItem);

        return $orderedItems;
    }

    private function isAdminVisitTypesFeatureEnabled(int $tenantId): bool
    {
        if ($tenantId > 0) {
            try {
                $featureMap = (new TenantFeatureService())->resolveEffectiveFeatureMapForTenant($tenantId);
                return !empty($featureMap['agenda_visit_types']);
            } catch (\Throwable $e) {
                return false;
            }
        }

        try {
            $featureMap = (new TenantCatalogService())->resolveFeatureMapForCurrentRuntimeTenant();
            return !empty($featureMap['agenda_visit_types']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function isAdminBillingFeatureEnabled(int $tenantId): bool
    {
        $context = (new TenantContextService())->getCurrentTenant();
        if ($context !== null && $context->isValid() && $context->tenantId === $tenantId) {
            return (new BillingFeatureService())->isEnabledForContext($context);
        }

        if ($tenantId > 0) {
            try {
                $featureMap = (new TenantFeatureService())->resolveEffectiveFeatureMapForTenant($tenantId);
                return !empty($featureMap['billing']);
            } catch (\Throwable $e) {
                return false;
            }
        }

        try {
            $featureMap = (new TenantCatalogService())->resolveFeatureMapForCurrentRuntimeTenant();
            return !empty($featureMap['billing']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function isAdminTsBillingFeatureEnabled(int $tenantId): bool
    {
        if ($tenantId > 0) {
            try {
                $featureMap = (new TenantFeatureService())->resolveEffectiveFeatureMapForTenant($tenantId);
                return !empty($featureMap['ts_billing']);
            } catch (\Throwable $e) {
                return false;
            }
        }

        try {
            $featureMap = (new TenantCatalogService())->resolveFeatureMapForCurrentRuntimeTenant();
            return !empty($featureMap['ts_billing']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveCatalogItemHref(array $item): string
    {
        helper(['admin_menu', 'portal']);

        $link = trim((string) ($item['link'] ?? ''));
        if ($link === '') {
            return '#';
        }

        if (str_starts_with($link, 'piattaforma/')) {
            return portal_platform_url(substr($link, strlen('piattaforma/')));
        }

        if (str_starts_with($link, 'spazio/')) {
            return portal_tenant_space_url(substr($link, strlen('spazio/')));
        }

        if ($link === 'logout') {
            return site_url('logout');
        }

        return admin_menu_resolve_href($link);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isCatalogItemActive(array $item, string $currentPath): bool
    {
        foreach ((array) ($item['exact_paths'] ?? []) as $exactPath) {
            $exactPath = strtolower($this->normalizePath((string) $exactPath));
            if ($exactPath !== '' && $currentPath === $exactPath) {
                return true;
            }
        }

        foreach ((array) ($item['route_prefixes'] ?? []) as $prefix) {
            $prefix = strtolower($this->normalizePath((string) $prefix));
            if ($prefix === '') {
                continue;
            }

            if ($currentPath === $prefix || str_starts_with($currentPath, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function resolveCurrentMenuUserId(): int
    {
        $session = session();
        $currentMenuUserId = (int) ($session->get('id_user') ?? 0);
        if ($currentMenuUserId > 0) {
            return $currentMenuUserId;
        }

        $sessionUser = $session->get('utente_sess');
        if (is_object($sessionUser) && !empty($sessionUser->id_user)) {
            return (int) $sessionUser->id_user;
        }

        return 0;
    }

    private function normalizedCurrentPath(): string
    {
        return strtolower($this->normalizePath(service('uri')->getPath()));
    }

    private function isLinkActive(string $href, string $currentPath): bool
    {
        $itemPath = strtolower($this->normalizePath($href));
        if ($itemPath === '') {
            return false;
        }

        return $currentPath === $itemPath || str_starts_with($currentPath, $itemPath . '/');
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $parsedPath = parse_url($path, PHP_URL_PATH);
        if (is_string($parsedPath) && $parsedPath !== '') {
            $path = $parsedPath;
        }

        return trim(str_replace('\\', '/', $path), '/');
    }
}
