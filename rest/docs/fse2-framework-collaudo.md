# CodeIgniter: aggiornamento candidato e collaudo isolato

11 settembre 2026. **Il framework attivo resta 4.6.0. Nessun deploy, push,
database clinico o invio FSE.** Queste prove non attestano accreditamento.

## Origine e personalizzazioni

`ops/fse-validation/prepare-framework-compat.ps1` scarica gli archivi ufficiali
GitHub di due commit fissi in una nuova cartella privata, verifica i percorsi ZIP
e confronta tutti i file del framework attivo prima/dopo. Non installa Composer,
non sostituisce `rest/system` e non copia configurazioni o dati della produzione.

Laboratorio: `rest/writable/fse-framework-compat/2c92718f32d648f29f0a22ddc362c3b6`.
Origini e SHA-256 degli archivi sono registrati in `lab.json`:

| Versione | Commit ufficiale |
| --- | --- |
| 4.6.0 | `5f37fda626cae9e9c81244777263b70c581fa27d` |
| 4.7.4 | `2bd0f01d2813f9ec06db42643ce39d9f5428bf6d` |

Dei **628 file locali**, solo due differiscono dalla distribuzione 4.6.0:

- `Debug/Toolbar.php`: inclusione di `toolbarloader.js` commentata.
- `Session/Session.php`: riga di log di inizializzazione commentata.

Non sono stati sovrascritti. Il candidato provato è il framework ufficiale senza
queste modifiche; nel laboratorio HTTP il toolbar è già escluso dai filtri.
Prima dell'adozione decidere esplicitamente come preservare l'intento di queste
personalizzazioni tramite configurazione, senza ricopiare interi file vecchi.

