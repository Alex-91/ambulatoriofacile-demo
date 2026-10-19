# Prenotazioni WhatsApp

## Accesso e configurazione

- Console piattaforma → Chatbot WhatsApp: selezionare lo spazio e utilizzare l’unico interruttore di attivazione. L’endpoint mantiene il controllo degli account master della piattaforma e conserva le regole preesistenti di conferma/cancellazione.
- Profilo spazio → Prenotazioni WhatsApp (`login/spazio/chatbot-whatsapp`): accesso ai responsabili dello spazio con appartenenza accettata e attiva. Il tenant non può concedersi l’abilitazione.
- Selezionare il medico e configurare prestazioni, parole chiave, conferma e dati del paziente. La prima configurazione salvata attiva il nuovo percorso all’interno del chatbot già abilitato.
- Modalità prestazione unica: assegna la prestazione predefinita, senza proporne la scelta al paziente.
- Dati: nome/cognome sempre, anche data di nascita, codice fiscale con ricerca esatta, oppure codice fiscale e nome/cognome sempre. Non vengono aggiornati automaticamente i dati di un’anagrafica esistente. Le nuove anagrafiche vengono create alla conferma effettiva dell’appuntamento, con cifratura e associazione al medico.
- Il codice fiscale associa un’anagrafica, non autentica il mittente: la risposta pubblica non espone il nome recuperato dal database. Una corrispondenza ambigua viene inviata alla segreteria.
- Sedi, indirizzi, giorni e orari vengono ricavati automaticamente dagli slot dell’agenda del medico selezionato.

## Esecuzione

Il webhook esistente, dopo la verifica e la risoluzione del tenant, passa i messaggi testuali al nuovo percorso prima delle regole precedenti. I messaggi estranei a una prenotazione restano disponibili alle regole precedenti. Le opzioni sono numerate, con otto scelte per pagina e comando `altro`. `annulla` interrompe la conversazione; `segreteria` crea una richiesta quando consentito.

La sessione scade dopo 30 minuti di inattività. Le modifiche alla configurazione invalidano il percorso precedente. I messaggi duplicati riutilizzano una ricevuta persistente; il riferimento della prenotazione consente il recupero anche se il commit sull’agenda precede un errore di persistenza nella piattaforma. La selezione non riserva lo slot: conferma automatica e approvazione della segreteria verificano nuovamente durata, blocchi, occupazione e sede. Viene usato il modello agenda esistente con connessione esplicita al tenant, senza saltare i blocchi degli operatori.

Le richieste manuali e le richieste di assistenza compaiono nella sezione Segreteria (ultime 100). Le richieste manuali non occupano l’agenda fino all’approvazione. L’esito viene notificato via WhatsApp. Se l’invio fallisce, `Riprova notifica` ripete solo il messaggio; non ricrea l’appuntamento. Il gateway non offre qui una garanzia di consegna esattamente una volta: un’interruzione dopo l’invio ma prima della registrazione può ripetere la notifica.

L’anteprima usa lo stesso motore conversazionale, con un adattatore esclusivamente dimostrativo. Non scrive sull’agenda, non crea anagrafiche, non invia messaggi. Le date e gli orari dell’anteprima non attestano la disponibilità reale.

## Installazione

Prima dell’utilizzo, applicare nell’ambiente di destinazione la migration `2026-10-19-100001_CreateWhatsAppBookings` sul gruppo `platform`. In alternativa è disponibile il comando mirato e idempotente, dalla directory `rest`:

```powershell
php spark whatsapp:install-bookings
```

Il comando usa il database platform configurato: verificare l’ambiente prima di eseguirlo. Crea solo le quattro tabelle di configurazione, sessioni, ricevute e richieste. Non attiva alcuno spazio. Lo schema agenda delle prestazioni e degli appuntamenti su più slot deve essere già installato nei tenant; il webhook non esegue riparazioni dello schema.

Servono il gateway WhatsApp instradato sul tenant, il dispositivo connesso e la chiave di cifratura anagrafica già prevista dall’applicazione. Nessuna modifica al gateway o a Coolify è necessaria per il codice. Il rilascio e la migrazione dei database esistenti non fanno parte delle verifiche locali effettuate.

## Verifiche riproducibili

```powershell
php -d xdebug.mode=off rest/tests/whatsapp_booking_regression.php
php -d xdebug.mode=off rest/tests/whatsapp_booking_http.php
php -d xdebug.mode=off rest/tests/whatsapp_booking_mysql.php --isolated-local-mysql
node rest/tests/whatsapp_booking_browser.cjs
```

La suite MySQL richiede un server usa-e-getta su `127.0.0.1:13369`, senza password, e crea/elimina esclusivamente database casuali `af_wa_test_*`. Non legge `.env`. Include tre prenotazioni concorrenti sullo stesso slot. La suite browser richiede Playwright (eventualmente tramite `NODE_PATH`) e può usare un eseguibile Chromium tramite `BROWSER_EXE`; renderizza la vista reale con dati sintetici e prova JavaScript e motore conversazionale, senza autenticazione o gateway reali.

## Disponibilità automatica dall’agenda

Selezionando il medico, il chatbot usa tutti gli slot futuri liberi della sua agenda, senza richiedere sede, indirizzo, preavviso o periodo prenotabile. Le precedenti impostazioni di disponibilità del chatbot vengono ignorate anche per configurazioni già salvate. Ogni orario riporta la sede e l’indirizzo letti dal catalogo dell’agenda; il riepilogo usa la sede dello slot scelto. Per modificare giorni, orari e sedi si interviene nell’agenda. Restano configurabili percorso, prestazioni proposte, identificazione del paziente e modalità di conferma.
