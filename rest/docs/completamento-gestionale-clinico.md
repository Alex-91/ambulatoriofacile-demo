# Completamento gestionale e cartella clinica

12 settembre 2026 · branch `codex/completamento-gestionale-clinico`.
Implementazione e collaudo locali, senza rilascio. Lavoro FSE preesistente
preservato. Nessun uso di produzione, demo congelata o progetto dell’amico.

## Stato per ambito

| Ambito | Risultato | Stato |
| --- | --- | --- |
| Fatturazione | Regressioni documenti/pagamenti, CSRF esteso, CSV sicuro, cache PDF privata, email/solleciti con trasporto simulato; accettazione SMTP distinta da errore nello storico | Consolidamento locale verificato |
| Sistema TS | Presa in carico atomica, blocco reinvii incerti, riconciliazione manuale con prova PDF cifrata, rettifiche/annulli serializzati, abbandono locale tracciato, esito e stato padre nella stessa transazione | Implementato e verificato; canale reale da collaudare con profilo abilitato |
| Agenda | Conflitti medico/stanza anche su slot distinti, prenotazione/aggiornamento/cancellazione, riuso risorsa e concorrenza | Consolidamento locale verificato |
| Accessi/allegati clinici | Controlli server per spazio, paziente, ruolo e relazione di cura; cifratura, integrità, download privati, audit e blocco cancellazione anagrafica con cartella | Implementati e verificati |
| Aggiornamenti | Regressioni con framework 4.6.0 attuale e 4.7.4 candidato e dipendenze candidate isolate | **Non ancora adottati: restano collaudo Linux/proxy e rilascio preparatorio** |
| Backup | Strumento cifrato per database/radici dichiarati, restore su istanza nuova, verifica file/tabelle e lettura cartella ripristinata | Procedura provata; pianificazione e retention produttive da attivare |
| Cartella | Visite, anamnesi, allergie, terapie, diagnosi, note, referti, bozze, definitivi e correzioni collegate | Implementata e verificata |
| Consensi | Modelli versionati, prove, dichiarante/operatore, concessione/diniego/presa visione/revoca; condivisione condizionata dossier | Implementati e verificati; testi effettivi a cura della struttura |
| Storico | Prestazioni correttamente collegate, documenti clinici, consensi e referti FSE scaricabili | Implementato e verificato; legacy senza collegamento univoco da bonificare separatamente |
| Firma | PAdES/CAdES esterna, controllo contenuto/firmatario/catena/revoca, originali immutabili ed evidenze | Collaudata con certificati sintetici; prova sul prodotto reale e materiale fiduciario operativo necessari |

Non tutti i punti sono chiusi in produzione. Docker/Podman e una distribuzione
WSL utilizzabile non erano disponibili: il laboratorio Linux preparato nel
lavoro precedente non è stato eseguito qui. Non sono stati attivati job backup
produttivi, aggiornamenti del framework o servizi esterni.

## Collaudo integrato successivo

Il percorso paziente–consenso–visita–referto/firma–fattura–pagamento–preparazione
TS è stato verificato nel laboratorio isolato. Corretti salvataggio rapido
anagrafica, relazione medico/cartella e conversione IVA esente per TS.
Risultati, limiti e contesto Linux aggiornato in
[Collaudo del percorso paziente](collaudo-percorso-paziente.md).
I conteggi seguenti descrivono il collaudo precedente; la suite clinica aggiornata
passa con 47 test e 269 asserzioni su entrambe le varianti.

## Verifiche eseguite

- `clinical-closure.xml`: **45 test, 254 asserzioni**, nessun errore/skip su
  baseline e candidato. Servizi clinici reali su SQLite, permessi, consensi,
  file, immutabilità, TS, email simulate e regressioni fatturazione/ponte TS.
- `application-compat.xml`: **67 test, 289 asserzioni** su entrambe le varianti.
  Le suite hanno test in comune: i conteggi non vanno sommati come casi unici.
