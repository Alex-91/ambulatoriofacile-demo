# PACS: prova operativa dal master test

Accesso: master attivo del tenant 4, con Cartella clinica e PACS abilitati.
Menu PACS / DICOM condiviso tra agenda e console: Richieste di prova,
Immagini dimostrative, Lista diagnostica, Verifica collegamenti, Configurazione.

La pagina cartella-clinica/demo-pacs/richieste salva bozze con paziente
interamente sintetico e cataloghi fissi di esami/apparecchiature. Usa i validatori
e il generatore ModalityWorklist del modulo operativo. La modalità deve coincidere
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
I referti di prova non sono firmati né inseriti in cartella o inviati al FSE.

Regressione senza .env, DB o rete:
php ops/pacs-validation/trial-regression.php
