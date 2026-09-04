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

Il comando seguente processa una voce della coda WhatsApp e riconcilia i fallback SMS scaduti:

```bash
php spark whatsapp-campaigns:run
```

Va eseguito con la schedulazione già prevista, tipicamente ogni minuto. La migration `2026-09-04-020001_CreateTenantNotificationPoliciesAndFallbacks.php` crea schema, contatori e coda fallback.

Ogni esecuzione mantiene aperta una finestra breve per processare più destinatari quando la politica consente più di un messaggio al minuto. I valori configurati sono tetti massimi: il worker distribuisce gli invii uniformemente nell'intervallo e può inviare meno messaggi quando il provider è lento o non disponibile.

## Deliverability email

La limitazione della velocità non sostituisce l'autenticazione del dominio. Prima dell'invio reale verificare SPF, DKIM, DMARC, TLS, bounce e segnalazioni spam del trasporto SMTP in uso. Aumentare i volumi gradualmente e ridurli se la reputazione peggiora.
