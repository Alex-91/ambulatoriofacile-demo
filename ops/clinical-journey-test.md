# Collaudo percorso clinico

Ambiente separato: https://af-clinical-test.178.104.113.107.sslip.io/login

- Coolify application `q1w9od977d8o2xqnfnl511su`, branch `codex/clinical-journey-test`.
- Database test privato preesistente `uapmyovmgml4ov24y4d94tao`; fixture limitate al tenant 4, database `af_ambiente_di_test`.
- Storage propri `af-clinical-test-upload` e `af-clinical-test-writable`.
- Invii esterni disabilitati dal runtime `AF_NAVIGATION_TEST`; nessuna modifica ai target demo/login.
- `AF_CLINICAL_JOURNEY_TEST=1` abilita la scheda sperimentale e la voce Agenda / Accettazione e lista esami. Il servizio rifiuta ambienti diversi dal database test previsto.

## Fixture

Eseguire nel container test come **www-data**, non root:

`php rest/spark clinical:seed-journey-test`

Richiede `AF_JOURNEY_FIXTURE_PASSWORD` di almeno 24 caratteri, da passare solo al processo. Non committare né stampare password. Il comando crea medici Giuseppe e Anna, segreteria Sara, sei pazienti sintetici, un tipo visita e dieci giorni di slot dalle 09:00 alle 15:00. Non sovrascrive appuntamenti esistenti. Il giorno iniziale è quello di esecuzione.

Account fixture: `giuseppe.percorso@example.test`, `anna.percorso@example.test`, `segreteria.percorso@example.test`. Credenziali nel file locale ignorato `rest/writable/billing-unify-20260927/clinical-test-access.local.json` del checkout principale.

## Giro manuale

1. Accedere come medico o segreteria. L'agenda e la finestra di prenotazione restano quelle esistenti.
2. Prenotare uno slot libero selezionando un paziente TEST e il tipo Ecografia addominale TEST.
3. Riaprire l'appuntamento e scegliere **Apri esame**, oppure Agenda / Accettazione e lista esami.
4. **Accetta paziente**; la segreteria si ferma qui. Il medico assegnato prosegue con **Avvia esame** e **Concludi e scrivi referto**.
5. Scrivere e salvare la bozza, confermare il testo, generare e scaricare il PDF definitivo.
6. Provare i pulsanti esplicitamente denominati **Simula firma** e **Simula consegna**. Non firmano il documento e non trasmettono nulla.

La firma esterna verificata, la consegna protetta reale e il collegamento a un ecografo reale richiedono configurazione/implementazione e collaudo separati. I risultati simulati non aggiornano lo stato `signed` nell'archivio clinico. La nuova tabella di prova non sostituisce o modifica lo storico PACS esistente.

## Controlli

`php rest/spark clinical:check-journey-test` (come www-data) percorre un appuntamento sintetico, verifica separazione dei medici, permessi della segreteria, revisione concorrente, PDF reale e immutabilità. Lascia il primo appuntamento di prova completato, gli altri restano disponibili.

Il rilascio in produzione resta subordinato alla verifica dell'utente. Questa versione è deliberatamente vincolata al test: non è una migrazione produttiva del percorso.
