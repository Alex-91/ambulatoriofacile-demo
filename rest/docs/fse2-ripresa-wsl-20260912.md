# FSE: ripresa dopo il riavvio — 12 settembre 2026

## Verifiche effettive su Windows e Debian

Riavvio Windows rilevato alle 16:25:40 locali. Il preflight della ripresa ha
superato il blocco sul riavvio pendente e verificato la configurazione WSL
esistente: circa 8,26 GiB RAM libera e 71,98 GiB liberi su C: al controllo.

La distribuzione dedicata `AmbulatorioFacile-FSE` è stata osservata registrata
come WSL 2 e avviata con successo: Debian 13 (trixie), x86_64, systemd,
kernel WSL `6.18.33.2-microsoft-standard-WSL2`. Rilevati 2.909 MiB RAM e
1.024 MiB swap; filesystem da 40 GiB con circa 37 GiB disponibili. Sono
misure puntuali, non una garanzia di capacità futura.

Due task sullo stesso checkout stavano riprendendo contemporaneamente il
laboratorio. Dopo aver rilevato l'installazione Docker già attiva nella task
**Analizza requisiti cliente**, le lavorazioni sono state coordinate: quella
task controlla in esclusiva installazione, build, avvio e arresto del laboratorio
Linux. Non eseguire in parallelo altri bootstrap Docker, cambi di branch o
modifiche ai sorgenti coperti dai manifest. Nessun deploy autorizzato da questa
ripresa; nessun database o storage produttivo utilizzato.

Il tentativo `wsl --install` di questa task ha restituito un errore di connessione
`0x80072eff`; l'effettiva disponibilità della distribuzione è attestata dai
controlli successivi, non dall'esito di quel comando. Un secondo tentativo di
bootstrap Docker si è fermato sul lock apt dell'installazione già in corso:
nessun processo terminato e nessun lock rimosso. I due file di bootstrap
duplicati creati qui sono stati rimossi, mantenendo lo script dell'altra task.

Snapshot aggiuntivo solo preparato, non trasferito o avviato da questa task:
`rest/writable/fse-linux-labs/d0aebd9639024f56a36f2ec2dbcb6f25`, variante
candidata, 4.092 file applicativi. Non è necessariamente il contesto finale
scelto per il collaudo integrato: usare quello riportato nelle relative evidenze.

## Avviso nazionale più recente effettivamente letto

Il 12 settembre è stato consultato in sola lettura il thread Slack dell'11
settembre: alla domanda di un fornitore su come procedere presso i clienti
durante la sospensione, Michela Vigliotti risponde alle 14:42:58 che l'attivazione
del processo presso i clienti non è possibile fino al riavvio e invita ad
attendere le indicazioni del supporto. Questo messaggio non è una deroga
normativa né un chiarimento sulle condizioni di rimborso del bando regionale.

