# Testi delle email di prenotazione online

Oggetto, preheader e versione solo testo di ogni email, più i messaggi SMS e WhatsApp di conferme e promemoria, l'SMS del codice di verifica (solo per gli studi che accendono il metodo SMS) e, in fondo, i testi delle schermate nuove del 22 ottobre e i messaggi dell'editor del team Ambulatorio Facile. Ogni paragrafo sta su una riga sola, senza a capo forzato: la versione testo va in AltMessage senza il wrap a 76 colonne. I dati sono quelli demo (Poliambulatorio Santa Chiara, Rosa Bianchi, giovedì 12 novembre 2026 alle 17:30, codice K7P2QH).

## Regole che valgono per ogni messaggio

- Oggetto: massimo 60 caratteri, mai prestazione, specialità, qualifica del medico, motivo della visita o nome del paziente.
- Preheader: stesse regole dell'oggetto. Fa eccezione P7, dove mostra il codice a 6 cifre.
- La prestazione compare solo in P2 e solo se lo studio ha acceso "Mostra il nome della prestazione nei messaggi" (riga marcata nel codice con il commento "solo se show_service_in_messages"). Nelle email allo studio (S1 a S4) la prestazione e il nome del paziente possono stare nel corpo, mai nell'oggetto.
- La nota del paziente non compare mai nel corpo di nessuna email.
- Le richieste GET dei link non modificano nulla: "Confermo la presenza", "Devo spostarlo", "Annulla la richiesta" aprono una pagina che chiede conferma con una POST. Dalle email allo studio non si approva nulla: ogni bottone apre il gestionale, con login.
- Il nome del professionista è scritto come "Dott.ssa Elena Conti", senza specialità.
- Il codice di verifica viaggia per email (P7) oppure per SMS, ma l'SMS solo se lo studio ha acceso quel metodo: è spento di default (decisione del 22 ottobre, che supera quella del 21 "mai SMS di verifica"). La verifica con WhatsApp è un messaggio che il paziente manda allo studio e non produce nessun testo da progettare qui. Gli SMS e i WhatsApp di conferme e promemoria sono a carico dello studio; anche l'SMS del codice si paga a consumo sul credito dello studio, come i promemoria.
- Oggetti con il nome dello studio: se l'oggetto supera i 60 caratteri (nomi lunghi, P7 e P8 sono i più esposti) si usa il "nome nei messaggi" breve dello studio (massimo 24 caratteri, impostato dal team Ambulatorio Facile nell'editor, campo `comunicazioni.nome_nei_messaggi`, per impostazione uguale al nome dello studio) e, se ancora non basta, si taglia al nome breve senza puntini. Se il nome dello studio contiene una specialità (per esempio "Studio Dermatologico ..."), lo studio può scegliere un nome neutro: l'oggetto compare nelle anteprime del telefono bloccato.
- I token e i codici nei link e nel codice di verifica di questo file (`t=e8Zq4mT1`, `aB3xQ9`, `483291`) sono segnaposto: in produzione i token sono casuali da 32 byte e il link breve ne usa una forma corta ma non indovinabile.
- Le scadenze ("Ti rispondiamo entro 24 ore", "Rispondi entro ...") leggono `accesso.ore_scadenza_richieste` della configurazione dello studio, non un numero fisso.
- Prenotazione senza verifica: le email partono solo se il paziente ha lasciato l'email. Cosa cambia è nella sezione "Prenotazione senza verifica: cosa cambia nei messaggi", in fondo.
- Il motivo di annullamento mostrato in S4 viene dall'elenco chiuso di chip del paziente; "Sto meglio" non si riporta mai nell'email (compare come "Altro motivo"), perché dice qualcosa sulla salute.

## Oggetti, in sintesi

| Email | Oggetto | Caratteri |
|---|---|---|
| P1 Richiesta ricevuta | Richiesta ricevuta: Poliambulatorio Santa Chiara | 48 |
| P2 Prenotazione confermata | Confermato: giovedì 12 novembre alle 17:30 | 42 |
| P3 Richiesta non accolta | Richiesta non confermata: Poliambulatorio Santa Chiara | 54 |
| P4 Promemoria | Promemoria: appuntamento giovedì alle 17:30 | 43 |
| P5 Appuntamento spostato | Appuntamento spostato a martedì 17 novembre | 43 |
| P6 Appuntamento annullato | Appuntamento annullato: Poliambulatorio Santa Chiara | 52 |
| P7 Codice di verifica | Il tuo codice di verifica per Poliambulatorio Santa Chiara | 58 |
| P8 Accesso alle tue prenotazioni | Accedi alle tue prenotazioni: Poliambulatorio Santa Chiara | 58 |
| S1 Nuova prenotazione online | Nuova prenotazione online: ven 13/11 15:00 | 42 |
| S2 Richiesta da confermare | Da confermare: prenotazione gio 12/11 17:30 | 43 |
| S3 Spostata dal paziente | Prenotazione spostata dal paziente: 12/11 a 17/11 | 49 |
| S4 Annullata dal paziente | Annullata dal paziente: gio 12/11 17:30 | 39 |
| S5 Attività anomala | Attività anomala sulle prenotazioni online | 42 |

Varianti di oggetto da gestire nel codice:

- P4: "Promemoria: appuntamento domani alle 17:30" quando manca un giorno, altrimenti il giorno della settimana ("giovedì").
- P5: "Appuntamento spostato a martedì 17 novembre" (data nuova in forma estesa, senza anno).
- S1 a S4: data in forma abbreviata "ven 13/11 15:00", mai il nome del paziente.

## P1 Richiesta ricevuta

Quando parte: Subito dopo l'invio di una richiesta da confermare (nuovo paziente, studio in modalità "Nuovi pazienti su approvazione").

Oggetto (48 caratteri):

`Richiesta ricevuta: Poliambulatorio Santa Chiara`

Preheader:

`Lo studio ti conferma a breve. L'orario è tenuto in sospeso per te.`

Versione solo testo:

