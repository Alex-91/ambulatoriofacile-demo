# FSE — browser Linux, concorrenza e interruzioni controllate

Collaudo preparatorio del 12 settembre 2026. Non costituisce accreditamento,
collaudo regionale ufficiale, firma qualificata o abilitazione agli invii.
Nessun dato di paziente reale, database live, storage produttivo, certificato
Sogei o endpoint esterno è stato usato in queste prove. Nessun deploy.

## Perimetro e provenienza

Il browser è l'in-app browser su Windows; **l'applicazione gira su Linux** in
WSL/Docker. Non si tratta di un browser installato in Linux né di una matrice
completa di browser/dispositivi. È stata usata la skill computer-use per azionare
l'interfaccia e osservare le risposte effettive, compreso il login JavaScript e
il caricamento dei PDF tramite file chooser. Le prove HTTP e CLI sono distinte
dal percorso UI e non lo sostituiscono.

Immagine applicativa immutabile:
`sha256:10c1973e8bc302115429402e2de5cd48bd38a550fcc6b33b98f50d6fff490b3a`.
MySQL:
`sha256:8c19b656bb381f163750b238852bd377ba5764e1ec30cdd3f02e55cf8e2f89b7`.
È la candidata precedente già costruita, **non il checkout corrente**:
le modifiche contemporanee di altre task non sono attestate da questo verbale.
Codice prodotto nell'immagine non modificato; harness supplementari copiati
separatamente nei volumi privati e censiti con SHA-256 in `instance.json`.

Tre database inventati per istanza: piattaforma, studio A (42), studio B (43).
Nuovi progetti Compose e volumi distinti dalle prove precedenti. Rete interna,
nessuna porta Docker pubblicata, nessun bind mount del codice o dei segreti,
app non privilegiata `33:33` con filesystem base in sola lettura. Invii reali
disabilitati. RAM massima app 1 GiB e MySQL 512 MiB, CPU 1 e 0,5.

Il collegamento del browser era un relay temporaneo su **127.0.0.1:8088**,
limitato alla medesima origine e all'ID esatto del container sintetico. Conserva
cookie/CSRF e non scavalca il login. Nessuna modifica a firewall, inoltro porte
WSL o configurazione produttiva. La porta 8085 della demo congelata è esclusa.

## Punto 1 — percorso effettivo nel browser

Istanza: `de15f9dbb59e48b58135360c52b1b59c`.
Directory privata delle evidenze:
`rest/writable/fse-linux-ui-resilience/de15f9dbb59e48b58135360c52b1b59c/evidence/`.

| Operazione osservata | Esito |
| --- | --- |
| Login normale studio A, creazione referto inventato #1 | Bozza salvata |
| Generazione CDA e PDF dall'interfaccia | Stato «Da validare» |
| Upload del PDF alterato | Rifiutato: `SIGNED_DOCUMENT_CONTENT_CHANGED` |
| Upload del PDF correttamente firmato con CA sintetica | Stato «Firmato», campi clinici disabilitati |
| Collegamento al laboratorio Toscana separato | Snapshot del firmato, hash coerente |
| Richiesta simulata, ricezione non finale, conferma positiva | Solo «Pubblicazione simulata»; originale ancora «Firmato» |
| Correzione con motivo, nuova versione #2 | Originale invariato, nuova bozza modificabile |
| Modifica e rigenerazione della versione #2 | «Da validare», CDA con collegamento `RPLC` all'originale |
| Upload sulla revisione del PDF firmato della versione #1 | Rifiutato: `PDF_CDA_MISMATCH` |
| Login studio B | Zero referti |
| Accesso diretto da B all'URL del referto #1 di A | Ritorno alla lista vuota, nessun dato di A visibile |
| Rientro in A dopo il recupero dei servizi | #1 ancora firmato e bloccato, storico #2 presente |

Firma di prova generata fuori dal browser con l'apposita fixture: CA effimera,
CRL sintetica, nessuna chiave di firma salvata e nessun servizio qualificato reale.
Le azioni sul gestionale sono state effettuate dall'interfaccia.

