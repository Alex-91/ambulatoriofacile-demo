# FSE — salvataggi locali e audit atomici

Incremento preparatorio del 13 settembre 2026, senza deploy né invii al Gateway.
La nota di coordinamento alla task «Analizza requisiti cliente» è stata inviata
dopo l'autorizzazione esplicita dell'utente.

## Problema riprodotto e correzione

Il verbale del 12 settembre distingueva il rollback di una transazione creata
dal test dall'atomicità del servizio applicativo. Le nuove prove mirate hanno
riprodotto sei fallimenti prima della correzione: bozza inserita senza audit,
errore audit silenzioso, modifica della bozza non annullata, preparazione con
riferimenti aggiornati nonostante l'errore, firma consolidata senza audit e
scrittura parziale confermata da una transazione esterna.

`FseLocalPersistenceService` racchiude ora le brevi scritture locali in una
transazione con savepoint, verifica stato ed esito del commit ed effettua il
rollback in caso di errore. Il savepoint serve anche quando un chiamante ha
già una transazione aperta e intercetta l'eccezione: la sola profondità delle
transazioni del framework non annulla le scritture interne. Riferimenti:
[transazioni CodeIgniter](https://codeigniter4.github.io/userguide/database/transactions.html)
e [savepoint MySQL 8.4](https://dev.mysql.com/doc/refman/8.4/en/savepoint.html).

Il percorso locale richiede esplicitamente la riuscita della registrazione
audit, anche quando il modello restituisce `false` senza lanciare eccezioni.
La modalità storica degli audit dei flussi remoti non è stata cambiata: nessuna
chiamata Gateway viene avvolta in una transazione locale o ripetuta automaticamente.

Sono protetti:

- creazione e modifica della bozza con edit token;
- riferimenti CDA/PDF e rispettivo evento di preparazione;
- riferimento al PDF firmato, stato consolidato ed evento di acquisizione;
- nuova versione locale ed evento di revisione.

Generazione PDF e controlli di firma restano fuori dalla breve transazione DB.
Ogni tentativo scrive nomi privati distinti: un errore non sovrascrive gli
allegati dell'ultima preparazione riuscita. File non referenziati possono
rimanere nell'archivio privato dopo un guasto; non sono esposti attraverso un
referto e non vengono cancellati automaticamente. La gestione della loro
conservazione rimane un'attività amministrativa separata.

## Verifiche e perimetro

Primi controlli mirati: 53 test / 232 asserzioni sia sul framework attuale 4.6.0
sia sul candidato 4.7.4, senza errori o skip. Dodici casi nuovi rispetto al
collaudo precedente (undici sulla persistenza e uno sull'audit della revisione).
Sono compresi transazioni disabilitate, commit che restituisce `false`, errore
SQL, transazioni annidate e conservazione del lavoro antecedente al savepoint.
Le fixture unitarie sono sintetiche in SQLite in memoria; le verifiche di firma
in questa sottosuite sono sostituite da fixture, non costituiscono firma qualificata.

Confronto preliminare con l'immagine Linux del 12 settembre: dei 632 file
applicativi già censiti, nove differiscono. Tre sono i servizi FSE modificati
qui; gli altri appartengono alle evoluzioni di agenda, cartella e abilitazioni.
Il nuovo helper FSE è aggiuntivo. Non si devono attribuire a una singola immagine
collaudata tutte le modifiche contemporanee del checkout condiviso.

### Regressioni Windows completate

- Framework attuale 4.6.0: 274 test PHP / 1.318 asserzioni, 24 documentali
  Python e 35 packaging/harness, tutti superati. Report
  `rest/writable/fse-validation-reports/20260913-002307-b1952631/summary.json`.
- Candidato 4.7.4: stessa regressione superata, report
  `rest/writable/fse-validation-reports/20260913-003227-6272926e/summary.json`.
- Raccolta finale sul candidato, dopo l'integrazione di nove test sulle protezioni
  del nuovo laboratorio: **342 esecuzioni**, 274 PHP / 1.318 asserzioni,
  24 documentali Python e 44 packaging/harness. Report
  `rest/writable/fse-validation-reports/20260913-004257-f83959ba/summary.json`.

Tutti i report sono `passed_offline`, senza errori, fallimenti o skip, con
sorgenti censiti immutati durante ciascuna esecuzione. Anche il confronto dopo
la raccolta finale non ha rilevato variazioni nei sorgenti censiti. I numeri
di esecuzioni delle ripetizioni e delle suite sovrapposte non si sommano come
casi distinti. Il controllo di provenienza finale conferma 4.7.4, nessuna
sorgente framework inattesa e sistema attivo non modificato:
`rest/writable/fse-framework-compat/2c92718f32d648f29f0a22ddc362c3b6/run-candidate-a5911d5e651b607a/provenance.json`.

### Linux/MySQL: incremento circoscritto e verificato

Creato un derivato dell'immagine candidata precedente, copiando soltanto i
quattro servizi FSE e i due file di test elencati nel manifest di build.
Nessuna installazione di dipendenze o cambio di configurazione runtime.
Il controller verifica hash delle copie, identità immutabile delle immagini,
ascendenza dei layer e configurazione; le istanze hanno DB e volumi nuovi,
rete interna, nessuna porta pubblicata o mount del filesystem dell'host.

- Base: `sha256:10c1973e8bc302115429402e2de5cd48bd38a550fcc6b33b98f50d6fff490b3a`.
- Derivato: `sha256:8f090570cc16054cec4328f8d593cfaf15d0ee75d2f646cbe6cd77566b06ab74`.
- Manifest: `rest/writable/fse-atomic-linux-builds/5d5192f7f6944073b48e6c37bb6cbb0c/build.json`.
- Istanza: `rest/writable/fse-linux-ui-resilience/1f078d29244048f6b7c53bffd7254213`.
- Runtime: PHP 8.2.33, CodeIgniter 4.7.4, MySQL 8.4.11; app non privilegiata,
  1 GiB RAM, MySQL 512 MiB, limiti CPU invariati e 64 connessioni massime.

Suite FSE Linux: **273 test / 1.315 asserzioni**, senza errori o skip,
`evidence/atomic-unit.json` e relativo log con SHA-256. La differenza di un
test rispetto al checkout Windows deriva dal perimetro storico della base:
questa immagine non attesta tutte le modifiche contemporanee del repository.
I sei file dell'incremento risultano identici alle copie collaudate anche al
confronto finale. Non è stata ripetuta in questa build la navigazione browser
JavaScript del verbale precedente.

Otto scenari applicativi effettivi superati in MySQL, rapporto
`evidence/atomic-scenarios.json` con stato `passed`:

1. Preparazione e acquisizione del PDF con firma crittografica sintetica e audit.
2. Quattro modifiche concorrenti: una sola accettata, conflitti espliciti.
3. Due preparazioni concorrenti: una sola accettata.
4. Quattro richieste di revisione: tutte riferite alla stessa unica nuova bozza.
5. Arresto dell'app dopo INSERT di una nuova bozza, prima dell'audit obbligatorio.
6. Arresto dell'app dopo UPDATE di una bozza, prima dell'audit obbligatorio.
7. Arresto dell'app dopo UPDATE dei riferimenti PDF/CDA, prima dell'audit.
8. Arresto di MySQL dopo INSERT di una nuova bozza, prima dell'audit.

Il checkpoint intercetta l'audit nella **vera transazione del servizio**,
senza una transazione aggiunta dal test. Una lettura indipendente non vede
le nuove bozze non confermate. Arresti SIGKILL verificati come exit 137 e
non OOM; al riavvio, assenza di scritture parziali e conservazione di originali,
hash, riferimenti agli artefatti ed eventi già consolidati. Nel caso di
preparazione, rimane lo stato `preparing` già registrato prima del lavoro:
nessuno sblocco SQL o retry automatico viene applicato dal collaudo.

Stato finale: tre documenti e sette eventi nel tenant sintetico 42; tenant 43
vuoto e invariato. App e MySQL entrambi healthy prima dell'arresto finale,
invii produzione/Toscana stage disabilitati, **zero chiamate Gateway**.
Rapporti `evidence/atomic-final-state.json`, `evidence/atomic-healthy.json`
e `evidence/stopped.json`. App e MySQL arrestati alle 00:44 locali del
13 settembre; volumi, file privati e log conservati. Chiuso soltanto il
keepalive WSL di questa attività, senza arresto globale della distribuzione.

### Tentativi incompleti conservati e limiti

- Prima build `6a5bd4b1dceb4e28be4f106659f79f73`: BuildKit ha interpretato
  `FROM sha256:<ID locale>` come repository remoto e la risoluzione dei metadati
  è fallita. Nessuna build applicativa riuscita in quel tentativo. Correzione:
  alias locale univoco verificato contro l'ID prima e dopo la build; il runtime
  continua a usare soltanto l'ID immutabile. Nessun accesso al Gateway coinvolto.
- Primo avvio rifiutato correttamente mentre era presente un altro container
  della distribuzione condivisa. Non è stato arrestato da questa attività;
  avvio effettuato solo dopo una verifica in sola lettura senza container attivi.
- Primo avvio PHPUnit Linux: cache del writable generale assente, 273 errori
  di bootstrap prima delle asserzioni. Conservato `evidence/atomic-unit.log`.
  La ripetizione usa il bootstrap Linux già esistente, che crea un writable
  di test privato per esecuzione, e un nuovo log univoco. Solo la ripetizione
  riuscita è conteggiata come collaudo superato.

I nove test offline del nuovo controller fanno ora parte della raccolta
automatica: rifiutano ID/perimetri errati, copie o ricette alterate, ascendenza
e configurazioni runtime diverse e sovrascrittura di scenari già registrati.
Non eseguono WSL o Docker. Il controller operativo è
`ops/fse-validation/local-atomic-lab.py`; richiede build e istanza esplicite.

Questa attività non rilascia il codice, non aggiorna database reali, non abilita
gli invii e non completa alcun accreditamento. Le prove sintetiche e la CA di
test non equivalgono a firma qualificata, collaudo ufficiale o autorizzazione
alla produzione nazionale/regionale. Nessuna nuova comunicazione a Sogei,
Regione o cliente è stata inviata; soltanto il coordinamento interno autorizzato.
