# Collaudo integrato del percorso paziente — 12 settembre 2026

## Esito e perimetro

Percorso verificato su laboratorio MySQL e HTTP locale isolato, con paziente,
studi, documenti e certificati interamente sintetici. Nessun invio TS/FSE, email,
dispositivo di firma reale, dato produttivo o deploy. Il controllo Linux resta
pendente: lo script di ripresa rileva `RESTART_REQUIRED`, nessuna distribuzione
WSL registrata. Nessun riavvio automatico effettuato.

Il test usa le sessioni, i filtri e i controller applicativi reali. La creazione
del paziente e il salvataggio rapido sono stati provati nel browser; i passaggi
successivi sono eseguiti via HTTP e controllati nel browser/PDF. La sola
preparazione TS usa il servizio applicativo da CLI: il router non espone invii
e tutti gli endpoint TS del laboratorio puntano a loopback, porta 1.

## Percorso verificato

- Paziente 101 creato dall'anagrafica, con relazione al personale 1.
- Appuntamento 2 prenotato per il medico con identificativo agenda 11.
- Consenso dossier con versione del testo e allegato sintetico di prova.
- Referto 1 definitivo, firma PAdES, download identico e originale conservato.
- Correzione 2 collegata al primo referto, firma CAdES e conservazione di entrambe
  le versioni. Nome, codice fiscale e riferimento alla correzione controllati
  nel testo estratto dal PDF e nell'immagine renderizzata.
- Fattura avviata dall'appuntamento con paziente, codice fiscale e descrizione
  precompilati; 100 euro di prestazione, 2 euro di bollo, totale 102 euro.
- Pagamento registrato, PDF generato, documento TS pronto e collegato alla
  fattura, senza protocollo né data di invio; esenzione N4 con aliquota TS nulla.
- Storico di visita/referti presente; download anonimo e dell'altro studio
  respinti; dati del secondo studio invariati.

Esecuzione progressiva e riprendibile: i controlli finali rileggono gli oggetti
creati nei passaggi precedenti, non ricreano tutto da zero in ogni tentativo.
Durante la prova l'anagrafica ha mostrato transitoriamente il solo identificativo
nella cartella; nome e cognome sono stati reimpostati tramite il controller e
verificati dopo un ulteriore salvataggio rapido nel browser. Il primo PDF non è
stato riscritto: la correzione conserva il precedente snapshot e contiene
l'intestazione completa. Non è stata attribuita una causa applicativa a questo
episodio, che non si è riprodotto nella seconda prova.

## Correzioni applicative

1. Il timer del controllo del codice fiscale non interrompe più la validazione
   avviata dal pulsante Salva: il salvataggio immediato completa al primo clic.
2. La cartella traduce l'identità del personale nell'identificativo legacy
   dell'agenda. Una visita non scompare quando i due codici differiscono.
   La regressione controlla anche che un altro medico non ottenga accesso per
   una coincidenza numerica fra i codici.
3. Il ponte fatturazione–TS converte IVA zero più natura in natura sola, sia
   nel payload di validazione sia nel record salvato. Aliquota positiva più
   natura rimane un errore, verificato con il servizio e database SQLite reali.

Lo storico della cartella mostra anche il motivo della visita, quando presente,
con escaping del testo e con le stesse restrizioni di accesso degli appuntamenti.

## Evidenze

Laboratorio: `rest/writable/fse-app-labs/7e9dda34980e4f0abe3e15a5e190dd74`.

- Rapporto finale: `journey-http-fad912fa665e4de99c33e2615de42ec0/report.json`:
  **78 controlli**, di cui 29 verifiche della provenienza delle richieste HTTP.
  Versione realmente caricata: framework 4.7.4 e dipendenze candidate.
- Artefatti privati: `journey-artifacts/`, inclusi originali, firme e preview.
- `clinical-closure.xml`: **47 test, 269 asserzioni**, senza errori o skip su
  baseline e candidato. Include permessi dei ruoli, consensi e regressioni TS.
- Provenienza suite: laboratorio dipendenze
  `e90fe89c1b0f4b2ba1d127e9c53a127f`, esecuzioni
  `application-candidate-f6f0547b2a1f7c5e` e
  `application-baseline-83a8d292315b500b`.
- Sintassi dei 10 file PHP interessati verificata; diff dei file tracciati
  controllato senza errori di whitespace.

Il test delle firme usa certificati sintetici e verifica formati/contenuto:
la qualifica legale della firma resta `not_assessed`. Non dimostra compatibilità
universale con ogni prodotto né accreditamento o collaudo con servizi esterni.

## Preparazione Linux e seguito

**Aggiornamento dopo il riavvio:** collaudo Linux completato, comprese cartella
clinica (66 controlli HTTP), firme, consensi, fatturazione e ripristino dei
documenti FSE. Corretta anche l'identità piattaforma usata dal catalogo
prestazioni. [Esiti, immagine provata e limiti](collaudo-linux-gestionale.md).
Entrambe le istanze Linux sono state fermate. Restano le verifiche con provider
reali e l'eventuale rilascio richiesto dall'utente.

Preparazione storica precedente al riavvio:

Nuovo contesto candidato:
`rest/writable/fse-linux-labs/f6a30c948707467db5a01fdbe9adb58f`.
Manifest verificati: **4.092 file applicativi e 51 runtime**. Stato
`PREPARED_NOT_BUILT`, Linux `NOT_EXECUTED`. Controllo della ricetta e corrispondenza
dei sorgenti superato: `PREPARATION_CHECK_PASSED_NOT_STARTED`.
Il pacchetto selezionato per il
laboratorio FSE non equivale a uno staging completo del gestionale.

Dopo il riavvio manuale, riprendere con `ops/fse-validation/resume-wsl-lab.ps1`
e la guida `ops/fse-validation/wsl-lab.md`. Restano la prova Linux, le verifiche
con dispositivo/provider reale e la scelta del target prima di un rilascio.
Codice mantenuto sul branch di lavoro; nessun aggiornamento di vendor attivi,
merge su main, push o pubblicazione effettuato in questa fase.
Server HTTP di prova e MySQL sintetico fermati a fine collaudo; evidenze conservate.

Gli script di questo collaudo sono `ops/fse-validation/app-lab-journey.php` e
`app-lab-journey-http.py`; richiedono i seed clinico/fatturazione, il paziente 101
creato dal browser e il server candidato. Per raccogliere la provenienza HTTP,
impostare anche `FSE_DEPENDENCY_LAB`, `FSE_FRAMEWORK_LAB` e
`FSE_APPLICATION_VARIANT=candidate`. Non riutilizzare certificati sintetici
scaduti né sostituire le evidenze dei tentativi precedenti.
