# FSE 2.0 — certificati ricevuti e prime verifiche Sogei

9 settembre 2026, branch locale `codex/fse-certificati-test`.
Ambulatorio Facile comunica con il Gateway nazionale di test: tre documenti
hanno ottenuto HTTP 200 in modalità VERIFICA. Questo risultato non è un
accreditamento, una firma clinica verificata o una pubblicazione nel FSE.

## Risultato tecnico

I due certificati pubblici ricevuti da Sogei sono stati importati senza rigenerare
le chiavi; corrispondono crittograficamente alle chiavi private e alle CSR originali.
Sono certificati della CA Ministero della Salute Test, validi dal 9 settembre
2026 al 9 settembre 2029. Autenticazione mTLS e firma JWT hanno funzionato.
Non sono certificati di produzione e quello di firma JWT non firma i referti
clinici al posto del medico.

| Documento di prova | Ora italiana del test | Esito |
| --- | --- | --- |
| XML ufficiale RSA caso 24, immutato e allegato a PDF/A-3b | 18:55:40 | VERIFICA HTTP 200, traceID e workflow presenti |
| XML ufficiale RSA caso 25, immutato e allegato a PDF/A-3b | 18:56:20 | VERIFICA HTTP 200, traceID e workflow presenti |
| RSA sintetico prodotto dal builder Ambulatorio Facile | 19:05:25 | VERIFICA HTTP 200, traceID e workflow presenti |

Il terzo documento usa le identità di prova dell'esempio ufficiale, non dati
della struttura o dei suoi pazienti. Non riproduce tutti i requisiti del caso
ufficiale 24: prova il nostro generatore, il PDF/A e il trasporto nazionale.
Le tre chiamate non hanno usato VALIDATION né alcuna operazione di pubblicazione.

## Difetti emersi e correzioni implementate

1. Multipart nazionale: il Gateway rifiutava il JSON inviato come secondo file
   allegato. `requestBody` nazionale ora è un campo form contenente JSON; il PDF
   rimane un file. Contratto regionale mantenuto separato, non ancora testato in rete.
2. Terminologia CDA: `DRS` era inserito nell'autore come codice professionale ISCO.
   Sogei ha risposto con errore vocabolario. Il campo professione opzionale è stato
   rimosso, come nell'esempio ufficiale RSA 24, senza attribuire una qualifica
   non raccolta dall'app. `DRS` rimane il ruolo del JWT dove previsto.
3. TLS Windows: PHP non aveva una CA server configurata. Il runner usa un bundle
   Mozilla controllato per hash; la nuova configurazione applicativa opzionale
   `FSE2_GATEWAY_CA_BUNDLE` mantiene verifica peer e hostname obbligatorie.
   Non sono stati modificati trust store Windows, php.ini o `.env`.
4. Controlli certificati: prima della firma JWT sono controllati corrispondenza
   chiave/certificato, RSA >=2048 bit e validità temporale. L'importazione verifica
   anche CSR, ruoli e identità del pacchetto. La catena/revoca del certificato
   client non è stata attestata da questi controlli locali.
5. Custodia: aggiunte esclusioni Docker per `ops/.local` e `.ops-secrets`, così
   che i materiali locali non entrino nel contesto di build. Nessuna chiave o
   passphrase inserita nel codice, nei log o in un allegato per la Regione.

Il primo tentativo TLS e i tentativi HTTP 400 sono conservati: non sono stati
cancellati o trasformati in successi. Le prove fallite hanno consentito di
correggere problemi che XSD/Schematron offline da soli non rilevavano.

## Verifiche automatiche

Esecuzione completa del 9 settembre:

- PHP: 130 test, 524 asserzioni, zero errori/fallimenti/saltati.
- Python: 23 test, zero errori/fallimenti/saltati.
- 108 file sorgente censiti con SHA-256, invariati durante la suite.
- Lint PHP su 59 file e parsing PowerShell su 8 script superati.
- `git diff --check` superato.

Report privato: `rest/writable/fse-validation-reports/20260909-190816-a9d6fa06/`.
La suite è isolata/offline, usa SQLite e documenti/firme sintetici, non il DB live.
I risultati di rete sono separati in `ops/.local/fse-accreditamento/gateway-runs/`:
`20260909-165540-ea13cd90`, `20260909-165620-08b2fbb2`,
`20260909-170525-81133115`. I JSON contengono timestamp UTC, hash, correlazioni ed
esito; l'ultimo conserva anche il PDF inviato e hash dei sorgenti pertinenti.
La [guida Windows](../../ops/fse-validation/gateway-test.md) rende ripetibili
importazione, preflight locale e chiamata esplicita di test.

