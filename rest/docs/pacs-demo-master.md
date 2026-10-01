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
