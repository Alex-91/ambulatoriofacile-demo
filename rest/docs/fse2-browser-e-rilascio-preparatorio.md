# FSE — collaudo browser e preparazione al rilascio

12 settembre 2026. Attività locali, nessun commit/push/deploy, invio FSE o nuovo
passaggio formale di accreditamento. Le prove riducono il lavoro tecnico residuo;
non sostituiscono accreditamenti, firma qualificata o abilitazione della struttura.

## Percorso reale nel browser

Laboratorio nuovo `rest/writable/fse-app-labs/7980a5a01ee64efdbd478e567cbc6692`,
MySQL 8.3 dedicato su loopback 33079 e PHP 8.3.6 su 8088. Due studi fittizi, nessun
database/storage copiato dalla produzione, nessuna lettura delle configurazioni
reali. CodeIgniter candidato 4.7.4 e vendor root candidato caricati insieme.

Azioni eseguite tramite interfaccia e JavaScript applicativo, non chiamando i
servizi al posto del browser:

1. Login studio A, nuovo referto con anagrafica fittizia, salvataggio bozza #1.
2. Generazione CDA e PDF: stato «Da validare».
3. Upload di un PDF firmato alterato: rifiuto `SIGNED_DOCUMENT_CONTENT_CHANGED`.
4. Upload del PDF correttamente firmato: stato «Firmato», campi bloccati.
5. Collegamento dello snapshot al simulatore Toscana: creazione, ricevuta non
   definitiva, conferma positiva. Il simulatore mostra «Pubblicazione simulata»;
   il referto sorgente rimane «Firmato», senza pubblicazione reale.
6. Apertura correzione #2, modifica testo e generazione PDF versione 2.
7. Tentativo di allegare il PDF firmato della versione 1: `PDF_CDA_MISMATCH`.
8. Logout e login studio B: elenco vuoto; accesso diretto al referto A respinto
   con ritorno all'elenco, senza esposizione del documento.
9. Nuovo login A dopo la correzione della policy proxy e controllo completo dal
   pannello: XSD, Schematron e veraPDF superati sul documento sintetico.

Originale, firmato e revisione: una pagina ciascuno, renderizzati con Poppler ed
esaminati visivamente. Testi leggibili, nessun taglio rilevato; la revisione indica
esplicitamente che il collegamento clinico non attesta sostituzione sul FSE.
La firma usa una CA/CRL **sintetica ed effimera**, non un certificato professionale
o Sogei, e non è una prova di firma qualificata. Nessun rinnovo artificiale della
CRL per far superare il controllo. I certificati Gateway reali non entrano nel lab.

Nessun errore JavaScript rilevato; soltanto due avvisi di service worker PWA non
registrato, perché la rotta è esclusa dal router privato. Il collaudo non copre
quindi installazione PWA, tutti i browser o tutti i percorsi del gestionale.

## Riscontri e ripristino

`ops/fse-validation/app-lab-ui-audit.php` è una verifica CLI in sola lettura dello
stato lasciato dall'interfaccia, **non** un runner che attesta da solo azioni UI.
Controlla due versioni, cinque artefatti con hash, assenza di pubblicazione,
separazione fra simulazione e referto, riferimento CDA RPLC e studio B vuoto.

Nel laboratorio sopra:

- `browser-state-e6ee2e9bc25df4a8.json`: 15 controlli superati.
- `recovery/57edc2a5558878d6/result.json`: 10 controlli di ripristino superati;
  database nuovi, cinque artefatti, decifratura, versioni e profili preservati;
  chiave errata, backup alterato e artefatti mancanti respinti; nessuna modifica
  ai database e file sorgenti.
- `pdf-qa/`: tre immagini delle pagine esaminate.

Il ripristino riguarda le tabelle e gli artefatti FSE, **non** il disaster recovery
completo del gestionale. Durante il drill è eliminato solo un job temporaneo
sintetico scaduto, come previsto dalla prova; job attivo preservato. Backup e
altre evidenze mantenuti. PHP e MySQL del laboratorio arrestati a fine prova.

