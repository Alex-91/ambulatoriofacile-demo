<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\TenantContext;
use App\Services\StaffLocationCatalogService;
use App\Services\TenantAdminMenuService;
use App\Services\TenantCatalogService;
use App\Services\TenantContextService;
use App\Services\TenantProvisioningService;

class Dashboard extends BaseController
{
    private TenantCatalogService $tenantCatalog;
    private TenantProvisioningService $tenantProvisioning;
    private TenantAdminMenuService $tenantAdminMenu;

    public function __construct()
    {
        $this->tenantCatalog = new TenantCatalogService();
        $this->tenantProvisioning = new TenantProvisioningService();
        $this->tenantAdminMenu = new TenantAdminMenuService();
    }

    public function index()
    {
        helper(['portal', 'admin_menu']);

        $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) {
            return redirect()->to('/login');
        }

        if (session()->get('is_admin') !== true && (int)($me->tipo ?? 0) !== 1) {
            return redirect()->to('/');
        }

        $menuAdmin = session()->get('menuDataAdmin');
        $menuItems = $menuAdmin['result'] ?? [];

        $tenantContext = $this->currentTenantContext();
        if ($tenantContext !== null && in_array($tenantContext->tenantRole, ['tenant_master', 'tenant_admin'], true)) {
            return view('admin/dashboard_tenant', $this->buildTenantDashboardData($tenantContext, $menuItems));
        }

        $seedReport = $this->loadLatestDemoSeedReport();

