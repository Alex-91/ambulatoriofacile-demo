# Preparazione FSE — collaudo HTTP della fatturazione

12 settembre 2026. Lavoro locale su `codex/fse-certificati-test`, senza commit,
push o deploy. È una verifica di compatibilità del gestionale prima degli
aggiornamenti proposti per FSE, non una prova di accreditamento né una verifica
fiscale. Nessuna fattura, email o comunicazione TS reale.

## Correzioni applicative

- **CSRF sui documenti di fatturazione.** Nel laboratorio un POST autenticato
  senza token ha inserito una bozza: HTTP 303, documenti dello studio 42 da uno
  a due. Il problema è preesistente: il filtro CSRF non era associato a questi
  percorsi. Le form contenevano già `csrf_field()`.
- Nuovo `BillingCsrfFilter`, registrato prima e dopo le richieste a
  `admin/fatturazione-documenti`, relativi sottopercorsi e pagina scadenzario.
  Conserva il cookie rinnovato anche sui redirect. Non abilita indiscriminatamente
  CSRF sugli altri moduli legacy. Impostazioni fatturazione e percorsi TS restano
  fuori da questa correzione e richiedono una verifica separata.
- **Cache PDF fuori dal codice.** L'endpoint di download utilizza
  `BillingPdfOptionsFactory`: cache font e temporanei sotto
  `WRITEPATH/billing-pdf-runtime/<tenant>/`, separati per studio. I font incorporati
  vengono ancora letti dalla libreria. PHP/JavaScript nel PDF disabilitati.
  Il comportamento dei loghi remoti è conservato: questa modifica non costituisce
  una barriera generale verso la rete. I documenti di prova non hanno loghi o URL.

## Ambiente e perimetro

Nuovo laboratorio `rest/writable/fse-app-labs/885e7906f2ee4cb79743fcfb73a85de5`:
MySQL 8.3 sulla sola interfaccia loopback, porta 33079; PHP 8.3.6 sulla 8088.
Database piattaforma sintetico e due database separati per gli studi 42 e 43.
Datadir e marcatori verificati prima di usare qualsiasi database. Nessuna lettura
di `.env`, credenziali reali, upload o database del gestionale ordinario.

Il seed estende il laboratorio con le migration applicative esistenti, una
configurazione di fatturazione fittizia e una tabella profili TS **vuota**. Non
crea nuove migration del prodotto. I ripristini dello schema dei due studi sono
eseguiti in processi distinti: il runner esistente copia le classi migration in
directory diverse e, se usato due volte nello stesso processo PHP, provoca una
ridichiarazione di classe. Il primo tentativo lo ha evidenziato; non è un collaudo
positivo di migration multi-tenant nello stesso worker. Il seed può completare
una inizializzazione interrotta solo senza documenti; rifiuta un laboratorio già
marcato come pronto e non cancella righe.

Il router privato ammette solo lista, nuovo, modifica, salvataggio, preview, PDF e
pagamento. Invio email, invii TS e percorsi arbitrari sono esclusi. Nei salvataggi
di laboratorio sono vietati `final_send_ts` e `ts_sync_enabled` diverso da zero.
Non è un sandbox di rete del sistema operativo. L'ambiente di sviluppo ha alcuni
bypass locali delle feature: le prove non attestano l'entitlement produttivo.

## Esiti HTTP reali

Stesso codice applicativo corretto, con due selezioni private delle dipendenze:

| Variante | Runtime | Verifiche | Richieste con provenienza verificata |
| --- | --- | --- | --- |
| Candidato | CodeIgniter 4.7.4, vendor candidato, Dompdf 3.1.6 | 26 superate | 38 |
| Baseline | CodeIgniter 4.6.0, vendor attivo, Dompdf 3.1.4 | 27 superate | 38 |

Il controllo aggiuntivo della baseline confronta anche l'inventario/hash dei font
nel vendor attivo prima e dopo la generazione: nessuna aggiunta o modifica.

Copertura: accesso anonimo/logout; login reale; bozza e modifica; ricalcolo totale
da 102 a 142 euro; duplicato respinto senza cambiare righe/configurazione;
passaggio a definitiva senza TS; pagata/non pagata; lista; preview con testo HTML
escapato; PDF reale e data di generazione. Richieste prive di token, con token
riutilizzato o cookie/token di un'altra sessione respinte con HTTP 403 e database
immutato. Il token valido ruota e il salvataggio successivo funziona.

