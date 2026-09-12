# Controlli locali documentali FSE

Implementazione locale, non accreditamento. Non abilita produzione o Toscana.
Non installare automaticamente questo runtime sul server live.

Dal 9 settembre 2026 sono disponibili anche comandi CLI separati per le
[prove preparatorie sul Gateway nazionale](gateway-test.md), con certificati
Sogei reali di test e soli dati sintetici/ufficiali. Le suite descritte sotto
restano offline; i certificati non abilitano Toscana né produzione.

Per il collaudo completo del modulo tramite login reale, due studi sintetici,
profili sede/regime, caricamento PAdES e backup/ripristino su MySQL Windows,
seguire la [procedura del laboratorio isolato](app-lab.md).

## Cosa viene verificato

Il worker PHP → Python usa ora un ambiente minimo: non eredita password DB,
token, configurazione SMTP, proxy o opzioni arbitrarie degli interpreti Java/Python.
PATH e percorsi temporanei/di sistema necessari sono ammessi; lingua e fuso sono
fissati. Questo riduce l'esposizione accidentale, **non è una sandbox**: il processo
conserva i permessi dell'utente che lo esegue. Non sostituisce isolamento OS,
controlli di rete, account separati o limiti di risorse. Il percorso settings del
validatore continua a essere passato esplicitamente nel job privato.

