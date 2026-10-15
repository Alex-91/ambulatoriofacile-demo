# Preparazione FSE Toscana Privati — 7 settembre 2026

## Aggiornamento software del 15 ottobre 2026

Fonte dei nuovi requisiti: risposta Regione Toscana fornita nella chat, ticket
`20260921360019`. Per la nuova fase di conferimento servono i certificati SOGEI
di test con CN `A1#090#NOMEFORNITOREXX` e `S1#090#NOMEFORNITOREXX`. Il suffisso
effettivo e le eventuali nuove CSR vanno confermati con SOGEI; non sono stati
inventati né sono state sostituite le credenziali precedenti. La disponibilità
di una struttura per proseguire è da riconfermare, essendosi la precedente
cliente orientata verso un altro fornitore. Il software non risolve questo
requisito amministrativo.

Implementati nel branch `codex/fse-toscana-090-rad-20261015`:

- Preflight locale delle due coppie: validità, RSA almeno 2048 bit, corrispondenza
  certificato/chiave, ruolo A1/S1, codice 090 e stesso suffisso fornitore.
  Il risultato riporta solo metadati pubblici e resta `operational_ready=false`.
  Il client regionale esegue gli stessi controlli prima di JWT e trasporto;
  i profili nazionali mantengono le credenziali e gli endpoint propri.
- Preparazione RAD nel gestionale per i profili Toscana di test: ordine,
  identificativo locale del paziente, domini OID e codice esame obbligatori.
  Dati aggiuntivi cifrati in `radiology_details_enc`; migration additiva
  `2026-10-15-100001_AddFseRadiologyDetails.php`, inclusa nel runner FSE.
  Le bozze esistenti non possono cambiare tipo; firma e artefatti consolidati
  restano protetti. Gli invii operativi Toscana rimangono bloccati.
- CDA RAD con LOINC `68604-8`, template RAD e sezioni radiologiche, collegato
  al generatore già disponibile. RSA continua sul proprio generatore.
- Smoke PHP→Python con RSA e RAD, PDF/A-3b, allegato CDA esatto e rifiuto delle
  alterazioni; esportazione opzionale di quattro artefatti sintetici con hash.
  I file esportati sono senza firma e non rappresentano casi ufficiali di
  accreditamento. Le firme sono verificate nella suite sintetica dedicata.
- Preparazione locale del contesto Docker di staging, con manifest e catalogo
  completo RSA/RAD. Nessun container o servizio remoto avviato.

### Comando per i futuri certificati 090

Il modello privo di segreti è `ops/fse-validation/toscana-stage-profile.example.json`.
Crearne una copia privata fuori da Git e dal webroot; tenere i materiali 090
in una sottocartella distinta da quelli nazionali. I percorsi nel JSON sono
relativi alla directory privata indicata con `--secrets-root`. L'audience va
compilata solo dopo conferma CART.

```powershell
php ops/fse-validation/toscana-stage-preflight.php --profile='<profilo-privato.json>' --secrets-root='<directory-privata-certificati>'
```

Per impostazione predefinita nessuna rete e nessuna lettura di `.env` o DB.
Il comando restituisce exit 2 se i controlli falliscono. Dopo autorizzazione
CART esplicita si può aggiungere `--probe-tls --cart-authorized`: viene eseguito
solo HEAD sull'endpoint fisso di stage, con mTLS, verifica CA/hostname e senza
redirect, JWT o documenti. Un HTTP 401/403/404/405 prova solo che si è raggiunto
un servizio HTTP attraverso TLS; non attesta autorizzazione API, mappatura CART,
collaudo o accreditamento. Questa prova di rete non è stata eseguita.

### Evidenze locali

