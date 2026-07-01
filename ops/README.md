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
