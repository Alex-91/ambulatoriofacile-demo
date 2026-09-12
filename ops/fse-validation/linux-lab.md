# Laboratorio applicativo FSE Linux

## Collaudo locale completato il 12 settembre 2026

Dopo il riavvio, il laboratorio è stato costruito ed eseguito nella distribuzione
WSL dedicata. Suite PHP/Python, HTTP FSE, fatturazione, cartella clinica e
ripristino FSE superati con dati sintetici; nessuno skip nelle suite finali.
Le istanze sono ferme e le evidenze conservate. Nessun deploy in produzione.
Il contesto finale è `1b6da77e72a44672af463349c28adec6`, con 4.108 file app/51 runtime;
il contesto `819fda12284342c38873010c8648bc62` è la seconda istanza runtime clinica
che riutilizza l'immagine verificata. [Verbale e limiti del collaudo](../../rest/docs/collaudo-linux-gestionale.md).

La sezione seguente conserva la preparazione storica, precedente al riavvio.

## Preparazione storica prima del riavvio

Pacchetto privato per provare il **modulo FSE** con CodeIgniter, MySQL nuovo e
due studi interamente fittizi. Non è lo staging completo del gestionale, non è
un collaudo Linux superato e non attribuisce accreditamento nazionale o regionale.
Nessun certificato Sogei, chiave privata, `.env`, dump o upload viene incluso
dalla procedura di copia. I sorgenti devono comunque essere revisionati prima
di trasferirli su un altro host.

Nuovo contesto candidato:
`rest/writable/fse-linux-labs/72ccd6c3fbff4c21983112d378140da1`.
4.080 file applicativi selezionati, 51 file runtime; framework 4.7.4 e lock root
candidato dai laboratori già provati su Windows. Controllo manifest, ricette e
corrispondenza con le sorgenti locali superato: `PREPARATION_CHECK_PASSED_NOT_STARTED`.
Non costruito, trasferito o avviato. Versioni attive, vendor e produzione invariati.

Il 12 settembre sono stati ricontrollati gli accessi: la chiave SSH dedicata è
rifiutata e la seconda chiave disponibile è in formato non utilizzabile. Nessuna
modifica a chiavi o firewall. Docker/Podman ancora assenti sul PC. Nel prosieguo
è stato installato **WSL 2.7.14.0**, con VirtualMachinePlatform abilitato e limiti
di risorse. Windows richiede ora un **riavvio manuale**; nessuna distribuzione
Linux o Docker è ancora installata. [Preparazione e ripresa WSL](wsl-lab.md).
La capacità riportata sotto è ancora la misura storica dell'11 settembre;
**nessuna nuova capacità disponibile è stata attestata**.

### Verifica della capacità Coolify

Ambiente dedicato creato: `fse-precollaudo`, UUID
`rml98w8xb4k4x38hqwr1602q`, progetto AmbulatorioFacile esistente.
Non contiene ancora il laboratorio, database FSE o storage applicativi.

Una sonda temporanea Compose, senza rete, porte o volumi, ha letto alle
**16:39:06 UTC dell'11 settembre** questi valori dai log del container:

| Misura | Valore osservato |
| --- | --- |
| Architettura / CPU dichiarate dal pannello | x86_64 / 2 |
| MemTotal | 3.900.700 KiB (circa 3,72 GiB) |
| MemAvailable | 875.296 KiB (circa 855 MiB) |
| Swap totale / disponibile | 4.194.300 / 1.006.656 KiB |
| Carico 1 / 5 / 15 minuti | 1,38 / 1,24 / 1,12 |
| Spazio disponibile sul filesystem overlay della sonda | 48.548.136 KiB (circa 46,3 GiB) |

Sono misure puntuali, non una serie temporale né l'inventario di tutti i dischi.
Il laboratorio prevede fino a 1,5 GiB per app e MySQL, oltre all'inizializzazione
e al costo della build. **Margine insufficiente per procedere prudentemente sul
server condiviso:** build, creazione DB e avvio del laboratorio non effettuati.
Non sono stati ridotti limiti o fermati servizi esistenti per liberare risorse.

