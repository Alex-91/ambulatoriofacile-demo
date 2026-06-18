# Ambienti e branch

## Obiettivo

Tenere separati:

- il codice prodotto da vendere
- la personalizzazione della farmacia
- i dati reali della farmacia
- le demo e i test

## Struttura consigliata

Il repository Git deve rappresentare il **prodotto**.
Ogni installazione reale o demo deve avere il suo ambiente separato:

- una propria cartella locale
- un proprio file `.env`
- un proprio database
- una propria cartella `upload/`
- una propria cartella `writable/`

## Copie locali consigliate

Fuori da questo repository, la gestione piu sicura e:

- una copia "farmacia" usata per manutenzione e deploy verso Aruba
- una copia "prodotto" collegata a GitHub e usata per evolvere il software
- una copia "demo" solo con dati finti

## Branch consigliati

- `main`: codice universale del prodotto
- `client/farmacia`: personalizzazioni specifiche della farmacia
- `release/demo`: opzionale, se vuoi preparare demo stabili

## Regola pratica sui bugfix

- se il fix e generico, nasce su `main` e poi viene portato su `client/farmacia`
- se il fix nasce dalla farmacia ma e utile a tutti, si pulisce e si riporta anche su `main`
- se una modifica serve solo alla farmacia, resta su `client/farmacia`

## Regola pratica sui dati

Non trattare mai il database locale come sorgente ufficiale della farmacia.
Per la farmacia in produzione la sorgente ufficiale e il server Aruba.

Il locale serve per:

- sviluppare
- testare
- preparare un rilascio

Non serve per sovrascrivere alla cieca i dati del server.
