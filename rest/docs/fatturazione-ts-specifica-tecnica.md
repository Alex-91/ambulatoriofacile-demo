# Fatturazione TS specifica tecnica

## Obiettivo di questo documento

Tradurre la roadmap TS in una specifica direttamente implementabile nel codebase attuale.

Questo documento definisce:

- dove vive il modulo nel repository
- quali tabelle servono e in quale database
- quali route, controller, model e service introdurre
- come integrare feature flag, menu e permessi gia esistenti
- quale e il primo slice tecnico da sviluppare

## Vincoli del codebase attuale

Il repository oggi ha questi pattern da rispettare:

- i dati commerciali e di entitlement vivono nel DB `platform`
- i dati operativi del cliente vivono nel DB tenant applicativo
- i moduli tenant vengono governati da `platform_features` e `TenantFeatureService`
- l accesso alle route tenant passa gia da `TenantFeatureAccessFilter` usando `TenantFeatureRegistry`
- il menu admin operativo usa `MenuRegistryService`, `TenantAdminMenuService` e `AdminMenuVisibilityService`
- il runtime container usa il `Dockerfile` in root

Nota tecnica importante emersa subito:

- il `Dockerfile` attuale non installa l estensione PHP `soap`
- il modulo TS non puo partire senza `ext-soap`

## Scelta architetturale

### Principio

Separare:

- `configurazione TS del tenant` nel DB `platform`
- `documenti TS e storico operativo` nel DB tenant applicativo

### Motivazione

Questo approccio evita di mischiare:

- credenziali e configurazione commerciale
- documenti fiscali operativi

e resta coerente con l architettura multi tenant gia introdotta.

## Decisione di perimetro aggiornata

Aggiornata al: `2026-07-05`

Per il primo rilascio:

- non viene modellato un soggetto `software house` come identita autonoma del Sistema TS
- il profilo TS configurato nel tenant appartiene alla struttura sanitaria o al professionista cliente
- il prodotto gestisce campi, segreti, validazioni e invio, ma non sostituisce il ruolo formale del soggetto obbligato

Implicazione architetturale:

- `TsProfileService` gestisce credenziali e metadati del soggetto cliente
- un eventuale flusso futuro `delegato / intermediario` restera un estensione separata del modello

### Decisione operativa sul collaudo

Per accelerare l integrazione tecnica:

- il modulo supporta una modalita `TEST ufficiale TS`
- i preset di prova vengono letti da un file locale ignorato da Git: `ops/.local/ts-test-presets.json`
- se il file locale non esiste, il runtime puo usare il fallback persistente `rest/writable/ts/ts-test-presets.json`
- i preset possono precompilare username, password, pincode e dati anagrafici del soggetto di prova
- il passaggio a `PRODUZIONE` restera separato e usera solo le credenziali reali del cliente

### Decisione tecnica sul canale SOAP sincrono

Aggiornata al: `2026-07-05`

Per il primo invio reale abbiamo allineato il modulo al tracciato SOAP ufficiale `Inserimento`:

- operazione: `Inserimento`
- request root: `inserimentoDocumentoSpesaRequest`
- autenticazione: `HTTP Basic` con username e password del profilo TS
- campi cifrati con `SanitelCF.cer`: `pincode`, `cfProprietario`, `cfCittadino`
- parsing risposta su: `esitoChiamata`, `protocollo`, `listaMessaggi`

Nota MVP:

- il form operativo supporta ora la scelta esplicita `F / D`
- nel collaudo reale `TEST` e gia passato lo scenario `F + SR + aliquotaIVA`

### Decisione tecnica su migrazioni e drift ambiente

Aggiornata al: `2026-07-05`

Per evitare che le migration TS si portino dietro migration `App` non correlate:

- il modulo introduce `TsMigrationSafetyService`
- il controllo `ts:doctor` ispeziona separatamente `platform` e `tenant`
- il comando `ts:migrate-safe` copia in una cartella temporanea solo le migration TS attese e applica esclusivamente quelle
- se nello stesso gruppo esistono migration `App` non TS ancora pendenti, il comando blocca l esecuzione salvo override esplicito con `--allow-drift=1`

