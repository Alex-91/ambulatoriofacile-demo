# Fatturazione unica

La voce Fatturazione raccoglie archivio documenti, incassi, report e collegamento alla coda TS esistente. Le opzioni `billing_services` (prestazioni e listini), `billing_agreements` (convenzioni e assicurazioni) e `billing_compensation` (compensi) non dipendono dalla tipologia dello spazio. Le autorizzazioni sono verificate anche sui POST. I cataloghi di base servono per configurare le opzioni attive. Documenti ordinari e personalizzazioni esistenti sono conservati.

## Attivazione per spazio

Durante una finestra senza operatori in scrittura, dopo il backup del database dello spazio:

```powershell
php rest/spark billing:unify ID_TENANT
php rest/spark billing:unify ID_TENANT --apply
```

Il primo comando verifica gli archivi già installati senza migrare; segnala gli schemi mancanti per uno spazio nuovo. Il secondo installa gli schemi necessari e migra. Uno schema ordinario esistente ma incompleto richiede prima la riparazione dedicata. Non vengono rieseguiti i backfill di pagamenti/scadenze sui database già pronti.

L'archivio operativo unico è `billing_documents`. Le tabelle `pc_*` conservano prestazioni, movimenti, rate, compensi e audit. `pc_documents` rimane come copia storica; dopo la migrazione le nuove operazioni non vi scrivono. Nessuna migrazione viene eseguita automaticamente visitando una pagina.

Numeri, date, importi e record ordinari non cambiano. I documenti migrati ricevono ID interni superiori ai massimi dei due archivi. La mappa persistente `pc_settings/unified_archive` conserva la corrispondenza; tutti i riferimenti operativi sono rimappati nella stessa transazione. Una collisione numero/data interrompe l'operazione, senza rinumerazioni silenziose. Rieseguire il comando non duplica i documenti.

I vecchi URL documento/PDF traducono gli identificativi originali. Un POST aperto prima della migrazione viene respinto: riaprire la schermata. Non ripristinare solo il vecchio codice dopo l'attivazione: tornerebbe a scrivere nell'archivio storico. Un ripristino richiede il backup coerente dello spazio e la verifica delle scritture successive.

Per gli spazi con il vecchio modulo, il comando concede le nuove funzioni solo dove non esiste già un override esplicito. Per gli altri spazi, concedere le singole funzioni dalla gestione piattaforma; il responsabile può attivarle/disattivarle tra le funzioni disponibili.

## Sistema TS

La selezione multipla e l'invio cumulativo rimangono nell'archivio e nella lista Sistema TS. La migrazione non abilita invii sui documenti storici. Nel dettaglio di un documento con registro incassi si impostano inclusione TS, tipo di spesa e opposizione. Si usano il bridge, i profili, le validazioni e le ricevute esistenti.

Le fatture private interamente incassate, senza storni e con pagamenti nello stesso anno entrano nel flusso comune, sempre soggette ai controlli TS. Quote ente, rimborsi, note di credito e incassi su anni diversi richiedono verifica e gestione dedicata nel Sistema TS: non viene trasmesso automaticamente l'intero importo. Il registro viene bloccato durante la preparazione; documenti già collegati a TS richiedono riconciliazione prima di nuove modifiche agli incassi.

L'unificazione non aggiunge SdI automatico né importazione nativa Passepartout: restano XML, CSV configurabile e simulatori già presenti.

## Verifiche

Test su SQLite sintetico: ID sovrapposti, relazioni, note di credito, saldi, collisioni, idempotenza, nuova fattura senza compensi, opposizione TS e presenza nella coda comune. Regressioni fatturazione ordinaria, bridge TS, email e viste. Browser su fixture senza database: navigazione avanzata/base, link TS, aggiunta rate, sei sezioni, form e CSRF.

```powershell
cd rest
php -d xdebug.mode=off vendor/bin/phpunit --no-coverage --filter 'UnifiedBillingTest|Polyclinic|BillingTsBridgeServiceTest|BillingDocumentServiceTest|BillingReport|BillingEmail'
# Dalla radice, con Playwright disponibile:
node rest/tests/unified_billing_browser.cjs
```

Per questa modifica non sono stati eseguiti deploy o migrazioni di database remoti.
