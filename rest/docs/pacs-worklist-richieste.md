# Richieste diagnostiche e worklist

## Stato del modulo

Il modulo aggiunge alla cartella il percorso **Esami e immagini → Richieste diagnostiche**.
È parte di `pacs_dicom`: resta subordinato all'abilitazione del super amministratore
e alla cartella clinica, senza abilitazioni automatiche o scorciatoie amministrative.

Si tratta della gestione delle richieste e dell'esportazione MWL. Non è ancora un
servizio RIS, un endpoint DICOM esposto dal prodotto o una coda di invio automatico.
Il laboratorio Orthanc dimostra che un server MWL reale legge il file esportato.

## Percorso del medico

1. Verificare l'identità del paziente nel profilo PACS della struttura.
2. Creare una bozza scegliendo prestazione, codice e catalogo, modalità, AE Title
   della stazione destinataria, data e ora. I codici e la destinazione devono essere
   quelli concordati con il servizio diagnostico; non sono dedotti dal nome dell'esame.
3. Rileggere nome, nascita, identità PACS, destinazione e prestazione, quindi confermare.
4. Scaricare la richiesta in formato DICOM JSON oppure worklist DICOM.
5. Aggiornare la pagina dopo il download prima di una nuova operazione.
6. Se necessario annullare. Se esiste già una copia esportata, il servizio diagnostico
   deve ritirarla anche dal sistema destinatario.

Gli stati sono **Bozza**, **Confermata**, **Annullata**. Una richiesta confermata non
è modificabile: va annullata e ricreata. L'esportazione non cambia lo stato in
“inviata”, “ricevuta” o “eseguita”. Il prodotto non dispone di questi riscontri.

Il numero richiesta (Accession Number, 16 caratteri) e lo Study Instance UID
(`2.25.<UUID decimale>`) restano stabili per l'intera richiesta.
La chiave del modulo evita duplicati al doppio invio; lo stesso modulo non può
creare richieste con contenuto differente. Un conflitto simultaneo è respinto
dal vincolo univoco: non genera una seconda richiesta.

## Identità, autorizzazione e integrità

- Nome e nascita vengono letti dall'anagrafica del tenant, mai dal form HTTP.
- La richiesta conserva una fotografia cifrata dell'anagrafica e dell'identità PACS.
  Una modifica anagrafica richiede aggiornamento della bozza oppure, dopo la
  conferma, annullamento e nuova richiesta.
- L'esportazione ricontrolla identità PACS attiva, revisione e impronta del profilo.
  Un archivio o collegamento cambiato blocca le richieste precedenti.
- Il medico autore modifica, conferma, annulla ed esporta. Gli altri clinici leggono
  solo con relazione di cura e consenso dossier corrente. La segreteria è esclusa.
- L'annullamento locale resta possibile all'autore quando il collegamento PACS
  è revocato o cambiato, purché il modulo e i permessi clinici restino attivi.
- Le revisioni sono incrementate anche all'esportazione. Esportazione e annullamento
  da moduli con la stessa revisione non possono entrambi essere confermati.
- Aggiornamento e audit sono nella stessa transazione. Un errore di audit blocca
  l'operazione e annulla l'aggiornamento. Su MySQL viene bloccata anche la riga
  dell'identità durante conferma/esportazione, prima di validarne la revisione.
- Payload cifrato tramite ClinicalVault; deduplicazione mediante HMAC.
  Audit senza nomi, note, password o corpi DICOM. Ultimo export tracciato con UTC
  e SHA-256. Elenco paginato a 25 richieste.
- Risposte private/no-store e modifiche solo POST con filtro CSRF clinico.

## Profilo MWL esportato

Una prestazione richiesta e un solo Scheduled Procedure Step per file/dataset.
Charset UTF-8 (`ISO_IR 192`); PatientID e IssuerOfPatientID dal collegamento verificato;
PatientName e PatientBirthDate dall'anagrafica; StudyInstanceUID e AccessionNumber
stabili. Sono presenti codifica prestazione, modalità, stazione AE, data/ora,
Scheduled Procedure Step ID e Requested Procedure ID.

Le date usano Europe/Rome, con offset UTC esplicito; orari inesistenti o ambigui
nel cambio dell'ora legale sono rifiutati. Le note interne non vengono esportate.
Sesso e campi clinici/anagrafici non disponibili rimangono vuoti, senza inferenze.

