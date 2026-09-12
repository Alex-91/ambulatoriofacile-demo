# FSE 2.0 — analisi, implementazione e percorso di attivazione

Aggiornamento: 9 settembre 2026.

Certificati Sogei di test ricevuti e verificati; superate le prime tre prove
nazionali VERIFICA/HTTP 200. Nessuna pubblicazione, attivazione Toscana o
convalida formale: [esiti, correzioni e attività aperte](fse2-certificati-e-primi-test.md).

**Aggiornamento documentale serale:** generazione e invii ora richiedono il runtime
locale XSD/Schematron, veraPDF e pyHanko. I controlli basati solo sui marcatori di
firma sono stati sostituiti. Le descrizioni storiche sotto vanno lette con questo
aggiornamento e con i limiti del [runtime documentale](../../ops/fse-validation/README.md).
Nessun deploy o accreditamento è stato eseguito con questo incremento.

Preparazione regionale e limiti aggiornati: [Toscana Privati — stato tecnico e piano di collaudo](fse2-toscana-preparazione.md). Le primitive di trasporto regionali sono predisposte ma il workflow operatore regionale e gli accreditamenti restano da completare. Gli esiti incerti non autorizzano un nuovo invio automatico.

## Risposta operativa

AmbulatorioFacile dispone ora di una base applicativa FSE 2.0 separata da Fatturazione e Sistema TS. Il primo tipo documentale implementato è il **Referto di Specialistica Ambulatoriale (RSA)**, coerente con il caso d'uso del prodotto.

Non esiste un account pubblico generico con username/password per provare il Gateway. L'ambiente di validazione usa due certificati X.509 rilasciati al fornitore nel percorso di accreditamento:

1. certificato di autenticazione per il canale HTTPS/mTLS;
2. certificato di firma usato per firmare entrambi i JWT (`Authorization` e `FSE-JWT-Signature`).

Prima di avere questi certificati si possono collaudare localmente anagrafica, cifratura, CDA, PDF con allegato `cda.xml`, ciclo di firma, hash, JWT con certificati locali e audit. Dal 9 settembre un runner CLI isolato consente anche VERIFICA sul solo Gateway nazionale di test, con documenti sintetici/ufficiali. Gli invii operativi dell'applicazione e la produzione non sono stati abilitati.

## Perimetro implementato

| Area | Stato | Note |
|---|---|---|
| Feature multi-tenant `fse2` | Implementata | Disattivata per default e separata da `billing`/`ts_billing` |
| Profilo ente/struttura | Implementato | Regione, organizzazione, struttura, locality, repository e OID |
| Segreti | Implementato | AES-256-GCM; passphrase non mostrate e chiavi fuori da Git |
| Referto RSA | Implementato | Header CDA2 e sezioni cliniche strutturate |
| PDF/A-3b con CDA allegato | Verificato localmente | Generazione e validazione veraPDF obbligatoria; dipende dal runtime separato |
| Firma PAdES | Verifica locale implementata | Integrità, CF, fiducia esplicita, revoca, identità CDA e confronto con l'originale; qualifica eIDAS ancora da accertare |
| JWT RS256 | Implementato | `x5c`, claim applicativi, soggetto/autore/paziente e hash allegato |
| mTLS | Implementato | Certificato e chiave distinti configurabili per tenant |
| Validazione Gateway | Implementata | `POST /documents/validation` |
| Pubblicazione ordinaria | Implementata | `POST /documents`, solo dopo validazione e firma |
| Stato asincrono | Implementato | `GET /status/{workflowInstanceId}` |
| Cancellazione | Implementata | `DELETE /documents/{idDoc}` |
| Audit e stati | Implementati | Eventi tecnici senza token/passphrase/dati paziente nei log |
| Schema database tenant | Implementato | Migration filtrata e idempotente al primo accesso; comando `fse:schema-repair` per riallineamento esplicito |
| Produzione | Protetta | Richiede esplicitamente `FSE2_ALLOW_PRODUCTION=true` |

## Flusso applicativo

1. Il responsabile abilita la feature e salva il profilo FSE dello spazio.
2. Il medico crea una bozza RSA con paziente, prestazione e testo clinico.
3. Il sistema cifra i dati sensibili nel database tenant.
4. Il sistema genera CDA XML e PDF con `cda.xml` allegato.
5. Il Gateway valida in modo sincrono il PDF/CDA non ancora firmato.
6. Il medico firma lo stesso PDF in PAdES con un dispositivo/servizio di firma qualificato.
7. Il PDF firmato viene acquisito soltanto dopo le verifiche locali documentali e crittografiche; marcatori di firma o stato `signed` preesistente non bastano. La pubblicazione ripete i controlli.
8. Il `workflowInstanceId` di pubblicazione viene conservato e interrogato fino all'esito finale.
9. È disponibile la cancellazione del documento pubblicato; sostituzione/versioning e oscuramento avanzato sono estensioni successive.

## Sicurezza e privacy

