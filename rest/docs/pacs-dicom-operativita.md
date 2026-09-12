# PACS/DICOM: modulo cloud riutilizzabile

## Ambito realizzato

Il modulo integra archivi PACS attraverso DICOMweb, con più collegamenti per tenant.
Lo stesso codice gestisce ricerca studi (QIDO-RS), serie, istanze, metadati e recupero
di un singolo oggetto (WADO-RS), e apertura del visualizzatore esterno configurato.
Comprende inoltre richieste diagnostiche locali, conferma del medico ed esportazione
di una Modality Worklist in DICOM JSON o file `.wl`.

Il prodotto conserva abbinamenti e metadati cifrati; le immagini rimangono nel PACS.
Non sostituisce l'archivio, il suo backup, la conservazione o il visualizzatore diagnostico.
L'immagine Orthanc presente nel laboratorio non viene distribuita al cliente.

## Abilitazione

- Voce super amministratore: **PACS / DICOM**, chiave `pacs_dicom`.
- Disattiva per default, `is_tenant_managed=0`: lo spazio non può concedersela.
- Richiede anche `clinical_records`, disattivato o revocato il quale si interrompe l'accesso.
- Collegamento **Esami e immagini** nella cartella, solo per personale clinico.
- Controllo dei diritti su ogni operazione, prima del database tenant nel controller,
  prima delle richieste remote e nuovamente dopo le risposte.
- Nessuna scorciatoia per il super amministratore verso i dati clinici.
- La segreteria non accede alle immagini. Il medico conferma identità e abbinamenti;
  il personale clinico autorizzato consulta secondo relazione di cura e consenso dossier.
- Gli abbinamenti di un altro operatore richiedono il consenso dossier attuale.
  Un collegamento a un documento eredita anche le restrizioni del documento.

## Installazione per uno spazio

1. Dalla piattaforma abilitare Cartella clinica e consensi e PACS / DICOM.
2. Verificare lo schema clinico con `php rest/spark clinical:install <tenant-id>`.
3. Verificare `php rest/spark pacs:install <tenant-id>`; usare `--apply` per creare
   esclusivamente le tabelle PACS del tenant scelto.
4. Predisporre un JSON privato fuori dalla directory pubblicata dell'applicazione
   (esempio: `/run/secrets/pacs-profiles.json`). Impostare `PACS_CONFIG_FILE`.
   Il file di esempio è in `ops/pacs-validation/profiles.example.json`.
5. Configurare i riferimenti alle credenziali e verificare
   `php rest/spark pacs:check <tenant-id>`. Il comando non contatta il PACS.
6. Eseguire il collaudo concordato con il fornitore usando identità di prova.
   Impostare `enabled: true` sul collegamento solo quando pronto.

Il catalogo dei moduli è auto-registrato dal meccanismo esistente. Nessuna migrazione
assegna abilitazioni. Le tabelle includono tenant_id anche per database condivisi.

## Profili e credenziali

Ogni tenant ha una lista di profili con identificativo stabile. Non riciclare un
identificativo per un archivio o per un dominio di pazienti diverso: creare un nuovo
profilo, disattivando quello precedente. Un cambio degli endpoint o del campo
`identity_namespace` blocca automaticamente i vecchi abbinamenti fino a riconferma.
Dopo sostituzione credenziali, archivi o
autorità verificare nuovamente gli abbinamenti prima della riattivazione.

Campi:
- `id`, `label`, `enabled`: identificazione e stato del collegamento.
- `qido_url` e `wado_url`: basi HTTPS, anche distinte.
- `auth`: `none`, `basic` oppure `bearer`.
- Basic: `username_env` e `password_env`; bearer: `token_env`.
  I valori sono nomi di variabili `PACS_...`, non password/token in JSON.
- `ca_file`, facoltativo: bundle PEM fiduciario gestito sul server per PKI private.
  Non disabilita la verifica del nome host né quella del certificato.
- `viewer_url`: URL del visualizzatore con un solo `{study}` nel percorso o nella
  query. Il fornitore deve autorizzare autonomamente l'accesso: non è un accesso anonimo
  né una procedura SSO universale. Non inserire credenziali o token nell'URL.
- `download_enabled`: consente lo scaricamento dei singoli oggetti, massimo 32 MB
  compreso il contenitore multipart. Il download è disattivato per default.

Il trasporto operativo consente solo HTTPS verso IPv4 pubblici, con indirizzo DNS
verificato e bloccato per ciascuna richiesta. Non segue redirect, proxy di ambiente,
URL restituiti nei metadati né BulkDataURI. Timeout connessione 5 s, totale 30 s;
JSON massimo 2 MB. CA e credenziali rimangono sul server.
OAuth con rinnovo token, mTLS, reti private/VPN, IPv6 e protocolli DIMSE richiedono
connettori ulteriori; non esistono deroghe di rete attivabili dall'interfaccia tenant.

## Percorso dell'operatore

1. Aprire la cartella e **Esami e immagini**.
2. Registrare l'identificativo paziente del PACS e la relativa autorità, verificandoli
   sul PACS. Il nome o il codice fiscale non sono assunti automaticamente come PatientID.
3. Cercare gli studi; controllare nome, nascita, data, descrizione e numero di accesso.
4. Confermare il collegamento; facoltativamente indicare il numero del documento
   clinico, che deve essere già accessibile per quel paziente. Il documento collega
   a sua volta la prestazione tramite la cartella esistente.
