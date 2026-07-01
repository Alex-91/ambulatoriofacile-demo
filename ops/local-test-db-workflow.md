# Locale con DB test Coolify

## Scopo

Usare l'app locale con una copia aggiornata del DB produzione, ma senza puntare mai al DB live.

Il flusso previsto e':

- refresh notturno `prod -> test` su Coolify
- switch locale verso il DB `test`
- ritorno rapido al DB locale quando hai finito

## Script

Switch verso il DB test:

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\use-coolify-test-db.ps1
```

Ritorno al DB locale originale:

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\use-local-db.ps1
```

Script principale con scelta esplicita della modalita':

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\switch-local-db-mode.ps1 -Mode test
powershell -ExecutionPolicy Bypass -File .\ops\switch-local-db-mode.ps1 -Mode local
```

Per provare senza scrivere su Coolify o sul `.env` locale:

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\use-coolify-test-db.ps1 -DryRun
```

## Come funziona

Quando passi a `test`, lo script:

- legge `coolifyBaseUrl`, `coolifyToken` e `testDatabaseUuid`
- salva una snapshot del tuo `rest/.env`
- legge via API le credenziali del DB test
- se serve, espone il DB test su una porta pubblica temporanea
- aggiorna il `rest/.env` locale con host, porta e credenziali del DB test
- per default disattiva integrazioni outbound locali come email, push e token reminder

Quando torni a `local`, lo script:

- ripristina il `rest/.env` originale dalla snapshot
- rimuove la snapshot locale

## Config locale

Lo script usa `ops/release-config.local.json`.

La sezione opzionale `localTestDb` e' questa:

```json
{
  "localTestDb": {
    "envPath": "rest/.env",
    "snapshotEnvPath": "ops/.local/rest.env.before-coolify-test",
    "testDatabaseUuid": "uuid-db-test",
    "publicHost": "coolify.example.com",
    "preferredPublicPort": 23306,
    "publicPortTimeout": 3600,
    "autoExposeDatabase": true,
    "disableOutboundIntegrations": true,
    "forceDevelopmentEnvironment": true
  }
}
```

Note pratiche:

- se `testDatabaseUuid` manca, lo script usa `dbRefresh.testDatabaseUuid`
- se `publicHost` manca, lo script usa l'host di `coolifyBaseUrl`
- `autoExposeDatabase=true` permette allo script di aprire il DB test via API solo quando serve
- `publicPortTimeout` definisce per quanto tempo la porta pubblica resta disponibile lato Coolify

## Attenzioni

- questo flusso e' pensato solo per il DB `test`, non per `prod`
- gli upload e `writable/` restano locali
- eventuali dati creati sul DB `test` possono sparire al successivo refresh notturno
- se devi provare invii reali email o SMS, riattivali consapevolmente nel `.env` locale
