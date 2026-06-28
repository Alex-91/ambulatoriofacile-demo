# Reset notturno demo

## Obiettivo

Rigenerare ogni notte il database demo con:

- reset completo delle tabelle demo
- dati finti coerenti
- agenda aggiornata dal giorno corrente in avanti
- finestra rolling di default di 5 giorni lavorativi

Il reset resta limitato al database demo: lo script rifiuta database che non contengono `demo` nel nome.

## Variabili env

Imposta in Coolify:

```dotenv
DEMO_SEED_AGENDA_BUSINESS_DAYS=5
DEMO_AUTO_RESET_ENABLED=1
DEMO_AUTO_RESET_HOUR=2
DEMO_AUTO_RESET_MINUTE=15
DEMO_AUTO_RESET_TZ=Europe/Rome
DEMO_RESET_ACCESS_TOKEN=CHANGE_ME
```

Note:

- `DEMO_RESET_ACCESS_TOKEN` puo restare vuoto se vuoi riusare `CRON_ACCESS_TOKEN`
- `DEMO_SEED_AGENDA_BUSINESS_DAYS` controlla sia il seed a bootstrap sia il reset notturno
- `DEMO_AUTO_RESET_ENABLED=1` attiva il loop interno al container: non serve tenere aperto Coolify e non serve configurare uno scheduler esterno
- `DEMO_AUTO_RESET_HOUR` e `DEMO_AUTO_RESET_MINUTE` definiscono l'orario locale del reset
- `DEMO_AUTO_RESET_TZ` di default usa `Europe/Rome`
- `DEMO_AUTO_RESET_CHECK_INTERVAL` e' opzionale e di default vale `300` secondi

## Comando CLI

Dentro il container:

```bash
php /var/www/html/rest/spark demo:reset-dataset --days=5
```

Con data iniziale forzata:

```bash
php /var/www/html/rest/spark demo:reset-dataset --days=5 --start-date=2026-06-28
```

## URL protetto per scheduler

Endpoint:

```text
https://ambulatoriofacile.it/demo/reset-demo/run?token=IL_TUO_TOKEN
```

Parametri opzionali:

- `days=5`
- `start_date=YYYY-MM-DD`

Esempio completo:

```text
https://ambulatoriofacile.it/demo/reset-demo/run?token=IL_TUO_TOKEN&days=5
```

Risposta attesa:

```json
{
  "ok": true,
  "message": "Reset dataset demo completato."
}
```

## Scheduler consigliati

Soluzione consigliata per questa demo:

1. usare il loop automatico interno al container con `DEMO_AUTO_RESET_ENABLED=1`

Alternative, solo se preferisci un trigger esterno:

1. cron del server che fa una `curl` verso l'endpoint protetto
2. scheduler esterno tipo cron-job.org o EasyCron
3. GitHub Actions schedulata che chiama l'endpoint

## Note operative

- La finestra rolling parte dalla data corrente; se capita nel weekend, gli appuntamenti partono dal primo giorno lavorativo utile.
- Il report JSON del reset viene salvato in `rest/writable/demo_setup/`.
- Il login reale non viene toccato.
