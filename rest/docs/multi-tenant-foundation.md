# Fondazione Multi Tenant

## Obiettivo di questo primo step

Preparare una base tecnica per:

- login unico su `ambulatoriofacile.it/login`
- un solo dominio pubblico
- spazi cliente separati dietro le quinte
- pacchetti e feature flag gestibili senza hardcodare eccezioni per username

Questo step introduce gia il primo aggancio al login unico, mantenendo come fallback il login legacy esistente.

## Cosa introduce

### Tabelle piattaforma

Migration: `rest/app/Database/Migrations/2026-06-19-000001_CreatePlatformMultiTenantFoundation.php`

Migration aggiuntiva per inviti e reset piattaforma:

- `rest/app/Database/Migrations/2026-06-19-000002_CreatePlatformUserAccessTokens.php`

Tabelle create:

- `platform_packages`
- `platform_features`
- `platform_package_features`
- `platform_tenants`
- `platform_tenant_features`
- `platform_tenant_feature_preferences`
- `platform_users`
- `platform_user_tenants`
- `platform_user_access_tokens`

### Concetti chiave

- `platform_users`: account centrali di login basati su email unica
- `platform_tenants`: anagrafica del cliente business, non del paziente
- `platform_user_tenants`: collega un utente piattaforma a uno o piu spazi
- `platform_packages`: definisce il pacchetto commerciale
- `platform_tenant_features`: override puntuali centrali per singolo cliente
- `platform_tenant_feature_preferences`: preferenze operative del tenant master, applicate solo alle funzioni che la piattaforma decide di delegare

## Runtime scelto

Scelta attuale:

- un solo sito
- metadata piattaforma nel DB centrale
- DB separato per ciascun tenant applicativo
- storage separato per tenant

Convenzione proposta:

- upload locale: `upload/tenants/<storage_key>`
- writable locale: `rest/writable/tenants/<storage_key>`

Per il database tenant sono previsti questi campi:

- `db_host`
- `db_port`
- `db_name`
- `db_username`
- `db_password_ref`

`db_password_ref` contiene il nome della env var che custodisce la password reale, per evitare di salvarla in chiaro nel catalogo piattaforma.

## Servizi aggiunti

- `TenantCatalogService`: legge tenant, membership e feature effettive
- `TenantContextService`: salva e legge il tenant attivo dalla sessione
- `TenantFeatureService`: separa feature concesse centralmente da quelle attivate dal tenant master
- `TenantDatabaseConnector`: costruisce la connessione DB tenant
- `TenantProvisioningService`: crea tenant, tenant master e blueprint runtime
- `TenantInfrastructureProvisioningService`: provisiona DB tenant, template, migration e cartelle dal pannello admin
- `TenantAppUserProvisioningService`: crea o collega automaticamente l utente legacy/app del tenant partendo dalla membership piattaforma
- `PlatformAccessService`: gestisce inviti email, reset password e token di primo accesso

## Comando rapido

```bash
php rest/spark tenant:create-space studio-verde "Studio Verde" team info@studioverde.it Mario Rossi --db-host=127.0.0.1 --db-name=amb_studio_verde --db-user=amb_studio_verde --db-password-ref=TENANT_STUDIO_VERDE_DB_PASSWORD --feature-profile=medical --prepare-local-dirs
```

Effetti:

- crea il tenant nel catalogo piattaforma
- crea o riusa il platform user master
- collega il master allo spazio
- restituisce il blueprint runtime
- opzionalmente crea le cartelle locali tenant

## Console master

Route UI:

- `login/piattaforma/spazi-clienti`

Il pannello permette di:

- creare un nuovo spazio cliente
- preparare gli account master configurati in `PLATFORM_MASTER_EMAILS`
- inviare o reinviare il primo accesso agli account master anche senza tenant gia associati
- gestire il catalogo funzioni globale da `login/piattaforma/funzioni`
- assegnare o cambiare il tenant master
- aggiungere utenti allo spazio e collegarli al tenant
- configurare package, stato e onboarding
- salvare host e credenziali logiche del DB tenant
- governare le feature concesse al tenant dalla UI
- rispettare il limite utenti del pacchetto scelto
- preparare le cartelle locali tenant senza passare da console
- lanciare il provisioning tecnico del tenant con il pulsante `Salva e provisiona`
- inviare o reinviare l accesso email a tenant master e membri dello spazio

Accesso:

- la console master resta sotto `/login/...`
- gli account master si distinguono per email tramite `PLATFORM_MASTER_EMAILS`
- il primo accesso dei master puo essere preparato dalla sezione `Account master piattaforma` dentro `login/piattaforma/spazi-clienti`
- il namespace `/admin` resta riservato al gestionale legacy e non e la home del nuovo login unico

Confine operativo:

- il database tenant resta gestito solo dall amministrazione centrale
- il cliente non vede host, credenziali o impostazioni infrastrutturali
- il cliente puo gestire soltanto gli utenti del proprio spazio, se il pacchetto lo consente

## Login unico

