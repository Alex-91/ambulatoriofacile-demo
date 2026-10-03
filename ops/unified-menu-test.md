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

## Dismissione dopo la prova dell'utente

Non eliminare la preview prima che l'utente abbia terminato la verifica. Quando lo conferma:

1. Rileggere UUID, nomi, collegamenti e mount; escludere tassativamente i target live.
2. Eliminare l'app preview e soltanto i suoi due volumi dedicati.
3. Per il database test preesistente, verificare eventuali altri utilizzatori prima della rimozione.
4. Se viene rimosso anche il DB test, mantenere disattivato/rimuovere il relativo job di refresh; non riattivare un job verso una risorsa dismessa.
5. Rimuovere eventuali credenziali temporanee locali e archiviare il worktree solo quando non serve piu'.

Non usare pulizie Docker globali e non rimuovere upload, volumi o database di produzione.