## Feature flag proposta

Chiave feature proposta:

- `ts_billing`

Scopo:

- abilitare o disabilitare il modulo per singolo tenant
- nascondere le route e il menu se il tenant non lo ha attivo
- permettere in futuro la commercializzazione per pacchetto

Proposta iniziale:

- `feature_scope`: `billing`
- `default_enabled`: `0`
- `is_tenant_managed`: `1`
- `tenant_default_enabled`: `0`
- `icon_class`: `fa-file-text-o`

## Punti di integrazione nel codice

### File da aggiornare

- [rest/app/Config/Routes.php](C:\Users\bassi\Documents\Simo\dottorAppLTE\rest\app\Config\Routes.php)
- [rest/app/Libraries/TenantFeatureRegistry.php](C:\Users\bassi\Documents\Simo\dottorAppLTE\rest\app\Libraries\TenantFeatureRegistry.php)
- [rest/app/Services/MenuRegistryService.php](C:\Users\bassi\Documents\Simo\dottorAppLTE\rest\app\Services\MenuRegistryService.php)
- [rest/app/Services/MenuResolverService.php](C:\Users\bassi\Documents\Simo\dottorAppLTE\rest\app\Services\MenuResolverService.php)
- [rest/app/Services/TenantAdminMenuService.php](C:\Users\bassi\Documents\Simo\dottorAppLTE\rest\app\Services\TenantAdminMenuService.php)
- [Dockerfile](C:\Users\bassi\Documents\Simo\dottorAppLTE\Dockerfile)

### Nuovi file proposti

- `rest/app/Config/TsBilling.php`
- `rest/app/Controllers/Admin/TsDashboardController.php`
- `rest/app/Controllers/Admin/TsDocumentsController.php`
- `rest/app/Controllers/Tenant/TsSettingsController.php`
- `rest/app/Models/TsDocumentModel.php`
- `rest/app/Models/TsDocumentEventModel.php`
- `rest/app/Models/TsDocumentReceiptModel.php`
- `rest/app/Models/PlatformTenantTsProfilesModel.php`
- `rest/app/Services/TsFeatureService.php`
- `rest/app/Services/TsProfileService.php`
- `rest/app/Services/TsSecretsService.php`
- `rest/app/Services/TsStorageService.php`
- `rest/app/Services/TsDocumentService.php`
- `rest/app/Services/TsDocumentValidationService.php`
- `rest/app/Services/TsPayloadBuilderService.php`
- `rest/app/Services/TsSoapClientFactory.php`
- `rest/app/Services/TsCryptoService.php`
- `rest/app/Services/TsDispatchService.php`
- `rest/app/Services/TsReceiptService.php`
- `rest/app/Services/TsAuditService.php`
- `rest/app/Services/TsMigrationSafetyService.php`
- `rest/app/Commands/TsDoctor.php`
- `rest/app/Commands/TsMigrateSafe.php`
- `rest/app/Views/admin/ts/dashboard.php`
- `rest/app/Views/admin/ts/document_form.php`
- `rest/app/Views/tenant/ts_settings.php`

### Asset tecnici locali da versionare

Cartella proposta:

- `rest/app/ThirdParty/TesseraSanitaria/`

Contenuto minimo da mettere in repo:

- `wsdl/`
- `xsd/`
- `certs/SanitelCF.cer`

Regola pratica:

- versionare solo asset tecnici pubblici o necessari al runtime
- non versionare utenze di test, password, pincode o file con segreti

Eccezione operativa locale:

- le utenze di prova ufficiali del kit TS possono essere salvate solo in file locali ignorati da Git, non dentro file versionati del modulo
- in sviluppo il path adottato nel progetto e `ops/.local/ts-test-presets.json`
- in produzione il fallback persistente consigliato e `rest/writable/ts/ts-test-presets.json`

## Database platform

### Tabella proposta: `platform_tenant_ts_profiles`