Il file `.wl` usa Explicit VR Little Endian, preambolo DICM e sequenze a lunghezza
definita. È un file destinato a un importatore/server worklist: **non è un oggetto
immagine da inviare con C-STORE o STOW-RS**. DICOM JSON descrive il dataset e non
definisce, da solo, un'API universale di creazione ordini.

Compatibilità validata con Orthanc Worklists 0.9.2 e DCMTK 3.6.9. I requisiti
specifici del destinatario (campi obbligatori, cataloghi, AE, caratteri supportati,
autenticazione e modalità di importazione) richiedono collaudo con il fornitore.

## Migrazione e rilascio

La nuova migration è `2026-09-13-110001_CreatePacsOrders.php`.
Crea `pacs_orders`, non modifica né cancella richieste esistenti; ripetibile.
Il rollback distruttivo è deliberatamente assente. Ogni riga include tenant_id.

`php rest/spark pacs:install <tenant-id> --apply` applica ora entrambe le migration
PACS e richieste, esclusivamente sul tenant indicato; senza `--apply` controlla
le quattro tabelle. Il comando non abilita il modulo.

Questo lavoro è nel branch `codex/pacs-dicom`, in copia isolata. Nessun database
live modificato e nessun rilascio effettuato.

## Verifiche del 13 settembre 2026

- Versione finale, PHP Windows 8.3.6 e Linux 8.2.33: **39 test / 488 asserzioni**
  in ciascun ambiente, nessun test saltato (gruppo laboratorio escluso esplicitamente).
- Suite completa con Orthanc durante il collaudo: **42 test / 405 asserzioni**,
  nessun test saltato. L'ultima revisione aggiunge controlli di input, ricontrollo
  dell'anagrafica nella transazione e visualizzazione dell'identità nella richiesta,
  riverificati nella suite finale, compreso un cambio anagrafico durante l'export.
- Regressione clinica/agenda: **49 test / 222 asserzioni**, nessun errore.
- I test applicativi usano SQLite sintetico. Il locking MySQL e il comando di
  migrazione vanno collaudati anche nell'ambiente test dedicato prima del rilascio;
  non è stata eseguita una prova su database live.
- DCMTK indipendente: parsing del file, nome UTF-8, PatientID/Issuer, accession,
  StudyInstanceUID, codifica e orari. C-FIND reale con risultato corretto; filtri
  per modalità, data e stazione; rifiuto di AE sconosciuta e AE destinataria errata.
- Ritiro esplicito della sola worklist sintetica e ripristino: C-FIND passa da
  un risultato a zero e nuovamente a uno. Non è una prova di ritiro automatico
  da parte del prodotto, che non è implementato.
- La mancata autorizzazione del peer è verificata anche sul risultato del
  protocollo: DCMTK può restituire exit code zero dopo un'interruzione del peer.
- Controller: accesso non autenticato, POST obbligatorio, CSRF, escape HTML,
  header privati, download esatto. Non sostituisce E2E con login di un cliente.
- Viste sintetiche controllate in browser desktop e a larghezza 390 px, compresa
  apertura dei campi di modifica. Preview locale chiusa a fine verifica.

Laboratorio: `af-pacs-147e6b2f7a02`, directory ignorata
`rest/writable/pacs-labs/147e6b2f7a024548b4b42bb001376bc7`.
Contiene JUnit e `worklist-result.json`; dati solo sintetici.
Server Orthanc arrestato al termine; nessuna porta DIMSE pubblicata.
Istruzioni riproducibili: [laboratorio PACS](../../ops/pacs-validation/README.md).

## Da completare con il contesto del fornitore

- Trasporto automatico degli ordini e gestione riscontri/retry attraverso API,
  HL7 o altro canale documentato; non basta un endpoint QIDO/WADO.
- Ritiro remoto e riallineamento delle richieste già esportate.
- MWL SCP operativo con isolamento dei tenant, rete autorizzata e AE effettivi.
- MPPS/esiti, gestione delle riconciliazioni e correlazione automatica con le
  immagini ricevute, validando sia identità che identificativi dell'ordine.
- Collaudo dei codici, degli obblighi informativi e delle licenze del servizio.

Riferimenti: [DICOM PS3.4 MWL](https://dicom.nema.org/medical/dicom/current/output/chtml/part04/sect_K.6.html),
[Orthanc Worklists](https://orthanc.uclouvain.be/book/plugins/worklists-plugin-new.html).
