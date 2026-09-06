# Cron promemoria appuntamenti

> Nota: questo documento descrive anche il cron storico. Nel flusso multi-tenant corrente i canali e i parametri di consegna sono gestiti dal master piattaforma in **Piattaforma → Notifiche appuntamenti**. Email, WhatsApp e SMS condividono limiti per spazio; WhatsApp usa il gateway AmbulatorioFacile e, quando entrambi i canali sono selezionati, l'SMS è un fallback e non un secondo invio duplicato.

## Automazione multi-tenant corrente

Il comando corrente è `php spark appointment-reminders:run`. Una volta installato, il task Coolify lo richiama ogni 5 minuti sulla sola applicazione centrale `login`, mentre il comando decide internamente l'orario usando `Europe/Rome`:

- fuori dalla finestra 08:00-08:59 non apre un nuovo batch del giorno;
- dalle 08:00 alle 08:59 ricontrolla ogni 5 minuti gli spazi con reminder attivo, così recupera appuntamenti diventati disponibili dopo il primo passaggio;
- per ogni spazio calcola la data target usando i giorni di anticipo configurati;
- invia gli appuntamenti non annullati usando soltanto i canali autorizzati;
- rispetta ritmo, limite giornaliero e fallback definiti per lo spazio;
- un lock impedisce batch concorrenti;
- lo stato per appuntamento e canale impedisce di ripetere gli invii già riusciti durante i ricontrolli;
- un batch incompleto viene ripreso mantenendo la sua data di riferimento originale.

Installazione o riallineamento del task Coolify, dopo che il codice è stato pubblicato su `main` e rilasciato su `login`:

```powershell
.\ops\setup-appointment-reminder-dispatch.ps1
```

Il resto del documento descrive lo script storico standalone.

Script: `C:\xampp_82\htdocs\dottorAppLTE\rest\cron_send_appointment_reminders.php`

Wrapper Aruba:

- `C:\xampp_82\htdocs\dottorAppLTE\rest\cron_send_appointment_reminders_dry_run.php`
- `C:\xampp_82\htdocs\dottorAppLTE\rest\cron_send_appointment_reminders_live.php`
- `C:\xampp_82\htdocs\dottorAppLTE\rest\cron_web_auth.php`

## Cosa fa

- legge i dottori abilitati da `dap39_sms_dot`
- prende gli appuntamenti di `+6 giorni` dal database `mail`
- usa i dati nuovi di `dap11_agenda_slot`, `dap12_agenda_appuntamenti`, `dap03_personale`, `dap42_ambulatori`
- nel percorso storico invia il promemoria sul canale `wa` tramite UltraMsg; nel flusso multi-tenant corrente usa il gateway AmbulatorioFacile
- se `conferma = 1`, aggiunge il testo con `1` conferma e `2` annulla
- evita doppie esecuzioni concorrenti con un lock file
- evita doppi invii dello stesso appuntamento nello stesso giorno con un file stato in `writable/reminder_state`
- rifiuta ogni esecuzione web non autorizzata
- le chiamate web sono ammesse solo passando un token segreto

## Dove metterlo

Metti il file PHP fuori dalla cartella web pubblica.

Nel tuo progetto locale adesso sta qui:

- `C:\xampp_82\htdocs\dottorAppLTE\rest\cron_send_appointment_reminders.php`

In produzione la regola deve essere:

- cartella esposta al browser: solo `public`
- file cron: nella root del progetto oppure in una sottocartella come `app/cron` o `private/cron`, ma non dentro `public`

Esempio corretto:

- `/home/tuoutente/app/rest/cron_send_appointment_reminders.php`
- webroot del sito: `/home/tuoutente/app/rest/public`

Esempio da evitare:

- `/home/tuoutente/public_html/cron_send_appointment_reminders.php`

## Modalità

- in `development` parte in `dry-run` se non passi opzioni
- in `production` invia davvero anche senza opzioni
- puoi forzare il comportamento con `--dry-run` oppure `--send`
- se il pannello Aruba accetta solo un file PHP, usa i wrapper `*_dry_run.php` e `*_live.php`

## Esempi locali

Dry-run standard:

```bash
php cron_send_appointment_reminders.php --dry-run
```

Con il default attuale questo comando lavora sugli appuntamenti di `oggi + 6 giorni`.

