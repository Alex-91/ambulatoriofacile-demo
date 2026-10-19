# Esportazioni e area commercialista

Integrazione del 19 ottobre 2026, branch `codex/esportazioni-area-commercialista`.

## Funzioni

- Archivio fatture e liste fatture TS: selezione indipendente dall'idoneità TS, CSV, XLSX e PDF / ZIP con download AJAX e rinnovo dei token CSRF. Il pulsante TS resta disponibile solo se tutta la selezione è idonea. Limiti: 500 documenti per selezione, 50 PDF per archivio.
- Report filtrati: CSV, XLSX e PDF. Le esportazioni XLSX conservano identificativi come testo, importi come numeri e date come date Excel. Nessuna formula viene generata dai dati.
- Sei colonne XLSX predefinite: Numero fattura, Data, Destinatario, Importo, Imponibile, Marca da bollo. Subtotale e bollo provengono dai valori salvati; nessun bollo viene aggiunto durante l'esportazione.
- Configurazione XLSX nella configurazione fatturazione, riservata a `tenant_master`. Sono disponibili i 24 campi del sorgente e sei campi aggiuntivi già conservati nei documenti per strutture: partita IVA, CAP, provincia, paese, codice destinatario e PEC. I valori assenti restano assenti. Nessuna classificazione del modello esterno, data visita o riferimento a note di credito viene inventato.
- Le preferenze sono salvate per tenant nelle preferenze della feature fatturazione. Il commercialista le legge senza modificarle. I salvataggi degli altri pannelli conservano le colonne XLSX; i documenti storici non vengono riscritti.
- Gestione deleghe da dashboard/configurazione fatturazione: invito email, anno iniziale, accettazione, revoca e ultimi 100 eventi di audit. Inviti validi 7 giorni, link di accesso 15 minuti, sessione limitata a 2 ore. I token sono casuali, salvati come hash e consumati una volta; l'apertura GET del link non li consuma.
- Portale `/commercialista`: più deleghe, ricerca e filtri, soli documenti definitivi dall'anno autorizzato, report, PDF singoli, ZIP del periodo (massimo 100 fatture con riepilogo CSV) e selezioni CSV/XLSX/PDF-ZIP.

## Adattamenti rispetto al sorgente

Il sorgente identifica il medico con `id_personale`. Questa destinazione isola invece l'archivio documentale per **spazio/tenant e database**: una delega autorizza l'archivio di fatturazione dello spazio, non una cartella clinica né un account personale legacy. Il titolare dello spazio (`tenant_master`) gestisce la delega. Se lo spazio contiene più professionisti, la delega copre il suo archivio fatture; non esiste nel modello di destinazione una colonna medico con cui introdurre un ulteriore filtro.

Le tre tabelle delle deleghe sono nel database **platform**, usando `tenant_id` e `tenant_label`. Le fatture sono lette esclusivamente tramite `BillingTenantDatabaseContextService`. I controlli richiedono tenant attivo e abilitazione effettiva della fatturazione, senza bypass locale. Ogni download ricontrolla la delega immediatamente prima della consegna; un cambio del periodo autorizzato durante la generazione invalida il risultato.

Il filtro globale impedisce al delegato di entrare in agenda, pazienti, API operative, impostazioni e modifiche cliniche. Il portale non eredita il binding del database runtime del medico; le pagine sensibili escludono toolbar e caching. Accettazione, accesso, revoca ed esportazioni POST sono protetti dal filtro CSRF già usato nella destinazione.

Sono mantenuti branding, navigazione, designer, numerazione e modifiche preesistenti della destinazione. Il servizio PDF usa la factory e lo storage temporaneo della destinazione. Il mittente degli inviti deve essere quello configurato nell'ambiente, senza fallback del progetto sorgente.

## Attivazione ancora da eseguire

Nessun database configurato è stato migrato durante questo task. Dopo aver scelto l'ambiente di destinazione, dalla sua directory `rest`:

```text
php spark billing:install-accountant
php spark billing:install-accountant --apply
```

