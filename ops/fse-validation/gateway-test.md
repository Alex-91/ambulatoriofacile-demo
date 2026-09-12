# Certificati Sogei e verifica nazionale da Windows

Preparazione tecnica del 9 settembre 2026. Non è una sessione ufficiale di
accreditamento. Nessuna configurazione `.env`, database clinico, istanza Coolify
o credenziale di produzione viene utilizzata dai comandi di questa guida.

## Custodia e importazione

I file sono in `ops/.local/fse-accreditamento/`, esclusa da Git e dal contesto
Docker. Non servire questa cartella via HTTP e non copiarla nel server web.
Le chiavi originali restano in `x509/`, con ACL NTFS private già presenti.
Il comando seguente legge solo lo ZIP esplicitamente indicato e le CSR/chiavi
originali del task; non rigenera chiavi, non modifica il trust store e non fa rete.

```powershell
php ops/fse-validation/import-test-certificates.php 'C:\Users\bassi\Downloads\AMBULATORIOFACILEXX_CERT.zip'
```

Il pacchetto corrente è già importato in `x509/received-6cf171446ae1b1d9/`.
Ripetere il comando non sovrascrive l'importazione. `receipt.json` conserva
metadati pubblici, SHA-256 e verifica di corrispondenza certificato/chiave/CSR.
L'ispezione controlla anche CN, RSA >=2048 bit, validità temporale e ruoli attesi;
non certifica catena/revoca, qualifica della firma o accreditamento.

- `auth.pem`: certificato pubblico per autenticazione mTLS.
- `sign.pem`: certificato pubblico per firma dei JWT, non del referto clinico.
- Le due chiavi `*-private.key` non vanno allegate a email o moduli e non vanno
  copiate nei pacchetti di accreditamento.

## Dati e fiducia TLS

`fetch-gateway-fixtures.ps1` scarica una sola copia dei documenti ufficiali alla
revisione `d937255fd7e9c079c5641c537da17fe98a2f2259` e salva URL/hash nel manifest.
Il download è già stato eseguito; lo script rifiuta di sovrascrivere lo snapshot.
Le checklist XLSX e la scheda CART ODS restano originali, non compilate.
`inspect-official-forms.py`, eseguito con un Python con openpyxl, le legge soltanto.