Scopo:

- contenere la configurazione TS di uno spazio cliente
- supportare gia da subito il caso futuro di piu profili per tenant
- nel MVP il profilo rappresenta direttamente il soggetto cliente che invia a TS

Per il primo MVP un tenant userebbe normalmente un solo profilo `default`.

Campi proposti:

| Campo | Tipo | Note |
| --- | --- | --- |
| `id_ts_profile` | INT PK | chiave primaria |
| `id_tenant` | INT | riferimento a `platform_tenants.id_tenant` |
| `profile_name` | VARCHAR(120) | es. `Studio Rossi` |
| `sender_type` | VARCHAR(40) | es. `medico`, `struttura_autorizzata` |
| `owner_piva` | VARCHAR(16) | P.IVA erogatore |
| `owner_cf_enc` | TEXT | CF proprietario cifrato |
| `owner_cf_hash` | CHAR(64) | hash per confronti esatti |
| `region_code` | VARCHAR(3) NULL | opzionale |
| `asl_code` | VARCHAR(3) NULL | opzionale |
| `ssa_code` | VARCHAR(6) NULL | opzionale |
| `auth_username` | VARCHAR(120) | username TS |
| `auth_password_enc` | TEXT | password TS cifrata |
| `pincode_enc` | TEXT | pincode TS cifrato |
| `environment` | VARCHAR(16) | `test` o `production` |
| `is_default` | TINYINT(1) | un profilo default per tenant |
| `is_enabled` | TINYINT(1) | profilo attivo |
| `last_check_status` | VARCHAR(20) NULL | `ok`, `warning`, `error` |
| `last_check_message` | TEXT NULL | ultimo messaggio healthcheck |
| `last_check_at` | DATETIME NULL | data ultimo controllo |
| `metadata_json` | LONGTEXT NULL | opzioni future |
| `created_by_platform_user` | INT NULL | audit |
| `updated_by_platform_user` | INT NULL | audit |
| `created_at` | DATETIME NULL | audit |
| `updated_at` | DATETIME NULL | audit |

Indici proposti:

- PK su `id_ts_profile`
- indice su `id_tenant`
- indice su `id_tenant, is_enabled`
- indice su `id_tenant, is_default`

Regola applicativa:

- un solo profilo `is_default = 1` per tenant
- se in MVP non serve multi profilo, la UI ne mostra comunque uno solo

## Database tenant applicativo

### Tabella proposta: `ts_documents`

Scopo:

- rappresentare il documento operativo da validare e inviare a TS

Campi proposti:

| Campo | Tipo | Note |
| --- | --- | --- |
| `id_ts_document` | INT PK | chiave primaria |
| `id_ts_profile` | INT NULL | id profilo platform usato al momento dell invio |
| `id_client` | INT NULL | riferimento al paziente locale |
| `source_type` | VARCHAR(30) | `manual`, `appointment`, `import` |
| `source_ref_id` | INT NULL | id record sorgente |
| `document_identifier_hash` | CHAR(64) | hash univoco del documento |
| `sender_piva_snapshot` | VARCHAR(16) | snapshot piva usata |
| `sender_cf_snapshot_enc` | TEXT NULL | snapshot cf usato |
| `sender_type_snapshot` | VARCHAR(40) | snapshot tipo soggetto |
| `patient_cf_enc` | TEXT | CF paziente cifrato |
| `patient_cf_hash` | CHAR(64) | hash CF paziente |
| `patient_label_snapshot_enc` | TEXT NULL | nome paziente cifrato |
| `document_number` | VARCHAR(32) | numero documento |
| `document_device` | INT NULL | dispositivo registratore, se richiesto |
| `issue_date` | DATE | data emissione |
| `payment_date` | DATE | data pagamento |
| `expense_type_code` | VARCHAR(8) | es. `SP` |
| `payment_mode` | VARCHAR(16) | `tracciato` o `contanti` |
| `amount_total` | DECIMAL(10,2) | importo totale |
| `opposition_flag` | TINYINT(1) | opposizione cittadino |
| `notes` | TEXT NULL | note interne |
| `local_state` | VARCHAR(20) | stato interno |
| `ts_state` | VARCHAR(20) NULL | stato ritorno TS |
| `validation_json` | LONGTEXT NULL | esito ultimo controllo locale |
| `request_payload_json` | LONGTEXT NULL | snapshot payload inviato |
| `response_payload_json` | LONGTEXT NULL | snapshot risposta ricevuta |
| `ts_protocol` | VARCHAR(64) NULL | protocollo TS |
| `ts_sent_at` | DATETIME NULL | data invio riuscito |
| `last_error_code` | VARCHAR(50) NULL | ultimo errore |
| `last_error_message` | TEXT NULL | ultima descrizione errore |
| `created_by` | INT NULL | audit |
| `updated_by` | INT NULL | audit |
| `created_at` | DATETIME NULL | audit |
| `updated_at` | DATETIME NULL | audit |

