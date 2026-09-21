# Preparazione dei moduli clinici per ogni spazio

Il master apre **Gestisci funzioni dello spazio → Prepara moduli clinici**, oppure il collegamento dalla pagina **Verifica collegamenti PACS**. L'indirizzo operativo è `cartella-clinica/configurazione`.

## Abilitazione e preparazione

Il super master mantiene il controllo delle abilitazioni. La pagina richiede Cartella clinica abilitata e una relazione attiva di master con lo spazio. Le sezioni PACS richiedono anche PACS/DICOM. La preparazione non modifica pacchetti, abilitazioni, ruoli o appartenenze degli utenti.

**Prepara lo spazio** esegue le migration dedicate della cartella, dei blocchi account e delle impostazioni. Se PACS è abilitato, prepara anche associazioni, richieste e avanzamenti. Opera sul solo database dello spazio corrente, conserva le righe esistenti e può essere ripetuto. Le DDL MySQL non sono una transazione globale: in caso di interruzione si ripete la procedura idempotente. Uno schema preesistente incompatibile richiede assistenza e una migration specifica; non vengono eliminate tabelle per ricrearle.

La chiave di cifratura rimane un prerequisito dell'ambiente, gestito sul server: la pagina non crea o sostituisce chiavi. La preparazione registra autore, spazio e data senza dati clinici o segreti.

## Profili PACS gestiti dal master

Il master può aggiungere e aggiornare profili DICOMweb con ricerca QIDO, download WADO e visualizzatore HTTPS opzionale. Sono supportate autenticazione Basic, Bearer e assenza di autenticazione. OAuth interattivo, certificati client, DIMSE e reti private richiedono una integrazione dedicata; il modulo non dichiara compatibilità universale con qualsiasi PACS.

La configurazione completa, incluse le credenziali, è cifrata nel database tenant. La cifratura lega il contenuto allo spazio e all'identificativo del profilo. Le credenziali non tornano mai nel form o nell'esito scaricabile. Campi vuoti conservano le credenziali solo se autenticazione e destinazioni restano identiche; cambiando destinazione vanno reinserite. Il master non può indicare file CA o nomi di variabili del server tramite il form.

I profili già forniti tramite configurazione privata del server restano disponibili e sono mostrati come gestiti sul server. I nuovi identificativi non possono sovrapporsi. Per modificare un profilo gestito sul server resta necessaria l'assistenza, evitando una sostituzione silenziosa dei collegamenti dei pazienti.

Salvataggi concorrenti sono controllati dalla revisione; un modulo aperto prima di una modifica viene rifiutato. Disattivare un profilo conserva configurazione e storico. Cambiare endpoint o ambito identificativi invalida la corrispondenza dell'impronta con le associazioni esistenti: occorre verificarle nuovamente.

Il trasporto mantiene HTTPS, verifica TLS, DNS pubblico vincolato per richiesta, limiti di risposta e timeout, nessun redirect e nessun proxy. Il salvataggio non esegue richieste al fornitore. Le prove di connessione vanno avviate esplicitamente su un profilo configurato.

## Collaudo di un'installazione

**Esegui collaudo** controlla gli archivi, la cifratura e una scrittura/rilettura cifrata nell'archivio privato. Il file contiene soltanto un valore casuale sintetico e viene rimosso anche in caso di errore. Non crea pazienti, account, referti o appuntamenti. Se si seleziona un PACS, prova QIDO con un identificativo casuale e richiede una risposta vuota compatibile; i dati eventualmente restituiti dal fornitore non vengono mostrati o salvati.

**Esegui e scarica esito** restituisce un JSON con spazio, data, singoli controlli, eventuale esito QIDO e limiti del collaudo. L'esito non contiene indirizzi o credenziali e non attesta worklist, MPPS, WADO, firma qualificata o interoperabilità completa.

Per lo stesso collaudo da terminale:

```text
php rest/spark clinical:check TENANT_ID
php rest/spark clinical:check TENANT_ID --profile PROFILO_CONFIGURATO
```

Il comando richiede uno spazio esplicito, attivo e abilitato. Non installa tabelle né modifica abilitazioni. Il codice di uscita è diverso da zero se un controllo selezionato non passa.

## Regressione prima di ogni aggiornamento

```powershell
powershell -ExecutionPolicy Bypass -File ops/pacs-validation/run-acceptance.ps1 -Python PERCORSO_PYTHON_VALIDATORE
```

Il comando usa le suite con bootstrap isolato, SQLite e storage sintetico, senza leggere `.env` e senza collegarsi ai database cliente. Esercita l'intero percorso appuntamento → richiesta → accettazione master → esecuzione medico → immagine → referto PDF, i permessi, i blocchi accesso, i profili cifrati, le firme sintetiche e i casi negativi. Produce JUnit e `result.json` in una nuova cartella ignorata sotto `rest/writable/acceptance-*`. Occorrono le dipendenze di sviluppo PHP e l'ambiente Python del validatore.

La procedura guidata elenca infine le prove da concordare con il fornitore: paziente sintetico, identificativi, richiesta, immagini, download, visualizzatore, referto e firma reale, eventuali worklist e avanzamenti. Questi passaggi non vengono marcati automaticamente come superati.
