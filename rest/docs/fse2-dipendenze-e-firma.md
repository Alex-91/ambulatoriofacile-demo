# FSE: dipendenze, sicurezza e preparazione della firma reale

Verifica locale dell'11 settembre 2026. Non è un penetration test, un audit della
produzione, una certificazione legale delle licenze o una prova di accreditamento.
Nessuna dipendenza attiva, database reale o configurazione produttiva è stata modificata.

Aggiornamento successivo della stessa giornata: CodeIgniter 4.7.4 provato in copia
privata, con confronto delle personalizzazioni e una correzione locale compatibile
della configurazione JSON. Il framework attivo rimane 4.6.0.
Vedere il [collaudo del framework](fse2-framework-collaudo.md) per risultati e limiti.

## Esito dell'inventario e dell'audit

Il raccoglitore `ops/fse-validation/audit_dependencies.py` legge soltanto manifest,
versioni e metadati delle distribuzioni. Con `--online` invia a OSV esclusivamente
ecosistema, nome e versione pubblici; non invia codice, documenti, configurazioni,
credenziali o dati dei pazienti. Controlla paginazione, risposte incomplete,
dimensioni e immutabilità dei manifest. L'indisponibilità del servizio non è un esito pulito.

Rapporto: `rest/writable/fse-dependency-audits/9f73675f0f2f4f4a8f516e959574e002/audit.json`.
228 voci nei diversi ambiti, 147 combinazioni distinte interrogate; manifest
immutati durante la raccolta. Le copie installate e quelle bloccate sono contate
separatamente, non sono 228 prodotti differenti.

| Ambito | Voci | Esito del confronto con gli avvisi disponibili |
| --- | ---: | --- |
| Lock principale del prodotto | 49 | Avvisi per cinque componenti |
| Dipendenze principali installate localmente | 49 | Stessi cinque componenti |
| Dipendenze `rest/vendor` locali | 70 | Nessun avviso trovato nel perimetro interrogato |
| Lock Composer del laboratorio Linux | 29 | Nessun avviso trovato; Linux non eseguito |
| Lock Python del validatore | 30 | Nessun avviso trovato; tutte le versioni coincidono con il runtime Windows |
| Framework incluso nel sorgente | 1 | Versione dichiarata CodeIgniter 4.6.0 associata a sei avvisi |

Conferma indipendente tramite `composer audit`, senza plugin/script/installazioni:
`rest/writable/fse-dependency-audits/7f0cbd2ddee347429487d1199dba5e95/summary.json`.
Il controllo Composer di `rest/vendor` **non comprende** il framework copiato
in `rest/system`: il suo esito pulito non annulla le segnalazioni su CodeIgniter.
Il rapporto intermedio `080024...` aveva un elemento null nell'elenco vuoto e
quello `748667...` riportava erroneamente SyncRoot; entrambi sono sostituiti dal
rapporto definitivo indicato sopra, senza alterare i risultati grezzi conservati.

### Aggiornamenti candidati, non applicati

`ops/fse-validation/prepare-dependency-candidate.ps1` risolve gli aggiornamenti
in una cartella nuova con una copia dei soli `composer.json`/`composer.lock`.
Non installa pacchetti, non sostituisce il lock del progetto, non attiva codice.

Proposta prodotta: `rest/writable/fse-dependency-candidates/5e30180817d64424b148fc9bdc195693/`.
Risoluzione riuscita e audit Composer del candidato con exit 0, senza modifiche
ai manifest originali. Stato iniziale del rapporto: **NON INSTALLATO / NON COLLAUDATO**.
Il successivo controllo in copia privata è descritto di seguito; le dipendenze attive
del gestionale non sono state aggiornate.

| Componente segnalato | Attuale | Candidato risolto |
| --- | --- | --- |
| dompdf/dompdf | 3.1.4 | 3.1.6 |
| guzzlehttp/guzzle | 7.9.3 | 7.15.5 |
| guzzlehttp/psr7 | 2.7.1 | 2.13.1 |
| web-auth/webauthn-lib | 5.2.2 | 5.3.9 |
| web-token/jwt-library | 4.0.5 | 4.2.2 |

Il resolver propone anche promises 2.5.3, cbor-php 3.4.0, cose-lib 4.8.0 e
polyfill-php80 1.37.0. Il dettaglio è in `summary.json` del candidato.
Questa proposta non risolve il framework vendorizzato: serve un aggiornamento
separato di CodeIgniter, previa verifica delle personalizzazioni in `rest/system`.

