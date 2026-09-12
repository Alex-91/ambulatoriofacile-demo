# Laboratorio PACS/DICOM

Solo dati sintetici. Gli script sono esclusi dall'immagine di produzione tramite
.dockerignore. Nessuno script legge il file .env dell'applicazione o connette database live.

## Test unitari e controller

Runtime PHP con estensioni curl, SQLite e dipendenze di sviluppo già disponibili:

```
php rest/vendor/phpunit/phpunit/phpunit -c ops/pacs-validation/phpunit.xml --filter "DicomWebClientTest|PacsServiceTest|PacsControllerTest" --do-not-cache-result
```

Bootstrap dedicato: database SQLite in memoria, chiave di cifratura casuale per test,
directory writable casuale. I test Orthanc richiedono esplicitamente PACS_SYNTHETIC_LAB.

## Orthanc reale

1. Con Python e cryptography eseguire `python ops/pacs-validation/prepare-lab.py`.
   Genera una directory casuale sotto rest/writable/pacs-labs, certificato TLS valido
   due giorni, credenziali casuali, compose.json e due piccoli DICOM completamente
   sintetici. Nessun file reale viene importato.
2. Avviare `docker compose -p <project restituito> -f <run>/compose.json up -d`.
   La sola porta è 127.0.0.1:18443, nessuna porta DIMSE. Storage tmpfs 128 MB, limite
   memoria 512 MB e una CPU. Non avviare se la porta è già occupata.
3. Eseguire `python ops/pacs-validation/seed-lab.py <run>` nello stesso ambiente di rete.
   Carica esclusivamente i due file generati tramite STOW-RS.
4. Impostare PACS_SYNTHETIC_LAB alla directory e lanciare la suite completa con
   `--fail-on-skipped --log-junit <run>/phpunit.xml`.
5. Fermare il solo progetto tramite `docker compose -p <project> -f <run>/compose.json down`.
   Conservare i rapporti. La distruzione del container perde solo lo storage sintetico.

Su questa macchina WSL non inoltra la porta a Windows; il test completo è stato
eseguito in un container PHP Linux sulla rete host di Docker. Non esporre il PACS
su 0.0.0.0 per aggirare questa separazione. Se WSL si arresta a sessione chiusa,
tenere il container collegato con `docker start -a <nome container del laboratorio>`.

Il trasporto di produzione rifiuta loopback e indirizzi privati. Solo la sottoclasse
nel test Orthanc ridefinisce la risoluzione di pacs.example.test su 127.0.0.1;
la verifica TLS resta attiva e usa il certificato sintetico. Nessun bypass è
configurabile nel prodotto.

Immagine Orthanc bloccata:
`orthancteam/orthanc@sha256:99082b87c96d56e57472d703ad799b779da7aa35aedac830d58cce646a43643f`
(tag 26.7.0, Orthanc 1.12.11, DICOMweb 1.23).
