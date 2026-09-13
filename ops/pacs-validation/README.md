# Laboratorio PACS/DICOM sintetico

Questa directory e i test sono esclusi dall'immagine di produzione.
Non avviare altri laboratori condivisi per eseguire queste prove. In presenza di un
laboratorio WSL di un altro task, coordinarsi prima e non arrestare container altrui.

## Suite senza rete o database reali

Da root repository, con le dipendenze Composer già installate:

```powershell
$env:XDEBUG_MODE='off'
php rest/vendor/phpunit/phpunit/phpunit -c ops/pacs-validation/phpunit.xml --exclude-group pacs_lab --fail-on-skipped --do-not-cache-result
```

Il bootstrap non carica .env e usa SQLite sintetico; il gruppo `pacs_lab` comprende
le tre prove Orthanc e la generazione esplicita della worklist di laboratorio.
Il trasporto operativo continua a rifiutare indirizzi privati e loopback: soltanto
la sottoclasse del test risolve pacs.example.test su 127.0.0.1, mantenendo TLS
verificato. Non esiste un bypass configurabile nel prodotto. Non pubblicare il
PACS su 0.0.0.0 per aggirare la separazione dei loopback.

## Collaudo DICOM reale

1. Eseguire `python ops/pacs-validation/prepare-lab.py` con Python e cryptography.
   Crea una directory casuale in `rest/writable/pacs-labs` e stampa solo percorso,
   nome progetto e porta. Manifest, password e certificati restano ignorati da Git.
   Il certificato scade dopo due giorni; storage tmpfs 128 MB, memoria massima
   512 MB e una CPU. Rigenerare il laboratorio alla scadenza.
2. Verificare libera la porta loopback 18443 e avviare esclusivamente il compose
   generato con il nome progetto stampato. In WSL tenere il compose collegato a
   una sessione del terminale: alcune installazioni arrestano WSL senza sessioni.
3. Montare questa copia del repository come `/workspace` in un runtime PHP CLI
   compatibile (8.2/8.3 con estensioni del progetto e dipendenze Composer).
   Usare un container temporaneo con accesso al loopback Linux del laboratorio;
   in WSL il loopback di Windows non raggiunge necessariamente quello Linux.
4. Nel runtime PHP eseguire, in sequenza:

```sh
python3 ops/pacs-validation/seed-lab.py rest/writable/pacs-labs/RUN_ID
PACS_SYNTHETIC_LAB=/workspace/rest/writable/pacs-labs/RUN_ID php rest/vendor/phpunit/phpunit/phpunit -c ops/pacs-validation/phpunit.xml --fail-on-skipped --do-not-cache-result --log-junit rest/writable/pacs-labs/RUN_ID/phpunit-linux.xml
```

Sostituire RUN_ID esclusivamente con la directory appena generata.
Il seed carica i due soli oggetti sintetici attraverso HTTPS/STOW.
Il test ordini usa il servizio applicativo per creare, confermare ed esportare
`worklists/af-synthetic.wl` e scrive gli identificativi attesi.

5. Costruire gli strumenti indipendenti:

```sh
docker build -f ops/pacs-validation/Dockerfile.mwl-tools -t af-pacs-mwl-tools:local ops/pacs-validation
```

6. Eseguire `check-worklist.py` in questa immagine, con entrypoint `python3`,
   repository montato in `/workspace`, working directory `/workspace` e
   `--network container:NOME_CONTAINER_ORTHANC_APPENA_CREATO`:

```sh
python3 ops/pacs-validation/check-worklist.py rest/writable/pacs-labs/RUN_ID
```

Il DICOM SCP non ha porte pubblicate: lo SCU condivide solo il namespace di rete
del suo container. Il controllo usa DCMTK, filtra gli identificativi e rinomina
temporaneamente il solo file sintetico per verificare il ritiro. Conserva
`worklist-result.json`; su errore termina con codice diverso da zero.
L'immagine base Orthanc è fissata per digest; la versione DCMTK effettiva viene
registrata nel risultato.

7. Arrestare espressamente il solo container/progetto appena creato. Non eseguire
   stop globali di WSL o Docker. I file sintetici e i rapporti possono restare nella
   directory ignorata per revisione.

## Preview delle viste

## Preflight MySQL sintetico

`prepare-mysql-lab.py` genera un MySQL dedicato con credenziali casuali, server_id
univoco, database `af_pacs_synthetic`, tmpfs e `network_mode: none`.
Avviare solo il compose generato, con il nome progetto restituito. In un runtime PHP
Linux temporaneo con mysqli, montare il repository in sola lettura e usare
`--network container:NOME_MYSQL_APPENA_CREATO`.
Impostare `PACS_MYSQL_LAB=/workspace/rest/writable/pacs-mysql-labs/RUN_ID` ed eseguire
`php ops/pacs-validation/mysql-smoke.php`. Il test controlla server_id e database,
richiede lo schema vuoto, usa le migration reali e quattro processi concorrenti.
Non legge .env e non permette host o nomi database arbitrari. Fermare il solo
container creato al termine; conservare stdout come evidenza dell'esito.

## Preview delle viste

`php ops/pacs-validation/render-preview.php` genera sette pagine HTML sintetiche in
`rest/writable/pacs-preview`. Servire solo quella directory su loopback, ad esempio
porta 18486, e chiudere il server al termine. Non servire la root del laboratorio:
contiene credenziali temporanee. I form della preview non eseguono azioni applicative.
