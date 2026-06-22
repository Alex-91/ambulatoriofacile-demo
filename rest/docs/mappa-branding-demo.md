# Mappa branding demo

## Obiettivo

Elencare i punti del codice piu esposti per la demo commerciale, in modo da sapere dove intervenire dopo senza toccare a caso l'applicativo.

Questa mappa non cambia il comportamento dell'app: serve solo a guidare il refactoring demo e a mantenere coerente il branding.

## Brand attuale desiderato

Il brand da esporre nella demo e:

- `AmbulatorioFacile`
- descrizioni coerenti con una piattaforma operativa moderna

## Punti ad alta visibilita

### PWA e browser shell

- `public/manifest.json`
- `public/sw.js`
- `public/notifications/icon.svg`
- `public/notifications/badge.svg`

### Accesso e sicurezza

- `app/Controllers/AuthMFA/AuthenticationController.php`
- `app/Controllers/Profilo.php`
- `app/Controllers/Compose/ComposeController.php`
- `app/Controllers/PushController.php`
- `app/Views/login/login.php`
- `app/Views/auth/auth.php`
- `app/Views/auth/link.php`
- `app/Views/password/scaduta.php`
- `app/Views/register/register.php`
- `app/Views/reset/reset.php`
- `app/Views/reset/cambio.php`

### Dashboard e moduli principali

- `app/Views/index.php`
- `app/Views/agenda/index.php`
- `app/Views/posta.php`
- `app/Views/Posta/read.php`
- `app/Views/chat/index.php`
- `app/Views/chat/thread.php`
- `app/Views/compose/compose.php`

### Prenotazioni

- `app/Views/prenotazioni_mmg/*`
- `app/Views/prenotazioni_spec/*`

### Admin

- `app/Views/admin/*`

## Termini di dominio ad alta frequenza

Dalla lettura del codice emergono spesso:

- `medico`
- `paziente`
- `segreteria`
- `infermiere`
- `ambulatorio`
- `specialista`
- `domiciliare`

## Rischio di refactoring

### Basso rischio

- titoli HTML
- meta tag
- testi manifest
- testi branding visivo
- copy della demo

### Medio rischio

- testi notifiche
- testi email OTP
- label di interfaccia condivise

### Alto rischio

- nomi variabili di dominio
- query SQL
- nomi campo database
- logica ruoli
- regole MMG e specialistiche

## Strategia consigliata

Intervenire in questo ordine:

1. branding demo esterno
2. titoli e descrizioni
3. testi utente piu visibili
4. etichette configurabili
5. solo dopo, eventuale generalizzazione piu profonda

## Nota importante

Termini come `ambulatorio` dentro il modello dati non vanno toccati subito.

Per la demo commerciale conviene prima cambiare:

- cio che vede l'utente
- cio che sente in presentazione
- cio che appare in notifiche e schermate principali

e solo dopo valutare un vero layer astratto sopra il dominio.