Indici proposti:

- PK su `id_ts_document`
- unique su `document_identifier_hash`
- indice su `local_state`
- indice su `ts_state`
- indice su `issue_date`
- indice su `payment_date`
- indice su `ts_protocol`
- indice su `id_client`

Note implementative:

- `document_identifier_hash` viene costruito da: `sender_piva + issue_date + document_number + document_device`
- serve a prevenire duplicati senza dipendere da nullable complicati

### Tabella proposta: `ts_document_events`

Scopo:

- tracciare la timeline del documento

Campi proposti:

| Campo | Tipo | Note |
| --- | --- | --- |
| `id_ts_event` | INT PK | chiave primaria |
| `id_ts_document` | INT | riferimento documento |
| `event_type` | VARCHAR(40) | es. `draft_created`, `validated_ok`, `send_success` |
| `event_level` | VARCHAR(16) | `info`, `warning`, `error` |
| `event_code` | VARCHAR(50) NULL | codice tecnico o funzionale |
| `message` | TEXT | messaggio leggibile |
| `context_json` | LONGTEXT NULL | dettagli evento |
| `created_by` | INT NULL | audit |
| `created_at` | DATETIME NULL | audit |

Indici proposti:

- PK su `id_ts_event`
- indice su `id_ts_document, created_at`
- indice su `event_type`

### Tabella proposta: `ts_document_receipts`

Scopo:

- collegare al documento ricevute e artefatti generati

Campi proposti:

| Campo | Tipo | Note |
| --- | --- | --- |
| `id_ts_receipt` | INT PK | chiave primaria |
| `id_ts_document` | INT | riferimento documento |
| `receipt_type` | VARCHAR(20) | `pdf`, `csv_error`, `xml_payload` |
| `ts_protocol` | VARCHAR(64) NULL | protocollo associato |
| `storage_path` | VARCHAR(255) | path file relativo |
| `mime_type` | VARCHAR(120) NULL | mime |
| `file_size` | INT NULL | bytes |
| `checksum_sha256` | CHAR(64) NULL | integrita |
| `created_at` | DATETIME NULL | audit |

Indici proposti:

- PK su `id_ts_receipt`
- indice su `id_ts_document`
- indice su `receipt_type`

## Storage file

Percorso proposto:

- `rest/writable/tenants/<storage_key>/ts/`

Sottocartelle:

- `payloads/YYYY/MM/`
- `receipts/YYYY/MM/`
- `errors/YYYY/MM/`
- `debug/YYYY/MM/` solo in ambiente test o locale

Regole:

- nessun artefatto TS dentro `upload/`
- nessun segreto nei path o nei nomi file
- usare file name stabili basati su `id_ts_document` e timestamp

## Config applicativa proposta

Nuovo file:

- `rest/app/Config/TsBilling.php`

Contenuto atteso:

- `FEATURE_KEY = 'ts_billing'`
- elenco stati locali e TS
- elenco tipi spesa supportati nel MVP
- path locali WSDL/XSD/cert
- endpoint `test` e `production`
- timeout SOAP
- contratto SOAP configurabile via env:
  - `TS_BILLING_DOCUMENT_OPERATION`
  - `TS_BILLING_DOCUMENT_REQUEST_ROOT`
  - `TS_BILLING_DOCUMENT_RESPONSE_PROTOCOL_PATH`
  - `TS_BILLING_DOCUMENT_RESPONSE_OUTCOME_PATH`
  - `TS_BILLING_DOCUMENT_RESPONSE_MESSAGE_PATH`
  - `TS_BILLING_DOCUMENT_RESPONSE_OK_VALUES`
  - `TS_BILLING_ASSUME_SUCCESS_ON_SOAP_RETURN`
- limiti upload e politiche log

Nota MVP sui tipi spesa:

- il modulo espone tutti i codici attualmente ammessi dall `XSD` ufficiale `DocumentoSpesa730pSchema.xsd`
- le descrizioni `TK`, `FC`, `FV`, `AS`, `SR`, `SP` sono considerate stabili nel contesto attuale
- i codici `CT`, `PI`, `IC`, `AA`, `AD`, `SV` restano disponibili ma mostrati con descrizione prudente finche non aggiungiamo una fonte business piu esplicita

## Route proposte

### Console tenant per configurazione

| Route | Metodo | Controller | Scopo |
| --- | --- | --- | --- |
| `login/spazio/fatturazione-ts` | GET | `Tenant\TsSettingsController::index` | pagina configurazione TS |
| `login/spazio/fatturazione-ts/save` | POST | `Tenant\TsSettingsController::save` | salva profilo TS |
| `login/spazio/fatturazione-ts/healthcheck` | POST | `Tenant\TsSettingsController::healthcheck` | verifica config |
| `spazio/fatturazione-ts` | GET | alias | coerenza con console spazio |

Nota di esercizio:

- i test end to end verso il Sistema TS richiedono credenziali del soggetto cliente abilitate anche per l ambiente `TEST`
- senza tali credenziali il modulo puo comunque essere collaudato localmente su validazioni, payload, storage, adapter SOAP e diagnostica

### Modulo operativo admin

| Route | Metodo | Controller | Scopo |
| --- | --- | --- | --- |
| `admin/fatturazione-ts` | GET | `Admin\TsDashboardController::index` | dashboard modulo |
| `admin/fatturazione-ts/documenti` | GET | `Admin\TsDocumentsController::index` | lista documenti |
| `admin/fatturazione-ts/documenti/nuovo` | GET | `Admin\TsDocumentsController::create` | form nuovo documento |
| `admin/fatturazione-ts/documenti/modifica/(:num)` | GET | `Admin\TsDocumentsController::edit/$1` | dettaglio/modifica documento |
| `admin/fatturazione-ts/documenti/save` | POST | `Admin\TsDocumentsController::save` | salva bozza o valida localmente in base a `save_mode` |
| `admin/fatturazione-ts/documenti/send` | POST | `Admin\TsDocumentsController::send` | tentativo invio TS con adapter SOAP |

Route ancora previste ma non implementate nello stato attuale:

- `admin/fatturazione-ts/documenti/ricevuta/(:num)`
- `admin/fatturazione-ts/errori`

## Integrazione feature e menu

### TenantFeatureRegistry

Aggiungere definizione:

- `ts_billing`

Proposta:

- `route_prefixes`: `admin/fatturazione-ts`, `spazio/fatturazione-ts`, `login/spazio/fatturazione-ts`
- `menu_prefixes`: `fatturazione-ts`, `spazio/fatturazione-ts`
- `schede_codes`: nessuno nel MVP

Effetto:

- `TenantFeatureAccessFilter` potra gia bloccare le route se la feature non e attiva

### MenuRegistryService

Aggiungere voce admin:

- `key`: `fatturazione-ts`
- `link`: `fatturazione-ts`
- `title`: `Fatturazione TS`
- `icon`: `fa-file-text-o`
- `order`: `1400`

`route_prefixes` da associare:

- `admin/fatturazione-ts`
- `admin/fatturazione-ts/documenti`
- `admin/fatturazione-ts/errori`

