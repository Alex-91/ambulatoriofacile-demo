# Fatturazione TS roadmap

## Obiettivo

Progettare e implementare in AmbulatorioFacile un modulo `Fatturazione TS` che permetta di:

- creare e gestire documenti fiscali validi per il Sistema Tessera Sanitaria
- validare localmente i dati prima dell’invio
- inviare i documenti ai web service ufficiali TS
- ricevere e salvare esiti, protocolli, ricevute ed errori
- riprendere facilmente il lavoro anche dopo interruzioni o task paralleli

## Documento guida di progetto

Questo file e la fonte di verita operativa del progetto TS.

Uso previsto:

1. prima di riprendere il lavoro TS, rileggere questo file
2. durante ogni task TS, aggiornare almeno:
   - `Stato attuale`
   - `Diario avanzamento`
   - `Prossimo step operativo`
   - `Nodi aperti`
3. quando nel mezzo entrano altri task non TS, questo file resta il punto da cui ripartire

## Stato attuale

Aggiornato al: `2026-07-05`

Stato generale:

- fase di discovery iniziale completata
- architettura di alto livello definita
- mockup UI iniziali prodotti
- specifica tecnica codebase-oriented prodotta
- fondazione tecnica del modulo avviata
- invio sincrono `Inserimento` cablato sul tracciato SOAP ufficiale TS per ambiente `TEST`

Decisioni già prese:

- partire con un `MVP` focalizzato sul singolo documento e sull’invio diretto
- usare prima i `web service sincroni` ufficiali TS
- prevedere da subito anche un fallback operativo con gestione bozza, validazione e storico
- rimandare l’invio massivo asincrono a una fase successiva
- separare `configurazione TS` nel DB `platform` e `documenti TS` nel DB tenant
- nel primo rilascio non gestire un profilo `software house / terzo delegato`: la struttura sanitaria inserira e userà i propri dati e le proprie credenziali TS

## Fonti ufficiali già verificate

- Sistema TS area spese sanitarie
- documentazione ufficiale `Destinatari dei servizi e modalità di utilizzo`
- documentazione ufficiale `Strumenti per lo sviluppo`
- kit pubblico `730 Spese Sanitarie`
- presenza di `WSDL`, `XSD`, esempi XML, endpoint test e produzione

Nota tecnica già confermata:

- esistono `web service SOAP` ufficiali
- esistono flussi `sincroni` e `asincroni`
- per il primo rilascio conviene integrare il flusso `sincrono`

## Ambito del primo MVP

Il primo MVP deve coprire:

- configurazione credenziali TS per tenant o struttura
- creazione manuale del `Documento TS`
- precompilazione dai dati paziente e prestazione quando disponibili
- validazione locale dei campi obbligatori
- invio singolo al Sistema TS
- acquisizione esito, protocollo ed errori
- storico eventi documento
- dashboard di controllo con documenti da inviare, inviati e scartati

Scelta operativa corrente:

- il soggetto che compare e trasmette al Sistema TS e la struttura sanitaria cliente
- l’applicativo ospita i campi e i flussi di configurazione, ma non introduce un’identità TS autonoma della software house

## Fuori ambito del primo MVP

Da non fare nel primo step, salvo cambio decisione:

- motore completo di fatturazione general purpose
- invio massivo asincrono
- reportistica avanzata multi anno
- sincronizzazione retroattiva dal portale TS
- gestione completa di casi rari o eccezioni di categoria non ancora censite

## Fasi di lavoro

### 1. Discovery funzionale e normativa

Obiettivo:

- bloccare il perimetro funzionale reale del modulo

Output attesi:

- elenco campi obbligatori documento TS
- stati documento
- mappa errori funzionali principali
- elenco categorie iniziali supportate
- decisione su cosa nasce da visita e cosa nasce da inserimento manuale

Stato:

- completata in forma iniziale
- da rifinire con i dettagli dei codici spesa e delle regole operative reali dei primi tenant

### 2. Architettura tecnica e fondazione

Obiettivo:

- decidere come incastrare il modulo TS nell’architettura esistente

Output attesi:

- service layer dedicato TS
- strategia configurazione credenziali e segreti
- convenzione log e audit
- mapping tra dati interni e payload TS

Stato:

- specifica tecnica definita
- fondazione tecnica in corso

### 3. Modello dati e migration

Obiettivo:

- creare le tabelle minime per gestire documenti, invii, esiti e storico

Output attesi:

- migration iniziale modulo TS
- tabella documenti TS
- tabella eventi o log documento
- tabella configurazione TS tenant
- eventuale tabella code errori o allegati ricevuta

Stato:

- completata per `platform`
- avviata per `tenant`

### 4. UI di base

Obiettivo:

- dare agli utenti un’UI chiara e usabile prima ancora dell’integrazione completa

Output attesi:

- dashboard `Fatturazione TS`
- lista documenti
- schermata dettaglio `Documento TS`
- schermata impostazioni TS
- badge stato e pannello controlli

Stato:

- mockup iniziali pronti
- sviluppo avviato con dashboard, lista documenti, form bozza e bottone di invio reale

### 5. Validazione locale

Obiettivo:

- intercettare gli errori banali prima della chiamata al servizio esterno

Output attesi:

- validazione codice fiscale paziente
- validazione tipo spesa
- validazione importi e date
- validazione modalità pagamento
- prevenzione documenti duplicati più comuni

Stato:

- avviata con regole base su P.IVA, CF paziente, importo, date, pagamento e `document_device`

### 6. Integrazione WS sincroni TS

Obiettivo:

- inviare il singolo documento ai servizi ufficiali TS

Output attesi:

- client SOAP
- gestione ambiente test e produzione
- cifratura dei campi richiesti
- invio singolo documento
- gestione timeouts, errori tecnici e messaggi funzionali

Stato:

- avviata con WSDL ufficiali, endpoint `TEST`, Basic Auth, cifratura dei campi sensibili e parser risposta

### 7. Esiti, ricevute e correzioni

Obiettivo:

- chiudere il ciclo operativo dopo l’invio

Output attesi:

- salvataggio protocollo TS
- salvataggio esito
- recupero ricevuta PDF se disponibile
- vista errori di validazione o scarto
- riapertura del documento per correzione

Stato:

- avviata con protocollo, esito e recupero ricevuta PDF già collaudati in ambiente `TEST`
- resta da rifinire la gestione operativa di correzione, annullo o rettifica nei casi reali

### 8. Stabilizzazione e rilascio

Obiettivo:

- portare il modulo a un primo rilascio controllato

Output attesi:

- test mirati
- flussi demo o test end to end
- documentazione operativa minima
- checklist rilascio

Stato:

- avviata con smoke test reale `TEST`, diagnostica migration dedicata e checklist onboarding cliente
- resta da completare il pilot con un tenant reale e la procedura controllata di passaggio a `production`

### 9. Fasi successive

Da affrontare solo dopo il MVP:

- invio asincrono massivo
- report mensili o annuali
- gestione segnalazioni o anomalie cittadino
- strumenti di annullo o rettifica più evoluti
- automazioni e controlli pianificati

## Proposta modello dati minimo

Entita principali da prevedere:

- `ts_settings`
  - credenziali e configurazione tenant
- `ts_documents`
  - documento fiscale lato TS
- `ts_document_events`
  - cronologia di bozza, validazione, invio, errori, annulli
- `ts_document_receipts`
  - metadati ricevuta o riferimenti file, se separati

Campi minimi per `ts_documents`:

- tenant o struttura
- paziente e codice fiscale
- partita IVA erogatore
- numero documento
- data emissione
- data pagamento
- tipo spesa
- importo
- pagamento tracciato o contanti
- opposizione
- stato locale
- stato TS
- protocollo TS
- payload o snapshot inviato
- ultimo errore

## Stati documento proposti

Stati locali:

- `draft`
- `to_validate`
- `ready`
- `sending`
- `sent`
- `rejected`
- `cancelled`

