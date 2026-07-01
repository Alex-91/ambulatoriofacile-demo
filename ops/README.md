# Release config locale

1. Copia `ops/release-config.local.example.json` in `ops/release-config.local.json`
2. Incolla dentro:
   - `coolifyBaseUrl`
   - `coolifyToken`
   - `deployWebhookUrl` per `demo`
   - `deployWebhookUrl` per `login`
   - URL pubblici e URL healthcheck
3. Il file `ops/release-config.local.json` e' ignorato da Git e resta solo locale.

Se per un target preferisci API invece del webhook, lascia:

- `deployMode`: `api`
- `appUuid`: UUID dell'app Coolify

e puoi lasciare vuoto `deployWebhookUrl`.

Per il rilascio guidato vedi anche `ops/release-workflow.md`.

## Refresh DB test

Per configurare il refresh notturno del DB test da produzione su Coolify:

- script: `ops/setup-test-db-refresh.ps1`
- comando rapido refresh immediato: `ops/refresh-test-db-now.ps1`
- guida: `ops/db-refresh-workflow.md`

## Collegare il locale al DB test

Per usare il locale contro il DB test su Coolify:

- switch principale: `ops/switch-local-db-mode.ps1`
- comando rapido verso DB test: `ops/use-coolify-test-db.ps1`
- comando rapido ritorno a DB locale: `ops/use-local-db.ps1`
- guida: `ops/local-test-db-workflow.md`

## Shortcut chat

Per le frasi rapide riutilizzabili anche nelle nuove chat:

- guida: `ops/chat-shortcuts.md`
