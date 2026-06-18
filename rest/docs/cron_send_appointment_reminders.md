# Cron promemoria appuntamenti

Script: `C:\xampp_82\htdocs\dottorAppLTE\rest\cron_send_appointment_reminders.php`

Wrapper Aruba:

- `C:\xampp_82\htdocs\dottorAppLTE\rest\cron_send_appointment_reminders_dry_run.php`
- `C:\xampp_82\htdocs\dottorAppLTE\rest\cron_send_appointment_reminders_live.php`
- `C:\xampp_82\htdocs\dottorAppLTE\rest\cron_web_auth.php`

## Cosa fa

- legge i dottori abilitati da `dap39_sms_dot`
- prende gli appuntamenti di `+6 giorni` dal database `mail`
- usa i dati nuovi di `dap11_agenda_slot`, `dap12_agenda_appuntamenti`, `dap03_personale`, `dap42_ambulatori`
- invia il promemoria sul canale storico `wa` tramite UltraMsg
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

## Modalita

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

Canale SMS Aruba invece di WhatsApp:

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

Variabili opzionali:

- `REMINDER_CHANNEL=wa` oppure `sms`
- `SMS_ULTRAMSG_URL=https://api.ultramsg.com/instance123914/messages/chat`
- `SMS_SENDER=AmbRIMAGGIO`
- `SMS_BATCH_DELAY_MS=900000`
- `SMS_FORCE_RECIPIENT=3331234567`
- `CRON_ACCESS_TOKEN=metti-qui-un-token-lungo-casuale`

## File generati

- log: `writable/logs/cron_send_appointment_reminders.log`
- stato invii: `writable/reminder_state/appointment_reminders_<channel>_<data>.json`
- lock: `writable/locks/appointment_reminders_<channel>.lock`

## Ritmo invio

- il comportamento predefinito replica il batch storico: `1` messaggio ogni `15 minuti`
- il default quindi e `SMS_BATCH_DELAY_MS=900000`
- il cron giornaliero va lanciato una sola volta: poi lo script resta in esecuzione e scorre i messaggi con quella pausa
- se vuoi cambiare il ritmo, puoi impostare `SMS_BATCH_DELAY_MS` nel `.env`

## Cron Aruba

Se Aruba ti consente un comando PHP/CLI, usa qualcosa di questo tipo:

```bash
php /percorso/del/progetto/cron_send_appointment_reminders.php >> /percorso/del/progetto/writable/logs/cron_send_appointment_reminders.out 2>&1
```

Se vuoi partire in modo prudente il primo giorno:

```bash
php /percorso/del/progetto/cron_send_appointment_reminders.php --dry-run >> /percorso/del/progetto/writable/logs/cron_send_appointment_reminders.out 2>&1
```

Poi, quando il risultato e corretto:

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
- se rilanci il cron lo stesso giorno, gli appuntamenti gia marcati nel file stato non vengono reinviati
