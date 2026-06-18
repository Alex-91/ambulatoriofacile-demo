# Deploy demo con Coolify

Questo progetto e' stato preparato per essere pubblicato su Coolify con il minimo numero di passaggi manuali.

## Cosa e' gia pronto

- `Dockerfile` per build automatica in Coolify
- `docker/start-container.sh` per creare cartelle runtime, linkare gli upload legacy e lanciare le migration
- `coolify.env.example` con le variabili da incollare in Coolify
- `.gitignore` e `.dockerignore` per evitare di caricare segreti e file runtime
- fix codice per:
  - URL base PWA
  - link notifiche
  - path upload legacy
  - path OpenSSL Windows non compatibili con Linux

## Scelta dominio

Scelta consigliata:

- demo separata: `demo.ambulatoriofacile.it`

Scelta possibile ma piu delicata:

- sottopercorso: `ambulatoriofacile.it/demo`

Usa il sottopercorso solo se l'intero dominio `ambulatoriofacile.it` e' gia gestito dal server/proxy che sta dietro a Coolify. Se il sito principale e' altrove, la strada semplice e robusta e' il sottodominio `demo.ambulatoriofacile.it`.

## Quello che devi fare tu

### 1. Crea il repository GitHub

Se questa cartella non e' ancora su GitHub:

1. Crea un nuovo repository vuoto nel team GitHub dove siete gia entrambi.
2. Inizializza git in questa cartella.
3. Fai il primo commit.
4. Collega il remote.
5. Fai push su `main`.

Comandi tipici:

```powershell
git init
git add .
git commit -m "Initial Coolify-ready demo setup"
git branch -M main
git remote add origin https://github.com/ORG_O_UTENTE/NOME_REPO.git
git push -u origin main
```

### 2. Fatti invitare bene in Coolify

Non usare l'account del tuo amico condiviso. La soluzione pulita e':

1. lui ti invita nel team Coolify con il tuo account
2. tu accetti l'invito

Messaggio pronto da mandargli:

```text
Invitami nel team Coolify con il mio account personale, non con login condiviso.
Poi dimmi se il dominio root ambulatoriofacile.it punta gia al server di Coolify.
Se no facciamo la demo su demo.ambulatoriofacile.it.
```

### 3. Chiedi questi dati una volta sola

Ti servono solo questi:

1. URL del server Coolify
2. conferma se il dominio root e' gia su quel server
3. accesso al team/progetto Coolify
4. conferma del repository GitHub da collegare

## Cosa fare dentro Coolify

### 1. Crea il database MySQL

1. Entra nel progetto `ambulatoriofacile`
2. Crea una risorsa `MySQL`
3. Salva:
   - host interno
   - database
   - username
   - password
   - porta

### 2. Crea l'applicazione

1. Crea una nuova `Application`
2. Source: `GitHub App`
3. Repository: questo repo
4. Branch: `main`
5. Build Pack: `Dockerfile`
6. Port: `80`

### 3. Configura il dominio

Se usi sottodominio:

- `demo.ambulatoriofacile.it`

Se usi sottopercorso:

- `ambulatoriofacile.it/demo`

Poi imposta in env:

- `app.baseURL`
- `APP_CANONICAL_URL`

con lo stesso valore finale.

### 4. Incolla le env

1. Apri il file `coolify.env.example`
2. Copia tutto in Coolify
3. Sostituisci i placeholder `CHANGE_ME`
4. Per il DB usa i valori della risorsa MySQL appena creata

### 5. Monta i volumi persistenti

Devi montare questi path dentro il container:

1. `/var/www/html/upload`
2. `/var/www/html/rest/writable`

Se non lo fai, al redeploy rischi di perdere sessioni, log e allegati.

### 6. Deploy

Fai partire il primo deploy.

Il container:

1. installa le dipendenze Composer
2. prepara le cartelle runtime
3. collega `rest/upload` a `upload`
4. esegue le migration automaticamente
5. avvia Apache

## Test del primo deploy

Dopo il deploy verifica:

1. apertura login
2. login corretto
3. caricamento CSS e JS
4. upload allegati
5. creazione sessione
6. eventuale invio email/OTP

## Flusso futuro con Codex

Da quel momento il flusso e':

1. lavori in locale con Codex
2. Codex modifica codice o migration
3. push su GitHub
4. Coolify redeploya
5. vedi subito la modifica live in demo

## Regola importante per il database

Per cambiare la struttura DB:

1. fai una migration in `rest/app/Database/Migrations`
2. pusha il codice
3. lascia che il deploy lanci `php rest/spark migrate --all --no-header`

Evita modifiche strutturali fatte a mano direttamente sul DB live, se puoi.

