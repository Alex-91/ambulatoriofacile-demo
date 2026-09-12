# Laboratorio FSE Linux su Windows — preparazione 12 settembre 2026

## Stato effettivo

### Ripresa dopo il riavvio del 12 settembre

Il riavvio è stato eseguito dall'utente. Il preflight è passato e la sola
distribuzione `AmbulatorioFacile-FSE` è ora registrata e avviabile: Debian 13.5
(trixie), x86_64, systemd, circa 2,84 GiB di RAM e disco virtuale massimo 40 GiB.
Docker Engine 29.8.0, containerd 2.3.5 e Compose 5.5.1 installati dal repository
ufficiale Docker tramite `install-wsl-lab-docker.sh`. Nessun Docker Desktop o
daemon TCP configurato. Procedura: https://docs.docker.com/engine/install/debian/.

Contesto candidato aggiornato `1b6da77e72a44672af463349c28adec6`, 4.108 file
applicativi e 51 runtime, copiato nella cartella privata Linux
`/opt/af-fse-labs/1b6da77e72a44672af463349c28adec6`. Manifest e corrispondenza dei
sorgenti verificati. La ricetta include SOAP, permessi COPY espliciti e test
clinici; `run-wsl-linux-lab.sh` prepara le azioni successive nel progetto dedicato.

La prima build è fallita nel download da Docker Hub (token auth risolto
transitoriamente a loopback); anche il primo pull MySQL ha avuto un reset TCP.
La successiva diagnosi DNS/TLS ha risposto correttamente senza modificare rete
o protezioni. Build e collaudo Linux ora **superati**: 261 test FSE, 47 clinici
e amministrativi, 24 documentali, 5 firme; HTTP FSE14, fatturazione27, cartella66
e ripristino10. Nessuno skip. Immagine finale `sha256:10c1973e8bc302115429402e2de5cd48bd38a550fcc6b33b98f50d6fff490b3a`.
Entrambe le istanze fermate, volumi conservati. Dettagli, tentativi storici e limiti:
[verbale Linux](../../rest/docs/collaudo-linux-gestionale.md).

Durante l'esecuzione mantenere un client WSL aperto: i servizi systemd da soli
non mantengono viva la distribuzione. Il collaudo usa un processo
`wsl -d AmbulatorioFacile-FSE -u root --exec tail -f /dev/null`, da terminare dopo
lo stop dei container. Non cambiare impostazioni globali per aggirare lo stop.
Per una ripresa usare `run-wsl-linux-lab.sh <contesto> resume`: `up` è riservato
a nuovi progetti e non deve rieseguire l'inizializzatore su dati già presenti.

Le informazioni seguenti descrivono la situazione precedente al riavvio.

WSL Microsoft **2.7.14.0** installato, kernel distribuito **6.18.33.2-2**.
Il componente `VirtualMachinePlatform` risulta `Enabled`; Windows indica
**riavvio necessario**. Non è stato eseguito alcun riavvio automatico, modificato
il firmware, disabilitata una protezione o toccata la produzione.

La richiesta di installazione Debian con nome `AmbulatorioFacile-FSE` e
`--no-launch` ha restituito il messaggio di riavvio necessario. Verifica successiva:
nessuna distribuzione registrata, cartella dedicata non creata. Non considerare
quindi Debian/Docker installati o il laboratorio Linux avviato.

Il PC rilevava Windows 11 Home build 26200, circa 19,8 GiB RAM totali, 5,8 GiB
liberi e 72 GiB liberi su C:. Sono misure puntuali, non una garanzia di capacità
per un'altra sessione. L'hypervisor risulta presente; i flag CPU interrogati
non attestano da soli una disabilitazione della virtualizzazione nel firmware.

Creata **solo perché prima assente** `C:/Users/bassi/.wslconfig`, identica a
`wslconfig.fse.example`: 3 GiB RAM, 2 CPU, 1 GiB swap, limite VHD predefinito
40 GiB, firewall attivo, inoltro porte verso Windows e proxy automatico disattivati.
Queste impostazioni sono globali WSL; nessuna distribuzione preesistente risultava
installata. Rivederle prima di usare WSL per altri progetti. Nessun dato utente
esistente sovrascritto. Il limite VHD non prealloca tutto lo spazio ma la capacità
effettiva e lo spazio residuo andranno verificati dopo la creazione.

## Ripresa dopo il riavvio manuale

Il perimetro concordato comprende la preparazione e il collaudo del laboratorio.
Riavvio del PC, acquisti, nuove identità/credenziali e attivazione in produzione
rimangono azioni distinte.

1. Controllare `wsl --version`, `wsl --status`, distribuzioni e risorse. Un errore
   `E_ACCESSDENIED` dal sandbox non dimostra che WSL sia guasto: effettuare il
   controllo con l'accesso locale autorizzato, senza indebolire le protezioni.