Stati UI da esporre in italiano:

- `Bozza`
- `Da validare`
- `Pronto`
- `In invio`
- `Inviato`
- `Scartato`
- `Annullato`

## Nodi aperti

Domande ancora da chiudere prima di implementare forte:

- quali `tipi spesa` supportiamo nel primo rilascio oltre a `SP`
- il documento nasce da visita, da prestazione o da inserimento libero
- dove memorizzare in modo sicuro credenziali e parametri TS per tenant
- come gestire tenant con più professionisti o più partite IVA
- quali utenti possono inviare davvero a TS e quali solo preparare bozze
- se il primo tenant reale ha bisogno di annullo o rettifica già nel MVP

Nodo chiarito il `2026-07-05`:

- per ora non implementiamo il caso `terzo delegato software house`; se in futuro servirà, verrà trattato come estensione separata

## Strategia test

Livelli di test previsti:

1. `test locale applicativo`
   - validazioni
   - bozza documento
   - audit
   - storage artefatti
   - costruzione payload
   - parsing risposta SOAP
2. `test tecnico di integrazione`
   - WSDL pubblici
   - endpoint `TEST`
   - bootstrap client SOAP
   - handshake, operazione `Inserimento` e tracciatura tecnica
3. `test funzionale TS reale`
   - eseguibile con le utenze di prova ufficiali del kit TS
   - da estendere poi alle credenziali reali del cliente quando disponibili

Conclusione pratica:

- possiamo costruire subito tutta l’infrastruttura software
- possiamo fare subito test locali, build payload e chiamate reali all’ambiente `TEST`
- il passaggio a `PRODUZIONE` dipendera invece dalle credenziali del soggetto cliente

## Rischi principali

- complessita delle regole per categorie professionali diverse
- dipendenza da credenziali e ambiente test reale TS
- integrazione SOAP e cifratura più delicata di una normale API REST
- possibili differenze tra dati interni attuali e requisiti del tracciato TS
- impatto UX se i messaggi di errore non vengono tradotti bene

## Strategia di avanzamento consigliata

Ordine consigliato:

1. bloccare il modello dati
2. bloccare UI e stati
3. implementare validazione locale
4. implementare impostazioni TS
5. implementare invio sincrono
6. implementare esiti e correzione errori
7. testare con ambiente TS di prova

## Regola di aggiornamento futuro

Quando lavoriamo di nuovo su TS, aggiornare sempre questo file con almeno una riga nel diario:

- data
- cosa è stato fatto
- cosa resta aperto
- prossimo step operativo

## Diario avanzamento

### 2026-07-04

