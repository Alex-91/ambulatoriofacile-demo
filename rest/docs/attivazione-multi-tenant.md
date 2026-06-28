# Attivazione Multi Tenant

Questa checklist serve per passare dalla base codice al primo uso reale del login unico e degli spazi cliente dalla console master sotto `/login`.

## Architettura consigliata produzione

- `https://ambulatoriofacile.it/` = sito vetrina
- `https://ambulatoriofacile.it/demo` = demo commerciale
- `https://ambulatoriofacile.it/login` = ingresso pubblico login unico
- `https://ambulatoriofacile.it/app/...` = area privata reale

Env minime consigliate sulla app reale:

- `BOOTSTRAP_DEMO_DB=0`
- `APP_BASE_URL=https://ambulatoriofacile.it/app/`
- `APP_CANONICAL_URL=https://ambulatoriofacile.it/app/`
- `APP_PUBLIC_ACCESS_BASE_URL=https://ambulatoriofacile.it/`

## 1. Database centrale piattaforma

Scegli una delle due modalita:

- piu semplice: usa lo stesso DB gia configurato in `database.default.*`
- piu pulita: usa un DB centrale separato impostando `PLATFORM_DB_*`

Nel DB centrale verranno create e usate queste tabelle:

- `platform_packages`
- `platform_features`
- `platform_package_features`
- `platform_tenants`
- `platform_tenant_features`
- `platform_tenant_feature_preferences`
- `platform_users`
- `platform_user_tenants`
- `platform_user_access_tokens`

## 2. Migration da eseguire

Prima di creare i tenant da pannello devono essere presenti le migration piattaforma:

- `2026-06-19-000001_CreatePlatformMultiTenantFoundation.php`
- `2026-06-19-000002_CreatePlatformUserAccessTokens.php`
- `2026-06-20-000002_AddPlatformAdminFlagToPlatformUsers.php`

In ambiente deploy normale basta mantenere `RUN_MIGRATIONS=1`.

## 3. Email obbligatoria

Per inviti e reset password il canale email deve essere gia funzionante.

Variabili minime:

- `EMAIL_FROM_ADDRESS`
- `EMAIL_FROM_NAME`
- `EMAIL_PROTOCOL`
- `EMAIL_SMTP_HOST`
- `EMAIL_SMTP_USER`
- `EMAIL_SMTP_PASS`
- `EMAIL_SMTP_PORT`
- `EMAIL_SMTP_CRYPTO`

## 4. Account master piattaforma

Per la gestione ordinaria puoi creare e governare gli account master direttamente dal pannello `login/piattaforma/spazi-clienti`.

`PLATFORM_MASTER_EMAILS` diventa opzionale e serve solo come seed/bootstrap tecnico iniziale se vuoi importare automaticamente una o piu email master da Coolify.

Esempio facoltativo:

- `PLATFORM_MASTER_EMAILS=tuamail@dominio.it,amico@dominio.it`

Il pulsante `Salva e provisiona` del pannello admin usa queste env:

- `TENANT_PROVISIONING_ADMIN_HOST`
- `TENANT_PROVISIONING_ADMIN_PORT`
- `TENANT_PROVISIONING_ADMIN_USERNAME`
- `TENANT_PROVISIONING_ADMIN_PASSWORD`
- `TENANT_PROVISIONING_RUNTIME_HOST`
- `TENANT_PROVISIONING_RUNTIME_PORT`
- `TENANT_PROVISIONING_RUNTIME_USERNAME`
- `TENANT_PROVISIONING_RUNTIME_DRIVER`
- `TENANT_PROVISIONING_RUNTIME_PASSWORD_REF`
- `TENANT_PROVISIONING_RUNTIME_USER_HOST`

Nota pratica:

- `TENANT_PROVISIONING_RUNTIME_PASSWORD_REF` deve contenere il nome di una env reale che custodisce la password DB
- esempio: `TENANT_PROVISIONING_RUNTIME_PASSWORD_REF=TENANT_SHARED_RUNTIME_PASSWORD`
- e quindi deve esistere anche `TENANT_SHARED_RUNTIME_PASSWORD=...`

## 5. Template per nuovi clienti

Il provisioning tecnico richiede una base applicativa da copiare nel DB del nuovo cliente.

Scegli una sola strategia:

- `TENANT_PROVISIONING_TEMPLATE_DATABASE`: nome di un DB template pulito da clonare
- `TENANT_PROVISIONING_TEMPLATE_SQL_PATH`: path di un file SQL pulito da importare

Importante:

- non usare il DB live di un cliente come template
- non usare il DB demo se contiene dati dimostrativi che non vuoi ritrovare negli spazi reali
- prepara un template aggiornato e neutro del prodotto

## 6. Primo test completo

Ordine consigliato:

1. apri `https://ambulatoriofacile.it/login/piattaforma/spazi-clienti`
2. nella sezione `Account master piattaforma`, crea dal pannello almeno un master persistente oppure importa quelli seed da `PLATFORM_MASTER_EMAILS`
3. crea dal pannello admin un nuovo spazio cliente
4. se serve, definisci o aggiorna la funzione globale da `login/piattaforma/funzioni`
5. spunta `Invia accesso al tenant master dopo il salvataggio`
6. usa `Salva e provisiona`
7. verifica che nel pannello compaia il riepilogo dell ultimo provisioning
8. il provisioning crea o collega automaticamente anche l `app_user_id` del tenant master nel DB del tenant
9. apri il link email del tenant master
10. imposta la password
11. entra da `ambulatoriofacile.it/login`
12. verifica che il tenant master veda solo il suo spazio
13. verifica che l account master centrale apra `ambulatoriofacile.it/login/piattaforma/spazi-clienti`
14. se la funzione e delegabile, verifica che il tenant master la possa governare da `login/spazio/funzioni`
15. aggiungi un utente da `login/spazio/utenti`
16. invia accesso anche a lui e verifica il flusso

## 7. Regole operative

- il DB lo gestite solo voi admin centrali
- i clienti non devono vedere host, credenziali o env
- i clienti possono solo gestire utenti del proprio spazio se il pacchetto lo consente
- eventuali verticalizzazioni devono essere abilitate con feature flag tenant, non con controlli sparsi su username
- se una funzione deve essere governabile dal cliente, la decisione nasce nel catalogo globale e non in if sparsi nel codice

## 8. Cosa controllare se qualcosa non va

- email non arriva: controlla config SMTP e log mail
- `Salva e provisiona` fallisce: controlla `TENANT_PROVISIONING_*` e i permessi MySQL dell utente admin
- utente entra ma non apre il tenant: probabilmente manca `app_user_id` e non c e match automatico via email nel DB tenant
- se salvi utenti prima che il DB tenant sia pronto, il pannello mostra un warning e la sincronizzazione dell `app_user_id` viene rimandata
- lo spazio non compare nel login: controlla `is_active`, `status` tenant e membership del platform user
