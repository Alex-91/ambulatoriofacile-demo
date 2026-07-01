# Shortcut chat

Queste frasi possono essere usate direttamente nelle chat future per attivare i flussi operativi gia' presenti nel repository.

## Deploy produzione

Frase rapida:

- `rilascia in prod`

Se non specifichi il target, verra' chiesto solo:

- `demo`
- `login`
- `entrambi`

Comando sottostante:

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\release-prod.ps1 -Target demo
powershell -ExecutionPolicy Bypass -File .\ops\release-prod.ps1 -Target login
powershell -ExecutionPolicy Bypass -File .\ops\release-prod.ps1 -Target both
```

## Refresh DB test

Frasi rapide:

- `configura refresh db test`
- `refresh db test adesso`

Comandi sottostanti:

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\setup-test-db-refresh.ps1
powershell -ExecutionPolicy Bypass -File .\ops\setup-test-db-refresh.ps1 -RunOnceCheck
powershell -ExecutionPolicy Bypass -File .\ops\refresh-test-db-now.ps1
```

## Locale su DB test

Frasi rapide:

- `collega locale al db test`
- `torna al db locale`

Comandi sottostanti:

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\use-coolify-test-db.ps1
powershell -ExecutionPolicy Bypass -File .\ops\use-coolify-test-db.ps1 -DryRun
powershell -ExecutionPolicy Bypass -File .\ops\use-local-db.ps1
```

## Controlli utili

Frasi rapide:

- `stato repo`
- `riepilogo comandi ops`

Cosa fanno:

- `stato repo`: controllo branch corrente, file sporchi e differenze locali
- `riepilogo comandi ops`: riepilogo dei comandi di deploy, refresh DB test e switch locale/test