Risorse diagnostiche create in questo task (nessun dato applicativo):

- Applicazione `yok8wnxgw6kq8v6pvqfqi81b`: tentativo iniziale fallito prima della
  creazione del container runtime; richiesta di stop eseguita.
- Servizio `sykdniym9ssvrsoqwfk317ib`: sonda Compose riuscita; richiesta di stop
  eseguita dopo la lettura. Nessun volume. `restart: no`; comunque termina dopo
  cinque minuti. Configurazione mantenuta per tracciabilità.

Il tentativo Dockerfile ha rivelato la conversione errata di `security-opt`
da parte di Coolify. Il relativo script rifiuta ora Prepare/Start. La sonda
Compose mantiene direttamente utente non privilegiato, rete assente, filesystem
sola lettura, capability rimosse, 64 MiB / 0,1 CPU / 16 PID nella configurazione
generata. La sola configurazione non equivale a un'attestazione runtime completa.
Riferimento: [segnalazione ufficiale Coolify #8173](https://github.com/coollabsio/coolify/issues/8173).
Nessun aggiornamento globale di Coolify eseguito. La chiave SSH locale è rifiutata;
il terminale web non si collega. Non sono state modificate chiavi o firewall.

## Preparazione ripetibile su Windows

```powershell
./ops/fse-validation/prepare-linux-lab.ps1
./rest/writable/fse-validation-venv/Scripts/python.exe ./ops/fse-validation/test_linux_bundle.py -v
```

Per includere i due aggiornamenti candidati insieme (senza modificare il sistema
attivo), passare `-FrameworkLab <lab-framework-marcato>` e
`-DependencyLab <lab-dipendenze-marcato>` a `prepare-linux-lab.ps1`. Entrambi sono
obbligatori se si sceglie la variante candidata; il comando verifica marcatori,
versione e hash dei lock. Senza opzioni il contesto è **baseline**, non aggiornato.
Non passare cartelle vendor generiche o laboratori ricostruiti senza evidenze.

Ogni preparazione crea una cartella nuova `rest/writable/fse-linux-labs/<id>`;
non sovrascrive i tentativi precedenti. `PREPARED_NOT_BUILT` e `NOT_EXECUTED`
sono stati intenzionali, non prove superate. Il contesto contiene sorgenti
selezionati, dipendenze bloccate, cataloghi e manifest SHA-256. Il verificatore
rifiuta file alterati, aggiunti fuori manifest, percorsi impropri e symlink.
I manifest attestano corrispondenza dei file, non assenza di vulnerabilità o
qualifica della catena distributiva.

### Controllo preventivo aggiunto dopo la prima preparazione

I nuovi contesti includono `.dockerignore`, che limita il contesto di build ad
app, runtime e Dockerfile. Per controllare il pacchetto appena generato:

```powershell
./rest/writable/fse-validation-venv/Scripts/python.exe ./ops/fse-validation/check_linux_recipe.py <contesto-generato>
```

Verifica manifest e corrispondenza delle ricette con i sorgenti locali esaminati;
rifiuta modifiche al Compose (comprese porte pubbliche, volumi esterni e flag di
invio), file mancanti o alterati. Ora verifica anche che **ogni file incluso**
corrisponda alla sorgente locale selezionata registrata nel manifest, rifiutando
pacchetti vecchi anche se internamente coerenti. Non confronta file esclusi o
nuovi file non censiti e non attesta autenticità upstream. Dopo modifiche al
codice rigenerare il contesto; i vecchi manifest senza provenienza sono rifiutati.
I nomi degli asset retina `@2x` sono ammessi con una regressione dedicata, senza
rilassare i controlli su path, symlink o origine. 35 test packaging/harness/audit
superati, incluse verifiche dei manifest immagini e allineamento del lock al
Dockerfile; nessuno esegue Docker, HTTP reale o accreditamento.

Le opzioni facoltative `--app-image` e `--mysql-image` vanno fornite insieme,
solo quando l'immagine applicativa sarà costruita: rifiutano tag mutabili. MySQL
deve corrispondere a `images.mysql` di `linux-images.lock.json`. Il controllo
è **del formato**, non verifica l'esistenza, il contenuto o la provenienza delle
immagini. Non chiama Docker, non modifica file, non ricontrolla la capacità del
server e non avvia risorse. L'esito `PREPARATION_CHECK_PASSED_NOT_STARTED` non è
un'autorizzazione a deploy né un collaudo Linux superato.

I test delle ricette sono ora inclusi in `collect-evidence.ps1` con rapporto
separato `linux-package-tests.json`, sempre marcato `NOT_EXECUTED` per Linux.
Un loro fallimento impedisce l'esito complessivo `passed_offline`.

## Passaggi ancora da eseguire su un host Docker idoneo

1. Sul PC Windows proseguire dopo il riavvio necessario descritto in `wsl-lab.md`;
   in alternativa concordare un host separato, senza acquisti automatici.
   Misurare nuovamente risorse, concordare accesso operativo e trasferimento
   privato del solo contesto. Non aprire DB/porte pubbliche o copiare storage live.
2. Revisionare dipendenze, licenze e immagini base. I digest immutabili di PHP,
   Composer e MySQL sono ora risolti dal registry ufficiale e registrati in
   `linux-images.lock.json`; il Dockerfile applicativo usa quelli di PHP e
   Composer. Restano pull dei layer, verifica delle immagini effettive e build.
   Manifest verificati non equivalgono a firme del publisher o supply chain
   qualificata; i pacchetti `apt` non sono congelati a uno snapshot storico.
3. Costruire il pacchetto fuori dalla produzione. Python Debian/Linux e wheel
   devono essere verificati: la risoluzione preliminare da Windows è superata,
   ma il lock originario deriva da Windows e il runtime non è ancora provato. Non rimuovere
   verifiche o aggiornare dipendenze alla cieca per superare un errore.
4. Validare `compose.yaml` con Docker Compose. Impostare `FSE_APP_IMAGE` e
   `FSE_MYSQL_IMAGE` con gli ID/digest verificati. Il template ne richiede la
   presenza, ma non convalida da solo il formato SHA-256.
5. Avviare solo il progetto generato: inizializzatore una tantum, nuovi volumi
   `lab-state` e `lab-mysql`, MySQL sulla rete interna, app nel medesimo namespace
   di rete con listener su loopback 8088. Nessuna porta pubblicata.
6. Eseguire esplicitamente `app-lab-seed.php` all'interno del container app:
   controlla il datadir e rifiuta un lab già inizializzato. Gli account e le
   password casuali restano nel volume privato; nessuna password in comandi/log.
7. Portare ed eseguire il collaudo HTTP/CSRF/sessioni, separazione tenant, artefatti,
   guasti e ripristino già provato su Windows. Alcuni orchestratori Windows
   richiedono ancora adattamento: non dichiarare equivalenza Linux prima della prova.
   Il semplice healthcheck TCP attesta solo il listener, non seed o collaudo.
8. Raccogliere versioni, digest, esiti, log redatti e verifiche runtime di limiti,
   reti e mount. Fermare solo questo progetto mantenendo le evidenze; nessun
   `prune` o eliminazione di volumi globali.

Non incollare il Compose in una risorsa che aggiunga reti/volumi della produzione.
Su Coolify revisionare il Compose **generato**, non soltanto quello inserito.
La guida descrive una ricetta ancora da collaudare, non un invito ad avviarla sul
server attuale nonostante la verifica di capacità negativa.

Firma clinica qualificata, adapter CART effettivo, prove ufficiali, accreditamenti
e attivazione dei clienti rimangono percorsi separati. Tutti gli invii reali
restano disabilitati.