Audit successivo in sola lettura: **15 controlli superati**, due referti in A,
zero in B, sei eventi, integrità di cinque artefatti, nessuna pubblicazione reale.
Rapporto `browser-state-7eea905fded725df.json`, SHA-256
`c16e534539e39e338abd14cc03517cb70311dcdaefb76a6492f4bc248b105727`.
Firmato #1: `e115a45cbd286200d8c161f075e5d990d27431d4e313517ebfbf87a3d0291b1e`.

Console: nessun errore JavaScript rilevato; avvisi ripetuti per il service worker
PWA del login, endpoint deliberatamente non esposto dal router di laboratorio
(404). Installazione PWA non collaudata. Lo screenshot della revisione rifiutata
è conservato; la cattura full-page nella viewport stretta presenta ripetizioni
di stitching e non è usata come attestazione della qualità grafica complessiva.

## Punto 2 — letture concorrenti e riavvii ordinati

Sul medesimo laboratorio UI: **4 sessioni indipendenti, 40 letture autenticate**
dei referti, distribuite fra A e B. Tutte riuscite. Durata 4,442 s; mediana 0,375 s,
95° percentile 0,457 s, massimo 0,481 s. Sono tempi interni al laboratorio e non
una promessa di capacità o latenza del servizio in produzione.
Rapporto `http-load-1789232824964728132.json`, SHA-256
`bdc21b2f37673172a82ac7a12b5ee445bdd0ae79dc549b024c702e796d4bb335`.

Confronti `before-load.json`, `after-load.json`, `after-app-restart.json` e
`after-paired-restart.json`: stessi hash delle righe, audit e cinque artefatti.
Arresto ordinato app con init e inoltro segnali al gruppo: exit 143 (SIGTERM),
non 137; MySQL exit 0. Nessun OOM rilevato.

### Anomalia del laboratorio trovata e gestita

L'app usa `network_mode: service:mysql`. Il riavvio isolato di MySQL cambia il
namespace: inizialmente la porta web risultava disponibile ma il database non
era raggiungibile e il browser mostrava un errore database. **Quel solo controllo
di salute era insufficiente**, non è contato come successo end-to-end.

Riavviando anche l'app dopo MySQL, l'accesso e i dati sono tornati disponibili;
è stato effettuato un nuovo login. Non si attesta conservazione della sessione
durante l'interruzione. Il controller del nuovo laboratorio verifica anche la
connettività ai database tenant; il nuovo healthcheck controlla entrambe le porte.
Il comando di riavvio MySQL ora riallinea anche l'app dello stesso progetto.
Questa è una proprietà del laboratorio condiviso, non una modifica al deploy
produttivo Coolify.

## Punto 2 — collisioni e interruzioni durante le operazioni

Esecuzione completata su istanza nuova `bdcdeb4af9c748549b25f988e47b90a7`.
Rapporto privato `evidence/resilience-summary.json`: **sei scenari superati**.
Queste prove invocano i servizi PHP reali in processi Linux concorrenti, con una
barriera di partenza; non sono richieste UI e non sono serializzate dal cookie
di un'unica sessione.

| Scenario | Esito verificato |
| --- | --- |
| 4 salvataggi con lo stesso edit token | Un solo aggiornamento e un solo nuovo evento; tre richieste respinte con errore di conflitto |
| 2 generazioni sullo stesso referto | Una riuscita, l'altra bloccata durante l'elaborazione |
| 4 richieste di revisione dell'originale firmato | Tutte restituiscono lo stesso figlio #2; originale immutato |
| 4 processi × 10 nuove bozze su due tenant | 20 nuove righe e 20 eventi per studio, nessun ID duplicato nel relativo database |
| SIGKILL dell'app dopo il lock di preparazione, prima degli artefatti | Contenuto e audit della bozza conservati; due tentativi successivi di sovrascrittura/rigenerazione bloccati; originale firmato e suoi tre artefatti invariati |
| SIGKILL MySQL con bozza e audit in transazione non confermata | Scrittura invisibile da una seconda connessione prima del crash; dopo ripartenza, riga e audit non confermati assenti e intero stato già confermato invariato |

