<?php
helper(['portal']);

$menuTree = $menuAgenda ?? ($menu ?? []);
$uri = service('uri');
$currentPath = trim($uri->getPath(), '/');
$session = session();
$tenantContextRaw = $session->get(\App\Services\TenantContextService::SESSION_KEY);
$tenantRole = is_array($tenantContextRaw) ? strtolower(trim((string) ($tenantContextRaw['tenant_role'] ?? ''))) : '';
$tenantId = is_array($tenantContextRaw) ? (int) ($tenantContextRaw['tenant_id'] ?? 0) : 0;
$canOpenOperationalConsole = ($tenantId > 0 && in_array($tenantRole, ['tenant_master', 'tenant_admin'], true))
    || $session->get('is_admin') === true
    || (int) ($session->get('admin') ?? 0) === 1;
$agendaConsoleUrl = null;
if ($tenantId > 0 && in_array($tenantRole, ['tenant_master', 'tenant_admin'], true)) {
    $agendaConsoleUrl = portal_session_console_url();
} elseif ($session->get('is_admin') === true || (int) ($session->get('admin') ?? 0) === 1) {
    $agendaConsoleUrl = portal_operational_home_url();
}
$visitTypesFeatureEnabledResolved = isset($visitTypesFeatureEnabled)
    ? !empty($visitTypesFeatureEnabled)
    : false;

if (!$visitTypesFeatureEnabledResolved && $tenantId > 0) {
    try {
        $featureMap = (new \App\Services\TenantFeatureService())->resolveEffectiveFeatureMapForTenant($tenantId);
        $visitTypesFeatureEnabledResolved = !empty($featureMap['agenda_visit_types']);
    } catch (\Throwable $e) {
        $visitTypesFeatureEnabledResolved = false;
    }
}