Catalogo pubblico fissato alla revisione `687cf371e1d0caf4f5f9f7bcc80eab3a97f09885`,
Schematron RSA 8.3 e RAD 4.1. Il precedente catalogo Windows aveva solo RSA:
creata una nuova directory con tutti i 13 file, preservando catalogo e settings
precedenti. Le prove RAD si sono fermate correttamente quando il catalogo RAD
mancava; con il catalogo completo gli 11 test RAD sono passati. Passati anche
24 test RSA: PDF/A, firma valida, revoca, scadenza, autore errato e alterazioni.
Le firme sintetiche non attestano la qualifica eIDAS della futura firma reale.

Suite PHP con integrazione documentale attivata: 322 test, 1.620 asserzioni,
nessun errore o skip. Rapporto privato nel checkout di task:
`rest/writable/fse-toscana-090-rad-20261015.junit.xml`.
L'ultima esportazione sintetica verificata è in
`rest/writable/fse-stage-smoke-9ef90279120263d4/`: `report.json` e quattro file
RSA/RAD XML/PDF, senza firma, con hash e dimensioni nel rapporto.
Smoke RSA/RAD passato con zero chiamate di rete, nessun dato clinico reale
e nessun accesso alla produzione. Contesto locale in
`rest/writable/fse-container-contexts/` (ogni esecuzione crea una directory
nuova e conserva le precedenti). La prova di migration ripetuta ha rilevato
una cache dei nomi colonna obsoleta: invalidata prima e dopo l'aggiunta del
campo RAD, quindi verificata su doppia esecuzione con dati preesistenti.

Comandi per ripetere le prove usando percorsi privati espliciti:

```powershell
php ops/fse-validation/stage-smoke.php --python='<python-runtime>' --settings='<settings-rsa-rad.json>' --export-artifacts
./ops/fse-validation/prepare-stage-runtime.ps1 -CatalogPath '<catalogo-completo-rsa-rad>' -RuntimeRoot '<runtime-pubblico-verificato>'
```

Restano da ottenere/verificare con gli enti: nuovi certificati 090, struttura
di riferimento e consenso al percorso, utenza Zammad corretta, modulo CART,
audience e mappatura del certificato, test ufficiali RSA/RAD, contratto degli
esiti regionali, accreditamento nazionale e autorizzazione alla produzione.

## Stato precedente e riferimenti storici

Questo incremento è locale, non un accreditamento né un'attivazione di produzione.
Il workflow clinico regionale non è ancora esposto agli operatori. Non usare dati reali nei test.

Aggiornamento 10 settembre: è collegato ai referti il **precollaudo**, solo nel
bootstrap applicativo sintetico isolato. Importa snapshot di artefatti firmati
verificati e prova le operazioni su uno stato separato, senza aggiornare il DB
clinico. Non è il dispatcher CART operativo. Vedere [guida e prova ripetibile](../../ops/fse-validation/app-lab.md).

