# Rilascio clinico: abilitazioni per spazio

## Moduli

Il super amministratore della piattaforma abilita per spazio:

- `clinical_records`: Cartella clinica e consensi, inclusi storico, referti,
  allegati e firma esterna PAdES/CAdES.
- `fse2`: FSE 2.0, separato dalla cartella.
- `billing`: Fatturazione.
- `ts_billing`: Sistema TS.

Tutti sono disattivati di default e non sono attivabili autonomamente dal tenant
master ordinario (`is_tenant_managed=0`). Restano validi gli eventuali diritti
già assegnati dal super amministratore, anche tramite pacchetti.

Il catalogo della piattaforma registra la nuova voce tramite il meccanismo
esistente `TenantFeatureService`. Il rilascio non assegna il nuovo modulo ad
alcuno spazio. Il collegamento paziente è omesso quando la cartella è disabilitata;
ogni azione del controller ricontrolla il diritto corrente, prima di connettere
il database clinico. La revoca vale anche con una sessione già aperta.

La cartella non mostra storico o link FSE se quel modulo è disattivato;
il relativo download diretto richiede entrambe le abilitazioni.
L'abilitazione non concede al super amministratore accesso automatico ai dati:
restano i controlli sul personale, sul paziente e sulla relazione di cura.

## Collaudo prima del rilascio

Runtime applicativo baseline (framework 4.6.0 e dipendenze attive), senza
modificarne le installazioni. Il candidato Linux precedente è documentato
separatamente e non viene adottato implicitamente da questo rilascio.

- Suite clinico-amministrativa: 51 test, 287 asserzioni, nessun errore o skip.
- Flusso clinico HTTP: 66 controlli, comprese PAdES/CAdES e consensi.
- Abilitazione/revoca HTTP: 28 controlli. Nove azioni GET/POST respinte dopo la
  revoca, senza alterare tabelle o audit clinici; riabilitazione nella stessa sessione.
- Laboratorio esclusivamente sintetico `6a477cca610f4975ad5e0e6ac23d0788`, fermato.
  Rapporti `clinical-http-ec93a279f50d4257bf944f9fd50fac9d/report.json` e
  `feature-gate-http-4399af9a7a2c4a2a885a18a5a7e4f037.json`.

## Attivazione operativa

Per uno spazio abilitato con database dedicato, verificare lo schema tramite
`php rest/spark clinical:install <tenant-id>` e applicare la migrazione dedicata
con `--apply` se necessaria. Non eseguire installazioni indistinte su tutti gli spazi.

Il runtime di verifica delle firme, il materiale fiduciario e i servizi esterni
richiedono configurazione e collaudo effettivi. L'abilitazione di un modulo
non configura credenziali, non attiva invii e non certifica la firma qualificata.
Il Dockerfile di produzione conserva il proprio runtime: nessuna installazione
automatica dei tool Linux/Python di laboratorio. Le suite e gli harness sintetici
sono esclusi dall'immagine pubblica; rimane il worker documentale operativo.
