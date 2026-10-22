# Bollo nelle fatture

Configurazione Fatturazione → Marca da bollo: calcolo automatico, addebito al paziente/cliente, trattamento delle operazioni senza IVA e modalità di assolvimento. Le configurazioni nuove attivano automatismo e addebito; una configurazione già salvata senza queste preferenze conserva il precedente importo fisso finché lo studio attiva l’automatismo.

In automatico viene proposto un unico bollo di 2 euro quando la somma delle operazioni soggette a bollo supera strettamente 77,47 euro, escluso il bollo stesso. Il controllo viene ripetuto sul server al salvataggio. Con IVA positiva la riga non concorre alla soglia. La natura fiscale per il bollo è una scelta esplicita, distinta dal testo libero stampato e dalla configurazione TS. Per fattispecie non coperte usare la verifica manuale; sono disponibili anche l’esenzione dal bollo e il trattamento per riga delle fatture miste. Le note vengono calcolate sul proprio importo.

Modificare l’importo del bollo attiva la modalità manuale; selezionare nuovamente Automatico ripristina il calcolo. Se il bollo rimane a carico dell’emittente, è visibile nel PDF ma non aumenta il totale, lo scadenzario o l’importo preparato per TS. Negli export Excel sono disponibili Bollo addebitato e Assolvimento bollo, oltre all’importo del bollo indicato nel documento.

Il contrassegno può avere un identificativo facoltativo di esattamente 14 cifre, conservando gli zeri iniziali. Inserirlo dichiara l’apposizione sull’originale cartaceo: il software non acquista né applica il contrassegno. Il bollo virtuale richiede numero e data validi dell’autorizzazione; è disponibile anche l’ufficio autorizzante. Il semplice calcolo del bollo non dichiara l’imposta pagata.

Le scelte sono conservate in `template_snapshot_json.document_stamp`; non serve una modifica dello schema DB. Le righe con trattamento proprio conservano i dati in `line_items_json`. Le nuove fatture mantengono anche la natura IVA salvata quando vengono ristampate. Le fatture storiche non vengono riscritte.

## Limiti dei canali esistenti

La sezione Fatture a strutture genera PDF, ma non genera XML né trasmette allo SdI. La modalità bollo SdI registra soltanto l’intenzione di assolverlo attraverso quel canale: non effettua trasmissioni o versamenti. La generazione XML e l’invio SdI richiedono un intervento distinto.

Il bridge TS attuale gestisce un solo trattamento IVA per documento. Per preparare TS lasciare le righe su Come il documento. Le fatture con trattamento IVA per riga possono essere salvate con preparazione TS disattivata; il bridge blocca anche eventuali tentativi successivi di invio di queste fatture.

## Verifiche isolate

- `php tests/billing_stamp_regression.php`: soglia, impostazioni nuove e preesistenti, natura fiscale, override, identificativo e autorizzazione.
- `php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap tests/_support/billing_port_bootstrap.php tests/unit/BillingBusinessTest.php`: SQLite in memoria, salvataggio/riapertura, fatture miste, note, quota a carico medico e importo TS. Il bootstrap non carica `.env`.
- `php tests/billing_stamp_pdf_regression.php`: stampe sintetiche nei layout classico, designer e strutture; output in `build/stamp/`.
- `node tests/billing_stamp_browser.cjs`: calcolo nel form, override manuale e controlli di assolvimento. Richiede Playwright e l’HTML generato da `billing_business_views_regression.php`; `BROWSER_CHANNEL=msedge` usa Edge.

Nessuna cancellazione di fatture, migration o operazione su database reali fa parte di questi test.
