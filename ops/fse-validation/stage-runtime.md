# Collegamento applicazione PHP / validatore — staging isolato

Aggiornamento 11 settembre: predisposto anche il [laboratorio applicativo Linux](linux-lab.md)
con DB sintetici. Creato l'ambiente Coolify dedicato e misurata la capacità:
RAM disponibile insufficiente per procedere sul server condiviso. Il laboratorio
non è stato costruito o avviato; le descrizioni del 9–10 settembre sotto sono storiche.

Questo secondo pacchetto estende il test del solo validatore descritto in
[runtime-container.md](runtime-container.md): usa le classi effettive del prodotto
per avviare il worker Python, generare e validare CDA/PDF/A e rifiutare un CDA
diverso da quello incorporato nel PDF. Non avvia CodeIgniter, Apache, migration,
sessioni, database o collegamenti FSE. Non è il collaudo dell'applicazione completa.

## Stato verificato il 9 settembre 2026

Lo smoke test è passato su Windows / PHP 8.3.6, compreso il rifiuto della
discordanza PDF/CDA. Docker non è disponibile su questo PC: il contesto Linux è
preparato, ma build ed esecuzione Linux NON sono state effettuate. Nessuna modifica
a Coolify, variabili degli ambienti o database. Le radici fiduciarie della firma
clinica restano volutamente assenti; questo test non qualifica una firma.

## Preparazione da Windows

```powershell
./ops/fse-validation/prepare-stage-runtime.ps1
```

Genera una nuova cartella privata sotto `rest/writable/fse-container-contexts`.
Contiene solo la lista ammessa del runtime, i documenti sintetici e due manifest:
SHA-256 dei file del pacchetto e dei sette sorgenti applicativi impiegati. Non
copia certificati Sogei, chiavi, .env, configurazioni private, DB o referti.
Il risultato `PREPARED_NOT_BUILT` non equivale a un collaudo superato.

## Esecuzione futura su host Docker dedicato

Prima creare una **nuova immagine di test** del prodotto dal checkout esaminato,
con il Dockerfile ordinario e `.dockerignore` aggiornato. Non usare né modificare
un'applicazione Coolify esistente; non montare volumi o configurazioni live.
Passare al pacchetto il suo identificativo immutabile `sha256:...` come
`AF_APP_IMAGE`, non inventare un digest e non usare un'immagine obsoleta.

```text
docker build --build-arg AF_APP_IMAGE=<id-immagine-app-test> --tag af-fse-stage-runtime:local <contesto-generato>
docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges --pids-limit 192 --memory 1g --cpus 2 --tmpfs /tmp:rw,nosuid,nodev,size=256m,mode=1777 --tmpfs /var/www/html/rest/writable:rw,nosuid,nodev,size=256m,mode=1777 af-fse-stage-runtime:local
```

La build richiede rete per dipendenze, il test gira senza rete, con utente
`www-data`, senza privilegi e con soli temporanei sintetici. ENTRYPOINT e
HEALTHCHECK del prodotto sono sostituiti: il bootstrap applicativo non parte.
Il JSON viene emesso su stdout prima della rimozione del container: conservarlo
con hash immagine, versione Docker, architettura e inventario dipendenze.

L'esito richiesto è `passed_app_runtime_smoke`, con `system: Linux`, manifest
applicativo corrispondente e `alteration_rejected: true`. Un errore di hash blocca
il test: ricostruire l'immagine giusta, non disattivare la verifica. La build può
fallire per wheel Linux non disponibili: il lock deriva dal runtime Windows e
deve essere verificato su Linux, non aggiornato alla cieca.

Questi comandi sono una ricetta **da collaudare**, non evidenza di esecuzione.
La preparazione non include una scansione vulnerabilità né una qualifica della
catena distributiva del JAR veraPDF: i manifest attestano corrispondenza dei file,
non affidabilità del distributore. Prima di promuovere un'immagine servono digest
base, lock Linux, inventario, licenze e verifiche di sicurezza.

## Ciò che resta separato

Verifica in sola lettura del 10 settembre 2026: demo e login su Coolify risultano
`running:healthy`, health HTTP 200. Nell'inventario non è stato identificato uno
staging FSE dedicato. Il servizio WhatsApp staging non è un ambiente applicativo
FSE e non va riutilizzato. Nessuna risorsa, rete o configurazione remota modificata.
Per ospitare il collaudo completo serve selezionare/preparare un ambiente Linux
isolato, con limiti di risorse e DB/storage nuovi, prima di eseguire questa ricetta.

- Staging dell'intera app con DB/storage esclusivamente di test, migration,
  permessi, sessioni/CSRF, code e interruzioni del worker sul server Linux.
- Firma clinica scelta dalla struttura, catene fiduciarie e revoche.
- Adapter delle risposte CART reali, collaudo regionale e attivazioni ufficiali.
- Accreditamento nazionale: le prove locali non sostituiscono la procedura.

Non abilitare invii veri sulla base di questo smoke test. Riferimento per le
opzioni del Dockerfile: [documentazione ufficiale Docker](https://docs.docker.com/reference/dockerfile/).
