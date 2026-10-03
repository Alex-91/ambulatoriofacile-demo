<?php

namespace App\Services;

/** Shared navigation, built from the existing permission-filtered menus. */
final class UnifiedMenuService
{
    public const FEATURE_KEY = 'unified_navigation';

    public function tenantId(): int
    {
        $context = session()->get(TenantContextService::SESSION_KEY);
        return is_array($context) ? (int) ($context['tenant_id'] ?? 0) : 0;
    }

    public function enabled(): bool
    {
        if ($this->tenantId() <= 0) return false;
        try {
            return !empty((new TenantFeatureService())->resolveEffectiveFeatureMapForTenant($this->tenantId())[self::FEATURE_KEY]);
        } catch (\Throwable $e) {
            log_message('error', 'Unified navigation unavailable: ' . $e->getMessage());
            return false;
        }
    }

    /** Extract only navigable entries; legacy renderers remain the permissions source. */
    public function groups(): array
    {
        helper(['session_auth', 'portal']);
        $user = session()->get('utente_sess');
        $userId = is_object($user) ? (int) ($user->id_user ?? 0) : 0;
        if (!session_access_is_confirmed() || $userId <= 0) return [];
        $agenda = (new \App\Models\AgendaModel())->getMenuVisibleByUser($userId);
        $html = view('agenda/partials/menu_laterale', ['menuAgenda' => $agenda, 'unifiedMenuBypass' => true], ['saveData' => false]);
        if (session_has_operational_profile_access()) {
            $html .= view('partials/sidebar_admin', ['unifiedMenuBypass' => true], ['saveData' => false]);
        }
        $groups = self::groupLinks(self::extractLinks($html));
        // Agenda is the existing tenant landing page, even when its legacy sidebar omits a self-link.
        if ($this->tenantId() > 0) $groups = array_filter(['Oggi'=>$groups['Oggi']??[], 'Agenda'=>[['label'=>'Agenda','href'=>site_url('agenda')]]] + $groups);
        $groups['Impostazioni'][] = ['label' => 'Preferenze personali', 'href' => site_url('preferenze-navigazione')];
        return $groups;
    }

    public static function extractLinks(string $html): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xpath = new \DOMXPath($dom);
            $links = [];
            foreach ($xpath->query('//a[@href]') as $node) {
                $href = trim($node->getAttribute('href'));
                if ($href === '' || $href === '#' || preg_match('~^(?:javascript|data):~i', $href)) continue;
                if ($node->getAttribute('aria-disabled') === 'true' || $xpath->query('ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " disabled ")]', $node)->length) continue;
                $label = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
                if ($label !== '') $links[$href] = ['href' => $href, 'label' => $label];
            }
            return array_values($links);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Pure grouping: no synthetic clinical/admin links or new permissions. */
    public static function groupLinks(array $links): array
    {
        $groups = array_fill_keys(['Oggi', 'Agenda', 'Pazienti', 'Esami', 'Amministrazione', 'Comunicazioni', 'Personale', 'Report', 'Impostazioni', 'Account e spazi'], []);
        foreach ($links as $link) {
            $path = strtolower((string) parse_url($link['href'], PHP_URL_PATH));
            $text = strtolower($link['label'] . ' ' . $path);
            $group = match (true) {
                (bool) preg_match('~logout|profilo$|spazi/cambia|imperson|selettore ruoli|apri spazio|\(attivo\)~', $text) => 'Account e spazi',
                (bool) preg_match('~configura|impostaz|preferenze|spazio/(?:funzioni|pacs|fse|sistema-ts|fatturazione)|gestione-(?:tipi|sedi|stanze|branche)|disponibilit|permessi|menu-ruoli|slot-bloc|orari|ferie~', $text) => 'Impostazioni',
                (bool) preg_match('~pacs|dicom|diagnostic|lista.*esami~', $text) => 'Esami',
                (bool) preg_match('~pazient|cartella-clinica|consens~', $text) => 'Pazienti',
                (bool) preg_match('~report|statistic~', $text) => 'Report',
                (bool) preg_match('~fattur|incass|compens|listin|convenzion|sistema-ts|accettaz|preventiv|ssn~', $text) => 'Amministrazione',
                (bool) preg_match('~messagg|posta|chat|whatsapp|campagn|promemoria|notific~', $text) => 'Comunicazioni',
                (bool) preg_match('~personale|operator|medic|inferm|sostituz|assegnaz|inviti~', $text) => 'Personale',
                (bool) preg_match('~agenda(?:/)?$|agenda(?:\?|/calendario)|vai.*agenda~', $text) => 'Agenda',
                (bool) preg_match('~dashboard|profilo operativo|/admin/?$|/home/?$~', $text) => 'Oggi',
                default => 'Impostazioni',
            };
            if (str_starts_with(strtolower($link['label']), 'vai ') && $group === 'Agenda') $link['label'] = 'Agenda';
            if (str_contains(strtolower($link['label']), 'profilo operativo')) $link['label'] = 'Riepilogo dello spazio';
            $groups[$group][] = $link;
        }
        return array_filter($groups);
    }

