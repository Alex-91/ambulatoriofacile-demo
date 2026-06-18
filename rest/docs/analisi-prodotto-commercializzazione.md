# Analisi prodotto e commercializzazione

## Obiettivo

Capire:

- cosa fa davvero il prodotto oggi
- cosa e gia vendibile senza stravolgerlo
- quanto e legato al dominio sanitario
- come trasformarlo in una base piu universale per altri settori

Questa analisi e stata ricavata leggendo il codice applicativo attuale, senza modificare il comportamento dell'app.

## Sintesi esecutiva

Il prodotto oggi non e un semplice calendario con login, ma una piattaforma operativa verticale per studi medici, poliambulatori e strutture sanitarie leggere. I suoi punti forti non sono solo agenda e prenotazioni: il valore reale sta nella combinazione di agenda avanzata, ruoli differenziati, messaggistica strutturata, chat, reminder automatici, gestione dispositivi, OTP multi-canale e controllo granulare della visibilita.

Dal punto di vista commerciale, la strada piu solida non e renderlo subito "per tutti". La strada piu forte e venderlo prima come soluzione verticale sanitaria molto completa, poi estrarre un "core engine" multi-tenant e multi-settore. In altre parole: prima monetizzare il valore che esiste gia, poi generalizzarlo per allargare il mercato.

## Cosa fa oggi il prodotto

La lettura di `app/Config/Routes.php`, `app/Controllers`, `app/Services` e `app/Views` mostra questi blocchi funzionali principali.

### 1. Accesso, identita e sicurezza

- login, logout, registrazione, reset password
- autenticazione OTP
- OTP via push, WhatsApp, SMS, email
- collegamento diretto di dispositivi mobili
- QR code per associare il device
- handoff autenticato da sistemi legacy
- log di consegna OTP

Indicatori nel codice:

- `app/Controllers/AuthMFA/AuthenticationController.php`
- `app/Services/NotificationService.php`
- `app/Services/LegacyLoginHandoffService.php`

### 2. Dashboard modulare e controllo accessi

- homepage composta da "schede" abilitate per utente
- accesso per modulo con controllo server-side
- badge dinamici per posta e chat
- menu e navigazione rigenerati in sessione

Indicatori nel codice:

- `app/Controllers/Home.php`
- `app/Models/SchedeModel.php`
- `app/Services/SessionNavigationService.php`

### 3. Agenda operativa

- calendario agenda
- disponibilita mensile
- blocco e sblocco slot
- generazione e rigenerazione slot
- copie agenda e repair di slot ricorrenti
- gestione note e memo
- visibilita operatori
- ferie e giorni bloccati
- ticket e PDF
- slot extra
- storico e report su slot bloccati

Indicatore forte:

- `app/Controllers/Agenda.php` e uno dei nuclei piu ricchi dell'intero prodotto

### 4. Gestione anagrafica e ruoli

- personale
- clienti
- medici
- segreterie
- infermieri
- sostituti
- sedi e stanze
- visibilita modulo per persona
- relazioni segreteria-medico e infermiere-medico

Indicatori nel codice:

- `app/Services/StaffDoctorAccessService.php`
- `app/Services/StaffDoctorLinkService.php`
- controller `app/Controllers/Admin/*`

### 5. Comunicazione interna ed esterna

- posta strutturata con inbox, inviati, bozze, thread
- allegati temporanei e permanenti
- reply, forward, bulk actions
- chat tra ruoli
- notifiche push per nuovi messaggi
- PDF dei thread

Indicatori nel codice:

- `app/Services/MessageService.php`
- `app/Controllers/Messages/MessageController.php`
- `app/Controllers/Posta/PostaController.php`
- `app/Controllers/Chat.php`

### 6. Prenotazioni utente

- prenotazioni MMG
- prenotazioni specialistiche
- ricerca slot disponibili
- blocco in caso di prenotazione futura gia esistente
- gestione prenotazione da parte del paziente

Indicatori nel codice:

- `app/Controllers/Prenotazioni/MedicoFamigliaController.php`
- `app/Controllers/Prenotazioni/MedicoSpecialistaController.php`

### 7. Reminder automatici e monitoraggio

- reminder appuntamenti
- supporto canale WhatsApp e SMS
- stato invii e lock anti-duplicazione
- monitor amministrativo dei batch reminder

Indicatori nel codice:

- `cron_send_appointment_reminders.php`
- `docs/cron_send_appointment_reminders.md`
- `app/Libraries/WhatsappReminderMonitor.php`

## Dove sta il vero valore commerciale

Il valore piu forte non e in una singola funzione, ma nella combinazione di piu aree che di solito nei gestionali piccoli sono separate:

- agenda operativa reale, non solo booking base
- accessi per ruolo e per relazione organizzativa
- comunicazione integrata
- reminder e automazioni
- sicurezza MFA con device linking
- multi-sede e gestione spazi

Questo rende il prodotto molto piu vicino a una "operational platform" verticale che a un semplice software di appuntamenti.

## Quanto e verticalizzato oggi

L'app e gia modulare nella struttura, ma il dominio sanitario e ancora molto presente nel linguaggio e in varie regole di business.

