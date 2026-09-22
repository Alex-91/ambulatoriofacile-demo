# Invii massivi email e SMS

La pagina `/login/spazio/invii-massivi` è disponibile al responsabile dello spazio anche senza moduli notifiche o WhatsApp. I vecchi URL `invii-whatsapp` restano compatibili e aprono la nuova pagina.

- Email sempre selezionabile. SMS ammesso solo con la funzione `appointment_notifications_sms` abilitata per lo spazio dal Super Master, in Gestione spazi → Abilitazione canali dello spazio → SMS. Il controllo viene ripetuto al momento dell'invio.
- Si può selezionare uno o entrambi i canali. Con entrambi, l'ordine scelto viene salvato nella campagna. Il secondo è un fallback: viene tentato solo se il primo restituisce un errore o non trova un recapito valido. L'accettazione del provider/SMTP termina l'invio; non è una garanzia di lettura né una gestione dei bounce tardivi.
- Un tentativo ogni 60 secondi per spazio, condiviso fra tutte le campagne e anche fra primo canale e fallback. Spazi diversi hanno slot indipendenti.
- Tabelle `platform_mass_campaigns`, `platform_mass_campaign_recipients` e `platform_mass_campaign_rate_limits`, senza gateway, code fallback o rate limiter di WhatsApp. Il trasporto email/SMS riutilizza le configurazioni dello spazio.
- Gli invii rimasti in stato `processing` dopo un'interruzione vengono segnalati come esito incerto dopo 15 minuti, senza reinvio automatico che potrebbe duplicare un messaggio già accettato.
- Nel registro e nel dettaglio, **Pausa** sospende campagne in coda o in corso. Un tentativo già prenotato dal worker può terminare, senza riattivare la campagna. **Riprendi** continua solo dai destinatari ancora in attesa, mantenendo l'eventuale secondo canale di fallback e il limite temporale già prenotato. Le campagne completate non si riaprono. I comandi sono riservati al responsabile dello spazio e protetti con POST e CSRF.

## Installazione e scheduler

La migration `2026-09-22-100001_CreateMassCampaignTables.php` crea soltanto le nuove tabelle. Per un aggiornamento mirato è disponibile `php spark mass-campaigns:install-schema`, idempotente; la migration ordinaria può comunque essere eseguita successivamente.

Eseguire `php spark mass-campaigns:run` ogni minuto. `--diagnose-schema` verifica esclusivamente le tre tabelle delle campagne.

Il comando già pianificato `whatsapp-campaigns:run` resta compatibile: usa la nuova coda email/SMS e mantiene separatamente la riconciliazione dei promemoria WhatsApp esistenti. Non deve essere rimosso senza mantenere la pianificazione della riconciliazione dei promemoria. L'eventuale esecuzione contemporanea dei due comandi condivide lo stesso lock e limite delle campagne.

Le vecchie campagne WhatsApp e i loro fallback restano conservati nelle tabelle precedenti, ma non vengono più elaborati né convertiti automaticamente. Il registro della nuova pagina mostra soltanto le nuove campagne email/SMS.

## Verifica locale

`php vendor/bin/phpunit tests/unit/MassCampaignServiceTest.php tests/unit/MenuRegistryServiceTest.php --no-coverage` dalla cartella `rest`.

I test usano SQLite in memoria e trasporti simulati: verificano selezione canali, destinatari senza cellulare, deduplicazione per paziente, fallback, isolamento spazi, limite temporale e interruzioni. Non effettuano invii reali né accedono ai database configurati. Il test SQLite non verifica i lock concorrenti MySQL.