## Sicurezza e compatibilità

`Config/App.php` accetta `X-Forwarded-Proto` e `X-Forwarded-Host` soltanto quando
`REMOTE_ADDR` corrisponde alla mappa `proxyIPs`. Nuova `TrustedProxyPolicy` per IP
esatti/CIDR IPv4 e IPv6; rifiutati peer invalidi e reti universali. L'indirizzo del
peer non viene dedotto da `X-Forwarded-For`. Schema e authority inoltrati sono
validati; niente credenziali, path o caratteri di controllo.

Non è stato indovinato un CIDR Docker né attivata fiducia globale. **Prima del
rilascio** verificare gli IP effettivi del proxy, configurare la mappa precisa e
provare HTTPS, URL pubblici/sottopercorsi, redirect e cookie nell'ambiente Linux.
Il laboratorio forza la propria URL loopback: il login browser riuscito non
dimostra compatibilità con Traefik/Coolify. La correzione riguarda l'origine URL
applicativa, non tutta la gestione proxy del framework o un allowlist generale
di `HTTP_HOST`. Non sostituisce l'aggiornamento del framework.

Suite congiunta ampliata a **67 test / 279 asserzioni per variante**, superati sia
con dipendenze attive sia candidate: policy URL, slot/preferenze/stampe agenda,
accessi e sessioni, ritorno dai link di notifica, fatturazione e template/policy
notifiche. Nessuna notifica reale. Corretto un mock del test notifiche che usava
un'interfaccia priva di `isAJAX`, senza cambiare il servizio applicativo.

Provenienze nel lab `rest/writable/fse-dependency-compat/e90fe89c1b0f4b2ba1d127e9c53a127f`:
`application-candidate-2ae3173dd7b6cb0b/provenance.json` e
`application-baseline-680bcdbe65a3b813/provenance.json`.
Sono test mirati: non una certificazione di sicurezza generale, un pentest o un
collaudo UI completo di agenda, OTP, WebAuthn, messaggistica e allegati generici.
Per i documenti caricati, la copertura browser qui riguarda il PDF FSE; per la
fatturazione vale il [collaudo HTTP precedente](fse2-fatturazione-collaudo.md).

Controllati anche i 48 rapporti di provenienza delle invocazioni CLI/HTTP del
nuovo laboratorio: tutti sulla variante candidata 4.7.4, nessun rapporto fallito.
La provenienza non dimostra da sola la copertura funzionale di ogni libreria.

Regressione finale sul codice e sulle ricette aggiornate: **314 test superati**
(261 PHP / 1271 asserzioni, 24 documentali Python, 29 packaging/harness/audit),
nessun errore, fallimento o skip, sorgenti censiti immutati durante il run.
Report `rest/writable/fse-validation-reports/20260912-124814-37077650/summary.json`,
`passed_offline`. Questa suite usa il framework candidato e il vendor root
attivo; il collaudo con entrambi i candidati è descritto separatamente sopra.

Produzione letta soltanto per controllo: demo e login `running:healthy` su
`main` alle 10:42 UTC. Nessun aggiornamento dell'applicazione live o dei database.

## Pacchetto Linux e criteri di installazione

`prepare-linux-lab.ps1` può selezionare insieme i due laboratori candidati con
`-FrameworkLab` e `-DependencyLab`. Copia il framework 4.7.4 e il lock candidato
nel solo contesto privato, lasciando immutati `rest/system`, vendor e lock attivi.
Senza parametri produce la baseline, che **non** include quegli aggiornamenti.
Incluse anche le utilità sintetiche di fatturazione e riscontro browser.

