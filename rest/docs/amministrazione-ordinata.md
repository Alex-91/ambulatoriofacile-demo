# Amministrazione ordinata

La navigazione organizza esclusivamente i collegamenti già autorizzati e visibili. Le sei aree sono Fatture e documenti, Incassi e scadenze, Prestazioni e tariffe, Compensi professionisti, Sistema TS, Report ed esportazioni. Accettazione passa a Oggi e FSE a Esami. Le destinazioni originali rimangono disponibili nelle schede interne; le voci personalizzate non riconosciute rimangono nel menu.

Archivio generale, strutture e incassi condividono filtri per periodo, date di emissione/pagamento/scadenza, medico, destinatario, stato, tipo, metodo, TS e scadenze/email. La selezione copre tutti i risultati filtrati, senza il precedente limite di caricamento di 250 documenti. Cambiando filtri vengono deselezionate le righe che escono dai risultati.

PDF per riga; invio TS singolo e cumulativo sugli endpoint esistenti. Il server mantiene controlli di accesso, idoneità e isolamento del tenant. L'invio usa un campo JSON per evitare troncamenti di max_input_vars. Excel/CSV vengono suddivisi in file da massimo 500 documenti, PDF/ZIP in file da massimo 50; i download sono sequenziali con rinnovo CSRF e arresto al primo errore.

Incassi e scadenze mostra totale incassato, da incassare, scaduto e scade oggi, con conteggi. Le schede locali non cambiano i riepiloghi, mentre i filtri sì. Restano accessibili registro movimenti/liquidazioni, note di credito, email, solleciti, anteprima, configurazione documento, canali SdI e scadenzario dettagliato precedente.

## Verifiche locali

- `php rest/tests/administration_navigation_regression.php`
- `php rest/tests/unified_menu_regression.php`
- `php rest/tests/navigation_layout_regression.php`
- `node rest/tests/administration_workspace_browser.cjs` (Playwright; variabile PHP_BIN se php non è nel PATH)

La fixture `ops/administration-workspace-preview.php` renderizza la vista reale con dati sintetici, senza bootstrap, configurazioni, sessioni o DB. Il test browser blocca connessioni esterne e intercetta export e invii. Verifica selezioni, filtri, routing strutture, CSRF tra lotti, importi, schede incassi, mobile e stato vuoto. Gli screenshot sono generati in rest/build/administration e non fanno parte del rilascio.

Non sono necessarie migrazioni. Non è stato eseguito un rilascio né un invio fiscale reale. Rimane da verificare il flusso completo su un ambiente di test con DB e storage separati; i test sintetici non misurano le prestazioni di archivi molto grandi.
