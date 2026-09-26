# Porting fatturazione, designer e Sistema TS — 26 settembre 2026

Implementazione nel branch `codex/billing-ts-designer-port`, confrontata con il codice di riferimento in `C:/xampp_82/htdocs/dottorAppLTE/rest`. Le modifiche locali preesistenti sono state conservate. Nessun deploy, invio TS esterno, accesso a database reale o copia di configurazioni/credenziali/dati paziente.

## Adattamenti al progetto

- Conservati database separati per spazio, catalogo piattaforma e audit con utenti piattaforma. Non importate le tabelle `doctor_billing_settings`/`doctor_ts_profiles` del riferimento.
- Impostazioni nuove e dati paziente di stampa nei JSON già disponibili (`config_json`, `template_snapshot_json`): nessuna nuova migration necessaria.
- Asset del designer in `public/assets/`, coerenti con il webroot e i percorsi `/demo`/`login` del progetto. Nuova route POST `admin/fatturazione-documento/preview`, coperta dal filtro CSRF già presente.
- Anteprima PDF con `BillingPdfOptionsFactory`, preservando cache e temporanei in storage writable dello spazio.

## Funzionalità riportate

- Compatibilità delle chiavi TS esistenti e fallback per variabili visibili tramite `env()`; errore esplicito se manca la chiave server.
- Codice natura IVA nel profilo TS, separato dal testo stampato della fattura. Zero significa aliquota assente; aliquota positiva esclude la natura. Il testo descrittivo non viene usato come codice TS.
- Aggiornamento delle bozze collegate in apertura, salvataggio e invio, conservando il confronto atomico con la versione originaria. Protocolli, timestamp di invio, esiti accettati e documenti in invio proteggono dalla sovrascrittura.
- Tipo spesa del profilo proposto nelle nuove fatture; rimosso SP forzato dall'agenda. Restano prioritarie le associazioni prestazione/tipo spesa esistenti.
- Errori e riferimenti di assistenza TS conservati nel flusso esistente. S041 resta un rifiuto esterno, senza sostituzioni del codice fiscale.
- Autocomplete paziente spostato vicino al campo, sopra i contenitori, con riposizionamento e numero progressivo della ricerca per ignorare risposte obsolete.
- Bollo predefinito configurabile e dettagli paziente salvati con la fattura; dati stampabili selezionabili tramite elementi del modello.
- Designer a blocchi con trascinamento, riordino degli elementi, larghezze intera/metà/terzo, stili, logo, salti pagina, annulla/ripristina anche dei testi condivisi.
- Conversione delle preferenze precedenti e mantenimento del renderer legacy per i documenti senza designer. Il nuovo renderer è condiviso da anteprima e PDF.
- Testi del modello senza troncamento individuale; limiti tecnici di 150.000 byte per designer JSON e 250.000 per payload POST serializzato, URL logo 255 caratteri.
- Informativa e footer indipendenti, modifica dal blocco e recupero dell'informativa inserita nella vecchia etichetta. Gestione testi lunghi, parole senza spazi e promozione delle colonne troppo alte a larghezza intera. Colonna IVA / Esenzione anche nel formato precedente.

## Verifiche eseguite

- 20 test PHPUnit, 153 asserzioni, tutti superati: salvataggio fatture e dettagli paziente, bridge TS, SR dall'agenda, aggiornamento reale delle bozze su SQLite in memoria, rifiuto di modifiche concorrenti/storiche, operazioni TS, riconciliazione, parser S041, contratti SOAP e certificato pubblico locali.
- Regressioni standalone `billing_*_regression.php`, `ts_vat_regression.php`, `ts_secrets_regression.php`: conversione, normalizzazione e persistenza impostazioni, bollo, riservatezza campi, escaping, testi lunghi, compatibilità crittografica, zero/aliquota/natura e payload SOAP.
- Browser Chromium con sole fixture sintetiche: trascinamento reale, undo/redo, colonne a un terzo, modifica informativa, anteprima live e ingrandita; autocomplete con risposte fuori ordine, scroll, resize e scelta paziente.
- PDF generati con Dompdf ed esaminati visivamente: esempio A4, 70 prestazioni (10 pagine con note lunghe), testi estesi (4 pagine), colonne strette e parole senza spazi (8 pagine), vecchio formato. Controllati intestazioni ripetute, contenuti finali, informativa/footer unici e coordinate entro la pagina.
- Lint PHP su 42 file e controllo sintassi JavaScript superati. WSDL, XSD e `SanitelCF.cer` erano già presenti; caricamento locale verificato senza chiamare TS.

## Ripetere i controlli

Dalla root, con PHP e dipendenze Composer installate:

```powershell
php -d xdebug.mode=off rest/vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap rest/tests/_support/billing_port_bootstrap.php rest/tests/unit/BillingPortRegressionTest.php rest/tests/session/BillingDocumentServiceTest.php rest/tests/session/BillingTsBridgeServiceTest.php rest/tests/unit/TsOperationClosureTest.php rest/tests/unit/TsReconciliationTest.php
php rest/tests/billing_ts_default_regression.php
php rest/tests/billing_long_text_regression.php
php rest/tests/billing_pdf_stress_regression.php
php rest/tests/billing_terms_label_regression.php
php rest/tests/billing_legacy_render_regression.php
php rest/tests/ts_secrets_regression.php
```

Per i browser test servono Node.js, Playwright e Chromium. `NODE_PATH` può indicare le dipendenze del runtime; `PHP_BIN` può indicare un eseguibile PHP specifico.

```powershell
node rest/tests/billing_designer_browser.cjs
node rest/tests/billing_autocomplete_browser.cjs
```

Le fixture non caricano `.env` e usano esclusivamente dati inventati; i PDF e screenshot sono in `rest/build/billing-port/`, ignorato da Git. Il bootstrap PHPUnit forza connessioni SQLite in memoria. Questi controlli non costituiscono una prova end-to-end su un account reale o una certificazione del servizio TS.

## Configurazione e rilascio

- Nella configurazione Sistema TS scegliere il codice natura appropriato e il tipo spesa predefinito desiderato (ad esempio SR). Non è stato imposto un codice fiscale/IVA a tutti gli spazi.
- Nella configurazione fatturazione scegliere il bollo predefinito (rimane zero finché non impostato); nel designer scegliere i dati paziente da stampare e salvare il modello convertito.
- Conservare la chiave server già usata per i segreti esistenti. Se nessuna chiave è disponibile, configurare quella prevista nell'ambiente di destinazione; non generare/sostituire alla cieca una chiave con credenziali già cifrate.
- La pubblicazione rimane quella Coolify del progetto: scelta del target demo/login/entrambi, commit del lavoro desiderato, integrazione e push su main, `ops/release-prod.ps1`, health check. In questo task il codice è locale e non è stato pubblicato.
