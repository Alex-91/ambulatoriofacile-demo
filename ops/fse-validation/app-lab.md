# Collaudo applicativo FSE su Windows

Laboratorio **solo sintetico**. Non usare credenziali, referti o database reali.
Non è un ambiente cliente né uno staging da esporre su Internet.

## Precollaudo collegato ai referti — 10 settembre 2026

Nel bootstrap isolato compare sul referto il pulsante **Verifica e collega
snapshot al laboratorio**. Ricontrolla CDA, PDF/A, PAdES di test e hash e importa
solo identificativi locali e impronte, non testo clinico, CF, file o credenziali.
La pagina delle simulazioni è separata: nessun esito modifica `fse_documents`,
audit clinico o artefatti. L'importazione ripetuta non azzera operazioni pendenti.
Le versioni successive devono essere preparate e firmate nell'applicazione; non
si può inventare una revisione di uno snapshot dal pulsante del simulatore.

Il collegamento rifiuta ogni runtime ordinario prima di risolvere il tenant. Non
bastano `development`, un parametro POST o un flag `.env`: servono il bootstrap
privato, il percorso marcato, WRITEPATH isolato, i due tenant fittizi, porta,
database e datadir esatti. L'esistenza del pannello non abilita il Gateway.

Per ripetere il test integrato, preparare un **nuovo laboratorio**, avviare MySQL
ed eseguire il seed come sotto. Prima di avviare il web server:

```powershell
$env:FSE_LAB_ROOT = '<nuovo-laboratorio>'
$env:XDEBUG_MODE = 'off'
& rest/writable/fse-validation-venv/Scripts/python.exe ops/fse-validation/app-lab-rehearsal.py
```

Lo script usa le classi applicative e MySQL effettivi; prepara tre PDF/CDA,
firma con una chiave sintetica effimera mai salvata e simula quattro operazioni
più rifiuto e timeout. Controlla immutabilità delle righe sorgente e isolamento
del secondo tenant. La fiducia sintetica dura un'ora e non viene sovrascritta:
per una nuova sessione creare un nuovo lab. Non importare questa CA altrove.
Il rapporto `rehearsal-report.json` distingue esplicitamente queste prove dalle
chiamate regionali, dalla firma qualificata e dal percorso browser.

## Regressione HTTP e ripristino automatico — 11 settembre 2026

Dopo `app-lab-rehearsal.py`, avviare il web server isolato come sotto e, in un
altro terminale con lo stesso `FSE_LAB_ROOT`, eseguire:

```powershell
& rest/writable/fse-validation-venv/Scripts/python.exe ops/fse-validation/app-lab-http.py
```

Usa login e sessioni effettivi, ma **non un browser e non JavaScript**. Verifica
download firmato con SHA-256 corrispondente, rapporto tecnico senza campi clinici,
cache `no-store`, CSRF assente/scaduto/di altra sessione, accesso anonimo e logout,
diniego di referto/download/rapporto/profilo dello studio A allo studio B.
L'import dello snapshot già presente è idempotente: confronta impronte di righe,
eventi FSE e simulazioni prima/dopo. Non chiama pubblicazione o Gateway.

Il client ignora proxy e `.netrc`; accetta esclusivamente `127.0.0.1:8088`,
con intestazioni del laboratorio corrispondenti alla cartella selezionata.
Non segue redirect esterni. Password e cookie non entrano nel rapporto.
Ogni tentativo conserva `http-report-<id>.json`, compresi errori e prove incomplete.

Per fermare il solo server HTTP, dopo aver completato le prove:

```powershell
./ops/fse-validation/app-lab-stop-web.ps1 -LabRoot $env:FSE_LAB_ROOT
php ops/fse-validation/app-lab-recovery.php
php ops/fse-validation/app-lab-stop.php
```