- Identificativi diretti, data di nascita e testi clinici sono cifrati nell'archivio tenant; restano in chiaro solo metadati tecnici e temporali necessari al workflow.
- I codici fiscali hanno anche un hash SHA-256 per confronti esatti senza indicizzazione del dato in chiaro.
- CDA, PDF non firmato e PDF firmato sono conservati sotto `rest/writable/tenants/<tenant>/fse2/<documento>` e non nello storage pubblico.
- Le chiavi private non sono caricabili dalla UI e non devono essere committate: il profilo memorizza solo percorsi relativi a `FSE2_SECRETS_ROOT`.
- I token JWT sono generati ex novo a ogni chiamata e non vengono salvati nei log.
- La produzione è disabilitata per default.
- Il modulo non sostituisce valutazione DPIA, nomine privacy, registro trattamenti, policy di conservazione, gestione incidenti e segregazione operativa.

## Dipendenze esterne ancora necessarie

### 1. Accreditamento fornitore

Certificati di test ricevuti il 9 settembre e utilizzati con successo. Restano la riapertura del processo formale Sogei/Ministero, la conferma del piano di test, la sessione applicabile e la presentazione dei risultati/checklist con `traceID` e `workflowInstanceID`. Le prove VERIFICA non sostituiscono i test ufficiali VALIDATION.

### 2. Regione o Provincia autonoma

Il Gateway nazionale non elimina il raccordo regionale. Devono essere concordati:

- codice Regione, azienda/organizzazione, struttura e `locality`;
- repository ID e namespace/OID documentali;
- assetto organizzativo corretto (codice `AD_PSC...`);
- modalità di onboarding e collaudo;
- eventuale middleware regionale al posto dell'accesso diretto al Gateway;
- regole di conservazione a norma e responsabilità del titolare.

Un URL regionale da solo non rende compatibile il workflow: ogni integrazione richiede metodi, esiti, certificati e collaudo propri. Per Toscana Privati è predisposto un trasporto separato, ancora disabilitato per gli invii operativi.

### 3. Firma digitale

La firma PAdES non viene simulata dal gestionale. Per l'esercizio serve scegliere una soluzione qualificata:

- firma locale con smart card/token e caricamento del PDF; oppure
- firma remota via API, con contratto, autenticazione forte e consenso del firmatario.

La prima modalità è supportata con policy conservativa: una firma incrementale sull'originale generato, senza riscrittura del PDF. L'upload verifica crittografia, fiducia esplicita, evidenze di revoca offline e CF del firmatario, oltre al CDA e al PDF/A. Non attesta qualifica eIDAS/QSCD né sostituisce le Trusted List UE o il collaudo del dispositivo/provider reale. Un provider di firma remota potrà essere aggiunto dietro un'interfaccia dedicata.

### 4. Conformità CDA

Il builder produce un CDA RSA strutturato, ma l'accettazione definitiva dipende dai test ufficiali e dalla versione delle specifiche/terminologie usata nel percorso di accreditamento. Il dataset RSA ufficiale deve diventare un test di regressione prima del rilascio.

## Piano di collaudo

### Subito, senza credenziali Sogei

- eseguire migration platform e tenant in ambiente test dedicato;
- attivare `fse2` solo sul tenant di prova;
- configurare ente/OID con valori di test concordati;
- creare RSA sintetici, mai con dati sanitari reali;
- verificare CDA e PDF, firma PAdES e audit;
- eseguire la suite unit locale.

### Dopo il rilascio dei certificati

- installare i quattro file PEM (certificato/chiave mTLS e certificato/chiave JWT) nello storage segreti;
- eseguire healthcheck locale;
- inviare i casi del dataset ufficiale in `VERIFICA`/`VALIDATION`;
- raccogliere trace e workflow per il report di accreditamento;
- correggere eventuali scarti semantici o terminologici;
- completare i test regionali end-to-end.

### Prima della produzione

- validazione formale del fornitore e della Regione;
- firma e conservazione definite contrattualmente;
- test di continuità, retry/idempotenza, monitoraggio e alert;
- backup cifrati e prova di ripristino;
- autorizzazione esplicita `FSE2_ALLOW_PRODUCTION=true` solo sull'ambiente live;
- rilascio controllato su `main`, senza usare il database live come ambiente di prova.

## Fonti ufficiali

- Supporto e documentazione: https://github.com/ministero-salute/it-fse-support
- Accreditamento: https://github.com/ministero-salute/it-fse-support/blob/main/doc/accreditamento/README.md
- Integrazione Gateway: https://github.com/ministero-salute/it-fse-support/blob/main/doc/integrazione-gateway/README.md
- OpenAPI Gateway: https://github.com/ministero-salute/it-fse-support/blob/main/openapi/gateway/swagger_gtw.yaml
- Tool ufficiali: https://github.com/ministero-salute/it-fse-gtw-tools
- Esempio CDA RSA: https://github.com/ministero-salute/it-fse-support/blob/main/doc/esempi/CDA/RSA.xml

Nota temporale: il repository storico di accreditamento segnala nel 2026 una transizione verso un nuovo repository. Prima di presentare la domanda va verificato il canale operativo corrente indicato dal Ministero/Sogei.