- chiarito il significato di `Fatturazione TS` nel contesto prodotto
- verificata l’esistenza di web service ufficiali TS
- verificata la disponibilità di kit tecnico pubblico con `WSDL` e `XSD`
- scelto di partire con un MVP basato su invio `sincrono`
- prodotti mockup iniziali per dashboard e dettaglio documento
- creato questo file come roadmap persistente di progetto
- scritta la specifica tecnica del modulo in `rest/docs/fatturazione-ts-specifica-tecnica.md`
- fissata come prima tranche di codice la `fondazione tecnica` del modulo
- aggiunta estensione PHP `soap` al runtime container
- registrata la feature `ts_billing` nel catalogo applicativo
- creata la migration `platform_tenant_ts_profiles`
- creati `Config/TsBilling.php`, `PlatformTenantTsProfilesModel.php` e la struttura `ThirdParty/TesseraSanitaria/`
- lasciata aperta l’importazione dei file ufficiali `WSDL/XSD/cert` nel repo, con path e convenzioni già pronti
- create le tabelle tenant `ts_documents`, `ts_document_events` e `ts_document_receipts`
- creati i model base `TsDocumentModel`, `TsDocumentEventModel` e `TsDocumentReceiptModel`
- creata la pagina tenant `spazio/fatturazione-ts` con salvataggio profilo TS locale
- introdotti i service `TsFeatureService`, `TsProfileService`, `TsSecretsService` e `TsHealthcheckService`
- aggiunto un `healthcheck locale` che controlla feature, campi minimi, runtime `soap` e presenza asset tecnici
- aperto il lato admin operativo con dashboard `admin/fatturazione-ts` e lista `admin/fatturazione-ts/documenti`
- aggiunta l’iniezione dinamica della voce menu admin TS quando la feature `ts_billing` è attiva
- aggiunta la schermata `Nuovo documento TS` con salvataggio bozza reale e `salva + valida localmente`
- introdotti `TsDocumentValidationService` e `TsAuditService` con timeline eventi base del documento
- aggiunti `TsPayloadBuilderService`, `TsSoapClientFactory`, `TsStorageService` e `TsDispatchService`
- collegato il bottone `Tenta invio TS` con preflight SOAP locale, snapshot sicuro e audit degli esiti tecnici
- corretto il legame tra documento TS e profilo usato, evitando cambi silenziosi di profilo sulle modifiche successive
- reso il canale SOAP configurabile via env per `operation`, `request root` e parser risposta
- aggiunta diagnostica tecnica in scheda documento con snapshot request/response dell’ultimo tentativo
- chiarito che il primo flusso supportato sarà `struttura sanitaria con proprie credenziali TS`, senza profilo terzo dedicato
- rivista la strategia test: useremo prima le utenze di prova ufficiali del kit TS, senza dipendere subito dalle credenziali reali del cliente
- importati gli asset pubblici ufficiali `WSDL/XSD/cert` del kit TS nella cartella `ThirdParty/TesseraSanitaria`
- aggiunto supporto ai preset locali `TEST` ufficiali tramite file ignorato `ops/.local/ts-test-presets.json`
- aggiornata la schermata tenant TS per precompilare il profilo con utenze di prova ufficiali
- mappata l’operazione SOAP `Inserimento` sul body ufficiale `inserimentoDocumentoSpesaRequest`
- confermata dai SoapUI ufficiali la cifratura di `pincode`, `cfProprietario` e `cfCittadino`
- introdotto `TsCryptoService` con uso del certificato pubblico `SanitelCF.cer`
- aggiunta Basic Auth reale al `SoapClient` usando username e password del profilo TS
- aggiornato il parser di risposta per leggere `esitoChiamata`, `protocollo` e `listaMessaggi`
- reso obbligatorio il campo `document_device` nella validazione locale per l’invio sincrono
- aggiornata la UI documento per chiarire che il pulsante esegue l’invio reale a Sistema TS

### 2026-07-05