### Tenant console

Aggiungere voce contesto spazio:

- `key`: `spazio/fatturazione-ts`
- `link`: `spazio/fatturazione-ts`
- `title`: `Configura Fatturazione TS`
- `icon`: `fa-file-text-o`

Visibilita:

- solo per `tenant_master`
- solo se `ts_billing` e abilitata per il tenant

### MenuResolverService

Aggiornamento richiesto:

- nascondere la voce admin `fatturazione-ts` se il tenant non ha `ts_billing`
- usare lo stesso approccio gia usato per `agenda_visit_types`, ma su una voce reale di menu admin

### Migrazione menu admin

Nuova migration proposta:

- `AddTsBillingAdminMenu.php`

Scopo:

- backfill della voce `fatturazione-ts` dentro `dap06_mnu` per tenant gia esistenti

## Modello permessi MVP

### Configurazione TS

Puo farla:

- `tenant_master`

### Operativita documenti TS

Possono accedere:

- `tenant_master`
- `tenant_admin`
- eventuali utenti legacy admin autorizzati dal menu admin

### Invio a TS

Nel MVP puo inviare:

- `tenant_master`
- `tenant_admin`
- admin operativo del tenant se la pagina e visibile e la feature e attiva

Nota:

- il split piu fine tra `puo preparare` e `puo inviare` si puo introdurre in una fase successiva
- per il MVP sfruttiamo i ruoli gia esistenti e la visibilita menu

## Service layer proposto

### `TsFeatureService`

Responsabilita:

- centralizzare il controllo feature `ts_billing`
- aiutare menu e controller a capire se il modulo e disponibile

### `TsProfileService`

Responsabilita:

- leggere e salvare `platform_tenant_ts_profiles`
- restituire il profilo default attivo del tenant
- nel MVP il profilo rappresenta direttamente la struttura / il professionista cliente
- caricare eventuali preset `TEST` ufficiali da file non versionato, con fallback persistente in `rest/writable/ts/`
- tracciare in `metadata_json` se il profilo usa un preset tecnico di collaudo oppure una configurazione manuale

### `TsSecretsService`

Responsabilita:

- cifrare e decifrare password TS, pincode e CF sensibili
- evitare che la cifratura sia duplicata nei controller

### `TsStorageService`

Responsabilita:

- costruire i path di `writable/tenants/<storage_key>/ts`
- salvare ricevute e payload

### `TsDocumentService`

Responsabilita:

- CRUD documento
- gestione stati
- calcolo `document_identifier_hash`
- scrittura audit base

### `TsDocumentValidationService`

Responsabilita:

- regole locali prima dell invio
- ritorno strutturato di errori e warning

### `TsPayloadBuilderService`

Responsabilita:

- trasformare il documento interno nel payload richiesto dai WSDL TS
- mantenere separato il mapping dominio -> SOAP

### `TsSoapClientFactory`

Responsabilita:

- istanziare client SOAP usando WSDL locali
- impostare endpoint reali `test` o `production`
- configurare timeout, trace e opzioni SSL

### `TsCryptoService`

Responsabilita:

- applicare la cifratura dei campi richiesta dal kit TS
- usare il certificato pubblico locale versionato nel repo

### `TsDispatchService`

Responsabilita:

- orchestrare il flusso `validate -> build payload -> encrypt -> send`
- salvare protocollo, risposta e stato
- distinguere tra successo, scarto funzionale ed errore tecnico del canale TS

### `TsReceiptService`

Responsabilita:

- recuperare PDF ricevuta o eventuali dettagli errori
- salvarli in `ts_document_receipts`

### `TsAuditService`

Responsabilita:

- scrivere eventi in `ts_document_events`
- uniformare i messaggi di timeline

### `TsMigrationSafetyService`

Responsabilita:

- leggere la history migration del gruppo `platform` e del DB tenant
- verificare che tabelle e colonne chiave del modulo TS siano presenti
- segnalare drift dovuto a migration `App` non TS ancora pendenti
- eseguire migration sicure del solo pacchetto TS tramite cartella temporanea filtrata