### Segnali concreti di verticalizzazione

Ricerca nel codice applicativo:

- `medic`: circa `350` occorrenze
- `pazient`: circa `697` occorrenze
- `segreteri`: circa `261` occorrenze
- `infermier`: circa `256` occorrenze
- `ambulator`: circa `400` occorrenze
- `specialist`: circa `56` occorrenze
- `domiciliar`: circa `229` occorrenze

Questi numeri non sono un problema in se, ma dimostrano che oggi il prodotto e semanticamente centrato su sanita e ambulatorio.

### Vincoli attuali piu forti

1. Ruoli hardcoded

- `DOTTORE`
- `PAZIENTE`
- `SEGRETERIA`
- `INFERMIERE`

Riferimento:

- `app/Config/MessageRoles.php`

2. Relazioni organizzative specifiche del dominio

- segreteria-medico
- infermiere-medico
- paziente-medico assegnato

Riferimenti:

- `app/Services/StaffDoctorAccessService.php`
- `app/Services/StaffDoctorLinkService.php`

3. Lessico di interfaccia e branding

- manifest PWA descritto come applicazione ambulatorio
- viste e testi orientati a medico, paziente, ambulatorio, visite domiciliari

Riferimento:

- `public/manifest.json`

4. Flussi verticali

- MMG
- specialisti
- visite domiciliari
- regole di reminder e testi paziente-oriented

5. Debito tecnico che ostacola l'allargamento

- controller e service molto grandi
- molte query dirette di dominio
- accoppiamento elevato tra logica, schema dati e ruoli reali
- cifratura direttamente dentro molte query SQL

I due hotspot piu evidenti sono:

- `app/Controllers/Agenda.php`
- `app/Services/MessageService.php`

## Cosa vendere adesso

### Posizionamento consigliato subito

La versione attuale va posizionata come:

`suite operativa per studi medici, specialisti e piccoli poliambulatori`

Non come:

- semplice agenda online
- software generico per qualsiasi business
- CRM universale

### Motivo

Nel sanitario leggero il prodotto ha gia un vantaggio credibile perche unisce:

- gestione agenda
- comunicazione interna
- comunicazione con il paziente
- reminder
- controllo accessi per staff
- workflow reali di front office e back office

Se lo presenti subito come prodotto troppo generico rischi di indebolire il messaggio e di promettere adattabilita che oggi nel codice non e ancora completa.

## Settori in cui si puo estendere

### Estensione facile o naturale

1. Poliambulatori e centri specialistici

- fit: molto alto
- sforzo: basso
- motivo: il prodotto e gia nato per questo

2. Fisioterapia, riabilitazione, logopedia

- fit: alto
- sforzo: medio-basso
- motivo: agenda, ruoli, reminder, multi-operatore e comunicazioni sono gia coerenti

3. Odontoiatria e medicina estetica

- fit: medio-alto
- sforzo: medio
- motivo: molte fondamenta sono riusabili, ma cambiano naming, workflow e alcuni campi operativi

4. Veterinaria

- fit: medio
- sforzo: medio
- motivo: pattern organizzativo simile, ma bisogna introdurre la relazione cliente-animale e adattare testi e flussi

### Estensione possibile ma non immediata

5. Studi professionali con prenotazioni e team interni

- fit: medio-basso oggi
- sforzo: alto
- motivo: i concetti strutturali esistono, ma sono ancora troppo medici nel modello e nell'interfaccia

6. Wellness, beauty, servizi alla persona

- fit: medio
- sforzo: medio-alto
- motivo: servono modelli piu commerciali, servizi, pacchetti, listini, tempi standard, politiche di appuntamento diverse

### Non consigliato come primo target

7. Gestionale universale per qualsiasi settore

- fit: basso oggi
- sforzo: molto alto
- motivo: manca ancora un layer astratto di dominio sopra il lessico sanitario

## Come generalizzarlo senza rompere il valore attuale

La generalizzazione non va fatta cancellando il sanitario. Va fatta introducendo un livello superiore piu astratto, lasciando il verticale sanitario come primo "pacchetto".

### Modello concettuale consigliato

Sostituzioni logiche:

- `dottore` -> `professionista` oppure `resource_owner`
- `paziente` -> `cliente`
- `segreteria` -> `coordinatore`
- `infermiere` -> `operatore`
- `ambulatorio` -> `sede`
- `stanza` -> `risorsa` oppure `room`
- `specializzazione` -> `categoria servizio`
- `visita domiciliare` -> `servizio esterno` oppure `appuntamento off-site`

Attenzione: non significa rinominare tutto subito nel database. Significa creare un layer di configurazione e presentazione che permetta al prodotto di mostrare terminologia diversa a seconda del vertical.

### Primo livello di generalizzazione consigliato

1. Terminologia configurabile

- dizionario per etichette UI
- nomi ruolo configurabili per tenant
- testi email, OTP, reminder e messaggi parametrizzabili

2. Feature flags per modulo

La base esiste gia con il sistema di `schede` e visibilita modulo.

Da evolvere verso:

- agenda
- messaging
- chat
- booking self-service
- reminder
- multi-sede
- home service

