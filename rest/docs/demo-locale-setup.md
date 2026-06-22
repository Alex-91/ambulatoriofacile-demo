# Demo locale separata

## Obiettivo

Preparare un ambiente demo locale senza toccare mai:

- la copia farmacia
- il database farmacia
- i dati reali

## Stato attuale

Nel repository sono stati preparati:

- template demo: [environments/demo/.env.example](/C:/xampp_82/htdocs/dottorAppLTE/rest/environments/demo/.env.example)
- file locale demo pronto: [\.env.demo](/C:/xampp_82/htdocs/dottorAppLTE/rest/.env.demo)
- bootstrap schema demo: [tools/InitializeDemoDatabase.php](/C:/xampp_82/htdocs/dottorAppLTE/rest/tools/InitializeDemoDatabase.php)
- copia catalogo sicuro: [tools/CopyDemoCatalogData.php](/C:/xampp_82/htdocs/dottorAppLTE/rest/tools/CopyDemoCatalogData.php)
- seed dataset demo: [tools/SeedDemoData.php](/C:/xampp_82/htdocs/dottorAppLTE/rest/tools/SeedDemoData.php)

## Scelta tecnica adottata

La demo deve usare:

- un database dedicato: `dottorapp_demo`
- un branding dedicato: `AgendaFlow`
- una copia applicativa separata
- dati finti

## Nota importante sul bootstrap

Questo progetto oggi non ha ancora uno schema completamente ricostruibile da zero solo con le migration.

Per questo motivo il bootstrap demo sicuro viene fatto cosi:

1. crea un database demo vuoto
2. clona solo la struttura tabelle dal database prodotto locale `mail`
3. non copia nessun dato
4. lascia il popolamento fake come step separato

Questo approccio:

- non tocca farmacia
- non copia dati reali
- prepara una base tecnica coerente per la demo

## Step catalogo sicuro

Dopo il clone struttura, e disponibile anche uno step che copia solo dati di sistema:

- ruoli
- menu
- permessi menu
- schede
- lookup
- specializzazioni
- calendario giorni rossi

Comando:

```bash
php tools/CopyDemoCatalogData.php --host=localhost --port=3306 --user=root --pass=root --source-db=mail --target-db=dottorapp_demo
```

Questo step continua a non copiare:

- utenti
- clienti
- personale
- messaggi
- appuntamenti
- agenda runtime
- configurazioni cliente specifiche

## Step dataset demo

Dopo struttura e catalogo, il popolamento demo completo si fa con:

```bash
php tools/SeedDemoData.php --env-file=.env.demo
```

Questo script:

- lavora solo su `dottorapp_demo`
- rifiuta target pericolosi come `farmacia` e `mail`
- legge chiave di cifratura e brand dal file `.env.demo`
- ripulisce solo le tabelle runtime demo
- inserisce staff, clienti, sedi, stanze, agenda, chat e posta fake
- genera un report JSON in `writable/demo_setup`

## Step runtime separata

Per costruire una copia applicativa demo standalone dentro il repository:

```bash
php tools/PrepareDemoRuntime.php --env-file=.env.demo
```

Questo step:

- crea `dist/demo-runtime`
- copia codice, `public`, `system`, `upload` e `productization`
- genera `index.php`, `.htaccess` e `spark` dedicati alla runtime separata
- copia `.env.demo` come `.env` nella runtime
- esegue un audit delle referenze statiche mancanti
- scrive un report JSON in `writable/demo_setup`

Verifica utile dal workspace root:

```bash
php dist/demo-runtime/spark routes
```

Nota pratica:

- la verifica va lanciata dalla root del workspace
- nel sandbox locale alcuni comandi lanciati con working directory direttamente dentro `dist/demo-runtime` possono dare falsi negativi sui permessi cache
- questo non tocca la farmacia e non usa il suo database

## Accessi demo creati

Password comune demo:

`Demo2026`

Accessi principali:

- admin diretto: `demo.admin`
- operativo diretto con OTP fisso: `demo.dietista`
- portale cliente demo: `demo.portal.nutri`
- portale cliente sport demo: `demo.portal.sport`

OTP demo utile:

- per `demo.dietista` il codice OTP e sempre `2510`

Accesso impersonato demo:

- `demo.admin->demo.segreteria`
- `demo.admin->demo.frontdesk.sport`
- `demo.admin->demo.fisio1`

Per questi accessi impersonati:

- password: `Demo2026`
- OTP: `2510`

Questo e utile per mostrare rapidamente flussi diversi senza dover configurare SMS o email reali.

## Comando di bootstrap

```bash
php tools/InitializeDemoDatabase.php --host=localhost --port=3306 --user=root --pass=root --source-db=mail --target-db=dottorapp_demo
```

## Cosa fa lo script

- verifica che il source esista
- rifiuta target pericolosi come `farmacia` e `mail`
- crea `dottorapp_demo` se manca
- clona solo le tabelle del source
- salta le view
- scrive un report JSON in `writable/demo_setup`

## Cosa non fa

- non legge o modifica `farmacia`
- non copia dati
- non tocca Aruba farmacia
- non tocca la copia locale farmacia
- non usa dati reali

## Prossimo passo

Dopo il bootstrap schema, il passo corretto e:

1. creare una copia applicativa separata dalla farmacia
2. usare `.env.demo` in quella copia
3. puntare quella copia a `dottorapp_demo`
4. iniziare rifinitura commerciale e demo guidate

Stato aggiornato:

1. database demo separato creato e popolato
2. runtime demo separata generata in `dist/demo-runtime`
3. rotta showcase dedicata disponibile su `demo`
4. audit frontend pronto per guidare il lavoro di rifinitura commerciale

## Regola permanente

Per la demo si lavora solo su:

- codice prodotto
- `dottorapp_demo`
- file `.env.demo`

Mai su:

- `farmacia`
- Aruba farmacia
- dati reali