[Risposta del supporto dell'11 settembre](https://developersitalia.slack.com/archives/C03RDT88FSM/p1789130578216499?thread_ts=1789130363.564639&cid=C03RDT88FSM).
La domanda successiva di un partecipante su una comunicazione formale/deroga
non aveva risposta nel thread osservato (due risposte totali).

Anche il [repository nazionale consultato](https://github.com/ministero-salute/it-fse-accreditamento)
mostra ancora l'avviso del 14 luglio 2026 sulla dismissione e il rinvio a Slack.
Non è stata presentata alcuna PR o dichiarazione di conformità in questa ripresa.
La ricerca Slack è circoscritta ai messaggi FSE successivi all'8 settembre con
termine accreditamento; non costituisce una lettura integrale di tutti i canali.
Non sono stati inviati messaggi, reazioni o allegati su Slack.

## Toscana

La [pagina Toscana Compliance consultata](https://compliance.toscana.it/portale/it/scenari/fse-2-0-strutture-private/)
mantiene il percorso con autorizzazione regionale, adesione CART e abilitazione
del certificato di test. Per la produzione richiede accreditamento regionale
e prove dei quattro metodi in staging, con i relativi X-CART-id. I nostri test
locali non producono questi identificativi ufficiali e i certificati nazionali
di test non attestano un'abilitazione CART.

Restano distinte la preparazione software, la firma clinica reale, il collaudo
di rete autorizzato, l'accreditamento formale e l'attivazione del singolo impianto.
Nessuna nuova risposta email regionale è stata fornita o verificata in questa
ripresa. Dettagli tecnici già censiti in [preparazione Toscana](fse2-toscana-preparazione.md).

## Esiti Linux

### Regressione Windows indipendente dopo le modifiche cliniche

Suite avviata alle 17:12:12 locali: **321 test superati** (262 PHP con 1.277
asserzioni, 24 documentali Python, 35 packaging/harness/audit). Zero
errori/fallimenti/skip; `passed_offline` e `source_unchanged_during_run=true`.
Report: `rest/writable/fse-validation-reports/20260912-171212-877c511e/summary.json`.
Anche il confronto dei sorgenti censiti subito dopo la lettura del report non
ha rilevato variazioni. La correzione della configurazione Compose segnalata
dall'altra task era quindi già compresa nello snapshot della prova; non è
stato ignorato il controllo di immutabilità. Framework candidato, PHP Windows
8.3.6, vendor root attivo: non equivale al collaudo dell'immagine Linux.

Ripetuta la regressione **dopo le correzioni finali all'harness Linux**, alle
17:57:13 locali: ancora **321 test superati** (262 PHP / 1.277 asserzioni,
24 Python e 35 packaging/harness/audit), zero errori/fallimenti/skip.
Report aggiornato:
`rest/writable/fse-validation-reports/20260912-175713-9b7de61b/summary.json`,
`passed_offline`, `source_unchanged_during_run=true`. Anche il confronto dei
sorgenti censiti dopo la conclusione ha rilevato zero variazioni. Questa è
l'ultima evidenza Windows; restano invariati piattaforma e perimetro indicati.

### Avanzamento comunicato dalla task Linux

Aggiornamento ricevuto dalla task esecutrice: Docker Engine 29.8.0 e Compose
5.5.1 installati; contesto candidato
`rest/writable/fse-linux-labs/1b6da77e72a44672af463349c28adec6`, 4.105 file app
e 51 runtime, trasferito nella distribuzione dedicata sotto
`/opt/af-fse-labs/1b6da77e72a44672af463349c28adec6`. Questa è la preparazione
integrata che sostituisce lo snapshot aggiuntivo non avviato di questa task.

La prima build/pull ha incontrato errori di rete Docker Hub. Successivamente
la task ha comunicato build completata, immagine
`sha256:37f977c959b455396dc4d9ae5081063fc56b47b5da912f01a753bdfce0c6eb83`,
PHP 8.2.33, Python 3.11.2, OpenJDK 17, `pip check` e smoke non privilegiato
senza rete superati. Inizializzatore completato e MySQL inizialmente healthy,
poi arrestato prima del seed: diagnosi in corso. È stato corretto l'escape
`$$s` del healthcheck PHP in Compose. Nessun esito applicativo Linux ancora
raccolto in questo verbale; i resoconti non sostituiscono i report finali.

La task esecutrice ha confermato l'arresto automatico di WSL tra le invocazioni:
uptime di cinque secondi, MySQL con exit 0 e senza OOM, arresto da segnale e
daemon riavviato. Mantiene ora una sessione foreground dedicata per tutta la
durata dei test. I due tenant sintetici sono stati creati e le suite Linux
sono partite. Il primo indizio indipendente era dockerd appena ripartito.
[Microsoft documenta](https://learn.microsoft.com/en-us/windows/wsl/systemd)
che i servizi systemd da soli non mantengono attiva la distribuzione.
Non sono stati cambiati i timeout globali WSL o le protezioni per questa verifica.

### Controllo indipendente dell'isolamento runtime

Fotografia **intermedia**, non lo snapshot finale: l'app `2e1308e7c829` è stata
successivamente ricreata durante le correzioni del laboratorio. La task
esecutrice raccoglierà identità immagine/container e isolamento definitivi.

Ispezione Docker in sola lettura dopo il seed, limitata al progetto
`af-fse-lab-1b6da77e72a44672af463349c28adec6`; nessuna azione su container,
volumi, rete o dati applicativi. Stato osservato: app e MySQL `healthy`.

| Controllo | Risultato osservato |
| --- | --- |
| Immagine app | `sha256:37f977c959b455396dc4d9ae5081063fc56b47b5da912f01a753bdfce0c6eb83` |
| App | UID/GID `33:33`, root filesystem read-only, tutte le capability rimosse, no-new-privileges |
| Limiti app | RAM 1 GiB, nessuna swap aggiuntiva, 1 CPU, 192 PID |
| Limiti MySQL | RAM 512 MiB, nessuna swap aggiuntiva, 0,5 CPU, 128 PID; processo mysqld UID 999 |
| MySQL | Tutte le capability rimosse salvo CHOWN/DAC_OVERRIDE/FOWNER/SETGID/SETUID; no-new-privileges; root filesystem non read-only |
| Porte | Nessuna pubblicazione per app o MySQL |
| Rete | `612fbdb6a439`, `Internal=true`; app nel namespace di rete MySQL |
| Invii reali | `FSE2_ALLOW_PRODUCTION=false`, `FSE2_ALLOW_TOSCANA_STAGE=false` nell'ambiente effettivo dell'app |
| Mount | Volumi Docker locali dedicati; nessun bind di directory host o socket Docker nei mount ispezionati |

L'immagine MySQL crea inoltre un volume anonimo a `/var/lib/mysql`, oltre ai due
volumi nominati Compose. Identificativo effettivo:
`146a46ae941913ed0d6f6fd27972038b4efd0f59188c2018af15f1c168ee6a1f`, driver local,
etichetta `com.docker.volume.anonymous`. Va incluso nell'inventario del lab;
non è stato cancellato. Il datadir previsto dal seed rimane quello personalizzato
nel volume nominato, non quello predefinito. Questo controllo non legge il
contenuto del volume né attesta un backup completo.

L'inizializzatore `e46741efcfd5` risulta exit 255 perché, dopo il primo successo
registrato nei log, un nuovo avvio ha correttamente rifiutato di inizializzare
di nuovo il volume già esistente. Non presentare questo codice come fallimento
del primo seed; non aggirare la protezione cancellando marcatori o dati.

### Errori intermedi conservati nelle evidenze

La task esecutrice riferisce un giro unit Linux con 261 test / 1.128 asserzioni,
due errori e 13 fallimenti: fixture `gateway-negative-jwt.php` e `ui-preview.php`
non incluse nel pacchetto, più la guardia del worker di concorrenza che non
riconosceva il percorso Linux marcato. Sono lacune del pacchetto/harness emerse
nel collaudo; la task sta correggendo copia delle fixture, cache e perimetro
del worker e ricostruendo l'immagine con manifest aggiornati. Nessuna asserzione
rimossa e nessun PASS Linux complessivo dichiarato. Le modifiche successive
all'harness non sono coperte retroattivamente dalla suite Windows delle 17:12.

### Evidenze Linux finali lette e verificate

Alle 17:50 locali la task esecutrice ha esportato i rapporti sotto
`rest/writable/fse-linux-labs/1b6da77e72a44672af463349c28adec6/evidence/`.
Questa task ha letto i rapporti effettivi e gli snapshot Docker esportati,
senza riavviare servizi o ispezionare altri container intermedi.

Immagine applicativa finale attestata da `tested-image.txt`, dal log della
build e da `runtime.json`:
`sha256:10c1973e8bc302115429402e2de5cd48bd38a550fcc6b33b98f50d6fff490b3a`,
`linux/amd64`. Il contesto candidato comprende 4.108 file applicativi e 51
runtime. Ripetuto qui in sola lettura il controllo `check_linux_recipe.py`
con i riferimenti immutabili app/MySQL: manifest, ricette e corrispondenza
delle sorgenti selezionate superati. Questo verificatore controlla solo il
formato dei riferimenti immagine: l'esecuzione Linux è documentata dalle
altre evidenze, non dal suo stato `PREPARATION_CHECK_PASSED_NOT_STARTED`.

| Prova | Esito letto | Evidenza |
| --- | --- | --- |
| PHPUnit filtro Fse | 261 test / 1.274 asserzioni, OK | `unit-final.log` |
| PHPUnit clinico/amministrativo | 47 test / 269 asserzioni, OK | `unit-final.log` |
| Validatore documentale Python | 24 test, OK | `unit-final.log` |
| Firma clinica sintetica Python | 5 test, OK | `unit-final.log` |
| HTTP FSE finale | 14 controlli superati, zero chiamate Gateway | `fse-http-final.log` e `reports.json` |
| HTTP fatturazione finale | 27 controlli superati, invii TS/email esclusi | `billing-final.log` e `reports.json` |
| Ripristino FSE in nuovi DB sintetici | 10 controlli superati, 9 artefatti, sorgenti invariati | `recovery.log` e `reports.json` |

Le quattro suite automatiche totalizzano 337 esecuzioni, non 337 casi unici:
il test clinico sul download storico FSE rientra sia nel filtro Fse sia nella
suite clinica. Il filtro Linux non comprende `MenuRegistryServiceTest`, presente
nella prova Windows: i conteggi delle due piattaforme non sono intercambiabili.
Gli errori delle prime esecuzioni, compresa la fixture Python assente e il primo
tentativo fatturazione incompleto, rimangono nei log e in `reports.json`.
Sono superati dai rispettivi giri finali, non cancellati o rinominati come PASS.

Il report rehearsal delle 15:39 UTC attesta creazione, sostituzione, metadati,
cancellazione e timeout **simulati**, con tenant separati e sorgenti invariati.
Precede l'immagine finale: non è qui dichiarato rieseguito sulla medesima
immagine. Le prove HTTP FSE e fatturazione finali sono invece delle 15:46 UTC;
il ripristino delle 15:47 UTC. La provenienza per singola richiesta HTTP del
percorso Windows non è attiva in questo lab Linux: il report FSE usa la dicitura
`active` per i vendor interni all'immagine candidata, non per attestare le
dipendenze attive del checkout Windows. Non dichiarare superato un controllo
di provenienza per richiesta che non compare fra i 14 controlli.

Nessun report `clinical-http` è presente nella raccolta letta. Le prove browser
con JavaScript, le firme qualificate reali, l'invio nazionale/regionale e un
disaster recovery dell'intera applicazione non sono attestati da queste suite.

### Isolamento finale e arresto

`runtime.json` associa il container app `a43dd272a5a8` all'immagine finale;
app e MySQL erano healthy prima dell'arresto. Confermati UID/GID app `33:33`,
root filesystem app in sola lettura, nessuna capability app, no-new-privileges,
limiti RAM/CPU/PID già indicati e nessuna porta pubblicata. La rete esportata
in `network.json` è interna. Entrambi i flag effettivi di invio FSE restano
`false`. Nessun bind di cartelle host o socket Docker; preservati i due volumi
dedicati e il volume anonimo MySQL già inventariato.

`stopped.json` documenta app e MySQL arrestati. L'app ha exit 137 con
`OOMKilled=false` dopo lo stop richiesto prima del recovery; MySQL exit 0.
Non è corretto descrivere questo come uno shutdown applicativo graceful
dimostrato, né come un esaurimento memoria. L'inizializzatore storico resta
sulla prima immagine e non è stato rieseguito con successo sul volume esistente:
la protezione contro la reinizializzazione è conservata.

Nessuna cancellazione di volumi, aggiornamento dei vendor attivi o attivazione
produttiva da questa verifica. Windows e Linux hanno evidenze separate; questi
risultati migliorano la preparazione tecnica ma non completano l'accreditamento.