2. Eseguire `./ops/fse-validation/resume-wsl-lab.ps1`: default non mutativo.
   Il comando rifiuta riavvio pendente, limiti WSL cambiati, risorse insufficienti
   o impossibilità di enumerare le distribuzioni. Se pronto, usare
   `-InstallDistribution` per la sola Debian `AmbulatorioFacile-FSE` nella cartella
   privata `rest/writable/fse-wsl-distribution`. Non sovrascrivere tentativi parziali
   né usare `wsl --unregister` come soluzione automatica.
3. Ispezionare il sistema **di questa distribuzione**, creare il contesto Linux
   dedicato e installare Docker Engine/Compose dalle fonti ufficiali adatte alla
   versione effettiva di Debian. Niente Docker Desktop, daemon TCP, accessi remoti
   o avvio di container appartenenti ad altri progetti. Non eseguire comandi
   scaricati senza averli esaminati.
4. Trasferire il solo contesto generato e verificato in un percorso privato Linux.
   Non montare il checkout completo, database, upload o segreti della produzione.
   Controllare manifest e immagini bloccate prima della build, e verificare i
   digest realmente scaricati e la versione PHP/Python dentro l'immagine costruita.
5. Seguire [la ricetta isolata](linux-lab.md): rete interna, nessuna porta
   pubblicata, nuovi volumi, limiti di risorse e identità del progetto verificati.
   Provare seed, FSE, sessioni/CSRF, fatturazione sintetica e ripristino. Non
   importare le CA sintetiche nel trust di Windows o dei servizi reali.
6. Conservare esiti e immagini, fermare solo il laboratorio. Niente riavvio globale
   di WSL, `docker prune`, cancellazione indiscriminata di volumi o deploy live.

Il ramo di installazione della distribuzione è stato eseguito dopo il riavvio;
non ripeterlo su questa installazione. Per le successive sessioni usare i controlli
di stato e il comando `resume` del laboratorio esistente.

## Chiusura verifiche prima del riavvio

Suite offline finale `20260912-142239-f3352ad4`: `passed_offline`, 320 test
superati (261 PHP con 1.271 asserzioni, 24 documentali Python, 35
packaging/harness/audit/manifest immagini), nessun errore/fallimento/skip e
sorgenti censiti immutati durante la prova. Il tentativo precedente, invalidato
da una modifica concorrente a `rest/app/Config/Filters.php`, non è usato come
evidenza finale. Modifica preservata e suite ripetuta sul nuovo stato.

Contesto candidato verificato:
`rest/writable/fse-linux-labs/72ccd6c3fbff4c21983112d378140da1`, 4.080 file app
e 51 runtime, `PREPARATION_CHECK_PASSED_NOT_STARTED`. La corrispondenza con i
sorgenti va ricontrollata dopo il riavvio; rigenerare il contesto se è cambiato
codice, senza alterare i manifest per aggirare il controllo.

## Immagini e dipendenze

Risolti dal registry ufficiale i manifest Linux/amd64 di PHP 8.2 CLI Bookworm,
Composer 2 e MySQL 8.4. Verificata corrispondenza SHA-256 di index e manifest della
piattaforma; riferimenti salvati in `linux-images.lock.json`, PHP/Composer fissati
nel Dockerfile. La verifica della ricetta richiede anche il lock identico e, se
fornita, la stessa immagine MySQL. Non sono ancora stati scaricati i layer,
verificate firme del publisher o eseguite immagini. `apt` durante la build usa
ancora repository aggiornabili: non è una build integralmente riproducibile.

Report privato: `rest/writable/fse-linux-image-resolutions/463b90801458483c92dc544ee9c34630/result.json`.
Il resolver usa richieste anonime di sola lettura a repository allowlistati;
nessun token Docker dell'utente, push o dato applicativo trasmesso.

`probe-linux-python.ps1` ha risolto il lock invariato per CPython 3.11 x86_64 e i
formati manylinux fino al livello glibc 2.36. La prima prova ometteva il livello
2.24 di SaxonC 13.0.0 e falliva: corretto il filtro del precontrollo, non la libreria.
Report riuscito: `rest/writable/fse-linux-wheels/11045cab7727482a9e85e78e3831ff49/summary.json`.
È un dry-run da Windows: marker dipendenze e funzionamento effettivo richiedono
ancora installazione e prove Linux. Nessun pacchetto attivo aggiornato.

## Fonti consultate

- [Microsoft: installazione WSL](https://learn.microsoft.com/en-us/windows/wsl/install)
- [Microsoft: comandi WSL](https://learn.microsoft.com/en-us/windows/wsl/basic-commands)
- [Microsoft: limiti e configurazione WSL](https://learn.microsoft.com/en-us/windows/wsl/wsl-config)
- [Docker: installazione Engine su Debian](https://docs.docker.com/engine/install/debian/)
- [Docker: autenticazione registry](https://docs.docker.com/reference/api/registry/auth/)
- [CNCF Distribution: manifest API](https://distribution.github.io/distribution/spec/api/)
- [PyPI: SaxonC 13.0.0](https://pypi.org/project/saxonche/13.0.0/)