## Model layer proposto

### `PlatformTenantTsProfilesModel`

- `DBGroup = 'platform'`
- tabella `platform_tenant_ts_profiles`

### `TsDocumentModel`

- tabella `ts_documents`

### `TsDocumentEventModel`

- tabella `ts_document_events`

### `TsDocumentReceiptModel`

- tabella `ts_document_receipts`

## Flusso operativo MVP

### 1. Configurazione tenant

1. il tenant master apre `spazio/fatturazione-ts`
2. inserisce tipo soggetto, P.IVA, credenziali, pincode e ambiente
3. salva
4. esegue `healthcheck`

### 2. Creazione documento

1. l operatore apre `admin/fatturazione-ts/documenti/nuovo`
2. seleziona o conferma paziente
3. compila numero, date, tipo spesa, importo, pagamento, opposizione
4. salva bozza

### 3. Validazione locale

1. il sistema controlla i campi obbligatori
2. salva `validation_json`
3. se ok porta `local_state` a `ready`
4. se no porta `local_state` a `to_validate` con errori

### 4. Invio sincrono

1. recupera profilo TS default del tenant
2. costruisce payload SOAP
3. cifra i campi richiesti
4. invia al servizio `DocumentoSpesa730pPort`
5. salva esito e protocollo
6. aggiorna `local_state` e `ts_state`
7. scrive evento in timeline

### 5. Post invio

1. se esito positivo salva protocollo e consente download ricevuta
2. se warning mostra warning e conserva documento come inviato
3. se errore o scarto salva codice e messaggio e riporta il documento in correzione

## Regole di validazione locale MVP

### Campi obbligatori

- profilo TS attivo presente
- P.IVA erogatore presente
- numero documento presente
- data emissione presente
- data pagamento presente
- codice fiscale paziente presente
- tipo spesa presente
- importo maggiore di zero
- pagamento valorizzato

### Regole formali minime

- CF paziente formalmente valido
- P.IVA formalmente valida
- numero documento entro lunghezza massima compatibile con il tracciato
- date non antecedenti ai minimi del tracciato TS
- `payment_mode` in `tracciato|contanti`
- `expense_type_code` tra quelli supportati nel config

### Controlli di coerenza

- nessun duplicato sullo stesso identificativo documento
- nessun invio se il documento e gia `sent`
- nessun invio se il profilo TS non e attivo

## Stati documento

### Stati locali

- `draft`
- `to_validate`
- `ready`
- `sending`
- `sent`
- `rejected`
- `cancelled`

### Stati TS esposti in UI

- `Bozza`
- `Da validare`
- `Pronto`
- `In invio`
- `Inviato`
- `Scartato`
- `Annullato`

## Sicurezza e privacy

### Dati da cifrare a riposo

- password TS
- pincode TS
- CF proprietario se salvato
- CF paziente snapshot
- eventuale etichetta paziente snapshot

### Dati da non loggare mai

- password TS in chiaro
- pincode TS in chiaro
- payload SOAP completo in chiaro se contiene dati sensibili decifrati

### Policy consigliata

- log applicativi con identificativi interni e protocolli
- payload completo solo in forma strutturata e protetta dove strettamente necessario
- nessuna stampa a video di segreti

## Runtime e dipendenze

### Docker / PHP

Prima di tutto va fatto questo:

- aggiungere `soap` a `docker-php-ext-install` nel [Dockerfile](C:\Users\bassi\Documents\Simo\dottorAppLTE\Dockerfile)

Dipendenze minime richieste:

- `ext-soap`
- `ext-openssl`
- `ext-dom`
- `ext-libxml`

Nota:

- `openssl` e gia presente
- `soap` oggi non lo e

## Strategia di test

### Test locali

- unit test su `TsDocumentValidationService`
- unit test su `TsPayloadBuilderService`
- unit test su `TsCryptoService`

### Test integrazione

- test con `SoapClient` mockato o wrapper astratto
- test di persistenza stati documento
- test route con feature spenta e accesa

