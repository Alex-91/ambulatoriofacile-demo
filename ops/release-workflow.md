# Workflow rilascio Coolify

## Config locale

- file locale: `ops/release-config.local.json`
- resta fuori da Git

## Uso base

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\release-prod.ps1 -Target demo
powershell -ExecutionPolicy Bypass -File .\ops\release-prod.ps1 -Target login
powershell -ExecutionPolicy Bypass -File .\ops\release-prod.ps1 -Target both
```

## Modalita sicura

Per provare il flusso senza chiamate remote:

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\release-prod.ps1 -Target both -DryRun
```

## Cosa controlla

Lo script, salvo override:

1. verifica che il repo sia pulito
2. verifica che il branch corrente sia quello configurato, di default `main`
3. esegue il deploy del target scelto
4. esegue l'health check finale

## Importante prima del deploy

Coolify pubblica dal repository remoto collegato, non dal tuo working tree locale.

Quindi:

1. se il codice da rilasciare e' solo locale, va prima portato su `main`
2. poi `main` va pushato su `origin`
3. solo dopo ha senso lanciare `release-prod.ps1`

## Note operative

- `deployMode: webhook` usa direttamente il `deployWebhookUrl`
- `deployMode: api` usa `coolifyBaseUrl` + `coolifyToken` + `appUuid`
- `-Force` imposta `force=true` solo in modalita `api`
- se il webhook Coolify accetta solo `GET` o solo `POST`, lo script prova prima `POST` e poi fa fallback