## Nazionale: cosa resta aperto

Il [repository ufficiale](https://github.com/ministero-salute/it-fse-accreditamento)
riporta la dismissione e rinvia ai nuovi avvisi. Nel
[thread Slack dell'8 settembre](https://developersitalia.slack.com/archives/C03RDT88FSM/p1788866938422539?thread_ts=1788866122.088939&cid=C03RDT88FSM)
il supporto conferma la sospensione per tutti i fornitori; consultazione del
9 settembre. Non risultano nuove istruzioni in quel thread e sul repository
consultato. Nessuna PR presentata nel repository dismesso, nessun nuovo EuSurvey
compilato e nessuna attestazione di accreditamento ricevuta.

Sono stati scaricati e letti checklist V9.0.0, schede RSA OK/KO e XML ufficiali
alla revisione `d937255fd7e9c079c5641c537da17fe98a2f2259`. Il piano preparatorio
privato elenca tutte le 23 righe RSA e le attività ancora necessarie.
I casi checklist 448/449 richiedono VALIDATION con HTTP 201: i test VERIFICA/200
di oggi non sono loro esecuzioni ufficiali. Non è stato creato un `data.json`
o un foglio ufficiale con risultati dichiarati anzitempo.

Restano: regole e finestra della nuova sessione, applicabilità dei casi KO,
copertura dei casi generati dall'app, gestione errori nel flusso operatore,
firma PAdES pertinente, raccolta finale e presentazione/valutazione ufficiale.

## Toscana: materiale pronto, abilitazione ancora da richiedere

La [procedura Toscana strutture private](https://compliance.toscana.it/portale/it/scenari/fse-2-0-strutture-private/)
richiede autorizzazione RT: per lo stage il fornitore trasmette scheda CART e
certificato pubblico di autenticazione a `fseprivati@regione.toscana.it`;
CART abilita il certificato dopo l'autorizzazione. La pagina indica inoltre
`compliance@regione.toscana.it` per registrazione del prodotto e referenti.
La disponibilità del certificato Sogei non rende automaticamente attivo lo stage.

In `ops/.local/fse-accreditamento/toscana-20260909/` sono pronti:

- `05-aggiornamento-toscana-certificati.txt`: risposta da inviare nella mail
  regionale già aperta, con copia al Team FSE 2.0 Toscana. Chiede se sia possibile
  anticipare lo stage del fornitore mentre si attendono riavvio nazionale e
  ammissione della struttura. Questa possibilità non è data per acquisita.
- `ambulatoriofacile-auth-stage.pem`: solo certificato pubblico A1, copia
  verificata dell'originale; nessuna chiave privata.
- `README.md`: corrispondenza con i campi effettivi della scheda CART v3.3,
  dati mancanti e passaggi successivi. ODS/XLSX originali non modificati.

Mancano denominazione/P.IVA/CF e sede della struttura, referente autorizzato e
recapiti, esito/riferimenti domanda, regime/impianto e scelta del repository.
Per Toscana Compliance manca anche il CF del referente tecnico prodotto.
Non è stata compilata o inviata una domanda con dati presunti. Nessuna email
inviata automaticamente e nessun messaggio pubblicato su Slack.

Restano l'autorizzazione CART, la registrazione/checklist, il collaudo regionale
dei quattro metodi (creazione, sostituzione, metadati, cancellazione), i test a
nome della struttura e relativi X-CART-id, quindi le abilitazioni di produzione.
I traceID nazionali di oggi non sono X-CART-id e non li sostituiscono.

## Stato degli ambienti

Modifiche locali, nessun commit/push/deploy in questa sessione; preservate le
modifiche preesistenti. Nessuna migration applicata a DB reali, nessun dato
paziente utilizzato, nessun profilo tenant reale modificato e nessun flag di
produzione o Toscana abilitato. La demo congelata non è stata toccata.
Runtime Linux, firma effettiva e workflow regionale restano da collaudare prima
di una futura attivazione: certificati e HTTP 200 non significano prodotto
pronto per l'uso clinico o rimborso del bando garantito.
# Aggiornamento successivo: prove negative e gestione operatore

Il 9 settembre sono state eseguite altre cinque chiamate preparatorie in VERIFICA:
controllo applicativo positivo 200; due token errati 403; nome mancante nel CDA
422; codifica non ammessa 400. Risultati, trace ID, controlli locali e analisi
delle 23 righe della checklist sono nella [matrice RSA](fse2-matrice-rsa-preparatoria.md).
Il seguito non modifica i risultati originali delle prime prove descritti sotto.
Nessuna chiamata regionale, firma clinica o pubblicazione FSE è stata eseguita.