Gli [avvisi ufficiali CodeIgniter](https://github.com/codeigniter4/CodeIgniter4/security/advisories)
riguardano, secondo la versione e l'uso effettivo, upload, nomi file, ImageMagick,
header HTTPS inoltrati e `deleteBatch`. Non sono prove che l'app sia stata attaccata
o che ciascun percorso sia sfruttabile. La configurazione immagini locale usa GD;
la ricerca nelle classi FSE non ha trovato i percorsi `deleteBatch`, `imagick`,
`getClientName` o `move`. Questo non sostituisce la revisione di tutti gli upload,
delle personalizzazioni, del proxy o dell'installazione produttiva.

Prima di adottare gli aggiornamenti: installazione in un ambiente separato,
test di login/password/passkey, JWT e notifiche push, PDF di agenda/fatturazione,
allegati e isolamento degli studi. Dompdf è usato anche da agenda, posta e
fatturazione: la sola suite FSE non basta a collaudarne l'aggiornamento.
Nessun aggiornamento massivo del framework o rilascio incluso in questa verifica.

### Collaudo successivo in copia privata: 11 settembre

Laboratorio `rest/writable/fse-dependency-compat/e90fe89c1b0f4b2ba1d127e9c53a127f`.
Le dipendenze candidate sono state installate **solo** sotto `candidate/vendor`,
con Composer senza plugin o script. Il percorso vendor è fissato esplicitamente;
una prima copia con disposizione inattesa è stata rifiutata dal runner e non usata.
Nessuna modifica a `composer.lock`, ai vendor attivi, al framework o ai database reali.

Due processi PHP separati hanno confrontato baseline e candidato, senza caricare
`.env` e con area runtime privata. Le versioni installate sono state confrontate
con il lock scelto; la provenienza delle sei classi principali è stata verificata
tramite Reflection e hash, evitando un falso test del candidato con librerie vecchie.

Rapporti definitivi nel laboratorio:

- `candidate-852a9d1d7512ea49/report.json`: **16 controlli superati**.
- `baseline-9de6edb7ff7df499/report.json`: **15 controlli di compatibilità superati**;
  la sonda aggiuntiva segnala il comportamento insicuro descritto sotto.
- Entrambi riportano fingerprint dei sorgenti applicativi esercitati, del runner
  e delle classi dipendenza; manifest attivi immutati.

Controlli eseguiti:

- Client HTTP con handler simulato: JSON, cookie e rifiuto di newline negli header.
- Firma/verifica ES256 con chiavi sintetiche; rifiuto di payload JWT alterato.
- Web Push: cifratura del contenuto, header VAPID e gestione dell'esito 410 tramite
  risposte simulate. Non è stato contattato il servizio push remoto del gestionale.
- API delle opzioni WebAuthn e rifiuto di una policy non ammessa: non è una prova
  di registrazione/login passkey con browser, authenticator o controllo dell'origine.
- Servizio applicativo `PlatformAuthService` con modelli finti: password corretta,
  password errata, utente assente e filtro degli studi sospesi; nessun DB coinvolto.
- Template applicativo `admin/billing/document_pdf` con 2 e 90 righe sintetiche:
  escaping HTML, generazione PDF in memoria e paginazione, con rete/PHP/JavaScript
  disabilitati e cache privata. Nessuna fattura emessa e nessun confronto visuale.

Sonda di sicurezza circoscritta: con chiavi autogenerate la libreria attuale
accetta un JWS con algoritmo indicato soltanto nell'header non protetto; quella
candidata lo rifiuta. Questo riproduce un comportamento locale da correggere,
non dimostra accesso abusivo o sfruttabilità nel flusso di login/FSE dell'app.
La sonda sulla baseline è registrata separatamente: i suoi controlli di
compatibilità superati **non** significano che la baseline sia sicura.

Stato aggiornato: **INSTALLATO E PROVATO SOLO IN COPIA PRIVATA, NON ATTIVATO
NEL PRODOTTO**. In seguito al primo collaudo di componenti, superati 33 test / 138
asserzioni applicative per variante anche con CodeIgniter 4.7.4 e dipendenze
candidate insieme. Quattro casi PDF fatturazione/agenda confrontati: tutte le
14 pagine rasterizzate e sette pagine candidate esaminate visivamente, contenuti
e pixel identici. Dettagli e limiti nel [collaudo congiunto](fse2-framework-collaudo.md).
Successivamente superate 15 verifiche HTTP FSE per variante con entrambe le
sostituzioni: sessioni, download, rifiuti upload, isolamento studi e provenienza
delle dipendenze in ciascuna richiesta. Restano acquisizione positiva completa
di nuovi referti via HTTP, gli altri moduli HTTP, browser/passkey, notifiche reali
di test, agenda/posta/allegati, altri layout PDF e proxy Linux.
Non è un'autorizzazione al deploy o una dichiarazione di sicurezza complessiva.

Runner ripetibile:

```powershell
& ops/fse-validation/prepare-dependency-compat.ps1 -Candidate rest/writable/fse-dependency-candidates/5e30180817d64424b148fc9bdc195693
# Usare il percorso Lab restituito dal comando precedente:
php ops/fse-validation/dependency-compat.php <Lab> candidate
php ops/fse-validation/dependency-compat.php <Lab> baseline
```

L'installazione richiede rete per i pacchetti pubblici; i due runner non inviano
email, notifiche, richieste FSE o altre chiamate HTTP reali. Ogni prova crea un
rapporto nuovo senza sovrascrivere quelli precedenti.

### Licenze: cosa è stato verificato

Il rapporto conserva licenze dichiarate dai pacchetti Composer, metadati Python
e hash dei file di licenza rintracciati nel virtualenv. È un inventario tecnico,
non un'autorizzazione alla redistribuzione né un fascicolo completo di avvisi.

La maggior parte dei metadati Python indica MIT, BSD, Apache o PSF; certifi
dichiara MPL-2.0. I metadati della wheel `saxonche` non riportano una licenza:
rimane una verifica da chiudere sul pacchetto effettivamente distribuito.
Nel lock PHP figurano inoltre licenze LGPL per Dompdf, php-font-lib, php-svg-lib
e PHPMailer: questi componenti restano inclusi nella revisione delle condizioni
di distribuzione; non sono stati classificati genericamente come MIT.
Saxonica distingue [SaxonC-HE open source da PE/EE commerciali](https://www.saxonica.com/html/products/latest.html).
Non è stata acquistata o attivata una licenza commerciale. Prima della consegna
del runtime occorre completare gli avvisi di terze parti, inclusi componenti
nativi, Java/veraPDF, immagini base e materiali dei cataloghi.

Non coperti: vulnerabilità di OS/PHP/Java/container, JavaScript/CDN, librerie
native incorporate nelle wheel, inventario produttivo, completezza delle fonti
di advisory. Nessun avviso trovato non significa assenza di vulnerabilità.

## Collaudo del servizio/dispositivo di firma: scheda pronta

Il percorso esistente consente il caricamento di un PDF firmato all'esterno,
con verifica documentale e crittografica conservativa. Nessun fornitore remoto è
stato scelto o integrato arbitrariamente. I certificati Sogei per mTLS/JWT non
sono la firma clinica del medico.

Quando è identificato il dispositivo/provider, raccogliere:

1. Nome, versione, ambiente di prova e documentazione tecnica del servizio;
   modalità locale/token oppure API remota. Non richiedere password o chiavi private in chat.
2. Profilo PAdES emesso, algoritmi, certificati pubblici e catena fiduciaria;
   identità/CF del firmatario e modalità di verifica della qualifica richiesta.
3. Un documento **sintetico** generato dal nostro laboratorio e firmato con quel
   prodotto: il provider deve conservare il CDA allegato, il PDF/A e l'originale,
   senza ricreare il PDF da zero. L'attuale policy ammette una firma incrementale;
   marche temporali o firme multiple richiedono prove dedicate, non un bypass.
4. Evidenze di revoca, disponibilità OCSP/CRL, rinnovo/scadenza dei certificati
   e policy di fiducia produttiva; eventuale LTV e marca temporale da verificare.
5. Per API remote: autorizzazione esplicita del firmatario, annullamento/scadenza
   della richiesta, identificativo univoco, autenticazione dei callback,
   idempotenza e recupero dell'esito prima di ripetere una firma incerta.
6. Ruoli, custodia dei segreti, separazione degli studi, log privi di contenuti
   clinici e disponibilità del servizio. Nessuna firma automatica a nome del medico.
7. Prove positive e negative: file corretto, altro autore, certificato scaduto/
   revocato/non attendibile, allegato CDA sostituito, PDF riscritto, bytes aggiunti,
   timeout, callback duplicato/tardivo e annullamento (ove applicabili).
8. Evidenza con hash di originale e firmato, versione del provider/validatore,
   ora/esito tecnico e responsabile dell'approvazione. Conservazione e accordi
   operativi sono da definire con struttura e prestatore, non dedotti dal test.

Questa scheda permette di avviare il collaudo appena è noto il prodotto di firma.
I test con certificati sintetici restano distinti da firma qualificata reale,
policy fiduciaria produttiva e accreditamento FSE.

## Ripetere le verifiche

Da root del repository, usando il runtime isolato già predisposto:

```powershell
& rest/writable/fse-validation-venv/Scripts/python.exe ops/fse-validation/audit_dependencies.py --online
& ops/fse-validation/audit-composer.ps1
& ops/fse-validation/prepare-dependency-candidate.ps1
```

Ogni esecuzione scrive un nuovo rapporto privato. Lo script Python restituisce 1
se trova avvisi, 2 se la verifica è incompleta: non interpretarli come un esito pulito.
I test delle protezioni del raccoglitore sono offline e inclusi nella suite locale.

Fonti del metodo: [API OSV](https://google.github.io/osv.dev/post-v1-querybatch/),
[Composer audit](https://getcomposer.org/doc/03-cli.md#audit).