```text
Stato: In attesa di conferma

Giovedì 12 novembre, ore 17:30

Ciao Rosa, abbiamo ricevuto la tua richiesta. La segreteria la controlla e ti conferma l'appuntamento con un'altra email.

L'orario è tenuto in sospeso per te: nessun altro può prenotarlo nel frattempo.

Con: Dott.ssa Elena Conti
Dove: Sede di Empoli, Via Roma 18, 50053 Empoli (FI). Apri in Maps: https://www.google.com/maps/search/?api=1&query=Via+Roma+18+50053+Empoli
Codice: K7P2QH

Vedi la richiesta: https://ambulatoriofacile.it/p/K7P2QH?t=e8Zq4mT1
Annulla la richiesta: https://ambulatoriofacile.it/p/K7P2QH?t=e8Zq4mT1&azione=annulla

Cosa succede adesso
Ti rispondiamo entro 24 ore. Se lo studio non riesce a confermare questo orario, ti avvisiamo e puoi sceglierne un altro. Non devi fare altro.

Poliambulatorio Santa Chiara
Santa Chiara Servizi Sanitari S.r.l., P.IVA 01234560485, Via Roma 18, 50053 Empoli (FI), tel. 0571 123 456, segreteria@poliambulatoriosantachiara.it
Informativa privacy: https://poliambulatoriosantachiara.it/privacy
Prenotazioni gestite con Ambulatorio Facile per conto di Santa Chiara Servizi Sanitari S.r.l., titolare del trattamento dei dati.
Email di servizio relativa alla tua prenotazione. Per smettere di ricevere i promemoria: Preferenze https://ambulatoriofacile.it/p/preferenze?t=e8Zq4mT1
```

## P2 Prenotazione confermata