5. Consultare serie/istanze, aprire il visualizzatore o scaricare l'oggetto autorizzato.
6. Correggere o disabilitare un'identità se necessario. Ogni modifica incrementa la
   revisione e rende inutilizzabili i vecchi abbinamenti fino a nuova verifica.

PatientID e IssuerOfPatientID devono essere restituiti e corrispondere esattamente.
Un PACS che omette l'autorità richiede una valutazione del relativo dominio di
identificativi e un adattatore esplicito. Nessun abbinamento per omonimia.
Ricerca con pagine da 25; serie fino a 100, con avviso di elenco parziale.
Lo storico della pagina mostra fino a 200 collegamenti recenti.
Gli oggetti scaricati sono file DICOM; il prodotto non interpreta immagini per diagnosi.

## Integrità, revoca e audit

Identità e metadati sono cifrati attraverso la chiave clinica esistente, con contesto
crittografico di tenant ed entità. La deduplicazione usa HMAC, non identificativi
paziente in chiaro. La rotazione della chiave richiede anche il riallineamento degli
HMAC delle identità secondo il piano di migrazione delle chiavi.

Il registro conserva operatore, spazio, paziente locale, azione, entità locale e data UTC.
Non contiene token, corpi DICOM, nomi remoti o URL completi. Un errore di audit impedisce
l'accesso remoto. Le rimozioni disattivano i collegamenti, non cancellano file sul PACS.
Le operazioni di modifica sono transazionali e le revisioni bloccano richieste obsolete.
I metadati vengono ricontrollati sul PACS prima di apertura e download. Nel download
sono controllati studio/serie/istanza e il contenitore MIME, e restituito un solo file.

## Attività che richiedono il cliente o un ambito aggiuntivo

- Compatibilità effettiva: DICOM Conformance Statement, autenticazione e licenze API.
- Autorizzazione di rete e collaudo con il PACS reale.
- SSO o URL firmati specifici del visualizzatore del fornitore.
- Consegna automatica degli ordini e degli annullamenti al RIS/PACS, ricezione degli
  esiti e notifiche di esecuzione: richiedono un connettore verso l'interfaccia reale.
  Creazione, conferma, annullamento locale ed esportazione MWL sono già implementati.
- Un archivio PACS gestito da noi, migrazione dello storico, accesso pazienti o un
  visualizzatore diagnostico integrato richiedono ambito e requisiti dedicati.
- Nessun invio clinico STOW né modifica/cancellazione remota è esposto dal prodotto.
  Lo STOW nel laboratorio serve esclusivamente a caricare i file sintetici di prova.

Non è dichiarata compatibilità universale con tutti i PACS. La base è riutilizzabile,
mentre servizi attivi, formati e limiti sono verificati per ciascun fornitore.

## Riferimenti utilizzati

- [DICOM PS3.18 Search](https://dicom.nema.org/medical/dicom/current/output/chtml/part18/sect_10.6.html)
- [DICOM PS3.18 Retrieve](https://dicom.nema.org/medical/dicom/current/output/chtml/part18/sect_10.4.html)
- [DICOM JSON](https://dicom.nema.org/medical/dicom/current/output/chtml/part18/chapter_F.html)
- [Orthanc DICOMweb](https://orthanc.uclouvain.be/book/plugins/dicomweb.html)
- [IHE Scheduled Workflow](https://wiki.ihe.net/index.php/Scheduled_Workflow)
- [DICOM PS3.4 Modality Worklist](https://dicom.nema.org/medical/dicom/current/output/chtml/part04/sect_K.6.html)
- [Orthanc Worklists](https://orthanc.uclouvain.be/book/plugins/worklists-plugin-new.html)


## Esito del collaudo del 12 settembre 2026

- PHP Windows 8.3.6: 27 test PACS, 154 asserzioni superate.
- PHP Linux 8.2.33: suite completa con Orthanc reale, 30 test e 170 asserzioni,
  nessun test saltato, nessun errore.
- Regressione cartella clinica e agenda: 49 test, 222 asserzioni superate.
- Orthanc 1.12.11 / DICOMweb 1.23: caricamento di due studi sintetici tramite STOW;
  QIDO, serie, istanze, metadati WADO e download DICOM con confronto SHA-256 esatto.
- Rifiuti verificati: identità diversa, autorità mancante o errata, accessi tra tenant,
  segreteria, relazione di cura assente, modulo o consenso revocato, identità cambiata
  durante la richiesta, archivio cambiato, credenziali errate e CA non attendibile.
- Controller, filtri CSRF, rotazione token, mancata autenticazione e risposte senza
  credenziali verificati mediante il test harness CodeIgniter. Questi test non
  sostituiscono una sessione E2E con login e PACS di un cliente.
- Controllo visivo delle viste sintetiche in browser, desktop e larghezza 390 px.
- Laboratorio `e4759103d176458293d8562a4aaf2581`, progetto Docker
  `af-pacs-e4759103d176`. Rapporto JUnit in
  `rest/writable/pacs-labs/e4759103d176458293d8562a4aaf2581/phpunit-linux.xml`.

Sviluppo su `codex/pacs-dicom`, basato sul rilascio `06025229`, in copia isolata.
Nessun collegamento cliente configurato e nessun rilascio in produzione in questa attività.
