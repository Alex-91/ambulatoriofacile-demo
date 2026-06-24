<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

class TenantAdminMenuService
{
    /**
     * Link da retro-inserire negli studi gia esistenti quando mancavano
     * nei menu creati prima dell'introduzione della nuova voce.
     *
     * @return list<string>
     */
    private function requiredBackfillLinks(): array
    {
        return [
            'agenda/gestione-sedi',
        ];
    }

    private TenantDatabaseConnector $tenantDbConnector;

    public function __construct()
    {
        $this->tenantDbConnector = new TenantDatabaseConnector();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        return [
            [
                'key' => 'staff_create',
                'title' => 'Nuovo personale',
                'link' => 'personale/nuovo',
                'icon' => 'fa-user-plus',
                'order' => 100,
                'default' => true,
                'description' => 'Inserisci i membri del team operativo del tenant.',
            ],
            [
                'key' => 'client_create',
                'title' => 'Nuovo cliente',
                'link' => 'personale/nuovo_cliente',
                'icon' => 'fa-user-plus',
                'order' => 200,
                'default' => true,
                'description' => 'Apri subito una scheda vuota per inserire un nuovo cliente.',
            ],
            [
                'key' => 'staff_edit',
                'title' => 'Modifica personale',
                'link' => 'personale/modifica_personale',
                'icon' => 'fa-pencil',
                'order' => 300,
                'default' => true,
                'description' => 'Aggiorna dati, luoghi e permessi del personale.',
            ],
            [
                'key' => 'client_edit',
                'title' => 'Modifica cliente',
                'link' => 'personale/modifica_cliente',
                'icon' => 'fa-building-o',
                'order' => 400,
                'default' => true,
                'description' => 'Cerca, aggiorna e gestisci l anagrafica clienti gia presenti.',
            ],
            [
                'key' => 'locations',
                'title' => 'Gestione sedi',
                'link' => 'agenda/gestione-sedi',
                'icon' => 'fa-map-marker',
                'order' => 450,
                'default' => true,
                'description' => 'Configura sedi e stanze prima di inserire il personale.',
            ],
            [
                'key' => 'module_visibility',
                'title' => 'Visibilita moduli',
                'link' => 'personale/visibilita-moduli',
                'icon' => 'fa-toggle-on',
                'order' => 600,
                'default' => false,
                'description' => 'Decidi dove ogni operatore compare dentro il gestionale.',
            ],
            [
                'key' => 'secretary_links',
                'title' => 'Segretarie e medici',
                'link' => 'personale/dap14',
                'icon' => 'fa-users',
                'order' => 700,
                'default' => false,
                'description' => 'Collega segretarie e medici quando serve una regia condivisa.',
            ],
            [
                'key' => 'nurse_links',
                'title' => 'Infermieri e medici',
                'link' => 'personale/dap15',
                'icon' => 'fa-heartbeat',
                'order' => 800,
                'default' => false,
                'description' => 'Gestisci le relazioni tra infermieri e medici del tenant.',
            ],
            [
                'key' => 'user_cards',
                'title' => 'Schede utente',
                'link' => 'personale/schede-utenti',
                'icon' => 'fa-th-large',
                'order' => 900,
                'default' => false,
                'description' => 'Assegna le schede operative che compaiono nella home utente.',
            ],
            [
                'key' => 'substitutions',
                'title' => 'Gestione sostituti',
                'link' => 'sostituti',
                'icon' => 'fa-exchange',
                'order' => 1000,
                'default' => false,
                'description' => 'Configura sostituzioni e coperture temporanee del personale.',
            ],
            [
                'key' => 'otp_stats',
                'title' => 'Statistiche OTP',
                'link' => 'otp-statistiche',
                'icon' => 'fa-line-chart',
                'order' => 1100,
                'default' => false,
                'description' => 'Controlla i tentativi OTP e lo stato degli accessi protetti.',
            ],
            [
                'key' => 'whatsapp_reminders',
                'title' => 'Stato reminder WhatsApp',
                'link' => 'whatsapp-reminders',
                'icon' => 'fa-whatsapp',
                'order' => 1200,
                'default' => false,
                'description' => 'Monitora l area reminder e notifiche degli appuntamenti.',
            ],
            [
                'key' => 'logs',
                'title' => 'Log di sistema',
                'link' => 'logs',
                'icon' => 'fa-file-text-o',
                'order' => 1300,
                'default' => false,
                'description' => 'Consulta i log operativi disponibili per il tenant.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function defaultLinks(): array
    {
        $defaults = [];
        foreach ($this->catalog() as $item) {
            if (!empty($item['default'])) {
                $defaults[] = (string) ($item['link'] ?? '');
            }
        }

        return $defaults;
    }

    public function normalizeLink(string $link): string
    {
        $normalized = trim(str_replace('\\', '/', $link), '/');
        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, 'admin/')) {
            $normalized = substr($normalized, strlen('admin/'));
        }

        return match ($normalized) {
            'nuovo' => 'personale/nuovo',
            'nuovo_cliente', 'personale/nuovo_cliente', 'clienti/nuovo' => 'personale/nuovo_cliente',
            'modifica_personale' => 'personale/modifica_personale',
            'modifica_cliente' => 'personale/modifica_cliente',
            'agenda/sedi', 'anagrafica/sedi', 'gestione-sedi', 'agenda/gestione-sedi' => 'agenda/gestione-sedi',
            default => $normalized,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listConfiguredMenu(BaseConnection $db): array
    {
        if (!$db->tableExists('dap06_mnu')) {
            return [];
        }

        $query = $db->query("
            SELECT titolo_menu,
                   class,
                   class_icon,
                   admin,
                   CASE
                     WHEN COALESCE(NULLIF(link2, ''), '') <> '' THEN link2
                     ELSE link
                   END AS link
            FROM dap06_mnu
            WHERE admin = 1
            ORDER BY ordinamento ASC, id_mnu ASC
        ");

        return $query ? $query->getResultArray() : [];
    }

    /**
     * @return list<string>
     */
    public function currentLinks(BaseConnection $db): array
    {
        $links = [];
        foreach ($this->listConfiguredMenu($db) as $row) {
            $link = $this->normalizeLink((string) ($row['link'] ?? ''));
            if ($link !== '' && !in_array($link, $links, true)) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ensureDefaultMenuIfEmpty(BaseConnection $db): array
    {
        $current = $this->listConfiguredMenu($db);
        if ($current !== []) {
            return $this->ensureRequiredLinksPresent($db, $this->requiredBackfillLinks());
        }

        return $this->syncConfiguredLinks($db, $this->defaultLinks());
    }

    /**
     * @param list<string> $requiredLinks
     * @return list<array<string, mixed>>
     */
    public function ensureRequiredLinksPresent(BaseConnection $db, array $requiredLinks): array
    {
        if (!$db->tableExists('dap06_mnu')) {
            return [];
        }

        $catalogByLink = [];
        foreach ($this->catalog() as $item) {
            $catalogByLink[(string) ($item['link'] ?? '')] = $item;
        }

        $rows = $db->table('dap06_mnu')
            ->select('id_mnu, titolo_menu, link, link2, class_icon, admin, ordinamento')
            ->where('admin', 1)
            ->orderBy('ordinamento', 'ASC')
            ->orderBy('id_mnu', 'ASC')
            ->get()
            ->getResultArray();

        if ($rows === []) {
            return $this->syncConfiguredLinks($db, $this->defaultLinks());
        }

        $normalizedRows = [];
        foreach ($rows as $row) {
            $normalized = $this->normalizeLink($this->menuLinkFromRow($row));
            if ($normalized !== '' && !isset($normalizedRows[$normalized])) {
                $normalizedRows[$normalized] = $row;
            }
        }

        foreach ($requiredLinks as $link) {
            $normalized = $this->normalizeLink((string) $link);
            if ($normalized === '' || !isset($catalogByLink[$normalized])) {
                continue;
            }

            $item = $catalogByLink[$normalized];
            $payload = [
                'titolo_menu' => (string) ($item['title'] ?? $normalized),
                'link' => $normalized,
                'link2' => $normalized,
                'class' => '',
                'class_icon' => (string) ($item['icon'] ?? 'fa-circle-o'),
                'admin' => 1,
                'ordinamento' => (int) ($item['order'] ?? 0),
            ];

            if (isset($normalizedRows[$normalized])) {
                $existing = $normalizedRows[$normalized];
                $needsUpdate =
                    trim((string) ($existing['titolo_menu'] ?? '')) !== $payload['titolo_menu']
                    || $this->menuLinkFromRow($existing) !== $normalized
                    || trim((string) ($existing['class_icon'] ?? '')) !== $payload['class_icon']
                    || (int) ($existing['ordinamento'] ?? 0) !== $payload['ordinamento'];

                if ($needsUpdate) {
                    $db->table('dap06_mnu')
                        ->where('id_mnu', (int) ($existing['id_mnu'] ?? 0))
                        ->update($payload);
                }

                continue;
            }

            $db->table('dap06_mnu')->insert($payload);
        }

        return $this->listConfiguredMenu($db);
    }

    /**
     * @param list<string> $selectedLinks
     * @return list<array<string, mixed>>
     */
    public function syncConfiguredLinks(BaseConnection $db, array $selectedLinks): array
    {
        if (!$db->tableExists('dap06_mnu')) {
            throw new \RuntimeException('La tabella menu admin del tenant non e disponibile.');
        }

        $catalogByLink = [];
        foreach ($this->catalog() as $item) {
            $catalogByLink[(string) ($item['link'] ?? '')] = $item;
        }

        $normalizedLinks = [];
        foreach ($selectedLinks as $link) {
            $normalized = $this->normalizeLink((string) $link);
            if ($normalized === '' || !isset($catalogByLink[$normalized]) || in_array($normalized, $normalizedLinks, true)) {
                continue;
            }

            $normalizedLinks[] = $normalized;
        }

        $db->transBegin();

        try {
            $db->table('dap06_mnu')
                ->where('admin', 1)
                ->delete();

            foreach ($normalizedLinks as $link) {
                $item = $catalogByLink[$link];

                $db->table('dap06_mnu')->insert([
                    'titolo_menu' => (string) ($item['title'] ?? $link),
                    'link' => $link,
                    'link2' => $link,
                    'class' => '',
                    'class_icon' => (string) ($item['icon'] ?? 'fa-circle-o'),
                    'admin' => 1,
                    'ordinamento' => (int) ($item['order'] ?? 0),
                ]);
            }

            if (!$db->transStatus()) {
                throw new \RuntimeException('Aggiornamento menu admin tenant non riuscito.');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        return $this->listConfiguredMenu($db);
    }

    /**
     * @return array<string, mixed>
     */
    public function loadCatalogStateForTenant(array $tenant): array
    {
        $selectedLinks = $this->defaultLinks();
        $available = false;
        $warning = null;

        try {
            $tenantDb = $this->tenantDbConnector->connect($tenant);
            $this->ensureRequiredLinksPresent($tenantDb, $this->requiredBackfillLinks());
            $selectedLinks = $this->currentLinks($tenantDb);
            if ($selectedLinks === []) {
                $selectedLinks = $this->defaultLinks();
            }
            $available = true;
        } catch (\Throwable $e) {
            $warning = $e->getMessage();
        }

        return [
            'available' => $available,
            'warning' => $warning,
            'links' => $selectedLinks,
            'items' => $this->catalogStateFromLinks($selectedLinks),
        ];
    }

    /**
     * @param list<string> $selectedLinks
     * @return list<array<string, mixed>>
     */
    public function catalogStateFromLinks(array $selectedLinks): array
    {
        $selectedLookup = [];
        foreach ($selectedLinks as $link) {
            $normalized = $this->normalizeLink((string) $link);
            if ($normalized !== '') {
                $selectedLookup[$normalized] = true;
            }
        }

        $items = [];
        foreach ($this->catalog() as $item) {
            $link = (string) ($item['link'] ?? '');
            $item['selected'] = isset($selectedLookup[$link]);
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param list<string> $selectedLinks
     * @return list<array<string, mixed>>
     */
    public function syncForTenant(array $tenant, array $selectedLinks): array
    {
        $tenantDb = $this->tenantDbConnector->connect($tenant);
        return $this->syncConfiguredLinks($tenantDb, $selectedLinks);
    }

    private function menuLinkFromRow(array $row): string
    {
        $link2 = trim((string) ($row['link2'] ?? ''));
        if ($link2 !== '' && $link2 !== '#') {
            return $link2;
        }

        return trim((string) ($row['link'] ?? ''));
    }
}