Quando parte: Prenotazione confermata (subito, o dopo l'approvazione della segreteria). Allegato ICS.

Oggetto (42 caratteri):

`Confermato: giovedì 12 novembre alle 17:30`

Preheader:

`Ti aspettiamo giovedì 12 novembre alle 17:30. Dentro trovi dove, con chi e come spostare.`

Versione solo testo:

```text
Stato: Confermata

Giovedì 12 novembre, ore 17:30

Ciao Rosa, il tuo appuntamento è confermato. Ti aspettiamo in studio.

Con: Dott.ssa Elena Conti
Prestazione: Visita dermatologica
Dove: Sede di Empoli, Via Roma 18, 50053 Empoli (FI). Apri in Maps: https://www.google.com/maps/search/?api=1&query=Via+Roma+18+50053+Empoli
Codice: K7P2QH

Gestisci prenotazione: https://ambulatoriofacile.it/p/K7P2QH?t=e8Zq4mT1

Aggiungi al calendario: https://ambulatoriofacile.it/p/K7P2QH/calendario?t=e8Zq4mT1
Come arrivare: https://ambulatoriofacile.it/p/K7P2QH/come-arrivare?t=e8Zq4mT1

Spostare o annullare
Puoi spostare o annullare l'appuntamento fino a 24 ore prima, dal bottone qui sopra. Paghi in studio. Porta con te la tessera sanitaria.

In allegato trovi l'invito per il calendario (file .ics).

Poliambulatorio Santa Chiara
Santa Chiara Servizi Sanitari S.r.l., P.IVA 01234560485, Via Roma 18, 50053 Empoli (FI), tel. 0571 123 456, segreteria@poliambulatoriosantachiara.it
Informativa privacy: https://poliambulatoriosantachiara.it/privacy
Prenotazioni gestite con Ambulatorio Facile per conto di Santa Chiara Servizi Sanitari S.r.l., titolare del trattamento dei dati.
Email di servizio relativa alla tua prenotazione. Per smettere di ricevere i promemoria: Preferenze https://ambulatoriofacile.it/p/preferenze?t=e8Zq4mT1
```

## P3 Richiesta non accolta

Quando parte: La segreteria rifiuta la richiesta, oppure la richiesta scade senza risposta.

Oggetto (54 caratteri):

`Richiesta non confermata: Poliambulatorio Santa Chiara`

Preheader:

`Questo orario non è disponibile. Scegli un altro orario in pochi tocchi.`

Versione solo testo:

```text
Stato: Non confermata

Giovedì 12 novembre, ore 17:30

Ciao Rosa, la segreteria non è riuscita a confermare la tua richiesta per questo orario. Ci dispiace per l'inconveniente.

Con: Dott.ssa Elena Conti
Motivo: L'orario non è più disponibile.
Codice: K7P2QH

Scegli un altro orario: https://ambulatoriofacile.it/prenota/santa-chiara?riprendi=K7P2QH&t=e8Zq4mT1

Chiama lo studio: 0571 123 456: tel:+390571123456

Nessun impegno
La tua richiesta non ha impegnato nulla. Se non vuoi riprenotare, non devi fare altro.

Poliambulatorio Santa Chiara
Santa Chiara Servizi Sanitari S.r.l., P.IVA 01234560485, Via Roma 18, 50053 Empoli (FI), tel. 0571 123 456, segreteria@poliambulatoriosantachiara.it
Informativa privacy: https://poliambulatoriosantachiara.it/privacy
Prenotazioni gestite con Ambulatorio Facile per conto di Santa Chiara Servizi Sanitari S.r.l., titolare del trattamento dei dati.
Email di servizio relativa alla tua prenotazione. Per smettere di ricevere i promemoria: Preferenze https://ambulatoriofacile.it/p/preferenze?t=e8Zq4mT1
```

## P4 Promemoria

Quando parte: Da 0 a 7 giorni prima dell'appuntamento (predefinito 2). Nessun allegato ICS.

Oggetto (43 caratteri):

`Promemoria: appuntamento giovedì alle 17:30`

Preheader:

`Ti aspettiamo giovedì 12 novembre alle 17:30. Se non puoi venire, avvisaci in tempo.`

Versione solo testo:

```text
Stato: Promemoria

Giovedì 12 novembre, ore 17:30

Ciao Rosa, ti ricordiamo l'appuntamento di giovedì.

Con: Dott.ssa Elena Conti
Dove: Sede di Empoli, Via Roma 18, 50053 Empoli (FI). Apri in Maps: https://www.google.com/maps/search/?api=1&query=Via+Roma+18+50053+Empoli
Codice: K7P2QH

Confermo la presenza: https://ambulatoriofacile.it/p/K7P2QH?t=e8Zq4mT1&azione=conferma
Devo spostarlo: https://ambulatoriofacile.it/p/K7P2QH?t=e8Zq4mT1&azione=sposta

Come arrivare: https://ambulatoriofacile.it/p/K7P2QH/come-arrivare?t=e8Zq4mT1

Se non puoi venire
Puoi spostare o annullare fino a 24 ore prima: così l'orario torna disponibile per un altro paziente.

Poliambulatorio Santa Chiara
Santa Chiara Servizi Sanitari S.r.l., P.IVA 01234560485, Via Roma 18, 50053 Empoli (FI), tel. 0571 123 456, segreteria@poliambulatoriosantachiara.it
Informativa privacy: https://poliambulatoriosantachiara.it/privacy
Prenotazioni gestite con Ambulatorio Facile per conto di Santa Chiara Servizi Sanitari S.r.l., titolare del trattamento dei dati.
Email di servizio relativa alla tua prenotazione. Per smettere di ricevere i promemoria: Preferenze https://ambulatoriofacile.it/p/preferenze?t=e8Zq4mT1
```

## P5 Appuntamento spostato

Quando parte: Spostamento fatto dallo studio (questa versione) o dal paziente (frase iniziale diversa, niente scuse, niente "Scegli un altro orario"). ICS con lo stesso UID e SEQUENCE più uno.

Oggetto (43 caratteri):

`Appuntamento spostato a martedì 17 novembre`

Preheader:

`Il tuo appuntamento ha un nuovo orario: martedì 17 novembre alle 9:00.`

Versione solo testo:

```text
Stato: Spostata

Martedì 17 novembre, ore 9:00

Ciao Rosa, lo studio ha dovuto spostare il tuo appuntamento. Ci scusiamo per il disagio.

Prima: Giovedì 12 novembre, 17:30 (non più valido)
Ora: Martedì 17 novembre, 9:00

Con: Dott.ssa Elena Conti
Dove: Sede di Empoli, Via Roma 18, 50053 Empoli (FI). Apri in Maps: https://www.google.com/maps/search/?api=1&query=Via+Roma+18+50053+Empoli
Codice: K7P2QH

Gestisci prenotazione: https://ambulatoriofacile.it/p/K7P2QH?t=e8Zq4mT1

Scegli un altro orario: https://ambulatoriofacile.it/p/K7P2QH?t=e8Zq4mT1&azione=sposta

Aggiungi al calendario: https://ambulatoriofacile.it/p/K7P2QH/calendario?t=e8Zq4mT1
Come arrivare: https://ambulatoriofacile.it/p/K7P2QH/come-arrivare?t=e8Zq4mT1

Non ti va bene il nuovo orario?
Puoi sceglierne un altro o annullare fino a 24 ore prima.

In allegato trovi l'invito aggiornato: sostituisce quello precedente nel tuo calendario.

Poliambulatorio Santa Chiara
Santa Chiara Servizi Sanitari S.r.l., P.IVA 01234560485, Via Roma 18, 50053 Empoli (FI), tel. 0571 123 456, segreteria@poliambulatoriosantachiara.it
Informativa privacy: https://poliambulatoriosantachiara.it/privacy
Prenotazioni gestite con Ambulatorio Facile per conto di Santa Chiara Servizi Sanitari S.r.l., titolare del trattamento dei dati.
Email di servizio relativa alla tua prenotazione. Per smettere di ricevere i promemoria: Preferenze https://ambulatoriofacile.it/p/preferenze?t=e8Zq4mT1
```

## P6 Appuntamento annullato

Quando parte: Annullamento fatto dallo studio (questa versione) o dal paziente. ICS con METHOD:CANCEL.

Oggetto (52 caratteri):

`Appuntamento annullato: Poliambulatorio Santa Chiara`

Preheader:

`L'appuntamento di giovedì 12 novembre alle 17:30 è stato annullato.`

Versione solo testo:

```text
Stato: Annullata

Giovedì 12 novembre, ore 17:30

Ciao Rosa, l'appuntamento è stato annullato dallo studio. Ci scusiamo per il disagio.

Con: Dott.ssa Elena Conti
Annullata da: Lo studio
Codice: K7P2QH

Prenota un nuovo orario: https://ambulatoriofacile.it/prenota/santa-chiara?riprendi=K7P2QH&t=e8Zq4mT1

Serve aiuto?
Per qualsiasi dubbio puoi chiamare la segreteria al 0571 123 456.

Se avevi aggiunto l'appuntamento al calendario, lo rimuovi aprendo il file .ics in allegato.

Poliambulatorio Santa Chiara
Santa Chiara Servizi Sanitari S.r.l., P.IVA 01234560485, Via Roma 18, 50053 Empoli (FI), tel. 0571 123 456, segreteria@poliambulatoriosantachiara.it
Informativa privacy: https://poliambulatoriosantachiara.it/privacy
Prenotazioni gestite con Ambulatorio Facile per conto di Santa Chiara Servizi Sanitari S.r.l., titolare del trattamento dei dati.
Email di servizio relativa alla tua prenotazione. Per smettere di ricevere i promemoria: Preferenze https://ambulatoriofacile.it/p/preferenze?t=e8Zq4mT1
```

## P7 Codice di verifica

Quando parte: Il paziente sceglie la verifica via email nel passo "Verifica che sei tu" (la verifica con WhatsApp non manda nessuna email) e superata la verifica anti bot. Nessun link, nessun bottone. Chi sceglie l'SMS (solo negli studi che hanno acceso quel metodo) non riceve questa email ma l'SMS del codice, nella sezione "SMS del codice di verifica" più sotto.

Oggetto (58 caratteri):

`Il tuo codice di verifica per Poliambulatorio Santa Chiara`

Preheader:

`Il tuo codice è 483291. Vale 10 minuti.`

Versione solo testo:

```text
Il tuo codice di verifica

Inserisci questo codice per continuare con la prenotazione. Te lo chiediamo per essere sicuri che sia proprio tu.

Il tuo codice: 483291

Vale 10 minuti. Non condividerlo con nessuno, nemmeno con lo studio: non te lo chiederemo mai.

Non hai chiesto tu il codice?
Ignora questa email. Senza il codice nessuno può prenotare a tuo nome.

Poliambulatorio Santa Chiara
Santa Chiara Servizi Sanitari S.r.l., P.IVA 01234560485, Via Roma 18, 50053 Empoli (FI), tel. 0571 123 456, segreteria@poliambulatoriosantachiara.it
Informativa privacy: https://poliambulatoriosantachiara.it/privacy
Prenotazioni gestite con Ambulatorio Facile per conto di Santa Chiara Servizi Sanitari S.r.l., titolare del trattamento dei dati.
Email di servizio relativa alla tua richiesta di verifica. Arriva solo quando la chiedi tu.
```

## P8 Accesso alle tue prenotazioni

Quando parte: Il paziente chiede di entrare in "Le mie prenotazioni" con la sua email. Link valido 15 minuti, una sola volta (la GET non consuma il link, lo consuma la POST).

Oggetto (58 caratteri):

`Accedi alle tue prenotazioni: Poliambulatorio Santa Chiara`

Preheader:

`Un tocco per vedere, spostare o annullare le tue prenotazioni. Il link vale 15 minuti.`

Versione solo testo:

```text
Accedi alle tue prenotazioni

Ciao Rosa, tocca il bottone per vedere le tue prenotazioni con Poliambulatorio Santa Chiara. Da lì puoi anche spostarle o annullarle.

Accedi alle tue prenotazioni: https://ambulatoriofacile.it/p/accesso?t=Hd72LkQ9

Il link vale 15 minuti e si può usare una sola volta. Se il bottone non si apre, copia questo indirizzo nel browser:
https://ambulatoriofacile.it/p/accesso?t=Hd72LkQ9

Non hai chiesto tu di accedere?
Ignora questa email. Senza il link nessuno può vedere le tue prenotazioni.

Poliambulatorio Santa Chiara
Santa Chiara Servizi Sanitari S.r.l., P.IVA 01234560485, Via Roma 18, 50053 Empoli (FI), tel. 0571 123 456, segreteria@poliambulatoriosantachiara.it
Informativa privacy: https://poliambulatoriosantachiara.it/privacy
Prenotazioni gestite con Ambulatorio Facile per conto di Santa Chiara Servizi Sanitari S.r.l., titolare del trattamento dei dati.
Email di servizio relativa alla tua richiesta di accesso. Arriva solo quando la chiedi tu.
```

## S1 Nuova prenotazione online

Quando parte: Prenotazione online confermata in automatico (paziente in archivio). Va alla segreteria e, se attivato, al professionista.

Oggetto (42 caratteri):

`Nuova prenotazione online: ven 13/11 15:00`

Preheader:

`Un paziente ha prenotato online. È già in agenda venerdì 13 novembre alle 15:00.`

Versione solo testo:

```text
Stato: Nuova prenotazione

Venerdì 13 novembre, ore 15:00

Un paziente ha prenotato dal sito. La prenotazione è confermata ed è già in agenda.

Professionista: Dott. Luca Romano
Sede: Vinci, Piazza della Libertà 3, 50059 Vinci (FI)
Prestazione: Seduta di fisioterapia
Paziente: Franco Rinaldi
Prenotata da: Davide Rinaldi, per conto del paziente
Contatti: 338 555 0142, davide.rinaldi@email.it
Canale: Sito web

Apri in agenda: https://ambulatoriofacile.it/app/agenda?prenotazione=K7P2QH

Nota del paziente
Il paziente ha lasciato una nota. Per tutelare la sua privacy non la riportiamo in questa email: leggila nel gestionale.

Poliambulatorio Santa Chiara
Santa Chiara Servizi Sanitari S.r.l., P.IVA 01234560485, Via Roma 18, 50053 Empoli (FI), tel. 0571 123 456, segreteria@poliambulatoriosantachiara.it
Informativa privacy: https://poliambulatoriosantachiara.it/privacy
Prenotazioni gestite con Ambulatorio Facile per conto di Santa Chiara Servizi Sanitari S.r.l., titolare del trattamento dei dati.
Avviso di servizio per la segreteria. Può contenere dati dei pazienti: non inoltrarlo fuori dallo studio. Scegli chi lo riceve: Preferenze https://ambulatoriofacile.it/app/prenotazioni-online/impostazioni#avvisi
```

## S2 Richiesta da confermare

Quando parte: Un nuovo paziente chiede un appuntamento e lo studio lavora su approvazione. Nessuna approvazione con un clic dall'email.

Oggetto (43 caratteri):

`Da confermare: prenotazione gio 12/11 17:30`

Preheader:

`Una nuova richiesta aspetta la tua risposta. Rispondi entro giovedì 22 ottobre alle 21:10.`

Versione solo testo:

```text
Stato: Da confermare

Giovedì 12 novembre, ore 17:30

Un nuovo paziente ha chiesto un appuntamento. L'orario resta occupato in agenda, in tratteggio, finché non rispondi.

Professionista: Dott.ssa Elena Conti
Sede: Empoli, Via Roma 18, 50053 Empoli (FI)
Prestazione: Visita dermatologica
Paziente: Rosa Bianchi, nuova (non in archivio)
Contatti: 347 812 6650 (verificato con WhatsApp), rosa.bianchi@email.it
Canale: Sito web
Rispondi entro: Giovedì 22 ottobre, ore 21:10

Rivedi la richiesta: https://ambulatoriofacile.it/app/prenotazioni-online/richieste/K7P2QH

Come funziona
Per confermare o rifiutare devi accedere al gestionale: da questa email non si approva nulla.
Se non rispondi entro la scadenza, la richiesta decade e l'orario torna libero.

Il paziente ha lasciato una nota: leggila nel gestionale.

Poliambulatorio Santa Chiara
Santa Chiara Servizi Sanitari S.r.l., P.IVA 01234560485, Via Roma 18, 50053 Empoli (FI), tel. 0571 123 456, segreteria@poliambulatoriosantachiara.it
Informativa privacy: https://poliambulatoriosantachiara.it/privacy
Prenotazioni gestite con Ambulatorio Facile per conto di Santa Chiara Servizi Sanitari S.r.l., titolare del trattamento dei dati.
Avviso di servizio per la segreteria. Può contenere dati dei pazienti: non inoltrarlo fuori dallo studio. Scegli chi lo riceve: Preferenze https://ambulatoriofacile.it/app/prenotazioni-online/impostazioni#avvisi
```

## S3 Spostata dal paziente

Quando parte: Il paziente sposta da solo la sua prenotazione dalla pagina di gestione.

Oggetto (49 caratteri):

`Prenotazione spostata dal paziente: 12/11 a 17/11`

Preheader:

`Il vecchio orario di giovedì 12 novembre alle 17:30 è di nuovo libero.`

Versione solo testo:

```text
Stato: Spostata dal paziente

Martedì 17 novembre, ore 9:00

Un paziente ha spostato la sua prenotazione. L'orario di giovedì 12 novembre alle 17:30 è di nuovo libero.

Prima: Giovedì 12 novembre, 17:30 (non più valido)
Ora: Martedì 17 novembre, 9:00

Professionista: Dott.ssa Elena Conti
Sede: Empoli, Via Roma 18, 50053 Empoli (FI)
Prestazione: Visita dermatologica
Paziente: Rosa Bianchi

Apri in agenda: https://ambulatoriofacile.it/app/agenda?prenotazione=K7P2QH

Poliambulatorio Santa Chiara
Santa Chiara Servizi Sanitari S.r.l., P.IVA 01234560485, Via Roma 18, 50053 Empoli (FI), tel. 0571 123 456, segreteria@poliambulatoriosantachiara.it
Informativa privacy: https://poliambulatoriosantachiara.it/privacy
Prenotazioni gestite con Ambulatorio Facile per conto di Santa Chiara Servizi Sanitari S.r.l., titolare del trattamento dei dati.
Avviso di servizio per la segreteria. Può contenere dati dei pazienti: non inoltrarlo fuori dallo studio. Scegli chi lo riceve: Preferenze https://ambulatoriofacile.it/app/prenotazioni-online/impostazioni#avvisi
```

## S4 Annullata dal paziente

Quando parte: Il paziente annulla da solo la prenotazione dalla pagina di gestione.

Oggetto (39 caratteri):

`Annullata dal paziente: gio 12/11 17:30`

Preheader:

`L'orario di giovedì 12 novembre alle 17:30 è di nuovo libero.`

Versione solo testo:

```text
Stato: Annullata dal paziente

Giovedì 12 novembre, ore 17:30

Un paziente ha annullato la prenotazione. L'orario è di nuovo libero e si può prenotare online.

Professionista: Dott.ssa Elena Conti
Sede: Empoli, Via Roma 18, 50053 Empoli (FI)
Prestazione: Visita dermatologica
Paziente: Rosa Bianchi
Motivo: Ho un impegno

Apri in agenda: https://ambulatoriofacile.it/app/agenda?prenotazione=K7P2QH

Poliambulatorio Santa Chiara
Santa Chiara Servizi Sanitari S.r.l., P.IVA 01234560485, Via Roma 18, 50053 Empoli (FI), tel. 0571 123 456, segreteria@poliambulatoriosantachiara.it
Informativa privacy: https://poliambulatoriosantachiara.it/privacy
Prenotazioni gestite con Ambulatorio Facile per conto di Santa Chiara Servizi Sanitari S.r.l., titolare del trattamento dei dati.
Avviso di servizio per la segreteria. Può contenere dati dei pazienti: non inoltrarlo fuori dallo studio. Scegli chi lo riceve: Preferenze https://ambulatoriofacile.it/app/prenotazioni-online/impostazioni#avvisi
```

## S5 Attività anomala

Quando parte: Più di 10 nuove richieste in un'ora (soglia configurabile), o tetto giornaliero delle email di verifica all'80%, o tetto giornaliero degli SMS di verifica raggiunto (solo per gli studi con il metodo SMS acceso). Al massimo un avviso ogni 3 ore.

Oggetto (42 caratteri):

`Attività anomala sulle prenotazioni online`

Preheader:

`Nell'ultima ora sono arrivate 14 richieste, oltre la soglia di 10. Controlla e, se serve, sospendi.`

Versione solo testo:

```text
Stato: Da controllare

Attività insolita sulle prenotazioni online

Nell'ultima ora sono arrivate 14 nuove richieste di prenotazione, più della soglia di 10 che hai impostato. Può essere normale, per esempio dopo un messaggio ai pazienti, ma può anche essere un uso scorretto del link.

Periodo: Mercoledì 21 ottobre, dalle 20:10 alle 21:10
Richieste: 14 in un'ora (soglia: 10)
Cellulari: 3 numeri diversi
Codici email: 62 su 100 oggi (tetto giornaliero)
Prenotazioni online: Attive

Controlla le richieste: https://ambulatoriofacile.it/app/prenotazioni-online/richieste
Sospendi le prenotazioni online: https://ambulatoriofacile.it/app/prenotazioni-online/impostazioni

Cosa puoi fare
Nel gestionale puoi rifiutare tutte le richieste di un numero, bloccare un numero o sospendere le prenotazioni online con un tocco. Quando sono sospese, la pagina mostra il telefono dello studio.

Le richieste dei pazienti nuovi restano da confermare e scadono da sole: nulla entra in agenda senza la tua approvazione.

Poliambulatorio Santa Chiara
Santa Chiara Servizi Sanitari S.r.l., P.IVA 01234560485, Via Roma 18, 50053 Empoli (FI), tel. 0571 123 456, segreteria@poliambulatoriosantachiara.it
Informativa privacy: https://poliambulatoriosantachiara.it/privacy
Prenotazioni gestite con Ambulatorio Facile per conto di Santa Chiara Servizi Sanitari S.r.l., titolare del trattamento dei dati.
Avviso di servizio per la segreteria. Può contenere dati dei pazienti: non inoltrarlo fuori dallo studio. Scegli chi lo riceve: Preferenze https://ambulatoriofacile.it/app/prenotazioni-online/impostazioni#avvisi
```

Variante per il tetto degli SMS di verifica (stesso oggetto e stessa struttura; cambiano il paragrafo iniziale e le righe di dettaglio):

```text
Oggi sono stati inviati 50 SMS con il codice di verifica, il massimo giornaliero impostato per il tuo studio. Fino a domani il codice via SMS non compare ai pazienti: possono verificarsi con gli altri metodi attivi. Può essere normale in una giornata intensa, ma può anche essere un uso scorretto del link per far partire SMS a pagamento.

SMS di verifica: 50 su 50 oggi (tetto giornaliero raggiunto)
Metodi ancora attivi: WhatsApp, Email
Prenotazioni online: Attive
```

Nella riga "Codici email" del testo principale, per gli studi con l'SMS acceso si aggiunge sotto la riga "SMS di verifica: 18 su 50 oggi".

## SMS e WhatsApp

Testo di al massimo 160 caratteri GSM-7: niente apostrofo tipografico, niente emoji, niente caratteri fuori dal set base (le lettere accentate italiane sono nel set). Il link breve è un segnaposto: i link brevi puntano a `/p/{ref}?t=` su un dominio ambulatoriofacile.it, mai a servizi di terzi. Il mittente dell'SMS è "SantaChiara" (11 caratteri, il massimo per il mittente alfanumerico): nel codice si ricava dal nome dello studio, senza spazi e troncato a 11. Il conteggio qui sotto include il link breve da 37 caratteri.

### P2 Prenotazione confermata

SMS (133 caratteri):

```text
Santa Chiara: appuntamento confermato gio 12/11 ore 17:30, Via Roma 18, Empoli. Gestiscilo qui: https://ambulatoriofacile.it/p/aB3xQ9
```

WhatsApp (145 caratteri):

```text
Poliambulatorio Santa Chiara: appuntamento confermato gio 12/11 ore 17:30, Via Roma 18, Empoli. Gestiscilo: https://ambulatoriofacile.it/p/aB3xQ9
```

### P4 Promemoria

SMS (136 caratteri):

```text
Santa Chiara: ti ricordiamo l'appuntamento di giovedì 12/11 alle 17:30. Per confermare o spostare: https://ambulatoriofacile.it/p/aB3xQ9
```

WhatsApp (140 caratteri):

```text
Poliambulatorio Santa Chiara: ti ricordiamo l'appuntamento di giovedì 12/11 alle 17:30. Per spostarlo: https://ambulatoriofacile.it/p/aB3xQ9
```

### P5 Appuntamento spostato

SMS (116 caratteri):

```text
Santa Chiara: appuntamento spostato a mar 17/11 ore 9:00. Dettagli e gestione: https://ambulatoriofacile.it/p/aB3xQ9
```

WhatsApp (136 caratteri):

```text
Poliambulatorio Santa Chiara: il tuo appuntamento è stato spostato a mar 17/11 ore 9:00. Dettagli: https://ambulatoriofacile.it/p/aB3xQ9
```

### P6 Appuntamento annullato

SMS (138 caratteri):

```text
Santa Chiara: il tuo appuntamento di gio 12/11 ore 17:30 è stato annullato. Prenota un nuovo orario: https://ambulatoriofacile.it/p/aB3xQ9
```

WhatsApp (138 caratteri):

```text
Poliambulatorio Santa Chiara: il tuo appuntamento di gio 12/11 ore 17:30 è stato annullato. Prenota: https://ambulatoriofacile.it/p/aB3xQ9
```

### P1 Richiesta ricevuta (solo senza verifica, con il solo cellulare)

Parte solo quando il paziente ha prenotato senza verifica, ha lasciato solo il cellulare e lo studio ha SMS o WhatsApp attivi. Il codice prenotazione c'è sempre, perché è l'unico riferimento che il paziente ha in mano.

SMS (149 caratteri):

```text
Santa Chiara: richiesta ricevuta per gio 12/11 ore 17:30, codice K7P2QH. Ti confermiamo entro 24 ore. Dettagli: https://ambulatoriofacile.it/p/aB3xQ9
```

WhatsApp (155 caratteri):

```text
Poliambulatorio Santa Chiara: richiesta ricevuta per gio 12/11 ore 17:30, codice K7P2QH. Ti confermiamo entro 24 ore: https://ambulatoriofacile.it/p/aB3xQ9
```

### P2 Prenotazione confermata (variante senza verifica, con il solo cellulare)

Come P2 sopra, con il codice prenotazione.

SMS (148 caratteri):

```text
Santa Chiara: appuntamento confermato gio 12/11 ore 17:30, Via Roma 18, Empoli. Codice K7P2QH. Gestiscilo qui: https://ambulatoriofacile.it/p/aB3xQ9
```

WhatsApp (148 caratteri):

```text
Poliambulatorio Santa Chiara: appuntamento confermato gio 12/11 ore 17:30, Via Roma 18, Empoli. Codice K7P2QH: https://ambulatoriofacile.it/p/aB3xQ9
```

### P3 Richiesta non accolta (solo senza verifica, con il solo cellulare)

SMS (123 caratteri):

```text
Santa Chiara: non riusciamo a confermare gio 12/11 ore 17:30. Scegli un altro orario: https://ambulatoriofacile.it/p/aB3xQ9
```

WhatsApp (139 caratteri):

```text
Poliambulatorio Santa Chiara: non riusciamo a confermare gio 12/11 ore 17:30. Scegli un altro orario: https://ambulatoriofacile.it/p/aB3xQ9
```

## SMS del codice di verifica

Quando parte: il paziente sceglie "Con un codice via SMS" nel passo "Verifica che sei tu", o per entrare in "Le mie prenotazioni", e supera la verifica anti bot. Solo negli studi che hanno acceso il metodo SMS (spento di default). Si invia con il modulo SMS dei promemoria, dallo stesso mittente ("SantaChiara"), e si paga a consumo sul credito dello studio. Stesso testo per prenotare e per accedere.

SMS (111 caratteri, codice d'esempio):

```text
Poliambulatorio Santa Chiara: il tuo codice di verifica è 482193. Vale 10 minuti. Non condividerlo con nessuno.
```

Regole:

- Niente link: un SMS con codice e link insieme è lo schema tipico del phishing, e il codice si scrive nella pagina già aperta.
- Niente prestazione, niente nome del paziente, niente data dell'appuntamento.
- Il nome è quello completo dello studio; se il testo supera i 160 caratteri si usa il "nome nei messaggi" breve (`comunicazioni.nome_nei_messaggi`).
- Il codice è casuale per ogni invio; nel prototipo il codice di prova è 123456, come quello email.
- Non ha il link "Preferenze": arriva solo quando lo chiede il destinatario.

## Prenotazione senza verifica: cosa cambia nei messaggi

Vale quando lo studio ha tolto la verifica (prenotazione senza registrazione, sezione 5.10 della guida).

- **Email al paziente.** P1, P2, P3, P4, P5 e P6 partono solo se il paziente ha lasciato l'email nel blocco "Come ti contattiamo". Il testo non cambia, tranne una riga in più sotto il codice: "Conservalo: ti serve se chiami lo studio." P7 e P8 non partono mai da una prenotazione senza verifica: arrivano solo se il paziente sceglie di verificarsi (per esempio per entrare in "Le mie prenotazioni", quando lo studio ha almeno un metodo acceso).
- **Solo cellulare.** Conferme, richieste ricevute, rifiuti, spostamenti e annullamenti partono per SMS o WhatsApp, se lo studio ha quei canali attivi (testi P1, P2 e P3 senza verifica qui sopra, P5 e P6 come per tutti). Se lo studio non li ha, non parte nessun messaggio: la schermata finale lo dice e mostra il codice e il telefono dello studio.
- **Nessun recapito** (solo con `recapito: facoltativo`): nessun messaggio. Il paziente ha il codice e il telefono dello studio.
- **Promemoria.** Partono sul recapito lasciato, con le regole di sempre. "Blocca questo numero" li ferma.
- **Email allo studio.** S1 e S2 riportano "(non verificato)" accanto ai contatti, per esempio "Contatti: 347 812 6650 (non verificato)", e una riga "Verifica: nessuna, lo studio accetta prenotazioni senza verifica". Se il recapito corrisponde a un paziente in archivio, S2 aggiunge "Il recapito corrisponde a un paziente in archivio: controlla prima di confermare." senza nominarlo.

## Testi delle schermate nuove (22 ottobre)

### Verifica che sei tu, con la scelta del metodo

- Titolo: "Verifica che sei tu"
- Sottotitolo: "Così siamo sicuri che sia proprio tu e nessuno può prenotare a tuo nome. Non serve creare un account."
- Campo: "Numero di cellulare", aiuto "Prefisso +39 già inserito. Lo usiamo per i promemoria e per riconoscerti se sei già paziente."
- Domanda: "Come vuoi verificare?"
- Opzioni, nell'ordine scelto dallo studio; la prima ha il badge "Consigliato":
  - "Con WhatsApp", descrizione "Mandi allo studio un messaggio già scritto. Basta un tocco, nessun codice da copiare."
  - "Con un codice via email", descrizione "Ti mandiamo 6 cifre all'indirizzo che scegli. Valgono 10 minuti."
  - "Con un codice via SMS", descrizione "Ti mandiamo 6 cifre al cellulare. Valgono 10 minuti."
- Bottone: "Mandami il codice" (email e SMS) oppure "Verifica con WhatsApp".
- Con un solo metodo acceso la domanda non c'è: al suo posto una riga di spiegazione ("Per verificare mandi allo studio un messaggio WhatsApp già scritto: basta un tocco." oppure "Ti mandiamo un codice di 6 cifre via email. Vale 10 minuti." oppure "Ti mandiamo un codice di 6 cifre via SMS a questo numero. Vale 10 minuti.") e lo stesso bottone di sopra.
- Per "Le mie prenotazioni" il sottotitolo elenca solo i metodi accesi: "Entra con il cellulare che hai usato per prenotare e una verifica veloce, su WhatsApp, via email o via SMS. Niente password da ricordare."
- Tetto giornaliero SMS raggiunto e SMS unico metodo: titolo "La verifica non è disponibile adesso", testo "Per oggi non possiamo mandare altri codici. Riprova domani, oppure prenota per telefono."

### Codice via SMS

- Titolo: "Inserisci il codice"
- Testo: "Ti abbiamo mandato un SMS al 347 812 6650. Vale 10 minuti."
- Link: "Cambia numero"
- Rinvio: "Non ti è arrivato? Puoi chiederne un altro tra 0:42.", poi "Non ti è arrivato?" con il link "Rimanda il codice"
- Bottone: "Verifica il codice"
- Codice errato: "Codice non corretto. Ti restano 3 tentativi." (all'ultimo: "Ti resta 1 tentativo.")
- Codice scaduto: "Il codice è scaduto. Chiedine uno nuovo."
- Troppi tentativi: "Per sicurezza abbiamo bloccato i tentativi per 15 minuti."
- Troppi codici richiesti: "Hai chiesto troppi codici per questo numero. Riprova tra un'ora oppure chiama lo studio."
- Conferma, annunciata con `aria-live`: "Numero verificato."

### Blocco "Come ti contattiamo" (senza verifica)

- Titolo del blocco: "Come ti contattiamo"
- Aiuto: "Ci serve per avvisarti se l'appuntamento cambia." Con `almeno_uno` la frase è preceduta da "Scrivi almeno uno tra cellulare ed email."
- Campi: "Cellulare" (aiuto "Prefisso +39 già inserito.") ed "Email". Con `facoltativo` entrambi hanno "(facoltativo)" nell'etichetta; con `cellulare` l'email è facoltativa; con `email` il cellulare è facoltativo.
- Errore, nessun recapito con `almeno_uno`: "Scrivi almeno un recapito: il cellulare oppure l'email."
- Errore, cellulare mancante con `cellulare`: "Scrivi il tuo cellulare: ti avvisiamo lì se l'appuntamento cambia."
- Errore, email mancante con `email`: "Scrivi la tua email: ti avvisiamo lì se l'appuntamento cambia."
- Errori di formato: "Scrivi un cellulare italiano, ad esempio 347 123 4567." e "Controlla l'email: deve essere come nome@esempio.it."

### Riepilogo senza verifica

- Riga dei contatti: etichetta "Ti contatteremo a", valore "+39 347 812 6650"; con entrambi i recapiti i due valori, e sotto "Conferma e promemoria su WhatsApp" (o il canale scelto).
- Senza recapiti: valore "Nessun recapito", sotto "Lo studio non potrà avvisarti se l'appuntamento cambia."
- Righe scelte in automatico, senza "Modifica": per esempio "Dott.ssa Elena Conti" alla riga Professionista e "Empoli" alla riga Sede.
- Nota (modalità su approvazione): "Lo studio conferma ogni prenotazione online. La tua sarà una richiesta: la conferma arriva entro 24 ore."
- Bottone: "Invia la richiesta" (approvazione) oppure "Conferma la prenotazione" (modalità aperta o invito).
- Sotto il bottone: "Protetto da una verifica automatica anti bot, senza puzzle." Durante il controllo: "Verifica di sicurezza in corso".
- Errore di Turnstile alla conferma (messaggio breve): "La verifica di sicurezza non è riuscita. Riprova."
- Troppe prenotazioni dallo stesso dispositivo o rete (da usare in produzione): "Da qui sono già state fatte diverse prenotazioni oggi. Per prenotarne altre chiama lo studio al 0571 123 456."

### Esito senza verifica

- Richiesta (modalità su approvazione): titolo "Richiesta inviata", testo "Lo studio la conferma di solito entro poche ore, al massimo entro 24." seguito da "Ti scriviamo su WhatsApp." (o il canale scelto) oppure, senza recapito, "Conserva il codice prenotazione."
- Confermata (modalità aperta): titolo "Ci vediamo giovedì 12 novembre alle 17:30", testo "La prenotazione è confermata."
- Codice in evidenza, sotto l'etichetta "Codice prenotazione": il codice (per esempio K7P2QH) e la riga "Conservalo: ti serve se chiami lo studio."
- Gestione, se lo studio non ha nessun metodo di verifica acceso: "Per spostare o annullare usa il link che ti abbiamo mandato, oppure chiama lo studio allo 0571 123 456." Senza recapiti il testo è "Per spostare o annullare chiama lo studio allo 0571 123 456." (da dire il codice).
- Con almeno un metodo acceso compare il bottone "Gestisci la prenotazione" (o "Gestisci la richiesta"), che porta alla verifica di "Le mie prenotazioni".
- Con il solo cellulare e studio senza SMS né WhatsApp (testo di produzione): "Non possiamo mandarti messaggi: per spostare o annullare chiama lo studio al 0571 123 456 e dai questo codice."

### Altri testi nuovi della pagina

- Schermata Professionista senza "Il primo disponibile": sottotitolo "Scegli con chi vuoi prenotare."
- Configurazione cambiata a metà prenotazione: "Lo studio ha appena aggiornato la pagina di prenotazione. Controlla le tue scelte e continua."
- Nessuna opzione possibile (catalogo o metodi non disponibili): "In questo momento non è possibile prenotare online. Chiama lo studio al 0571 123 456."
- Banner dell'anteprima (solo team Ambulatorio Facile): "Anteprima della bozza: le prenotazioni fatte qui non vengono salvate."
- Pagina dello studio, riepilogo del percorso: "Per cambiare il percorso di prenotazione scrivi all'assistenza di Ambulatorio Facile."
- Per la segreteria, sulle prenotazioni senza verifica: badge "Non verificato"; indizio "Il recapito corrisponde a un paziente in archivio: Rosa Bianchi"; bottoni "Collega a questa scheda" e "Crea una scheda nuova".

## Messaggi dell'editor (team Ambulatorio Facile)

Gli errori bloccano "Pubblica", gli avvisi chiedono conferma con "Pubblica comunque". Codici e condizioni nella sezione 4.7 della guida.

Errori:

- `riepilogo_non_ultimo`: "Il riepilogo deve essere l'ultimo passo e restare sempre attivo."
- `orario_troppo_presto`: "Il passo Orario deve venire dopo Prestazione, Professionista e Sede (quelli attivi): serve sapere durata, persona e sede."
- `dati_prima_di_verifica`: "Il passo I tuoi dati deve venire dopo la verifica: così chi è già paziente trova i dati compilati."
- `passo_non_spegnibile`: "Orario, I tuoi dati e Riepilogo non si possono spegnere."
- `passi_non_validi`: "Ogni passo deve comparire una sola volta."
- `prestazione_predefinita_mancante`: "Hai spento il passo Prestazione: scegli la prestazione predefinita."
- `professionista_non_determinabile`: "Hai tolto Il primo disponibile e spento il passo Professionista: scegli un professionista predefinito, perché {prestazione} ha più professionisti."
- `metodi_verifica_vuoti`: "La verifica è attiva ma nessun metodo è acceso: accendi almeno WhatsApp, email o SMS."
- `whatsapp_non_collegato`: "Il metodo WhatsApp richiede il numero WhatsApp dello studio collegato al gestionale. Collegalo o spegni il metodo."
- `sms_non_attivo`: "Il metodo SMS richiede il modulo SMS dello studio attivo, lo stesso dei promemoria. Attivalo o spegni il metodo."
- `archivio_senza_verifica`: "Solo pazienti in archivio non funziona senza verifica: non possiamo sapere chi sta prenotando. Scegli un'altra modalità o accendi la verifica."
- `recapito_nascosto`: "Hai chiesto il {campo} come recapito, ma il campo è nascosto nei dati. Mostralo o cambia il recapito richiesto."
- `riferimento_non_valido`: "{campo} punta a una prestazione o a un professionista che non è prenotabile online."
- `catalogo_vuoto`: "Nessuna prestazione è prenotabile online: la pagina mostrerebbe solo il telefono dello studio."
- `privacy_mancante`: "Manca il link all'informativa privacy dello studio."

Avvisi:

- `recapito_facoltativo`: "Senza un recapito obbligatorio lo studio potrebbe non riuscire ad avvisare il paziente se l'appuntamento cambia."
- `aperta_senza_verifica` (arancione): "Chiunque può occupare l'agenda senza essere verificato."
- `sms_a_consumo`: "Gli SMS di verifica si pagano a consumo sul credito dello studio, come i promemoria. Tetto attuale: 50 al giorno."
- `predefinito_ignorato`: "Il passo è attivo: il valore predefinito non viene usato."
- `durata_non_compatibile`: "{prestazione} dura {n} minuti e non entra negli slot di {professionista}."

Altri messaggi dell'editor:

- Bozza cambiata da un'altra persona: "Qualcun altro ha modificato la bozza nel frattempo. Ricarica per vedere la versione aggiornata."
- Ripristino: "Ripristinare la revisione 5? La bozza attuale verrà sostituita. La pagina pubblica non cambia finché non pubblichi."
- Pubblicazione riuscita: "Pubblicata la revisione 8. La pagina dei pazienti è aggiornata."
- Logo troppo grande: "Il file supera 1 MB. Caricane uno più leggero."
- Tipo di file non ammesso: "Carica il logo in PNG, JPG o SVG."
- SVG non ammesso: "Questo SVG contiene elementi non ammessi: caricalo in PNG."
- Revisione pubblicata non più valida: "La configurazione pubblicata non è più valida: {motivo}. La pagina dei pazienti si adatta da sola: correggi e pubblica di nuovo."