Stato finale: **43 referti sintetici confermati**, 23 in A e 20 in B; 26 eventi
in A e 20 in B. Eventuali buchi nella numerazione AUTO_INCREMENT dopo conflitti
o rollback non sono documenti perduti. I due SIGKILL hanno exit 137 e
`OOMKilled=false`; sono interruzioni deliberate, non esaurimenti di memoria.
Il comando corretto di riavvio coordinato è stato poi provato nuovamente:
`before-integrated-restart.json` e `after-integrated-restart.json` coincidono,
insieme allo stato finale della suite.

Il fault di preparazione è iniettato mediante una dipendenza PDF di test che
si ferma in un punto noto; non si modifica il codice prodotto. Il fault MySQL
usa una **transazione esplicita del test attorno al servizio**: prova il rollback
di quella transazione, non dimostra l'atomicità di ogni sequenza applicativa
possibile fra salvataggio del referto e scrittura dell'audit.

La bozza interrotta #26 resta intenzionalmente in `preparing`, senza sblocco SQL,
rigenerazione o reinvio automatico. Il controllo immediato restituisce
`LOCAL_CHECK_RUNNING`, `retry_allowed=false`; non è stato dichiarato un recupero
automatico dell'operazione. In un caso reale è necessaria la verifica assistita
di processo, artefatti e audit. Non si attesta resistenza a ogni istante di
blackout, guasto disco o interruzione verso un Gateway esterno.

### Tentativo preliminare conservato, non contato come superato

Istanza `c47afee88ff2460fb358bb2a74a905c7`: prima barriera incompleta perché il
limite MySQL di **20 connessioni** è stato raggiunto dai processi in attesa e dai
controlli del laboratorio. Il log identifica `Too many connections` durante
la verifica dello schema; non era un errore di edit token. Nessuno scenario è
contato come superato in quel rapporto. Istanza fermata senza reset dei dati.

L'esecuzione completa usa **64 connessioni massime**, sempre con gli stessi
limiti di RAM/CPU e quattro processi. Questa correzione riguarda la ricetta
del laboratorio; non è una certificazione di dimensionamento della produzione.

## Strumenti e ripetibilità

Script aggiunti in `ops/fse-validation/`: `linux-ui-resilience.py`,
`linux-ui-relay.py`, `linux-ui-resilience-worker.php`,
`linux-ui-resilience-http.py`, `linux-resilience-scenarios.py` e
`test_linux_ui_resilience.py` e `verify-linux-ui-resilience.py`.
Il relay è temporaneo, non un server di produzione.
`prepare --purpose browser|resilience` crea una nuova istanza; `start ID` rifiuta
la presenza di altri container attivi e di istanze già inizializzate. I comandi
operano solo su ID, immagini, progetti e reti verificati. `stop ID` conserva i
volumi. Non effettuare `down -v`, reset SQL o riuso della demo congelata.

Le evidenze non vanno sovrascritte. Una prova interrotta si conserva come tale e
si ripete su un'istanza nuova. Il runner dei fault non deve essere lanciato su
un laboratorio UI o un database popolato. Nessun risultato di questi script
deve essere descritto come test case ufficiale FSE.

Sette nuovi test di sicurezza dell'harness superati; discovery allargata
`test_linux*.py`: 47 esecuzioni riuscite, con cinque test dei manifest immagini
caricati due volte (non 47 casi distinti). PHP lint del worker superato.
Il verificatore offline confronta manifest, hash, stati, rapporti e arresti;
non riesegue né certifica autonomamente le azioni browser.

Verifica finale: **27 riscontri sulle evidenze superati**. Tutte e tre le istanze
create da questa task sono ferme, volumi conservati; nessun container attivo al
controllo finale, relay terminato e porta 8088 non più in ascolto. Non sono stati
cancellati referti o rapporti né arrestati ambienti di altre task.
Il rapporto conclusivo è `bdcdeb4af9c748549b25f988e47b90a7/evidence/verification-final.json`
sotto la directory privata `rest/writable/fse-linux-ui-resilience/`.

Riferimenti tecnici: [init e servizi Compose](https://docs.docker.com/reference/compose-file/services/#init)
e [worker del server PHP di sviluppo](https://www.php.net/manual/en/features.commandline.webserver.php).
L'uso di quattro worker riguarda solo il server di collaudo, non un'architettura
raccomandata per produzione.
