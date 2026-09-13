# Rilascio preparatorio FSE — 13 settembre 2026

Autorizzazione utente: pubblicare su entrambi i target demo e login, mantenendo
separata l'attivazione degli invii dall'accreditamento nazionale e regionale.

## Perimetro del commit

Base `bb805a1ccf92c9e618f8b5e71a2ca57828e0b71c`, già presente su `origin/main`.
Il precedente rilascio clinico ha incluso il resto della preparazione FSE.
Qui cambiano soltanto quattro servizi applicativi: FseAuditService,
FseDocumentService, FseRevisionService e nuovo FseLocalPersistenceService.
Inclusi test, harness sintetici e verbali; gli harness sono esclusi dall'immagine
pubblica dalle regole Docker già in vigore. Nessun certificato, chiave, dato
clinico, configurazione locale o volume del laboratorio incluso nel commit.

Nessuna nuova migration FSE, modifica al Dockerfile, sostituzione di framework,
aggiornamento delle dipendenze o attivazione di moduli per gli studi. Conservati
gli aggiornamenti già su main, escluso il branch PACS separato e lasciato intatto
il checkout condiviso di sviluppo. Il worktree di rilascio è separato.

## Preflight sulla copia da pubblicare

Framework di produzione conservato: CodeIgniter 4.6.0. Copiate solo le librerie
di test nelle directory vendor ignorate; nessun `.env` o database copiato.

- Raccolta FSE: 274 test PHP / 1.319 asserzioni, 24 documentali Python e 44
  packaging/harness, **342 esecuzioni**, senza fallimenti, errori o skip.
  Report privato nel worktree:
  `rest/writable/fse-validation-reports/20260913-192144-c5ca85ca/summary.json`,
  `passed_offline`; sorgenti censiti invariati durante e dopo il run.
- Regressione clinico-amministrativa circoscritta: 45 test / 233 asserzioni,
  senza errori o skip; `rest/writable/clinical-release-scoped-junit.xml`.
- Compatibilità con le priorità WhatsApp già su main: 12 test / 57 asserzioni
  e, separatamente, sette test / 37 asserzioni. Solo fixture in memoria.
- Sintassi PHP valida per gli otto file PHP dell'incremento.
- Le precedenti prove MySQL/guasti Linux riguardano l'immagine circoscritta
  documentata nel [verbale atomico](fse2-salvataggi-atomici-20260913.md), non una
  ricostruzione completa dell'immagine produttiva finale.

Il primo tentativo della suite clinica completa ha saltato sei test HTTP che
richiedono il diverso laboratorio di compatibilità: non è contato come superato.
La ripetizione usa una selezione esplicita di 45 test compatibili, non aggira
la protezione dei sei casi esclusi. Un primo comando WhatsApp eseguito dalla
directory errata si è fermato nel bootstrap; ripetuto dalla directory `rest`.

## Controlli remoti e limiti operativi

Prima del rilascio, entrambi i target erano `running:healthy`, collegati al
repository atteso e a `main`. Ultimi deployment conclusi: demo `06025229…`,
login `bb805a1c…`. La demo viene quindi anche allineata alla versione main già
pubblicata su login; l'ulteriore migration presente riguarda campi WhatsApp
additivi/idempotenti. Non si lanciano riordini di campagne o invii di prova.

Nessuna variabile `FSE2_*` configurata nelle applicazioni Coolify al preflight:
valgono i default `allowProduction=false` e `allowToscanaStage=false`.
Il runtime documentale operativo, il materiale fiduciario e la configurazione
per ogni struttura non sono installati né abilitati da questo rilascio.
Pubblicare il codice non rende automaticamente operativo l'invio FSE e non
sostituisce accreditamento, collaudo ufficiale o firma qualificata.

Rilascio tramite `ops/release-prod.ps1 -Target both` da main pulito e allineato.
Il primo health check dello script può rispondere ancora dalla versione
precedente: per dichiarare concluso il rilascio occorre verificare per ciascun
target il deployment `finished` del commit atteso, poi `running:healthy` e HTTP.
L'esito effettivo viene registrato separatamente dopo la pubblicazione.

In caso di problema, usare la precedente revisione compatibile; nessun rollback
schema indiscriminato, eliminazione di allegati o retry automatico verso FSE.
