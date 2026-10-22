# Prenotazione online del paziente: guida di implementazione

Destinatario: Alessio, che la implementa nel gestionale (repo `Alex-91/ambulatoriofacile-demo`, CodeIgniter 4, un database MySQL per studio) con l'aiuto di Codex. Prima versione: 21 ottobre 2026. Aggiornata il 22 ottobre 2026 con le modifiche chieste da Simone e Alessio.

Questa guida è la fonte unica per lo sviluppo. Il canvas e il prototipo mostrano come deve apparire; qui c'è come deve funzionare. Se il canvas e questa guida dicono cose diverse su una regola (limiti, ordine dei passi, testi di sicurezza), vale questa guida.

Verifica dell'identità, decisione del 22 ottobre 2026 che supera quella del 21 ("mai SMS di verifica"): i metodi sono tre, e per ogni studio il team di Ambulatorio Facile sceglie quali accendere e in che ordine. WhatsApp (è il paziente a scrivere allo studio, a costo zero per lo studio), codice o link via email (gratuito) e codice via SMS (a consumo sul credito dello studio, spento di default, con protezioni dedicate). Lo studio può anche togliere la verifica del tutto: è la prenotazione senza registrazione della sezione 5.10.

Tutti i percorsi di file del gestionale sono relativi alla radice del repo. I numeri di riga sono quelli del 21 ottobre 2026: prima di modificare, cerca il nome della funzione, perché le righe possono essere cambiate.

> **Novità del 22 ottobre 2026**
>
> - **Tutto configurabile per studio.** Il team di Ambulatorio Facile decide per ogni studio l'ordine delle schermate, quali schermate ci sono, cosa chiedere, come si verifica il paziente, chi può prenotare, il logo e i testi. Lo fa da un editor riservato al ruolo super admin AF, con bozza, pubblicazione, cronologia, ripristino e anteprima dal vivo. Lo studio vede un riepilogo in sola lettura. Sezione 4.
> - **Ordine e passi.** L'ordine dell'array `passi` è l'ordine delle schermate e ogni passo facoltativo si può spegnere, con tre sole regole d'ordine fisse. La sequenza dei passi si costruisce dalla configurazione lato client e il server la rivalida. Sezioni 4.4 e 4.6.
> - **"Il primo disponibile" si può togliere.** Il paziente deve allora scegliere una persona. Sezione 4.5.
> - **Selezione automatica.** Un solo professionista, una sola sede o una sola prestazione possibile: si seleziona da sola, la schermata non si mostra e la scelta resta visibile nel riepilogo. Sezione 4.5.
> - **Logo dello studio.** Si carica dall'editor, compare in alto nella pagina e nel footer delle email; senza logo resta il monogramma. Sezione 4.8.
> - **SMS come terzo metodo di verifica.** WhatsApp, email e SMS: lo studio sceglie quali accendere. L'SMS è spento di default, usa il modulo SMS dei promemoria, si paga a consumo sul credito dello studio e ha protezioni contro lo SMS pumping. Sezione 5.6.
> - **Prenotazione senza registrazione.** Lo studio può togliere la verifica: il paziente lascia un recapito e prenota, con Turnstile alla conferma, limiti per dispositivo e IP e approvazione consigliata. Sezione 5.10.
> - **Nuova numerazione.** La sezione 4 è nuova: anti abuso diventa 5, architettura 6, email 7, PWA 8, pagina dello studio 9, piano di lavoro 10.

## 1. Cosa c'è in questa cartella