if (!function_exists('agenda_menu_norm_icon_shared')) {
    function agenda_menu_norm_icon_shared($icon, $isExternal = false) {
        $icon = trim((string)$icon);
        if ($icon === '') {
            return $isExternal ? 'fa fa-external-link' : 'fa fa-circle-o';
        }
        return $icon;
    }

    function agenda_menu_get_value_shared($node, $key, $default = '') {
        if (is_object($node)) {
            return $node->$key ?? $default;
        }
        if (is_array($node)) {
            return $node[$key] ?? $default;
        }
        return $default;
    }

    function agenda_menu_href_from_node_shared($node) {
        $rotta = trim((string) agenda_menu_get_value_shared($node, 'rotta', ''));
        if ($rotta === '' || $rotta === '#') {
            return '#';
        }
        return base_url(ltrim($rotta, '/'));
    }

    function agenda_menu_children_from_node_shared($node) {
        $children = agenda_menu_get_value_shared($node, 'children', []);
        return is_array($children) ? $children : [];
    }

    function agenda_menu_has_route_shared(array $nodes, string $route): bool {
        $route = trim($route, '/');
        foreach ($nodes as $node) {
            $nodeRoute = trim((string) agenda_menu_get_value_shared($node, 'rotta', ''), '/');
            if ($nodeRoute === $route) {
                return true;
            }

            $children = agenda_menu_children_from_node_shared($node);
            if ($children !== [] && agenda_menu_has_route_shared($children, $route)) {
                return true;
            }
        }

        return false;
    }

    function agenda_menu_has_active_child_shared(array $children, string $currentPath): bool {
        foreach ($children as $child) {
            $href = agenda_menu_href_from_node_shared($child);
            $path = trim((string) parse_url($href, PHP_URL_PATH), '/');

            if ($path === $currentPath) {
                return true;
            }

            $grandChildren = agenda_menu_children_from_node_shared($child);
            if (!empty($grandChildren) && agenda_menu_has_active_child_shared($grandChildren, $currentPath)) {
                return true;
            }
        }
        return false;
    }

    function agenda_menu_render_tree_shared(array $nodes, string $currentPath): string {
        $html = '';

        foreach ($nodes as $node) {
            $label = htmlspecialchars((string) agenda_menu_get_value_shared($node, 'label_menu', 'Voce'), ENT_QUOTES, 'UTF-8');
            $icon  = htmlspecialchars(agenda_menu_norm_icon_shared((string) agenda_menu_get_value_shared($node, 'icona', '')), ENT_QUOTES, 'UTF-8');
            $tipo  = strtoupper(trim((string) agenda_menu_get_value_shared($node, 'tipo_voce', 'ITEM')));
            $href  = agenda_menu_href_from_node_shared($node);
            $path  = trim((string) parse_url($href, PHP_URL_PATH), '/');
            $children = agenda_menu_children_from_node_shared($node);

            if ($tipo === 'MENU' && !empty($children)) {
                $isOpen = agenda_menu_has_active_child_shared($children, $currentPath);
                $idMenu = (int) agenda_menu_get_value_shared($node, 'id_menu', 0);

                $html .= '<li class="agenda-menu-parent'.($isOpen ? ' open' : '').'">';
                $html .= '<a href="#" class="agenda-menu-toggle" data-target="submenu_'.$idMenu.'">';
                $html .= '<i class="'.$icon.'"></i> '.$label;
                $html .= '<span class="pull-right"><i class="fa fa-angle-'.($isOpen ? 'down' : 'left').' agenda-menu-arrow"></i></span>';
                $html .= '</a>';

                $html .= '<ul class="nav nav-pills nav-stacked agenda-submenu" id="submenu_'.$idMenu.'"'.($isOpen ? '' : ' style="display:none;"').'>';

                foreach ($children as $child) {
                    $childLabel = htmlspecialchars((string) agenda_menu_get_value_shared($child, 'label_menu', 'Voce'), ENT_QUOTES, 'UTF-8');
                    $childIcon  = htmlspecialchars(agenda_menu_norm_icon_shared((string) agenda_menu_get_value_shared($child, 'icona', 'fa fa-circle-o')), ENT_QUOTES, 'UTF-8');
                    $childHref  = agenda_menu_href_from_node_shared($child);
                    $childPath  = trim((string) parse_url($childHref, PHP_URL_PATH), '/');
                    $childActive = ($childPath === $currentPath);

                    $html .= '<li'.($childActive ? ' class="active"' : '').'>';
                    $html .= '<a href="'.$childHref.'" style="padding-left:30px;"><i class="'.$childIcon.'"></i> '.$childLabel.'</a>';
                    $html .= '</li>';
                }

                $html .= '</ul>';
                $html .= '</li>';
            } else {
                $isActive = ($path === $currentPath);

                $html .= '<li'.($isActive ? ' class="active"' : '').'>';
                $html .= '<a href="'.$href.'"><i class="'.$icon.'"></i> '.$label.'</a>';
                $html .= '</li>';
            }
        }

        return $html;
    }
}

if ($visitTypesFeatureEnabledResolved && !agenda_menu_has_route_shared($menuTree, 'agenda/gestione-tipi-visita')) {
    $menuTree[] = [
        'id_menu' => 'codex_tipi_visita',
        'id_menu_padre' => 0,
        'tipo_voce' => 'ITEM',
        'label_menu' => 'Tipi visita',
        'icona' => 'fa fa-list-alt',
        'rotta' => 'agenda/gestione-tipi-visita',
        'children' => [],
    ];
}
?>

<?php if ($agendaConsoleUrl !== null): ?>
    <div style="padding:15px 15px 0;">
        <a href="<?= esc($agendaConsoleUrl) ?>" class="btn btn-default btn-block" style="font-weight:700;">
            <i class="fa fa-briefcase"></i> Vai al centro operativo
        </a>
    </div>
<?php endif; ?>

<ul class="nav nav-pills nav-stacked" id="agendaMenuLaterale" style="margin-top:<?= $agendaConsoleUrl !== null ? '12px' : '0' ?>;">
    <?= agenda_menu_render_tree_shared($menuTree, $currentPath) ?>
</ul>