        return view('admin/dashboard', [
            'menu_items' => $menuItems,
            'pageTitle' => 'Admin',
            'demoScenario' => $this->demoScenario(),
            'demoSeedStatus' => [
                'finished_at' => (string)($seedReport['finished_at'] ?? ''),
                'database' => (string)($seedReport['target_db'] ?? ''),
                'password' => (string)($seedReport['password'] ?? 'Demo2026'),
                'summary' => (array)($seedReport['summary'] ?? []),
            ],
        ]);
    }

    private function currentTenantContext(): ?TenantContext
    {
        $raw = session()->get(TenantContextService::SESSION_KEY);
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $context = TenantContext::fromArray($raw);
        return $context->isValid() ? $context : null;
    }

    /**
     * @param list<array<string, mixed>> $menuItems
     * @return array<string, mixed>
     */
    private function buildTenantDashboardData(TenantContext $tenantContext, array $menuItems): array
    {
        $tenant = $this->tenantCatalog->getTenantById($tenantContext->tenantId);
        $capacity = $this->tenantProvisioning->getTenantUserCapacity($tenantContext->tenantId);
        $locationCount = count((new StaffLocationCatalogService($this->db))->listSelectableLocations());
        $personnelCount = $this->countTableRows('dap03_personale');
        $clientCount = $this->countTableRows('dap02_clients');
        $teamCount = count($this->tenantProvisioning->listTenantMembers($tenantContext->tenantId));
        $shortcutActions = $this->buildTenantShortcutActions($menuItems);

        $checklist = [
            [
                'title' => 'Imposta le sedi',
                'description' => $locationCount > 0
                    ? 'Le sedi sono gia pronte e il personale puo essere assegnato ai luoghi corretti.'
                    : 'Prima di inserire il personale configura almeno una sede e le sue stanze operative.',
                'complete' => $locationCount > 0,
                'cta_label' => $locationCount > 0 ? 'Gestisci sedi' : 'Configura sedi',
                'cta_url' => site_url('agenda/gestione-sedi'),
                'icon' => 'fa-map-marker',
                'counter' => $locationCount,
                'counter_label' => $locationCount === 1 ? 'sede pronta' : 'sedi pronte',
            ],
            [
                'title' => 'Crea il personale',
                'description' => $personnelCount > 0
                    ? 'Hai gia persone attive nello spazio. Da qui puoi aggiungerne altre o aggiornarle.'
                    : 'Appena le sedi sono pronte puoi inserire il primo medico, operatore o segreteria.',
                'complete' => $personnelCount > 0,
                'cta_label' => $personnelCount > 0 ? 'Nuovo personale' : 'Inserisci il primo personale',
                'cta_url' => site_url('admin/personale/nuovo'),
                'icon' => 'fa-user-plus',
                'counter' => $personnelCount,
                'counter_label' => $personnelCount === 1 ? 'profilo personale' : 'profili personale',
            ],
            [
                'title' => 'Carica i clienti',
                'description' => $clientCount > 0
                    ? 'L anagrafica clienti e gia avviata e puoi continuare a lavorarci subito.'
                    : 'Dopo il team, popola i primi clienti cosi i flussi agenda e comunicazione partono puliti.',
                'complete' => $clientCount > 0,
                'cta_label' => $clientCount > 0 ? 'Apri clienti' : 'Aggiungi clienti',
                'cta_url' => site_url('admin/personale/modifica_cliente'),
                'icon' => 'fa-building-o',
                'counter' => $clientCount,
                'counter_label' => $clientCount === 1 ? 'cliente' : 'clienti',
            ],
        ];

        $completedSteps = count(array_filter($checklist, static fn(array $item): bool => !empty($item['complete'])));
        $totalSteps = count($checklist);
        $completionPercent = $totalSteps > 0
            ? (int) round(($completedSteps / $totalSteps) * 100)
            : 0;

        return [
            'menu_items' => $menuItems,
            'pageTitle' => 'Dashboard spazio',
            'tenantContext' => $tenantContext,
            'tenant' => $tenant,
            'capacity' => $capacity,
            'shortcutActions' => $shortcutActions,
            'checklist' => $checklist,
            'dashboardStats' => [
                'location_count' => $locationCount,
                'personnel_count' => $personnelCount,
                'client_count' => $clientCount,
                'team_count' => $teamCount,
                'current_users' => (int) ($capacity['current_users'] ?? 0),
                'remaining_users' => $capacity['remaining_users'] ?? null,
                'package_name' => (string) ($capacity['package_name'] ?? $capacity['package_code'] ?? 'Base'),
            ],
            'completion' => [
                'completed' => $completedSteps,
                'total' => $totalSteps,
                'percent' => $completionPercent,
            ],
            'requiresLocationSetup' => $locationCount === 0,
        ];
    }

    private function countTableRows(string $table): int
    {
        if (!$this->db->tableExists($table)) {
            return 0;
        }

        return (int) $this->db->table($table)->countAllResults();
    }

    /**
     * @param list<array<string, mixed>> $menuItems
     * @return list<array<string, string>>
     */
    private function buildTenantShortcutActions(array $menuItems): array
    {
        $catalogByLink = [];
        foreach ($this->tenantAdminMenu->catalog() as $item) {
            $catalogByLink[(string) ($item['link'] ?? '')] = $item;
        }

        $configuredLinks = [];
        foreach ($menuItems as $item) {
            $link = $this->tenantAdminMenu->normalizeLink((string) ($item['link'] ?? ''));
            if ($link !== '' && isset($catalogByLink[$link]) && !in_array($link, $configuredLinks, true)) {
                $configuredLinks[] = $link;
            }
        }

        $shortcutLinks = [];
        foreach ($this->tenantAdminMenu->defaultLinks() as $defaultLink) {
            if (in_array($defaultLink, $configuredLinks, true)) {
                $shortcutLinks[] = $defaultLink;
            }
        }

        if ($shortcutLinks === [] && $configuredLinks !== []) {
            $shortcutLinks = array_slice($configuredLinks, 0, 4);
        }

        if ($shortcutLinks === []) {
            $shortcutLinks = $this->tenantAdminMenu->defaultLinks();
        }

        $actions = [];
        foreach ($shortcutLinks as $link) {
            $item = $catalogByLink[$link] ?? null;
            if (!is_array($item)) {
                continue;
            }

            $actions[] = [
                'title' => (string) ($item['title'] ?? $link),
                'description' => (string) ($item['description'] ?? ''),
                'href' => admin_menu_resolve_href($link),
                'icon' => (string) ($item['icon'] ?? 'fa-circle-o'),
            ];
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>
     */
    private function demoScenario(): array
    {
        return [
            'title' => 'Studio Nutrizione Equilibrio',
            'subtitle' => 'Demo locale costruita per uno studio di dietistica con 2 professionisti e 1 segreteria.',
            'metrics' => [
                ['value' => '2', 'label' => 'professionisti'],
                ['value' => '1', 'label' => 'segreteria'],
                ['value' => '3', 'label' => 'stanze demo'],
                ['value' => '1', 'label' => 'accesso admin'],
            ],
            'focus' => [
                'Agenda condivisa per prime visite, controlli e follow-up ricorrenti.',
                'Segreteria con visione operativa chiara su conferme, spostamenti e reminder.',
                'Continuita tra professionisti, chat interna e comunicazione strutturata col paziente.',
            ],
            'roles' => [
                [
                    'title' => 'Admin',
                    'account' => 'demo.admin',
                    'goal' => 'Apri la demo spiegando struttura, ruoli, visibilita moduli e relazione tra segreteria e professionisti.',
                ],
                [
                    'title' => 'Segreteria',
                    'account' => 'demo.admin->demo.segreteria',
                    'goal' => 'Mostra agenda del giorno, ricerca disponibilita, inserimento visita e gestione conferme.',
                ],
                [
                    'title' => 'Dietista',
                    'account' => 'demo.dietista',
                    'goal' => 'Fai vedere follow-up, posta, chat interna e passaggio MFA con OTP fisso 2510.',
                ],
                [
                    'title' => 'Collaboratrice',
                    'account' => 'demo.nutrizionista',
                    'goal' => 'Chiudi facendo vedere come piu professionisti convivono senza perdere ordine o responsabilita.',
                ],
            ],
            'timeline' => [
                '1. Parti dall admin e spiega in 60 secondi che la demo usa database separato e dati anonimi.',
                '2. Passa alla segreteria e fai vedere come si riempie o si sposta un appuntamento.',
                '3. Entra come dietista e collega agenda, follow-up, messaggi e continuita col paziente.',
                '4. Se serve, fai il passaggio OTP per far vedere che l accesso e protetto ma semplice.',
                '5. Chiudi tornando sul valore: meno caos operativo, meno no-show, piu controllo del team.',
            ],
            'talkingPoints' => [
                'Non vi sto facendo vedere una semplice agenda: vi sto facendo vedere come lavora lo studio quando ci sono piu persone coinvolte.',
                'La segreteria guadagna velocita, ma ogni professionista mantiene il proprio contesto clinico e operativo.',
                'Il vantaggio vero non e solo prenotare: e tenere insieme conferme, spostamenti, comunicazione e sicurezza di accesso.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadLatestDemoSeedReport(): ?array
    {
        $matches = glob(WRITEPATH . 'demo_setup' . DIRECTORY_SEPARATOR . 'demo_seed_*.json') ?: [];
        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a)
        );

        $content = file_get_contents($matches[0]);
        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }
}
