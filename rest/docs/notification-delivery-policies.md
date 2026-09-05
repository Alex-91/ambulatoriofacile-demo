# Politiche di consegna notifiche per spazio

## Dove si configurano

Il master piattaforma apre **Piattaforma → Notifiche appuntamenti → Parametri di consegna per spazio**. La configurazione è salvata per `id_tenant` in `platform_tenant_notification_policies`.

Il pannello dello spazio continua a scegliere quali flussi attivare e quali canali usare. La piattaforma stabilisce invece identità di invio, ritmo, tetti giornalieri e fallback.

## Email

- il mittente deve appartenere esattamente a `ambulatoriofacile.it`;
- il `Reply-To` è sempre `noreply@ambulatoriofacile.it` e non è modificabile dal tenant;
- il corpo indica che il messaggio è automatico e non richiede risposta;
- prefisso oggetto, nome visualizzato, ritmo e limite giornaliero sono configurabili per spazio;
- il trasporto SMTP e le relative credenziali restano centrali nell'ambiente e non vengono salvati per tenant.

Default prudente: `10` email ogni `5` minuti, distribuite uniformemente, massimo `500` al giorno.

Il limite è per spazio, mentre la reputazione è condivisa dal dominio `ambulatoriofacile.it`: il master deve sorvegliare anche il volume aggregato e aumentare i tetti gradualmente.

## WhatsApp e fallback SMS

Quando la piattaforma abilita WhatsApp per uno spazio, il canale viene instradato automaticamente al gateway AmbulatorioFacile. Per i flussi che hanno sia WhatsApp sia SMS:

1. parte WhatsApp;
2. se il provider rifiuta subito l'invio, il fallback SMS è subito eleggibile;
3. se l'invio è accettato, il worker attende il termine configurato;
4. se il gateway riporta `delivered` o `read`, l'SMS non parte;
5. altrimenti l'SMS viene accodato rispettando ritmo e tetto giornaliero dello spazio.

Default prudente WhatsApp: `1` messaggio ogni `5` minuti, massimo `250` al giorno. Default fallback: `30` minuti. Default SMS: `10` ogni `5` minuti, massimo `500` al giorno.

Lo stesso meccanismo governa i reminder automatici e le campagne WhatsApp massive. La tabella `platform_notification_rate_limits` condivide i contatori tra i diversi produttori di messaggi, mentre `platform_notification_fallbacks` rende il fallback idempotente.

## Worker

### Reminder appuntamenti

Il comando automatico dei reminder è:

```bash
php spark appointment-reminders:run
```

Una volta installato in Coolify viene richiamato ogni 5 minuti, ma il comando usa sempre il fuso `Europe/Rome` e apre un nuovo batch giornaliero soltanto nella finestra **08:00-08:59**. Un lock impedisce esecuzioni concorrenti e uno stato giornaliero impedisce di ripetere un batch già concluso. Se un'esecuzione viene interrotta o resta incompleta, il giro successivo riprende la data di riferimento più vecchia non conclusa anche fuori da quella finestra; gli appuntamenti già inviati restano esclusi grazie allo stato per appuntamento, data e canale.

Per ogni spazio il dispatcher:

1. verifica che modulo e reminder siano attivi;
2. legge i giorni di anticipo scelti dallo spazio;
3. seleziona gli appuntamenti non annullati della data risultante;
4. usa soltanto i canali autorizzati e configurati;
5. applica ritmo, limite giornaliero e fallback dello spazio.

Il task va installato soltanto sull'applicazione centrale `login`, per evitare che due applicazioni elaborino gli stessi spazi:

```powershell
.\ops\setup-appointment-reminder-dispatch.ps1
```

Per controllare subito il piano senza inviare:

```bash
php spark appointment-reminders:run --dry-run
```

### Campagne WhatsApp e fallback

Il comando seguente processa una voce della coda WhatsApp e riconcilia i fallback SMS scaduti:

```bash
php spark whatsapp-campaigns:run
```

Va eseguito con la schedulazione già prevista, tipicamente ogni minuto. La migration `2026-09-04-020001_CreateTenantNotificationPoliciesAndFallbacks.php` crea schema, contatori e coda fallback.

Ogni esecuzione mantiene aperta una finestra breve per processare più destinatari quando la politica consente più di un messaggio al minuto. I valori configurati sono tetti massimi: il worker distribuisce gli invii uniformemente nell'intervallo e può inviare meno messaggi quando il provider è lento o non disponibile.

## Deliverability email

La limitazione della velocità non sostituisce l'autenticazione del dominio. Prima dell'invio reale verificare SPF, DKIM, DMARC, TLS, bounce e segnalazioni spam del trasporto SMTP in uso. Aumentare i volumi gradualmente e ridurli se la reputazione peggiora.
