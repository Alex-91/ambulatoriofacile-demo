# FSE 2.0 — dossier di preparazione e collaudo offline

Stato software aggiornato al 12 settembre 2026: sviluppo FSE avviato sul branch
`codex/fse-certificati-test`; alla ripresa dopo il riavvio il checkout condiviso
è sul branch `codex/completamento-gestionale-clinico`. Nessun rilascio da questa ripresa.
Questo dossier offline non è una dichiarazione di accreditamento né prova di pubblicazione nel FSE.
Nessun database clinico o di produzione viene usato nelle prove descritte.

Certificati Sogei di test ricevuti e verificati; quattro prove positive accettate
dal Gateway nazionale in modalità VERIFICA e quattro prove negative con i rifiuti
attesi (403, 403, 422, 400). Le evidenze di rete sono separate dalle suite offline:
[certificati e primi test reali](fse2-certificati-e-primi-test.md) e
[matrice RSA delle 23 voci analizzate](fse2-matrice-rsa-preparatoria.md).
La suite storica del 9 settembre ha superato 166 test PHP (830 asserzioni) e 24 test Python,
senza errori o test saltati. Report finale privato:
`rest/writable/fse-validation-reports/20260909-195016-a5d25846/summary.json`;
sorgenti censiti immutati durante l'esecuzione. Nessun rilascio in produzione.

## Ultimo incremento: prime prove Linux completate

Il riavvio è completato e il laboratorio Debian/Docker è stato eseguito con
due tenant interamente sintetici dalla task **Analizza requisiti cliente**.
Risolti l'arresto automatico WSL e i problemi delle fixture e dei percorsi del
laboratorio. Rapporti Linux esportati alle 17:50 locali, letti e verificati qui:

- 261 test PHP FSE / 1.274 asserzioni e 47 clinico-amministrativi / 269 asserzioni;
- 24 test documentali Python e 5 sulla firma sintetica;
- 14 controlli HTTP FSE, 27 HTTP fatturazione e 10 di ripristino FSE, tutti superati;
- 9 artefatti recuperati in nuovi database sintetici, sorgenti invariati.

Le suite automatiche hanno una sovrapposizione: 337 esecuzioni non significa
337 casi distinti. Nessuna prova Gateway/CART o firma qualificata reale.
Il rehearsal simulato precede l'ultima build; browser JavaScript e HTTP clinico
non sono attestati da questa raccolta Linux. App e MySQL sono stati arrestati,
con volumi ed evidenze conservati. Nessun rilascio.

Regressione Windows ripetuta alle 17:57 dopo le correzioni del laboratorio:
**321 test superati** (262 PHP /
1.277 asserzioni, 24 documentali Python, 35 packaging/harness/audit), nessun
errore/fallimento/skip e sorgenti censiti immutati durante la prova. Report
`rest/writable/fse-validation-reports/20260912-175713-9b7de61b/summary.json`.
Stato `passed_offline`; ulteriore confronto dei sorgenti censiti dopo la
conclusione senza variazioni. Framework candidato e vendor root attivo su
Windows: non è lo stesso perimetro della suite nell'immagine Linux candidata.

