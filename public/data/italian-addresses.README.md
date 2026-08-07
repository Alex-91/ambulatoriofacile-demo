# Dati italiani per l'autocompletamento degli indirizzi

Il file `italian-addresses.json` contiene un estratto compatto di comuni attuali,
comuni storici, province e CAP. Viene caricato localmente dal browser e non invia
le ricerche dell'utente a servizi esterni.

Fonte: [Database dei comuni italiani di Garda Informatica](https://www.gardainformatica.it/database-comuni-italiani),
derivato e normalizzato da fonti ufficiali tra cui ISTAT. Il dataset è distribuito
con licenza MIT; si veda `italian-addresses.LICENSE.txt`.

Per aggiornare lo snapshot:

```powershell
.\ops\update-address-reference-data.ps1
```

Lo script individua l'ultima versione pubblicata, genera soltanto i campi usati
dall'applicazione e aggiorna la data incorporata nel JSON.

I CAP sono forniti come suggerimenti e non come vincolo: l'utente può sempre
inserire manualmente comune, provincia o CAP, anche compilando un solo campo.
