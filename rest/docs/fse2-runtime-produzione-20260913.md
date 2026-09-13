# Runtime documentale FSE nell'immagine di produzione

Intervento del 13 settembre 2026. Completa l'installazione del motore locale;
**non costituisce accreditamento, abilitazione regionale o firma qualificata**.
Non modifica schema DB, dati clinici, certificati privati o configurazioni dei clienti.

## Cosa viene installato

Il Dockerfile del prodotto include PHP 8.2.33 Apache Bookworm da digest ufficiale,
Python 3.11, Java 17, veraPDF 1.30.2, SchXslt 1.10.1 e le 30 dipendenze Python
già utilizzate nei collaudi. Runtime pubblico in `/opt/fse`, fuori dalla document
root e non scrivibile dall'utente Apache. Nessun processo Java rimane in ascolto.

- Base PHP e Composer bloccati a digest, wheel Linux bloccate per versione e
  SHA-256; pip deve usare `--only-binary=:all: --require-hashes` e superare `pip check`.
- XSD/Schematron ufficiali RSA 8.3: stessa revisione collaudata
  `687cf371e1d0caf4f5f9f7bcc80eab3a97f09885`, con verifica dei 12 hash.
- Installer veraPDF scaricato dal sito ufficiale: SHA-256
  `6cc6341cb1af644044054b81f00a6590a7918abb18f762243de115258bcad838`.
  Firma GPG verificata il 13 settembre contro l'impronta pubblicata
  `13DD102B4DD69354D12DE5A83184863278B17FE7`. Ogni build ricontrolla l'hash
  dell'archivio autenticato e quello del JAR effettivamente installato.
- SchXslt distribuito dall'autore su Maven Central, SHA-256 verificato;
  archivi rifiutati se contengono traversal, link o nomi duplicati.
- Download solo nella build; il validatore documentale non usa la rete.
  Installer e compilatore conservano i metadati/licenze inclusi nei rispettivi JAR.

La ricetta non promette immagini identiche byte per byte: i pacchetti Debian
ricevono aggiornamenti di sicurezza dai repository della distribuzione.
Il controllo OSV sulle 30 versioni Python e sui due artefatti Maven non ha
restituito segnalazioni il 13 settembre; non copre OS o dipendenze Java transitive.

## Configurazione iniziale e limiti

L'immagine imposta `FSE2_VALIDATOR_PYTHON=/opt/fse/venv/bin/python` e
`FSE2_VALIDATOR_SETTINGS=/opt/fse/settings.json`. Il file contiene catalogo e tool
pubblici, ma `trust_roots`, `crls` e `ocsps` sono vuoti: la firma rimane bloccata.
Certificati Sogei mTLS/JWT di test e radici TLS non sono radici per la firma clinica.

Gli invii reali e Toscana stage restano disabilitati:
`FSE2_ALLOW_PRODUCTION=false`, `FSE2_ALLOW_TOSCANA_STAGE=false`.
Non viene attivato automaticamente il modulo per alcun cliente.

Un worker alla volta per applicazione (`FSE2_VALIDATOR_MAX_CONCURRENT=1`).
La soglia `FSE2_VALIDATOR_MIN_AVAILABLE_MIB=768` controlla memoria disponibile
dell'host e spazio residuo nel cgroup v2, prima di creare il payload privato.
Misura mancante o memoria insufficiente fanno fallire il controllo senza inviare.
È una verifica d'ammissione, **non una prenotazione di RAM né un limite OS**:
demo e login hanno lock separati; documenti grandi o carichi concorrenti richiedono
una prova di capacità dedicata prima dell'apertura operativa ai clienti.

Sonda isolata del server, 13 settembre ore 19:15:53 UTC: MemAvailable 1.390.372 KiB,
disco disponibile 48.327.884 KiB, swap già usata circa 3,4 GiB. Nessun DB o volume
applicativo montato; sonda fermata dopo la misura. Rilasci da eseguire in sequenza.

## Verifiche ripetibili

Ogni build esegue come `www-data` `ops/fse-validation/runtime-self-test.php`:
genera un CDA fittizio e PDF/A-3b, li ricontrolla e rifiuta l'alterazione del CDA.
Usa solo `/tmp`, non avvia CodeIgniter, non legge `.env`, DB o documenti dei tenant.
Un errore impedisce il completamento della build. Report tecnico nell'immagine:
`/opt/fse/build-self-test.json`.

Il collaudo della stessa immagine con `--network none --read-only`, utente 33:33,
tmpfs privato e memoria massima 1 GiB è passato: picco 292.532.224 byte (279 MiB).
Con limite 256 MiB il guard ha bloccato subito l'operazione, picco 7.786.496 byte,
`OOMKilled=false`. Questo dato riguarda la fixture sintetica, non il caso peggiore.

Regressioni Windows sul codice del rilascio: 275 test PHP / 1.329 asserzioni,
24 test documentali Python e 8 test dell'installer superati. La prima esecuzione
PHP era fallita perché il nuovo worktree non aveva la cartella cache privata:
creata la cartella del laboratorio, la suite completa è stata rieseguita con successo.

Anche tutti i 24 test documentali/firme sono passati dentro l'immagine finale Linux,
senza rete, con limite 768 MiB: picco 298.323.968 byte (285 MiB). Le CA/CRL dei
test sono generate nel tmpfs e non aggiunte all'immagine. Primo tentativo incompleto
per un generatore di fixture non montato; montati i tre soli file di test, suite
rieseguita integralmente senza errori. Nessuna chiave clinica o Sogei utilizzata.

## Attivazione operativa successiva

Servono ancora esiti ufficiali dell'accreditamento e collaudo applicabile, credenziali
di produzione, identificativi/configurazione della struttura, procedura di firma
clinica e materiale fiduciario/revoche verificato e mantenuto aggiornato.
Le nuove regole nazionali/regionali possono richiedere adeguamenti ulteriori.
Non abilitare invii o rendicontare un'adesione al bando sulla base di questo report.

La conferma del deploy dei due target va verificata sui job Coolify **terminati**
e sui relativi health check, non sulla sola presenza del commit in `main`.

Riferimenti primari: [verifica del distributore veraPDF](https://software.verapdf.org/),
[installazione veraPDF](https://docs.verapdf.org/install/),
[licenza duale veraPDF](https://docs.verapdf.org/develop/),
[catalogo FSE](https://github.com/ministero-salute/it-fse-catalogs),
[hash checking pip](https://pip.pypa.io/en/stable/topics/secure-installs/).