- `prototipo.html`: prototipo navigabile del flusso del paziente, un solo file autonomo, si apre nel browser senza server. Mostra il percorso di Rosa (paziente nuova, studio in modalità "Nuovi pazienti su approvazione") fino a "Richiesta inviata", la conferma, la gestione della prenotazione e gli stati di errore. Va guardato a 390, 768 e 1280 pixel di larghezza. Il codice di prova è 123456, sia per l'email sia per l'SMS.
- `email/`: un file HTML per ciascuna delle 13 email (da P1 a P8 per il paziente, da S1 a S5 per lo studio), già nel formato finale a tabelle con CSS in linea, più `email/testi.md` con oggetto, preheader e versione solo testo di ogni email, gli SMS e WhatsApp di conferme e promemoria, l'SMS del codice di verifica e i testi delle schermate nuove del 22 ottobre (scelta del metodo, "Come ti contattiamo", prenotazione senza verifica, errori dell'editor). Due file base per lo sviluppo: `email/_layout.html` (la shell comune, da convertire in `rest/app/Views/emails/booking/_layout.php`, con l'elenco dei segnaposto) e `email/_componenti.html` (badge, righe, bottoni e box da riusare).
- `README.md`: questa guida.
- Canvas su Claude Design, con tutte le tavole (token, schermate mobile e desktop, stati, PWA, area studio, email): [apri il canvas](https://claude.ai/artifact/2XYVSGcwiJsPqhmR6fWwfq).
- Pagina nel CRM del sito, con il prototipo in anteprima su tre larghezze, la galleria delle email e questa guida: [ambulatoriofacile.it/admin/prenotazione-online](https://ambulatoriofacile.it/admin/prenotazione-online) (serve il tuo accesso `alessio` al CRM).

Dati di esempio usati ovunque, tutti inventati: Poliambulatorio Santa Chiara (Santa Chiara Servizi Sanitari S.r.l.), sedi di Empoli (Via Roma 18) e Vinci (Piazza della Libertà 3); professionisti Dott.ssa Elena Conti, Dott.ssa Giulia Ferri, Dott. Marco Bellini, Dott. Luca Romano; paziente Rosa Bianchi; prenotazione di giovedì 12 novembre 2026 alle 17:30, codice K7P2QH. Non usare mai dati di studi o pazienti reali nei test, nelle fixture e negli screenshot.

## 2. Come funziona oggi e cosa va corretto subito

### 2.1 Il percorso attuale in breve

Il paziente si registra su `/register` con il codice fiscale come nome utente, entra da `/login` e conferma con un OTP a 4 cifre. Le tessere di prenotazione (`/prenotazioni/mmg` e `/prenotazioni/specialisti`) compaiono solo se un admin le abilita a mano. La prenotazione mostra al massimo 10 slot in una lista piatta e "Prenota" scrive subito in `dap12_agenda_appuntamenti` con stato `CONFERMATO`, senza riepilogo, senza scelta della prestazione o della sede, senza notifiche. Non esiste un link pubblico per studio: il tenant si ricava dalle credenziali.

Il riferimento più maturo è il motore WhatsApp (`rest/app/Services/WhatsAppBookingAgenda.php`, `WhatsAppBookingEngine.php`, `WhatsAppBookingService.php`, `WhatsAppBookingConfig.php`): disponibilità reale con esclusione di lock, giorni bloccati e sovrapposizioni, prestazioni con durata, prenotazione atomica con `GET_LOCK` più `FOR UPDATE` più `saveAppointment`. Il nuovo flusso web parte da lì.

### 2.2 Correzioni di sicurezza obbligatorie (prima di qualunque grafica)

Queste correzioni valgono anche se il nuovo flusso non viene ancora pubblicato: le rotte vecchie sono raggiungibili oggi.

| # | Problema | Dove | Cosa fare |
|---|---|---|---|
| 1 | IDOR sull'annullamento: qualunque utente loggato annulla qualunque appuntamento conoscendone l'id | `rest/app/Models/PrenotazioniDataModel.php`, `deleteBookingByPrenotazioneId` (righe 187-257) | Verificare che l'appuntamento appartenga al `id_client` della sessione e sia futuro e non annullato. Con il nuovo flusso, rimuovere le rotte `prenotazioni/mmg/cancella` e `prenotazioni/specialisti/*/cancella` (`rest/app/Config/Routes.php`, righe 503-540). |
| 2 | Il client sceglie medico e slot senza controlli: si prenotano domeniche, festivi, giorni bloccati e medici diversi dal proprio | `rest/app/Controllers/Prenotazioni/MedicoFamigliaController.php` righe 204-205, `MedicoSpecialistaController.php` righe 168-169, `PrenotazioniDataModel::bookSlot` (righe 445-545) | Validare lato server professionista, slot, sede e prestazione con `AgendaAvailabilityService` (sezione 6.1) al momento della scrittura. Mai fidarsi di `id_medico` e `slot` del POST. |
| 3 | Doppie prenotazioni: niente `FOR UPDATE`, niente `GET_LOCK`, lock di `dap14` ignorati, INSERT diretto che salta `AgendaAppointmentModel::saveAppointment` | `PrenotazioniDataModel::bookSlot` | Scrivere solo tramite `saveAppointment` con `check_active_locks` e dentro lo schema di lock della sezione 6.6. |
| 4 | CSRF non verificato: `csrf_field()` c'è nelle form ma nessun filtro copre `prenotazioni/*` | `rest/app/Config/Filters.php`, array `$filters` (righe 200-216) | Aggiungere un filtro dedicato sul modello di `WhatsAppBookingCsrfFilter` per `prenotazioni/*`, `prenota/*` e `api/prenota/*` (sezione 6.5). |
| 5 | Dati personali nei log: `print_r($utente)` dentro `log_message` | `MedicoFamigliaController.php` righe 115 e 125, `rest/app/Models/PrenotazioniMmgModel.php` righe 63, 134, 151-155 | Togliere tutti i `print_r` e ogni dato personale dai log dei controller e dei model `Prenotazioni*`. Loggare solo id tecnici. |
| 6 | `/api/doctors` è pubblico e restituisce email e cellulare decifrati di tutti i medici | `rest/app/Config/Filters.php` riga 140 (eccezione pubblica), `rest/app/Controllers/DoctorController.php` righe 21-38 (`findAllDecrypted`) | Metterlo dietro autenticazione, oppure restituire solo id, nome, qualifica. Mai email o cellulare. |
| 7 | OTP debole: nessun limite di tentativi, codice non invalidato dopo l'uso, codice fisso `2510` per alcune utenze | `rest/app/Models/AuthCodeModel.php` (`checkOtp`, righe 32-52), `rest/app/Controllers/AuthMFA/AuthenticationController.php` righe 640 e 668 | Limite di 5 tentativi, cancellazione del codice dopo l'uso, rimozione del codice fisso, mai OTP nella query string. |
| 8 | QR del dispositivo generato da un servizio esterno (`api.qrserver.com`) | ricerca `qrserver` nel repo | Generarlo in locale (libreria PHP per QR). |
| 9 | Registrazione: un account può collegarsi a una scheda `dap02_clients` esistente senza prova di possesso del cellulare | `rest/app/Controllers/RegisterController.php` | Chiedere la verifica del cellulare prima del collegamento, con la verifica WhatsApp o con il codice SMS della sezione 5.6. L'email non basta: prova il possesso dell'indirizzo, non del cellulare. |
| 10 | Dati personali in chiaro in `state_json` delle sessioni WhatsApp, senza pulizia | `platform_whatsapp_booking_sessions`, `receipts`, `requests` | Job di pulizia periodica delle righe scadute. |

Ogni correzione ha il suo test di regressione (sezione 10).

## 3. Esperienza del paziente

### 3.1 Punti di ingresso

| Ingresso | URL | Note |
|---|---|---|
| Link pubblico dello studio | `/prenota/{tenant_key}` | Da sito, scheda Google, QR in sala d'attesa, risposte WhatsApp. Il tenant si ricava dall'URL, non dal login. |
| Link profondo | `/prenota/{tenant_key}?prestazione={id}&professionista={id}&sede={id}` | Per i bottoni "Prenota" sul sito dello studio. Parametri non validi, o in conflitto con la configurazione dello studio: si ignorano in silenzio. I parametri validi preselezionano la scelta (sezione 4.6). |
| Le mie prenotazioni e PWA | `/prenota/{tenant_key}/le-mie-prenotazioni` | `start_url` della PWA. Accesso con codice, senza password. Esiste solo se lo studio ha almeno un metodo di verifica acceso (sezione 5.10). |
| Gestione da email, SMS o WhatsApp | `/prenota/{tenant_key}/p/{booking_ref}?t={token}` | Link firmato, vale solo per quella prenotazione. |
| Invito personale (modalità `invito`) | `/prenota/{tenant_key}/invito/{token}` | Link firmato, a scadenza, una sola prenotazione. |
| Anteprima della bozza (solo team AF) | `/prenota/{tenant_key}?anteprima=bozza&firma={firma}` | Firma a scadenza legata all'utente AF, nessuna scrittura reale (sezione 4.2). Senza firma valida il parametro si ignora. |
| Vecchie rotte | `/prenotazioni/mmg*`, `/prenotazioni/specialisti*` | Redirect 301 al nuovo flusso quando è pubblicato. |

### 3.2 Il flusso, passo per passo

Questo è il percorso con la configurazione predefinita (sezione 4.3), quella che vale quando il team AF non ha cambiato nulla. Per ogni studio il team AF può cambiare l'ordine dei passi, spegnerne alcuni e togliere la verifica: la sequenza reale si costruisce dalla configurazione pubblicata con l'algoritmo della sezione 4.6, e i testi qui sotto restano gli stessi in qualunque ordine. Ogni passo è uno stato della history (`pushState`): il tasto indietro del browser torna al passo precedente mostrato, senza perdere le scelte.

La barra di avanzamento conta solo i passi mostrati davvero. Con la configurazione predefinita ha 5 segmenti (Prestazione, Professionista, Orario, I tuoi dati, Conferma); sede, per chi, verifica e codice sono sottopassi e non aggiungono segmenti. Un passo spento, o saltato perché c'era una sola scelta, non compare nella barra.

1. **Prestazione.** Campo di ricerca "Cosa ti serve?" e lista raggruppata per specialità. Ogni voce: nome pubblico, durata, prezzo dello studio se inserito ("100 €, paghi in studio"). In fondo: "Non sai cosa prenotare? Chiedi allo studio" (richiesta di contatto). Se lo studio ha una sola prestazione prenotabile, si seleziona da sola e la schermata non si mostra.
2. **Professionista.** Prima card fissa "Il primo disponibile", che il team AF può togliere (`scelte.primo_disponibile`, sezione 4.5). Poi una card per professionista con iniziali o foto, nome, qualifica (solo in pagina, mai nei messaggi), sedi e il badge "Primo posto libero: mar 3 novembre". Se per la prestazione e la sede già scelte c'è un solo professionista, si seleziona da solo e la schermata non si mostra.
3. **Sede** (sottopasso, solo se le sedi possibili sono più di una; con una sola si seleziona da sola). Nome, indirizzo, "Apri in Maps", badge di prima disponibilità.
4. **Data e orario.** Su mobile una striscia di 14 giorni con pallino di disponibilità (posti, pochi posti, nessun posto, chiuso); su desktop un mese a griglia a sinistra e gli orari a destra. Orari in chip da 48 px raggruppati in Mattina, Pomeriggio, Sera, con cifre tabulari. Un giorno senza posti propone il primo giorno libero con un bottone. Toccare un orario NON lo blocca: lo seleziona soltanto (vedi sezione 5).
5. **Per chi è la visita** (sottopasso). "Per me" oppure "Per un'altra persona" (familiare, minore, assistito), con la dichiarazione di essere autorizzati e, per i minori, di essere genitore o tutore. Se il passo è spento la visita è sempre per chi prenota.
6. **Verifica che sei tu** (sottopasso). Si chiede il cellulare (un solo campo, prefisso +39 fisso di default: serve per i promemoria e per riconoscere chi è già paziente) e si sceglie come verificare, tra i metodi accesi dallo studio in `verifica.metodi` e nell'ordine della configurazione; il primo ha il badge "Consigliato". **Con WhatsApp**, solo se lo studio ha il numero WhatsApp collegato al gestionale. **Con un codice via email**: il paziente scrive l'indirizzo. **Con un codice via SMS** (spento di default): il codice arriva al cellulare appena indicato. Con la configurazione predefinita i metodi sono WhatsApp (consigliato) ed email. Se un solo metodo è acceso non c'è la scelta: si va diretti a quel metodo. Testo: "Così siamo sicuri che sia proprio tu e nessuno può prenotare a tuo nome. Non serve creare un account." Prima di avviare la verifica parte il controllo anti bot invisibile (Turnstile, sezione 5.6); il paziente non vede nulla se va tutto bene.
7. **Conferma della verifica** (sottopasso). Con WhatsApp la pagina dice "Manda il messaggio su WhatsApp": il pulsante "Apri WhatsApp" apre l'app con un messaggio già scritto verso il numero dello studio, per esempio "Confermo la prenotazione K7P2QH, codice di verifica 4821"; il paziente tocca Invia e la pagina passa da sola al passo successivo, senza ricaricarsi (meccanica nella sezione 5.6). Su desktop, al posto del pulsante, compare un codice QR da inquadrare col telefono. Con l'email o con l'SMS: sei caselle per il codice a 6 cifre (`autocomplete="one-time-code"`, `inputmode="numeric"`), "Rimanda il codice" attivo dopo 60 secondi, link "Cambia indirizzo" (email) o "Cambia numero" (SMS). Dopo la verifica, se l'orario è già scelto, viene riservato per 10 minuti e compare il banner discreto "Teniamo questo orario per te per 9:58" (conto alla rovescia, `aria-live="polite"`, annunciato a 5 minuti e a 1 minuto). Se l'orario viene dopo la verifica nell'ordine dello studio, la riserva nasce al "Continua" del passo orario.
8. **I tuoi dati.** Solo per chi è nuovo: nome e cognome (sempre obbligatori), poi i campi della sezione `dati` della configurazione, ciascuno obbligatorio, facoltativo o nascosto. Con la configurazione predefinita: email facoltativa, data di nascita e codice fiscale nascosti (quando lo studio li chiede hanno l'etichetta "richiesto dallo studio"), nota facoltativa. Il cellulare compare già compilato e in sola lettura, con la dicitura "verificato", se la verifica è stata fatta con WhatsApp o con l'SMS; con l'email resta un campo modificabile marcato "non ancora verificato", e l'email verificata compare già compilata. Nota con l'avviso "Non serve scrivere dettagli sulla tua salute: ne parlerai in visita". Casella "Ho letto l'informativa privacy di {Studio}" non pre-spuntata, con link. Chi è già in archivio vede invece "Bentornato Davide" con i dati già compilati e conferma con un tocco (regole di riconoscimento nella sezione 5.3).
9. **Riepilogo.** Prestazione, professionista, sede con indirizzo, data e ora in lettere, durata, paziente. Ogni riga scelta dal paziente ha "Modifica" che torna al passo giusto; le scelte automatiche restano visibili senza "Modifica" ("Con Dott.ssa Elena Conti", "Sede di Empoli"), perché non c'è niente da cambiare. Se il team AF l'ha scritta, qui compare `testi.nota_riepilogo`. Regola: "Puoi spostare o annullare fino a 24 ore prima". Bottone "Conferma la prenotazione". È sempre l'ultimo passo.
10. **Esito.** 10a "Richiesta inviata" per un paziente nuovo in modalità approvazione: timeline in tre passi (Richiesta inviata, Lo studio la conferma, Ricevi la conferma), testo chiaro che l'orario è tenuto per lei ma non è ancora confermato, azione "Annulla la richiesta". 10b "Prenotazione confermata": titolo "Ci vediamo giovedì 12 novembre alle 17:30", codice K7P2QH, "Aggiungi al calendario" (ICS, Google, Outlook), "Come arrivare", "Gestisci prenotazione", poi la card "Installa l'app".

**Senza verifica.** Se lo studio ha tolto la verifica (sezione 5.10) i passi 6 e 7 non ci sono. In "I tuoi dati" compare il blocco "Come ti contattiamo" con cellulare ed email ("Ci serve per avvisarti se l'appuntamento cambia."), il riepilogo dice "Ti contatteremo a: 347 812 6650" e l'esito mostra il codice prenotazione in evidenza, con "Conservalo: ti serve se chiami lo studio".

Obiettivo misurabile: dalla scelta della prestazione alla conferma in meno di 60 secondi per un paziente già in archivio con dispositivo riconosciuto.

### 3.3 Gestione della prenotazione

- **Le mie prenotazioni.** Accesso con uno dei metodi di verifica accesi per lo studio, gli stessi del flusso: con WhatsApp il messaggio già scritto è "Confermo l'accesso K7P2QH, codice di verifica 4821"; con l'email arriva il link di accesso P8 (valido 15 minuti, una volta sola); con l'SMS arriva un codice a 6 cifre al numero indicato. Nessuna password. Tab Prossime, Passate, Annullate. Badge di stato con icona più testo: Confermata, In attesa di conferma, Annullata, Svolta. "Prenota di nuovo" sulle passate. Se lo studio non ha nessun metodo acceso (`verifica.metodi` vuota, possibile solo senza verifica) il bottone "Le mie prenotazioni" non c'è e la gestione passa solo dal link firmato (sezione 5.10).
- **Dettaglio.** Stessa card del riepilogo, conto alla rovescia ("tra 2 giorni"), azioni Sposta, Annulla, Aggiungi al calendario, Come arrivare, Contatta lo studio (telefono, WhatsApp ed email dello studio, mai quelli del professionista). Le azioni non più consentite restano visibili, disattivate, con il motivo: "Mancano meno di 24 ore: per spostare chiama lo studio".
- **Sposta.** Riusa il passo Data e orario bloccato su prestazione, professionista e sede. Riepilogo "Da giovedì 12 novembre 17:30 a martedì 17 novembre 9:00" e "Conferma lo spostamento". Operazione atomica: se il nuovo orario non è più libero, il vecchio resta valido. Se lo studio ha `regole.spostamento_consentito: false`, al posto di "Sposta" c'è "Per spostare chiama lo studio".
- **Annulla.** Bottom sheet su mobile, dialogo su desktop, mai `confirm()` nativo. Motivo facoltativo a chip. Bottone primario "Tieni l'appuntamento", bottone di pericolo "Annulla la prenotazione".

### 3.4 Stati ed errori

Al paziente mai codici tecnici, nomi di tabelle o testo di eccezioni (`$e->getMessage()` non arriva mai al client).

| Situazione | Comportamento | Testo |
|---|---|---|
| Caricamento | Skeleton che ricalca il layout | nessuno |
| Orario preso nel frattempo (409) | Schermata con i 3 orari più vicini | "Questo orario è appena stato prenotato. Ecco i più vicini." |
| Riserva scaduta (10 minuti) | Banner con "Riprova con lo stesso orario" (nuova riserva se ancora libero) | "Il tempo per confermare è scaduto." |
| Codice email o SMS errato | Errore sotto le caselle, che si svuotano | "Codice non corretto. Ti restano 3 tentativi." |
| Troppi tentativi sul codice email o SMS | Blocco 15 minuti | "Per sicurezza abbiamo bloccato i tentativi per 15 minuti." |
| Troppe verifiche avviate | Blocco fino alla fine dell'ora | "Hai chiesto troppe verifiche. Riprova più tardi o chiama lo studio al {telefono}." |
| Messaggio WhatsApp non arrivato (dopo 10 minuti) | La pagina propone di riprovare o di usare un altro metodo acceso | "Non abbiamo ricevuto il tuo messaggio. Riprova o scegli un altro modo per verificare." |
| Messaggio WhatsApp da un altro numero | Resta nella pagina, la verifica non avanza | "Hai scritto da un numero diverso da quello indicato. Scrivi dal tuo numero oppure scegli un altro modo per verificare." |
| SMS non arrivato | "Rimanda il codice" dopo 60 secondi, poi la proposta degli altri metodi accesi | "Non è arrivato? Rimanda il codice oppure scegli un altro modo per verificare." |
| WhatsApp non collegato allo studio | Si vedono solo gli altri metodi accesi | nessun errore |
| Tetto giornaliero di SMS raggiunto, o credito SMS esaurito | Il metodo SMS non compare fino al giorno dopo; se era l'unico metodo, schermata con telefono | nessun errore, oppure "Per prenotare chiama lo studio al {telefono}." |
| Recapito mancante (senza verifica) | Errore sotto il blocco "Come ti contattiamo" | "Scrivi il cellulare o l'email: ci serve per avvisarti se l'appuntamento cambia." |
| Troppe prenotazioni dallo stesso dispositivo o rete (senza verifica) | Blocco con telefono | "Da qui sono già state fatte diverse prenotazioni oggi. Per prenotarne altre chiama lo studio al {telefono}." |
| Configurazione cambiata durante la prenotazione | Si torna al primo passo con una scelta non più valida, le altre restano | "Lo studio ha appena aggiornato la pagina di prenotazione. Controlla le tue scelte e continua." |
| Limite di prenotazioni attive | Blocco con link a Le mie prenotazioni (o al telefono, se Le mie prenotazioni non c'è) | "Hai già 2 prenotazioni in arrivo con questo studio." |
| Stessa prestazione già prenotata | Dialogo prima del riepilogo | "Hai già un appuntamento per questa visita giovedì 12 novembre. Vuoi spostarlo?" |
| Sovrapposizione | Blocco | "A quell'ora hai già un altro appuntamento." |
| Numero non in archivio, modalità `archivio` | Schermata con telefono | "Per la prima visita chiama lo studio al {telefono}." |
| Numero bloccato dallo studio | Stessa schermata della riga sopra, nessun dettaglio | "Per prenotare chiama lo studio al {telefono}." |
| Prenotazioni sospese dallo studio | Pagina con contatti | "In questo momento lo studio non accetta prenotazioni online. Chiama il {telefono}." |
| Link scaduto o non valido | Pagina dedicata | "Questo link non è più attivo." con "Accedi alle tue prenotazioni" (solo se lo studio ha un metodo di verifica acceso) |
| Errore di rete o server | Messaggio in linea, i dati restano | "Qualcosa non ha funzionato. Riprova tra qualche secondo." |

### 3.5 Regole di interfaccia da rispettare

- Token visivi: quelli del canvas (fondo `#f6f8fa`, card bianche con bordo `#e2e8f0`, testo `#1a1a2e`, secondario `#64748b`, titoli navy `#08364F`, primario `#00795f` con testo bianco, hover `#006a51`, accento decorativo `#00ac8d` solo su superfici e mai sotto un testo). Font Inter ospitato in locale, nessun Google Fonts.
- Lo studio in alto (logo o monogramma, nome, sede), Ambulatorio Facile solo nel footer, piccolo: "Prenotazioni gestite con" più logo. Il logo viene da `aspetto.logo_url`, alto 40 px su mobile e 48 px su desktop (sezione 4.8); con `aspetto.mostra_nome_con_logo: false` il nome dello studio non si ripete accanto al logo.
- Mobile first: barra fissa in basso con il CTA da 52 px. Desktop da 1024: due colonne, riepilogo fisso a destra largo 360 con il CTA.
- Accessibilità WCAG 2.2 AA: `lang="it"`, zoom sempre consentito (mai `user-scalable=no`), label vere sugli input, focus ring 2 px `#00795f` con offset 2, aree tattili di almeno 44 px, stato selezionato anche con spunta o bordo e non solo col colore, chip orario come `role="radiogroup"` con frecce da tastiera, focus sul titolo a ogni cambio di passo, input di almeno 16 px.
- Nessuna libreria da CDN esterni (niente jQuery, AdminLTE, FontAwesome, ionicons): JavaScript vanilla a moduli ES, icone SVG inline. Unica eccezione: lo script di Cloudflare Turnstile (sezione 5.6).
- Nessun Google Analytics, Clarity o Pixel nell'area paziente. Il funnel si misura lato server, senza dati personali.

## 4. Configurazione per studio (team Ambulatorio Facile)

Ogni studio ha la sua pagina di prenotazione e il team di Ambulatorio Facile la configura su misura: l'ordine delle schermate, quali schermate ci sono, cosa chiedere al paziente, come si verifica, chi può prenotare, il logo, i testi. La configurazione è un documento JSON versionato, salvato nel database dello studio. La pagina del paziente non ha un percorso scritto nel codice: lo costruisce dalla configurazione pubblicata.

### 4.1 Chi configura e cosa vede lo studio

- **Il team AF.** Configura da un editor nel gestionale riservato al ruolo super admin AF (utenti della piattaforma, non dello studio), dopo aver scelto lo studio. L'editor ha a sinistra i controlli raggruppati come lo schema (Aspetto, Testi, Passi, Scelte, Chi può prenotare, Verifica, Senza verifica, Dati, Limiti, Regole, Protezioni, Comunicazioni, Catalogo) e a destra l'anteprima dal vivo della pagina del paziente, con i pulsanti 390 e 1280 pixel. I passi si riordinano trascinandoli o con i bottoni "Sposta su" e "Sposta giù" (usabili da tastiera), ognuno con il suo interruttore; i passi che non si possono spegnere hanno l'interruttore disattivato con il motivo.
- **Lo studio.** Nella pagina "Prenotazione online" del gestionale (tavola A01, sezione 9) vede un riepilogo in sola lettura della configurazione pubblicata, con la frase "Per cambiare il percorso di prenotazione scrivi all'assistenza di Ambulatorio Facile". Continua a gestire solo le cose operative: sospendere le prenotazioni, confermare o rifiutare le richieste, bloccare un numero, creare gli inviti, segnare le assenze, scegliere chi riceve gli avvisi.
- **Il paziente.** Vede solo la configurazione pubblicata, mai la bozza.

### 4.2 Bozza, pubblicazione, cronologia e anteprima

- Per ogni studio esistono al massimo una bozza e una configurazione pubblicata. Ogni modifica nell'editor salva la bozza (salvataggio automatico dopo un secondo di pausa, più il bottone "Salva bozza"). La pagina pubblica non cambia finché non si preme "Pubblica".
- "Pubblica" valida la bozza con tutte le regole della sezione 4.7 contro i dati veri dello studio (catalogo, numero WhatsApp collegato, modulo SMS). Se c'è un errore non pubblica e mostra l'elenco degli errori accanto ai controlli coinvolti. Se ci sono solo avvisi chiede conferma con "Pubblica comunque". Alla pubblicazione la bozza diventa la revisione successiva (1, 2, 3...), la precedente passa in archivio e la cache del catalogo si svuota.
- **Cronologia.** L'elenco delle revisioni pubblicate con data, autore e una nota facoltativa scritta da chi pubblica ("Tolto il primo disponibile su richiesta dello studio"). Ogni revisione si apre in sola lettura, con le differenze rispetto alla pubblicata.
- **Ripristino.** "Ripristina questa versione" copia quella revisione nella bozza, dopo conferma perché sovrascrive la bozza in corso. Per renderla pubblica serve "Pubblica", come per ogni altra modifica: il ripristino non tocca mai da solo la pagina pubblica.
- **Due persone sulla stessa bozza.** Il salvataggio manda l'`etag` della bozza letta (header `If-Match`); se nel frattempo qualcun altro l'ha cambiata, risposta 409 `bozza_cambiata` e l'editor propone di ricaricare.
- **Anteprima.** L'editor mostra la pagina vera in un iframe con l'URL `/prenota/{tenant_key}?anteprima=bozza&firma={firma}`. La firma è un HMAC-SHA256 di tenant, id dell'utente AF e scadenza (1 ora), con la chiave `PATIENT_BOOKING_PREVIEW_KEY`. Con una firma valida il catalogo restituisce la bozza invece della pubblicata, la pagina mostra il banner "Anteprima della bozza: le prenotazioni fatte qui non vengono salvate" e tutte le API di scrittura rispondono 403 `anteprima` senza inviare nulla: verifica ed esito si simulano nella pagina, come nel prototipo. Senza firma, o con firma scaduta, il parametro si ignora e si vede la pubblicata. Risposte con `X-Robots-Tag: noindex` e `Cache-Control: no-store`. L'iframe si ricarica a ogni salvataggio della bozza.

### 4.3 Lo schema (versione 1)

Il campo `versione` è la versione dello schema, non della configurazione: le revisioni pubblicate si contano a parte (sezione 4.9). Le sezioni da `aspetto` a `limiti` sono quelle decise il 22 ottobre. `regole`, `protezioni`, `comunicazioni` e `catalogo` raccolgono le impostazioni che la prima versione di questa guida metteva nella config di `BookingConfigService` con chiavi inglesi (`access_mode`, `max_active_per_phone`, `verify_channels`...): quelle chiavi non esistono più, la tabella della sezione 5.4 dà la corrispondenza. Il JSON qui sotto è la configurazione predefinita, con il catalogo dello studio d'esempio.

```json
{
  "versione": 1,
  "aspetto": {
    "logo_url": null,
    "logo_alt": "Poliambulatorio Santa Chiara",
    "mostra_nome_con_logo": true
  },
  "testi": {
    "benvenuto_titolo": null,
    "benvenuto_sottotitolo": null,
    "nota_riepilogo": null
  },
  "passi": [
    { "id": "prestazione",    "attivo": true },
    { "id": "professionista", "attivo": true },
    { "id": "sede",           "attivo": true },
    { "id": "orario",         "attivo": true },
    { "id": "perchi",         "attivo": true },
    { "id": "verifica",       "attivo": true },
    { "id": "dati",           "attivo": true },
    { "id": "riepilogo",      "attivo": true }
  ],
  "scelte": {
    "primo_disponibile": true,
    "salta_professionista_se_unico": true,
    "salta_sede_se_unica": true,
    "prestazione_predefinita": null,
    "professionista_predefinito": null
  },
  "accesso": { "modalita": "approvazione", "ore_scadenza_richieste": 24 },
  "verifica": {
    "richiesta": true,
    "metodi": ["whatsapp", "email"],
    "sms_tetto_giornaliero": 50
  },
  "senza_verifica": { "recapito": "almeno_uno" },
  "dati": {
    "cellulare": "obbligatorio",
    "email": "facoltativo",
    "nascita": "nascosto",
    "codice_fiscale": "nascosto",
    "note": "facoltativo"
  },
  "limiti": { "attive_per_recapito": 2, "stessa_prestazione": 1, "assenze_per_approvazione": 2 },
  "regole": {
    "preavviso_minimo_min": 120,
    "giorni_prenotabili": 90,
    "preavviso_modifica_ore": 24,
    "spostamento_consentito": true,
    "minuti_riserva": 10
  },
  "protezioni": {
    "verifiche_per_numero_ora": 3,
    "verifiche_per_dispositivo_ora": 5,
    "email_per_indirizzo_ora": 3,
    "email_tetto_giornaliero": 100,
    "prefissi_esteri": [],
    "senza_verifica_per_dispositivo_giorno": 3,
    "senza_verifica_per_ip_giorno": 5,
    "soglia_anomalia_ora": 10,
    "giorni_dispositivo": 30
  },
  "comunicazioni": {
    "nome_nei_messaggi": "Poliambulatorio Santa Chiara",
    "mostra_prestazione_nei_messaggi": false,
    "contatti": { "telefono": "0571 123 456", "whatsapp": "333 123 4567", "email": "segreteria@poliambulatoriosantachiara.it" },
    "privacy_url": "https://poliambulatoriosantachiara.it/privacy"
  },
  "catalogo": {
    "prestazioni": [
      { "id_tipo_visita": 12, "online": true, "nome_pubblico": "Visita dermatologica", "descrizione": null, "preparazione": null, "prezzo": "100 €", "modalita": null }
    ],
    "professionisti": [
      { "id_dot": 7, "online": true, "prestazioni": [12], "sedi": [1, 2], "modalita": null }
    ]
  }
}
```

Campo per campo:

| Campo | Valori ammessi | Predefinito | Significato |
|---|---|---|---|
| `versione` | `1` | `1` | Versione dello schema. Il server rifiuta di pubblicare una versione che non conosce. |
| `aspetto.logo_url` | URL relativo del gestionale o `null` | `null` | Logo dello studio. Lo imposta il server dopo il caricamento (sezione 4.8), mai scritto a mano. Con `null` la pagina mostra il monogramma con le iniziali e il nome dello studio, come oggi. |
| `aspetto.logo_alt` | testo, massimo 80 caratteri | nome dello studio | Testo alternativo del logo. |
| `aspetto.mostra_nome_con_logo` | `true`, `false` | `true` | Mostra il nome dello studio accanto al logo. Da spegnere quando il logo contiene già il nome. |
| `testi.benvenuto_titolo` | testo, massimo 60 caratteri, o `null` | `null` | Titolo della prima schermata. Con `null` si usa il testo predefinito del prototipo. |
| `testi.benvenuto_sottotitolo` | testo, massimo 140 caratteri, o `null` | `null` | Sottotitolo della prima schermata. Con `null` il predefinito. |
| `testi.nota_riepilogo` | testo, massimo 280 caratteri, o `null` | `null` | Nota nel riepilogo, sopra il bottone, per esempio "Porta con te la tessera sanitaria e gli esami precedenti.". Testo semplice, mostrato con escape: niente HTML, niente link. |
| `passi[]` | gli 8 id, ciascuno una volta | ordine qui sopra | L'ordine dell'array è l'ordine delle schermate. `attivo: false` toglie la schermata. Regole nella sezione 4.4. |
| `scelte.primo_disponibile` | `true`, `false` | `true` | Mostra "Il primo disponibile" nella schermata Professionista (sezione 4.5). |
| `scelte.salta_professionista_se_unico` | `true`, `false` | `true` | Un solo professionista possibile: si seleziona da solo e la schermata non si mostra. |
| `scelte.salta_sede_se_unica` | `true`, `false` | `true` | Una sola sede possibile: idem. |
| `scelte.prestazione_predefinita` | `id_tipo_visita` del catalogo o `null` | `null` | Obbligatoria se il passo `prestazione` è spento. Ignorata se il passo è attivo. |
| `scelte.professionista_predefinito` | `id_dot` del catalogo o `null` | `null` | Usato se il passo `professionista` è spento; se è `null` decide il primo posto libero. Ignorato se il passo è attivo. |
| `accesso.modalita` | `approvazione`, `aperta`, `invito`, `archivio` | `approvazione` | Chi può prenotare (sezione 5.3). Si può sovrascrivere per prestazione o professionista in `catalogo`. |
| `accesso.ore_scadenza_richieste` | da 1 a 168 | 24 | Dopo quante ore una richiesta da confermare scade e libera l'orario. |
| `verifica.richiesta` | `true`, `false` | `true` | Con `false` si prenota senza verifica (sezione 5.10). Va sempre d'accordo con il passo `verifica`: l'editor li cambia insieme. |
| `verifica.metodi` | sottoinsieme ordinato di `whatsapp`, `email`, `sms` | `["whatsapp", "email"]` | Metodi accesi, nell'ordine in cui il paziente li vede; il primo ha il badge "Consigliato". Servono anche per "Le mie prenotazioni", anche quando `richiesta` è `false`. |
| `verifica.sms_tetto_giornaliero` | da 1 a 1000 | 50 | Massimo di SMS di codice al giorno per lo studio. Raggiunto il tetto, l'SMS sparisce fino al giorno dopo (sezione 5.4). |
| `senza_verifica.recapito` | `almeno_uno`, `cellulare`, `email`, `facoltativo` | `almeno_uno` | Recapito richiesto quando non c'è la verifica (sezione 5.10). Ignorato con la verifica attiva. |
| `dati.cellulare` | `obbligatorio`, `facoltativo`, `nascosto` | `obbligatorio` | Con la verifica attiva il cellulare si chiede sempre nel passo Verifica ed è sempre obbligatorio, con qualunque metodo (serve per riconoscere chi è già paziente e per i promemoria): questo valore è ignorato. Conta solo senza verifica, insieme a `senza_verifica.recapito` (sezione 5.10). |
| `dati.email` | come sopra | `facoltativo` | Se il paziente ha verificato l'email, compare già compilata. |
| `dati.nascita` | come sopra | `nascosto` | Data di nascita, con l'etichetta "richiesto dallo studio" quando è obbligatoria. |
| `dati.codice_fiscale` | come sopra | `nascosto` | Idem. Validazione del formato, non del controllo con l'anagrafe. |
| `dati.note` | come sopra | `facoltativo` | Nota al professionista, con l'avviso sulla salute. Meglio non renderla obbligatoria: spingerebbe a scrivere dati sulla salute. |
| `limiti.attive_per_recapito` | da 1 a 10 | 2 | Prenotazioni future attive per identità verificata o, senza verifica, per recapito indicato. |
| `limiti.stessa_prestazione` | da 1 a 5 | 1 | Prenotazioni future attive per la stessa prestazione. |
| `limiti.assenze_per_approvazione` | da 1 a 10 | 2 | Assenze senza disdetta dopo cui il paziente passa ad approvazione obbligatoria. |
| `regole.preavviso_minimo_min` | da 0 a 10080 | 120 | Minuti minimi tra adesso e l'orario prenotabile. |
| `regole.giorni_prenotabili` | da 1 a 365 | 90 | Finestra massima di prenotazione. |
| `regole.preavviso_modifica_ore` | da 0 a 168 | 24 | Fino a quante ore prima il paziente sposta o annulla da solo. |
| `regole.spostamento_consentito` | `true`, `false` | `true` | Con `false` il paziente può solo annullare. |
| `regole.minuti_riserva` | da 5 a 15 | 10 | Durata della riserva dopo la verifica. Senza verifica non c'è riserva. |
| `protezioni.*` | interi positivi | valori sopra | Limiti anti abuso della sezione 5.4. `prefissi_esteri` è l'elenco dei prefissi internazionali ammessi oltre ai cellulari italiani (per esempio `["+41"]`); per l'SMS valgono sempre e solo i cellulari italiani. |
| `comunicazioni.nome_nei_messaggi` | testo, massimo 24 caratteri | nome dello studio | Nome breve per gli oggetti delle email e per gli SMS (sezione 7.3, punto 11). |
| `comunicazioni.mostra_prestazione_nei_messaggi` | `true`, `false` | `false` | La prestazione nel corpo dei messaggi al paziente (sezione 7.3, punto 2). |
| `comunicazioni.contatti` | telefono, WhatsApp, email | dati dello studio | Contatti mostrati al paziente. Mai quelli personali dei professionisti. |
| `comunicazioni.privacy_url` | URL https | nessuno, obbligatorio | Informativa privacy dello studio, linkata nella casella del passo dati e nelle email. |
| `catalogo.prestazioni[]` | `id_tipo_visita` di `dap44` | nessuna online | Prestazioni prenotabili online, con nome pubblico, descrizione breve, preparazione, prezzo indicativo facoltativo e `modalita` che sovrascrive `accesso.modalita`. La durata si legge da `dap44`, con un avviso se non entra negli slot del professionista. |
| `catalogo.professionisti[]` | `id_dot` | nessuno online | Professionisti online, con le prestazioni che fanno online, le sedi e la `modalita` facoltativa. |

Fuori dal JSON restano solo le cose operative, che non passano dalla bozza perché devono valere subito: l'interruttore "Sospendi le prenotazioni online", gli indirizzi che ricevono gli avvisi, i numeri bloccati, gli inviti e le assenze (sezioni 5.9, 6.2 e 9).

### 4.4 Ordine dei passi e passi spenti

Vincoli d'ordine, gli unici (l'editor li fa rispettare, il server li rivalida a ogni pubblicazione e a ogni prenotazione):

1. `riepilogo` è sempre l'ultimo ed è sempre attivo.
2. `orario` viene dopo `prestazione` (serve la durata) e dopo `professionista` e `sede` quando sono attivi.
3. `dati` viene dopo `verifica` quando la verifica è attiva (chi è già paziente trova i dati compilati).

Tutto il resto è libero. Per esempio il professionista prima della prestazione (la lista delle prestazioni si filtra per quel professionista), la sede per prima (professionisti e prestazioni filtrati per sede), "per chi" prima o dopo verifica e dati, l'orario prima o dopo la verifica.

Cosa succede quando un passo è spento:

| Passo spento | Comportamento |
|---|---|
| `prestazione` | Si usa `scelte.prestazione_predefinita`, obbligatoria in questo caso. Tipico di uno studio con una sola prestazione prenotabile. |
| `professionista` | Lo sceglie il sistema con il primo posto libero, come "Il primo disponibile", oppure si usa `scelte.professionista_predefinito` se è impostato. |
| `sede` | Si usa la sede del primo posto libero, o l'unica sede. |
| `perchi` | La visita è sempre per chi prenota (`per.tipo = "me"`). |
| `verifica` | Equivale a `verifica.richiesta: false`: prenotazione senza verifica (sezione 5.10). L'editor tiene allineati interruttore del passo e campo. |
| `orario`, `dati`, `riepilogo` | Non si possono spegnere. |

La riserva dell'orario nasce sempre dopo la verifica: se l'orario è prima della verifica nasce subito dopo la verifica, se è dopo nasce al "Continua" del passo orario. In entrambi i casi toccare un orario prima della verifica non blocca nulla.

### 4.5 Il primo disponibile e la selezione automatica

- **Il primo disponibile.** `scelte.primo_disponibile: false` toglie la voce "Il primo disponibile" dalla schermata Professionista: il paziente deve scegliere una persona. Se è `false` e il passo professionista è spento, serve `professionista_predefinito` oppure ogni prestazione del catalogo deve avere un solo professionista, altrimenti l'editor dà errore (sezione 4.7). Con "Il primo disponibile" scelto, gli orari sono quelli di tutti i professionisti che fanno quella prestazione in quella sede e il professionista si ricava dallo slot scelto.
- **Un solo professionista possibile** (per la prestazione e la sede già scelte) e `salta_professionista_se_unico: true`: il professionista si seleziona da solo e la schermata non si mostra. Vale anche quando "Il primo disponibile" è acceso: con una sola persona la voce non serve.
- **Una sola sede possibile** e `salta_sede_se_unica: true`: idem per la sede.
- **Una sola prestazione prenotabile**: si seleziona da sola, sempre (non c'è un interruttore: una lista di una voce non serve).
- **La scelta automatica resta visibile**: nella riga di riepilogo in alto e nel riepilogo finale ("Con Dott.ssa Elena Conti", "Sede di Empoli"), senza il link "Modifica", perché non c'è niente da cambiare. La barra di avanzamento conta solo i passi mostrati davvero.
- **Link profondo e invito.** Un parametro valido del link profondo, o un vincolo dell'invito, preseleziona la scelta e salta la schermata; nel riepilogo la riga ha "Modifica" se esistono altre opzioni (con l'invito, mai: l'invito vincola).

### 4.6 Come si costruisce la sequenza dei passi

La pagina costruisce la sequenza dei passi dalla configurazione pubblicata e dal catalogo, e la ricalcola dopo ogni scelta. Il server non si fida della sequenza del client: la ricostruisce con la stessa funzione e rivalida ogni scelta a `POST /riserva` e a `POST /prenotazioni`. La logica esiste due volte, in JavaScript (pagina) e in PHP (`BookingConfigService::costruisciSequenza`), e le due versioni girano sugli stessi casi di prova (sezione 4.12).

```
// Lato client e lato server, stessa logica.
// config: configurazione pubblicata e normalizzata (sezione 4.11)
// catalogo: prestazioni, professionisti e sedi online
// scelte: { prestazione, professionista, sede, orario, per } fatte dal paziente nella pagina
// prescelte: parametri validi del link profondo o vincoli dell'invito
// contesto: { gia_verificato: dispositivo riconosciuto con sessione valida }

funzione costruisciSequenza(config, catalogo, scelte, prescelte, contesto):
  sequenza = []
  per ogni passo in config.passi, nell'ordine dell'array:
    se non passo.attivo:
      applicaPassoSpento(passo.id, config, catalogo, scelte)    // tabella 4.4
      continua

    se passo.id in ["prestazione", "professionista", "sede"]:
      opzioni = opzioniPossibili(passo.id, catalogo, scelte)     // filtrate dalle scelte già fatte, in qualunque ordine
      se opzioni è vuota:
        restituisci errore("nessuna_opzione", passo.id)          // la pagina mostra il telefono dello studio
      se prescelte[passo.id] è in opzioni:
        scelte[passo.id] = prescelte[passo.id]
        sequenza.aggiungi({ id: passo.id, mostrato: falso, motivo: "dal_link", modificabile: lunghezza(opzioni) > 1 e non invito })
        continua
      se lunghezza(opzioni) == 1 e saltaSeUnico(passo.id, config):
        scelte[passo.id] = opzioni[0]
        sequenza.aggiungi({ id: passo.id, mostrato: falso, motivo: "unica_scelta", modificabile: falso })
        continua
      se scelte[passo.id] esiste e non è in opzioni e non è "primo":
        cancella scelte[passo.id]                                // scelta diventata impossibile dopo un ripensamento

    se passo.id == "verifica" e contesto.gia_verificato:
      sequenza.aggiungi({ id: "verifica", mostrato: falso, motivo: "gia_verificato" })
      continua

    sequenza.aggiungi({ id: passo.id, mostrato: vero })
  restituisci sequenza

funzione saltaSeUnico(id, config):
  se id == "prestazione":    restituisci vero
  se id == "professionista": restituisci config.scelte.salta_professionista_se_unico
  se id == "sede":           restituisci config.scelte.salta_sede_se_unica

funzione opzioniPossibili(id, catalogo, scelte):
  // "Il primo disponibile" non conta come opzione: la pagina lo aggiunge in testa
  // quando config.scelte.primo_disponibile è vero e le opzioni sono più di una
  prestazione:    prestazioni online fatte dal professionista scelto (se c'è e non è "primo") nella sede scelta (se c'è)
  professionista: professionisti online che fanno la prestazione scelta (se c'è) nella sede scelta (se c'è)
  sede:           sedi del professionista scelto o, con "primo" o senza scelta, di chi fa la prestazione scelta
```

Regole d'uso:

- Quando il paziente torna indietro e cambia una scelta, si ricalcola la sequenza: le scelte dei passi successivi che non sono più tra le opzioni si cancellano, quelle automatiche si ricalcolano.
- La barra di avanzamento e il numero "Passo 3 di 5" contano solo gli elementi con `mostrato: vero`.
- Il tasto indietro del browser salta gli elementi con `mostrato: falso`.

Rivalidazione lato server, a `POST /riserva` (fino all'orario) e a `POST /prenotazioni` (tutto):

```
funzione validaRichiesta(richiesta, config, catalogo, sessione):
  scelte = richiesta.scelte                                   // prestazione, professionista, sede, slot, per
  per ogni passo spento: applicaPassoSpento(...)              // il valore mandato dal client si ignora
  sequenza = costruisciSequenza(config, catalogo, scelte, prescelteFirmate(richiesta), contesto(sessione))
  se sequenza è un errore: 409 configurazione_cambiata con passo
  per ogni id in ["prestazione", "professionista", "sede"]:
    il valore deve stare in opzioniPossibili(id, catalogo, scelte), oppure essere "primo" se primo_disponibile è vero
    altrimenti: 409 configurazione_cambiata con passo = id
  slot = verificaFirmaSlot(richiesta.slot)                     // sezione 6.3
  lo slot deve essere della prestazione e della sede scelte, e del professionista scelto
    (con "primo" o con il passo spento senza predefinito: di un professionista ammesso, che diventa quello della prenotazione)
  se config.verifica.richiesta: serve la sessione verificata, altrimenti 401 sessione_assente
  altrimenti: token Turnstile con action "prenota_conferma" e limiti della sezione 5.10
  se il passo perchi è spento: per.tipo deve essere "me"
  dati: ogni campo obbligatorio presente e valido, ogni campo nascosto scartato,
        recapito secondo senza_verifica.recapito quando non c'è verifica
```

La richiesta porta anche `config_revisione` (la revisione letta dal catalogo). Il server valida sempre contro la revisione pubblicata adesso: se le scelte valgono ancora, accetta anche se la revisione è cambiata nel frattempo; se non valgono, risponde 409 `configurazione_cambiata` con il passo da cui ripartire e la pagina ricarica il catalogo.

### 4.7 Validazioni dell'editor

Gli errori bloccano "Pubblica", gli avvisi chiedono solo conferma. La bozza si salva comunque, anche con errori. Ogni errore ha un codice per il codice e un messaggio per il team AF, mostrato accanto al controllo coinvolto.

| Codice | Quando | Messaggio | Effetto |
|---|---|---|---|
| `riepilogo_non_ultimo` | `riepilogo` non è l'ultimo o è spento | "Il riepilogo deve essere l'ultimo passo e restare sempre attivo." | Blocca |
| `orario_troppo_presto` | `orario` prima di `prestazione`, o prima di `professionista` o `sede` attivi | "Il passo Orario deve venire dopo Prestazione, Professionista e Sede (quelli attivi): serve sapere durata, persona e sede." | Blocca |
| `dati_prima_di_verifica` | `dati` prima di `verifica` attiva | "Il passo I tuoi dati deve venire dopo la verifica: così chi è già paziente trova i dati compilati." | Blocca |
| `passo_non_spegnibile` | `orario`, `dati` o `riepilogo` spenti | "Orario, I tuoi dati e Riepilogo non si possono spegnere." | Blocca |
| `passi_non_validi` | id mancante, ripetuto o sconosciuto | "Ogni passo deve comparire una sola volta." | Blocca |
| `prestazione_predefinita_mancante` | passo `prestazione` spento senza `prestazione_predefinita` | "Hai spento il passo Prestazione: scegli la prestazione predefinita." | Blocca |
| `professionista_non_determinabile` | `primo_disponibile` falso, passo `professionista` spento, nessun predefinito e almeno una prestazione con più professionisti | "Hai tolto Il primo disponibile e spento il passo Professionista: scegli un professionista predefinito, perché {prestazione} ha più professionisti." | Blocca |
| `metodi_verifica_vuoti` | `verifica.richiesta` vero e `metodi` vuota | "La verifica è attiva ma nessun metodo è acceso: accendi almeno WhatsApp, email o SMS." | Blocca |
| `whatsapp_non_collegato` | `whatsapp` tra i metodi senza numero WhatsApp collegato al gestionale | "Il metodo WhatsApp richiede il numero WhatsApp dello studio collegato al gestionale. Collegalo o spegni il metodo." | Blocca |
| `sms_non_attivo` | `sms` tra i metodi senza modulo SMS attivo per lo studio | "Il metodo SMS richiede il modulo SMS dello studio attivo, lo stesso dei promemoria. Attivalo o spegni il metodo." | Blocca (aggiunto per coerenza con WhatsApp) |
| `archivio_senza_verifica` | `accesso.modalita` (o una `modalita` del catalogo) è `archivio` e la verifica è spenta | "Solo pazienti in archivio non funziona senza verifica: non possiamo sapere chi sta prenotando. Scegli un'altra modalità o accendi la verifica." | Blocca |
| `recapito_nascosto` | senza verifica, `senza_verifica.recapito` chiede un campo che in `dati` è nascosto | "Hai chiesto il {campo} come recapito, ma il campo è nascosto nei dati. Mostralo o cambia il recapito richiesto." | Blocca |
| `riferimento_non_valido` | predefinito, prestazione o professionista del catalogo che non esiste o non è online | "{campo} punta a una prestazione o a un professionista che non è prenotabile online." | Blocca |
| `catalogo_vuoto` | nessuna prestazione online | "Nessuna prestazione è prenotabile online: la pagina mostrerebbe solo il telefono dello studio." | Blocca |
| `privacy_mancante` | `comunicazioni.privacy_url` vuoto | "Manca il link all'informativa privacy dello studio." | Blocca |
| `recapito_facoltativo` | senza verifica e `recapito: facoltativo` | "Senza un recapito obbligatorio lo studio potrebbe non riuscire ad avvisare il paziente se l'appuntamento cambia." | Avviso |
| `aperta_senza_verifica` | senza verifica e modalità `aperta` | "Chiunque può occupare l'agenda senza essere verificato." (avviso arancione) | Avviso |
| `sms_a_consumo` | `sms` tra i metodi | "Gli SMS di verifica si pagano a consumo sul credito dello studio, come i promemoria. Tetto attuale: {n} al giorno." | Avviso |
| `predefinito_ignorato` | predefinito impostato con il passo attivo | "Il passo è attivo: il valore predefinito non viene usato." | Avviso |
| `durata_non_compatibile` | durata della prestazione che non entra negli slot di un professionista | "{prestazione} dura {n} minuti e non entra negli slot di {professionista}." | Avviso |

Gli stessi controlli girano a ogni lettura della configurazione pubblicata (sezione 4.11): se un dato dello studio cambia dopo la pubblicazione (WhatsApp scollegato, professionista tolto), la pagina si adatta e l'editor mostra un banner rosso sulla revisione pubblicata.

### 4.8 Logo dello studio

- **Caricamento.** Dall'editor, in "Aspetto". Tipi ammessi: PNG, JPG, SVG, massimo 1 MB, riconosciuti dal contenuto (`finfo`) e non dall'estensione. Lato massimo dell'originale 4000 px. Idealmente orizzontale. Niente GIF animate.
- **SVG.** Si ripulisce prima di salvarlo: niente `script`, `foreignObject`, attributi `on*`, riferimenti esterni (`href` verso altri domini, `@import`, `url(...)` remoti). Se la pulizia non è possibile, si rifiuta con "Questo SVG contiene elementi non ammessi: caricalo in PNG.".
- **Ridimensionamento.** Il server genera due PNG: uno per la pagina, alto 96 px (il doppio dei 48 px mostrati su desktop, per gli schermi ad alta densità), e uno per le email, largo al massimo 240 px su fondo trasparente. Si conserva anche l'originale, per rigenerare le varianti. Proporzioni sempre rispettate, mai ritagli.
- **Uso.** Pagina: alto 40 px su mobile e 48 px su desktop, con `aspetto.logo_alt`. Email: nel blocco del titolare del footer (sezione 7.3, punto 3), come URL assoluto https, mai data URI. PWA: le icone si generano dal logo (sezione 8).
- **URL.** `GET /media/studio-logo/{tenant_key}/{hash}.png` per la pagina e `.../{hash}-email.png` per le email, con `Cache-Control: public, max-age=31536000, immutable`: un logo nuovo ha un hash nuovo, quindi nessuna cache vecchia. Il caricamento aggiorna solo la bozza: la pagina pubblica cambia logo alla pubblicazione.
- **Senza logo.** `logo_url: null`: monogramma con le iniziali (quadrato arrotondato navy `#08364F` con le iniziali bianche) e nome dello studio, come oggi.

### 4.9 Tabelle (DDL indicativo, MySQL 8)

I numeri `dap53` e `dap54` sono indicativi, come quelli della sezione 6.2: usa i primi liberi. Stanno nel database di ogni studio. Le migrazioni devono essere idempotenti (`CREATE TABLE IF NOT EXISTS`).

```sql
-- Database dello studio: configurazione del percorso di prenotazione, una riga per bozza o revisione
CREATE TABLE IF NOT EXISTS dap53_prenotazione_config (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  stato               ENUM('bozza','pubblicata','archiviata') NOT NULL,
  revisione           INT UNSIGNED NULL,                    -- assegnata alla pubblicazione: 1, 2, 3...; null per la bozza
  schema_versione     TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- il campo "versione" del JSON
  config_json         JSON NOT NULL,
  id_logo             INT UNSIGNED NULL,                    -- dap54_prenotazione_logo
  etag                CHAR(32) NOT NULL,                    -- cambia a ogni salvataggio della bozza (If-Match)
  nota                VARCHAR(255) NULL,                    -- nota di chi pubblica, mai dati di pazienti
  ripristinata_da     INT UNSIGNED NULL,                    -- revisione da cui nasce la bozza, se ripristinata
  creata_da           INT NOT NULL,                         -- id dell'utente super admin AF (database della piattaforma)
  pubblicata_da       INT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  pubblicata_at       DATETIME NULL,
  una_bozza           TINYINT GENERATED ALWAYS AS (IF(stato = 'bozza', 1, NULL)) STORED,
  una_pubblicata      TINYINT GENERATED ALWAYS AS (IF(stato = 'pubblicata', 1, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY ux_dap53_revisione (revisione),
  UNIQUE KEY ux_dap53_bozza (una_bozza),                    -- al massimo una bozza
  UNIQUE KEY ux_dap53_pubblicata (una_pubblicata)           -- al massimo una pubblicata
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Database dello studio: loghi caricati (il filesystem del container non è un posto sicuro)
CREATE TABLE IF NOT EXISTS dap54_prenotazione_logo (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hash            CHAR(64) NOT NULL,                        -- SHA-256 del PNG della pagina, usato nell'URL
  mime_originale  VARCHAR(40) NOT NULL,                     -- image/png, image/jpeg, image/svg+xml
  originale       MEDIUMBLOB NOT NULL,                      -- massimo 1 MB, SVG già ripulito
  png_pagina      MEDIUMBLOB NOT NULL,                      -- alto 96 px
  png_email       MEDIUMBLOB NOT NULL,                      -- largo al massimo 240 px
  larghezza       SMALLINT UNSIGNED NOT NULL,               -- del PNG della pagina
  altezza         SMALLINT UNSIGNED NOT NULL,
  caricato_da     INT NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_dap54_hash (hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- La pubblicazione è una transazione: la pubblicata passa ad `archiviata`, la bozza passa a `pubblicata` con `revisione` uguale al massimo più 1, si aggiorna `revisione_pubblicata` in `platform_patient_booking_configs` (sezione 6.2) per invalidare le cache, si registra l'evento.
- I loghi non usati da nessuna revisione negli ultimi 12 mesi si possono cancellare con il job della sezione 5.8.
- Nessun `ALTER` a mano in produzione: tutto passa dalle migrazioni del gestionale.

### 4.10 Endpoint dell'editor

Prefisso `af/prenotazione-online/{id_tenant}`, nell'area del gestionale riservata al team AF (cerca dove vive oggi la gestione dei tenant, accanto a `TenantCatalogService`). Tutti protetti dal filtro di ruolo `afsuperadmin` (utente della piattaforma con ruolo super admin AF: gli utenti degli studi ricevono 403) e dal filtro CSRF dell'area amministrativa. Ogni scrittura finisce nel registro eventi con l'id dell'utente AF.

- `GET config`: bozza (se c'è) con `etag`, pubblicata con `revisione`, errori e avvisi correnti di entrambe, dati di contesto per l'editor (prestazioni e professionisti dello studio, sedi, WhatsApp collegato sì o no, modulo SMS attivo sì o no, SMS di verifica inviati oggi).
- `PUT config/bozza`: corpo `{"config": {...}}`, header `If-Match: {etag}`. Salva la bozza (la crea dalla pubblicata, o dalla predefinita, se non c'è). Risposta 200 `{"etag": "...", "errori": [...], "avvisi": [...]}`. Errori: 409 `bozza_cambiata`, 422 `json_non_valido` (JSON malformato o tipi sbagliati: in questo caso non si salva).
- `DELETE config/bozza`: scarta la bozza.
- `POST config/pubblica`: corpo `{"nota": "Tolto il primo disponibile su richiesta dello studio", "conferma_avvisi": true}`. Risposta 200 `{"revisione": 8, "pubblicata_at": "..."}`. Errori: 422 `configurazione_non_valida` con l'elenco, 409 `conferma_avvisi` se ci sono avvisi non confermati, 404 `bozza_assente`.
- `GET config/versioni`: elenco delle revisioni (`revisione`, `pubblicata_at`, autore, `nota`). `GET config/versioni/{revisione}`: il JSON di quella revisione, per la vista in sola lettura e le differenze.
- `POST config/ripristina`: corpo `{"revisione": 5}`. Copia la revisione nella bozza. Non pubblica.
- `POST config/logo`: `multipart/form-data` con il file. Risposta 200 `{"logo_url": "/media/studio-logo/santa-chiara/3f9a1c....png", "larghezza": 312, "altezza": 96, "avvisi": []}`; aggiorna la bozza. Errori: 413 `file_troppo_grande`, 415 `tipo_non_ammesso`, 422 `svg_non_ammesso`.
- `POST config/anteprima`: risposta `{"url": "/prenota/santa-chiara?anteprima=bozza&firma=...", "scade_at": "..."}`.

Esempio di risposta con errori:

```json
{
  "errore": "configurazione_non_valida",
  "messaggio": "La configurazione ha 1 errore da correggere.",
  "errori": [
    {"codice": "prestazione_predefinita_mancante", "campo": "scelte.prestazione_predefinita", "messaggio": "Hai spento il passo Prestazione: scegli la prestazione predefinita."}
  ],
  "avvisi": [
    {"codice": "aperta_senza_verifica", "campo": "accesso.modalita", "messaggio": "Chiunque può occupare l'agenda senza essere verificato."}
  ]
}
```

Lo studio non ha endpoint per cambiare la configurazione: legge il riepilogo con `GET spazio/prenotazione-online/percorso` (sezione 6.4).

### 4.11 Catalogo pubblico, normalizzazione e compatibilità

- **Solo la pubblicata.** `GET /api/prenota/{tenant_key}/catalogo` restituisce la configurazione PUBBLICATA, già normalizzata e validata, e la revisione. Mai la bozza, tranne con la firma di anteprima valida della sezione 4.2. Cache lato server per `(tenant, revisione)`, svuotata alla pubblicazione.
- **Solo i campi che servono alla pagina.** Il catalogo espone `aspetto`, `testi`, `passi`, `scelte`, `verifica.richiesta`, `verifica.metodi` (già filtrati: WhatsApp tolto se non collegato, SMS tolto se il tetto del giorno è raggiunto o il credito è finito), `senza_verifica`, `dati`, le regole pubbliche (`preavviso_modifica_ore`, `spostamento_consentito`) e il catalogo con i soli campi pubblici. Non espone mai `limiti`, `protezioni`, `sms_tetto_giornaliero`, la `modalita` delle singole prestazioni né i dati interni dell'editor.
- **Normalizzazione** (`BookingConfigService::normalizza`, la stessa in lettura e prima di salvare): le chiavi mancanti prendono il valore predefinito della sezione 4.3; le chiavi sconosciute si ignorano e si registrano; un passo mancante si aggiunge spento, tranne `orario`, `dati` e `riepilogo`, che si aggiungono attivi nella posizione predefinita; `verifica.richiesta` diventa falso se il passo `verifica` è spento, e viceversa.
- **Configurazione assente.** Uno studio senza nessuna riga in `dap53` usa la configurazione predefinita della sezione 4.3. Poiché il catalogo predefinito è vuoto, la pagina risponde come uno studio non attivo finché il team AF non pubblica almeno una prestazione online.
- **Versioni dello schema.** Una futura `versione: 2` avrà la sua funzione di migrazione da 1 a 2, applicata in lettura: le revisioni vecchie restano leggibili e ripristinabili.
- **Pubblicata non più valida.** Se la configurazione pubblicata non passa più i controlli per un cambiamento dello studio, la pagina degrada senza errori tecnici: un metodo non disponibile sparisce; se non resta nessun metodo con la verifica richiesta, o nessuna prestazione, la pagina mostra il telefono dello studio. Il team AF vede il banner nell'editor.

### 4.12 Test della configurazione

- [ ] Casi condivisi in `tests/fixtures/prenotazione/sequenza-passi.json` (configurazione, catalogo, scelte, sequenza attesa) eseguiti sia dai test PHP sia da quelli JavaScript: stesso risultato, caso per caso.
- [ ] Ordine predefinito: 5 segmenti nella barra, nessun passo saltato con il catalogo di esempio completo.
- [ ] Professionista prima della prestazione: la lista delle prestazioni è filtrata per quel professionista.
- [ ] Sede per prima: professionisti e prestazioni filtrati per sede.
- [ ] Un solo professionista per la prestazione: schermata saltata, riga nel riepilogo senza "Modifica", barra con un segmento in meno.
- [ ] Una sola sede: idem. Una sola prestazione nel catalogo: idem.
- [ ] `primo_disponibile: false`: la voce non c'è; la richiesta con `professionista: "primo"` è rifiutata dal server (409).
- [ ] Passo `prestazione` spento: si usa la predefinita, anche se il client manda un'altra prestazione.
- [ ] Passo `perchi` spento: una richiesta con `per.tipo: "altro"` è rifiutata.
- [ ] Tutte le validazioni della tabella 4.7: ognuna blocca o avvisa come scritto, con il suo messaggio.
- [ ] Bozza modificata: la pagina pubblica non cambia finché non si pubblica; il catalogo non restituisce mai la bozza senza firma valida, nemmeno con `?anteprima=bozza`.
- [ ] Anteprima con firma valida: le API di scrittura rispondono 403 `anteprima`, nessuna email o SMS parte.
- [ ] Ripristino di una revisione: cambia la bozza, non la pubblicata.
- [ ] Due salvataggi concorrenti con lo stesso `etag`: il secondo riceve 409.
- [ ] Utente di uno studio sulle rotte `af/*`: 403.
- [ ] Logo PNG, JPG e SVG: varianti generate; SVG con `script`: rifiutato; file da 1,2 MB: 413.
- [ ] Pubblicazione mentre un paziente è a metà: se le sue scelte valgono ancora la prenotazione passa, se no 409 `configurazione_cambiata` e la pagina riparte dal passo indicato.

## 5. Anti abuso: il problema dei link pubblici

### 5.1 Il problema

Un link pubblico di prenotazione è raggiungibile da chiunque. Senza difese un bot, o una persona malintenzionata, può riempire l'agenda di prenotazioni finte, congelare gli orari con riserve mai confermate, oppure sommergere di messaggi di verifica numeri e indirizzi di persone che non c'entrano nulla. Il gestionale di oggi non ha nessuna di queste difese. Gli SMS meritano un'attenzione in più: ogni SMS costa allo studio, e i moduli pubblici che mandano SMS sono il bersaglio preferito di chi li usa per far spedire messaggi a pagamento a numeri a caso (frode da SMS pumping). Per questo la verifica via SMS è spenta di default e, quando lo studio la accende, ha le protezioni della sezione 5.6.

### 5.2 La soluzione a strati

Nessuno strato basta da solo; insieme rendono l'abuso costoso e inutile, senza peggiorare l'esperienza di Rosa.

1. **Nessuna scrittura senza identità verificata, quando la verifica è richiesta.** Finché la verifica non è completata non esiste nulla in agenda: niente lock, niente appuntamento, niente anagrafica. L'identità verificata è la registrazione: nessuna password, nessun account. Si verifica con WhatsApp (è il paziente a scrivere allo studio), con un codice via email o con un codice via SMS, tra i metodi che lo studio ha acceso. Se lo studio sceglie la prenotazione senza registrazione questo strato non c'è, e al suo posto valgono le protezioni della sezione 5.10.
2. **Riserva solo dopo la verifica.** Toccare un orario non lo blocca. Il lock su `dap14_agenda_lock` nasce solo dopo la verifica, dura 10 minuti e ce n'è al massimo uno attivo per identità verificata. Al momento della conferma c'è un controllo atomico finale; se l'orario non è più libero, si propongono i 3 più vicini. Senza verifica non c'è riserva: solo il controllo atomico alla conferma.
3. **Chi può prenotare: impostazione dello studio.** Quattro modalità, una per tutto lo studio, sovrascrivibile per prestazione o per professionista nel catalogo.
4. **Limiti per identità verificata, o per recapito senza verifica.** Pochi appuntamenti futuri attivi, niente doppioni, niente sovrapposizioni, approvazione obbligatoria dopo le assenze.
5. **Protezione delle verifiche.** Turnstile invisibile prima di avviare ogni verifica, campo trappola, limiti per numero, per indirizzo email, per dispositivo, per IP e per studio, domini email usa e getta bloccati, solo cellulari italiani di default. Il tetto giornaliero di email di verifica per studio è una difesa contro l'abuso, non un costo da contenere; il tetto giornaliero di SMS di verifica protegge anche il credito dello studio.
6. **Controllo dello studio.** Avviso di attività anomala, interruttore di sospensione, rifiuto e blocco di un numero con un tocco.

Nel copy per il paziente il tono resta gentile: la verifica si spiega come tutela ("per essere sicuri che sia proprio tu"), mai come sospetto. I messaggi di blocco non dicono mai "sei stato bloccato": rimandano al telefono dello studio.

### 5.3 Le quattro modalità di accesso

| Modalità | Valore in `accesso.modalita` | Paziente in archivio | Paziente nuovo | Senza verifica (sezione 5.10) |
|---|---|---|---|---|
| a. Solo pazienti già in archivio | `archivio` | Prenota, confermato subito | Vede "Per la prima visita chiama lo studio" con il telefono | Non ammessa: l'editor la blocca |
| b. Nuovi pazienti su approvazione (predefinita) | `approvazione` | Prenota, confermato subito | Crea una richiesta "da confermare" che occupa l'orario e scade dopo 24 ore se non gestita | Consigliata: TUTTE le prenotazioni diventano richieste da confermare |
| c. Aperta a tutti | `aperta` | Confermato subito | Confermato subito | Ammessa con l'avviso arancione "Chiunque può occupare l'agenda senza essere verificato" |
| d. Solo su invito | `invito` | Solo con link personale firmato | Solo con link personale firmato | Ammessa: il link firmato fa da prova |

Regole di riconoscimento "in archivio". Con la verifica WhatsApp l'identità è il numero da cui scrive il paziente (deve coincidere con quello indicato nel passo); con la verifica SMS è il cellulare che ha ricevuto il codice. In entrambi i casi deve corrispondere al cellulare di una scheda in `dap02_clients` (confronto sul valore cifrato, come fa `AuthCodeModel` con `encrypt_select_login`). Con la verifica via email l'identità è l'indirizzo email: il paziente è riconosciuto solo se quell'indirizzo coincide con l'email di una scheda in archivio. Il cellulare indicato ma non verificato non serve mai a riconoscere nessuno né a precompilare dati: altrimenti basterebbe conoscere il numero di un altro per vedere il suo nome e prenotare a suo nome. Un paziente che verifica con l'email e non viene riconosciuto è trattato come nuovo (in modalità `approvazione` la sua prenotazione va in approvazione; la segreteria la vede marcata "cellulare non verificato" in S2) e fino alla conferma della segreteria i promemoria WhatsApp e SMS verso quel numero restano spenti: vanno per email. Per "Per me", la persona è riconosciuta se l'identità corrisponde a una sola scheda; se corrisponde a più schede (famiglie con un numero condiviso) si mostrano solo i nomi propri di quelle schede per scegliere. Per "Per un'altra persona" si cerca la scheda per nome, cognome e data di nascita tra quelle collegate a quel numero. Se la corrispondenza è ambigua o assente, la persona si tratta come nuova: mai collegamento automatico su un dubbio. Senza verifica nessuno è mai riconosciuto in automatico (sezione 5.10).

La modalità si valuta sulla persona che riceve la visita. La regola delle assenze (5.5) e il blocco di un numero portano un paziente in archivio in modalità `approvazione` anche se lo studio è in modalità `aperta`.

Modalità `invito`: il link è `/prenota/{tenant_key}/invito/{token}`, token casuale di 32 byte salvato come hash SHA-256, legato a un paziente, eventualmente a prestazione e professionista, scadenza predefinita 14 giorni, valido per una prenotazione. Con la verifica attiva il numero (WhatsApp o SMS) o l'indirizzo email (email) deve coincidere con quello del paziente invitato. Senza verifica il link fa da prova: la prenotazione va al paziente dell'invito e la pagina mostra solo il suo nome proprio.

### 5.4 Regole e valori predefiniti

Tutti configurabili dal team AF nell'editor (sezione 4), tranne dove è indicato "fisso". La terza colonna è il percorso nel JSON della sezione 4.3 e, tra parentesi, la vecchia chiave della prima versione della guida.

| Regola | Predefinito | Campo nel JSON |
|---|---|---|
| Modalità di accesso | `approvazione` | `accesso.modalita` (era `access_mode`) |
| Scadenza di una richiesta da confermare | 24 ore | `accesso.ore_scadenza_richieste` (era `request_ttl_hours`) |
| Prenotazioni future attive per identità verificata o recapito indicato (confermate più da confermare) | 2 | `limiti.attive_per_recapito` (era `max_active_per_phone`) |
| Prenotazioni future attive per la stessa prestazione | 1 | `limiti.stessa_prestazione` (era `max_active_per_service`) |
| Sovrapposizioni per lo stesso numero | vietate (fisso) | |
| Assenze senza disdetta prima dell'approvazione obbligatoria | 2 | `limiti.assenze_per_approvazione` (era `no_show_threshold`) |
| Durata della riserva dopo la verifica | 10 minuti | `regole.minuti_riserva` (era `hold_minutes`) |
| Riserve attive per numero | 1 (fisso) | |
| Verifica richiesta | sì | `verifica.richiesta` |
| Metodi di verifica accesi, in ordine | WhatsApp (consigliato, solo se il numero dello studio è collegato) ed email; SMS spento | `verifica.metodi` (era `verify_channels`) |
| Codice di verifica | WhatsApp: 4 cifre più il codice pratica di 6 caratteri. Email e SMS: 6 cifre. Tutti validi 10 minuti (fisso) | |
| Tentativi per codice email o SMS | 5, poi blocco 15 minuti (fisso) | |
| Messaggi WhatsApp di verifica errati per mittente | 5 all'ora, poi i messaggi di quel numero si ignorano per un'ora (fisso) | |
| Attesa prima di "Rimanda il codice" | 60 secondi (fisso) | |
| Verifiche avviate per numero indicato (tutti i metodi insieme) | 3 all'ora | `protezioni.verifiche_per_numero_ora` (era `verify_per_phone_hour`) |
| SMS di codice per numero, invii e rinvii insieme | 3 all'ora (fisso) | |
| SMS di codice per IP | 10 all'ora (fisso, su tutti gli studi) | |
| Verifiche avviate per dispositivo (cookie di verifica) | 5 all'ora | `protezioni.verifiche_per_dispositivo_ora` (era `verify_per_device_hour`) |
| Email di codice per indirizzo email | 3 all'ora | `protezioni.email_per_indirizzo_ora` (era `otp_per_email_hour`) |
| Verifiche avviate per IP | 10 all'ora (fisso, su tutti gli studi) | |
| Tetto giornaliero di email di verifica per studio (solo protezione anti abuso) | 100, avviso alla segreteria all'80% | `protezioni.email_tetto_giornaliero` (era `otp_daily_cap`) |
| Tetto giornaliero di SMS di verifica per studio (anti abuso e credito) | 50, avviso alla segreteria quando è raggiunto | `verifica.sms_tetto_giornaliero` |
| Prefissi ammessi | solo cellulari italiani (+39 3xx); per l'SMS sempre e solo cellulari italiani (fisso) | `protezioni.prefissi_esteri` (era `allowed_prefixes`) |
| Recapito senza verifica | almeno uno tra cellulare ed email | `senza_verifica.recapito` |
| Prenotazioni senza verifica per dispositivo | 3 al giorno | `protezioni.senza_verifica_per_dispositivo_giorno` |
| Prenotazioni senza verifica per IP | 5 al giorno | `protezioni.senza_verifica_per_ip_giorno` |
| Domini email usa e getta | bloccati, con elenco aggiornabile (pacchetto dedicato, non scritto a mano) | |
| Avviso di attività anomala | più di 10 nuove richieste in un'ora | `protezioni.soglia_anomalia_ora` (era `anomaly_threshold_hour`) |
| Dispositivo riconosciuto dopo la verifica | 30 giorni (cookie firmato) | `protezioni.giorni_dispositivo` (era `device_days`) |
| Preavviso minimo per prenotare | 120 minuti | `regole.preavviso_minimo_min` (era `min_notice_minutes`) |
| Finestra massima | 90 giorni | `regole.giorni_prenotabili` (era `max_days_ahead`) |
| Preavviso per spostare o annullare | 24 ore | `regole.preavviso_modifica_ore` (era `cancel_notice_hours`) |

Note sulle regole:

- I limiti per persona contano gli appuntamenti futuri con stato `CONFERMATO` o `IN_ATTESA` collegati all'identità verificata (numero WhatsApp o SMS, oppure indirizzo email) oppure al numero o all'email indicati dal paziente, anche se non verificati, per qualunque persona (anche familiari). Contare anche il recapito indicato impedisce di aggirare il limite cambiando indirizzo email a ogni richiesta. Il limite si controlla prima di creare la riserva e di nuovo al momento della conferma.
- Il tetto giornaliero di email conta le email di verifica inviate nella giornata in `Europe/Rome`. Raggiunto il tetto non si inviano altre email di codice: gli altri metodi accesi restano disponibili; se non ne resta nessuno, il paziente vede il telefono dello studio. La segreteria riceve S5. Il tetto non serve a contenere un costo, ma a fermare chi prova a inondare di email gli indirizzi altrui.
- Il tetto giornaliero di SMS conta gli SMS di codice (invii e rinvii, per prenotare e per entrare in "Le mie prenotazioni") della giornata in `Europe/Rome`. Raggiunto il tetto, il catalogo toglie `sms` dai metodi fino a mezzanotte, `verifica/avvia` con `metodo: "sms"` risponde 422 `metodo_non_disponibile` e la segreteria riceve S5 (una volta al giorno per questo motivo). Ogni SMS costa: il tetto protegge il credito dello studio anche da un attacco che superasse le altre difese.
- I numeri non mobili o esteri vengono rifiutati prima di Turnstile, con un messaggio che invita a usare un cellulare italiano o a chiamare lo studio. Per l'SMS il controllo è sempre "+39 seguito da 3", anche se lo studio ammette prefissi esteri per gli altri metodi.
- Le risposte di `verifica/avvia` sono identiche per un numero in archivio, nuovo o bloccato: nessuna enumerazione dei pazienti. Anche con l'SMS: a un numero bloccato non parte nessun SMS, ma la risposta è la stessa.

### 5.5 Assenze senza disdetta

Il gestionale oggi non registra le assenze. Si aggiunge in agenda l'azione "Non si è presentato" sugli appuntamenti passati di origine online (MVP) e, in Fase 2, su tutti. L'azione scrive `esito_presenza = 'assente'` in `dap50_prenotazioni_online` e incrementa il contatore dell'identità (numero o email verificata, oppure recapito indicato senza verifica) in `dap51_prenotazioni_online_numeri`. Al raggiungimento della soglia l'identità passa a `richiede_approvazione`; la segreteria può riportarla a normale.

### 5.6 Verifica dell'identità, Turnstile e campo trappola

Il paziente dimostra di essere lui in uno dei metodi accesi dallo studio in `verifica.metodi`: WhatsApp ed email a costo zero per lo studio, SMS a consumo sul credito dello studio. In tutti e tre nasce prima una sessione di verifica (`platform_patient_verifiche`, sezione 6.2): non è un appuntamento, non occupa l'agenda e scade da sola in 10 minuti. Il server accetta solo i metodi accesi in quel momento per lo studio: un metodo spento risponde 422 `metodo_non_disponibile`.

**Verifica con WhatsApp.** Disponibile solo se lo studio ha il numero WhatsApp collegato al gestionale. È il paziente a scrivere allo studio: un messaggio in entrata, compreso nel modulo WhatsApp a canone fisso, che prova che il numero è davvero suo.

1. `POST verifica/avvia` con `metodo: "whatsapp"` e il cellulare indicato. Il server crea la sessione con un codice pratica di 6 caratteri (lo stesso che diventerà il `booking_ref`, per esempio K7P2QH) e un codice di 4 cifre (per esempio 4821), poi risponde con il testo del messaggio e con il link `https://wa.me/{numero dello studio}?text=...` già codificato.
2. Il paziente tocca "Apri WhatsApp": si apre l'app con il messaggio già scritto, per esempio `Confermo la prenotazione K7P2QH, codice di verifica 4821`, e lo invia. Su desktop la pagina mostra un QR (generato in locale, mai con servizi esterni) che contiene lo stesso link `wa.me`.
3. Il messaggio arriva al gateway WhatsApp dello studio. Prima di passarlo al bot, il punto d'ingresso dei messaggi in arrivo del modulo WhatsApp (oggi il motore `WhatsAppBookingEngine`: cerca la classe che riceve il webhook del gateway) chiama `PatientVerificationService::matchWhatsApp()`. Il testo si riconosce con un'espressione tollerante (maiuscole, punteggiatura e spazi diversi: il paziente può averlo modificato), che estrae codice pratica e codice. Si cerca una sessione `in_attesa` di quello studio con quella coppia. Il codice da solo non basta: la coppia ha circa un miliardo di combinazioni, quindi chi scrive a caso da un altro telefono non può agganciarsi alla verifica di un altro paziente.
4. Il numero mittente (formato E.164, preso dal gateway e mai dal testo) deve coincidere con il cellulare indicato nel passo. Se coincide, la sessione passa a `verificata` e registra l'`identita_hash` di quel numero. Se non coincide, la sessione resta `in_attesa` con esito `numero_diverso` e la pagina lo dice (sezione 3.4). In ogni caso il messaggio non va al bot.
5. La pagina chiama `GET verifica/{id}/stato` ogni 2 secondi finché è visibile (ogni 5 dopo il primo minuto). Appena lo stato è `verificata` chiama `POST verifica/{id}/completa`, che imposta il cookie del dispositivo una sola volta (la GET non modifica mai nulla). Poi passa da sola al passo successivo e annuncia "Numero verificato" con `aria-live`. Dopo 10 minuti lo stato è `scaduta` e la pagina propone di riprovare o di usare un altro metodo acceso.
6. Sicurezza: codice pratica e codice si confrontano a tempo costante; la sessione è legata al browser che l'ha avviata (hash di un cookie di verifica), quindi l'esito non si può leggere da un altro browser; ogni sessione si usa una volta sola; i messaggi con coppia sbagliata contano per mittente (5 all'ora, poi si ignorano per un'ora, senza risposta); il testo dei messaggi riconosciuti non finisce nei log. Una risposta automatica al paziente ("Fatto. Torna alla pagina di prenotazione.") è facoltativa e ammessa solo dentro la finestra di 24 ore aperta dal suo messaggio, quindi senza costo aggiuntivo nel modulo a canone fisso: verificare con il provider prima di attivarla.

**Verifica via email (gratuita).** `POST verifica/avvia` con `metodo: "email"`, il cellulare indicato e l'indirizzo email. Dopo Turnstile il server invia P7 con un codice di 6 cifre (valido 10 minuti, 5 tentativi, poi blocco di 15 minuti), che il paziente scrive in `POST verifica/{id}/codice`. In alternativa al codice si può mandare un link di conferma: la pagina aperta dal link mostra il bottone "Conferma" e la verifica avviene con la POST, perché la GET non deve consumare nulla (gli scanner delle caselle aprono i link in anticipo). Gli indirizzi di domini usa e getta sono rifiutati. L'email verificata prova il possesso dell'indirizzo, non del cellulare: cosa cambia per il riconoscimento e per i promemoria è nella sezione 5.3.

**Verifica via SMS (a consumo, spenta di default).** Disponibile solo se lo studio ha messo `sms` in `verifica.metodi`, ha il modulo SMS attivo (lo stesso dei promemoria) e non ha raggiunto il tetto del giorno.

1. `POST verifica/avvia` con `metodo: "sms"` e il cellulare indicato. Controlli in quest'ordine, e nessun SMS parte se uno fallisce: campo trappola vuoto; numero mobile italiano (+39 seguito da 3, sempre, anche se lo studio ammette prefissi esteri); token Turnstile valido; limiti (3 SMS all'ora per numero, invii e rinvii insieme; 10 all'ora per IP; 5 verifiche all'ora per dispositivo; tetto giornaliero dello studio); numero bloccato (risposta identica, nessun invio).
2. Il server crea la sessione con un codice di 6 cifre (solo l'HMAC in tabella) e invia l'SMS con il modulo SMS che il gestionale usa già per i promemoria: stesso provider, stesso mittente alfanumerico già registrato per lo studio ("SantaChiara"), stessa registrazione dei consumi. L'SMS si paga a consumo come i promemoria, sul credito dello studio, e nel registro dei consumi ha il tipo `verifica`, così lo studio lo distingue dai promemoria.
3. Testo, fisso e senza link, senza prestazione, senza nome del paziente: `Poliambulatorio Santa Chiara: il tuo codice di verifica è 482193. Vale 10 minuti. Non condividerlo con nessuno.` (111 caratteri GSM-7, un solo SMS). Se il nome dello studio rende il testo più lungo di 160 caratteri, si usa `comunicazioni.nome_nei_messaggi`.
4. Il paziente scrive il codice nelle sei caselle e la pagina chiama `POST verifica/{id}/codice`, lo stesso endpoint dell'email: 5 tentativi, poi blocco di 15 minuti; codice valido 10 minuti e usabile una volta.
5. "Rimanda il codice" (`POST verifica/{id}/rimanda`) si attiva dopo 60 secondi, conta nei limiti e, solo per l'SMS, vuole un token Turnstile nuovo: un bot non può ripetere l'invio con lo stesso token.
6. Se il provider risponde con un errore o il credito SMS dello studio è finito, la verifica non parte: 422 `metodo_non_disponibile`, la pagina propone gli altri metodi accesi (o il telefono dello studio) e l'evento va nel registro.
7. L'SMS verificato prova il possesso del cellulare come WhatsApp: l'identità è il numero, con le stesse regole di riconoscimento della sezione 5.3.

**Le mie prenotazioni.** Stessa meccanica con `scopo: "accesso"`: con WhatsApp il messaggio è `Confermo l'accesso K7P2QH, codice di verifica 4821`; con l'email arriva il link di accesso P8, valido 15 minuti e usabile una volta sola (la GET apre la pagina, la POST consuma il link); con l'SMS arriva lo stesso SMS del codice. I metodi sono quelli di `verifica.metodi`; se la lista è vuota "Le mie prenotazioni" non esiste (sezione 5.10).

**Turnstile.**

- **Cliente.** Il widget Cloudflare Turnstile in modalità invisibile (`appearance: "interaction-only"`, `cData` uguale al `tenant_key`) si carica solo quando serve, non prima. Con la verifica: al sottopasso "Verifica che sei tu", con `action: "verifica_avvia"`; il token va nel corpo di `verifica/avvia`, per tutti i metodi, e in `verifica/{id}/rimanda` per l'SMS. Senza verifica: al passo Riepilogo, con `action: "prenota_conferma"`; il token va nel corpo di `POST /prenotazioni` (sezione 5.10). Nell'informativa dello studio va citato Cloudflare come fornitore della verifica anti bot (non usa cookie di profilazione).
- **Server.** Prima di ogni avvio di verifica, di ogni rinvio di SMS e di ogni prenotazione senza verifica, chiamata POST a `https://challenges.cloudflare.com/turnstile/v0/siteverify` con `secret`, `response` (il token), `remoteip` e `idempotency_key`. Timeout 3 secondi. Si accetta solo se `success` è vero, `action` è quella attesa per l'endpoint, `hostname` è il dominio del gestionale e `cdata` è il `tenant_key` della richiesta. Il token vale una volta sola e 300 secondi. Se Cloudflare non risponde, si nega l'operazione (fail closed), si registra l'evento e il paziente vede "Non riusciamo a verificare la richiesta. Riprova tra poco o chiama lo studio al {telefono}".
- **Chiavi.** Variabili d'ambiente `TURNSTILE_SITE_KEY` e `TURNSTILE_SECRET_KEY`, una coppia per il dominio del gestionale. La chiave segreta non va mai nel repo, nei log o nelle risposte.

**Campo trappola.** Un input `name="sito_web"` fuori schermo (`position:absolute; left:-10000px`, `tabindex="-1"`, `autocomplete="off"`, `aria-hidden="true"`), presente sia nel passo di verifica sia, senza verifica, nel riepilogo. Se arriva valorizzato, il server risponde come se avesse avviato la verifica (o accettato la prenotazione) ma non crea nulla, non invia nulla e registra l'evento.

### 5.7 Schema di flusso delle richieste

Schema con l'ordine predefinito dei passi e la verifica attiva. Senza verifica il flusso è quello della sezione 5.10.

```
Paziente                           Gestionale
--------                           ----------
sceglie prestazione, professionista, sede, orario
  (nessuna scrittura, solo letture di disponibilita)
indica il cellulare e
sceglie come verificare ---------> POST verifica/avvia
                                     0. metodo acceso per lo studio? (sms: tetto e credito ok?)
                                     1. campo trappola vuoto?
                                     2. numero mobile italiano? email non usa e getta?
                                     3. Turnstile siteverify ok?
                                     4. limiti: numero 3/ora, dispositivo 5/ora, email 3/ora,
                                        IP 10/ora, studio 100 email al giorno,
                                        sms: 3/ora per numero, 10/ora per IP, tetto studio 50 al giorno
                                     5. numero bloccato? -> risposta identica, nessun invio
                                     6. crea la sessione di verifica (solo hash del codice)
                                        WhatsApp: risponde con messaggio e link wa.me
                                        email: invia P7 con il codice
                                        sms: invia l'SMS del codice dal modulo SMS dello studio
WhatsApp: scrive allo studio -----> gateway WhatsApp (messaggio in entrata)
                                     coppia (codice pratica, codice) trovata?
                                     mittente = numero indicato? -> sessione verificata
oppure scrive il codice email
o SMS ---------------------------> POST verifica/{id}/codice
                                     max 5 tentativi, poi blocco 15 minuti
la pagina chiede lo stato ------> GET verifica/{id}/stato (nessuna modifica)
                         ---------> POST verifica/{id}/completa
                                     cookie di sessione firmato (30 giorni)
                                     sessione di verifica usata, non riutilizzabile
                         ---------> POST riserva
                                     scelte rivalidate sulla configurazione pubblicata (4.6)
                                     limiti per identita' (2 attive, 1 per prestazione,
                                       nessuna sovrapposizione)
                                     una sola riserva attiva per identita'
                                     lock dap14 per 10 minuti, token al client
completa i dati, conferma -------> POST prenotazioni (Idempotency-Key)
                                     GET_LOCK + FOR UPDATE sugli slot del giorno
                                     slot ancora libero? no -> 409 con 3 alternative
                                     chi riceve la visita e' in archivio?
                                       si  -> dap12 CONFERMATO, P2 + S1
                                       no  -> secondo accesso.modalita:
                                              archivio     -> rifiuto con telefono
                                              approvazione -> dap12 IN_ATTESA, P1 + S2
                                              aperta       -> anagrafica + CONFERMATO, P2 + S1
                                     lock dap14 rilasciato
```

### 5.8 Ciclo di vita di una richiesta da confermare

```
            crea richiesta (dap12 IN_ATTESA, slot occupato, agenda tratteggiata)
                                   |
            +----------------------+-----------------------+---------------------+
            |                      |                       |                     |
   segreteria conferma    segreteria rifiuta     paziente annulla        scadono 24 ore
            |                      |                       |                     |
   crea anagrafica,       dap12 ANNULLATO,        dap12 ANNULLATO,       dap12 ANNULLATO,
   dap12 CONFERMATO,      slot liberato,          slot liberato,         slot liberato,
   P2 al paziente         P3 al paziente          S4 allo studio         P3 al paziente
                                                                         (motivo: non
                                                                         confermata in tempo)
```

- La richiesta usa lo stato `IN_ATTESA` già presente nell'enum di `dap12_agenda_appuntamenti.stato`, con `id_client` nullo e nome, cognome e cellulare nelle colonne dell'appuntamento. Poiché la disponibilità esclude ogni appuntamento non `ANNULLATO`, l'orario resta occupato senza codice nuovo.
- L'anagrafica in `dap02_clients` si crea solo alla conferma della segreteria (`PazientiModel::savePatientAndLink`, `ClientDoctorModel::setDoctorForClient`, `DoctorPatientSearchModel::syncClient`). Fino ad allora i dati del paziente stanno solo nell'appuntamento e in `dap50_prenotazioni_online`. Per una richiesta senza verifica la segreteria sceglie se collegarla a una scheda esistente o crearne una nuova (sezione 5.10).
- La scadenza la gestisce un comando CLI (`php spark prenota:scadenze`) lanciato ogni 5 minuti dal cron del container: richieste oltre `accesso.ore_scadenza_richieste`, riserve `dap14` scadute (stato `SCADUTO` e `restoreSlotState`), codici OTP scaduti, sessioni scadute, loghi non più usati (sezione 4.9).
- In agenda gli appuntamenti `IN_ATTESA` di origine online si disegnano con bordo tratteggiato e l'etichetta "Da confermare", con i bottoni "Conferma" e "Non accogliere" (motivo facoltativo).
- I messaggi al paziente (P1, P2, P3) partono per email se il paziente ha lasciato l'email; senza verifica e con il solo cellulare partono per SMS o WhatsApp, se lo studio li ha (sezione 5.10).

### 5.9 Avviso di attività anomala e freno di emergenza

- **Soglia.** Più di `protezioni.soglia_anomalia_ora` (10) nuove richieste o prenotazioni online nell'ultima ora per lo studio, oppure il tetto giornaliero di email di verifica all'80%, oppure il tetto giornaliero di SMS di verifica raggiunto. Si controlla a ogni nuova richiesta con un contatore scorrevole.
- **Avviso.** Email S5 alla segreteria (massimo una ogni 3 ore per studio) e banner rosso nella pagina "Prenotazione online" del gestionale con i numeri dell'ultima ora e i bottoni rapidi. Per il tetto SMS l'avviso dice anche che fino a domani il metodo SMS non compare ai pazienti.
- **Sospendi le prenotazioni online.** Interruttore in un tocco (`sospesa = 1` in `platform_patient_booking_configs`, fuori dalla bozza perché deve valere subito): la pagina pubblica mostra subito il telefono dello studio, le richieste in corso restano.
- **Per numero.** "Rifiuta tutte le richieste di questo numero" (annulla tutte le `IN_ATTESA` di quel numero e manda un solo P3) e "Blocca questo numero" (stato `bloccato` in `dap51`, valido anche per l'indirizzo email collegato alle richieste e per i recapiti indicati senza verifica; il paziente vede solo il telefono dello studio).

### 5.10 Prenotazione senza registrazione (senza verifica)

**Quando si usa.** Con `verifica.richiesta: false`, o con il passo `verifica` spento: il paziente prenota senza nessuna verifica e senza codice, lasciando un recapito. È una scelta dello studio, fatta dal team AF nell'editor, per chi mette la semplicità prima di tutto (per esempio pazienti anziani che non usano WhatsApp né l'email) e accetta i rischi scritti in fondo a questa sezione. Il predefinito resta la verifica attiva.

**Cosa vede il paziente.**

- Nessun passo "Verifica che sei tu", nessun codice.
- In "I tuoi dati", al posto dei campi cellulare ed email, il blocco "Come ti contattiamo" con cellulare ed email e il testo d'aiuto "Ci serve per avvisarti se l'appuntamento cambia.". `senza_verifica.recapito` decide cosa è obbligatorio: `almeno_uno` (predefinito: almeno uno tra cellulare ed email), `cellulare`, `email`, oppure `facoltativo` (nessun recapito obbligatorio: lo studio lo sceglie sapendo che non potrà avvisare il paziente, e l'editor lo avvisa). Se `dati.cellulare` o `dati.email` sono `obbligatorio`, vale il più severo tra i due campi. Il cellulare segue le stesse regole di formato del flusso con verifica.
- Il riepilogo dice "Ti contatteremo a: 347 812 6650" (o "a: 347 812 6650 e rosa.bianchi@email.it"); senza recapiti dice "Non hai lasciato recapiti: se l'appuntamento cambia non potremo avvisarti.".
- La schermata finale mostra il codice prenotazione in evidenza ("Il tuo codice prenotazione: K7P2QH", "Conservalo: ti serve se chiami lo studio").

**Modalità di accesso compatibili.** Sono nella tabella della sezione 5.3. In sintesi: `approvazione` (consigliata e predefinita senza verifica: TUTTE le prenotazioni senza verifica diventano richieste da confermare; la segreteria vede se il numero corrisponde a un paziente in archivio, ma è solo un indizio), `aperta` (confermate subito, con l'avviso arancione nell'editor), `invito` (il link firmato fa da prova). `archivio` non è ammessa: non si può sapere chi è davvero, e l'editor la blocca.

**Protezioni che restano.**

- Turnstile obbligatorio prima della conferma (`action: "prenota_conferma"`, token nel corpo di `POST /prenotazioni`), con le regole server della sezione 5.6.
- Campo trappola nel riepilogo.
- Al massimo 2 prenotazioni future attive per recapito (`limiti.attive_per_recapito`), contate sia sul cellulare sia sull'email indicati, e 1 per la stessa prestazione.
- Avviso di attività anomala, interruttore "Sospendi", "Blocca questo numero" (sezione 5.9).
- Throttle generale per IP della sezione 6.5.

**Protezioni in più.**

- Al massimo 3 prenotazioni senza verifica al giorno per dispositivo (`protezioni.senza_verifica_per_dispositivo_giorno`) e 5 per IP (`protezioni.senza_verifica_per_ip_giorno`). Il dispositivo si riconosce da un cookie firmato `af_pz_dev_{tenant_key}` (`HttpOnly`, `Secure`, `SameSite=Lax`, 30 giorni) emesso alla prima visita della pagina; in tabella solo il suo hash. Un browser senza cookie conta come dispositivo nuovo, per questo esiste anche il limite per IP.
- Approvazione consigliata: con `approvazione` nulla entra in agenda come confermato senza che la segreteria lo veda.

**Orario: nessuna riserva.** Senza verifica non c'è la riserva di 10 minuti, perché non c'è un passo di verifica dopo cui farla nascere: una riserva senza identità sarebbe proprio lo strumento per congelare l'agenda. L'orario si blocca solo alla conferma, con il controllo atomico della sezione 6.6 (`GET_LOCK`, `FOR UPDATE`, nuovo calcolo della disponibilità). Se nel frattempo l'ha preso qualcun altro: 409 con i 3 orari più vicini, come nel flusso con verifica.

**Gestione dopo la prenotazione.**

- Con il link firmato (sezione 6.6) nell'email, o nell'SMS o WhatsApp di conferma se lo studio li ha. Il link permette di vedere, spostare e annullare quella prenotazione, con le stesse regole di preavviso.
- "Le mie prenotazioni" resta disponibile solo se almeno un metodo di verifica è acceso in `verifica.metodi` (per entrare serve comunque verificarsi). Se la lista è vuota il pulsante sparisce e la schermata finale dice "Per spostare o annullare usa il link che ti abbiamo mandato, oppure chiama lo studio".
- Messaggi: se il paziente ha lasciato l'email, le email P1 a P6 partono come sempre. Con il solo cellulare, conferme, rifiuti, spostamenti e annullamenti partono per SMS o WhatsApp se lo studio li ha (testi in `email/testi.md`); se lo studio non li ha, il paziente ha solo il codice e il telefono dello studio, e la schermata finale lo dice. P7 e P8 non partono mai senza una verifica richiesta dal paziente.

**Cosa vede la segreteria.**

- Badge "Non verificato" sull'appuntamento in agenda, nelle richieste da confermare, in S1 e in S2 (riga "Contatti: 347 812 6650 (non verificato)").
- Se il cellulare o l'email indicati corrispondono a una scheda in archivio, la richiesta mostra "Il recapito corrisponde a un paziente in archivio: Rosa Bianchi", come indizio. Non c'è mai collegamento automatico, e la pagina del paziente non mostra mai nulla di quella scheda.
- Senza verifica non si crea e non si collega mai in automatico una scheda in `dap02_clients`: l'appuntamento ha `id_client` nullo e i dati stanno nell'appuntamento e in `dap50`. La segreteria sceglie "Collega a questa scheda" o "Crea una scheda nuova": alla conferma della richiesta in modalità `approvazione`, oppure dall'agenda in modalità `aperta` (al più tardi all'arrivo del paziente).
- I promemoria WhatsApp e SMS verso un cellulare non verificato partono solo se il paziente l'ha lasciato come recapito e lo studio ha il canale attivo; "Blocca questo numero" li ferma.

**Tabelle (sezione 6.2).** In `dap50_prenotazioni_online`: `verificato` (`TINYINT(1)`, 0 per queste prenotazioni), `verificato_con` nullo, `recapito_tipo` (`cellulare`, `email`, `entrambi`, `nessuno`), `phone_hash` ed `email_hash` dei recapiti indicati (nulli se assenti), `identita_hash` nullo. In `platform_patient_booking_events`: eventi `prenotazione_non_verificata` con `device_hash` e `ip_hash`, su cui si contano i limiti per dispositivo e IP.

**Endpoint.** `POST /api/prenota/{tenant_key}/prenotazioni` senza sessione verificata, con Turnstile, ammesso solo se la configurazione pubblicata ha la verifica spenta (altrimenti 401 `sessione_assente`, come oggi). Header `Idempotency-Key` obbligatorio.

```json
{
  "config_revisione": 7,
  "scelte": {"prestazione": 12, "professionista": "primo", "sede": 1, "slot": "s_88213", "per": {"tipo": "me"}},
  "paziente": {"nome": "Rosa", "cognome": "Bianchi"},
  "recapito": {"cellulare": "+393478126650", "email": ""},
  "nota": "",
  "privacy_letta": true,
  "turnstile_token": "0.AbCd...",
  "sito_web": ""
}
```

Risposta 201: `{"ref": "K7P2QH", "stato": "in_attesa", "verificato": false, "quando": "giovedì 12 novembre 2026 alle 17:30", "contatto": "347 812 6650", "gestisci_url": "/prenota/santa-chiara/p/K7P2QH?t=...", "le_mie_prenotazioni": true}`. Errori: 422 `recapito_mancante` con `campi`, 422 `verifica_non_riuscita` (Turnstile), 429 `troppe_prenotazioni` (dispositivo o IP), 409 `slot_non_disponibile` con `alternative`, 409 `configurazione_cambiata`, 422 `limite_prenotazioni`, 422 `prestazione_gia_prenotata`, 403 `prenotazioni_sospese`. Le risposte per un recapito bloccato sono identiche a quelle di una richiesta accettata, ma non si scrive nulla.

**Checklist di test senza verifica.**

- [ ] Configurazione con verifica spenta: nessun passo di verifica, blocco "Come ti contattiamo" nel passo dati, nessuna chiamata a `verifica/*`.
- [ ] `POST /prenotazioni` senza sessione con verifica attiva: 401 `sessione_assente`.
- [ ] Senza token Turnstile, con token riusato o con `action` diversa da `prenota_conferma`: 422, nessuna scrittura.
- [ ] Campo trappola valorizzato: risposta 201 identica, nessuna riga in `dap12` e `dap50`, evento registrato.
- [ ] `recapito: almeno_uno` senza cellulare né email: 422 `recapito_mancante`. Con `cellulare`: l'email da sola non basta. Con `facoltativo`: si prenota senza recapiti.
- [ ] Quarta prenotazione del giorno dallo stesso dispositivo, sesta dallo stesso IP: 429 `troppe_prenotazioni`.
- [ ] Terza prenotazione futura con lo stesso cellulare, o con la stessa email: 422 `limite_prenotazioni`.
- [ ] Nessuna riga in `dap14` in nessun momento; due conferme concorrenti sullo stesso slot: una 201, l'altra 409 con alternative.
- [ ] Modalità `approvazione`: anche un numero presente in archivio produce `IN_ATTESA`, con l'indizio "corrisponde a un paziente in archivio" e nessun `id_client`.
- [ ] Modalità `aperta`: `CONFERMATO` con `id_client` nullo e badge "Non verificato" in agenda.
- [ ] Modalità `archivio` con verifica spenta: la pubblicazione è bloccata; una configurazione così forzata nel database risponde con il telefono dello studio e non scrive nulla.
- [ ] Solo email: P1 o P2 per email. Solo cellulare con SMS o WhatsApp attivi: conferma per SMS o WhatsApp, nessuna email. Solo cellulare senza canali: nessun messaggio, schermata finale con codice e telefono.
- [ ] `verifica.metodi` vuota: nessun bottone "Le mie prenotazioni", testo finale con il link e il telefono; `verifica/avvia` risponde 422 `metodo_non_disponibile`.
- [ ] Link firmato della conferma: sposta e annulla funzionano entro il preavviso.
- [ ] Nessun dato della scheda in archivio arriva mai alla pagina del paziente.

**Rischi, detti chiaramente.**

- Chiunque può prenotare a nome di un altro: il nome non è verificato. La segreteria non deve fidarsi del nome, e il badge "Non verificato" serve a ricordarlo.
- Il recapito può essere sbagliato o di un'altra persona: lo studio potrebbe non riuscire ad avvisare il paziente, oppure mandare SMS e WhatsApp di conferma a un estraneo (a pagamento, per gli SMS). I limiti per recapito e "Blocca questo numero" contengono il danno, non lo azzerano.
- Turnstile ferma i bot, non le persone. Una persona determinata può cambiare rete e browser e superare i limiti per dispositivo e IP. In modalità `aperta` può occupare orari veri fino alla scoperta; in modalità `approvazione` occupa al massimo orari da confermare, che scadono da soli.
- Più assenze: chi prenota senza verificarsi si impegna meno. La regola delle assenze conta sul recapito, che si può cambiare.
- Dati di terzi: chi prenota può scrivere dati di un'altra persona. L'informativa dello studio deve coprire questo caso, e i dati di una richiesta non accolta si cancellano come quelli di ogni richiesta (sezione 6.2).
- Con `recapito: facoltativo` lo studio accetta di non poter avvisare il paziente se l'appuntamento cambia.

Per questo la verifica resta il predefinito e, senza verifica, la modalità consigliata è `approvazione`.

### 5.11 Checklist di test anti abuso

- [ ] Toccare un orario e abbandonare: nessuna riga in `dap14`, `dap12`, `dap02`.
- [ ] SMS di verifica solo con il metodo acceso: con `sms` fuori da `verifica.metodi` la porta SMS finta fa fallire il test se viene invocata, per ogni scopo (prenotazione, accesso) e per ogni altro metodo (WhatsApp, email).
- [ ] Inviare `verifica/avvia` senza token Turnstile, con token scaduto, con token riusato, con `action` o `cdata` diversi: nessuna sessione creata, nessuna email o SMS inviati, risposta 422 generica.
- [ ] Campo trappola valorizzato: risposta 200 identica, nessun invio, evento registrato.
- [ ] Quarta verifica avviata per lo stesso numero nella stessa ora, sesta dallo stesso dispositivo, oppure quarta email di codice allo stesso indirizzo: 429 con il testo di 3.4.
- [ ] Undicesima verifica avviata dallo stesso IP nell'ora, anche su numeri diversi e studi diversi: 429.
- [ ] Tetto giornaliero di email di verifica raggiunto: nessuna altra email di codice, gli altri metodi accesi restano disponibili, S5 inviata una sola volta.
- [ ] Numero fisso, estero o malformato: rifiutato prima di Turnstile.
- [ ] Sesto tentativo di codice email o SMS: blocco di 15 minuti anche con il codice giusto.
- [ ] Codice usato una volta: il secondo uso fallisce.
- [ ] Risposte di `verifica/avvia` identiche per numero in archivio, nuovo e bloccato (stesso corpo, stesso codice HTTP, tempi simili), anche con l'SMS.
- [ ] Messaggio WhatsApp con la coppia giusta dal numero indicato: sessione `verificata`, la pagina passa avanti da sola, il messaggio non arriva al bot.
- [ ] Stesso messaggio da un altro numero: sessione ancora `in_attesa` con esito `numero_diverso`.
- [ ] Codice giusto con codice pratica sbagliato, e viceversa: nessun effetto.
- [ ] Messaggio riusato dopo la verifica o dopo 10 minuti: ignorato.
- [ ] Sesto messaggio errato dallo stesso mittente nell'ora: ignorato senza risposta.
- [ ] Esito di una sessione letto da un altro browser: rifiutato.
- [ ] Verifica via email di chi indica il numero di un paziente in archivio ma un'email diversa: trattato come nuovo, nessun dato precompilato, nessun promemoria WhatsApp o SMS verso quel numero.
- [ ] Dominio email usa e getta: 422 `email_non_valida`.
- [ ] SMS acceso: `verifica/avvia` con `metodo: "sms"` invia un solo SMS con il testo esatto della sezione 5.6, senza link, nome o prestazione, dal mittente dei promemoria; il consumo è registrato con tipo `verifica` sul credito dello studio.
- [ ] SMS verso un numero estero o fisso, anche con prefissi esteri ammessi: 422 `numero_non_valido`, nessun invio.
- [ ] Quarto SMS (invio o rinvio) allo stesso numero nell'ora: 429. Undicesimo SMS dallo stesso IP nell'ora, su numeri diversi: 429.
- [ ] "Rimanda il codice" prima di 60 secondi, o senza un token Turnstile nuovo: rifiutato, nessun invio.
- [ ] Tetto SMS dello studio raggiunto (51esimo SMS con tetto 50): `sms` sparisce dal catalogo, `verifica/avvia` con SMS risponde 422 `metodo_non_disponibile`, S5 inviata una volta; il giorno dopo il metodo torna.
- [ ] Credito SMS esaurito o errore del provider: 422 `metodo_non_disponibile`, la pagina propone gli altri metodi, nessun addebito.
- [ ] Numero bloccato con l'SMS: risposta identica, nessun SMS.
- [ ] Codice SMS giusto: identità = numero, riconoscimento in archivio come con WhatsApp.
- [ ] Riserva senza sessione verificata (con la verifica attiva): 401. Seconda riserva per lo stesso numero: la prima viene rilasciata.
- [ ] Terza prenotazione futura con lo stesso numero: 422 `limite_prenotazioni`.
- [ ] Seconda prenotazione della stessa prestazione: 422 `prestazione_gia_prenotata`.
- [ ] Due conferme concorrenti sullo stesso slot da due numeri diversi: una 201, l'altra 409 con alternative.
- [ ] Stessa conferma ripetuta con la stessa `Idempotency-Key`: una sola prenotazione, stessa risposta.
- [ ] Modalità `archivio` con numero nuovo: nessuna scrittura, schermata con telefono.
- [ ] Modalità `approvazione` con numero nuovo: `dap12` `IN_ATTESA`, nessuna riga `dap02`, P1 e S2 inviate.
- [ ] Richiesta non gestita per 24 ore: `ANNULLATO`, slot di nuovo disponibile, P3 inviata.
- [ ] 11 richieste in un'ora: S5 inviata una volta, banner nel gestionale.
- [ ] "Sospendi": la pagina pubblica mostra il telefono entro il refresh successivo; le API di scrittura rispondono 403 `prenotazioni_sospese`.
- [ ] "Blocca questo numero": nessun nuovo codice inviato a quel numero (né per email né per SMS), messaggio neutro.
- [ ] Due assenze registrate: la prenotazione successiva di quel numero diventa `IN_ATTESA` anche in modalità `aperta`.
- [ ] Link di invito usato due volte o scaduto: pagina "Questo link non è più attivo".
- [ ] Tutta la checklist senza verifica della sezione 5.10.

## 6. Architettura

### 6.1 Servizi

1. **`AgendaAvailabilityService`** (nuovo), estratto da `WhatsAppBookingAgenda::availableSlots`, `fits` e `choices` (`rest/app/Services/WhatsAppBookingAgenda.php`, righe 52-104).
   - Parametri: `id_dot[]`, `id_tipo_visita`, `id_amb_legacy`, `from`, `to` (massimo 14 giorni per chiamata), `min_notice_minutes`, `max_days_ahead` (letti da `regole.preavviso_minimo_min` e `regole.giorni_prenotabili`).
   - Fuso fisso `Europe/Rome` anche per i confronti oggi fatti con `date()` (il container gira in UTC).
   - Esclusioni: slot non `LIBERO`, lock attivi `dap14`, giorni bloccati `dap21`, sovrapposizioni con `dap12` non `ANNULLATO`, festivi `dap48`, domeniche.
   - Correzione di `fits()` (righe 94-104): oggi accetta solo una somma esatta delle durate; deve accettare la prima sequenza contigua, stessa stanza e stessa sede, con totale maggiore o uguale alla durata, e scartare durate nulle o pari a 0. Una prestazione da 45 minuti su slot da 30 oggi non viene mai offerta.
   - Restituisce `{giorno: [{start, end, id_slots[], id_dot, id_amb_legacy, id_stanza}]}` e, per la striscia dei giorni, un riepilogo `{giorno: "posti" | "pochi" | "nessuno" | "chiuso"}` ("pochi" = 3 orari o meno).
2. **`PatientVerificationService`** (nuovo): avvio e verifica dell'identità con tutte le regole delle sezioni 5.4 e 5.6. Metodi: `avvia()` (WhatsApp, email o SMS, solo tra i metodi accesi per lo studio), `matchWhatsApp()` (chiamato dal punto d'ingresso dei messaggi WhatsApp in arrivo, prima del bot), `verificaCodice()` (email e SMS), `completa()`. Usa `service('throttler')` di CodeIgniter per i limiti a finestra (come `AccountantController` alla riga 32) e la tabella `platform_patient_verifiche` per sessioni, codici e tentativi. Per l'SMS chiama il modulo SMS dei promemoria attraverso un'interfaccia (`SmsSender`) iniettata: nei test è una porta finta, che fa fallire il test se viene chiamata quando il metodo SMS è spento.
3. **`PatientSessionService`** (nuovo): emette e verifica il cookie del dispositivo riconosciuto. Valore: id di sessione casuale; in tabella solo l'hash. Cookie `af_pz_{tenant_key}`, `HttpOnly`, `Secure`, `SameSite=Lax`, `Path=/` (deve arrivare sia alle pagine `/prenota/{tenant_key}` sia alle API `/api/prenota/{tenant_key}`, che hanno percorsi diversi), durata 30 giorni. La riga di sessione ricorda lo studio e il canale con cui è stata verificata: un cookie di un altro studio non vale. Revoca da "Esci" e da "Blocca questo numero". Emette anche il cookie di dispositivo `af_pz_dev_{tenant_key}` usato dai limiti senza verifica (sezione 5.10).
4. **`PatientBookingService`** (nuovo):
   - `hold()`: dopo la verifica, crea il lock in `dap14_agenda_lock` riusando `AgendaLockModel` (stato `ATTIVO`, `expires_at` a `regole.minuti_riserva`, `id_ope` = id dell'operatore tecnico "Prenotazione online" da creare per ogni studio, perché la colonna non ammette null). Restituisce il token. `releaseHold()` lo rilascia.
   - `book()`: riusa lo schema di `WhatsAppBookingAgenda::book` e `bookLocked` (righe 128-172): `GET_LOCK`, `FOR UPDATE` sugli slot del giorno del professionista, nuovo controllo con `AgendaAvailabilityService`, `AgendaAppointmentModel::saveAppointment` con `visit_type_required` e `check_active_locks`, escludendo il lock del paziente stesso. Rivalida le scelte con `BookingConfigService` (sezione 4.6). Decide `CONFERMATO` o `IN_ATTESA` secondo le sezioni 5.3 e 5.10. Funziona anche senza sessione verificata, solo quando la configurazione pubblicata ha la verifica spenta e con Turnstile (sezione 5.10). Idempotenza con `Idempotency-Key` (sezione 6.6).
   - `reschedule()`: una transazione, lock su entrambi i giorni in ordine di data per evitare deadlock, stesso `id_appuntamento`, `ics_sequence` più 1, vecchi slot liberati con `restoreSlotState`. Se il nuovo orario non è libero non si tocca nulla.
   - `cancel()`: sul modello di `WhatsAppBookingAgenda::cancel` (righe 200-217); proprietà verificata con il token del link o con la sessione; `deleteAppointment($id, $idOperatoreTecnico)` per un audit con nome; rispetto di `regole.preavviso_modifica_ore`.
   - `approve()`, `reject()`, `expire()`: ciclo di vita della sezione 5.8. `linkPatient()`: collega una prenotazione senza verifica a una scheda esistente o a una nuova, su scelta della segreteria.
5. **`BookingConfigService`** (nuovo, generalizza il modello di `WhatsAppBookingConfig`: default, normalize, validate): legge la configurazione del percorso da `dap53` (sezione 4.9), la normalizza (sezione 4.11), la valida con le regole della sezione 4.7 e costruisce la sequenza dei passi (sezione 4.6). Metodi: `pubblicata()`, `bozza()`, `salvaBozza()`, `pubblica()`, `ripristina()`, `versioni()`, `normalizza()`, `valida()`, `costruisciSequenza()`, `perCatalogo()` (solo i campi pubblici). Lo stato del servizio (`attiva`, `sospesa`), il `tenant_key` e gli indirizzi degli avvisi stanno in `platform_patient_booking_configs` (sezione 6.2).
6. **`StudioLogoService`** (nuovo): controlli, pulizia degli SVG, ridimensionamento e servizio del logo (sezione 4.8). Riusa la libreria di immagini già presente nel gestionale, se c'è (GD o Imagick).
7. **`BookingNotificationService`**: estende `AgendaAppointmentNotificationService` con gli eventi della sezione 7. Sceglie il canale per ogni paziente: email se c'è, SMS o WhatsApp verso il cellulare solo se verificato, confermato dalla segreteria o lasciato come recapito senza verifica (sezione 5.10). Gancio anche in `rest/app/Controllers/Agenda.php` (`eliminaAppuntamento`, riga 4435) così gli annullamenti fatti dalla segreteria avvisano il paziente.
8. **`IcsCalendarService`**: VCALENDAR con VTIMEZONE `Europe/Rome` (sezione 7.4).
9. **`TurnstileVerifier`**: chiamata a siteverify con i controlli della sezione 5.6, con l'`action` attesa passata da chi lo chiama.

Il tenant si ricava da `tenant_key` con `TenantCatalogService::getTenantById` e `TenantDatabaseConnector::connect`, sul modello di `WhatsAppBookingAgenda::forTenant`, senza passare dal login multi-tenant. Un `tenant_key` inesistente o con prenotazione spenta risponde 404 con la pagina dello studio non attivo, mai con un errore tecnico.

### 6.2 Tabelle (DDL indicativo, MySQL 8)

I numeri `dap50`, `dap51`, `dap52` sono indicativi: usa i primi liberi (nel dump demo l'ultimo è `dap49_dot_spec`). Le tabelle della configurazione e del logo (`dap53`, `dap54`) sono nella sezione 4.9. Le tabelle `dapNN` vanno nel database di ogni studio, le `platform_*` nel database della piattaforma. Le migrazioni devono essere idempotenti (`CREATE TABLE IF NOT EXISTS`).

```sql
-- Database dello studio: una riga per ogni prenotazione nata online
CREATE TABLE IF NOT EXISTS dap50_prenotazioni_online (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_appuntamento       BIGINT NOT NULL,                 -- dap12_agenda_appuntamenti
  booking_ref           CHAR(6) NOT NULL,                -- es. K7P2QH, alfabeto senza 0 O 1 I
  canale                ENUM('web','pwa','whatsapp','invito') NOT NULL DEFAULT 'web',
  id_client             INT NULL,                        -- chi riceve la visita (null finche' in attesa o non collegato)
  id_client_prenotante  INT NULL,                        -- chi ha prenotato, se diverso
  verificato            TINYINT(1) NOT NULL DEFAULT 1,   -- 0 per le prenotazioni senza verifica (sezione 5.10)
  verificato_con        ENUM('whatsapp','email','sms') NULL, -- null se non verificato
  recapito_tipo         ENUM('cellulare','email','entrambi','nessuno') NOT NULL DEFAULT 'cellulare',
  phone_hash            CHAR(64) NULL,                   -- HMAC-SHA256 del cellulare indicato (verificato solo con WhatsApp o SMS)
  email_hash            CHAR(64) NULL,                   -- HMAC-SHA256 dell'email indicata, per i limiti per recapito
  identita_hash         CHAR(64) NULL,                   -- HMAC-SHA256 del canale verificato (numero o email); null senza verifica
  stato_richiesta       ENUM('in_attesa','confermata','rifiutata','annullata','scaduta') NOT NULL,
  dati_richiesta        TEXT NULL,                       -- dati del paziente nuovo, cifrati, cancellati all'approvazione
  config_revisione      INT UNSIGNED NULL,               -- revisione della configurazione usata (dap53)
  manage_token_hash     CHAR(64) NOT NULL,
  token_expires_at      DATETIME NOT NULL,
  ics_sequence          INT NOT NULL DEFAULT 0,
  cancel_reason         VARCHAR(40) NULL,
  cancelled_by          ENUM('paziente','studio','sistema') NULL,
  esito_presenza        ENUM('presente','assente') NULL,
  idempotency_key       CHAR(36) NOT NULL,
  request_expires_at    DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_dap50_ref (booking_ref),
  UNIQUE KEY ux_dap50_idem (idempotency_key),
  UNIQUE KEY ux_dap50_app (id_appuntamento),
  KEY idx_dap50_phone_stato (phone_hash, stato_richiesta),
  KEY idx_dap50_email_stato (email_hash, stato_richiesta),
  KEY idx_dap50_identita (identita_hash, stato_richiesta),
  KEY idx_dap50_scadenza (stato_richiesta, request_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Database dello studio: stato delle identita' verificate o dei recapiti indicati (numero o email)
CREATE TABLE IF NOT EXISTS dap51_prenotazioni_online_numeri (
  identita_hash  CHAR(64) NOT NULL,
  tipo           ENUM('telefono','email') NOT NULL,
  stato          ENUM('normale','richiede_approvazione','bloccato') NOT NULL DEFAULT 'normale',
  assenze        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  nota_studio    VARCHAR(255) NULL,                      -- mai dati sanitari
  updated_by     INT NULL,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (identita_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Database dello studio: dispositivi riconosciuti e link di invito
CREATE TABLE IF NOT EXISTS dap52_prenotazioni_online_accessi (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo          ENUM('sessione','invito','link_accesso') NOT NULL,
  token_hash    CHAR(64) NOT NULL,
  identita_hash CHAR(64) NULL,
  verificato_con ENUM('whatsapp','email','sms') NULL,
  id_client     INT NULL,                                -- per gli inviti
  vincoli_json  JSON NULL,                               -- prestazione o professionista dell'invito
  usato_at      DATETIME NULL,
  expires_at    DATETIME NOT NULL,
  revoked_at    DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_dap52_token (token_hash),
  KEY idx_dap52_identita (identita_hash, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Database della piattaforma: link pubblico e stato del servizio per studio.
-- La configurazione del percorso sta nel database dello studio (dap53, sezione 4.9).
CREATE TABLE IF NOT EXISTS platform_patient_booking_configs (
  id_tenant             INT NOT NULL,
  tenant_key            VARCHAR(40) NOT NULL,            -- slug pubblico del link
  attiva                TINYINT(1) NOT NULL DEFAULT 0,   -- servizio acceso dal team AF
  sospesa               TINYINT(1) NOT NULL DEFAULT 0,   -- "Sospendi le prenotazioni online" dello studio
  sospesa_da            INT NULL,
  sospesa_at            DATETIME NULL,
  revisione_pubblicata  INT UNSIGNED NULL,               -- copia di dap53 per invalidare le cache; la fonte resta dap53
  avvisi_json           JSON NULL,                       -- chi riceve gli avvisi: indirizzi e professionisti (gestito dallo studio)
  updated_by            INT NULL,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_tenant),
  UNIQUE KEY ux_ppbc_key (tenant_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Database della piattaforma: sessioni di verifica del flusso paziente (separate da dap16_auth_code)
CREATE TABLE IF NOT EXISTS platform_patient_verifiche (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_tenant     INT NOT NULL,
  ref           CHAR(6) NOT NULL,                        -- codice pratica, diventa il booking_ref se la prenotazione va a buon fine
  metodo        ENUM('whatsapp','email','sms') NOT NULL,
  scopo         ENUM('prenotazione','accesso') NOT NULL,
  phone_hash    CHAR(64) NULL,                           -- cellulare indicato: con WhatsApp deve coincidere con il mittente, con l'SMS e' il destinatario
  email_hash    CHAR(64) NULL,                           -- l'indirizzo email non si conserva: si usa per l'invio e basta
  code_hash     CHAR(64) NOT NULL,                       -- HMAC del codice (4 cifre WhatsApp, 6 cifre email e SMS), mai in chiaro
  browser_hash  CHAR(64) NOT NULL,                       -- lega la sessione al browser che l'ha avviata
  stato         ENUM('in_attesa','verificata','usata','scaduta','bloccata') NOT NULL DEFAULT 'in_attesa',
  esito         ENUM('numero_diverso') NULL,
  tentativi     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  invii         TINYINT UNSIGNED NOT NULL DEFAULT 1,     -- invii e rinvii di email o SMS per questa sessione
  ultimo_invio_at DATETIME NULL,                         -- per i 60 secondi prima di "Rimanda il codice"
  bloccato_fino DATETIME NULL,
  expires_at    DATETIME NOT NULL,
  verified_at   DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_ppv_ref (id_tenant, ref),
  KEY idx_ppv_lookup (id_tenant, stato, expires_at),
  KEY idx_ppv_phone (id_tenant, phone_hash, created_at),
  KEY idx_ppv_metodo_giorno (id_tenant, metodo, created_at) -- tetto giornaliero di email e SMS
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Database della piattaforma: eventi per limiti, anomalie e funnel (senza dati personali)
CREATE TABLE IF NOT EXISTS platform_patient_booking_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_tenant   INT NOT NULL,
  tipo        VARCHAR(40) NOT NULL,                      -- verifica_avviata, verifica_negata, email_inviata, sms_inviato, sms_tetto, sms_errore, whatsapp_errato, trappola, richiesta, prenotazione, prenotazione_non_verificata, config_pubblicata, anomalia...
  identita_hash CHAR(64) NULL,
  ip_hash     CHAR(64) NULL,
  device_hash CHAR(64) NULL,                             -- cookie di dispositivo, per i limiti senza verifica
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ppbe_tenant_tipo (id_tenant, tipo, created_at),
  KEY idx_ppbe_device (id_tenant, tipo, device_hash, created_at),
  KEY idx_ppbe_ip (tipo, ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `phone_hash`, `email_hash`, `identita_hash`, `ip_hash` e `device_hash` sono HMAC-SHA256 con una chiave di piattaforma (variabile `PATIENT_BOOKING_HASH_KEY`), così numeri e indirizzi non stanno in chiaro nelle tabelle di servizio. Il cellulare in chiaro resta solo dove il gestionale già lo tiene (cifrato) in `dap02` e `dap12`.
- Conservazione: `platform_patient_verifiche` cancellata a scadenza più 1 giorno; `platform_patient_booking_events` 90 giorni; `dap52` alla scadenza più 30 giorni; `dati_richiesta` svuotato all'approvazione, al rifiuto o alla scadenza.
- Nessun `ALTER` a mano in produzione: tutto passa dalle migrazioni del gestionale.

### 6.3 Endpoint del paziente

Tutti in JSON, prefisso `/api/prenota/{tenant_key}`, con il filtro CSRF della sezione 6.5 sulle scritture e `Cache-Control: no-store` sulle risposte con dati personali. Gli errori hanno sempre la forma `{"errore": "codice_macchina", "messaggio": "Testo per il paziente"}`. Con la firma di anteprima valida (sezione 4.2) tutte le scritture rispondono 403 `anteprima`.

**`GET /catalogo`**: configurazione pubblicata (solo i campi pubblici, sezione 4.11) con la sua revisione, prestazioni online, professionisti (solo nome, qualifica, iniziali o foto, sedi; nessun contatto personale), sedi, regole pubbliche, contatti dello studio.

```json
{
  "studio": {"nome": "Poliambulatorio Santa Chiara", "telefono": "0571 123 456", "whatsapp_numero": "333 123 4567", "privacy_url": "https://poliambulatoriosantachiara.it/privacy"},
  "attivo": true,
  "revisione": 7,
  "config": {
    "aspetto": {"logo_url": null, "logo_alt": "Poliambulatorio Santa Chiara", "mostra_nome_con_logo": true},
    "testi": {"benvenuto_titolo": null, "benvenuto_sottotitolo": null, "nota_riepilogo": null},
    "passi": [{"id": "prestazione", "attivo": true}, {"id": "professionista", "attivo": true}, {"id": "sede", "attivo": true}, {"id": "orario", "attivo": true}, {"id": "perchi", "attivo": true}, {"id": "verifica", "attivo": true}, {"id": "dati", "attivo": true}, {"id": "riepilogo", "attivo": true}],
    "scelte": {"primo_disponibile": true, "salta_professionista_se_unico": true, "salta_sede_se_unica": true, "prestazione_predefinita": null, "professionista_predefinito": null},
    "verifica": {"richiesta": true, "metodi": ["whatsapp", "email"]},
    "senza_verifica": {"recapito": "almeno_uno"},
    "dati": {"cellulare": "obbligatorio", "email": "facoltativo", "nascita": "nascosto", "codice_fiscale": "nascosto", "note": "facoltativo"},
    "regole": {"preavviso_modifica_ore": 24, "spostamento_consentito": true}
  },
  "prestazioni": [{"id": 12, "nome": "Visita dermatologica", "specialita": "Dermatologia", "durata_min": 30, "prezzo": "100 €", "professionisti": [7]}],
  "professionisti": [{"id": 7, "nome": "Dott.ssa Elena Conti", "qualifica": "Dermatologa", "sedi": [1, 2], "prestazioni": [12]}],
  "sedi": [{"id": 1, "nome": "Empoli", "indirizzo": "Via Roma 18, 50053 Empoli (FI)"}, {"id": 2, "nome": "Vinci", "indirizzo": "Piazza della Libertà 3, 50059 Vinci (FI)"}]
}
```

**`GET /disponibilita?prestazione=12&professionista=7&sede=1&dal=2026-11-09&al=2026-11-15`** (oppure `prima_disponibilita=1`; `professionista=primo` o assente quando la scelta è del sistema): nessuna scrittura, nessun lock. Cache di 30 secondi lato server per combinazione.

```json
{
  "giorni": {"2026-11-09": "nessuno", "2026-11-10": "pochi", "2026-11-11": "nessuno", "2026-11-12": "posti", "2026-11-13": "posti", "2026-11-14": "chiuso", "2026-11-15": "chiuso"},
  "orari": {"2026-11-12": [{"slot": "s_88213", "inizio": "17:30", "fine": "18:00", "professionista": 7, "sede": 1}]},
  "primo_libero": {"data": "2026-11-03", "ora": "09:00"}
}
```

Il campo `slot` è un identificativo opaco firmato (HMAC di `id_slots`, professionista, sede, giorno e ora), non l'id interno: così il client non può inventare slot.

**`POST /verifica/avvia`**: avvia la verifica con uno dei metodi accesi. Per WhatsApp:

```json
{"metodo": "whatsapp", "telefono": "+393478126650", "scopo": "prenotazione", "turnstile_token": "0.AbCd...", "sito_web": ""}
```

Risposta 200:

```json
{"verifica": "v_9f2c...", "metodo": "whatsapp", "scade_tra_sec": 600, "ref": "K7P2QH", "messaggio": "Confermo la prenotazione K7P2QH, codice di verifica 4821", "whatsapp_url": "https://wa.me/393331234567?text=Confermo%20la%20prenotazione%20K7P2QH%2C%20codice%20di%20verifica%204821", "numero_studio": "333 123 4567"}
```

Per l'email il corpo aggiunge `"email": "rosa.bianchi@email.it"` e la risposta è `{"verifica": "v_9f2c...", "metodo": "email", "scade_tra_sec": 600, "rinvio_tra_sec": 60}`. Per l'SMS il corpo ha `"metodo": "sms"` e il telefono, e la risposta è `{"verifica": "v_9f2c...", "metodo": "sms", "scade_tra_sec": 600, "rinvio_tra_sec": 60}`. La risposta ha la stessa forma per un numero in archivio, nuovo o bloccato (per un numero bloccato la sessione non si aggancia mai a nessun messaggio e nessun codice parte). Errori: 422 `numero_non_valido`, 422 `email_non_valida` (anche domini usa e getta), 422 `verifica_non_riuscita` (Turnstile), 422 `metodo_non_disponibile` (metodo spento, WhatsApp non collegato, tetto SMS raggiunto, credito SMS finito, errore del provider), 429 `troppe_verifiche`, 403 `prenotazioni_sospese`.

**`GET /verifica/{id}/stato`**: `{"stato": "in_attesa" | "verificata" | "scaduta"}`, più `"esito": "numero_diverso"` quando il messaggio WhatsApp è arrivato da un altro numero. Nessun effetto collaterale, `Cache-Control: no-store`, risponde solo al browser che ha avviato la verifica. È l'endpoint che la pagina interroga mentre aspetta il messaggio WhatsApp.

**`POST /verifica/{id}/codice`** (email e SMS)

```json
{"codice": "482913"}
```

Risposta 200 `{"stato": "verificata"}`. Errori: 422 `codice_errato` con `tentativi_rimasti`, 429 `bloccato_temporaneamente` con `riprova_tra_sec`, 410 `codice_scaduto`. `POST /verifica/{id}/rimanda` rimanda l'email o l'SMS (non prima di 60 secondi, conta nei limiti; per l'SMS il corpo porta un `turnstile_token` nuovo).

**`POST /verifica/{id}/completa`**: quando lo stato è `verificata`, imposta il cookie `af_pz_{tenant_key}` e consuma la sessione di verifica (una volta sola). Risposta 200:

```json
{"verificato": true, "verificato_con": "whatsapp", "paziente": {"noto": false}, "persone": []}
```

Per un paziente in archivio (riconosciuto dal numero WhatsApp o SMS, o dall'email, sezione 5.3): `{"verificato": true, "verificato_con": "sms", "paziente": {"noto": true, "nome": "Davide"}, "persone": [{"id": "p_1", "nome": "Davide"}, {"id": "p_2", "nome": "Franco"}]}` (solo nomi propri, id opachi). Errori: 409 `non_verificata`, 410 `verifica_scaduta`.

**`POST /riserva`** (sessione obbligatoria; non esiste senza verifica)

```json
{"config_revisione": 7, "scelte": {"prestazione": 12, "professionista": 7, "sede": 1, "slot": "s_88213", "per": {"tipo": "me"}}}
```

Risposta 201: `{"riserva": "r_9f2c...", "scade_at": "2026-10-21T21:48:00+02:00"}`. Errori: 401 `sessione_assente`, 409 `slot_non_disponibile` con `alternative` (3 orari), 409 `configurazione_cambiata` con `passo`, 422 `limite_prenotazioni`, 422 `prestazione_gia_prenotata`, 422 `sovrapposizione`, 403 `prima_visita_al_telefono` (modalità `archivio`).

**`DELETE /riserva/{token}`**: 204.

**`POST /prenotazioni`** (con la verifica attiva serve la sessione; senza verifica il corpo e le regole sono quelli della sezione 5.10. Header `Idempotency-Key: 6f1c2a3e-...`. `canale_promemoria` è `whatsapp`, `sms` o `email`, solo tra quelli attivi per lo studio e, per WhatsApp e SMS, solo verso un numero verificato, confermato dalla segreteria o lasciato come recapito senza verifica)

```json
{
  "config_revisione": 7,
  "riserva": "r_9f2c...",
  "per": {"tipo": "me"},
  "paziente": {"nome": "Rosa", "cognome": "Bianchi", "email": "rosa.bianchi@email.it"},
  "nota": "",
  "privacy_letta": true,
  "canale_promemoria": "whatsapp"
}
```

Risposta 201:

```json
{"ref": "K7P2QH", "stato": "in_attesa", "verificato": true, "quando": "giovedì 12 novembre 2026 alle 17:30", "scade_richiesta_at": "2026-10-22T21:46:00+02:00", "gestisci_url": "/prenota/santa-chiara/p/K7P2QH?t=..."}
```

Con paziente in archivio o modalità aperta `"stato": "confermata"` e in più `"ics_url"`. Errori: 409 `slot_non_disponibile` con `alternative`, 409 `configurazione_cambiata` con `passo`, 410 `riserva_scaduta`, 422 `dati_non_validi` con `campi` (`{"email": "Controlla l'indirizzo email."}`), 422 limiti come sopra.

**Gestione**: `GET /prenotazioni/mie` (sessione), `GET /prenotazioni/{ref}?t=`, `POST /prenotazioni/{ref}/sposta` (`{"slot": "s_...", "t": "..."}`, header `Idempotency-Key`), `POST /prenotazioni/{ref}/annulla` (`{"motivo": "impegno", "t": "..."}`), `GET /prenotazioni/{ref}/calendario.ics?t=`, `POST /contatto` (richiesta alla segreteria, generalizza `platform_whatsapp_booking_requests` con `kind=handoff`, `channel=web`).

Le richieste GET non modificano mai nulla: gli scanner delle caselle email aprono i link in anticipo. Spostare, annullare e confermare richiedono una POST dalla pagina.

### 6.4 Endpoint dello studio (gestionale, login obbligatorio)

- `GET spazio/prenotazione-online`: la pagina dello studio (sezione 9). `GET spazio/prenotazione-online/percorso`: riepilogo in sola lettura della configurazione pubblicata (revisione, data, ordine dei passi, metodi di verifica, chi può prenotare, dati richiesti, limiti, logo) e consumo degli SMS di verifica di oggi e del mese, se il metodo è acceso. La configurazione si cambia solo dall'editor del team AF (sezione 4.10).
- `GET spazio/prenotazione-online/richieste`: richieste `IN_ATTESA` con scadenza, con il badge "Non verificato" e l'indizio di corrispondenza in archivio per quelle senza verifica.
- `POST spazio/prenotazione-online/richieste/{id}/conferma` e `.../rifiuta` (`{"motivo": "..."}`): riusano `WhatsAppBookingService::resolve`. Va gestita `WhatsAppBookingUnavailable`, che oggi alla riga 148 resta non gestita. Per una richiesta senza verifica la conferma porta `{"collega": {"id_client": 123}}` oppure `{"collega": "nuova"}`.
- `POST spazio/prenotazione-online/appuntamenti/{id}/collega` (`{"id_client": 123}` oppure `{"nuova": true}`): collega a una scheda un appuntamento senza verifica già confermato (modalità `aperta`).
- `POST spazio/prenotazione-online/numeri/{identita_hash}/rifiuta-tutte`, `.../blocca`, `.../sblocca`, `.../approvazione`.
- `POST spazio/prenotazione-online/sospendi` e `.../riattiva`.
- `POST spazio/prenotazione-online/inviti` (`{"id_client": 123, "prestazione": 12, "giorni": 14}`): crea il link di invito e lo manda al paziente.
- `POST spazio/prenotazione-online/appuntamenti/{id}/assente`.
- `GET` e `POST spazio/prenotazione-online/avvisi`: chi riceve gli avvisi (`avvisi_json`), "Invia una prova a me".

Tutte con il filtro CSRF dell'area spazio e il controllo del ruolo (segreteria o amministratore dello studio). Gli endpoint dell'editor del team AF sono nella sezione 4.10.

### 6.5 Filtri CSRF e throttle

- **CSRF.** Nuovo alias `patientbookingcsrf` in `rest/app/Config/Filters.php`, classe sul modello di `WhatsAppBookingCsrfFilter` (estende `CodeIgniter\Filters\CSRF` e ripropaga il cookie nella risposta). Applicato con `before` e `after` su `prenota/*` e `api/prenota/*` (la GET della pagina emette il cookie, le POST lo verificano). Il client manda il token nell'header `X-CSRF-TOKEN`. L'editor `af/prenotazione-online/*` usa il filtro CSRF dell'area amministrativa più il filtro di ruolo `afsuperadmin`.
- **Throttle.** Filtro `patientbookingthrottle` che usa `service('throttler')`: 60 richieste al minuto per IP su `api/prenota/*` in lettura, 20 al minuto in scrittura. I limiti specifici di verifiche, SMS e prenotazioni stanno nei servizi (sezione 5.4), non solo nel filtro, perché devono contare per numero, per dispositivo e per studio.
- **IP reale.** Il gestionale sta dietro proxy: configurare `Config\App::$proxyIPs` con gli IP del proxy di Coolify così `getIPAddress()` restituisce l'IP del client e non quello del proxy. Senza questo, il limite per IP blocca tutti.
- **Header.** Sulle pagine `prenota/*`: `Content-Security-Policy` con `script-src 'self' https://challenges.cloudflare.com` e `frame-src https://challenges.cloudflare.com`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Robots-Tag: noindex` sulle pagine di gestione, sui link con token e sull'anteprima della bozza. La pagina pubblica deve poter stare nell'iframe dell'editor solo con la firma di anteprima: `frame-ancestors 'self'` in quel caso, `frame-ancestors 'none'` negli altri.

### 6.6 Lock e idempotenza

- **Ordine dei lock.** `GET_LOCK('prenota-{tenant}-{id_dot}-{data}', 10)` per serializzare per professionista e giorno, poi transazione con `SELECT ... FOR UPDATE` sugli slot del giorno ordinati per `id_slot`. Per lo spostamento si prendono i due lock in ordine di data. Se `GET_LOCK` non arriva in 10 secondi: 503 con "Riprova tra qualche secondo".
- **Controllo finale.** Dentro la transazione si ricalcola la disponibilità con `AgendaAvailabilityService` escludendo il lock `dap14` del paziente stesso; se lo slot non è più libero si fa rollback e si risponde 409 con 3 alternative. Senza verifica non esiste un lock del paziente: il controllo finale è l'unica protezione, ed è sufficiente perché è atomico.
- **Idempotenza.** Il browser genera un UUID v4 per ogni tentativo di conferma e lo riusa nei retry. Il server cerca `idempotency_key` in `dap50` prima e dentro la transazione (come fa oggi `bookLocked` con il marcatore): se esiste, restituisce la stessa risposta senza scrivere. Il vincolo `UNIQUE` su `idempotency_key` è l'ultima difesa.
- **Rilascio.** Alla conferma il lock `dap14` passa a `RILASCIATO`. Le riserve scadute passano a `SCADUTO` con il job della sezione 5.8 e lo slot torna `LIBERO` con `restoreSlotState`.
- **Codice prenotazione.** 6 caratteri dall'alfabeto `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`, generato con `random_int`, nuovo tentativo in caso di collisione sul vincolo `UNIQUE`.
- **Link firmati.** Token casuale di 32 byte, salvato solo come hash SHA-256, legato a prenotazione, tenant e permessi; scade 7 giorni dopo l'appuntamento; si revoca all'annullamento.

## 7. Email e messaggi

### 7.1 Elenco

Le anteprime finali sono in `email/`, i testi (oggetto, preheader, solo testo, SMS e WhatsApp, SMS del codice di verifica, schermate nuove) in `email/testi.md`.

| Codice | Evento | Destinatario | Allegati e link |
|---|---|---|---|
| P1 | Richiesta ricevuta (paziente nuovo in approvazione, o qualunque paziente senza verifica in approvazione) | Paziente | Link firmato per annullare la richiesta, nessun ICS |
| P2 | Prenotazione confermata | Paziente | ICS `METHOD:PUBLISH`, link Google e Outlook, link firmato |
| P3 | Richiesta non accolta (rifiutata o scaduta) | Paziente | Link "Scegli un altro orario" precompilato |
| P4 | Promemoria (predefinito 2 giorni prima) | Paziente | Link firmato, nessun ICS |
| P5 | Prenotazione spostata | Paziente | ICS con stesso UID e `SEQUENCE` più 1 |
| P6 | Prenotazione annullata | Paziente | ICS `METHOD:CANCEL` |
| P7 | Codice di verifica (solo per chi sceglie l'email) | Paziente | Nessun link |
| P8 | Accesso alle tue prenotazioni | Paziente | Link di accesso valido 15 minuti, una volta sola |
| S1 | Nuova prenotazione online | Segreteria | "Apri in agenda" (login obbligatorio) |
| S2 | Richiesta da confermare | Segreteria | "Rivedi la richiesta" (login obbligatorio), nessuna approvazione con un clic dall'email |
| S3 | Spostata dal paziente | Segreteria | Link all'agenda |
| S4 | Annullata dal paziente | Segreteria | Link all'agenda |
| S5 | Avviso di attività anomala sulle prenotazioni online (anche tetto SMS raggiunto) | Segreteria | Link alla pagina "Prenotazione online" con i bottoni Sospendi, Rifiuta, Blocca |

Fase 2: S6 riepilogo giornaliero, S7 messaggi non consegnati, P9 posto liberato dalla lista d'attesa.

Versioni SMS e WhatsApp per P2, P4, P5 e P6, più P1 e P3 per chi prenota senza verifica lasciando solo il cellulare: massimo 160 caratteri GSM-7, niente apostrofo tipografico, niente emoji, link brevi su un dominio ambulatoriofacile.it (mai servizi di accorciamento di terzi). L'SMS del codice di verifica (solo per chi sceglie l'SMS) non ha link: testo nella sezione 5.6 e in `email/testi.md`.

### 7.2 Mittente e intestazioni

- From: `"Poliambulatorio Santa Chiara tramite Ambulatorio Facile" <prenotazioni@ambulatoriofacile.it>`, cioè `"{Nome studio} tramite Ambulatorio Facile"`. Il dominio resta `@ambulatoriofacile.it` come vuole `TenantNotificationPolicyService.php` (righe 11-12 e 370).
- Reply-To: l'email della segreteria dello studio. Oggi è forzato a noreply (`AppointmentNotificationChannelService.php` riga 480 e policy riga 257): va reso configurabile, il paziente deve poter rispondere allo studio.
- Header `Auto-Submitted: auto-generated`. SPF, DKIM e DMARC allineati su `ambulatoriofacile.it`, anche per gli SMTP dedicati degli studi.
- HTML più versione solo testo (AltMessage) con `setMailType('html')`, come `BillingDocumentEmailService.php` righe 118-124. Togliere l'a capo forzato a 76 colonne (`Config/Email.php` riga 80).
- Template in `rest/app/Views/emails/booking/_layout.php` più una view per evento e la gemella `_text.php`. Le anteprime HTML di `email/` sono il riferimento esatto per il markup.

### 7.3 Regole di conformità (per ogni messaggio)

1. Oggetto, preheader, SMS, WhatsApp, push e titolo ICS: mai prestazione, specialità, qualifica del professionista, motivo della visita, note. Il nome del paziente non compare mai nell'oggetto delle email allo studio.
2. Corpo: dati minimi. La prestazione compare solo se lo studio accende "Mostra il nome della prestazione nei messaggi" (`comunicazioni.mostra_prestazione_nei_messaggi`, spento di default). Le note e il motivo della visita non compaiono mai, nemmeno allo studio: "Il paziente ha lasciato una nota: leggila nel gestionale".
3. Testata senza loghi: nome dello studio in testo più "Prenotazione online". Footer in due blocchi: logo e dati del titolare (ragione sociale, indirizzo, telefono, email, link all'informativa dello studio); poi il logo Ambulatorio Facile piccolo e la riga "Prenotazioni gestite con Ambulatorio Facile per conto di {Ragione sociale}, titolare del trattamento dei dati." Il logo dello studio è il PNG per le email della sezione 4.8, preso dalla configurazione pubblicata; senza logo il blocco mostra solo i dati del titolare.
4. Messaggi strettamente di servizio: niente promozioni. Il link "Preferenze" spegne solo i promemoria; conferme e annullamenti restano.
5. Logo Ambulatorio Facile per le email: `https://ambulatoriofacile.it/images/email/logo-green.png` (420x89, mostrato a 120x25 circa). Logo dello studio: URL assoluto https, PNG trasparente, mai data URI.
6. Il registro invii conserva destinatario, esito e id appuntamento, senza note, per 12 mesi. I file `.jsonl` si sostituiscono con una tabella.
7. Fornitori da indicare nel registro dei trattamenti dello studio: SMTP, provider SMS (promemoria e, se lo studio accende il metodo, codice di verifica), gateway WhatsApp, Cloudflare (Turnstile). UltraMsg con istanza fissa (`AppointmentNotificationChannelService.php` riga 12) va dismesso.
8. Si toglie `{{note}}` dal template di default (`AppointmentMessageTemplateService.php` riga 51). Nuovi token: `{{link_gestione}}`, `{{telefono_studio}}`, `{{nome_studio}}`, `{{codice_prenotazione}}`, `{{data_estesa}}`.
9. La notifica allo studio non passa mai per la tabella degli OTP di login (`dap16_auth_code`). `AgendaCrossDoctorBookingNotificationService.php` è codice morto e va eliminato.
10. Il codice di verifica viaggia per email (P7) oppure per SMS, solo se lo studio ha acceso il metodo SMS (testo fisso della sezione 5.6, senza link). La verifica WhatsApp parte dal paziente (sezione 5.6) e non ha testo da inviare. P7, P8 e l'SMS del codice non hanno il link "Preferenze": sono messaggi di sicurezza che arrivano solo su richiesta del destinatario.
11. Oggetto con il nome dello studio: se supera i 60 caratteri (P7 e P8 sono i più esposti con nomi lunghi) si usa il "nome nei messaggi" breve dello studio (`comunicazioni.nome_nei_messaggi`, massimo 24 caratteri, impostato dal team AF nell'editor, per impostazione uguale al nome dello studio). Lo stesso vale per l'SMS del codice se il nome lo porta oltre i 160 caratteri. Se il nome contiene una specialità (per esempio "Studio Dermatologico ...") si può scegliere un nome neutro: l'oggetto compare nelle anteprime a telefono bloccato.
12. Il motivo di annullamento in S4 viene da un elenco chiuso; "Sto meglio" non si riporta mai (compare come "Altro motivo"), perché dice qualcosa sulla salute.
13. Link brevi e link lunghi: nelle email e negli SMS i link hanno la forma breve `https://ambulatoriofacile.it/p/{ref}?t={token}` (e `/p/{ref}/calendario`, `/p/{ref}/come-arrivare`, `/p/preferenze`, `/p/accesso`). Il token inizia con 4 caratteri non segreti che identificano lo studio, seguiti da 32 byte casuali: così il gestionale sceglie il database dello studio senza cercarli tutti, poi risponde con un redirect 302 a `/prenota/{tenant_key}/p/{ref}?t={token}` (sezione 3.1). Il link `?riprendi={ref}&t={token}` apre il flusso con prestazione, professionista e sede già scelti, mai con dati personali.
14. Nelle anteprime di `email/` i token (`e8Zq4mT1`) e i percorsi dell'area studio sono segnaposto. Da mappare sulle rotte reali (sezione 6.4): `/app/prenotazioni-online/richieste` e `/app/prenotazioni-online/richieste/{ref}` aprono `spazio/prenotazione-online` sulla tab "Richieste e numeri"; `/app/prenotazioni-online/impostazioni` apre la stessa tab, dove c'è l'interruttore "Sospendi"; `#avvisi` punta alla tab "Avvisi"; `/app/agenda?prenotazione={ref}` apre l'agenda sull'appuntamento. Tutti richiedono il login: dalle email allo studio non si approva mai nulla.
15. Prenotazione senza verifica: le email partono solo se il paziente ha lasciato l'email; con il solo cellulare, conferme e cambi partono per SMS o WhatsApp se lo studio li ha. S1 e S2 riportano "(non verificato)" accanto ai contatti. Dettagli in `email/testi.md`.

### 7.4 ICS (RFC 5545)

```
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Ambulatorio Facile//Prenotazioni//IT
METHOD:PUBLISH
BEGIN:VTIMEZONE
TZID:Europe/Rome
... (regole CET/CEST)
END:VTIMEZONE
BEGIN:VEVENT
UID:K7P2QH@santa-chiara.ambulatoriofacile.it
SEQUENCE:0
DTSTAMP:20261021T194500Z
DTSTART;TZID=Europe/Rome:20261112T173000
DTEND;TZID=Europe/Rome:20261112T180000
SUMMARY:Appuntamento presso Poliambulatorio Santa Chiara
LOCATION:Via Roma 18\, 50053 Empoli (FI)
DESCRIPTION:Gestisci la prenotazione: https://...\nTelefono dello studio: 0571 123 456
STATUS:CONFIRMED
END:VEVENT
END:VCALENDAR
```

- Nessuna prestazione in `SUMMARY` o `DESCRIPTION`.
- Spostamento: stesso `UID`, `SEQUENCE` più 1 (`ics_sequence` in `dap50`). Annullamento: `METHOD:CANCEL`, `STATUS:CANCELLED`, stesso `UID`, `SEQUENCE` più 1.
- Righe terminate da CRLF e ripiegate a 75 ottetti. Allegato con `Content-Type: text/calendar; charset=utf-8; method=PUBLISH` (per l'annullamento `method=CANCEL`, coerente con la riga `METHOD`).
- Limite noto: con `METHOD:PUBLISH` e `CANCEL` senza `ORGANIZER` e `ATTENDEE`, Apple Calendar e Google aggiornano o tolgono l'evento se hanno lo stesso `UID`, mentre Outlook desktop può tenerne una copia. Per questo P5 e P6 dicono al paziente cosa fare. Non aggiungere `ATTENDEE` a un `PUBLISH`: non è valido.

## 8. PWA per studio

- Nuovo `PwaController::patientManifest($tenantKey)` servito a `/prenota/{tenant_key}/manifest.webmanifest`: `id` e `scope` `/prenota/{tenant_key}/`, `start_url` `/prenota/{tenant_key}/le-mie-prenotazioni?source=pwa` (oppure `/prenota/{tenant_key}?source=pwa` se lo studio non ha nessun metodo di verifica acceso e quindi non ha "Le mie prenotazioni"), `name` uguale al nome dello studio, `short_name` di massimo 12 caratteri, `display: standalone`, `theme_color` `#FFFFFF`, `background_color` `#F6F8FA`.
- Icone generate dal logo dello studio della configurazione pubblicata (sezione 4.8) su fondo bianco (192, 512 e maskable 512); senza logo, il monogramma (quadrato arrotondato navy `#08364F` con le iniziali bianche). Si rigenerano alla pubblicazione di un logo nuovo.
- Service worker a `/prenota/{tenant_key}/sw.js` con lo stesso scope: shell in cache, API prima in rete, prenotazioni già scaricate leggibili offline in sola lettura, pagina offline con il banner "Sei offline: le prenotazioni salvate restano visibili". Nessun dato personale nella cache oltre alle prenotazioni del dispositivo verificato; "Esci" svuota la cache. Il catalogo in cache si scarta quando cambia la revisione.
- Installazione: card mostrata dopo la conferma o alla seconda visita, mai alla prima. Android e Chrome con `beforeinstallprompt`; iOS con la guida in tre passi (Condividi, Aggiungi alla schermata Home, Apri l'app); Samsung Internet con istruzioni dedicate. In standalone la card non compare. "Non ora" la nasconde per 30 giorni (`localStorage`).
- Push (Fase 2): riuso del gestore di `sw.js` della radice (righe 157-228), senza la logica OTP di `rest/public/sw.js`.
- Pulizia: eliminare il manifest duplicato `rest/public/manifest.json` ("AgendaFlow") e il secondo theme-color in `login.php` riga 35.

## 9. Pagina dello studio nel gestionale

Rotta `spazio/prenotazione-online`, ruolo segreteria o amministratore dello studio, tavola A01 nel canvas. Lo studio non cambia più le regole del percorso: le decide il team AF con l'editor della sezione 4. Qui lo studio fa le cose operative e vede come è configurata la sua pagina.

1. **Richieste e numeri.** In alto l'interruttore "Sospendi le prenotazioni online", in evidenza. Elenco delle richieste da confermare con scadenza, "Conferma" e "Non accogliere" con motivo. Le richieste senza verifica hanno il badge "Non verificato", l'eventuale indizio "Il recapito corrisponde a un paziente in archivio" e la scelta "Collega a questa scheda" o "Crea una scheda nuova" alla conferma. Elenco dei numeri con stato (richiede approvazione, bloccato) e azioni ("Rifiuta tutte le richieste di questo numero", "Blocca questo numero", "Sblocca"). Banner di attività anomala quando scatta S5.
2. **Link e QR.** Link da copiare, QR generato in locale e scaricabile in PNG e in PDF A5 da sala d'attesa, creazione di inviti personali.
3. **Percorso di prenotazione** (sola lettura). Riepilogo della configurazione pubblicata: revisione e data, logo, ordine dei passi mostrati, "Il primo disponibile" sì o no, chi può prenotare, metodi di verifica (per esempio "Canali di verifica: WhatsApp (consigliato), Email" oppure, con l'SMS acceso, "WhatsApp (consigliato), Email, SMS (a consumo, massimo 50 al giorno)", oppure "Prenotazione senza verifica"), dati richiesti, limiti, preavvisi, prestazioni e professionisti online. Con l'SMS acceso: SMS di verifica inviati oggi e nel mese. In fondo la frase "Per cambiare il percorso di prenotazione scrivi all'assistenza di Ambulatorio Facile" con il contatto dell'assistenza.
4. **Avvisi.** "Chi riceve gli avvisi" (indirizzi e professionisti), anteprima reale delle email desktop e mobile, "Invia una prova a me".

Le assenze si segnano dall'agenda ("Non si è presentato"), come nella sezione 5.5.

## 10. Piano di lavoro

### Fase 0: sicurezza (prima di tutto, rilasciabile da sola)

Le 10 correzioni della sezione 2.2.

Definition of Done: ogni correzione ha un test di regressione che fallisce sul codice di oggi e passa dopo; `/api/doctors` non restituisce più email e cellulari; nessun `print_r` di dati personali nei log; codice fisso rimosso; filtro CSRF attivo su `prenotazioni/*`.

### MVP

La configurazione per studio e la costruzione dinamica dei passi entrano nell'MVP: il frontend non deve mai avere un ordine dei passi scritto nel codice, altrimenti andrebbe riscritto subito dopo.

1. `AgendaAvailabilityService` con `fits()` corretto e fuso `Europe/Rome`.
2. Tabelle delle sezioni 6.2 e 4.9 con migrazioni idempotenti. Configurazione predefinita della sezione 4.3.
3. `BookingConfigService` (normalizzazione, validazione, `costruisciSequenza` in PHP e in JavaScript con i casi condivisi della sezione 4.12), `StudioLogoService`.
4. Editor del team AF (sezione 4): bozza, pubblicazione, cronologia, ripristino, anteprima firmata, logo, validazioni con i messaggi della tabella 4.7.
5. `PatientVerificationService` con WhatsApp ed email (con l'aggancio ai messaggi WhatsApp in entrata), `TurnstileVerifier`, `PatientSessionService` con tutti i limiti della sezione 5. Il campo `metodo` ammette già `sms` nelle tabelle, ma l'invio SMS arriva nella Fase 1b.
6. `PatientBookingService` (hold, book, reschedule, cancel, approve, reject, expire, linkPatient) con lock, idempotenza e rivalidazione della sequenza.
7. Endpoint delle sezioni 6.3, 6.4 e 4.10 con filtri CSRF e throttle.
8. Frontend del flusso (sezione 3) costruito dalla configurazione pubblicata, con selezione automatica, "Il primo disponibile" facoltativo e logo, fedele a prototipo e canvas, a 390, 768 e 1280.
9. Le mie prenotazioni, dettaglio, sposta, annulla, link firmati.
10. Le 13 email (P1-P8, S1-S5) con versioni solo testo e SMS o WhatsApp, ICS.
11. Pagina `spazio/prenotazione-online` (sezione 9) con riepilogo in sola lettura, richieste da confermare, sospendi, rifiuta, blocca, assenze, avvisi.
12. Comando `prenota:scadenze` ogni 5 minuti.
13. PWA per studio installabile con guida iOS.
14. Redirect 301 dalle vecchie rotte.
15. Prenotazione senza registrazione (sezione 5.10), come ultimo blocco dell'MVP. Motivo: Simone e Alessio la vogliono come opzione per gli studi, e con il motore di configurazione già pronto costa poco (un passo in meno, Turnstile alla conferma, i limiti per dispositivo e IP, il badge "Non verificato"). Resta l'ultimo blocco perché toglie la prima difesa: va rilasciata solo con la sua checklist verde, e lo studio pilota parte comunque con la verifica attiva.

Definition of Done dell'MVP:

- Tutta la checklist della sezione 5.11 passa (le voci SMS nella Fase 1b), compresa la checklist senza verifica della sezione 5.10, e tutta quella della sezione 4.12.
- Test automatici (sul modello di `rest/tests/whatsapp_booking_regression.php`, con una porta finta per disponibilità e invii): due prenotazioni concorrenti sullo stesso slot ne fanno passare una sola; spostamento atomico; annullamento fuori preavviso rifiutato; token scaduto rifiutato; richiesta scaduta che libera lo slot; `fits()` con durata 45 su slot da 30; sequenza dei passi identica in PHP e JavaScript su tutti i casi condivisi.
- Nessun errore tecnico visibile al paziente in nessuno stato della tabella 3.4.
- Lighthouse su mobile: accessibilità almeno 95, nessun contrasto sotto 4,5:1, navigazione completa da tastiera, lettore di schermo che annuncia passo, orari e conto alla rovescia.
- Le email passano su Gmail (web e app), Outlook desktop e Apple Mail, chiare e senza dati sanitari in oggetto e preheader.
- Prova su uno studio di test con dati inventati e almeno tre configurazioni diverse (predefinita, professionista prima della prestazione con un solo professionista, senza verifica), poi su uno studio pilota con la modalità `approvazione` e la verifica attiva.

### Fase 1b: verifica via SMS (subito dopo l'MVP)

Invio del codice via SMS con il modulo SMS dei promemoria, registrazione del consumo con tipo `verifica`, tetto giornaliero e avviso S5, rinvio con Turnstile nuovo, tutte le voci SMS della checklist 5.11. Motivo per tenerla fuori dall'MVP: è spenta di default e nessuno studio pilota ne ha bisogno per partire (WhatsApp ed email bastano); in compenso tocca il credito degli studi e va protetta dallo SMS pumping, quindi merita un rilascio a sé, testato da solo. Tabelle, configurazione, editor e frontend la prevedono già dall'MVP: accenderla non richiede migrazioni.

Definition of Done: voci SMS della checklist 5.11 verdi; prova su uno studio di test con credito SMS di prova; nessun SMS reale nei test automatici.

### Fase 2

Lista d'attesa con offerta automatica del posto liberato (P9, riserva di 15 minuti al primo in coda); notifiche push della PWA; widget da incorporare (`/prenota/embed.js`); riepilogo giornaliero (S6) e messaggi non consegnati (S7); assenze su tutti gli appuntamenti; feed calendario in abbonamento; colore dello studio sui bottoni primari (solo se il testo bianco sopra passa 4,5:1); interfaccia in inglese.

Definition of Done: ogni funzione ha la sua tavola nel canvas aggiornata prima dello sviluppo e i suoi test; nessuna regola della sezione 5 viene allentata.

### Fase 3

Richiamo per i controlli periodici come messaggio di servizio legato a un'indicazione dello studio; check-in con QR all'arrivo; visita in video con link; documenti pre-visita, da progettare a parte perché toccano dati sanitari.

Definition of Done: valutazione privacy scritta prima dello sviluppo per ogni funzione che tratta dati sanitari.

### Come lavorare con Codex

- Un ramo e una PR per ogni blocco del piano, nell'ordine: Fase 0, servizi e tabelle, configurazione ed editor, API, frontend, email, pagina dello studio, PWA, senza verifica, poi la Fase 1b con l'SMS.
- Ogni PR cita le sezioni di questa guida che implementa e include i test.
- La funzione che costruisce la sequenza dei passi esiste in PHP e in JavaScript: ogni modifica alla logica aggiorna prima i casi condivisi in `tests/fixtures/prenotazione/sequenza-passi.json` e passa solo se passano entrambe le suite.
- Nel frontend nessun ordine dei passi, nessun metodo di verifica e nessun testo configurabile scritti nel codice: tutto arriva dal catalogo. I testi predefiniti stanno in un solo modulo, copiati dal prototipo.
- Prima di scrivere il frontend, aprire il prototipo alle tre larghezze e copiare testi, spaziature e stati da lì, non inventarli.
- Mai dati reali di pazienti o studi in test, fixture, screenshot o log. Mai SMS reali nei test: sempre la porta finta.
