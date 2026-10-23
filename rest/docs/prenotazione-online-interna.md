# Prenotazione online interna

Implementazione sul branch `codex/complete-patient-booking`. Le tavole A01–A05, i font, le icone e i template email provengono dall'archivio `prenotazione-online-design-completo.zip`; i sorgenti originali restano immutati. `original-admin.js` collega i controlli ai dati dello spazio. Il prototipo paziente viene adattato tramite `node ops/build-patient-booking.cjs`, mantenendo il CSS originale.

## Accesso e ambiente

Solo spazio autenticato, abilitato dal super amministratore. Il bottone dell'amministrazione apre il percorso paziente. Nessuna route pubblica, verifica identità, OTP o prenotazione WhatsApp. Recapiti manuali, badge Non verificato, nessun collegamento automatico all'anagrafica. I link e QR puntano allo stesso ingresso interno e richiedono l'accesso allo spazio.

Il super amministratore gestisce esclusivamente l'abilitazione del modulo per spazio: non imposta catalogo, professionisti, sedi, recapiti, privacy o workflow. L'attivazione è consentita anche prima che il cliente configuri questi dati. I tenant master/admin dello spazio gestiscono tutte le scelte dalle tavole A01–A05, inclusi «Prestazioni e professionisti» e «Modifica i contatti». Le bozze possono essere incomplete; la pubblicazione e l'accesso agli orari prenotabili richiedono catalogo e privacy validi. Disattivazione e riattivazione da piattaforma conservano configurazione e bozze del cliente. Le scritture dello spazio non possono cambiare l'abilitazione e rispettano la revisione concorrente.

La preview `node ops/preview-patient-booking.cjs` usa dati sintetici in memoria e non il database applicativo. Le email di prova della preview sono simulate. I test MySQL avviano un database temporaneo su loopback 13371 e lo eliminano a fine prova.

## Installazione al rilascio

Non eseguire migrazioni via richieste HTTP. Usare `php spark prenota:install --platform`, poi `php spark prenota:install --tenant ID` per ciascuno spazio previsto. Lo script è idempotente e aggiunge le tabelle dei metadati cifrati, richieste di contatto, inviti, limiti, assenze e coda email; estende lo stato nativo dell'agenda con IN_ATTESA. Non abilita automaticamente gli spazi. Il database della demo congelata viene escluso.

Le email relative alle operazioni sono accodate nella stessa transazione della prenotazione e inviate dopo il commit. Nessun invio reale nei test. Scadenze, riallineamento con l'agenda e promemoria richiedono il comando periodico:

```
php spark prenota:scadenze --tenant ID --patient-url https://HOST/login/spazio/prenotazione-online/paziente
```

`ops/setup-patient-booking-maintenance.ps1` prepara la task Coolify ogni cinque minuti; supporta `-DryRun`, `-TenantId ID` oppure `-AllEnabled`. La modalità `-AllEnabled` crea una sola task per ambiente e rilegge gli spazi attivi abilitati a ogni esecuzione, includendo quelli creati in futuro. Gli invii falliti rimangono registrati senza un reinvio automatico ambiguo. Il trasporto email è quello già configurato per lo spazio.

La migration `2026-10-23-100001_CreatePatientBookingSchema.php` è inclusa nel normale provisioning dei nuovi spazi e prepara le tabelle senza abilitarli. Quando un nuovo DB viene creato copiando un template, i dati delle prenotazioni e le eventuali abilitazioni copiate vengono rimossi dalla procedura di inizializzazione. Il rollback della migration conserva i dati. La demo congelata locale resta esclusa; la demo Coolify online usa un database omonimo su un server diverso.

Per predisporre gli spazi esistenti senza abilitarli, eseguire nel relativo container `php ops/patient-booking-provision.php prepare-all demo` oppure `prepare-all login`. `audit-all` verifica in sola lettura tutti gli spazi. Il comando controlla l'URL dell'ambiente, non stampa dati dei pazienti e restituisce un codice non zero se una verifica fallisce. Non modifica cataloghi, workflow o abilitazioni.

Esempio per il job unico di login:

```powershell
./ops/setup-patient-booking-maintenance.ps1 -Target login -AllEnabled -PatientUrl https://ambulatoriofacile.it/login/spazio/prenotazione-online/paziente
```

Per demo usare il target `demo` e l'URL `https://ambulatoriofacile.it/demo/login/spazio/prenotazione-online/paziente`. I job non installano schemi e non elaborano spazi disabilitati. Se uno spazio fallisce, gli altri vengono comunque elaborati e il comando segnala l'errore.

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

## Confronto degli stati dinamici — 23 ottobre 2026

- `node rest/tests/patient_booking_parity.cjs`: 92 confronti di testo, CTA e pixel del contenuto paziente, su 390 e 1280 px. Comprende campi obbligatori/errori, per sé/per altri, catalogo senza risultati, disponibilità, riepilogo, conferma/richiesta, nessuna email, dettaglio, richiesta rifiutata/scaduta, modifica, annullamento e motivo, elenchi vuoti, contatti, invito e sospensione. Il riferimento è il prototipo originale, con gli stessi dati e font. Il pannello demo è escluso; la PWA è nello stato già installato, coerente con l'ingresso interno. Le schermate di verifica non fanno parte del percorso autorizzato.
- `node rest/tests/patient_booking_admin_parity.cjs URL`: 44 confronti dei controlli attivi in entrambi gli stati, usando i componenti originali delle tavole. Radio, checkbox e switch coincidono nei ritagli confrontati. I controlli di verifica, anti bot pubblico e contatti non configurati rimangono non operativi in questo ingresso interno.
- `node rest/tests/patient_booking_admin_dynamic.cjs URL`: errori di salvataggio e conflitti, recupero della bozza, ordine non valido e correzione, selezione del dispositivo, caricamento/rimozione logo, sincronizzazione delle modalità e sospensione. Usare una preview di test: modifica i dati sintetici in memoria.

Le immagini e i risultati dettagliati vengono salvati localmente in `output/patient-booking/`. I confronti pixel verificano il contenuto/stato indicato, non costituiscono un confronto integrale di ogni pagina amministrativa con qualsiasi dato reale. Catalogo, recapiti, logo, cronologia e link vengono alimentati dai dati dello spazio conservando la struttura delle tavole. Prezzi assenti non mostrano “null”; la conferma di invio email appare solo dopo un esito positivo dell'outbox. Le prove comprendono 71 controlli MySQL su database temporaneo e non inviano email reali.

Nessuna pubblicazione effettuata durante questa verifica. Le 44 tavole HTML sono state ricontrollate byte per byte contro lo ZIP.