I manifest registrano variante e origine dei file. `check_linux_recipe.py`
confronta i file inclusi con le sorgenti locali selezionate e rifiuta un pacchetto
coerente ma ormai vecchio, oltre a ricette alterate e file fuori manifest. Non
controlla file esclusi dal pacchetto o l'autenticità upstream. Cinque regressioni
aggiunte, inclusi gli asset retina `@2x`; 29 test packaging/harness/audit superati
senza rete. Il primo preflight ha respinto il contesto per una regola troppo
restrittiva sui nomi degli asset; dopo la correzione il controllo completo è
superato, senza rimuovere verifiche di origine o integrità.

Contesto `rest/writable/fse-linux-labs/0e71175140a94fc78de3354d87cacbb6`:
4.080 file applicativi e 50 runtime, variante candidata,
`PREPARATION_CHECK_PASSED_NOT_STARTED`. Tutti i file censiti corrispondono alle
sorgenti selezionate al momento del controllo.

Questo è un **pacchetto di collaudo**, non un installer produttivo: build Linux,
dipendenze native/wheel, digest reali delle immagini e isolamento a runtime non
sono ancora verificati. [Ricetta e limiti Linux](../../ops/fse-validation/linux-lab.md).
Docker/Podman assenti e WSL non installato sul PC. Accesso SSH al server rifiutato
con la prima chiave; la seconda non è in formato utilizzabile. Non sono state
alterate chiavi/accessi né installato un sistema operativo. La misura delle
risorse dell'11 settembre resta storica, non una verifica aggiornata.

## Sequenza di rilascio e rollback da applicare solo dopo i controlli mancanti

1. Chiudere collaudo Linux e proxy, firma reale e percorsi regionali richiesti.
   Selezionare versione prodotto, lock, runtime documentale e digest precisi.
   Il laboratorio non viene pubblicato come applicazione per i clienti.
2. Revisionare e committare **solo** i file del task; preservare le numerose
   modifiche estranee del checkout. Nessun segreto, `.env`, certificato, chiave,
   dump o materiale runtime nel commit. Integrare su `main` e push solo quando
   verrà autorizzato il rilascio; non spostare il live sul branch del task.
3. Provare backup coerente di DB platform, DB tenant, storage, configurazioni e
   chiavi in custodia separata. Provare il ripristino completo su destinazione
   nuova. Registrare immagine precedente e versione schema; non stampare segreti.
4. Revisionare tutte le migration pendenti: il bootstrap del container può
   eseguire `migrate --all`, quindi il perimetro non si deduce dai soli file FSE.
   Platform FSE: `AddFse2Feature`, `CreatePlatformTenantFseProfiles`. Per tenant:
   `CreateFseDocumentsTables`, `AddFseServiceDescription`,
   `AddFseDocumentRevisions`, `AddFseProfileSnapshot`. Provare prima nello staging
   sintetico e verificare schema completo per ogni studio selezionato.
5. `fse:schema-repair --tenant-id=<id-verificato>` è un comando **mutativo**, non
   un dry-run; usarlo soltanto nel contesto approvato dopo backup. Non usare
   `--all=1` per una prima attivazione e non lanciare comandi dal locale senza
   aver risolto e verificato database, ambiente e tenant effettivi.
6. Rilascio tramite flusso Coolify esistente, target esplicito demo/login/entrambi,
   `main` pulito e allineato al remoto. Invii FSE disabilitati finché mancano le
   abilitazioni; niente prove cliniche o automatiche verso Gateway di produzione.
   Health check target, login, sessioni/CSRF, fatturazione/PDF e controlli FSE.
7. Se fallisce: disabilitare l'operatività interessata, riconciliare operazioni
   con esito remoto incerto e ripristinare la precedente immagine/configurazione
   compatibile. **Non eseguire rollback schema indiscriminato**: le migration
   nuove rifiutano `down()` per conservare testi, storico e snapshot. Le vecchie
   migration possono essere distruttive. Ripristinare DB/storage solo con un
   piano coordinato che non perda documenti creati dopo il backup, conservando
   eventi e identificativi; niente retry ciechi né cancellazione di referti.

Questa checklist è preparazione operativa, non autorizzazione al rilascio.
