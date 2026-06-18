# Deploy farmacia via FTP

## Principio

Per Aruba puoi continuare a usare FTP, ma solo per pubblicare il **codice**.
I **dati** della farmacia restano sul server: database, file caricati dagli utenti e cartelle runtime non vanno sincronizzati alla cieca dal locale.

## Cosa caricare normalmente

- `app/`
- `public/`
- `system/` solo se il framework viene aggiornato davvero
- `composer.json` se cambia qualcosa lato dipendenze o setup

## Cosa non caricare normalmente

- `.env`
- `writable/`
- `upload/`
- `dist/`
- `tests/`
- `.git/`
- file di backup, dump SQL, export CSV, log locali

## Procedura consigliata

1. Parti dal branch `client/farmacia`.
2. Verifica quali file sono davvero cambiati.
3. Fai un backup minimo del database server.
4. Salva una copia dei file critici che stai per sostituire.
5. Carica via FTP solo i file modificati.
6. Se il rilascio richiede modifiche database, esegui anche la migration o lo script SQL separatamente.
7. Fai un test rapido su login, agenda, messaggi, email e notifiche toccate dal rilascio.

## Regola sui database

Il database locale non va considerato una replica sempre affidabile del server.
Usalo per testare, non per sostituire il database di Aruba a meno di ripristini controllati.

## Regola sulle urgenze

Se fai una correzione urgente nata per la farmacia:

1. applicala nel ramo farmacia
2. pubblicala su Aruba
3. poi riporta la parte generica anche su `main`

Cosi il cliente resta aggiornato, ma il prodotto non si biforca.