Lo stop Windows verifica porta, intestazioni, eseguibile PHP e percorso del lab;
non ferma altri processi PHP. Errori di lettura delle porte/processi devono bloccare
il controllo, non essere interpretati come assenza del server. Nel runner Codex
la lettura CIM può richiedere l'autorizzazione di esecuzione prevista dal prodotto.

Il ripristino ora seleziona una coppia originale/revisione non ambigua, senza
presumere ID 1 e 2 o una dicitura fissa del testo. Rifiuta catene ambigue o rotte;
confronta testo decifrato e profilo predefinito con gli effettivi valori origine.
Restano i controlli su chiavi errate, backup/artefatti alterati, tenant e timeout.
È utilizzabile sia dopo il percorso manuale sotto sia dopo quello automatico.

Dopo l'avvio web si può verificare login → referto firmato → collegamento →
laboratorio → ritorno al referto ancora firmato. Gli esiti sintetici non devono
comparire come pubblicazioni del gestionale. Conservare anche il rapporto
separato del laboratorio, senza presentarlo come quattro X-CART-id ufficiali.

## Isolamento e prerequisiti

Usa il PHP disponibile e il MySQL 8.3 installato in `C:/wamp64/bin/mysql/mysql8.3.0`,
ma crea un **datadir nuovo**: nessun servizio WAMP o suo database viene riutilizzato.
Porte dedicate: MySQL `127.0.0.1:33079`, app `127.0.0.1:8088`.
Le porte 8085, la demo congelata, `.env`, Coolify e la produzione sono esclusi.
Serve il [runtime documentale locale](README.md) già configurato.

Il bootstrap non legge `.env`, elimina le variabili DB/FSE/SMTP ereditate e usa
solo database `fselab_*`, password generate e storage nella cartella marcata.
Il router usa login, sessioni, guardie, CSRF, controller, viste, header e sidebar
reali. Agenda e home rimandano alla dashboard FSE; altre funzioni del gestionale
sono escluse. Il debug toolbar è disattivato solo nel laboratorio; l'endpoint del
service worker PWA è escluso. Non è un collaudo dell'intero gestionale o della PWA.

Proteggere la cartella con ACL NTFS dell'operatore. I `chmod` PHP non creano ACL
Windows. `lab.json` contiene solo segreti sintetici ma non deve essere pubblicato.
Tutti gli output sono in `rest/writable/fse-app-labs/<id>` e ignorati da Git.

## Avvio

Da PowerShell nella root del repository:

```powershell
./ops/fse-validation/app-lab-prepare.ps1
```

Annotare il percorso stampato. Il comando rifiuta una porta 33079 già occupata;
non installa servizi. In un terminale dedicato:

```powershell
./ops/fse-validation/app-lab-mysql.ps1 -LabRoot '<percorso-stampato>'
```

In un secondo terminale:

```powershell
$env:FSE_LAB_ROOT = '<percorso-stampato>'
$env:XDEBUG_MODE = 'off'
php ops/fse-validation/app-lab-seed.php
./ops/fse-validation/app-lab-serve.ps1 -LabRoot $env:FSE_LAB_ROOT
```

Il seed si esegue solo una volta e controlla il datadir prima di scrivere.
Se fallisce, non tentare un reset del database: diagnosticare o creare un nuovo
lab dopo aver arrestato il precedente. Non utilizzare il server MySQL normale.

Aprire `http://127.0.0.1:8088/login`. Account **inventati**:

- `studio-a@fse.invalid`, password `FseLabOnly_2026!`;
- `studio-b@fse.invalid`, stessa password sintetica.

## Prova browser ripetibile

1. Accedere come A. Il profilo `Sede A — privato` è disabilitato.
2. Creare un referto con i dati inventati di `rest/tests/_support/fse_synthetic.php`:
   Mario Rossi, CF RSSMRA80A01H501U, nascita 1980-01-01, sesso M, inizio
   2026-09-07 10:00; prestazione «Visita specialistica sintetica per collaudo
   offline» e referto «Documento sintetico. Nessuna informazione clinica reale.».