Il riesame delle [specifiche v1.5 del 29 luglio 2026](https://compliance.toscana.it/portale/wp-content/uploads/2026/08/Specifiche-Tecniche-API_FSE2.0_Privati_v1.5.pdf)
conferma che il passaggio reale richiede autorizzazione RT e abilitazione CART.
Il paragrafo 5 distingue l'impianto per repository regionale e quello con
repository proprietario/doppio regime; il riepilogo HTML conserva una definizione
diversa. Non creare CN o dedurre abilitazioni per sede in automatico.
Da confermare inoltre il contratto di esito/riconciliazione: il paragrafo 9 rinvia
la notifica dello stato alla disponibilità delle specifiche nazionali. Gli stati
`SIM.*` del laboratorio non sono messaggi CART reali. Restano da allineare gli
identificativi documentali ai valori assegnati e agli esempi del paragrafo 13;
gli ID locali `AF.*` non dimostrano conformità al naming regionale.

Aggiornamento 9 settembre: certificati Sogei di test ricevuti e prime VERIFICA
nazionali riuscite; nessuna abilitazione CART o prova di rete toscana effettuata.
Scheda CART v3.3 letta e materiale di aggiornamento Regione preparato, senza
inventare dati della struttura: [stato e prossimi passi](fse2-certificati-e-primi-test.md).

## Implementato e verificabile offline

- Preset tecnico `access_mode=toscana_privati`, con endpoint CART v2 distinti per test e produzione. Il middleware regionale generico richiede un URL esplicito.
- Primitive del client: `validateAndCreate`, `replace`, `updateMetadata` (JSON) e `delete`. La sostituzione richiede un ID nuovo e un ID precedente distinto.
- `X-CART-id` estratto dagli header anche sugli errori; nessuna memorizzazione degli header Authorization o Set-Cookie. Nei flussi già collegati al dispatcher, l'identificativo è conservato nell'audit per ogni chiamata e in `last_response_json.transport.x_cart_id`. Il risultato restituito dal client espone lo stesso dato per il futuro runner regionale.
- Errori di trasporto, risposte 5xx o successi HTTP non interpretabili sono trattati come esito incerto: gli invii già avviati restano bloccati in attesa di riconciliazione. Nessun retry automatico.
- Il workflow di pubblicazione non eredita l'ID della precedente validazione. Il controllo stato di una validazione non può rendere il documento pubblicato.
- Rigenerazione degli artefatti vietata negli stati validato, firmato, in elaborazione e pubblicato.
- Test con trasporto simulato e database SQLite in memoria, senza Gateway, pazienti o database di produzione.

Esecuzione dalla directory `rest`:

```powershell
$env:XDEBUG_MODE='off'
php vendor/bin/phpunit --filter Fse --no-coverage --do-not-cache-result
```

## Configurazione futura del collaudo

Il solo trasporto regionale richiede `FSE2_ALLOW_TOSCANA_STAGE=true`, dopo autorizzazione CART e installazione dei certificati di test. Il flag non abilita il workflow operatore e non certifica il software.

L'audience JWT va confermata con CART e passata esplicitamente al client tramite `jwt_audience` (nel `metadata_json` del profilo); non viene dedotta dall'URL regionale. Dall'8 settembre la guida espone il campo audience e la modalità Toscana solo per preparazione disabilitata. Sono disponibili profili distinti per sede/regime, con snapshot sui referti. Non attivare invii modificando manualmente il database.

La produzione Toscana è bloccata anche con `FSE2_ALLOW_PRODUCTION=true` finché il percorso applicativo regionale e il collaudo non sono completati.

## Evidenze da raccogliere in stage, non ancora eseguite

| Prova | Chiamata | Evidenze mancanti |
| --- | --- | --- |
| Pubblicazione | POST `/documents/validate-and-create` | Esito reale e X-CART-id |
| Sostituzione | PUT `/documents/{idPrecedente}` | Nuovo CDA/versione, esito e X-CART-id |
| Metadati | PUT `/documents/{id}/metadata` | Nuova sottomissione, esito e X-CART-id |
| Cancellazione | DELETE `/documents/{id}` | Esito e X-CART-id |

Prima degli invii occorre congelare una matrice di test concordata con Regione e usare esclusivamente i dati sintetici ufficiali ammessi. Il runner offline raccoglie ora le evidenze locali, non quelle di un ambiente regionale reale: vedere il [dossier di collaudo](fse2-dossier-collaudo.md).

## Lavoro applicativo ancora necessario

### Riesame dopo i certificati Sogei — 9 settembre

La disponibilità dei certificati nazionali non abilita automaticamente CART.
L'[iter pubblicato da Toscana Compliance](https://compliance.toscana.it/portale/it/scenari/fse-2-0-strutture-private/)
prevede scheda CART e certificato pubblico A1, verifica/autorizzazione regionale
e successiva abilitazione del certificato. Occorre confermare con Regione
l'accesso anticipato come fornitore mentre la struttura attende l'ammissione.
Non abbiamo eseguito chiamate regionali né modificato i flag di attivazione.

| Area regionale | Codice preparato e prove locali | Condizione ancora mancante |
| --- | --- | --- |
| Creazione | Client POST validate-and-create; preparazione e firma locale; mock di esiti 202/400/408/502 e correlazione X-CART-id | Collegamento del dispatcher regionale e verifica dell'esito reale; profilo/impianto autorizzato |
| Sostituzione | Client PUT; revisioni locali, nuovo ID, CDA RPLC, originale immutabile | Transizione regionale tra originale e revisione, evidenza di sostituzione effettiva e test CART |
| Metadati | Client PUT JSON e nuova sottomissione nei test di contratto | Comando operatore con permessi e audit; campi modificabili, riservatezza e regole regionali confermati |
| Cancellazione | Client DELETE e simulazione locale di esiti | Percorso produttivo confermato, gestione dell'esito regionale e mantenimento dell'originale |
| Esiti incerti | Nessun reinvio automatico, diagnosi in sola lettura, correlazioni conservate | Procedura ufficiale di riconciliazione CART e operatori responsabili; non riutilizzare implicitamente lo stato nazionale |
| Esercizio | Profili distinti per sede/regime, separazione ambienti e blocco produzione | Dati assegnati, repository, firma effettiva e policy fiduciaria, runtime del server e collaudo multi-impianto |

I test verificano inoltre che **tutte e quattro** le primitive siano bloccate
con stage disattivato, prima di usare certificati o contattare la rete.
Sono test di contratto e di sicurezza, non quattro X-CART-id ufficiali raccolti.

Materiale già preparabile: anagrafica fornitore/prodotto, certificato pubblico
A1 stage, scheda CART con soli dati verificati, proposta RSA iniziale,
matrice delle quattro operazioni e richiesta di chiarimenti su audience/DELETE.
Da completare con struttura/Regione: ragione sociale/CF/PIVA, sede e Comune,
referenti, regime privato o SSR, codici assegnati, repository e iter del bando.
Non compilare campi mancanti con identificativi degli esempi Lazio.

Le prove negative nazionali e il perimetro clinico effettivamente supportato
sono distinti nella [matrice RSA](fse2-matrice-rsa-preparatoria.md).

1. XSD e Schematron RSA ufficiali e PDF/A-3b verificato con veraPDF sono ora collegati al generatore locale. Restano dataset completo, terminologie e conferma delle regole regionali; vedere [runtime documentale](../../ops/fse-validation/README.md).
2. Verifica crittografica PAdES, identità del firmatario, revoca offline e identità del CDA implementate con blocco in assenza di esito verificato. Restano policy fiduciaria produttiva, qualifica eIDAS/Trusted List UE e compatibilità con il dispositivo di firma effettivo.
3. Firma locale prima dell'invio predisposta per Toscana e revisioni; laboratorio con otto scenari fissi e zero chiamate di rete. Restano collegamento del workflow operativo e interpretazione degli esiti CART reali; non riutilizzare automaticamente il polling nazionale.
4. Versioning locale implementato: originale immutabile, nuova bozza, motivo cifrato, paziente invariato, vincolo anti-duplicazione, CDA RPLC e riferimento nel PDF. Le revisioni non possono essere inviate come documenti nuovi; resta da collegare la sostituzione reale.
5. Workflow metadati/oscuramento con permessi, regole regionali, nuova sottomissione e audit; nessuna UI di oscuramento disponibile in questo incremento.
6. Diagnosi in sola lettura degli esiti incerti e dei worker fermi, blocchi di concorrenza e salvataggi obsoleti implementati. Guida multi-profilo e recupero sintetico verificati su Windows/MySQL: vedere [laboratorio](../../ops/fse-validation/app-lab.md). Restano riconciliazione con evidenze reali, carico, credenziali assegnate e collaudo operativo multi-impianto/regime. Non sbloccare a mano un invio ambiguo.
7. Onboarding, certificati e approvazioni, nazionale e regionale; scelta repository e, se necessario, servizio di recupero FHIR. Nessuna credenziale di un altro software trasferisce il suo accreditamento ad Ambulatorio Facile.

## Controlli del payload aggiunti l'11 settembre

`FseGatewayPayload` è ora usato dal client effettivo, non soltanto dal simulatore.
Ferma prima della firma JWT/trasporto date inesistenti o relative, fine precedente
all'inizio, codici non ammessi, sottomissione/repository mancanti, identificativi
oltre i limiti e regole di accesso malformate. Non converte implicitamente il fuso
orario e non assegna OID, repository o identità alla struttura.

Confronto con PDF v1.5 e OpenAPI scaricati l'11 settembre:

- PDF SHA256 `9dbb52b67f25ec647d0b92e5efc01a16984fa5995d031a64a8d881c780a33258`.
- OpenAPI SHA256 `71d8e9adca2999feb09a611ef3d6174c340ca601788e2d3282457f78a6694d5b`.
- Gli esempi curl del PDF contengono ancora `/v1`, `administrativeRequest` scalare
  e un codice alto livello `LDO`, mentre l'OpenAPI richiamata usa un array per il
  regime e include `REF` per i referti. Il client conserva l'array nazionale e
  `REF` per RSA, con endpoint regionali v2. Queste discrepanze e la codifica della
  parte multipart `requestBody` vanno confermate da CART prima del test regionale.
- Il naming regionale del paragrafo 13 non è certificato dal controllo generico
  OID/estensione. Gli ID sintetici `AF.*` non diventano conformi per questo motivo.

Il trasporto limita inoltre il corpo della risposta a 4 MiB e gli header cumulativi
a 64 KiB, impone HTTPS/TLS verificato e vieta redirect. Al superamento dei limiti
mantiene la correlazione CART già acquisita e restituisce esito incerto, non un
successo e non un permesso di riprovare. Sono limiti conservativi locali, non
nuove prescrizioni regionali. Nessuna chiamata CART eseguita in questo incremento.

Per audit dipendenze e preparazione del prestatore di firma:
[scheda sicurezza e firma reale](fse2-dipendenze-e-firma.md).

## Bando della struttura cliente

La cliente ha comunicato domanda presentata al bando di agosto e ammissione ancora in attesa. Non equivale ad autorizzazione all'esercizio del nuovo software né a rimborso garantito.

Per il secondo avviso, B.2/C.4 indicano 45 giorni dalla comunicazione di ammissione e limite del 30 novembre 2026. B.3 riporta invece il 30 giugno 2026 per l'ammissibilità delle spese: questa incongruenza richiede conferma scritta del gestore, non una nostra correzione presunta. Chiedere anche se è consentito sostituire prodotto/fornitore dichiarato in domanda e con quali formalità. Non emettere promesse di rimborso o retrodatare documenti.

## Riferimenti

- [Toscana Compliance — scenario Privati](https://compliance.toscana.it/portale/it/scenari/fse-2-0-strutture-private/), specifiche tecniche v1.5 del 29 luglio 2026, sezioni 4–10. Gli esempi non sostituiscono lo schema OpenAPI; il percorso DELETE di produzione nel PDF presenta un'incongruenza da confermare con CART.
- [OpenAPI nazionale richiamata dalla Regione](https://github.com/ministero-salute/it-fse-support/blob/main/openapi/gateway/swagger_gtw.yaml).
- [Secondo bando — Sviluppo Toscana](https://www.sviluppo.toscana.it/bando/bando-fse-pnrr-m6-c2-i131-secondo-bando/).
- [Avviso modificato, decreto 15064/2026](https://www301.regione.toscana.it/bancadati/atti/Contenuto.xml?id=5522091&nomeFile=Decreto+n.15064+del+07-07-2026-+Allegato+A+).

Monitoraggio avvisi nazionali impostato in questa task, feriali alle 09:00. Accesso al canale Slack #fse verificato il 7 settembre e aggiunto al controllo già esistente. Non condividere password o codici in chat.
