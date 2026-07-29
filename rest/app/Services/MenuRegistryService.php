<?php

namespace App\Services;

class MenuRegistryService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function tenantAdminCatalog(): array
    {
        return [
            [
                'key' => 'personale/nuovo',
                'link' => 'personale/nuovo',
                'title' => 'Nuovo personale',
                'icon' => 'fa-user-plus',
                'order' => 100,
                'default' => true,
                'group' => 'Menu operativo',
                'description' => 'Inserisci i membri del team operativo del tenant.',
                'route_prefixes' => [
                    'admin/personale/nuovo',
                ],
            ],
            [
                'key' => 'personale/nuovo_cliente',
                'link' => 'personale/nuovo_cliente',
                'title' => 'Nuovo cliente',
                'icon' => 'fa-user-plus',
                'order' => 200,
                'default' => true,
                'group' => 'Menu operativo',
                'description' => 'Apri subito una scheda vuota per inserire un nuovo cliente.',
                'route_prefixes' => [
                    'admin/personale/nuovo_cliente',
                ],
            ],
            [
                'key' => 'personale/modifica_personale',
                'link' => 'personale/modifica_personale',
                'title' => 'Modifica personale',
                'icon' => 'fa-pencil',
                'order' => 300,
                'default' => true,
                'group' => 'Menu operativo',
                'description' => 'Aggiorna dati, luoghi e permessi del personale.',
                'route_prefixes' => [
                    'admin/personale/modifica_personale',
                    'admin/personale/search',
                    'admin/personale/get',
                    'admin/personale/update',
                    'admin/personale/elimina-account',
                    'admin/personale/elimina-dottore',
                ],
            ],
            [
                'key' => 'personale/modifica_cliente',
                'link' => 'personale/modifica_cliente',
                'title' => 'Modifica cliente',
                'icon' => 'fa-building-o',
                'order' => 400,
                'default' => true,
                'group' => 'Menu operativo',
                'description' => 'Cerca, aggiorna e gestisci l anagrafica clienti gia presenti.',
                'route_prefixes' => [
                    'admin/personale/modifica_cliente',
                    'admin/clienti/search',
                    'admin/clienti/get',
                    'admin/clienti/update',
                    'admin/clienti/device/disconnect',
                ],
            ],
            [
                'key' => 'agenda/gestione-sedi',
                'link' => 'agenda/gestione-sedi',
                'title' => 'Gestione sedi',
                'icon' => 'fa-map-marker',
                'order' => 450,
                'default' => true,
                'group' => 'Menu operativo',
                'description' => 'Configura sedi e stanze prima di inserire il personale.',
                'route_prefixes' => [
                    'agenda/gestione-sedi',
                    'admin/anagrafica/sedi',
                    'admin/anagrafica/sedi/save',
                    'admin/anagrafica/sedi/toggle',
                    'admin/anagrafica/sedi/stanza/save',
                    'admin/anagrafica/sedi/stanza/toggle',
                ],
            ],
            [
                'key' => 'agenda/elimina-appuntamenti-massivo',
                'link' => 'agenda/elimina-appuntamenti-massivo',
                'title' => 'Elimina appuntamenti',
                'icon' => 'fa-calendar-times-o',
                'order' => 500,
                'default' => true,
                'group' => 'Menu operativo',
                'description' => 'Cerca un paziente ed elimina gli appuntamenti futuri selezionati.',
                'route_prefixes' => [
                    'agenda/elimina-appuntamenti-massivo',
                ],
            ],
            [
                'key' => 'personale/visibilita-moduli',
                'link' => 'personale/visibilita-moduli',
                'title' => 'Visibilita moduli',
                'icon' => 'fa-toggle-on',
                'order' => 600,
                'default' => false,
                'group' => 'Menu operativo',
                'description' => 'Decidi dove ogni operatore compare dentro il gestionale.',
                'route_prefixes' => [
                    'admin/personale/visibilita-moduli',
                    'admin/personale/visibilita-moduli/search',
                    'admin/personale/visibilita-moduli/get',
                    'admin/personale/visibilita-moduli/update',
                ],
            ],
            [
                'key' => 'personale/dap14',
                'link' => 'personale/dap14',
                'title' => 'Segretarie e medici',
                'icon' => 'fa-users',
                'order' => 700,
                'default' => false,
                'group' => 'Menu operativo',
                'description' => 'Collega segretarie e medici quando serve una regia condivisa.',
                'route_prefixes' => [
                    'admin/personale/dap14',
                    'admin/personale/dap14/update',
                ],
            ],
            [
                'key' => 'personale/dap15',
                'link' => 'personale/dap15',
                'title' => 'Infermieri e medici',
                'icon' => 'fa-heartbeat',
                'order' => 800,
                'default' => false,
                'group' => 'Menu operativo',
                'description' => 'Gestisci le relazioni tra infermieri e medici del tenant.',
                'route_prefixes' => [
                    'admin/personale/dap15',
                    'admin/personale/dap15/update',
                ],
            ],
            [
                'key' => 'personale/schede-utenti',
                'link' => 'personale/schede-utenti',
                'title' => 'Schede utente',
                'icon' => 'fa-th-large',
                'order' => 900,
                'default' => false,
                'group' => 'Menu operativo',
                'description' => 'Assegna le schede operative che compaiono nella home utente.',
                'route_prefixes' => [
                    'admin/personale/schede-utenti',
                    'admin/schede-utenti/cerca',
                    'admin/schede-utenti/lista',
                    'admin/schede-utenti/toggle',
                    'admin/schede-utenti/menu-admin',
                    'admin/schede-utenti/menu-admin/toggle',
                ],
            ],
            [
                'key' => 'sostituti',
                'link' => 'sostituti',
                'title' => 'Gestione sostituti',
                'icon' => 'fa-exchange',
                'order' => 1000,
                'default' => false,
                'group' => 'Menu operativo',
                'description' => 'Configura sostituzioni e coperture temporanee del personale.',
                'route_prefixes' => [
                    'admin/personale/sostituti',
                    'admin/sostituti/salva',
                    'admin/sostituti/elimina',
                ],
            ],
            [
                'key' => 'otp-statistiche',
                'link' => 'otp-statistiche',
                'title' => 'Statistiche OTP',
                'icon' => 'fa-line-chart',
                'order' => 1100,
                'default' => false,
                'group' => 'Menu operativo',
                'description' => 'Controlla i tentativi OTP e lo stato degli accessi protetti.',
                'route_prefixes' => [
                    'admin/otp-statistiche',
                    'admin/otp-statistiche/csv',
                ],
            ],
            [
                'key' => 'whatsapp-reminders',
                'link' => 'whatsapp-reminders',
                'title' => 'Stato reminder WhatsApp',
                'icon' => 'fa-whatsapp',
                'order' => 1200,
                'default' => false,
                'group' => 'Menu operativo',
                'description' => 'Monitora l area reminder e notifiche degli appuntamenti.',
                'route_prefixes' => [
                    'admin/whatsapp-reminders',
                    'admin/whatsapp-reminders/launch',
                    'admin/whatsapp-reminders/run',
                ],
            ],
            [
                'key' => 'logs',
                'link' => 'logs',
                'title' => 'Log di sistema',
                'icon' => 'fa-file-text-o',
                'order' => 1300,
                'default' => false,
                'group' => 'Menu operativo',
                'description' => 'Consulta i log operativi disponibili per il tenant.',
                'route_prefixes' => [
                    'admin/personale/logs',
                    'admin/logs/read',
                    'admin/logs/download',
                    'admin/logs/list',
                ],
            ],
            [
                'key' => 'fatturazione',
                'link' => 'fatturazione',
                'title' => 'Fatturazione',
                'icon' => 'fa-calculator',
                'order' => 1400,
                'default' => false,
                'group' => 'Menu operativo',
                'description' => 'Apri il modulo documenti cliente, separato ma compatibile con il Sistema TS.',
                'route_prefixes' => [
                    'admin/fatturazione',
                ],
            ],
            [
                'key' => 'fatturazione-documenti',
                'link' => 'fatturazione-documenti',
                'title' => 'Lista fatture',
                'icon' => 'fa-folder-open-o',
                'order' => 1410,
                'default' => false,
                'group' => 'Menu operativo',
                'description' => 'Consulta, modifica, elimina e stampa le fatture del modulo Fatturazione.',
                'route_prefixes' => [
                    'admin/fatturazione-documenti',
                    'admin/fatturazione-documenti/nuovo',
                    'admin/fatturazione-documenti/modifica',
                    'admin/fatturazione-documenti/save',
                    'admin/fatturazione-documenti/elimina',
                    'admin/fatturazione-documenti/preview',
                    'admin/fatturazione-documenti/pdf',
                ],
            ],
            [
                'key' => 'fatturazione-ts',
                'link' => 'sistema-ts',
                'title' => 'Sistema TS',
                'icon' => 'fa-file-text-o',
                'order' => 1420,
                'default' => false,
                'group' => 'Menu operativo',
                'description' => 'Apri dashboard, documenti e diagnostica del modulo Sistema Tessera Sanitaria.',
                'route_prefixes' => [
                    'admin/sistema-ts',
                    'admin/sistema-ts/diagnostica',
                    'admin/sistema-ts/diagnostica/download',
                    'admin/sistema-ts/documenti',
                    'admin/sistema-ts/documenti/nuovo',
                    'admin/sistema-ts/documenti/modifica',
                    'admin/sistema-ts/documenti/save',
                    'admin/sistema-ts/documenti/send',
                    'admin/fatturazione-ts',
                    'admin/fatturazione-ts/diagnostica',
                    'admin/fatturazione-ts/diagnostica/download',
                    'admin/fatturazione-ts/documenti',
                    'admin/fatturazione-ts/documenti/nuovo',
                    'admin/fatturazione-ts/documenti/modifica',
                    'admin/fatturazione-ts/documenti/save',
                    'admin/fatturazione-ts/documenti/send',
                ],
            ],
            [
                'key' => 'fatturazione-documento',
                'link' => 'fatturazione-documento',
                'title' => 'Documento fatturazione',
                'icon' => 'fa-file-text-o',
                'order' => 1430,
                'default' => false,
                'group' => 'Menu operativo',
                'description' => 'Configura modello documento, campi, logo e regole di integrazione del modulo Fatturazione.',
                'route_prefixes' => [
                    'admin/fatturazione-documento',
                    'admin/fatturazione-documento/save',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tenantContextCatalog(): array
    {
        return [
            [
                'key' => 'spazio/utenti',
                'link' => 'spazio/utenti',
                'title' => 'Gestisci utenti dello spazio',
                'icon' => 'fa-users',
                'order' => 2000,
                'group' => 'Console spazio',
                'description' => 'Voce rapida della console spazio per utenti e inviti.',
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
                'key' => 'spazio/dispositivi-otp',
                'link' => 'spazio/dispositivi-otp',
                'title' => 'Gestisci dispositivi OTP',
                'icon' => 'fa-mobile',
                'order' => 2050,
                'group' => 'Console spazio',
                'description' => 'Voce rapida della console spazio per dispositivi OTP collegati.',
                'route_prefixes' => [
                    'spazio/dispositivi-otp',
                    'spazio/dispositivi-otp/disconnect',
                    'login/spazio/dispositivi-otp',
                    'login/spazio/dispositivi-otp/disconnect',
                ],
            ],
            [
                'key' => 'spazio/funzioni',
                'link' => 'spazio/funzioni',
                'title' => 'Gestisci funzioni dello spazio',
                'icon' => 'fa-toggle-on',
                'order' => 2100,
                'group' => 'Console spazio',
                'description' => 'Voce rapida della console spazio per attivare o disattivare feature.',
                'route_prefixes' => [
                    'spazio/funzioni',
                    'spazio/funzioni/save',
                    'login/spazio/funzioni',
                    'login/spazio/funzioni/save',
                ],
            ],
            [
                'key' => 'spazio/fatturazione',
                'link' => 'spazio/fatturazione',
                'title' => 'Configura Fatturazione',
                'icon' => 'fa-calculator',
                'order' => 2150,
                'group' => 'Console spazio',
                'description' => 'Voce rapida della console spazio per il modulo documenti cliente.',
                'route_prefixes' => [
                    'spazio/fatturazione',
                    'login/spazio/fatturazione',
                ],
            ],
            [
                'key' => 'spazio/fatturazione-ts',
                'link' => 'spazio/sistema-ts',
                'title' => 'Configura Sistema TS',
                'icon' => 'fa-file-text-o',
                'order' => 2175,
                'group' => 'Console spazio',
                'description' => 'Voce rapida della console spazio per credenziali e profilo Sistema Tessera Sanitaria.',
                'route_prefixes' => [
                    'spazio/sistema-ts',
                    'spazio/sistema-ts/save',
                    'spazio/sistema-ts/healthcheck',
                    'login/spazio/sistema-ts',
                    'login/spazio/sistema-ts/save',
                    'login/spazio/sistema-ts/healthcheck',
                    'spazio/fatturazione-ts',
                    'spazio/fatturazione-ts/save',
                    'spazio/fatturazione-ts/healthcheck',
                    'login/spazio/fatturazione-ts',
                    'login/spazio/fatturazione-ts/save',
                    'login/spazio/fatturazione-ts/healthcheck',
                ],
            ],
            [
                'key' => 'spazio/notifiche-appuntamenti',
                'link' => 'spazio/notifiche-appuntamenti',
                'title' => 'Gestisci notifiche appuntamenti',
                'icon' => 'fa-comments',
                'order' => 2200,
                'group' => 'Console spazio',
                'description' => 'Voce rapida della console spazio per reminder e notifiche operative.',
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
     * @return list<array<string, mixed>>
     */
    public function platformConsoleCatalog(): array
    {
        return [
            [
                'key' => 'piattaforma/spazi-clienti',
                'link' => 'piattaforma/spazi-clienti',
                'title' => 'Spazi cliente',
                'icon' => 'fa-sitemap',
                'order' => 100,
                'group' => 'Console piattaforma',
                'description' => 'Gestisci onboarding, pacchetti e configurazioni dei tenant.',
                'exact_paths' => [
                    'login/piattaforma',
                    'piattaforma',
                ],
                'route_prefixes' => [
                    'login/piattaforma/spazi-clienti',
                    'piattaforma/spazi-clienti',
                ],
            ],
            [
                'key' => 'piattaforma/funzioni',
                'link' => 'piattaforma/funzioni',
                'title' => 'Catalogo funzioni',
                'icon' => 'fa-toggle-on',
                'order' => 200,
                'group' => 'Console piattaforma',
                'description' => 'Configura il catalogo funzioni disponibile a livello piattaforma.',
                'route_prefixes' => [
                    'login/piattaforma/funzioni',
                    'login/piattaforma/funzioni/save',
                    'piattaforma/funzioni',
                    'piattaforma/funzioni/save',
                ],
            ],
            [
                'key' => 'piattaforma/impersonificazione',
                'link' => 'piattaforma/impersonificazione',
                'title' => 'Accesso delegato',
                'icon' => 'fa-user-secret',
                'order' => 300,
                'group' => 'Console piattaforma',
                'description' => 'Apri una sessione temporanea come utente dello spazio con audit.',
                'route_prefixes' => [
                    'login/piattaforma/impersonificazione',
                    'login/piattaforma/impersonificazione/start',
                    'login/piattaforma/impersonificazione/stop',
                    'piattaforma/impersonificazione',
                    'piattaforma/impersonificazione/start',
                    'piattaforma/impersonificazione/stop',
                ],
            ],
            [
                'key' => 'piattaforma/dispositivi-otp',
                'link' => 'piattaforma/dispositivi-otp',
                'title' => 'Dispositivi OTP',
                'icon' => 'fa-mobile',
                'order' => 400,
                'group' => 'Console piattaforma',
                'description' => 'Controlla i dispositivi OTP collegati a livello piattaforma.',
                'route_prefixes' => [
                    'login/piattaforma/dispositivi-otp',
                    'login/piattaforma/dispositivi-otp/disconnect',
                    'piattaforma/dispositivi-otp',
                    'piattaforma/dispositivi-otp/disconnect',
                ],
            ],
            [
                'key' => 'piattaforma/notifiche-appuntamenti',
                'link' => 'piattaforma/notifiche-appuntamenti',
                'title' => 'Notifiche appuntamenti',
                'icon' => 'fa-comments',
                'order' => 500,
                'group' => 'Console piattaforma',
                'description' => 'Monitora i flussi centralizzati di reminder e notifiche appuntamenti.',
                'route_prefixes' => [
                    'login/piattaforma/notifiche-appuntamenti',
                    'login/piattaforma/notifiche-appuntamenti/launch',
                    'login/piattaforma/notifiche-appuntamenti/run',
                    'piattaforma/notifiche-appuntamenti',
                    'piattaforma/notifiche-appuntamenti/launch',
                    'piattaforma/notifiche-appuntamenti/run',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTenantAdminItem(string $key): ?array
    {
        return $this->findByKey($this->tenantAdminCatalog(), $key);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTenantContextItem(string $key): ?array
    {
        return $this->findByKey($this->tenantContextCatalog(), $key);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPlatformConsoleItem(string $key): ?array
    {
        return $this->findByKey($this->platformConsoleCatalog(), $key);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private function findByKey(array $items, string $key): ?array
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        foreach ($items as $item) {
            if (trim((string) ($item['key'] ?? '')) === $key) {
                return $item;
            }
        }

        return null;
    }
}
