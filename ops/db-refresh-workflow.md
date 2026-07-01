# Refresh notturno DB test

## Scopo

Configura su Coolify una piccola app interna che:

- resta nella rete Docker interna di Coolify
- legge il DB produzione
- riscrive il DB test
- esegue il refresh ogni notte

## Script

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\setup-test-db-refresh.ps1
```

Per forzare il refresh del DB test subito:

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\refresh-test-db-now.ps1
```

Per fare setup o riallineamento e anche una verifica immediata con una task temporanea:

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\setup-test-db-refresh.ps1 -RunOnceCheck
```

Per provare il flusso senza scrivere su Coolify:

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\setup-test-db-refresh.ps1 -DryRun
```

## Config locale

Lo script legge `ops/release-config.local.json`.

Puoi aggiungere una sezione opzionale `dbRefresh`:

```json
{
  "dbRefresh": {
    "projectUuid": "project-uuid",
    "environmentUuid": "environment-uuid",
    "serverUuid": "server-uuid",
    "prodDatabaseUuid": "prod-db-uuid",
    "testDatabaseUuid": "test-db-uuid",
    "appName": "ambulatoriofacile-db-refresh-job",
    "taskName": "nightly-prod-to-test-refresh",
    "syncTimezone": "Europe/Rome",
    "syncHour": 2,
    "syncMinute": 15,
    "pollFrequency": "* * * * *",
    "timeoutSeconds": 7200
  }
}
```

Note:

- `pollFrequency` puo' restare `* * * * *`: la task gira ogni minuto ma esegue il dump solo all'orario locale desiderato.
- usare gli UUID evita ambiguita' se su Coolify esistono piu' database con lo stesso nome.
- i secret del DB non vengono salvati nel repo: lo script li legge dalle API Coolify e li scrive come env della toolbox interna.

## Risultato atteso

Alla fine trovi su Coolify:

- un'app interna `ambulatoriofacile-db-refresh-job`
- una scheduled task `nightly-prod-to-test-refresh`

L'app non espone domini pubblici e serve solo come toolbox per eseguire `mysqldump` e `mysql` dentro la rete interna.
