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
        if ($this->tenantId() > 0) {
            $agendaLinks=array_values(array_filter($groups['Agenda']??[],static fn($link)=>$link['href']!==site_url('agenda')));
            $groups=array_filter(['Oggi'=>$groups['Oggi']??[], 'Agenda'=>array_merge([['label'=>'Agenda','href'=>site_url('agenda')]],$agendaLinks)] + $groups);
        }
        $groups['Impostazioni'][] = ['label' => 'Preferenze personali', 'href' => site_url('preferenze-navigazione')];
        $features=(new TenantFeatureService())->resolveEffectiveFeatureMapForTenant($this->tenantId());
        foreach ($groups as $name=>$links) {
            $groups[$name]=self::filterEnabledLinks($links,$features);
        }
        return array_filter($groups);
    }

    /** Final tenant gate also covers links injected by legacy sidebar templates. */
    public static function filterEnabledLinks(array $links,array $features): array
    {
        return array_values(array_filter($links,static function(array $link) use ($features): bool {
            $path=trim(strtolower((string)parse_url($link['href'],PHP_URL_PATH)),'/');
            $path=preg_replace('~^app/~','',$path);
            $required=[];
            $module=\App\Libraries\TenantFeatureRegistry::resolveFeatureKeyFromRoutePath($path);
            if ($module) $required[]=$module;
            if (preg_match('~(?:^|/)(?:sistema-ts|fatturazione-ts)(?:[-/]|$)~',$path)) $required[]='ts_billing';
            elseif (preg_match('~(?:^|/)fatturazione(?:[-/]|$)~',$path)) $required[]='billing';
            if (preg_match('~(?:pacs|cartella-clinica/(?:diagnostica|configurazione|richieste-esami|worklist))~',$path)) $required=array_merge($required,['clinical_records','pacs_dicom']);
            elseif (str_contains($path,'cartella-clinica')) $required[]='clinical_records';
            if (str_contains($path,'importa-pazienti-excel')) $required[]='patient_excel_import';
            if (str_contains($path,'gestione-tipi-visita')) $required[]='agenda_visit_types';
            if (preg_match('~/(?:gestione-sedi|gestione-stanze)(?:/|$)~',$path)) $required[]='multi_location';
            if (preg_match('~^(?:admin/)?personale/(?:visibilita-moduli|dap14|dap15|schede-utenti)(?:/|$)~',$path) || preg_match('~/(?:visibilita-operatori|sostituti)(?:/|$)~',$path)) $required[]='staff_management';
            if (preg_match('~(?:spazio/utenti|(?:admin/)?personale/(?:nuovo|modifica_personale)$)~',$path)) $required[]='staff_management';
            if (str_contains($path,'notifiche-appuntamenti')) $required[]='appointment_notifications';
            if (str_contains($path,'fse2')) $required[]='fse2';
            if ($path==='admin/accettazione' && (empty($features['billing']) || !array_filter(array_intersect_key($features,array_flip(['billing_services','billing_agreements','billing_compensation']))))) return false;
            if (str_contains($path,'fatturazione/gestione')) {
                parse_str((string)parse_url($link['href'],PHP_URL_QUERY),$query);
                $capability=match($query['kind']??'') {'agreement'=>'billing_agreements','rule'=>'billing_compensation','list'=>'billing_services',default=>null};
                if ($capability) $required[]=$capability;
            }
            foreach(array_unique($required) as $key) if (empty($features[$key])) return false;
            return true;
        }));
    }

    /** Inline SVG keeps navigation icons available on pages using different icon fonts. */
    public static function icon(string $group): string
    {
        $shape=match($group) {
            'Oggi'=>'<path d="m3 10 9-7 9 7v11H3zM9 21v-8h6v8"/>',
            'Agenda'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18m-13 4h2m4 0h2"/>',
            'Pazienti'=>'<circle cx="12" cy="7" r="4"/><path d="M4 21v-3a8 8 0 0 1 16 0v3"/>',
            'Esami'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M5 12h4l2-5 3 10 2-5h3"/>',
            'Amministrazione'=>'<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M8 6h8M8 11h2m4 0h2m-8 4h2m4 0h2m-8 4h2m4 0h2"/>',
            'Comunicazioni'=>'<path d="M3 3h18v14H8l-5 4zM7 7h10M7 11h7"/>',
            'Personale'=>'<circle cx="9" cy="7" r="3"/><path d="M2 21v-4a7 7 0 0 1 14 0v4M16 4a3 3 0 0 1 0 6m3 4a6 6 0 0 1 3 5v2"/>',
            'Report'=>'<path d="M3 3v18h18M7 17v-5m5 5V6m5 11V9"/>',
            'Account e spazi'=>'<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="12" cy="9" r="3"/><path d="M6 20v-2a6 6 0 0 1 12 0v2"/>',
            default=>'<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="8" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="10" cy="18" r="2"/>',
        };
        return '<svg class="af-menu-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'.$shape.'</svg>';
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
                (bool) preg_match('~configura|impostaz|preferenze|spazio/(?:funzioni|pacs|fse|sistema-ts|fatturazione|dispositivi-otp)|gestione-(?:tipi|sedi|stanze|branche)|disponibilit|permessi|menu-ruoli|config-slot|slot-extra|slot-bloc|orari|ferie|visibilita|schede-utenti|dap14|dap15|/logs|otp-statistiche|fatturazione-documento(?:$|[? ])~', $text) => 'Impostazioni',
                (bool) preg_match('~sistema-ts|fatturazione-ts~', $text) => 'Amministrazione',
                (bool) preg_match('~pacs|dicom|diagnostic|lista.*esami~', $text) => 'Esami',
                (bool) preg_match('~pazient|cartella-clinica|consens|personale/(?:nuovo_cliente|modifica_cliente)~', $text) => 'Pazienti',
                (bool) preg_match('~report|statistic~', $text) => 'Report',
                (bool) preg_match('~fattur|incass|compens|listin|convenzion|sistema-ts|accettaz|preventiv|ssn~', $text) => 'Amministrazione',
                (bool) preg_match('~messagg|posta|chat|whatsapp|campagn|promemoria|notific~', $text) => 'Comunicazioni',
                (bool) preg_match('~personale|operator|medic|inferm|sostitut|sostituz|assegnaz|inviti|spazio/utenti~', $text) => 'Personale',
                (bool) preg_match('~agenda|appuntamenti|prenotazioni|memo~', $text) => 'Agenda',
                (bool) preg_match('~dashboard|profilo operativo|/admin/?$|/home/?$~', $text) => 'Oggi',
                default => 'Oggi',
            };
            if (str_starts_with(strtolower($link['label']), 'vai ') && $group === 'Agenda') $link['label'] = 'Agenda';
            if (str_contains(strtolower($link['label']), 'profilo operativo')) $link['label'] = 'Riepilogo dello spazio';
            $groups[$group][] = $link;
        }
        return array_filter($groups);
    }

    public const SECTION_SLUGS=['Oggi'=>'oggi','Agenda'=>'agenda','Pazienti'=>'pazienti','Esami'=>'esami','Amministrazione'=>'amministrazione','Comunicazioni'=>'comunicazioni','Personale'=>'personale','Report'=>'report','Impostazioni'=>'impostazioni','Account e spazi'=>'account'];

    public static function sectionUrl(string $name,array $links): string
    {
        if ($name==='Agenda') return site_url('agenda');
        if ($name==='Oggi' && count($links)===1) return $links[0]['href'];
        return site_url('navigazione/'.self::SECTION_SLUGS[$name]);
    }

    /** The same areas as the approved prototype, containing only available real links. */
    public static function tiles(string $group,array $links): array
    {
        $out=[];
        foreach($links as $link){
            $text=strtolower($link['href'].' '.$link['label']);
            $title=match($group){
                'Impostazioni'=>match(true){
                    str_contains($text,'preferenze-navigazione')=>'Preferenze personali',
                    (bool)preg_match('~sedi|stanze~',$text)=>'Struttura',
                    (bool)preg_match('~pacs|fse|sistema-ts|fatturazione-ts~',$text)=>'Integrazioni',
                    (bool)preg_match('~fatturazione|modello documento~',$text)=>'Fatturazione',
                    (bool)preg_match('~branche|prestazioni|tipi-visita~',$text)=>'Catalogo prestazioni',
                    (bool)preg_match('~dispositivi|otp~',$text)=>'Sicurezza',
                    (bool)preg_match('~permessi|menu-ruoli|visibilita|schede-utenti|dap14|dap15|spazio/funzioni~',$text)=>'Accessi e permessi',
                    (bool)preg_match('~agenda|slot|orari|ferie|disponibilit~',$text)=>'Agenda e disponibilità',
                    default=>'Assistenza e diagnostica',
                },
                'Amministrazione'=>match(true){
                    (bool)preg_match('~sistema-ts|fatturazione-ts~',$text)=>'Sistema TS',
                    (bool)preg_match('~scadenz|incass~',$text)=>'Incassi e scadenze',
                    (bool)preg_match('~compens|kind=rule~',$text)=>'Compensi',
                    (bool)preg_match('~listin|convenzion|kind=(?:list|agreement)|catalogo~',$text)=>'Listini e convenzioni',
                    str_contains($text,'accettaz')=>'Accettazione',
                    default=>'Fatture',
                },
                'Personale'=>match(true){
                    str_contains($text,'spazio/utenti')=>'Account e inviti',
                    (bool)preg_match('~sostituz|sostitut~',$text)=>'Sostituzioni',
                    (bool)preg_match('~assegnaz|visibilita-operatori~',$text)=>'Assegnazioni',
                    default=>'Elenco personale',
                },
                'Comunicazioni'=>match(true){
                    (bool)preg_match('~campagn|invii-massivi~',$text)=>'Campagne',
                    (bool)preg_match('~promemoria|notific|reminder|sms-appuntamenti~',$text)=>'Promemoria appuntamenti',
                    default=>'Messaggi',
                },
                'Pazienti'=>str_contains($text,'cartella-clinica')?'Cartella clinica':'Anagrafica pazienti',
                'Esami'=>str_contains($text,'demo-pacs')?'Demo guidata':'Lista di lavoro esami',
                'Report'=>preg_match('~fattur|incass|compens~',$text)?'Andamento economico':'Attività dello studio',
                default=>$link['label'],
            };
            $out[$title][]=$link;
        }
        $order=match($group){
            'Impostazioni'=>['Preferenze personali','Struttura','Catalogo prestazioni','Agenda e disponibilità','Accessi e permessi','Integrazioni','Fatturazione','Sicurezza','Assistenza e diagnostica'],
            'Amministrazione'=>['Fatture','Incassi e scadenze','Listini e convenzioni','Compensi','Sistema TS','Accettazione'],
            'Personale'=>['Elenco personale','Assegnazioni','Sostituzioni','Account e inviti'],
            'Comunicazioni'=>['Promemoria appuntamenti','Messaggi','Campagne'],
            default=>array_keys($out),
        };
        return array_filter(array_replace(array_fill_keys($order,[]),$out));
    }

    public function homeOptions(): array
    {
        $options = [];
        foreach ($this->groups() as $group => $links) {
            if ($group === 'Account e spazi') continue;
            $options[self::sectionUrl($group,$links)]=$group;
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
        if (!$db->query('INSERT INTO platform_navigation_preferences (id_tenant,id_platform_user,home_url,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE home_url=VALUES(home_url),updated_at=VALUES(updated_at)', [$this->tenantId(), $userId, $href, date('Y-m-d H:i:s')])) throw new \RuntimeException('Pagina iniziale non salvata. Riprova tra poco.');
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
