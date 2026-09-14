# Riscontro alla richiesta di offerta — 14 settembre 2026

Documento interno di preparazione. «Disponibile» indica una funzione software,
non un prezzo incluso o un collaudo positivo presso il cliente. L'offerta deve
distinguere **SI incluso / SI con costo aggiuntivo / NO / DA VERIFICARE** soltanto
dopo avere definito il perimetro commerciale e verificato il canale del cliente.

| Richiesta | Stato tecnico | Condizione da chiudere |
|---|---|---|
| Anagrafiche, personale, prestazioni, agenda e prenotazioni | Funzioni presenti | Configurazione organizzativa/importazione dati |
| Accettazione e richieste diagnostiche | Flusso PACS presente | Corrispondenza delle prestazioni e collaudo del flusso reale |
| Cartella, consensi, storico, allegati e referti | Modulo presente, accessi clinici distinti | Modelli/permessi e collaudo struttura |
| Firma | Percorso esterno e verifica PAdES/CAdES presenti | Prodotto reale, trust e requisiti richiesti; non garantire compatibilità con ogni prodotto |
| Listini e preventivi | Nuovo modulo operativo, PDF e precompilazione fattura | Tariffe e trattamento fiscale; fatturazione controllata dall'operatore |
| Fatture, pagamenti, TS | Moduli esistenti | Dati fiscali, deleghe/credenziali e prove del canale |
| Convenzioni, assicurazioni e fondi | Nuovo registro accordi/pratiche/incassi | Specifiche di ogni eventuale invio; massimali annuali e portali non integrati |
| Spettanze | Nuovo calcolo/registro su fatture | Regole contrattuali, attribuzione delle prestazioni e trattamento fiscale |
| SSN | Nuovo registro interno prescrizioni/esenzioni/ticket/quote | Nessun collegamento ricetta elettronica o flusso regionale operativo |
| Esportazione contabile | Registri CSV generici | Tracciato e riconciliazione con contabilità destinataria |
| FSE specialistica RSA | Preparazione/firma/validazione/coda presenti | Accesso Umbria, accreditamento e prova pubblicazione reale |
| FSE radiologia RAD | Generatore e validazione ufficiale offline aggiunti | Schermata/mappatura RAD e integrazione autorizzata ancora da completare |
| PACS immagini | Connettore DICOMweb QIDO/WADO e associazione studi | Titolarità PACS, licenze, endpoint, credenziali e identità |
| MWL | Export worklist e collaudo C-FIND sintetico | Consegna automatica al server MWL reale non implementata |
| DICOM Storage | PACS esterno necessario; prove sintetiche disponibili | Ricezione C-STORE e archivio clinico produttivo non forniti dall'app |
| Associazione automatica | Identificativi e strumenti di associazione disponibili | Non attestare automazione universale macchina→PACS→referto |
| Interconnessione 4.0 | Protocolli/evidenze tecniche documentabili | Requisiti del bene e valutazione competente; nessuna attestazione automatica |
| Cloud e aggiornamenti | App pubblicata su Coolify, volumi persistenti | Capacità/storage/PACS e condizioni di servizio da quotare |
| Backup/ripristino | Tool cifrato e prove isolate esistenti | Pianificazione produttiva, retention, copia remota e prova del piano effettivo |
| Assistenza e continuità | Procedure tecniche disponibili | Orari, responsabili, tempi, RPO/RTO e impegni contrattuali da definire |

## Informazioni tecniche minime da raccogliere

1. PACS della struttura o del fornitore; disponibilità delle interfacce e contatto
   tecnico. Specifiche DICOM/DICOMweb, MWL, Storage, eventuale MPPS e licenze.
2. Domini degli identificativi paziente/ordine, accession e StudyInstanceUID;
   documentazione di conformità e un caso di prova sintetico concordato.
3. Canale FSE Umbria previsto, soggetto che cura l'accreditamento, tipologie RSA/RAD,
   profili/OID assegnati e ambiente di collaudo.
4. Prodotto di firma e formati richiesti, identità del firmatario e configurazione
   autorizzata. Non raccogliere password tramite email.
5. Fondi/assicurazioni/SSN effettivamente richiesti, regole di copertura e specifiche
   di invio/esportazione; regole delle spettanze e della contabilità.
6. Volumi e conservazione delle immagini/documenti, migrazione, assistenza e
   requisiti di ripristino da inserire nell'offerta.

Non occorre chiedere nuovamente se il software debba essere cloud o quale
gestionale usino. Non serve un inventario generico di tutte le macchine: la
documentazione delle interfacce previste guida la valutazione iniziale.

## Struttura economica da compilare senza inventare prezzi

Separare canone base, ogni modulo, FSE RSA/RAD, collegamento PACS, MWL e Storage,
firma, TS, integrazioni specifiche, avviamento, migrazione, formazione, assistenza,
storage incluso/eccedenze e servizi di terzi. Per ciascuna voce indicare costo
iniziale, ricorrente, limiti inclusi, dipendenze e criterio di collaudo.

## Criteri per il collaudo finale

Usare pazienti sintetici e annotare versione software, operatore, data, risultato
atteso, risultato osservato e riferimento della prova. Percorso: prenotazione,
accettazione, worklist alla destinazione reale, esame archiviato nel PACS,
associazione alla cartella, referto firmato, pubblicazione FSE RSA/RAD con ricevuta,
gestione dello scarto/reinvio, preventivo/fattura e riconciliazione. Verificare
separatamente isolamento degli spazi e accessi master/medico. Le prove tecniche
locali già superate non sostituiscono questi collaudi.

Riferimenti interni: `moduli-amministrativi.md`, `fse-radiologia-preparazione.md`,
`pacs-dicom-operativita.md`, `pacs-worklist-richieste.md`, `../../ops/full-backup.md`.