La prima esecuzione verifica soltanto; `--apply` crea le tre tabelle dedicate sul collegamento `platform`, non sui database dei tenant. La migration è `app/Database/Accountant/AddAccountantDelegations.php` e include rollback. Il comando è idempotente sulle tabelle già create. Le preferenze XLSX usano lo schema già esistente e non richiedono migrazioni dei documenti.

Servono le dipendenze PDF già installate nella destinazione, estensione PHP ZIP, cache scrivibile e un mittente email configurato. La consegna SMTP reale e l'applicazione su MySQL dell'ambiente scelto restano da verificare in fase di attivazione. Non è stato effettuato alcun deploy, né sono stati inviati messaggi o create deleghe reali.

## Verifiche eseguite

- `tests/accountant_access_regression.php`: migration reale up/down su SQLite; token, scadenza, replay, cooldown, revoca, multi-tenant, sessioni, abilitazioni, filtro isolamento, controller reali, CSV/XLSX/PDF/ZIP, contenuti autorizzati, selezioni, limiti PDF, nomi ZIP sicuri, preferenze separate/preservate e rifiuto delle modifiche XLSX da non titolari.
- La suite usa il bootstrap sintetico che non carica `.env`. Il double `tests/_support/accountant_synthetic_boundaries.php` sostituisce soltanto il connettore verso database SQLite separati e il controllo/riparazione schema MySQL. **Non costituisce un collaudo delle migrazioni MySQL dei tenant.**
- `tests/billing_xlsx_regression.php`: colonne, ordine, valori, date, bollo zero, codici con zeri iniziali, testi simili a formule, tutte le colonne opzionali ed export vuoto. Lettura indipendente del risultato con `openpyxl`; confronto A:F con il foglio `Fatture emesse` del modello fornito.
- `tests/billing_csv_download_test.cjs`: endpoint, download ripetuti, rinnovo CSRF, errori e MIME CSV/XLSX/PDF/ZIP.
- `tests/billing_xlsx_controls_test.cjs`: selezione/ordine, serializzazione, rifiuto di selezione vuota e assenza di overflow mobile.
- `tests/billing_export_views_fixture.php` e `tests/billing_export_views_test.cjs`: viste reali con dati sintetici, selezione pagina/tutti i risultati, code TS, export anche senza TS attivo, desktop/mobile e assenza di errori JavaScript. Le richieste del browser sono intercettate; nessun backend operativo viene usato.
- Regressioni preesistenti: preferenze, header fatture, numerazione (incluse 60 emissioni concorrenti) e viste strutture. Lint dei 31 file PHP del port e controllo whitespace.

Su Windows il sandbox segnala non scrivibile la cache PDF e impedisce il profilo temporaneo Edge: le suite PDF/browser sono state eseguite con permessi estesi, sempre su fixture e runtime sintetici. Per PHP usare una directory temporanea scrivibile (`-d sys_temp_dir=...`) se quella del sandbox non consente la chiusura degli ZIP.

## File del port

Nuovi componenti applicativi:

- `app/Controllers/AccountantController.php`, `app/Controllers/Admin/AccountantDelegationsController.php`
- `app/Services/AccountantDelegationService.php`, `AccountantAccessPolicy.php`, `AccountantTenantAccess.php`
- `app/Services/BillingXlsxExportService.php`, `BillingDocumentExportService.php`
- `app/Filters/AccountantIsolationFilter.php`
- `app/Database/Accountant/AddAccountantDelegations.php`, `app/Commands/InstallAccountantArea.php`
- `app/Views/accountant/{accept,documents,footer,header,home,manage}.php`
- `../public/assets/js/billing-csv-export.js`

Integrazioni puntuali su file esistenti:

- `app/Config/Routes.php`, `app/Config/Filters.php`
- `app/Controllers/Admin/BillingReportsController.php`, `app/Controllers/Tenant/BillingSettingsController.php`
- `app/Services/BillingReportService.php`, `app/Services/BillingDocumentSettingsService.php`
- `app/Views/admin/billing/{dashboard,documents,reports}.php`, `app/Views/admin/ts/documents.php`, `app/Views/tenant/billing_settings.php`

I file test sono quelli elencati sopra. Le altre modifiche locali del repository non fanno parte di questo port e sono state mantenute.