- Suite PHP FSE con filtro `Fse`, framework candidato e integrazione artefatti:
  **262 test, 1.277 asserzioni**, nessuno skip. Include il nuovo test sul download
  storico FSE della cartella oltre alle regressioni precedenti.
- `test_validator.py`: **24 test** passati (CDA, PDF/A e firme). Soli avvisi del
  logger Xdebug non scrivibile in un sottoprocesso, nessun test fallito.
- `test_clinical_signature.py ClinicalSignatures`: **5 test** con sottocasi,
  firme valide e rifiuto di alterazioni, altro contenuto/firmatario, dati aggiunti,
  pagina sostituita, certificati revocati/scaduti e revoca mancante.
- HTTP clinico PHP/MySQL: **66 controlli** passati, con PDF Dompdf, firme vere
  su certificati sintetici PAdES/CAdES, isolamento tenant/anonimo, CSRF,
  consensi/revoca e richieste obsolete. Nessun invio FSE.
- Browser: accesso, inserimento e finalizzazione referto; layout controllato.
  PDF renderizzato con Poppler e verificato visivamente, anche il footer finale.
- Agenda MySQL: **13 controlli**, inclusa prenotazione simultanea nella stessa
  stanza con un solo vincitore. Cancellazione singola e multipla ora condividono
  la procedura transazionale.
- TS MySQL: due processi creano una sola variazione; due operazioni collegate
  concorrenti ottengono una sola presa in carico dell’invio.
- Comando installazione: controllo e `--apply` idempotente su due tenant sintetici.
- Backup: **4 database, 740 file**, ripristinati con conteggi/checksum coincidenti.
  Respinti chiave errata, archivio corrotto, sorgente come destinazione e target
  non vuoto. Cartella ripristinata leggibile, due firme integre e revoca mantenuta.
- Sintassi: **152 file PHP** controllati, nessun errore. `git diff --check` sui
  principali file modificati del task senza errori di whitespace.

## Evidenze locali

Laboratorio dipendenze:
`rest/writable/fse-dependency-compat/e90fe89c1b0f4b2ba1d127e9c53a127f`.
Provenienza delle esecuzioni:

- `application-candidate-b53981a4e939104d/provenance.json` (clinico candidato);
- `application-baseline-c82b6f31e36ee35a/provenance.json` (clinico baseline);
- `application-candidate-6382b9e41ed1bb7c/provenance.json` (app candidato);
- `application-baseline-7ad72e1eccee8854/provenance.json` (app baseline).

Laboratorio framework:
`rest/writable/fse-framework-compat/2c92718f32d648f29f0a22ddc362c3b6`,
regressione FSE `run-candidate-819164318bd0b3c9`.

Laboratorio applicativo:
`rest/writable/fse-app-labs/5a698e6c209d491283906f6ad65a050b`:

- `clinical-http-fda15eec4acc47b1a1b641ae1660cd53/report.json`;
- `agenda-report.json` e `ts-concurrency-report.json`;
- `full-backup-drill/report.json` (fotografia prima delle ultime correzioni
  TS/email, non un backup della produzione o della release finale).

I percorsi writable contengono evidenze private locali da non committare.
Gli script lab richiedono i marker sintetici previsti e non leggono `.env` reale.

## Uso e attivazione

Guide: `rest/docs/cartella-clinica-operativita.md` e `ops/full-backup.md`.
La firma è indipendente dal marchio attraverso file standard; non è una garanzia
di compatibilità con ogni prodotto/versione/variante. Limiti esatti nella guida.

Restano distinti dallo sviluppo: prova col dispositivo/provider reale, gestione
fiducia e revoche, profilo TS abilitato, collaudo regionale FSE Umbria, processo
oppure servizio di conservazione, adozione degli aggiornamenti su Linux e rilascio
negli ambienti richiesti. I test non attestano qualifica legale o accreditamento.

Non avviato un nuovo modulo DICOM/PACS. Nessuna email reale, firma a nome di un
medico o pubblicazione sanitaria effettuata.