### Test ambiente TS

- usare prima ambiente `test`
- usare tenant e dati dedicati
- nessuna prova su produzione live
- collaudo reale gia eseguito il `2026-07-05` con comando `php spark ts:smoke-test --preset=struttura_accreditata_lazio`
- esito verificato: invio accettato dal gateway `TEST`, protocollo `99260705001852582`, `esitoChiamata=0`
- scenario tecnico che oggi passa end-to-end: `tipoDocumento=F`, `tipoSpesa=SR`, `aliquotaIVA=10.00`, pagamento `tracciato`
- follow-up chiuso: `aliquotaIVA` non e piu un fallback hardcoded, ma un dato persistito sul documento insieme a `document_type` e `vat_nature`
- secondo collaudo reale eseguito dopo la persistenza dei nuovi campi: protocollo `99260705001852583`, `esitoChiamata=0`
- terzo collaudo reale completato con recupero ricevuta PDF tramite `RicevutaPdf`: protocollo `99260705001852586`, PDF archiviato nello storage tenant e registrato in `ts_document_receipts`
- il modulo espone ora tutti i codici `tipoSpesa` ammessi dall `XSD` ufficiale; alcune descrizioni restano volutamente conservative finche non aggiungiamo un mapping business piu ricco
- dopo il collaudo e stato aggiunto anche il controllo `php spark ts:doctor` per verificare schema, drift migration e allineamento runtime prima di ogni rilascio o pilot cliente

### Comandi operativi consigliati

- `php spark ts:doctor`
  - fotografia rapida di schema TS, drift migration e tenant runtime
- `php spark ts:migrate-safe --scope=all`
  - applica solo le migration TS attese su `platform` e `tenant`
- `php spark ts:migrate-safe --scope=all --allow-drift=1`
  - stesso flusso, ma senza blocco preventivo in presenza di migration `App` non TS ancora pendenti

## Slices di implementazione consigliati

### Slice 1: fondazione tecnica

- creare `TsBilling.php`
- aggiungere `ext-soap` al `Dockerfile`
- versionare WSDL/XSD/cert pubblici in `ThirdParty/TesseraSanitaria`
- introdurre feature `ts_billing`

### Slice 2: persistence

- creare `platform_tenant_ts_profiles`
- creare `ts_documents`
- creare `ts_document_events`
- creare `ts_document_receipts`
- creare model base

### Slice 3: settings tenant

- `Tenant\TsSettingsController`
- view `tenant/ts_settings`
- salvataggio credenziali e healthcheck base

### Slice 4: UI operativa

- dashboard admin
- lista documenti
- dettaglio documento e salvataggio bozza

### Slice 5: validazione e invio sincrono

- validazione locale
- build payload
- invio SOAP
- persistenza esiti

### Slice 6: ricevute e correzioni

- download PDF
- error list
- timeline documento

## Decisioni gia fissate

- il primo rilascio usa i `WS sincroni`
- i dati documento vivono nel DB tenant
- le credenziali TS vivono nel DB `platform`
- il modulo e governato dalla feature `ts_billing`
- la UI impostazioni vive nella console spazio
- la UI operativa vive nel pannello admin

## Decisioni ancora aperte

- quali pacchetti commerciali abilitano `ts_billing` di default
- set minimo di `tipoSpesa` da supportare in UI oltre a `SR` gia verificato nel collaudo TEST
- se il tenant puo avere un solo profilo TS attivo o piu profili gia dal day one
- se il primo tenant reale richiede da subito annullo o rettifica

## Primo task di implementazione consigliato

Il primo task di codice da aprire dopo questa specifica e:

`slice 1: fondazione tecnica del modulo TS`

Tradotto in file concreti:

1. aggiornare `Dockerfile` con `soap`
2. creare migration `AddTsBillingFeature`
3. creare `Config/TsBilling.php`
4. creare struttura `ThirdParty/TesseraSanitaria/`
5. creare migration `CreatePlatformTenantTsProfiles`
