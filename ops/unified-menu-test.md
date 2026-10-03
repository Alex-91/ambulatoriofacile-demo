# Preview del menu unificato

La preview usa il branch `codex/unified-menu-test`, senza rilascio su `main`.

- URL: https://af-menu-test.178.104.113.107.sslip.io/login
- App Coolify: `ambulatoriofacile-menu-test`, UUID `vf4is9u9dsyphfshkjwato3j`.
- Ambiente: staging (`sgurxzkkkk06o68phpm3h22v`).
- Database privato dedicato: `uapmyovmgml4ov24y4d94tao`.
- Volumi dedicati: `af-menu-test-upload`, `af-menu-test-writable`.
- Copia DB del 3 ottobre 2026: piattaforma e cinque database tenant. Gli upload di produzione non sono stati copiati.
- Le chiavi di cifratura dati sono state copiate con autorizzazione esplicita. La chiave dei token di accesso e' separata.
- `AF_NAVIGATION_TEST=1` impone il database test e disabilita gli invii esterni nel runtime PHP.

Lo script `unified-menu-test.ps1` accetta `Status`, `Prepare`, `Deploy` e il percorso della configurazione locale ignorata da Git. `Prepare` rigenera la chiave dei token della preview: non ripeterlo senza necessita'.

## Collaudo

`php rest/spark navigation:test-audit` legge conteggi di utenti, collegamenti e credenziali decifrabili solo dal database test previsto. Non stampa password.
L'opzione `fixture` crea due identita' temporanee nel solo test, usando `AF_TEST_AUDIT_PASSWORD`; `cleanup` le rimuove insieme alle relative preferenze. Rimuovere anche il job temporaneo e la variabile dopo il collaudo.

Il refresh notturno `d24ji8ae0gebi8x5r54phknn` dell'app toolbox `zczppid5npwqqfvkiat9up8s` e' sospeso durante la prova per non sovrascrivere le preferenze.

### Esito del 3 ottobre 2026

Codice collaudato online: `2c66671c`, deploy `vh7kgo4d8hniy94vh524afji`.

- Cinque tenant, 26 credenziali cifrate leggibili su 26, nessun collegamento applicativo mancante.
- Login HTTP con identita' sintetica master in tutti i cinque tenant; operatore nei quattro tenant con profilo personale disponibile.
- Per ogni sessione: menu presente, preferenze HTTP 200, Agenda selezionabile, salvataggio della pagina iniziale e verifica dopo nuovo login riusciti.
- Master: passaggio al vecchio menu e ripristino del nuovo riusciti in tutti i tenant. Operatore: cambio menu globale negato con HTTP 403.
- Nessun redirect verso produzione nel collaudo. Login e demo live HTTP 200 e Coolify healthy.
- Regressione menu superata; test sessione: 17 test, 34 asserzioni.

Non sono state usate o cambiate le password personali dei clienti. Questi controlli verificano configurazione e flusso con account sintetici, non equivalgono a una prova manuale di ogni password cliente.

Il database piattaforma copiato incontra una migration legacy che presuppone `dap01_users`. Il comando `navigation:migrate` applica e registra soltanto la migration dichiarata delle preferenze, separatamente; il bootstrap lo esegue dopo il tentativo delle migration generiche. La correzione generale delle migration legacy resta fuori da questo intervento e va valutata prima del rilascio produttivo.

## Dismissione dopo la prova dell'utente

Non eliminare la preview prima che l'utente abbia terminato la verifica. Quando lo conferma:

1. Rileggere UUID, nomi, collegamenti e mount; escludere tassativamente i target live.
2. Eliminare l'app preview e soltanto i suoi due volumi dedicati.
3. Per il database test preesistente, verificare eventuali altri utilizzatori prima della rimozione.
4. Se viene rimosso anche il DB test, mantenere disattivato/rimuovere il relativo job di refresh; non riattivare un job verso una risorsa dismessa.
5. Rimuovere eventuali credenziali temporanee locali e archiviare il worktree solo quando non serve piu'.

Non usare pulizie Docker globali e non rimuovere upload, volumi o database di produzione.
