# Workflow rilascio Coolify

## Config locale

- file locale: `ops/release-config.local.json`
- resta fuori da Git

## Uso base

Prerequisito: il codice da rilasciare deve essere già stato portato su `main` e pushato su `origin/main`.

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\release-prod.ps1 -Target demo
powershell -ExecutionPolicy Bypass -File .\ops\release-prod.ps1 -Target login
powershell -ExecutionPolicy Bypass -File .\ops\release-prod.ps1 -Target both
```

## Modalità sicura

Per provare il flusso senza chiamate remote:

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\release-prod.ps1 -Target both -DryRun
```

## Cosa controlla

Lo script, salvo override:

1. verifica che il repo sia pulito
2. verifica che il branch corrente sia quello configurato, di default `main`
3. verifica che `main` sia allineato con `origin/main`
4. esegue il deploy del target scelto
5. esegue l'health check finale seguendo anche eventuali redirect

## Preparazione del codice da rilasciare

Se stai lavorando su un branch task, il flusso corretto e':

1. commit delle modifiche del task
2. allineamento del contenuto su `main` con merge o fast-forward
3. verifica che `main` sia pulito
4. push di `main` su `origin`
5. lancio di `release-prod.ps1`

## Importante prima del deploy

Coolify pubblica dal repository remoto collegato, non dal tuo working tree locale.

Quindi:

1. se il codice da rilasciare e' solo locale, va prima portato su `main`
2. poi `main` va pushato su `origin`
3. lo script blocca il rilascio se `main` e `origin/main` non coincidono
4. solo dopo ha senso lanciare `release-prod.ps1`

## Note operative

- `deployMode: webhook` usa direttamente il `deployWebhookUrl`
- `deployMode: api` usa `coolifyBaseUrl` + `coolifyToken` + `appUuid`
- `-Force` imposta `force=true` solo in modalità `api`
- se il webhook Coolify accetta solo `GET` o solo `POST`, lo script prova prima `POST` e poi fa fallback

## Task reminder delle 08:00

Dopo il primo rilascio del comando `appointment-reminders:run` su `login`, installa o riallinea una volta il task Coolify:

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\setup-appointment-reminder-dispatch.ps1
```

Il task viene creato soltanto sull'applicazione centrale `login`. È richiamato ogni 5 minuti per poter riprendere automaticamente un'esecuzione interrotta, ma il comando apre il nuovo batch giornaliero una sola volta nella finestra 08:00-08:59 `Europe/Rome`.