Lo studio B non può aprire form, preview o PDF dello studio A, né modificarne
fattura/pagamento **anche con il proprio token valido**. Snapshot di righe e
preferenze dello studio B identico a inizio/fine. Nessun documento TS o log email
generato; i profili TS restano assenti.

Rapporti privati nel laboratorio:

- Prova del difetto: `billing-http-4cf2e63e4b3d4dd79dc50582dc20884d.json`.
- Candidato corretto: `billing-http-8f789745561c44e5b12aaa7bde5de2b3.json`.
- Baseline corretta: `billing-http-3fd28b3cb0c2404592f234d54360b170.json`.
- Provenienza delle singole richieste: `writable/dependency-provenance/`.

Conservati anche i primi tentativi incompleti: timeout nel bootstrap dello
snapshot SQL e prima rilevazione CSRF. Gli snapshot ora usano il bootstrap CLI
attivo isolato; la compatibilità candidata è attestata dalle richieste HTTP,
non dalle letture SQL di confronto.

## Test applicativi e PDF

Suite `application-compat.xml`: **39 test, 182 asserzioni per variante**, nessun
errore, fallimento o skip. Comprende sei nuovi test di filtro/configurazione PDF.
Provenienza e hash dei sorgenti verificati; non si tratta di test di notifiche
o servizi esterni. JUnit `billing-application-candidate-passed.xml` e
`billing-application-baseline-passed.xml` nel laboratorio. I rapporti precedenti
con sei skip o quattro errori sono conservati: erano errori nel nuovo test
(guardia sulla directory runtime e trait HTTP mancante), corretti prima del run
finale eseguito anche con `--fail-on-skipped`.

Provenienza CLI nel laboratorio dipendenze:
`application-candidate-f9dc35f2fbe03398/provenance.json` e
`application-baseline-4592e103bd8beb47/provenance.json`, entrambi positivi.

I due PDF effettivamente scaricati sono ciascuno di una pagina A4. Renderizzati
con Poppler e ispezionati visivamente seguendo la skill PDF: nessun taglio o
sovrapposizione, importi e testi leggibili. Estrazione del testo verificata anche
con pypdf. Numerazione e timestamp differiscono intenzionalmente tra i due run:
non si dichiara uguaglianza byte-per-byte o pixel-per-pixel di questi documenti.
Sono esclusivamente fixture tecniche senza valore fiscale.

Regressione FSE separata: **309 test superati** (261 PHP / 1271 asserzioni,
24 documentali Python, 24 packaging/harness/audit), nessun errore o skip.
`rest/writable/fse-validation-reports/20260912-100231-0f39b500/summary.json`:
`passed_offline`, sorgenti censiti immutati. Framework candidato e vendor root
attivo; la suite non estende la copertura HTTP sopra descritta.

## Ripetizione e limiti

Su un nuovo laboratorio preparato e avviato con il flusso `app-lab-*`, eseguire:

```powershell
$env:FSE_LAB_ROOT = '<laboratorio applicativo sintetico marcato>'
php ops/fse-validation/app-lab-seed.php
php ops/fse-validation/app-lab-billing.php seed
# Nei processi server e client: stesse variabili FSE_FRAMEWORK_LAB,
# FSE_DEPENDENCY_LAB e varianti baseline/candidate documentate nella guida framework.
./ops/fse-validation/app-lab-serve.ps1 -LabRoot $env:FSE_LAB_ROOT
# In un secondo processo:
& rest/writable/fse-validation-venv/Scripts/python.exe ops/fse-validation/app-lab-billing-http.py
```

I comandi seed non vanno eseguiti nuovamente su un laboratorio già pronto.
Non serve riutilizzare firme/CRL FSE per questi test di fatturazione. L'estensione
non è stata eseguita su Linux né validata come parte del pacchetto container.

Esclusi: browser/JavaScript e autocomplete pazienti, dati clinici reali, firma
qualificata, email/TS effettivi, eliminazione e impostazioni via HTTP, scadenzario
completo, report statistici, concorrenza, proxy/TLS Coolify e procedure fiscali.
I test dei percorsi email/eliminazione provano l'associazione del filtro, non
l'esecuzione di questi flussi. L'aggiornamento complessivo non è pronto per un
rilascio automatico: restano i vincoli descritti nella guida framework.

Al termine PHP e MySQL del laboratorio sono arrestati, porte 8088/33079 libere,
temporanei upload vuoti. Materiali sintetici ed evidenze conservati. Demo e login
verificati via API Coolify in sola lettura: `running:healthy`, branch `main`.
Nessun nuovo accesso a Slack, invio a enti, accreditamento o abilitazione regionale
è avvenuto in questa estensione software.