    public function homeOptions(): array
    {
        $options = [];
        foreach ($this->groups() as $group => $links) {
            if ($group === 'Account e spazi') continue;
            foreach ($links as $link) $options[$link['href']] = $group . ' · ' . $link['label'];
        }
        return $options;
    }

    public function preferredHome(): ?string
    {
        if (!$this->enabled()) return null;
        $db = \Config\Database::connect('platform');
        if (!$db->tableExists('platform_navigation_preferences')) return null;
        $userId = (int) (session()->get('platform_user_id') ?? 0);
        if ($userId <= 0) return null;
        $row = $db->table('platform_navigation_preferences')->where('id_tenant', $this->tenantId())->where('id_platform_user', $userId)->get()->getRowArray();
        $href = (string) ($row['home_url'] ?? '');
        return $href !== '' && isset($this->homeOptions()[$href]) ? $href : null;
    }

    public function hasHomePreference(): bool
    {
        if (!$this->enabled()) return false;
        $db=\Config\Database::connect('platform');
        return $db->tableExists('platform_navigation_preferences')
            && $db->table('platform_navigation_preferences')->where('id_tenant',$this->tenantId())
                ->where('id_platform_user',(int)session()->get('platform_user_id'))->countAllResults()>0;
    }

    public function setHome(string $href): void
    {
        $userId = (int) (session()->get('platform_user_id') ?? 0);
        if ($userId <= 0 || !isset($this->homeOptions()[$href])) throw new \InvalidArgumentException('Pagina iniziale non accessibile.');
        $db = \Config\Database::connect('platform');
        $db->query('INSERT INTO platform_navigation_preferences (id_tenant,id_platform_user,home_url,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE home_url=VALUES(home_url),updated_at=VALUES(updated_at)', [$this->tenantId(), $userId, $href, date('Y-m-d H:i:s')]);
    }

    public function setEnabled(bool $enabled): void
    {
        helper('session_auth');
        if (!session_has_tenant_master_access() || $this->tenantId() <= 0) throw new \RuntimeException('Solo il master dello spazio può cambiare il menu.');
        foreach ((new TenantFeatureService())->listFeatureStatesForTenant($this->tenantId()) as $state) {
            if ($state['feature_key'] !== self::FEATURE_KEY) continue;
            if ($enabled && empty($state['entitlement_enabled'])) throw new \RuntimeException('Il nuovo menu deve essere abilitato dal Super Tenant Master.');
            if (!(new \App\Models\PlatformTenantFeaturePreferencesModel())->setPreference($this->tenantId(), (int) $state['id_feature'], $enabled, (int) session()->get('platform_user_id'))) throw new \RuntimeException('Salvataggio non riuscito.');
            return;
        }
        throw new \RuntimeException('Funzione menu non disponibile.');
    }
}