- eseguite migration TS sul database di test corrente per abilitare `platform_tenant_ts_profiles`, `ts_documents`, `ts_document_events` e `ts_document_receipts`
- aggiunto il comando CLI `php spark ts:smoke-test` per collaudo end-to-end ripetibile
- corretta la gestione dei preset TEST ufficiali salvando `metadata_json` e rilassando solo in ambiente `test` le validazioni formali su `P.IVA` e `CF` dei preset tecnici
- corretta la costruzione del payload SOAP `Inserimento` evitando il wrapper errato, preservando gli zeri iniziali della `P.IVA` e attivando trace diagnostico sanificato
- estratti automaticamente da `office_code` i campi `codiceRegione`, `codiceAsl` e `codiceSSA`
- corretto il parsing importo nello smoke test per non trasformare `18.40` in `1840`
- verificato end-to-end l’invio reale verso Sistema TS `TEST` con preset `struttura_accreditata_lazio`
- scenario passato usando `tipoSpesa=SR`, `aliquotaIVA=10.00`, documento `TS260705160437`, record locale `#10`
- protocollo restituito dal gateway di prova: `99260705001852582`
- aggiunta la migration `2026-07-05-000004_AddTsVatMetadataToDocuments` con i nuovi campi persistiti `document_type`, `vat_rate` e `vat_nature`
- aggiornati form admin, service documento, validazione locale e payload SOAP per usare i metadati IVA salvati sul documento invece del fallback tecnico
- rilanciato il test reale verso Sistema TS `TEST` dopo la rimozione del fallback builder e ottenuto un secondo invio accettato: documento `TS260705161817`, record locale `#11`, protocollo `99260705001852583`
- esteso il catalogo `tipoSpesa` del modulo a tutti i codici ammessi dall `XSD` ufficiale `DocumentoSpesa730pSchema.xsd`, mantenendo descrizioni prudenti dove il kit locale non esplicita il significato
- aggiunti in scheda documento il box `Esito TS corrente`, la sezione `Ricevute TS` e le route admin per recupero e download ricevuta
- implementato `TsReceiptService` con chiamata SOAP `RicevutaPdf`, salvataggio PDF in `writable/tenants/<storage_key>/ts/receipts/...` e persistenza in `ts_document_receipts`
- eseguito collaudo reale completo `invio + recupero ricevuta PDF` su Sistema TS `TEST`: documento `TS260705163850`, record locale `#14`, protocollo `99260705001852586`, PDF salvato localmente con successo
- resta da rifinire le descrizioni business dei codici `tipoSpesa` in UI e modellare meglio i casi IVA non standard oltre allo scenario `F + SR + aliquotaIVA` già collaudato
- resta da affinare le descrizioni business dei codici `tipoSpesa` meno documentati nel kit locale e, se serve, modellare eventuali campi avanzati come `flagTipoSpesa`
- nota di attenzione: il comando `php spark migrate -g platform` sul DB test ha registrato anche migration dell namespace `App`; prima del rilascio conviene rifinire la strategia di esecuzione migration per gruppo
- nota di attenzione aggiornata: anche `php spark migrate -n App -g default` sul DB test ha applicato migration `App` pregresse ancora pendenti sul tenant di prova; prima di rilasciare conviene definire una procedura migration più prevedibile per ambiente e gruppo
- introdotto `TsMigrationSafetyService` per ispezionare schema TS, drift migration e allineamento runtime separatamente su `platform` e `tenant`
- aggiunti i comandi `php spark ts:doctor` e `php spark ts:migrate-safe` per una diagnostica ripetibile e una procedura di migrazione filtrata solo TS
- esteso l `healthcheck locale` della schermata tenant con i controlli su tabelle, colonne chiave, feature `ts_billing` e mismatch tra tenant configurato e runtime locale
- rifinita la parte business dei `tipoSpesa`: il modulo distingue ora tra descrizioni `verificate` e descrizioni `prudenti` per i codici presenti nell `XSD` ma non ancora chiariti meglio
- aggiunto il documento operativo `rest/docs/fatturazione-ts-onboarding-cliente.md` con checklist, flusso ambienti e diagrammi di onboarding
- eseguito `php spark ts:migrate-safe --scope=all --allow-drift=1` sul DB test: le due migration TS `platform` sono ora allineate in history senza applicare migration `App` non TS
- rilanciato `php spark ts:doctor`: il modulo TS risulta allineato su `platform` e `tenant`; resta solo il warning sul drift storico di altre migration `platform` non ancora applicate

## Prossimo step operativo

Il prossimo step consigliato e:

`pilot controllato con un tenant reale: allineare dati cliente, verificare la procedura di passaggio da TEST a PRODUCTION e decidere il perimetro MVP di annullo/rettifica`

Aggiornamento:

`fondazione + settings + dashboard/lista + bozza + validazione + adapter SOAP + preset TEST ufficiali + mapping reale Inserimento + ricevute PDF + doctor/migrate-safe + checklist onboarding completati in locale; prossimo focus consigliato: pilot reale e criteri di go-live`

## Regola pratica di ripartenza

Quando in futuro diremo:

- `riprendiamo la TS`
- `torniamo sulla fatturazione TS`
- `continuiamo il modulo TS`

la prima cosa da fare sarà:

1. rileggere questo file
2. verificare `Stato attuale`, `Nodi aperti` e `Prossimo step operativo`
3. aggiornare il diario con la nuova sessione di lavoro