Verificati manifest e snapshot finali esportati: immagine app `10c1973e…`,
utente non privilegiato, rete interna, nessuna porta pubblicata e invii reali
disabilitati. Limiti del controllo di provenienza HTTP e dettaglio dell'arresto
sono nel [verbale della ripresa, evidenze e avviso Slack dell'11 settembre](fse2-ripresa-wsl-20260912.md).

## Incremento precedente: prerequisiti Linux installati sul PC Windows

Installato WSL Microsoft **2.7.14.0**; VirtualMachinePlatform risulta abilitato.
Creata una nuova configurazione limitata a 3 GiB RAM / 2 CPU / 1 GiB swap, con
firewall attivo e inoltro porte verso Windows disabilitato. Nessuna configurazione
preesistente sovrascritta. **Windows richiede un riavvio manuale**: distribuzione
Debian e Docker non sono ancora installati, nessuna prova Linux eseguita.

Verificati i manifest SHA-256 pubblici di PHP/Composer/MySQL e fissati i riferimenti
nel lock/ricetta. Preparato e controllato il contesto candidato
`rest/writable/fse-linux-labs/72ccd6c3fbff4c21983112d378140da1`: 4.080 file app,
51 runtime, `PREPARATION_CHECK_PASSED_NOT_STARTED`. Risolte 30 dipendenze Python
per il target Linux in dry-run da Windows, lock invariato; non è un test runtime.

Script di ripresa con blocco sul riavvio e sulle risorse insufficienti, nessuna
installazione su distribuzioni esistenti o cancellazione automatica. Il ramo di
installazione dedicato resta da eseguire dopo il riavvio. Produzione non modificata;
demo/login healthy al controllo delle 12:17 UTC del 12 settembre.

Regressione finale: **320 test superati** (261 PHP / 1.271 asserzioni, 24
documentali Python, 35 packaging/harness/audit/manifest immagini), nessun
errore/fallimento/skip. Report privato
`rest/writable/fse-validation-reports/20260912-142239-f3352ad4/summary.json`:
`passed_offline`, sorgenti censiti immutati durante l'esecuzione. Il precedente
tentativo `20260912-141618-019f8953` aveva test verdi ma stato
`incomplete_source_changed_during_run` per una modifica concorrente a
`rest/app/Config/Filters.php`: modifica preservata, pacchetto rigenerato e suite
rieseguita. Queste prove non attestano tutti i flussi TS interessati dalla modifica
concorrente, né l'esecuzione Linux. Documentazione aggiornata dopo la suite.

Evidenze, modifiche al PC e istruzioni per riprendere nella
[guida WSL del laboratorio](../../ops/fse-validation/wsl-lab.md).

## Incremento precedente: browser, ripristino, proxy e pacchetto candidato

Completato il percorso FSE nell'interfaccia con JavaScript reale e due studi
fittizi: nuovo referto, CDA/PDF, rifiuto del PDF alterato, acquisizione positiva
del firmato, campi bloccati, simulazione distinta dalla pubblicazione, correzione,
rifiuto del PDF della versione precedente e isolamento fra studi. Autotest
documentale dal pannello superato. Tre PDF renderizzati ed esaminati; firma solo
sintetica. Audit di stato in sola lettura: 15 controlli. Ripristino FSE in database
nuovi: 10 controlli, cinque artefatti e sorgenti preservati.

Rafforzata la fiducia negli header proxy per l'origine URL; nessun IP produttivo
indovinato/configurato. **67 test / 279 asserzioni per variante** superati con
framework e librerie attive oppure candidate. Preparato e controllato il nuovo
pacchetto Linux candidato, non costruito o avviato. Docker/WSL non disponibili
localmente e accesso SSH non funzionante: collaudo Linux/proxy ancora da eseguire.
Nessun deploy; demo/login healthy al controllo delle 10:42 UTC del 12 settembre.

Regressione finale: **314 test**, zero errori/fallimenti/skip, sorgenti censiti
immutati, report `rest/writable/fse-validation-reports/20260912-124814-37077650/summary.json`
con esito `passed_offline`. Framework candidato e vendor root attivo in questa
suite; i 67 test e le prove browser usano le selezioni congiunte indicate sopra.

Dettagli, evidenze, limiti e sequenza di installazione/rollback nel
[verbale browser e preparazione al rilascio](fse2-browser-e-rilascio-preparatorio.md).

## Incremento precedente: fatturazione HTTP e protezione dei salvataggi

Nel nuovo laboratorio con due database di studio sintetici sono superate 26
verifiche HTTP con dipendenze candidate e 27 con quelle attive (la baseline
include anche l'inventario dei font del vendor immutato). Provati bozza, modifica,
duplicati, definitiva, pagamento, PDF e rifiuti di accesso tra studi. Nessun invio
TS/email. Corretto un salvataggio che accettava POST senza CSRF e separata la
cache PDF dai file del codice; modifiche solo locali, non ancora rilasciate.

Superati **39 test / 182 asserzioni per variante**; entrambi i PDF scaricati
renderizzati e controllati visivamente. Regressione FSE: **309 test superati**,
report `rest/writable/fse-validation-reports/20260912-100231-0f39b500/summary.json`,
nessun errore/fallimento/skip e sorgenti censiti immutati. Servizi del laboratorio
arrestati; demo/login healthy. Nessun nuovo avanzamento formale di accreditamento
deriva da questi test. [Dettagli, evidenze e limiti](fse2-fatturazione-collaudo.md).

## Incremento precedente: collaudo HTTP FSE con entrambi gli aggiornamenti

Superate **15 verifiche HTTP per variante** con framework e vendor candidati
insieme, poi con le versioni attive. Tra i nuovi controlli: upload multipart senza
CSRF, file non PDF, rifiuto della sovrascrittura di un referto firmato e upload
incrociato fra studi con CSRF valido dello studio sbagliato. Download successivo
integro; righe ed eventi sorgente e simulazioni immutati. Verificata la provenienza
delle dipendenze su tutte le 33 richieste di ciascuna suite.

Rapporti nel laboratorio `rest/writable/fse-app-labs/aac410e00e524fdfad908d93dceb647c`:
`http-report-eb47b39bb693422ba130e933862e07e8.json` (candidato),
`http-report-e41a4778597a40c2962692a476b89226.json` (baseline).
Prove concluse con CRL sintetica ancora valida, senza modificarne scadenza/fiducia.
Tre nuove regressioni del client di collaudo; 24 test packaging/harness/audit
superati senza rete. Nessun nuovo documento o migration reale, invio FSE o firma
qualificata. Laboratorio fermato; produzione demo/login verificata healthy.

Regressione offline finale: **309 test** (261 PHP con 1271 asserzioni, 24
documentali Python, 24 packaging/harness/audit), nessun errore/fallimento/skip.
Report `rest/writable/fse-validation-reports/20260911-231125-42f28d0f/summary.json`,
`passed_offline`, sorgenti censiti immutati. Suite con framework candidato e
vendor root attivo; le prove HTTP con entrambe le sostituzioni sono quelle sopra.

Restano esclusi da questa estensione browser/JavaScript, acquisizione positiva
completa di nuovi referti via HTTP e gli altri moduli HTTP del gestionale.
Copertura, evidenze e istruzioni nel [collaudo del framework](fse2-framework-collaudo.md).

## Incremento precedente: collaudo congiunto del gestionale e delle stampe

Superati **33 test / 138 asserzioni per variante** con framework e dipendenze
attuali oppure candidati insieme: agenda, fatturazione, accessi/sessioni,
template notifiche e policy SMTP con dati simulati. Sistemate dipendenze non
simulate in due test di fatturazione, senza cambiare le regole del servizio.

Verificati inoltre quattro casi PDF realizzati dai template applicativi: fattura
breve/multipagina, agenda team e settimanale. **14 pagine renderizzate**, testo e
pixel identici fra versioni; tutte le sette pagine candidate esaminate visivamente,
senza tagli/sovrapposizioni nei casi provati. Prove locali, nessuna notifica o
fattura reale. Rapporti e istruzioni nel [collaudo del framework](fse2-framework-collaudo.md).

Regressione FSE finale: **261 test PHP / 1271 asserzioni + 45 controlli Python e
harness**, tutti superati senza prove saltate, framework candidato 4.7.4 e
librerie root attive. Rapporto `rest/writable/fse-validation-reports/20260911-223741-c52b33bf/summary.json`:
`passed_offline`, sorgenti censiti immutati durante il run. Non è un nuovo test
del Gateway o un collaudo HTTP con tutte le dipendenze candidate.

Coolify controllato in sola lettura: Traefik avviato, demo e login healthy.
Indirizzi effettivi del proxy/header verso PHP ancora da verificare prima di
impostare la fiducia del proxy. Nessuna modifica a produzione, reti o framework
attivo. Queste verifiche **non completano né accelerano formalmente gli iter di
accreditamento**: riducono lavoro tecnico da svolgere dopo l'accesso allo staging.

## Incremento precedente: candidato CodeIgniter 4.7.4

Framework ufficiale scaricato e confrontato in copia privata: 628 file attivi,
solo due personalizzazioni (toolbar e log sessione), preservate nell'installazione
attuale. **261 test PHP / 1271 asserzioni + 45 controlli Python/harness superati**
con il candidato; 14 sonde framework e 16 controlli con le librerie aggiornate.
Provenienza delle classi verificata, nessuna sostituzione di `rest/system` o vendor.

Trovata durante il login HTTP e corretta una configurazione JSON mancante
(`jsonEncodeDepth=512`), con regressione verificata anche sul framework attivo.
Dettagli, rapporti, differenze e condizioni prima del rilascio:
[collaudo del framework](fse2-framework-collaudo.md).
La modifica locale non è stata pubblicata; non abilita invii o accreditamenti.
Nuovo laboratorio MySQL sintetico: tre referti firmati, **nove verifiche HTTP
superate** e percorso browser login → referto → snapshot verificato → ritorno
all'originale ancora firmato → logout. Servizi locali fermati a fine collaudo,
file e rapporti conservati. Produzione demo/login verificata `running:healthy`.

## Incremento precedente: confronto delle dipendenze in copia privata

L'11 settembre è stato eseguito un primo collaudo dei componenti aggiornati, senza
attivarli nel gestionale: **16 controlli sul candidato e 15 sulla baseline**.
Verificati provenienza/versioni, client HTTP simulato, ES256/VAPID/Web Push,
opzioni WebAuthn, servizio password/filtro studi e template PDF di fatturazione
con dati sintetici e 2/90 righe. Nessun DB reale, invio, browser o deploy.

La sonda JWT separata accetta l'algoritmo non protetto nella libreria attuale e
lo rifiuta nel candidato. Il superamento dei test di compatibilità della baseline
non è un esito di sicurezza pulito. Il framework non è stato aggiornato.
Rapporti, copertura esatta e limiti nella [scheda dipendenze e firma](fse2-dipendenze-e-firma.md).
La suite FSE seguente è quella dell'incremento applicativo precedente; questa
sessione modifica soltanto strumenti di collaudo e documentazione.

## Incremento precedente dell'11 settembre: payload, trasporto e dipendenze

Suite completata: **260 test PHP / 1268 asserzioni, 24 test Python documentali e
21 controlli delle ricette/harness/audit**, zero errori, fallimenti o prove saltate.
Rapporto `rest/writable/fse-validation-reports/20260911-201743-8e256063/summary.json`:
`passed_offline`, sorgenti censiti immutati durante la verifica.

- Il client utilizza il nuovo controllo locale del payload: date e codici validi,
  identificativi entro i limiti, sottomissione/repository obbligatori, regole di
  accesso strutturate. Errori bloccati prima della firma JWT e del trasporto.
- Risposte Gateway limitate a 4 MiB di corpo e 64 KiB di header, HTTPS obbligatorio,
  TLS verificato, nessun redirect. Superamento dei limiti = esito incerto senza
  reinvio; correlazione CART già acquisita preservata.
- Prova aggiuntiva del trasporto su un JSON **pubblico OSV**, senza credenziali e
  senza FSE: HTTP 200, cURL 0, verifica TLS 0 usando il bundle certifi locale.
  Il tentativo iniziale senza bundle esplicito falliva con cURL 60/TLS 20;
  nessuna disattivazione TLS o modifica globale del PHP è stata effettuata.
- Inventario licenze/versioni e audit OSV/Composer effettivi completati:
  segnalazioni nel prodotto e nel framework, nessuna nelle versioni Python
  interrogate. Proposta Composer risolta in una copia privata, con audit pulito,
  **non installata né collaudata nell'app**. Il framework richiede intervento
  separato; nessuna dichiarazione di sicurezza complessiva della produzione.
- Preparata la scheda di acquisizione e collaudo del servizio/dispositivo di firma
  reale; nessun provider, contratto o credenziale scelto arbitrariamente.

Dettagli, rapporti e prossimi controlli: [dipendenze e firma](fse2-dipendenze-e-firma.md).
Le incongruenze tra esempi regionali e OpenAPI sono registrate nella
[preparazione Toscana](fse2-toscana-preparazione.md), senza inventare una risposta CART.
Nessun nuovo invio nazionale/regionale, migration reale, commit, push o deploy.

Pacchetto Linux aggiornato: `rest/writable/fse-linux-labs/4783da8ad01d4293b1836a328c40bd0b`,
4051 file applicativi e 50 file runtime. Controllo di ricette e hash superato
(`PREPARATION_CHECK_PASSED_NOT_STARTED`), senza build/avvio o modifiche remote.
Contiene le dipendenze correnti del prodotto, non gli aggiornamenti candidati:
restano il collaudo di sicurezza e il vincolo di capacità del server condiviso.

## Verifica precedente dell'11 settembre: HTTP reale e ripristino ripetibile

Suite precedente: **223 test PHP / 1150 asserzioni, 24 test Python documentali e
15 controlli delle ricette/harness**, zero fallimenti, errori o prove saltate.
Rapporto `rest/writable/fse-validation-reports/20260911-195342-5b716faf/summary.json`,
`passed_offline`, sorgenti censiti immutati durante la verifica.

Nuovo laboratorio effettivamente eseguito su Windows / PHP 8.3.6 / MySQL 8.3,
in `rest/writable/fse-app-labs/7e38c28be56d45389280a8de19d8f692`:

- `rehearsal-report.json`: tre documenti generati e firmati con fixture sintetiche;
  creazione, sostituzione, metadati, cancellazione e timeout **simulati**, senza
  modificare i referti sorgente. Nessuna firma qualificata o chiamata regionale.
- `http-report-6f2c112b096b4dd1bd5696888d05a79c.json`: nove controlli superati su
  HTTP reale. Login/sessioni, download con hash del firmato, rapporto tecnico,
  CSRF mancante/scaduto/di altra sessione, accesso anonimo e dopo logout;
  lo studio B non può ottenere referto, allegato, rapporto o profilo di A.
  Righe FSE, eventi e simulazioni identici prima/dopo. Il primo rapporto HTTP
  `http-report-a136b30ebd3c4087a5603b169bb2c24a.json` copriva otto controlli;
  quello successivo aggiunge CSRF tra sessioni e il diniego della scheda referto.
  Nessuna verifica visuale del browser/JavaScript eseguita in questa sessione.
- `recovery/f141afcff4289941/result.json`: backup autenticato e ripristino in
  **tre database nuovi**, nove artefatti ricostruiti. Verificati righe, hash,
  decifrazione, catena di versioni, profilo predefinito, separazione tenant,
  rifiuto di chiave errata/file alterato/artefatto assente e assenza di reinvii
  automatici per operazioni incerte. Database e artefatti sorgente invariati.
  Rimosso soltanto il job temporaneo sintetico scaduto creato dalla prova;
  il job con lock attivo è stato preservato. Database, backup e referti di test
  sono conservati nell'area privata: nessun reset o eliminazione di dati reali.

Il ripristino non presume più numeri di referto fissi o testo digitato a mano:
seleziona una sola coppia originale/revisione coerente e confronta i valori
origine. Le prove negative coprono ID non consecutivi, padre mancante, doppioni,
catene ambigue e snapshot diversi. Non è ancora disaster recovery dell'intera app.

Procedure in [app-lab.md](../../ops/fse-validation/app-lab.md). Gli script Windows
sono stati eseguiti con PowerShell 7. Lo stop del web verifica marker HTTP,
percorso del lab, porta e prima installazione PHP risolta dal comando effettivo;
la lettura delle porte fallisce esplicitamente se CIM non è accessibile.
Arresto MySQL tramite controllo del datadir e comando SHUTDOWN solo sull'istanza
sintetica. **Verifica finale: nessun listener su 8088 e 33079**; gli altri servizi
non sono stati fermati. Coolify demo/login ancora `running:healthy`.

Rigenerato il contesto Linux `rest/writable/fse-linux-labs/ce32eb90cd98474481ef69a3c1427e26`,
con i nuovi helper inclusi: 4048 sorgenti app e 50 file runtime, controllo
`PREPARATION_CHECK_PASSED_NOT_STARTED`. Nessuna build o esecuzione Linux;
nessuna nuova risorsa, release, migration o configurazione di produzione.
Restano separati capacità del server, firma effettiva, adapter CART e abilitazioni.

## Precedente verifica dell'11 settembre: ambiente worker e preflight

Ultimo rapporto completo: **218 test PHP / 1145 asserzioni, 24 test Python
documentali, 12 test del pacchetto**, zero errori, fallimenti o test saltati.
`rest/writable/fse-validation-reports/20260911-192904-2c0823c5/summary.json`:
`passed_offline`, sorgenti censiti immutati durante l'esecuzione. I test delle
ricette sono ora parte dell'esito complessivo ma restano separatamente marcati
come prove offline, non esecuzione Linux.

- Il worker riceve soltanto variabili di sistema ammesse; segreti applicativi,
  proxy e opzioni degli interpreti non sono ereditati. Provato con un processo
  figlio reale e valori esclusivamente sintetici; la prova completa Python/Java
  funziona anche con un'opzione Java volutamente non valida nel processo padre.
  Nessuna modifica permanente all'ambiente del chiamante. Non è una sandbox OS.
- Aggiornato anche lo smoke test indipendente da CodeIgniter: esito
  `passed_app_runtime_smoke` su Windows, PDF/A verificato e discordanza CDA/PDF
  rifiutata. Evidenza: `rest/writable/fse-stage-smoke-52c8f3bc5184284b/report.json`.
- Nuovo contesto `rest/writable/fse-linux-labs/bcf3f1de67504460a169c43b0f477018`:
  4045 file app e 50 runtime verificati. Include `.dockerignore` dedicato;
  il controllo preventivo rifiuta ricette alterate, file mancanti e riferimenti
  immagine mutabili. Esito `PREPARATION_CHECK_PASSED_NOT_STARTED`, senza digest
  immagine forniti e senza interrogare Docker. Il vecchio contesto è correttamente
  rifiutato perché manca la nuova protezione del contesto di build.
- Disponibilità delle wheel verificata con `pip download`, richiedendo CPython
  3.11 / Linux x86_64 / tag manylinux fino a glibc 2.36: **30 pacchetti**, circa
  64 MiB. Nessuna installazione o cambio versione. Inventario e SHA-256 privati:
  `rest/writable/fse-linux-wheels/40ba866fdb264a7d832d379270c7de9d/availability.json`.
  È una verifica di disponibilità delle distribuzioni, non di import/esecuzione,
  sicurezza delle dipendenze, completezza dei marker Linux o compatibilità effettiva
  di tutte le librerie native. Il Dockerfile non usa ancora questa cache locale.
- `pip check` sul runtime Windows: nessuna dipendenza incoerente rilevata. Non è
  una scansione vulnerabilità. [Metodo ufficiale di download cross-platform](https://pip.pypa.io/en/stable/cli/pip_download/).

Nessuna chiamata Gateway, nuovo servizio o modifica di produzione in questo
incremento. Verifica Coolify in sola lettura: demo e login `running:healthy`.
Restano necessari ambiente Linux idoneo, prove reali CART, firma qualificata
effettiva, identificativi assegnati e abilitazioni formali. Non è stato acquisito
un server o aumentata la capacità senza una decisione dell'utente.

## Prima verifica dell'11 settembre: preparazione Linux e capacità server

Ripetute le suite offline: **213 test PHP / 1135 asserzioni e 24 test Python**,
senza errori, fallimenti o test saltati; sorgenti censiti invariati durante la prova.
Report: `rest/writable/fse-validation-reports/20260911-184219-8400fc46/summary.json`.
Altri **6 test del pacchetto Linux** superati, separati da queste suite.
Manifest verificati per 4043 file applicativi e 50 file runtime; l'inizializzatore
Linux rifiuta correttamente l'esecuzione su Windows.

Contesto: `rest/writable/fse-linux-labs/24fc0a1894d74c8cb1c59601c07b9a5f`.
Il file `preflight.json` conserva hash, misura del server e stato delle risorse.
Ambiente `fse-precollaudo` creato su Coolify; sonde diagnostiche verificate ferme.
Demo/login rimangono `running:healthy`, entrambe HTTP 200. Nessun rilascio live,
nuovo DB applicativo remoto o invio Gateway in questa sessione.
Il laboratorio Linux **non è costruito né avviato**: capacità RAM insufficiente
per procedere prudentemente sul server condiviso. Dettagli e passaggi mancanti
nella [guida Linux](../../ops/fse-validation/linux-lab.md).

## Incremento del 10 settembre: referto → precollaudo Toscana

Suite finale: **213 test PHP, 1135 asserzioni; 24 test Python**, zero errori,
fallimenti o test saltati. Evidenza privata:
`rest/writable/fse-validation-reports/20260910-221731-e63ee393/summary.json`.
Esito `passed_offline`, 134 sorgenti censiti immutati durante la prova, chiamate
Gateway `NOT_EXECUTED`. Il precedente run `20260910-221453-427495e2` è incompleto
per modifica dei sorgenti durante la verifica grafica e non va usato come esito
finale. Il nuovo run sopra lo sostituisce. `git diff --check` senza errori.

Il pulsante di collegamento è esposto solo nel bootstrap sintetico isolato.
Gli artefatti effettivamente prodotti e firmati nel gestionale vengono ricontrollati;
nel laboratorio separato entrano soltanto ID locali e hash, non dati clinici,
credenziali o copie dei PDF. Le quattro operazioni restano simulate e non aggiornano
il referto sorgente. Un import ripetuto non azzera una richiesta incerta.

Prova integrata Windows/MySQL completata il 10 settembre alle 20:12:19 UTC:
`rest/writable/fse-app-labs/5d85709744aa4db690c4afd7f1934879/rehearsal-report.json`.
Tre artefatti RSA/PDF/A con firme sintetiche; creazione, sostituzione, aggiornamento
metadati, cancellazione rifiutata e poi confermata, timeout bloccato. Le righe dei
tre documenti sorgente sono rimaste identiche, tutte in stato `signed`. Verificato
il diniego dal tenant B e l'assenza di stato importato nel suo laboratorio.
Zero chiamate esterne; nessuna firma qualificata e nessun accreditamento attestato.

Verifica browser separata: login A con sessione effettiva → referto #2 → POST del
pulsante con CSRF → laboratorio con snapshot ed esiti chiaramente simulati →
ritorno al referto ancora «Firmato». Le icone del laboratorio usano risorse locali.
I documenti #2/#3 sono versioni 1/2; #4 copre il timeout. La bozza #1 deriva da
un primo tentativo del solo harness interrotto prima della firma e resta conservata.
La [guida del laboratorio](../../ops/fse-validation/app-lab.md) permette di ripetere
le prove in un nuovo datadir senza modificare i precedenti.

Verifica browser B completata: l'URL del referto A rimanda alla lista vuota di B;
anche il laboratorio B risulta vuoto. Scheda di prova chiusa, server web 8088
arrestato e MySQL 33079 arrestato regolarmente con controllo del datadir. Tutti
i file del laboratorio sono conservati; nessuna modifica a dati reali o `.env`.

Il dispatcher operativo CART e le sue risposte vere rimangono da completare e
collaudare: questo incremento è un ponte di precollaudo, non una pubblicazione FSE.
Il runtime Linux e lo staging completo non sono stati eseguiti. Demo/login Coolify
sono stati soltanto letti: entrambi `running:healthy`, health HTTP 200.

## Funzioni disponibili nel codice

| Area | Preparazione implementata | Cosa non attesta |
| --- | --- | --- |
| Prontezza per spazio | Dashboard, autotest sintetico XSD/Schematron/PDF/A, controllo coppie mTLS/JWT e scadenze, presenza materiale fiduciario | Accreditamento o autorizzazione della struttura |
| Correzioni | Nuova bozza collegata, motivazione cifrata, originale conservato, identificativo nuovo, setId stabile, versione incrementata, CDA RPLC | Sostituzione già avvenuta sul FSE |
| Concorrenza | Un solo successore per originale, token anti-salvataggio obsoleto, blocchi durante generazione/firma/invio | Collaudo di carico sul database MySQL di destinazione |
| Firme | Verifica crittografica, identità del firmatario, integrità del CDA/PDF, revoca offline obbligatoria | Qualifica eIDAS, QSCD o valutazione Trusted List UE |
| Diagnosi | Lettura degli invii pendenti/incerti, correlazioni tecniche, blocco del reinvio | Riconciliazione automatica o possibilità di sbloccare a mano il DB |
| Toscana | Modello offline: creazione, sostituzione, metadati, eliminazione, timeout e risposta incompleta | Connettività CART, interpretazione degli esiti reali, accettazione regionale |
| Profili sede/regime | Configurazione guidata, checklist, più profili per spazio, default per nuove bozze, snapshot sui referti, salvataggi concorrenti protetti | Identificativi assegnati, autorizzazione multi-impianto o accreditamento regionale |
| Recupero sintetico | Backup autenticato e ripristino in nuovi DB/storage, hash e decrittazione, storico e profili, diagnosi job interrotti | Disaster recovery produttivo, custodia/off-site e tempi di ripristino in esercizio |

Le viste hanno test automatici con un harness sintetico e, dall'8 settembre, un
collaudo browser del modulo FSE con login/sessioni reali, header/sidebar reali,
MySQL 8.3 su datadir nuovo, due database tenant separati e firma PAdES sintetica.
Verificati bozza, CDA/PDF, rifiuto del PDF alterato, firma integra, correzione,
profilo SSR separato e accesso negato a documenti/profili dell'altro spazio.
Il laboratorio esclude agenda, PWA e funzioni estranee: non è un E2E dell'intero
gestionale, del dispositivo di firma effettivo o degli enti esterni.
La [procedura ripetibile Windows](../../ops/fse-validation/app-lab.md) descrive
isolamento, firme inventate, recupero e arresto senza servizi permanenti.

Le route usano la guardia FSE per responsabili dello spazio. Il controllo dell'8
settembre ha rilevato token senza filtro attivo: aggiunto `FseCsrfFilter` alle
sole rotte FSE, comprese impostazioni/alias login e cookie sui redirect.
I test controller includono accesso negato, modulo spento, OTP pendente, flag
di abilitazione manomesso ignorato e passphrase escluse dal ripristino del form
in sessione dopo errori. Le migration temporanee usano percorsi canonici, corretti
anche su Windows con separatori misti.

## Configurazione per sedi e regimi

La pagina consente profili distinti con codice sede/impianto, regime e audience.
La checklist distingue campi inseriti da verifiche/abilitazioni ufficiali. Il
salvataggio dalla guida mantiene sempre gli invii disabilitati, anche con POST
manomesso. Cambiare default invalida le vecchie schede di configurazione aperte.

Ogni nuova bozza conserva l'ID del profilo e uno snapshot di organizzazione,
percorso e identità applicativa, senza CF medico, chiavi o passphrase. Lo snapshot
si propaga alle correzioni. Cambiare default/configurazione non reindirizza un
referto esistente; credenziali ruotate e interruttore di disabilitazione restano
quelli correnti del profilo associato. Il regime deve corrispondere sul server.
Per cambiare sede/routing di una bozza serve crearne una nuova; i vecchi documenti
senza snapshot non vengono migrati inventando la loro configurazione storica e
restano bloccati per l'invio. La gestione dei medici/operatori effettivi e i valori
assegnati devono essere concordati nel percorso di attivazione della struttura.

## Riprodurre e raccogliere le evidenze

Prerequisiti nel [runtime documentale](../../ops/fse-validation/README.md).

```powershell
./ops/fse-validation/collect-evidence.ps1
```

Ogni esecuzione crea una cartella nuova sotto
`rest/writable/fse-validation-reports/<data-id>/` (privata, ignorata da Git), con:

- `python-tests.json`: numero di test, errori, fallimenti e prove saltate;
- `php-junit.xml`: risultato PHPUnit con test di database SQLite e servizi;
- `summary.json`: stato complessivo, commit di base, presenza di modifiche locali,
  hash SHA-256 dei file FSE elencati e indicazione `OFFLINE_SYNTHETIC_ONLY`.

Il rapporto è `passed_offline` solo se entrambe le suite terminano con esito
positivo e senza test saltati. Errori o esecuzioni interrotte rimangono documentati,
non vengono sovrascritti da un tentativo successivo. Gli hash non sono una firma
digitale del rapporto, né un'attestazione dell'intero ambiente di esecuzione.
Non inviare automaticamente questi file agli enti: attendere modalità e dataset
richiesti dalla nuova procedura. I report JUnit possono contenere percorsi locali.

Il laboratorio accessibile dalla dashboard può inoltre scaricare un rapporto JSON
degli otto scenari fissi. Non è il rapporto delle suite crittografiche/documentali.

## Gestione delle correzioni

1. Aprire un referto consolidato, non in corso di invio e non eliminato.
2. Indicare il motivo e aprire la correzione. Ripetere l'azione riapre la stessa
   bozza: non genera un secondo successore.
3. Correggere il contenuto e rigenerare gli artefatti. Il paziente/collegamento
   anagrafico non può essere sostituito con quello di un altro soggetto.
4. Il CDA contiene il riferimento verificato al CDA originale; il PDF riporta
   versione e identificativo precedente. La firma precedente non viene copiata.
5. La nuova versione resta locale: il dispatcher vieta di pubblicarla come un
   documento nuovo. Il percorso di sostituzione operativo sarà collegato dopo
   conferma del contratto regionale e relativo collaudo.

Un originale privo del CDA integro non può essere clonato presumendo il suo OID
dal profilo corrente. Un referto firmato poi scartato non diventa modificabile.
La motivazione resta nella cartella locale, cifrata; non viene aggiunta ai log.

## Riconciliazione e controlli conservativi

- Un timeout o un'eccezione durante un invio non autorizza a ripeterlo. Anche
  un'eccezione di preflight non distinguibile con certezza può richiedere assistenza.
- Risposte accettate senza workflow sono trattate come incerte. Gli identificativi
  di una precedente validazione non vengono riutilizzati per la pubblicazione.
- Il polling nazionale considera solo eventi terminali espliciti e correlati:
  `UAR_FINAL_STATUS` per pubblicazione e `INI_DELETE` per cancellazione. I percorsi
  INI-only o altri contratti restano pendenti: questa policy deve essere concordata
  nel collaudo, non allargata sulla base di un generico `SUCCESS`.
- Una risposta tardiva non può sovrascrivere un'operazione più recente. Un successo
  di validazione, PUBLICATION o SEND_TO_INI, da solo, non è prova di conclusione FSE.
- Messaggi arbitrari, subject e contenuti SOAP del Gateway non vengono conservati
  nelle nuove risposte/audit: si mantengono solo campi tecnici ammessi. Non è stata
  eseguita alcuna bonifica distruttiva dei log storici.
- I job locali rimasti oltre cinque minuti sono segnalati, non sbloccati. Prima
  di intervenire: verificare che il worker sia terminato, controllare audit e hash,
  accertare se sia partita una chiamata. Non modificare stati con SQL manuale.
- Se il sistema operativo trattiene file temporanei dopo un timeout, l'operazione
  fallisce comunque. I file restano nell'area privata `writable/fse2/validation-jobs`;
  `ops/fse-validation/cleanup-jobs.php` esegue una verifica senza eliminazione.
  La modalità esplicita `--apply` pulisce solo job riconosciuti, non attivi e
  scaduti da 24 ore; referti e certificati sono esclusi. Nessun job automatico
  installato e nessuna pulizia applicata ai dati dell'app durante questo task.
- Il validatore ammette al massimo due worker per filesystem locale (configurabile
  1–8); i lock sono provati anche tra processi PHP distinti su Windows. Un crash
  può lasciare figli orfani: non è ancora collaudo di risorse su repliche Linux.
- Il bootstrap della suite dedicata esclude `.env` e configura tutti i gruppi DB
  standard come SQLite in memoria; le impostazioni DB del gestionale non sono usate.

## Dati e decisioni ancora necessari

| Responsabile / fonte | Elementi da acquisire | Stato |
| --- | --- | --- |
| Team nazionale | Repository/regole aggiornate, eventuale nuovo EuSurvey, conferma dataset e sessione | Certificati di test ricevuti il 9 settembre; VERIFICA nazionale funzionante. Iter formale ancora sospeso, nessun accreditamento ottenuto |
| Regione / CART | Adesione fornitore/prodotto, profilo RSA e ambiente, audience JWT, esiti e recupero stato, impianto e regime | Da confermare per iscritto |
| Struttura | Identificativi/OID assegnati, organizzazione, impianto, regime, repository, medici e autorizzazioni | Da acquisire senza copiare accreditamenti altrui |
| Struttura / prestatore firma | Dispositivo/servizio PAdES effettivo, qualifica, policy fiduciaria, rinnovi e revoche | Da scegliere e collaudare |
| Regione / responsabile clinico | Codici prestazione e terminologie ammesse, oscuramento, eventuale recupero documenti/FHIR | Da definire; nessuna regola clinica inventata |
| Gestore del bando | Ammissibilità, termini e documentazione della spesa, eventuale cambio fornitore | Fuori dal collaudo software; nessuna garanzia di rimborso |

## Prima di una futura attivazione

Priorità aggiunta dall'audit dell'11 settembre: collaudare gli aggiornamenti di
sicurezza del prodotto e del framework in un ambiente separato. Non distribuire
la proposta di lock privata come se fosse già verificata da questa suite FSE.

Aggiornamento infrastrutturale 11 settembre 2026: creato l'ambiente Coolify
`fse-precollaudo`, ma **nessun laboratorio FSE Linux avviato**. La sonda isolata
ha rilevato circa 855 MiB di RAM disponibile e circa 3 GiB di swap occupata.
Build e DB rinviati per non gravare sulla produzione. Preparazione, misure e
passaggi mancanti nella [guida del laboratorio Linux](../../ops/fse-validation/linux-lab.md).

- Eseguire le migration FSE su un ambiente dedicato, con backup e verifica:
  `AddFseServiceDescription`, `AddFseDocumentRevisions` e `AddFseProfileSnapshot`.
  Provate su SQLite e MySQL sintetici; non applicate ai database reali o produttivi.
- Preparare e collaudare il runtime Linux separato: l'ambiente Python/Java Windows
  usato qui non viene portato in produzione da un semplice deploy del repository.
  È pronta la [ricetta di prova isolata](../../ops/fse-validation/runtime-container.md)
  con pacchetto senza credenziali: preparazione verificata su Windows, build/avvio
  Linux non eseguiti perché Docker non è disponibile. Non è l'immagine produttiva.
- Acquisire e verificare credenziali/identificativi per impianto e regime: la guida
  multi-profilo è implementata, ma non assegna identità o abilitazioni regionali.
- Confermare metadati, terminologie, oscuramento e transizioni reali con CART.
- Eseguire test end-to-end in staging con dati ufficiali ammessi, firma effettiva,
  errori/revoche/timeout e separazione tenant. Raccogliere esiti e correlazioni reali.
- Completare gli iter richiesti e ricevere le abilitazioni. Il blocco operativo
  Toscana e quello delle sostituzioni non vanno rimossi con modifiche manuali al DB.
- Solo successivamente pianificare un rilascio esplicito, backup, verifica migration,
  healthcheck e avvio controllato su una struttura pilota.

## Riferimenti tecnici

- [Interfacce Gateway nazionali](https://github.com/ministero-salute/it-fse-support/tree/main/doc/integrazione-gateway): operazioni e correlazione degli stati, documento consultato il 7 settembre 2026.
- [Cataloghi ufficiali CDA](https://github.com/ministero-salute/it-fse-catalogs): snapshot fissato nel runtime, con XSD e Schematron RSA.
- [Scenario Toscana strutture private](https://compliance.toscana.it/portale/it/scenari/fse-2-0-strutture-private/): profili, specifiche, adesione CART e documentazione regionale.
