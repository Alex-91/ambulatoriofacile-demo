# Prenotazione online interna

Implementazione sul branch `codex/complete-patient-booking`. Le tavole A01–A05, i font, le icone e i template email provengono dall'archivio `prenotazione-online-design-completo.zip`; i sorgenti originali restano immutati. `original-admin.js` collega i controlli ai dati dello spazio. Il prototipo paziente viene adattato tramite `node ops/build-patient-booking.cjs`, mantenendo il CSS originale.

## Accesso e ambiente

Solo spazio autenticato, abilitato dal super amministratore. Il bottone dell'amministrazione apre il percorso paziente. Nessuna route pubblica, verifica identità, OTP o prenotazione WhatsApp. Recapiti manuali, badge Non verificato, nessun collegamento automatico all'anagrafica. I link e QR puntano allo stesso ingresso interno e richiedono l'accesso allo spazio.

La preview `node ops/preview-patient-booking.cjs` usa dati sintetici in memoria e non il database applicativo. Le email di prova della preview sono simulate. I test MySQL avviano un database temporaneo su loopback 13371 e lo eliminano a fine prova.

## Installazione al rilascio

Non eseguire migrazioni via richieste HTTP. Usare `php spark prenota:install --platform`, poi `php spark prenota:install --tenant ID` per ciascuno spazio previsto. Lo script è idempotente e aggiunge le tabelle dei metadati cifrati, richieste di contatto, inviti, limiti, assenze e coda email; estende lo stato nativo dell'agenda con IN_ATTESA. Non abilita automaticamente gli spazi. Il database della demo congelata viene escluso.

Le email relative alle operazioni sono accodate nella stessa transazione della prenotazione e inviate dopo il commit. Nessun invio reale nei test. Scadenze, riallineamento con l'agenda e promemoria richiedono il comando periodico:

```
php spark prenota:scadenze --tenant ID --patient-url https://HOST/login/spazio/prenotazione-online/paziente
```

`ops/setup-patient-booking-maintenance.ps1` prepara la task Coolify ogni cinque minuti per target e spazio espliciti; supporta `-DryRun`. Schema e task non sono stati installati in produzione durante questo intervento. Gli invii falliti rimangono registrati senza un reinvio automatico ambiguo. Il trasporto email è quello già configurato per lo spazio.

## Verifica

- `php -d xdebug.mode=off rest/tests/patient_booking_workflow.php`
- `php -d xdebug.mode=off rest/tests/patient_booking_editor.php`
- `php -d xdebug.mode=off rest/tests/patient_booking_http.php`
- `php -d xdebug.mode=off rest/tests/patient_booking_assets.php`
- `./ops/test-patient-booking-mysql.ps1`
- `node rest/tests/patient_booking_browser.cjs`
- `node rest/tests/patient_booking_original_browser.cjs http://127.0.0.1:PORTA` su preview appena avviata
- `node rest/tests/patient_booking_admin_actions.cjs http://127.0.0.1:PORTA`

I test browser richiedono Playwright in NODE_PATH e Chrome in BROWSER_EXE. Coprono larghezze 390/768/1280, bozza e pubblicazione, ripristino, approvazione, contatti, anteprima email e QR PNG. I test MySQL coprono occupazione atomica, concorrenti, retry, spostamento, annullamento, scadenza, limiti, blocchi, inviti monouso, assenze, collegamento esplicito all'anagrafica e outbox con trasporto finto.

Restano deliberatamente fuori da questo ingresso interno la verifica e i relativi P7/P8, l'accesso pubblico, la PWA pubblica e le funzioni indicate nella guida come Fase 2. Il rendering runtime cambia dati dimostrativi e contenuti condizionali: la conservazione delle tavole non equivale a dichiarare identità pixel per pixel di ogni stato dinamico.