Lettura delle guide ufficiali intermedie 4.6.1–4.7.4 completata; copie dal commit
fisso in `upgrade-guides/`. Riferimenti: [passaggio 4.7.0](https://codeigniter4.github.io/userguide/installation/upgrade_470.html),
[passaggio 4.7.4](https://github.com/codeigniter4/CodeIgniter4/blob/2bd0f01d2813f9ec06db42643ce39d9f5428bf6d/user_guide_src/source/installation/upgrade_474.rst),
[avvisi ufficiali](https://github.com/codeigniter4/CodeIgniter4/security/advisories).

## Selezione del framework e prove riproducibili

Impostando `FSE_FRAMEWORK_LAB` negli strumenti di collaudo si attiva esclusivamente
il bootstrap privato `framework-bootstrap.php`. L'entry point del prodotto non
lo legge. Accetta solo la cartella marcata; usa un WRITEPATH nuovo per i test CLI,
oppure il WRITEPATH del laboratorio applicativo già validato. Non legge `.env`.

Il classmap Composer ottimizzato di `rest/vendor` punta ai vecchi sorgenti:
il bootstrap lo rimappa **solo in memoria**. Ogni processo conserva versione,
provenienza e hash delle classi del framework effettivamente caricate, e ricontrolla
tutti i file del framework attivo. Provenienza errata impedisce un esito positivo.
Il report di provenienza, da solo, non significa che i test siano passati.

```powershell
$env:FSE_FRAMEWORK_LAB = '<cartella privata del framework>'
$env:XDEBUG_MODE = 'off'
php ops/fse-validation/framework-probes.php
./ops/fse-validation/collect-evidence.ps1
```

Per il confronto col framework attivo impostare `FSE_FRAMEWORK_VARIANT=baseline`;
eliminare entrambe le variabili per tornare al normale collaudo. Il worker della
prova concorrente riceve il WRITEPATH esplicito e accetta solo la directory
ordinaria dei test oppure il runtime privato marcato: non usa più per errore
una cartella diversa quando il test principale è isolato.

## Risultati

- Suite candidata finale: **261 test PHP / 1271 asserzioni + 24 test documentali
  Python + 21 controlli packaging/harness/audit**, senza errori o prove saltate.
  Report `rest/writable/fse-validation-reports/20260911-221215-127e4228/summary.json`.
  Sorgenti censiti immutati durante il run; provenienza della suite in
  `run-candidate-f18c45999f522b7b/provenance.json`: 111 file di classi del framework
  caricate dal candidato, nessuna classe del vecchio framework nel processo.
- Framework attivo, regressione mirata JSON/concorrenza/password: **8 test / 94
  asserzioni**; `php-baseline-regression.xml` nel laboratorio framework.
- Sonda framework candidata: **14 controlli superati**,
  `run-candidate-e881c7c455cda2c0/probes.json`. Confronto attuale:
  `run-baseline-daafe3bc70dd1a5c/probes.json`, 9 controlli di compatibilità.
- Librerie Composer candidate insieme a CodeIgniter 4.7.4: **16 controlli di
  componente superati**, `rest/writable/fse-dependency-compat/e90fe89c1b0f4b2ba1d127e9c53a127f/candidate-6e7b6ac9a5bc81f6/report.json`.
  Non è il collaudo dell'intero prodotto con entrambe le sostituzioni.

Le sonde confrontano intestazioni HTTPS non fidate, file PNG innocui con
estensione `.png`/`.php`, e compilazione `deleteBatch` con un valore contenente
un apostrofo. Il candidato rifiuta gli header non fidati e l'estensione incoerente,
e conserva l'escaping del valore; la versione attuale mostra i comportamenti
precedenti. Il compilatore SQL comune usa una connessione SQLite in memoria per
l'escaping, **senza eseguire SQL specifico di MySQL**. Nessun file eseguibile viene
caricato via web, nessuna sonda contatta un server remoto. Non sono prove di
sfruttabilità della produzione e non coprono tutti e sei gli avvisi.

### Incompatibilità reale trovata e corretta

Il login HTTP con 4.7.4 falliva perché `Config\Format::$jsonEncodeDepth` non era
definito. Aggiunto al sorgente applicativo il valore ufficiale **512**, equivalente
al limite implicito precedente, con `FseFrameworkCompatibilityTest` che verifica
la risposta JSON effettiva. Il cambiamento è compatibile anche con 4.6.0.
Non è stata aggiornata la libreria/framework attiva.

Le prime prove fallite sono conservate: classmap vecchio, cartella cache mancante,
worker con WRITEPATH diverso, parametro JSON mancante. Non vengono contate come
successi. Il vecchio laboratorio applicativo `7e38...` ha poi rifiutato correttamente
la ri-verifica della firma: la CRL sintetica scadeva alle **18:42:52 UTC** dell'11/9.
Non è stato cambiato l'orologio né disattivata la verifica. Per ripetere il flusso
con firma serve una nuova sessione con materiali sintetici validi per un'ora.

### Primo collaudo applicativo HTTP e browser completato

Nuovo laboratorio `rest/writable/fse-app-labs/aac410e00e524fdfad908d93dceb647c`,
con MySQL 8.3, database e storage nuovi; app avviata con CodeIgniter **4.7.4**.
Le dipendenze principali usate in questo flusso HTTP restano quelle attive:
la prova delle librerie Composer candidate insieme al framework è quella di
componente separata, non un collaudo HTTP congiunto.

- `rehearsal-report.json`: tre referti sintetici preparati e firmati, revisione,
  creazione/sostituzione/metadati/cancellazione simulati, rifiuto e timeout senza
  reinvio. Righe sorgente immutate e isolamento del secondo studio verificato.
- `http-report-0ac2f199e2a740429b200ca7a0743b56.json`: **nove controlli HTTP
  superati**, inclusi login/sessioni, SHA-256 del download firmato, rapporto
  tecnico, CSRF mancante/scaduto/di altra sessione, isolamento documenti/profili,
  logout e immutabilità di righe, eventi e simulazioni. Il client richiede
  l'header del framework candidato oltre all'identità esatta del laboratorio.
- Verifica manuale tramite browser dell'app: login dello studio fittizio A,
  dashboard, lista dei tre referti firmati, apertura del primo, pressione di
  **Verifica e collega snapshot al laboratorio**, conferma visibile, ritorno al
  referto ancora **Firmato**, logout con ritorno al login. Verifica DOM e screenshot
  della dashboard, senza prove responsive o confronto visuale dei PDF. Il controllo
  browser è distinto dal rapporto HTTP automatico che dichiara correttamente
  `browser_javascript=NOT_EXECUTED` per le proprie prove.

Nel browser compare il warning atteso per il service worker PWA escluso dal router
del laboratorio (404); non è un collaudo della PWA, dell'agenda o delle notifiche
esterne. Le pagine dichiarano chiaramente che gli esiti sono simulati. Nessun
documento inviato al Gateway, nessuna firma qualificata o abilitazione acquisita.

Al termine entrambi i laboratori usati in questa sessione sono **fermi**; porte
8088 e 33079 libere, scheda browser temporanea chiusa. Database, referti e rapporti
conservati; non sono stati eliminati dati del progetto o dei clienti.

## Estensione: gestionale e PDF con entrambe le sostituzioni

Regressione FSE finale dopo l'estensione degli strumenti: **261 test PHP / 1271
asserzioni + 24 documentali Python + 21 packaging/harness**, zero errori/fallimenti/
skip. Report `rest/writable/fse-validation-reports/20260911-223741-c52b33bf/summary.json`,
`passed_offline`, sorgenti censiti immutati. Framework 4.7.4 confermato in
`run-candidate-015ded027618f412/provenance.json` (111 file, nessuna provenienza
errata, framework attivo immutato). Questa suite FSE usa le librerie root attive;
le prove congiunte sono i 33 test e i PDF descritti di seguito, non l'intera suite FSE.

Il bootstrap CLI `ops/fse-validation/application-compat-bootstrap.php` seleziona
insieme framework e librerie root, senza modificare gli autoloader su disco.
Richiede entrambi i laboratori marcati, verifica manifest/lock/installazione,
registra la provenienza delle classi esercitate e confronta gli hash dei sorgenti
applicativi/test PHP e degli strumenti PHP/XML prima/dopo. Runtime in una directory
privata nuova, `.env` escluso, database standard SQLite in memoria. Non è un firewall:
si eseguono solo test esaminati, con trasporti esterni simulati, non suite arbitrarie.

La lista esplicita in `application-compat.xml` ha superato **33 test / 138
asserzioni per ciascuna variante**, senza errori, fallimenti, skip o deprecazioni
PHPUnit. Copertura: colori/rowspan/view agenda, messaggi promemoria, policy e
cifratura delle credenziali SMTP sintetiche, password, permessi/sessione,
catalogo e salvataggio fattura, risposta JSON. Nessuna email o notifica inviata.
Sono prove di servizi/helper/template, non dei relativi endpoint HTTP.

Due test di fatturazione avevano dipendenze TS/catalogo non simulate: fallivano
anche sulla baseline, tentando di interrogare tabelle assenti nel DB in memoria.
Aggiornati i mock con aspettative esplicite su studio e righe, e sostituita la
vecchia API PHPUnit per mock astratti con il mock di una connessione concreta.
Nessuna regola del servizio di fatturazione è stata cambiata per farli passare.
I tentativi iniziali falliti e quello intermedio con mock incompleto sono conservati.

Rapporti PHPUnit nel laboratorio framework:
`application-baseline-passed.xml`, `application-candidate-passed.xml`.
Provenienza nel laboratorio dipendenze `e90fe89c1b0f4b2ba1d127e9c53a127f`:
`application-baseline-5acf4edcca85822b/provenance.json` e
`application-candidate-60fde2ccf5a92d71/provenance.json`.
Entrambi: sorgenti immutati, nessuna provenienza errata. Le classi di libreria
caricate per controllo della provenienza non sono, da sole, collaudo funzionale.

### Confronto visuale delle stampe

`application-pdf-compat.php` usa i **template reali** di fatturazione e timeline,
con modelli dati costruiti esplicitamente e soltanto sintetici. Dompdf attuale
3.1.4/CI 4.6.0 contro Dompdf candidato 3.1.6/CI 4.7.4; rete, PHP e JavaScript
disabilitati, cache font e temporanei nel run privato. Non si tratta di PDF FSE,
di PDF/A, di documenti fiscalmente validi o di referti firmati.

| Caso | Pagine per variante | Esito |
| --- | --- | --- |
| Fatturazione, 2 righe | 1 | Testo e pixel identici |
| Fatturazione, 60 righe | 3 | Tutte le righe e i totali presenti, pixel identici |
| Agenda team, 3 colonne e 32 intervalli | 2 | Rowspan, colori e continuazione presenti, pixel identici |
| Agenda settimana, 5 colonne e 32 intervalli | 1 | Testo e pixel identici |

Otto PDF intermedi di QA, **14 pagine rasterizzate a 100 dpi**: nessun identificativo
sintetico mancante/duplicato, nessun carattere rilevato fuori pagina. Confronto
automatico con `application-pdf-compare.py`, che verifica hash, HTML identico e
provenienza prima di confrontare. Esame visuale manuale di tutte le **sette pagine
candidate**, ciascuna identica alla corrispondente baseline: nei casi provati non
si osservano testo tagliato, sovrapposizioni o righe perse; intestazioni delle
tabelle ripetute sulle continuazioni, caratteri accentati leggibili. I PDF sono
materiale interno di collaudo, non documenti da consegnare alla struttura.

Run baseline `application-baseline-e51f85701009e464`; candidato
`application-candidate-49bd4d13d54ef43f`, nel laboratorio dipendenze. Rapporto:
`pdf-qa-535472e8e1d5439c9b9b16c93e6a1308/comparison.json` dentro il run candidato.
L'esame visuale è registrato qui: il rapporto automatico non si attribuisce un
controllo umano. Identità dei pixel non esclude difetti preesistenti o problemi
con altri layout, loghi, campi lunghi e dati non inclusi nei quattro casi.

Ripetizione (i due laboratori devono già esistere):

```powershell
$env:FSE_DEPENDENCY_LAB = '<laboratorio dipendenze>'
$env:FSE_FRAMEWORK_LAB = '<laboratorio framework>'
$env:FSE_APPLICATION_VARIANT = 'candidate' # oppure baseline
$env:XDEBUG_MODE = 'off'
php rest/vendor/phpunit/phpunit/phpunit -c ops/fse-validation/application-compat.xml
php ops/fse-validation/application-pdf-compat.php
# Usare i due percorsi run appena restituiti; Poppler/Python sono strumenti QA.
python ops/fse-validation/application-pdf-compare.py --baseline <run-baseline> --candidate <run-candidato> --pdftoppm <eseguibile-poppler>
```

### Proxy Coolify: verificato in sola lettura, configurazione non attivabile ancora

L'API del server indica **Traefik running**; la configurazione salvata dichiara
`traefik:v3.6` e reti esterne. L'immagine salvata non prova la versione/digest del
container effettivamente in esecuzione. Le due applicazioni demo/login risultano
`running:healthy`, branch `main`, porta interna esposta 80 e `ports_mappings=null`.
Quest'ultimo campo non è una scansione delle porte o una prova del firewall.

Il nuovo endpoint ufficiale `GET /api/v1/servers/{uuid}/proxy` risponde 404 su
questa installazione; il controllo ha usato il `GET` del server esistente e solo
campi selezionati della configurazione già salvata, senza stampare token/segreti.
Non sono state trovate righe `forwardedHeaders.trustedIPs/insecure` nel testo
salvato esaminato. Questo non attesta l'intera configurazione dinamica.

**IP/CIDR effettivi e header ricevuti da Apache/PHP non ancora verificati.**
Occorre un'ispezione autorizzata delle reti/container e una prova in staging
con la stessa topologia. Non sono stati cambiati `proxyIPs`, header, TLS, cookie,
porte, firewall o configurazione Coolify. Il laboratorio localhost non sostituisce
questa verifica. Riferimenti: [API proxy](https://coolify.io/docs/api/endpoints/servers/get-server-proxy),
[configurazione di rete Coolify](https://coolify.io/docs/core/networking-in-coolify),
[avviso CodeIgniter sugli header HTTPS](https://github.com/codeigniter4/CodeIgniter4/security/advisories/GHSA-7wmf-pw8j-mc78).

## Estensione successiva: HTTP FSE con framework e dipendenze insieme

Il laboratorio MySQL già isolato `aac410e00e524fdfad908d93dceb647c` è stato
riavviato solo su loopback, senza nuove migration o documenti. La CRL sintetica
scadeva alle **21:14:55 UTC**; entrambe le prove si sono concluse prima della
scadenza (candidato 21:07:46, baseline 21:09:07 UTC dell'11 settembre). Nessuna
estensione della validità, sostituzione della fiducia o disattivazione dei controlli.

Nuovo opt-in privato `app-lab-dependencies.php`, richiamato esclusivamente dal
bootstrap del laboratorio: valida cartelle/marcatori, manifest, lock e pacchetti,
carica il vendor selezionato prima del framework e verifica le classi effettive.
Rifiuta varianti framework/dipendenze discordanti. Non legge `.env` e non cambia
vendor, autoloader, configurazioni del prodotto o ambienti Coolify.

Il client HTTP richiede le intestazioni del laboratorio, versione framework,
variante dipendenze e identificativo della prova. Alla fine verifica il rapporto
privato di provenienza di **ognuna delle 33 richieste per variante**, incluse le
risposte negate. I pacchetti caricati preventivamente per la provenienza non sono
da soli copertura funzionale: Guzzle/WebAuthn/push non sono esercitati come servizi
esterni da questi percorsi FSE.

**15 verifiche HTTP superate su entrambe le varianti**, con CodeIgniter 4.7.4 e
vendor candidato oppure CodeIgniter 4.6.0 e vendor attivo:

- login/sessioni, accesso anonimo e dopo logout;
- download firmato con SHA-256 e rapporto tecnico senza dati clinici;
- caricamento multipart senza CSRF respinto; file non PDF respinto con messaggio;
- impossibilità di sovrascrivere il documento già firmato, verificando il rifiuto
  visibile e il download successivo byte-per-byte tramite hash;
- caricamento sul documento di un altro studio respinto **anche con il CSRF
  valido dello studio attaccante**; isolamento di form, documento e profilo;
- CSRF mancante, riutilizzato o appartenente a un'altra sessione respinto;
- snapshot sintetico importato senza cambiare righe/eventi sorgente o simulazioni;
- provenienza delle dipendenze di tutte le richieste verificata.

Nel laboratorio applicativo sono conservati:

| Variante | Rapporto HTTP |
| --- | --- |
| Candidato | `http-report-eb47b39bb693422ba130e933862e07e8.json` |
| Baseline | `http-report-e41a4778597a40c2962692a476b89226.json` |

I rapporti delle richieste sono in `writable/dependency-provenance/`. I 71 rapporti
framework creati nella finestra delle due suite (incluse verifiche CLI e arresto/
riavvio del server) risultano tutti con provenienza corretta e framework attivo
immutato. Il primo tentativo `http-report-9debc2cd865a48dbb2988a2553ef25c9.json`
è fallito: il nuovo controllo CLI leggeva la versione del framework prima di
caricarne la classe. Corretto il bootstrap del test; il fallimento è conservato.

Aggiunti tre test senza rete del client di collaudo: header dipendenze errati o
mancanti, framework inatteso e redirect fuori loopback. Suite packaging/harness/
audit: **24 test superati**. Nessun browser JavaScript eseguito in questa estensione,
nessun invio Gateway, firma qualificata o test di accreditamento. Il test dell'upload
copre i rifiuti descritti, non l'acquisizione positiva completa di un nuovo referto
attraverso il browser. Agenda, posta, fatturazione e passkey HTTP erano fuori
dal router di questa prova; la successiva estensione fatturazione è descritta sotto.

Regressione offline finale con framework candidato e vendor root attivo:
**261 test PHP / 1271 asserzioni + 24 test documentali + 24 packaging/harness/audit**,
zero errori, fallimenti o skip; sorgenti censiti immutati. Report
`rest/writable/fse-validation-reports/20260911-231125-42f28d0f/summary.json`,
`passed_offline`. È distinta dalle due prove HTTP congiunte, non ne estende
automaticamente la copertura ai moduli esclusi.

Ripetizione: usare un laboratorio già predisposto con materiali sintetici ancora
validi, oppure crearne uno nuovo con il flusso ordinario. Nei **due processi** PHP
server e client Python impostare le stesse variabili:

```powershell
$env:FSE_DEPENDENCY_LAB = '<laboratorio dipendenze marcato>'
$env:FSE_APPLICATION_VARIANT = 'candidate' # oppure baseline
$env:FSE_FRAMEWORK_LAB = '<laboratorio framework marcato>'
$env:FSE_FRAMEWORK_VARIANT = 'candidate' # stessa variante
$env:FSE_LAB_ROOT = '<laboratorio applicativo marcato>'
# MySQL dedicato già avviato e verificato; mai usare il database dell'app ordinaria.
./ops/fse-validation/app-lab-serve.ps1 -LabRoot $env:FSE_LAB_ROOT
# In un secondo processo, con le stesse variabili:
& rest/writable/fse-validation-venv/Scripts/python.exe ops/fse-validation/app-lab-http.py
```

Al termine PHP e MySQL isolati sono **fermi**, porte 8088/33079 libere e directory
temporanea upload vuota. File sorgente, database sintetico ed evidenze conservati.
Demo e login verificati via API in sola lettura: `running:healthy`, branch `main`.

## Estensione del 12 settembre: documenti di fatturazione HTTP

Nuovo laboratorio con database sintetici separati: **26 verifiche HTTP candidate
e 27 baseline superate**, 38 richieste per variante con provenienza verificata.
Provati documenti, importi, pagamento e PDF; corretta la mancanza di controllo
CSRF sui documenti e spostata la cache PDF in storage writable per studio.
La baseline include anche un inventario dei font del vendor prima/dopo: invariato.

Suite applicativa estesa: **39 test / 182 asserzioni per variante**, zero skip.
Due PDF effettivamente scaricati renderizzati e ispezionati. Regressione FSE
**309 test superati**, report `20260912-100231-0f39b500`. Nessuna attivazione
produttiva o operazione di accreditamento. Entrambi i servizi sintetici fermati,
demo/login healthy. [Rapporto e istruzioni](fse2-fatturazione-collaudo.md).

## Condizioni prima di attivare l'aggiornamento

1. Verificare IP/CIDR effettivi del proxy Coolify e comportamento degli header.
   Con 4.7.4 `isSecure()` ignora gli header HTTPS se il proxy non è fidato;
   `Config\App::$proxyIPs` è attualmente vuoto. Anche la logica personalizzata
   di URL/host in `Config\App` legge header inoltrati: riesaminarla con la reale
   topologia, senza autorizzare indiscriminatamente tutte le reti. I test loopback
   non verificano TLS, cookie Secure o redirect nella topologia produttiva.
2. Completare merge dei file di progetto necessari: fra le novità documentate
   `Hostnames`, `WorkerMode`, opzione lock delle migration; non attivare Worker Mode
   o nuovi comportamenti arbitrariamente. Verificare API rimosse/override usati.
3. Completare il collaudo dell'intero gestionale e degli endpoint HTTP con entrambe
   le sostituzioni: i test e PDF descritti, le 15 verifiche HTTP FSE e il nuovo
   ciclo HTTP fatturazione coprono solo un sottoinsieme. Restano acquisizione
   positiva completa di referti via HTTP, agenda completa, altri percorsi della
   fatturazione (impostazioni/report/email/TS), allegati/posta, altri layout PDF,
   notifiche reali con destinatari di prova e passkey dove presenti.
4. Preparare rilascio e rollback su staging Linux con risorse adeguate, poi solo
   con autorizzazione pubblicare su `main`. Non usare il server condiviso già
   limitato dalla RAM come banco di prova senza decisione sulla capacità.

PHP minimo ufficiale 8.2; prove locali svolte con PHP 8.3.6, mentre il Dockerfile
del prodotto dichiara PHP 8.2. Questo non certifica la versione effettivamente
installata in ogni ambiente remoto. Demo e login Coolify risultavano entrambi
`running:healthy`, branch `main`, alla verifica in sola lettura di questa sessione.
