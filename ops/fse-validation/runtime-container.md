# Collaudo runtime Linux separato — NON produzione

Windows va bene per sviluppare e per la suite locale. Questa ricetta prepara un
secondo collaudo del solo validatore Linux, quando è disponibile un host Docker
isolato. Non modifica Coolify, il Dockerfile del prodotto, .env o database.
Al momento della preparazione Docker non è disponibile su questo PC: build e
avvio Linux **non eseguiti**. Non presentare il pacchetto come runtime qualificato.

## Preparazione da Windows

```powershell
./ops/fse-validation/prepare-container.ps1
```

Produce una nuova cartella sotto `rest/writable/fse-container-contexts`, ignorata
da Git. Copia solo validatore, dipendenze dichiarate, catalogo verificato,
compilatore verificato, JAR veraPDF e un CDA fittizio. Non include .env, database,
upload, chiavi, configurazione privata o radici fiduciarie. Ogni file ha SHA-256
nel manifest del pacchetto; la build ricontrolla gli hash. L'hash del JAR è una
fotografia dell'installazione locale, non una firma del distributore.

## Esecuzione su Docker isolato (da collaudare)

Usare come contesto **solo la cartella prodotta**, non l'intero repository.

```text
docker build --tag af-fse-runtime-test:local <cartella-prodotta>
docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges --pids-limit 192 --memory 1g --cpus 2 --tmpfs /tmp:rw,nosuid,nodev,size=256m,mode=1777 af-fse-runtime-test:local
```

La build necessita rete per immagine base, pacchetti Java e wheel Python; il test
non ha rete né volumi del gestionale. L'utente nel container non è root. La root
è di sola lettura; i temporanei sono volatili e limitati. Vincoli descritti nella
[documentazione Docker](https://docs.docker.com/engine/containers/run/).

L'output atteso è `passed_runtime_smoke`: CDA, PDF/A, rifiuto XML pericoloso e
assenza intenzionale delle radici fiduciarie. Non prova firma clinica effettiva,
applicazione PHP/Linux, DB MySQL, concorrenza multiprocesso su server o Gateway.

La base è [Python 3.12 slim Bookworm](https://github.com/docker-library/python/blob/master/3.12/slim-bookworm/Dockerfile).
Le versioni Python derivano dal lock Windows: la build Linux può fallire se una
wheel non è disponibile; non aggirare un errore aggiornando dipendenze a caso.
Prima della qualifica fissare digest della base con `FSE_PYTHON_IMAGE`, inventario
pacchetti, scansione vulnerabilità, revisione licenze e lock Linux verificato.

Il prossimo passaggio server resta integrare e collaudare **l'app completa** in
staging dedicato: PHP 8.2, migration, sessioni/CSRF, storage privato e permessi,
gestione processi orfani, revoche e firma scelta dalla struttura. Un semplice
deploy del codice non installa né abilita questo runtime.