3. Salvare e generare CDA/PDF. Il primo documento ha ID 1 in un lab nuovo.
4. Generare fixture di firma in un terzo terminale, con lo stesso FSE_LAB_ROOT:

   ```powershell
   & rest/writable/fse-validation-venv/Scripts/python.exe ops/fse-validation/app-lab-sign.py "$env:FSE_LAB_ROOT/writable/tenants/42/fse2/1/referto-da-firmare.pdf"
   ```

   Crea una CA, una CRL e due PDF sintetici. Le chiavi private non sono conservate;
   le impostazioni di fiducia sono **solo del lab**. La CRL dura un'ora, non è un
   servizio di rinnovo. Lo script rifiuta di sostituire una fiducia già generata.
   Non sono firme qualificate. Non importare la CA nel sistema o nel prodotto.
5. Caricare `signing/synthetic-altered.pdf`: atteso rifiuto
   `SIGNED_DOCUMENT_CONTENT_CHANGED` e possibilità di riprovare.
6. Caricare `signing/synthetic-signed.pdf`: atteso stato «Firmato», campi bloccati,
   nessun invio FSE. Aprire la correzione con motivazione sintetica.
7. Nella versione 2 impostare il referto «Documento sintetico aggiornato nella
   seconda versione. Nessuna informazione clinica reale.», salvare e generare.
   Lo storico conserva l'originale firmato; nessuna sostituzione sul FSE.
8. Configurazione: creare `Sede A — SSR test`, codice `IMPIANTO-TEST-A-SSR`,
   modalità Toscana, regime SSR, nuovo predefinito. I dati incompleti devono
   comparire in checklist; invii sempre disabilitati. Il referto 1 resta privato.
   Una nuova bozza propone il predefinito e il suo regime; cambiare profilo
   aggiorna il regime proposto, verificato nuovamente dal server al salvataggio.
9. Uscire e accedere come B: lista vuota; gli URL di modifica/download del referto
   1 non restituiscono dati di A; `login/spazio/fse2?profile=1` è rifiutato.

Le schermate sono state percorse con il browser dell'app il 08/09/2026. I test
unitari aggiuntivi coprono token obsoleti, associazioni profilo e regime,
separazione tenant e snapshot senza passphrase.

## Backup e ripristino simulati

Terminare prima il server web con Ctrl+C, lasciando acceso il MySQL del lab:

```powershell
php ops/fse-validation/app-lab-recovery.php
```

Lo script richiede il percorso manuale 1–9 oppure il rehearsal automatico; rifiuta altri datadir e un server
web ancora attivo. Produce un backup autenticato, crea **tre database nuovi**
con nomi casuali, ripristina tabelle FSE/profili e gli artefatti presenti in un nuovo
storage. Non configura l'app per usarli e non sovrascrive il laboratorio origine.

Verifica righe, hash, decrittazione, originali/versioni, snapshot e isolamento.
Prova chiave errata, manifest/file alterati, artefatto assente, operazioni locali
interrotte ed esiti remoti incerti: nessuno sblocco/reinvio automatico.
La sola eliminazione è un job temporaneo sintetico scaduto e senza lock; il job
attivo resta intatto. Report in `recovery/<run>/result.json`; tentativi falliti
restano distinti, non vengono cancellati. Questo non sostituisce un piano di
disaster recovery, backup off-site, custodia chiavi o test sui volumi produttivi.

Arresto del solo MySQL isolato, dopo aver fermato il server web:

```powershell
php ops/fse-validation/app-lab-stop.php
```

I database/file sintetici rimangono disponibili per ispezione. Nessuna pulizia
ricorsiva e nessun servizio permanente. Un riavvio del lab usa il datadir esistente,
senza `prepare` o `seed`; le fixture di firma scadute non vanno riutilizzate.
