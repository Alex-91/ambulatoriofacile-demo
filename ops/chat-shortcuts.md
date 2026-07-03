# Shortcut chat

Queste frasi possono essere usate direttamente nelle chat future per attivare i flussi operativi gia' presenti nel repository.

## Deploy produzione

Frase rapida:

- `rilascia in prod`

Se non specifichi il target, verra' chiesto solo:

- `demo`
- `login`
- `entrambi`

Flusso atteso prima del comando:

1. se le modifiche sono ancora nel working tree o in un branch task, fare commit
2. portare il codice da rilasciare su `main`
3. verificare che `main` sia pulito e allineato con `origin/main`
4. fare push di `main`
5. solo dopo lanciare `release-prod.ps1`

Comando sottostante:

```powershell
powershell -ExecutionPolicy Bypass -File .\ops\release-prod.ps1 -Target demo
powershell -ExecutionPolicy Bypass -File .\ops\release-prod.ps1 -Target login
powershell -ExecutionPolicy Bypass -File .\ops\release-prod.ps1 -Target both
```

Nota:

- `release-prod.ps1` blocca il rilascio se il working tree non e' pulito, se il branch corrente non e' `main` o se `main` non e' allineato a `origin/main`

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
