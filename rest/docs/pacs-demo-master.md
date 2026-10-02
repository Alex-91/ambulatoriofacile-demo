# PACS: prova operativa dal master test

Accesso: master attivo del tenant 4, con Cartella clinica e PACS abilitati.
Menu PACS / DICOM condiviso tra agenda e console: Richieste di prova,
Immagini dimostrative, Lista diagnostica, Verifica collegamenti, Configurazione.

La pagina cartella-clinica/demo-pacs/richieste salva bozze con paziente
interamente sintetico e cataloghi fissi di esami/apparecchiature. Usa i validatori
e il generatore ModalityWorklist del modulo operativo. La modalitÃ  deve coincidere
con quella della destinazione. Conferma, accettazione, avvio, conclusione,
annullamento e referto di esercitazione hanno storico e revisioni.

Archivio JSON privato sotto WRITEPATH/pacs-trial/tenant-4/master-ID, separato
per operatore, massimo 200 richieste. Scritture con lock e sostituzione atomica.
POST protetti dal filtro CSRF clinico; contesto tenant/abilitazioni/master verificato
ad ogni richiesta. Nessuna modifica di ruolo, tabelle cliniche o chiavi.

Esportazione DICOM JSON e .wl solo dopo conferma e prima dell'esecuzione;
il download incrementa la revisione e richiede di aggiornare la pagina prima
dell'operazione successiva. Nessun invio remoto, MPPS o conferma di ricezione.
Le immagini campione restano una serie indipendente: non vengono presentate
come risultato delle nuove richieste, che hanno Study UID e accession propri.
I referti di prova non sono firmati nÃ© inseriti in cartella o inviati al FSE.

Regressione senza .env, DB o rete:
php ops/pacs-validation/trial-regression.php

## Selezione paziente

Il menu PACS / DICOM include Nuova richiesta: ricerca anagrafica nello spazio corrente, filtrata dalle relazioni di cura e accessibile ai medici. La selezione apre il percorso richieste già esistente; creazione, identità PACS, conferma, esportazione, avanzamento e referto mantengono i controlli di autorizzazione. La cartella ha un collegamento diretto Nuova richiesta per questo paziente. Nessuna modifica ai ruoli o migrazione.

Il master TEST TEST può scegliere fra tre identità sintetiche in Richieste di prova. Il paziente selezionato è conservato nella richiesta e nella worklist; le immagini campione rimangono indipendenti. Il modulo di prova non legge l’anagrafica reale e non invia ai dispositivi.

## Caso completo verificato (2 ottobre 2026)

`demo-pacs/cartella` mostra al master di tenant 4 una proiezione in sola lettura del caso sintetico prodotto in un SQLite separato. Il caso passa attraverso PacsOrderService (creazione, conferma, export worklist, avanzamenti, linkImages e saveReport) e ClinicalRecordService::patient; non si inseriscono record nei database operativi. Il referto resta bozza, senza firma o invio FSE. Gli identificativi della nuova richiesta vengono usati dal generatore DICOM e dal PACS cloud reale; 16 download WADO sono verificati per SHA256. Le immagini sono consultate online e vincolate al manifest fisso.

La pagina non è la cartella operativa modificabile e non importa le richieste salvate nel precedente modulo di prova. La selezione di pazienti reali resta nel percorso clinico con i permessi esistenti.

Riproduzione: impostare AF_SYNTHETIC_STATE sullo stato privato del laboratorio marcato; eseguire `php ops/pacs-validation/complete-case.php prepare`, `python ops/pacs-validation/seed-complete-case.py <stato-privato>`, poi `php ops/pacs-validation/complete-case.php complete`. Su Windows specificare curl.cainfo con il bundle CA valido, senza disabilitare TLS. Il comando usa soltanto SQLite sotto writable/pacs-complete-case, non carica .env. Il risultato verificato si trova in verified-case.json; chiave e DB rimangono ignorati, non pubblicarli. Il seed ritenta gli stessi UID senza duplicarli. Il PACS usa tmpfs: dopo una ricreazione occorre ricaricare questi oggetti.

## Demo operativa dalla cartella del paziente

Per il master del tenant 4 con PACS abilitato, la cartella mostra Nuovo esame · demo e il modulo completo. Le prove sono salvate nello storage dedicato per operatore con source_patient_id, filtrate nella cartella e protette contro accesso da un altro paziente. I POST ricontrollano tenant, ruolo e disponibilità del paziente. La worklist usa solo un’identità fittizia AF-DEMO-CHART-ID; non copia anagrafica reale. Immagini campione esplicitamente etichettate come simulazione possono essere aggiunte dopo l’esecuzione; il referto resta nel modulo demo di questa cartella, non nei documenti clinici. Nessuna modifica ai permessi della cartella reale.
