# Grafica fatturazione coerente con il mockup

La voce Fatturazione apre l'archivio operativo: sidebar scura, schede, riepiloghi, filtri e dettaglio laterale selezionabile. Il dettaglio usa documenti, quote, incassi e compensi reali. Le funzioni opzionali rispettano le abilitazioni del singolo spazio. I riepiloghi si riferiscono ai documenti filtrati tra gli ultimi 250 caricati; il report completo rimane accessibile. L'incassato nel riepilogo è relativo ai documenti selezionati dai filtri, non a un periodo di cassa indipendente.

Restano le azioni esistenti di PDF, anteprima, modifica, incasso, nota di credito, email, sollecito e selezione cumulativa TS. I POST continuano a usare gli endpoint protetti e il CSRF; la selezione TS viene azzerata per le righe nascoste dai filtri. Le note di credito riducono il fatturato e non duplicano i rimborsi già nel registro incassi. Sidebar basata sui menu autorizzati, icone locali, layout adattato a telefono.

Nessuna modifica a dati, migrazioni, numerazione, impostazioni di fatturazione, default TS/bollo o modelli PDF. Il servizio BillingWorkspacePresenter legge e prepara soltanto dati per la visualizzazione.

Verifiche: 59 test PHPUnit, 417 asserzioni; browser su interfaccia reale con fixture sintetiche (desktop, mobile, spazio base, vuoto, filtri, dettaglio, riepiloghi e form cumulativo TS), regressioni browser designer/autocomplete/gestione avanzata. Immagini di collaudo in rest/build/billing-workspace, escluse dai commit.

Comandi dalla root: node rest/tests/billing_workspace_browser.cjs; dalla cartella rest: php -d xdebug.mode=off vendor/bin/phpunit --no-coverage --filter 'UnifiedBillingTest|Polyclinic|BillingTsBridgeServiceTest|BillingDocumentServiceTest|BillingReport|BillingEmail|BillingPortRegressionTest|TsOperationClosureTest|TsReconciliationTest'.

## Correzione menu

Ripristinati header e menu applicativi originali. Lo stile del mockup è limitato al contenuto della fatturazione; rimossi sidebar scura, marchio alternativo e relativo toggle mobile. Nessuna modifica ai dati o alle funzioni. Verifica browser su desktop/mobile e sul registro incassi superata.
