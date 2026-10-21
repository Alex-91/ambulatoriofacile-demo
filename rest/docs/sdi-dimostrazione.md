# Percorsi SDI dimostrativi

Accesso: **Fatture a strutture → Invio SDI · dimostrazione**. Route: `admin/fatturazione-strutture/sdi`.

La pagina usa il controllo amministrativo e l'abilitazione Fatturazione già esistenti. Non richiede l'installazione del modulo Poliambulatori. Il collegamento è disponibile anche nel form delle fatture a strutture.

Tre percorsi interattivi: XML / portale AdE, PEC e provider API. Ciascuno mostra quattro esiti simulati: consegna, scarto, mancata consegna, attesa. Cambio del canale o dell'esito azzera il risultato precedente. I download sono un XML sintetico marcato NON TRASMETTERE e un riepilogo esplicitamente non ufficiale.

Non vengono lette o modificate fatture, inviate email/PEC o eseguite chiamate a provider. Nessuna credenziale viene richiesta o conservata. Non sono implementati connettori reali PEC/API né un export fiscale delle fatture esistenti. Non presentare questa demo come invio SDI operativo; per l'operatività servono implementazione, configurazione e collaudo del canale scelto e del generatore XML. Non è un servizio di conservazione a norma.

Verifica: `rest/tests/billing_sdi_browser.cjs` con playwright-core su NODE_PATH, browser Chromium e PHP disponibili. La fixture CLI non avvia l'app, non legge .env e non apre database. Il test copre 12 combinazioni, download, reset, mobile e assenza di richieste esterne.