Dry-run su una data specifica:

```bash
php cron_send_appointment_reminders.php --dry-run --date=2026-06-02
```

Invio reale solo per un dottore:

```bash
php cron_send_appointment_reminders.php --send --doctor=67
```

Invio reale con redirect di test verso un numero:

```bash
php cron_send_appointment_reminders.php --send --doctor=67 --force-recipient=3331234567
```

Canale SMS (provider scelto da `SMS_PROVIDER`) invece di WhatsApp:

```bash
php cron_send_appointment_reminders.php --send --channel=sms
```

## Variabili usate da `.env`

- `database.default.hostname`
- `database.default.database`
- `database.default.username`
- `database.default.password`
- `DB_ENCRYPTION_KEY`
- `DB_ENCRYPTION_MODE`
- `SMS_API_TOKEN`
- `SMS_USERNAME`
- `SMS_PASSWORD`
- `SMSFACTOR_API_TOKEN` quando `SMS_PROVIDER=smsfactor`

Variabili opzionali:

- `REMINDER_CHANNEL=wa` oppure `sms`
- `SMS_ULTRAMSG_URL=https://api.ultramsg.com/instance123914/messages/chat`
- `SMS_SENDER=AmbRIMAGGIO`
- `SMS_PROVIDER=smsfactor` (oppure `aruba` per rollback)
- `SMSFACTOR_BASE_URL=https://api.smsfactor.com`
- `SMSFACTOR_TIMEOUT_SECONDS=30`
- `SMSFACTOR_PUSH_TYPE=alert`
- `SMSFACTOR_TENANT_ID=<id>` per associare i DLR del cron legacy allo spazio
- `SMS_BATCH_DELAY_MS=900000`
- `SMS_FORCE_RECIPIENT=3331234567`
- `CRON_ACCESS_TOKEN=metti-qui-un-token-lungo-casuale`

## File generati

- log: `writable/logs/cron_send_appointment_reminders.log`
- stato invii: `writable/reminder_state/appointment_reminders_<channel>_<data>.json`
- lock: `writable/locks/appointment_reminders_<channel>.lock`

## Ritmo invio

- il batch storico usa `SMS_BATCH_DELAY_MS` e, se non configurato diversamente, replica `1` messaggio ogni `15 minuti`
- il flusso multi-tenant corrente usa invece quantità, intervallo e limite giornaliero configurati per ciascuno spazio dal master piattaforma
- il valore `delay_ms` passato manualmente dal pannello è solo un override operativo; a zero viene applicata la politica dello spazio

## Scheduler del provider

Per lo scheduler HTTP o PHP/CLI usa qualcosa di questo tipo:

```bash
php /percorso/del/progetto/cron_send_appointment_reminders.php >> /percorso/del/progetto/writable/logs/cron_send_appointment_reminders.out 2>&1
```

Se vuoi partire in modo prudente il primo giorno:

```bash
php /percorso/del/progetto/cron_send_appointment_reminders.php --dry-run >> /percorso/del/progetto/writable/logs/cron_send_appointment_reminders.out 2>&1
```

Poi, quando il risultato è corretto:

```bash
php /percorso/del/progetto/cron_send_appointment_reminders.php --send >> /percorso/del/progetto/writable/logs/cron_send_appointment_reminders.out 2>&1
```

Se invece nel pannello puoi indicare solo uno script PHP e non puoi aggiungere argomenti, imposta:

- test senza invio: `rest/cron_send_appointment_reminders_dry_run.php`
- invio reale: `rest/cron_send_appointment_reminders_live.php`

Se Aruba esegue lo script come una richiesta web e ricevi `403`, usa il tipo `HTTP/HTTPS` invece di `PHP` e chiama:

- test senza invio: `https://tuodominio/rest/cron_send_appointment_reminders_dry_run.php?token=IL_TUO_TOKEN`
- invio reale: `https://tuodominio/rest/cron_send_appointment_reminders_live.php?token=IL_TUO_TOKEN`

## Note pratiche

- il batch storico usava davvero `UltraMsg` per WhatsApp, non l'API SMS Aruba, anche se nel progetto erano presenti entrambe le strade
- la conversione nuova non tocca `farmacia`: lavora sul database `mail`
- se rilanci il cron lo stesso giorno, gli appuntamenti già marcati nel file stato non vengono reinviati
