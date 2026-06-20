# Attivazione Multi Tenant

Questa checklist serve per passare dalla base codice al primo uso reale del login unico e degli spazi cliente da pannello admin.

## Architettura consigliata produzione

- `https://ambulatoriofacile.it/` = sito vetrina
- `https://ambulatoriofacile.it/demo` = demo commerciale
- `https://ambulatoriofacile.it/login` = ingresso pubblico login unico
- `https://ambulatoriofacile.it/app/...` = area privata reale

Env minime consigliate sulla app reale:

- `BOOTSTRAP_DEMO_DB=0`
- `app.baseURL=https://ambulatoriofacile.it/app/`
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
- `platform_users`
- `platform_user_tenants`
- `platform_user_access_tokens`

## 2. Migration da eseguire

Prima di creare i tenant da pannello devono essere presenti le migration piattaforma:

- `2026-06-19-000001_CreatePlatformMultiTenantFoundation.php`
- `2026-06-19-000002_CreatePlatformUserAccessTokens.php`

In ambiente deploy normale basta mantenere `RUN_MIGRATIONS=1`.

## 3. Email obbligatoria

Per inviti e reset password il canale email deve essere gia funzionante.

Variabili minime:

- `email.fromEmail`
- `email.fromName`
- `email.protocol`
- `email.SMTPHost`
- `email.SMTPUser`
- `email.SMTPPass`
- `email.SMTPPort`
- `email.SMTPCrypto`

## 4. Provisioning tenant dal pannello

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

1. crea dal pannello admin un nuovo spazio cliente
2. spunta `Invia accesso al tenant master dopo il salvataggio`
3. usa `Salva e provisiona`
4. verifica che nel pannello compaia il riepilogo dell ultimo provisioning
5. il provisioning crea o collega automaticamente anche l `app_user_id` del tenant master nel DB del tenant
5. apri il link email del tenant master
6. imposta la password
7. entra da `ambulatoriofacile.it/login`
8. verifica che il tenant master veda solo il suo spazio
9. aggiungi un utente da `spazio/utenti`
10. invia accesso anche a lui e verifica il flusso

## 7. Regole operative

- il DB lo gestite solo voi admin centrali
- i clienti non devono vedere host, credenziali o env
- i clienti possono solo gestire utenti del proprio spazio se il pacchetto lo consente
- eventuali verticalizzazioni devono essere abilitate con feature flag tenant, non con controlli sparsi su username

## 8. Cosa controllare se qualcosa non va

- email non arriva: controlla config SMTP e log mail
- `Salva e provisiona` fallisce: controlla `TENANT_PROVISIONING_*` e i permessi MySQL dell utente admin
- utente entra ma non apre il tenant: probabilmente manca `app_user_id` e non c e match automatico via email nel DB tenant
- se salvi utenti prima che il DB tenant sia pronto, il pannello mostra un warning e la sincronizzazione dell `app_user_id` viene rimandata
- lo spazio non compare nel login: controlla `is_active`, `status` tenant e membership del platform user
