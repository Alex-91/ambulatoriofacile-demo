# Poliambulatorio: copertura della demo

Implementazione locale sul branch `codex/poliambulatorio-amministrazione`. Nessun rilascio o aggiornamento della demo congelata eseguito.

| Richiesta | Disponibilità | Dettaglio |
|---|---|---|
| Agenda multi-medico e multi-stanza | Modulo esistente | Risorse e appuntamenti nell'agenda. |
| Accettazione e paziente | Aggiunta | Arrivo, attesa, visita, conclusione, collegamento appuntamento e paziente. |
| Cartella clinica e documenti sanitari | Modulo esistente, da abilitare nel tenant | Cartella, referti, allegati e consensi. |
| Prestazioni e listini | Aggiunta | Catalogo, branche, listini e tariffe versionate. |
| Fatturazione elettronica | XML e demo del canale | XML FPR12 per destinatari azienda, conservazione del file preparato, esiti manuali e simulatore separato. Nessun invio SdI reale o certificazione XSD. |
| Sistema Tessera Sanitaria | Modulo esistente | Attivazione e collaudo del profilo reale separati. Incassi parziali e note di credito del nuovo registro non sono automaticamente riconciliati con TS. |
| Incassi, note di credito e fatture | Aggiunta | Emissione, pagamenti parziali, rate, rimborsi, storni, residui e protezione dei documenti registrati. |
| Compensi professionisti | Aggiunta | Importo fisso o percentuale, maturazione su fatturato o incassato, liquidazioni e recuperi. |
| Convenzioni, assicurazioni, SSN | Gestione amministrativa aggiunta | Listino, quota ente/paziente e autorizzazione. Portali assicurativi e flussi regionali SSN non collegati. |
| Report per medico, branca, prestazione e periodo | Aggiunta | Fatturato, storni, netto e incassi; riepilogo compensi per professionista. |
| Export commercialista / Passepartout | Universale per demo | Prima nota CSV con conti, ordine colonne e separatore configurabili; simulazione esiti. Import nativo Passcom/Mexal da adattare e collaudare dopo la selezione del cliente. |

## Accesso e installazione

Aprire la voce autonoma **Fatturazione poliambulatori** (`admin/fatturazione-poliambulatori`). Il modulo richiede autenticazione, autorizzazioni amministrative e abilitazione `polyclinic_billing` del tenant. Il master piattaforma la trova nelle funzionalità dello spazio: è spenta di default e non dipende da `billing`. Uno spazio può avere solo Fatturazione, solo Fatturazione poliambulatori, entrambe o nessuna. La cartella clinica mantiene le proprie abilitazioni.

Per installare le tabelle usare `php rest/spark polyclinic:install <tenant-id>` per il controllo e aggiungere `--apply` per applicare. Usare un tenant di demo isolato con dati sintetici. Lo schema si trova fuori da `Database/Migrations`: il deploy e `migrate --all` non installano questo modulo. Non usare il database live o quello della demo congelata per i test. Installazione e abilitazione sono due operazioni distinte; disabilitare la funzione non elimina i dati.

Le fatture del poliambulatorio risiedono in `pc_documents`, quelle classiche in `billing_documents`: liste, report, incassi, numerazione, export e PDF leggono l'archivio del rispettivo modulo. Nessuna conversione automatica delle fatture preesistenti. Emittente e impostazioni contabili del poliambulatorio sono autonomi. I precedenti collegamenti e calcoli introdotti nel cruscotto/scadenzario/PDF classici sono stati rimossi. Un eventuale archivio della prima versione sperimentale viene segnalato dall'installatore e richiede una migrazione esplicita, senza spostamenti automatici.

## Percorso dimostrativo

1. Configurare branca, professionista, prestazione, listino, convenzione e regola compensi.
2. Selezionare un paziente sintetico, registrare l'arrivo e aggiungere le prestazioni.
3. Passare ad attesa, visita e conclusione; emettere la fattura.
4. Registrare un acconto, verificare il residuo, impostare rate e mostrare il compenso maturato.
5. Mostrare nota di credito e rimborso, quindi report per medico e prestazione.
6. Configurare i conti e scaricare la prima nota CSV.
7. In **Commercialista e XML**, eseguire la simulazione universale scegliendo accettazione, scarto o esito incerto. Le simulazioni hanno riferimenti `DEMO-`, non usano rete e non alterano lo stato fiscale dei documenti. La simulazione SdI usa solo una fattura aziendale sintetica.

## Verifiche e limiti

Test dedicati su importi al centesimo, quote ente/paziente, compensi, storni, report, replay idempotenti, XML immutabile, simulazioni e rendering delle sei sezioni. Verifica su SQLite e su istanza MySQL 8.3 separata con soli dati sintetici; regressioni dei servizi di fatturazione e collegamento TS.

Separazione verificata con 34 test e 255 asserzioni sia su SQLite sia su MySQL isolato: abilitazioni indipendenti e revoca, menu, archivio classico invariato dopo emissione/incasso/storno nel nuovo modulo, report e scadenzario classici invariati, funzionamento senza schema billing, export separato e generazione PDF.

La preview `ops/polyclinic-ui-preview.php` è una rappresentazione di sola lettura con dati sintetici, utile al controllo grafico: non sostituisce un tenant di demo installato. I test automatici non costituiscono collaudo presso SdI, Sistema TS, Passepartout o enti regionali.
