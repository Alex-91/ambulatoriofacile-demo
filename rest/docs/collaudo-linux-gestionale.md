# Collaudo Linux del gestionale — 12 settembre 2026

Stato: **COLLAUDO SINTETICO LINUX SUPERATO**. Entrambe le istanze fermate,
evidenze e volumi conservati. Nessuna pubblicazione in produzione.

## Esiti finali

| Verifica | Esito |
| --- | --- |
| PHP FSE | 261 test, 1.274 asserzioni |
| PHP clinico e amministrativo | 47 test, 269 asserzioni |
| Python documentale | 24 test |
| Python firme cliniche | 5 test |
| HTTP FSE | 14 controlli |
| HTTP fatturazione | 27 controlli, incluso autore del catalogo |
| HTTP cartella clinica | 66 controlli, PAdES/CAdES e consensi |
| Ripristino FSE | 10 controlli, 9 artefatti |

Suite senza errori, fallimenti o skip. I conteggi sono esecuzioni per suite;
una verifica storica FSE della cartella è selezionata anche nella suite FSE.
I 35 controlli Windows della ricetta e dei manifest sono passati separatamente.

Immagine finale di entrambe le istanze:
`sha256:10c1973e8bc302115429402e2de5cd48bd38a550fcc6b33b98f50d6fff490b3a`.

Evidenza verificata automaticamente:
`rest/writable/fse-linux-labs/1b6da77e72a44672af463349c28adec6/evidence/summary.json`.
Log unitario finale **`unit-billing-fix.log`**, successivo alla correzione del
catalogo. I log `unit-final.log` e degli altri tentativi restano storici.
`verify-final.py` ricontrolla log, esiti HTTP, identità delle immagini, isolamento
e stato fermo dei container a partire dai file esportati.

I rapporti completi sono nei due `evidence/reports.json`. Riferimenti selezionati:

- FSE HTTP: `http-report-fefd1b7508774f5cb8408b0272e4bfd8.json`.
- Fatturazione: `billing-http-8ec8f7ebb7454a4b8f646faccbf2c195.json`.
- Ripristino: `recovery/bef801b0a0de07e5/result.json`.
- Cartella, nella seconda istanza:
  `clinical-http-538c1596d7084328a24273ad9592e7ec/report.json`.

La preparazione dei tre documenti FSE è avvenuta su un'immagine precedente;
HTTP e ripristino sull'immagine finale hanno ricontrollato gli artefatti
conservati. Non viene presentata come una nuova preparazione sull'ultima immagine.
Le etichette `active` del rapporto HTTP FSE indicano il runtime interno al
container: il contenuto candidato è attestato dai manifest e dall'immagine,
non da quell'etichetta generica.

Snapshot runtime: app e MySQL healthy, app UID33 con root in sola lettura,
capability rimosse e `no-new-privileges`, rete interna e nessuna porta pubblicata.
App limitata a 1 GiB/1 CPU e MySQL a 512 MiB/0,5 CPU. Ogni istanza ha due volumi
Compose e **un volume anonimo aggiuntivo** creato dall'immagine MySQL per
`/var/lib/mysql`; il datadir utilizzato dai test è quello dedicato. Tutti i nomi
dei volumi, gli ID dei container e delle reti sono nell'inventario del riepilogo.

## Perimetro

Distribuzione locale WSL `AmbulatorioFacile-FSE`, Debian 13.5, Docker Engine
29.8.0 e Compose 5.5.1. Immagine candidata con CodeIgniter 4.7.4 e lock delle
dipendenze già preparato; nessuna sostituzione dei vendor o del framework attivi.
Runtime osservato: PHP 8.2.33, Python 3.11.2, Java 17.0.20.1 e MySQL 8.4.11.

Contesto sorgenti `1b6da77e72a44672af463349c28adec6`: 4.108 file applicativi e
51 runtime. I tentativi successivi conservano i log di build separati e aggiornano
esplicitamente i manifest prima della ricostruzione. L'identità dell'immagine
effettivamente provata è riportata nelle evidenze, non dedotta dal tag.

Istanza clinica `819fda12284342c38873010c8648bc62`: soltanto una seconda istanza
runtime con nuovi volumi, riutilizza la stessa immagine immutabile. Non è un
secondo contesto sorgenti. Le due istanze vengono eseguite in sequenza.

Database, utenti, pazienti, documenti, CA e firme sono sintetici. Nessun invio
FSE/TS, email o accesso a servizi di firma reali. Nessun deploy, merge, push,
modifica alla demo congelata o utilizzo di database/storage reali.

## Correzioni emerse

- La ricetta Compose deve usare `$$s` nel comando PHP di health check: `$s`
  viene interpolato da Compose prima dell'esecuzione.
- Il bootstrap dei test crea cache, log e sessioni nel proprio percorso privato.
  Il pacchetto include le fixture PHP/Python usate dai test negativi e dalle viste.
- I test di concorrenza e preparazione fatturazione riconoscono il percorso Linux
  soltanto dopo verifica del marcatore dell'immagine e del laboratorio sintetico.
  Le asserzioni sono mantenute e gli skip sono trattati come errori.
- Il router sintetico Linux carica anche l'autoloader Composer della root, che
  contiene Dompdf e le altre dipendenze del prodotto. La mancanza era nel laboratorio.
- **Prodotto:** il salvataggio del catalogo prestazioni della fatturazione riceve
  ora l'utente piattaforma della sessione. Prima riceveva l'utente locale e poteva
  fallire sul vincolo `platform_users`, oppure attribuire erroneamente l'operazione
  se i numeri coincidevano. L'audit del documento conserva l'utente locale.
  Il test usa identità distinte e la prova HTTP controlla catalogo e autore salvati.

## Gestione del laboratorio

WSL può terminare la distribuzione quando non rimane alcun processo avviato dal
client Windows, anche con servizi systemd attivi. Durante il collaudo è mantenuto
aperto un processo `wsl ... tail -f /dev/null`, chiuso dopo lo stop dei container.
Verifica conclusiva `docker ps`: nessun container in esecuzione nella distribuzione.
Non sono stati cambiati timeout globali, firewall o criteri di riavvio.

`run-wsl-linux-lab.sh up` rifiuta progetti già inizializzati; `resume` riavvia
soltanto i container MySQL e app esistenti. L'init iniziale è riuscito; un successivo
tentativo di rieseguirlo ha correttamente rifiutato il volume già popolato (exit255).
Non è stato cancellato o reinizializzato il database per aggirare il controllo.

## Limiti

La validazione delle firme verifica formati, integrità e catene sintetiche:
`qualified_signature=not_assessed`. Non dimostra compatibilità universale con
ogni token/provider, né accreditamento FSE. I flussi esterni richiedono il loro
collaudo con credenziali e servizi reali.

Il ripristino verifica tabelle FSE, profili, versioni e artefatti selezionati;
non certifica il disaster recovery dell'intero gestionale. Le prove HTTP Linux
non eseguono JavaScript nel browser: il percorso browser Windows precedente è
documentato in [collaudo-percorso-paziente.md](collaudo-percorso-paziente.md).