Per PHP/cURL Windows, privo di CA server configurata, il test usa esclusivamente
`tls/cacert-20260909.pem`: bundle Mozilla scaricato via HTTPS da
[curl.se](https://curl.se/ca/cacert.pem), estrazione 13 agosto 2026, 121 certificati.
SHA-256 atteso: `f66dff1bdf8f96060b8177976f8b7d9254bc89bc4db933d769f7384d28480bc9`.
Il runner fallisce se il file manca o cambia: un futuro aggiornamento richiede
verifica della fonte e nuovo hash, non disabilitazione di TLS.
L'app dispone dell'opzione `FSE2_GATEWAY_CA_BUNDLE` per un bundle server gestito
dall'operatore; se vuota usa il trust store cURL predefinito. TLS peer/hostname
restano obbligatori. Le CA della firma clinica restano separate.

## Generazione e preflight senza rete

```powershell
$env:XDEBUG_MODE = 'off'
& rest/writable/fse-validation-venv/Scripts/python.exe ops/fse-validation/prepare-gateway-fixtures.py --cases 24 25 app
php ops/fse-validation/gateway-test.php --credentials=received-6cf171446ae1b1d9 --case=app
```

I casi 24/25 incapsulano XML ufficiali immutati in PDF/A-3b verificati con
veraPDF. `app` usa il vero builder Ambulatorio Facile, contenuti clinici fittizi
e identità di prova dell'esempio ufficiale: non è l'intero caso ufficiale 24.
Le versioni del PDF applicativo hanno il hash CDA nel nome per conservare i
tentativi precedenti. Le fixture 1–4 sono esempi storici selezionabili, non il
piano corrente completo; il tentativo iniziale sul caso 4 è scaduto durante
veraPDF e non costituisce un caso superato.

Il preflight controlla ricevuta, coppie certificate/chiavi/CSR, firme JWT locali,
manifest, hash del PDF e precedenti verifiche documentali. Non esegue chiamate.
I valori di Regione Lazio/organizzazione/medico sono segnaposto ufficiali di
test: non copiarli nella configurazione di uno studio reale.

## Chiamata esplicita e limitata al test nazionale

Solo aggiungendo `--send` viene eseguita una chiamata autenticata al Gateway
nazionale di test. Non usare questo runner con documenti reali.

```powershell
php ops/fse-validation/gateway-test.php --credentials=received-6cf171446ae1b1d9 --case=app --send
```

Host fisso: `modipa-val.fse.salute.gov.it`; unica operazione consentita:
`POST /govway/rest/in/FSE/gateway/v1/documents/validation`, attività `VERIFICA`.
Nessuna pubblicazione, sostituzione, cancellazione, chiamata regionale o firma
clinica; nessun retry automatico. TLS rimane verificato e i redirect vietati.

Ogni tentativo crea `gateway-runs/<UTC-id>/result.json`, anche in caso di errore.
Le nuove esecuzioni conservano inoltre `request.pdf` (solo documento di prova),
hash dei sorgenti pertinenti, timestamp, traceID, workflowInstanceId ed esito TLS.
I primi tentativi del 9 settembre precedono l'aggiunta della copia PDF per-run:
i relativi PDF sono conservati nelle fixture e identificati tramite hash.
Il trasporto diagnostico è CLI-only e limitato a questo endpoint/attività;
conserva campi di errore limitati/redatti e non logga JWT o chiavi. Non è il
logger dell'applicazione, che conserva solo correlazioni tecniche.

Successo preparatorio: HTTP 200, nessun errore cURL/TLS, traceID e workflow presenti.
Un timeout resta un tentativo senza esito, non diventa un invio riuscito.
`official_accreditation_evidence=false` è intenzionale in ogni rapporto.

I [casi nazionali](https://github.com/ministero-salute/it-fse-accreditamento/tree/main/Test%20Case)
448/449 richiedono attività `VALIDATION` con HTTP 201: i risultati HTTP 200
di questa guida **non** vanno inseriti come loro esecuzione ufficiale.
Firma PAdES, copertura della checklist e gestione degli errori nel flusso utente
devono essere completate secondo le nuove regole, quando disponibili.

Il [dossier aggiornato](../../rest/docs/fse2-certificati-e-primi-test.md) riporta
esiti reali, modifiche applicative e attività nazionali/regionali ancora aperte.
# Prove negative preparatorie del 9 settembre

Le istruzioni seguenti si aggiungono alle prove positive descritte più sotto.
Non compilano né inviano una checklist ufficiale. Non usare referti reali.

```powershell
& rest/writable/fse-validation-venv/Scripts/python.exe ops/fse-validation/prepare-rsa-negative-fixtures.py
php ops/fse-validation/gateway-test.php --credentials=received-<hash> --case=app --negative=cda-control
```

Senza `--send` viene effettuato solo il preflight locale. Gli scenari ammessi
sono `cda-control`, `jwt-missing-purpose`, `jwt-action-invalid`, `cda-no-given`,
`cda-gender`. Solo dopo verifica del controllo positivo aggiungere `--send`
per una singola chiamata al Gateway nazionale di test, attività VERIFICA.
Nessun endpoint, file arbitrario, accesso regionale o pubblicazione ammessi.
Il difetto JWT è confinato a una classe CLI: il codice applicativo non lo carica.

Il generatore prepara 10 mutazioni CDA e 3 PDF/A-3b (controllo più 2 negativi).
Non indebolisce il validatore applicativo. La prova di terminologia NB passa
XSD/Schematron ma non il Gateway; il builder ordinario impedisce già quel valore.
Un HTTP negativo atteso è un risultato diagnostico, non un test ufficiale PASS.
Hash, sorgenti e risultati originali vengono conservati nell'area privata.

Analisi completa e risultati: [matrice RSA](../../rest/docs/fse2-matrice-rsa-preparatoria.md).
