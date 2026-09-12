# Priorità campagne WhatsApp per appuntamento

## Comportamento

Le nuove campagne a tutti i pazienti fissano un giorno limite, stimato con i
destinatari unici, la coda precedente dello stesso spazio, i limiti giornalieri
e gli slot di invio 07:30-22:30 Europe/Rome. Il passo minimo è 10 minuti.
La stima non garantisce consegna/lettura né l'arrivo prima di una visita imminente.

Prima vengono i pazienti con una visita futura valida entro tutto il giorno
limite, ordinati per inizio effettivo dell'appuntamento. Poi vengono tutti gli
altri per cognome e nome, inclusi quelli con visite successive al giorno limite.
Le visite annullate o trascorse non danno priorità. Per riferimenti legacy si
mantiene la stessa corrispondenza id_client/id_paziente dell'agenda.
Un recapito condiviso riceve un solo messaggio e assume la prima visita utile.
Il giorno limite non viene spostato a ogni invio o a ogni riavvio.

I nuovi campi sono additivi: `send_order` sui destinatari e `priority_plan_json`
sulla campagna. Gli ID dei destinatari non vengono rinumerati. Gli stati, i
recapiti, il testo e i riferimenti al provider restano invariati durante il riordino.
Il vecchio dispatcher può continuare a funzionare anche dopo la migration.

## Rilascio durante la pausa notturna

Eseguire soltanto dopo le 22:35 e con sufficiente margine prima delle 07:30.
Non effettuare il push di main in anticipo: l'ambiente potrebbe avere autodeploy.

1. Controllare branch, working tree e remoto; integrare solo il branch approvato
   su main e fare push. Non includere gli altri lavori locali.
2. Eseguire `ops/release-prod.ps1 -Target login -ConfigPath <config-locale>` da main
   pulito e allineato al remoto. Attendere il deploy del commit previsto e healthy.
3. Verificare che `whatsapp-campaign-dispatch` sia abilitato ogni 10 minuti e che
   conservi la guardia Europe/Rome. Il setup corretto è in
   `ops/setup-whatsapp-campaign-dispatch.ps1` (usare esplicitamente `-Target login`).
4. Installare solo lo schema necessario se non già applicato dal bootstrap:
   `php /var/www/html/rest/spark whatsapp-campaigns:install-priority-schema --no-header`.
   È la stessa implementazione della migration versionata; è idempotente.
5. Leggere l'anteprima con
   `php /var/www/html/rest/spark whatsapp-campaigns:prioritize --tenant ID --active --no-header`.
   `--active` richiede esattamente una campagna a tutti i pazienti attiva nel tenant;
   in caso di ambiguità usare `--campaign ID` dopo aver identificato la campagna.
   L'output contiene soltanto ID e conteggi, nessun testo o recapito di pazienti.
6. Applicare lo stesso comando aggiungendo `--apply`. Il comando rifiuta invii
   ancora processing, tenant errati e applicazioni fuori dalla pausa notturna.
   Il salvataggio è transazionale e tocca esclusivamente pending della campagna.
7. Conservare l'output `applied` o `already_planned`, poi ripetere l'anteprima:
   deve risultare `already_planned`, con la stessa data limite e gli stessi conteggi
   di pianificazione. Rimuovere gli eventuali task diagnostici temporanei di Coolify.
8. Confermare healthy, cadenza invariata e pausa notturna; comunicare il risultato.

In Coolify i comandi dei task devono restare entro 255 caratteri. Non usare la
console di invio per testare il gateway: di notte il worker deve rispondere
`outside_window`. Nessun messaggio di prova deve essere inviato ai pazienti.

## Verifiche locali

Da `rest`, con PHP intl/mbstring/sqlite3 e Composer disponibili:

```powershell
$env:XDEBUG_MODE='off'
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap tests/_support/campaign_priority_bootstrap.php tests/unit/WhatsAppCampaignPlanTest.php tests/unit/WhatsAppCampaignPriorityTest.php
```

In un worktree si può impostare `AF_CAMPAIGN_TEST_AUTOLOAD` al vendor/autoload.php
di un'installazione già presente ed eseguire il suo binario PHPUnit.
I test usano esclusivamente record sintetici su SQLite in memoria, senza gateway.
Coprono conteggi/date, ora legale, limiti/coda precedente, ordinamento, duplicati,
lettura degli appuntamenti, preview senza scritture, isolamento tenant, invii
in corso, conservazione di stati e ID, rollback e idempotenza.

## Recupero

Se schema o riordino falliscono, non ricreare la campagna e non azzerare gli invii.
Un errore prima del commit annulla tutti i nuovi ordini; ripetere il comando solo
dopo aver risolto la causa. `already_planned` è un successo idempotente.
Un eventuale rollback del codice può lasciare i campi additivi nel database:
la versione precedente li ignora e usa gli ID originali. Non eliminare dati.
