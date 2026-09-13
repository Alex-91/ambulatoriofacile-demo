# Percorso diagnostico: agenda, accettazione, immagini e referto

Implementazione sul branch `codex/percorso-diagnostico`, a partire dal rilascio
`40dda46f`. Questa estensione non è ancora pubblicata in produzione.

## Utilizzo

1. Nella cartella, sezione Prestazioni e referti, il medico dell'appuntamento apre
   **Richiedi esame**. Paziente, prestazione e data sono ripresi dal server.
   Si verificano collegamento PACS, codici, catalogo, modalità e destinazione.
   Questi parametri dipendono dalla configurazione del servizio diagnostico:
   non sono dedotti dal nome dell'esame.
2. Il medico salva e conferma la bozza. Rimane possibile creare richieste senza
   un appuntamento. Richiesta, Accession Number e Study Instance UID restano unici.
3. **Lista diagnostica**, dal menu agenda o dalla cartella, mostra le richieste
   confermate per giorno. La segreteria assegnata al medico registra l'accettazione.
   Medico autore e infermieri assegnati registrano inizio ed esecuzione, in ordine.
4. Dopo l'esecuzione, **Cerca e collega le immagini** cerca lo Study Instance UID
   esatto. PatientID, Issuer, UID e Accession Number devono tutti coincidere.
   Un errore o un cambiamento concorrente lascia invariati i collegamenti.
5. Il medico scrive la bozza del referto nella richiesta. Il documento viene salvato
   nella cartella esistente, con paziente, appuntamento, autore e tipo vincolati.
   **Apri il referto nella cartella** mostra il documento preciso, anche se vecchio.
   Da lì si usano finalizzazione, originale PDF e acquisizione/verifica della firma
   già disponibili; non viene prodotto un referto clinico automatico.
6. La richiesta mostra lo stato reale del referto. Le correzioni seguono la catena
   di revisioni della cartella: una nuova bozza non viene presentata come firmata.
   Lo storico della richiesta registra operatore, ora e avanzamenti. La cartella
   mostra le cinque richieste più recenti e rimanda all'elenco completo.

## Stati e permessi

La richiesta conserva gli stati `draft / ready / cancelled`.
L'avanzamento locale è separato: `awaiting → accepted → in_progress → performed`.
Nessun export o riscontro di immagini registra automaticamente l'esecuzione.
La worklist si può esportare prima dell'inizio, da richiesta aggiornata.

| Operazione | Medico autore | Infermiere assegnato | Segreteria assegnata |
|---|---|---|---|
| Lista operativa, con relazione di cura | Sì | Sì | Sì |
| Accettazione | Sì | Sì | Sì |
| Inizio / esecuzione | Sì | Sì | No |
| Creazione, modifica, conferma, export, annullamento | Sì | No | No |
| Collegamento immagini / scrittura referto | Sì | No | No |
| Lettura dei contenuti di un altro medico | Con consenso dossier corrente | Con consenso dossier corrente | No |

La lista operativa contiene soltanto anagrafica essenziale, prestazione, orario,
numero richiesta e avanzamento. Non restituisce note, nascita, identità PACS,
Study UID, contenuto del referto o payload cifrati. La relazione con il medico
e il paziente è ricontrollata nelle azioni. Utenti inattivi sono respinti.
L'abilitazione resta `pacs_dicom` insieme a `clinical_records`, gestita dal
super amministratore: niente nuova attivazione implicita. Menu e API rispettano
l'abilitazione; nessun ruolo amministrativo scavalca i permessi clinici.

## Integrità e concorrenza

- L'appuntamento viene verificato per paziente e medico, con mapping degli ID agenda.
  Prestazione e orario del form non possono sostituire quelli dell'agenda.
- Una fotografia HMAC di medico, appuntamento, slot, prestazione e data rileva
  spostamenti e modifiche. L'annullamento dell'appuntamento blocca le operazioni.
  La bozza si può aggiornare; una richiesta confermata va annullata e ricreata.
- Su MySQL i controlli sulla prenotazione bloccano le righe di appuntamento e slot
  durante la transazione. Non si cambiano gli stati dell'agenda per rappresentare
  l'accettazione diagnostica.
- Revisioni e aggiornamenti condizionati impediscono l'uso simultaneo della stessa
  versione. Referto, collegamento alla richiesta e audit si salvano insieme.
  Un errore annulla anche il salvataggio clinico annidato.
- Il referto conserva gli altri campi strutturati eventualmente compilati in cartella.
  Modifiche e correzioni non possono cambiarne tipo o appuntamento.
- Il collegamento immagini verifica nuovamente le identità dopo l'I/O remoto;
  nessuna transazione rimane aperta durante la chiamata al PACS. L'accession della
  richiesta è verificata anche quando si riapre lo studio.
- Lo storico usa UTC e revisione della richiesta per ordinare operazioni registrate
  nello stesso secondo; l'interfaccia mostra l'ora italiana.
- Richieste, lista operativa e storico sono paginati. Per le richieste antecedenti
  alla migration, il filtro giorno verifica anche la data nel payload cifrato.
  Le pagine possono avere meno di 25 risultati per effetto dei controlli di visibilità.
- Annullare una richiesta conserva documenti, immagini e storico. Il ritiro delle
  copie già esportate dal sistema destinatario resta un'operazione del fornitore.

## Installazione e confini

La migration `2026-09-13-120001_AddPacsOrderWorkflow.php` aggiunge sei campi
a `pacs_orders`, la revisione a `pacs_audit` e indici per lista e collegamento
referto. È ripetibile, non cancella dati e non ha rollback distruttivo.

`php rest/spark pacs:install <tenant-id> --apply` applica le tre migration PACS
nel tenant esplicito. Senza `--apply` controlla anche la completezza dei campi nuovi.
Non vengono attivati tenant né utilizzati database live dal collaudo.

Il lavoro completa il percorso interno all’applicazione e riusa l'interfaccia DICOMweb
e l'export MWL già esistenti. Non aggiunge un servizio C-FIND SCP, invio automatico
al fornitore, MPPS, storage immagini proprietario, nuova firma remota o accreditamento
FSE. Collegamenti effettivi e compatibilità con ciascun fornitore richiedono
configurazione e collaudo: il codice comune non sostituisce tali verifiche.

## Collaudo

- Suite PACS su SQLite sintetico, PHP Windows 8.3.6 e Linux 8.2.33: **51 test, 736 asserzioni**.
- Regressioni cartella, FSE e agenda: **102 test, 454 asserzioni**.
- MySQL 8.4.11 separato, senza porte pubblicate: migration ripetute, anagrafica
  cifrata, appuntamento, stati, referto reale in cartella e collegamento immagini.
  Quattro processi per ogni gara di export/annullamento, accettazione e creazione
  referto: un solo aggiornamento accettato e nessun documento duplicato.
- Errori artificiali di audit: rollback dell'ordine, della bozza clinica e dei
  collegamenti immagini. Controlli su tenant, ruoli, revoca modulo, prenotazione
  spostata/annullata, identificativi DICOM errati e accession modificata sul PACS.
- Controller: autenticazione, POST, CSRF, redirect operativo, escape e no-store.
- Preview HTML sintetiche della segreteria e del percorso medico verificate in
  browser. I form della preview non eseguono operazioni; le azioni sono provate
  tramite controller e servizi. Non è un collaudo con account o PACS di un cliente.
