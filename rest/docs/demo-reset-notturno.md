# Reset notturno demo

## Modalità seed precedente

La demo pubblica allineata usa la modalità `aligned_snapshot` descritta sotto. Questa sezione documenta soltanto il seed precedente, destinato alle demo che non hanno una base allineata.

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

- `DEMO_RESET_ACCESS_TOKEN` può restare vuoto se vuoi riusare `CRON_ACCESS_TOKEN`
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

- Nella modalità seed la finestra rolling parte dalla data corrente; se capita nel weekend, gli appuntamenti partono dal primo giorno lavorativo utile.
- Il report JSON del seed viene salvato in `rest/writable/demo_setup/`.
- Il login reale non viene toccato.

## Base allineata al master (demo pubblica)

La demo pubblica usa una base privata acquisita dopo l'allineamento allo spazio di prova. Il reset ripristina l'intero database demo, i ruoli, le preferenze, le associazioni della segreteria e gli allegati della base. Le prove dei visitatori e le loro sessioni vengono eliminate. Il reset non si collega al database di login.

Configurazione del solo ambiente demo:

```dotenv
DEMO_DATASET_RESET_MODE=aligned_snapshot
DEMO_ALIGNED_BASELINE=master-aligned-20261023
DEMO_AUTO_RESET_ENABLED=1
DEMO_AUTO_RESET_HOUR=2
DEMO_AUTO_RESET_MINUTE=15
DEMO_AUTO_RESET_TZ=Europe/Rome
RUN_DEMO_SEED=0
```

La base rimane nei volumi persistenti in `rest/writable/demo-baselines/`, protetta dall'accesso HTTP. Ogni ripristino salva prima una copia di recupero privata con data e ora. La base originale non viene sovrascritta.

Per acquisire una nuova base dopo aver preparato tutti i moduli, usare un nome nuovo:

```bash
php /var/www/html/rest/tools/AlignedDemoSnapshot.php capture --baseline=master-aligned-20261023
php /var/www/html/rest/tools/AlignedDemoSnapshot.php verify --baseline=master-aligned-20261023
```

Impostare poi `DEMO_ALIGNED_BASELINE` sul nome verificato. Per eseguire subito lo stesso reset usato di notte:

```bash
php /var/www/html/rest/spark demo:reset-dataset
```

Gli orari e le date dell'agenda avanzano per settimane intere rispetto alla settimana della base: gli stessi giorni della settimana restano popolati e la finestra futura rimane disponibile. Dati clinici, documenti e date di audit conservano la loro cronologia.

Un controllo fallito della base o dello schema interrompe il reset senza richiamare il vecchio seed. Dopo migration che introducono nuove tabelle preparare e verificare una nuova base. Durante i pochi secondi del ripristino la demo risponde con un avviso temporaneo; in caso di errore la transazione viene annullata e gli allegati precedenti vengono ripristinati. Il comando restituisce un errore, così il loop ritenta senza segnare la giornata come completata.