3. Ruoli configurabili sopra i ruoli tecnici

Internamente puoi mantenere ruoli tecnici, ma esporre ruoli commerciali per settore:

- sanitario: dottore, paziente, segreteria, infermiere
- terapia: terapista, paziente, reception
- veterinaria: medico veterinario, proprietario, assistente
- servizi: consulente, cliente, coordinatore

4. Template di workflow per vertical

Esempi:

- sanitario base
- poliambulatorio
- fisioterapia
- veterinaria

Ogni template decide:

- naming
- moduli attivi
- campi visibili
- testi reminder
- eventi di calendario

5. Adapter per servizi esterni

Conviene isolare meglio:

- SMS
- WhatsApp
- email
- push

La base attuale e valida, ma la versione commerciale deve partire con provider disattivati di default e attivabili via configurazione per cliente.

## Strategia commerciale consigliata

### Fase 1. Monetizzare il verticale che hai gia

Proposta:

- vendere il prodotto come soluzione per studi medici e poliambulatori
- usare la demo su dataset pulito e branding neutro
- mantenere la farmacia come ramo cliente separato

### Fase 2. Creare un "core" e dei pacchetti verticali

Struttura consigliata:

- `core`: autenticazione, ruoli, schede, agenda base, messaggi, notifiche
- `vertical sanitario`: MMG, specialisti, pazienti, sostituzioni, domiciliari
- `vertical terapia`: paziente, terapista, sale, cicli appuntamento
- `vertical veterinaria`: proprietario, animale, medico, sale

### Fase 3. Spingere il modello multi-tenant

Ogni tenant dovrebbe avere:

- branding
- terminologia
- moduli attivi
- canali reminder
- sedi e risorse
- ruoli esposti

## Packaging commerciale

Il mercato dei software di prenotazione e practice management tende a usare piani modulari con extra per reminder, multi-sede, ruoli avanzati, API, database dedicato e strumenti di comunicazione. Questo pattern e coerente con quanto mostrano i siti ufficiali di Doctolib, SimplyBook.me, Fresha e TIMIFY.

### Esempio di pacchetti

1. Start

- agenda
- utenti base
- una sede
- reminder email

2. Team

- piu operatori
- chat e posta interna
- booking online
- reminder WhatsApp o SMS

3. Pro

- multi-sede
- ruoli avanzati
- log operativi
- report
- configurazioni verticali

4. Enterprise o Custom

- tenant dedicato
- branding dedicato
- flussi custom
- integrazioni
- onboarding e migrazione

### Add-on sensati

- pacchetto reminder
- attivazione WhatsApp
- multi-sede
- onboarding dati
- personalizzazione testi
- supporto prioritario

## Priorita tecniche prima di allargare davvero il prodotto

### Priorita alta

1. Configurazione tenant

- branding
- lessico
- feature flags
- canali attivi

2. Neutralizzazione dei testi

- rimuovere testi hardcoded da viste e controller
- spostare il piu possibile in config o language files

3. Riduzione dell'accoppiamento nei moduli centrali

- estrarre sotto-servizi da `Agenda.php`
- estrarre componenti da `MessageService.php`

4. Configurazione provider esterni

- provider disattivi di default
- attivazione esplicita per ambiente e cliente

### Priorita media

5. Demo dataset universale

- utenze fake
- scenari credibili
- nessun riferimento reale

6. Piano migrazioni e installazione pulita

- seed iniziale
- onboarding nuovo tenant
- setup da zero piu semplice

7. Catalogo servizi e tipi appuntamento

- maggiore astrazione rispetto alla coppia MMG/specialisti

## Cosa non fare subito

- non riscrivere tutto per renderlo "genericissimo"
- non cambiare prima la farmacia in produzione
- non partire con troppi settori contemporaneamente
- non vendere la piattaforma come universale prima di avere terminologia, ruoli e workflow configurabili

## Raccomandazione finale

La scelta piu forte e questa:

1. tenere la farmacia come ramo cliente e ambiente separato
2. preparare una demo pulita e neutra della versione commerciale
3. vendere prima il verticale sanitario, che oggi e gia molto forte
4. costruire subito dopo un layer di generalizzazione sopra ruoli, lessico, moduli e template
5. allargare in un secondo momento verso settori adiacenti, non verso "tutti"

Se devo sintetizzarlo in una frase:

`oggi hai gia un buon prodotto verticale; la commercializzazione piu intelligente e usarlo per entrare nel mercato, non appiattirlo troppo presto.`

## Fonti esterne usate per il benchmark

Fonti ufficiali consultate per confrontare posizionamento e pattern commerciali:

- Doctolib agenda online: <https://info.doctolib.it/agenda-online/>
- Doctolib altre specialita: <https://info.doctolib.it/altre-specialita/>
- SimplyBook.me pricing: <https://simplybook.me/pricing>
- Fresha for business features: <https://www.fresha.com/for-business/features>
- Fresha reminder knowledge base: <https://www.fresha.com/help-center/knowledge-base/calendar/167-send-appointment-reminders>
- TIMIFY plans: <https://www.timify.com/es/plans/>
