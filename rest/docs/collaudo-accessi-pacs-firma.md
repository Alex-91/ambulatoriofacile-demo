# Accessi del personale e collaudo PACS

## Disattivazione

Il responsabile dello spazio può cercare il personale in **Modifica personale** e usare **Disattiva accesso**. Il blocco è separato dalla scadenza password: cambiare la password non lo rimuove. Non cancella il personale, i pazienti, gli appuntamenti o i documenti.

Il controllo viene ripetuto a ogni richiesta con sessione, incluse richieste AJAX e passaggi OTP. Le sessioni già aperte sono invalidate alla richiesta successiva. Una richiesta già in esecuzione al momento del blocco può terminare; non è una cancellazione di processi in corso. Login legacy, handoff e accesso piattaforma verificano il blocco prima di attivare il profilo. Anche i servizi clinici lo verificano.

Il responsabile non può disattivare sé stesso né un account amministrativo tramite questa pagina. Il blocco conserva autore e data. La riattivazione non è esposta da questa funzione: richiede una procedura amministrativa distinta, comprensiva di revoca delle precedenti sessioni.

Per ogni database tenant, installare la migration prima di usare il pulsante:

```text
php spark personnel:install-access TENANT_ID
php spark personnel:install-access TENANT_ID --apply
```

La migration è idempotente e crea soltanto `personnel_access_blocks` con chiave composta tenant/utente. Gli ambienti non ancora migrati conservano il comportamento precedente e non mostrano il comando di disattivazione. Nessuna abilitazione clinica o PACS viene modificata.

## Verifica PACS

Il master apre **Lista diagnostica → Verifica collegamenti PACS**. Sono richiesti Cartella clinica e PACS abilitati. La pagina mostra schema, cifratura, stato delle credenziali e configurazione download/visualizzatore senza stampare segreti, riferimenti alle variabili private o indirizzi tecnici.

Il pulsante invia una richiesta QIDO al solo profilo configurato per lo spazio. Usa un identificativo tecnico casuale, limite 1 e risposta limitata a 8 KiB. Accetta solamente una risposta vuota compatibile; risposte contenenti dati non sono esposte né memorizzate. Mantiene i controlli HTTPS, DNS pubblico, timeout e divieto di redirect del trasporto ordinario. I controlli GET non generano traffico verso il PACS.

La verifica QIDO non dichiara riusciti WADO, visualizzatore, MWL o MPPS. Questi passaggi richiedono un esame sintetico concordato col fornitore. Dalla pagina il master può aprire la [preparazione guidata](preparazione-moduli-clinici.md), che consente di inizializzare lo spazio e salvare profili DICOMweb cifrati. Le prove utilizzano soltanto profili già configurati.

## Prove ripetibili senza cliente

```text
php rest/vendor/phpunit/phpunit/phpunit -c ops/pacs-validation/phpunit.xml --exclude-group pacs_lab --fail-on-skipped --do-not-cache-result
php rest/vendor/phpunit/phpunit/phpunit -c ops/pacs-validation/phpunit.xml rest/tests/unit/ClinicalRecordTest.php --do-not-cache-result
```

La suite usa SQLite e storage sintetico, senza `.env`. Include appuntamento → bozza precompilata → conferma medico → accettazione master → esecuzione medico → immagine con identità/UID/accession corrispondenti → referto → PDF definitivo, con chiave cloud. Verifica anche cambi di appuntamento, isolamento, CSRF e divieto di modifica del referto definitivo. Questo test esercita i servizi e la generazione PDF; non sostituisce un collaudo della UI di inserimento appuntamenti.

Dalla directory `ops/fse-validation`, con il runtime Python del validatore:

```text
python -m unittest test_clinical_signature.ClinicalSignatures -q
```

Le firme PAdES/CAdES usano esclusivamente certificati e revoche sintetici. Sono rifiutati documenti non firmati, firme alterate, originali differenti, firmatari errati, certificati scaduti o revocati e prove di revoca mancanti. La qualifica legale della firma resta `not_assessed`; non viene simulata un'accreditazione.