Riferimento: [ambiente del processo in PHP](https://www.php.net/manual/en/function.proc-open.php).

- CDA RSA: XSD UV02 e Schematron RSA 8.3 del catalogo ufficiale, revisione
  `687cf371e1d0caf4f5f9f7bcc80eab3a97f09885`; hash dei file e regole in ogni report.
- PDF/A-3b: verifica effettiva con veraPDF 1.30.2, prima e dopo la firma.
- Un solo `cda.xml`, identico byte per byte, anche nel riferimento PDF `/AF`.
- Firma PAdES: CMS, integrità, copertura dell'intero file, catena esplicitamente
  attendibile, revoca obbligatoria, CF del firmatario uguale al medico.
- Confronto incrementale con il PDF originale: modifiche alle pagine respinte,
  consentite solo le modifiche di firma ammesse dalla policy pyHanko. La prima
  firma può impostare SigFlags da 0 a 3; nessuna deroga per il contenuto clinico.
- Gli invii ricontrollano gli artefatti salvati e i loro SHA-256. Un record
  precedentemente etichettato `signed` non aggira i nuovi controlli.

## Installazione per collaudo isolato

Prerequisiti: Python 3.12, Java, PHP e dipendenze di test già installate.
Usare una directory privata NON servita dal web, esclusa da Git e dai backup
pubblici. Non usare certificati dei pazienti, chiavi mTLS o chiavi JWT come radici
di fiducia per la firma clinica.

1. Creare un virtualenv e installare `requirements.txt` con il suo interprete:

   ```powershell
   & '<python-3.12>' -m venv rest/writable/fse-validation-venv
   & 'rest/writable/fse-validation-venv/Scripts/python.exe' -m pip install -r ops/fse-validation/requirements.lock.txt
   ./ops/fse-validation/fetch-catalog.ps1
   ```

2. Scaricare [SchXslt 1.10.1 dall'autore su Maven Central](https://repo.maven.apache.org/maven2/name/dmaus/schxslt/schxslt/1.10.1/schxslt-1.10.1.jar)
   ed estrarlo come archivio ZIP nella directory privata. SHA-256 dell'archivio
   verificato in questo collaudo: `4f4f21edab7b37f96ad59ae12a344d3510f1092ac46b6d81a4efa0120b73cb58`.
3. Installare [veraPDF 1.30.2](https://software.verapdf.org/releases/1.30/verapdf-greenfield-1.30.2-installer.zip)
   in una directory dedicata, con la [procedura ufficiale](https://docs.verapdf.org/install/).
   SHA-256 dell'archivio: `6cc6341cb1af644044054b81f00a6590a7918abb18f762243de115258bcad838`.
   Questi hash documentano i download HTTPS del collaudo, non sostituiscono la
   verifica delle firme dei distributori per un rilascio.
4. Creare la configurazione privata usando `settings.example.json`, con percorsi
   assoluti. Radici fiduciarie vuote significano **firma bloccata**. `crls` e `ocsps`
   sono liste di file DER, aggiornati e verificabili, non URL. L'applicazione non
   scarica risorse di rete indicate nei certificati o nei PDF.
5. Impostare nel solo ambiente di prova:

   - `FSE2_VALIDATOR_PYTHON`: interprete del virtualenv;
   - `FSE2_VALIDATOR_SETTINGS`: file JSON privato;
   - `FSE2_VALIDATOR_TIMEOUT`: massimo 60 secondi, default 55.
   - `FSE2_VALIDATOR_MAX_CONCURRENT`: worker simultanei per storage locale,
     default 2, minimo 1 e massimo 8. Saturazione: operazione rifiutata prima
     della creazione del payload; riprovare esplicitamente, nessuna coda nascosta.

Il progetto include `run-local-tests.ps1`: imposta queste variabili solo nel suo
processo, esegue i test Python e PHP e ripristina i valori precedenti. **Non**
modifica `.env`, DB live, Coolify o storage produttivi. I test PHP DB usano SQLite
dedicati; quelli delle firme generano CA, chiavi e CRL sintetiche temporanee.
La suite dedicata `phpunit.xml` usa un bootstrap che non carica `.env` e sostituisce
la configurazione Database con gruppi SQLite in memoria. I test dei controller
non possono risolvere i gruppi predefiniti verso database dell'applicazione.

```powershell
./ops/fse-validation/run-local-tests.ps1 -Python '<virtualenv>/Scripts/python.exe' -Settings '<config-privata.json>'
```

Per un dossier ripetibile, usare `collect-evidence.ps1` con gli stessi parametri:
genera JSON/JUnit in una nuova cartella privata `rest/writable/fse-validation-reports/`,
con esito delle suite, hash dei sorgenti prima e dopo la prova e stato esplicitamente
offline. Non considera superata una prova saltata o eseguita mentre i sorgenti cambiano.
Il [dossier di collaudo](../../rest/docs/fse2-dossier-collaudo.md) descrive funzioni,
limiti, procedure e dati da acquisire alla ripresa degli iter ufficiali.

La dashboard distingue controllo rapido da autotest completo: quest'ultimo genera
e verifica un documento fittizio, senza restituzione degli artefatti o chiamate di rete.
La configurazione delle radici/CRL/OCSP non equivale a firma valida o qualificata.

## Limiti da non confondere con conformità operativa

- XSD/Schematron sono una verifica locale su una revisione fissata. Non coprono
  tutto il dataset di accreditamento, il servizio di terminologia del Gateway o
  le regole organizzative regionali. Verificare la revisione concordata al collaudo.
- Il builder ora richiede `service_description`: testo inserito dall'operatore,
  cifrato tramite migration `AddFseServiceDescription`. Non viene dedotto dal titolo.
  Il codice prestazione non disponibile è rappresentato con `nullFlavor=OTH` e
  riferimento alla descrizione: l'ammissibilità per il regime va confermata in stage.
- Il controllo crittografico non attesta qualifica eIDAS/QSCD o stato del prestatore
  nelle Trusted List UE: il report restituisce `qualified_signature=not_assessed`.
  Prima della produzione servono policy fiduciaria approvata, firma qualificata
  scelta dalla struttura e gestione delle evidenze di revoca aggiornate.
- Policy intenzionalmente conservativa: una sola firma, una sola revisione
  incrementale su un originale a revisione singola, CF nel serialNumber del
  certificato (CF oppure `TINIT-CF`). File riscritti, ulteriori firme, LTV/LTA con
  revisioni aggiuntive o codifiche identità differenti sono respinti, non aggirati.
- Catalogo, compilatore, Java e librerie devono essere amministrati come codice
  fidato e protetti da scritture del processo web. Runtime produttivo/container,
  aggiornamenti controllati e limiti di risorse devono essere collaudati prima
  di un rilascio. Il Dockerfile di produzione non è stato modificato.
- I vecchi PDF generati senza PDF/A non diventano conformi retroattivamente.
  Preparare nuove bozze di prova; non sovrascrivere referti validati o firmati.
- Gli stati `preparing` e `checking_signature` impediscono invii/salvataggi
  concorrenti. Un'eccezione ripristina lo stato precedente; un arresto brutale
  richiede riconciliazione amministrativa, mai sblocco cieco.

Il runtime restituisce solo codici diagnostici e hash. Messaggi libxml, certificati,
CF e testi clinici non sono copiati nei log/audit. I job sono temporanei con accesso
privato; un processo può lasciare file dopo un arresto brutale.

## Gestione temporanei e server (8 settembre 2026)

```powershell
php ops/fse-validation/cleanup-jobs.php
```

È solo una verifica: non elimina dati. `--apply` rimuove esclusivamente job
riconosciuti, scaduti da almeno 24 ore e senza lock attivo. Non visita lo storage
dei referti e non cancella ricorsivamente. File sconosciuti, link, cartelle recenti
e vecchi job senza marker sono lasciati all'operatore. Il rapporto contiene solo
conteggi. Nessuna pianificazione automatica della pulizia è stata installata.
I file `slot-*.lock` non devono essere cancellati mentre l'app è attiva.

Il semaforo protegge processi PHP che condividono lo stesso filesystem locale;
non è un limite globale tra server/repliche né una soluzione per NFS. Variare il
limite solo dopo aver drenato i worker. Un kill del processo padre richiede verifica
dei figli/orfani: i limiti di container e la procedura operatore restano necessari.
Su Windows i permessi PHP `chmod` non sostituiscono ACL NTFS private; su Linux
catalogo, runtime e impostazioni devono essere non scrivibili dall'utente web.

Le rotte FSE (console e impostazioni, inclusi alias login) hanno ora un filtro CSRF
esplicito, con trasferimento del cookie rigenerato sulle risposte redirect/download.
Test verificano guardia reale dei controller, ruoli, OTP pendente, modulo spento,
token assente/errato, cookie e mutazioni vietate agli operatori. Non sostituiscono
il percorso browser completo login → compilazione → upload sull'app in staging.

Per il server è disponibile una [ricetta container isolata](runtime-container.md):
pacchetto sintetico generabile da Windows, non un deploy. Docker e il collaudo
Linux non sono stati eseguiti su questo PC. I risultati aggiornati delle suite
sono nel `summary.json` prodotto da `collect-evidence.ps1`.

## Verifiche eseguite il 7 settembre 2026

- 23 test documentali Python superati, inclusi XSD, Schematron, revisioni e PDF/A prima/dopo
  firma, firma alterata, certificato scaduto/revocato/non fidato, revoca assente,
  CF incoerente, sostituzione XML, pagina sostituita e byte dopo la firma.
- 90 test PHP FSE superati (280 asserzioni), incluso il runtime Python reale,
  blocco di pubblicazione/upload, ripristino stato dopo errore e migration
  idempotente senza perdita del contenuto esistente. Nessun test saltato in
  questa esecuzione con `FSE2_RUN_ARTIFACT_INTEGRATION=1`.
- Lint PHP, `git diff --check` e `pip check` superati.
- Campione PDF sintetico renderizzato con Poppler e verificato visivamente;
  date leggibili, testo presente e nessun problema evidente di impaginazione.
- Nessuna modifica a `.env`, nessun deploy o invio Gateway, nessun dato clinico
  reale e nessuna migration su database produttivi.

Fonti: [catalogo Ministero](https://github.com/ministero-salute/it-fse-catalogs),
[veraPDF](https://docs.verapdf.org/cli/validation/),
[pyHanko](https://github.com/MatthiasValvekens/pyHanko/blob/master/docs/lib-guide/validation.rst).