Route pubbliche introdotte:

- `login`
- `login/tenant-select`
- `login/recupero`
- `login/password-imposta`

Route autenticata introdotta:

- `login/spazi/cambia/{id}`
- `login/spazio/funzioni`
- `login/spazio/utenti`
- `login/spazio/utenti/accesso`
- `login/spazio/onboarding`

Comportamento attuale:

- se l utente inserisce una email che appartiene a `platform_users` e non collide con un vecchio username legacy, il login passa dal catalogo piattaforma
- se l utente appartiene a un solo tenant disponibile entra direttamente nel suo spazio
- se appartiene a piu tenant, sceglie lo spazio direttamente dalla stessa pagina di login
- se l account ha `must_reset_password = 1`, prima imposta la password e solo dopo entra nello spazio
- dopo il login puo cambiare spazio dal menu utente senza uscire dall applicazione
- dopo la scelta, la richiesta successiva usa il DB applicativo del tenant come connessione `default`
- i moduli principali `agenda`, `posta`, `chat` vengono filtrati in base alle feature effettive del tenant
- le feature effettive nascono da due livelli: concessione centrale e attivazione eventuale del tenant master
- il tenant master vede una pagina di onboarding iniziale finche lo spazio non viene segnato come `ready`

Nota importante:

- per entrare davvero nell app, la membership tenant deve conoscere `app_user_id` oppure il sistema deve riuscire a ricavarlo automaticamente dall email del profilo nel DB tenant

## Provisioning tecnico da pannello

Il provisioning tecnico ora puo essere lanciato dal pannello admin con il bottone `Salva e provisiona`.
Durante il provisioning viene sincronizzato anche l `app_user_id` del tenant master, cosi il login unico puo aprire subito il portale corretto.

Flusso previsto:

1. salva o aggiorna il tenant nel catalogo piattaforma
2. risolve i default DB mancanti
3. crea il database tenant se non esiste
4. assegna i permessi al runtime user, se la `db_password_ref` punta a una env valida
5. se il DB e vuoto, clona un template database oppure importa un template SQL
6. applica le migration applicative filtrando quelle solo piattaforma
7. prepara cartelle `upload` e `writable` del tenant
8. registra l esito in `platform_tenants.metadata_json`

Env previste per il provisioning:

- `TENANT_PROVISIONING_ADMIN_HOST`
- `TENANT_PROVISIONING_ADMIN_PORT`
- `TENANT_PROVISIONING_ADMIN_USERNAME`
- `TENANT_PROVISIONING_ADMIN_PASSWORD`
- `TENANT_PROVISIONING_RUNTIME_HOST`
- `TENANT_PROVISIONING_RUNTIME_PORT`
- `TENANT_PROVISIONING_RUNTIME_USERNAME`
- `TENANT_PROVISIONING_RUNTIME_PASSWORD_REF`
- `TENANT_PROVISIONING_RUNTIME_DRIVER`
- `TENANT_PROVISIONING_TEMPLATE_DATABASE`
- `TENANT_PROVISIONING_TEMPLATE_SQL_PATH`
- opzionale: `TENANT_PROVISIONING_RUNTIME_USER_HOST`

Note operative:

- per il percorso piu semplice si puo usare un runtime user condiviso per tutti i tenant, variando solo `db_name`
- se si vuole un utente DB dedicato per tenant, la `db_password_ref` deve puntare a una env gia valorizzata
- il template database deve essere una base pulita e aggiornata del prodotto, non il DB live del cliente

## Inviti e reset piattaforma

Flussi introdotti:

- invio accesso dalla console master per tenant master e membri
- invio accesso dal pannello `login/spazio/utenti` per il tenant master del cliente
- recupero password dal login unico tramite `login/recupero`
- impostazione password via token oppure dopo login temporaneo

Regola pratica:

- utenti nuovi o con `must_reset_password = 1` ricevono un link di primo accesso
- utenti gia attivi ricevono un link di reset password

## Pacchetti seeded

- `base`
- `team`
- `enterprise`

## Feature seeded

- `agenda`
- `posta`
- `chat`
- `push_notifications`
- `staff_management`
- `multi_location`
- `vertical_overrides`
- `advanced_reporting`
- `custom_branding`

Ogni feature puo avere anche questi attributi operativi:

- `is_tenant_managed`: decide se il tenant master la puo governare
- `tenant_default_enabled`: stato iniziale lato cliente quando la funzione e delegabile
- `sort_order` e `icon_class`: supportano l ordinamento e la UI dei pannelli

## Punti ancora aperti

1. Definire e mantenere un template database pulito dedicato ai nuovi tenant.
2. Decidere se tenere un solo runtime user DB condiviso oppure passare a utenti DB dedicati per tenant.
3. Valutare se portare anche l OTP nel flusso login piattaforma, oltre al bootstrap tenant gia attivo.
4. Quando nasce una nuova feature applicativa, aggiungerla nel catalogo globale e nel registro centralizzato di mapping route/menu, cosi i controlli restano concentrati in un solo punto.
