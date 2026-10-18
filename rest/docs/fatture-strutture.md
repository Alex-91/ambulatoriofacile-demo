# Fatture a strutture

Voce di menu **Fatture a strutture**, disponibile con il modulo Fatturazione e le stesse autorizzazioni amministrative. Percorso: `admin/fatturazione-strutture` → **Nuova fattura a struttura**.

Consente di intestare un documento a cliniche, aziende o enti senza visita o paziente: ragione sociale, partita IVA/codice fiscale, indirizzo, codice destinatario e PEC. Le righe delle prestazioni sono libere. Sono disponibili bozza, conferma definitiva, anteprima e PDF con intestazione aziendale. Per queste fatture il totale comprende prestazioni, IVA calcolata sull'imponibile e bollo inserito dall'operatore.

L'archivio dedicato mostra le ultime 250 fatture a strutture; i documenti partecipano anche all'archivio generale, allo scadenzario e ai report. La numerazione è quella comune del modulo Fatturazione, con le protezioni già esistenti per i documenti definitivi.

I dati aziendali e il tipo di destinatario sono salvati nello snapshot del documento (`template_snapshot_json`); i campi storici `patient_name`, `patient_tax_code` e `patient_email` contengono l'intestatario per compatibilità con scadenzario/report. `id_client` rimane nullo: nessuna anagrafica paziente viene creata o modificata. Il tipo di destinatario non è convertibile modificando una fattura già salvata. Nessuna nuova migration è necessaria.

Questi documenti sono esclusi dal Sistema TS sia in salvataggio sia nel controllo di ammissibilità del bridge. Codice destinatario e PEC sono memorizzati, ma questa schermata non esegue invii allo SdI né genera XML. Il PDF aziendale usa un layout dedicato con i dati emittente delle impostazioni Fatturazione.

Verifiche isolate: `tests/unit/BillingBusinessTest.php` con `tests/_support/billing_port_bootstrap.php` (SQLite in memoria, senza `.env`), regressioni dei servizi BillingDocumentService e BillingTsBridgeService, e `php tests/billing_business_views_regression.php` per i form aziendale/paziente. Artefatti sintetici in `rest/build/business-billing/`.
