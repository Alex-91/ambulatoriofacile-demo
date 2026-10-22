# Prenotazione online dei pazienti: esportazione completa del design

Esportazione del progetto Claude Design "Prenotazione online pazienti" di Ambulatorio Facile, aggiornata al 22 ottobre 2026 (canvas versione 6, 44 tavole). Contiene tutte le tavole, comprese quelle dell'area amministrativa (A01-A05), più il prototipo del paziente, la guida di implementazione e le email. Non serve nessun accesso a Claude: tutto si apre offline nel browser.

## Da dove partire

1. Apri `tavole/indice.html`: tutte le tavole divise per pagina del canvas, con anteprima e link a HTML, PNG, PDF e sorgente.
2. Leggi `guida/README.md` (guida di implementazione: configurazione per studio nella sezione 4, anti abuso nella 5, architettura nella 6, email nella 7, pagina dello studio nella 9, piano di lavoro nella 10).
3. Prova `prototipo/prototipo.html` (flusso del paziente interattivo, con il pannello "Demo" per simulare la configurazione dello studio).

## Cosa c'è

| Cartella | Contenuto |
|---|---|
| `tavole/html/` | Le 44 tavole come pagine HTML autonome. È il codice sorgente fedele: markup e stile inline identici all'originale di Claude Design, testi compresi. Font e logo puntano ai file locali di questo pacchetto. |
| `tavole/png/` | Screenshot di ogni tavola a grandezza reale (1x), per il confronto visivo. |
| `tavole/pdf/` | Ogni tavola in PDF vettoriale, una pagina della misura esatta della tavola (testo selezionabile). |
| `sorgente-claude-design/` | I file originali `.dc.html` e `canvas.json` (posizione, misura, pagina e titolo di ogni tavola), più le regole del formato. I `.dc.html` funzionano solo dentro Claude Design (caricano il suo `support.js`, che non è esportabile): per lavorare usa le versioni in `tavole/html/`, che hanno lo stesso contenuto. |
| `css/tokens.css` | I token visivi (colori, raggi, spaziature, tipografia, ombra, focus) come variabili CSS. Sono gli stessi valori scritti inline nelle tavole. |
| `font/` | Inter, font variabile (copre i pesi 400, 500, 600 e 700 usati), alfabeto latino e latino esteso, con `inter.css` pronto. Licenza SIL Open Font License 1.1. |
| `asset/` | Logo Ambulatorio Facile in PNG e SVG (solo nel footer), logo d'esempio dello studio, e in `asset/icone/` le 102 icone usate nelle tavole come SVG singoli, con `indice.html` che le mostra e dice dove compare ciascuna. |
| `prototipo/` | Il prototipo interattivo del paziente (vanilla JS, servizi finti con i nomi degli endpoint della guida). |
| `guida/` | `README.md` (guida di implementazione) e `specifica-visiva.md` (direzione visiva, token, dati demo, copy). |
| `email/` | Le 13 email HTML (P1-P8 al paziente, S1-S5 allo studio), la shell comune `_layout.html`, `_componenti.html` e `testi.md` con tutti i testi, gli SMS e i messaggi WhatsApp. |

## Area amministrativa

Le tavole dell'area amministrativa sono larghe 1280 px e mostrano la pagina dentro il gestionale (menu laterale, barra in alto). Sono mockup statici: ogni stato che serve è disegnato nella tavola stessa (interruttori accesi e spenti, badge, errori, varianti affiancate), e il comportamento è specificato nella guida e simulato nel prototipo.

| Tavola | Chi la usa | Sezioni | Specifica nella guida |
|---|---|---|---|
| A01 Studio: regole e protezioni | Segreteria dello studio | Percorso di prenotazione in sola lettura, modalità di accesso, tempi, protezione dai bot e dagli abusi, anteprima, link dello studio, limiti per paziente | 9, 5 |
| A02 Studio: richieste da confermare | Segreteria dello studio | Elenco "Da confermare" con azioni, agenda con le richieste tratteggiate, cosa riceve il paziente | 9, 5.8 |
| A03 Studio: link, QR e aspetto | Segreteria dello studio | Link dello studio, link per prestazione o professionista, QR per la sala d'attesa, logo, contatti mostrati, privacy e messaggi, anteprima email | 9, 7, 8 |
| A04 Editor del percorso | Team Ambulatorio Facile (super admin) | Stato bozza e pubblicata, ordine delle schermate con maniglie e frecce, passi fissi con lucchetto, esempio di errore di validazione, scelte, aspetto e logo, dati da chiedere, anteprima della bozza, versioni pubblicate, note per lo sviluppo | 4 |
| A05 Verifica e accesso | Team Ambulatorio Facile (super admin) | Metodi di verifica (WhatsApp, email, SMS) con ordine e interruttori, tetto SMS e protezioni, prenotazione senza registrazione (spenta e, nella fascia "Variante", accesa con recapito e avvisi), chi può prenotare, anteprima, contatori del giorno, controlli prima di pubblicare | 4.7, 5.6, 5.10 |

Collegati: `X01-componenti` (componenti e token di tutto il progetto), `X02-varianti-configurazione` (logo o iniziali, studio con un solo professionista e una sede, senza "Il primo disponibile", sede per prima), `G08-stati` (stati e casi limite lato paziente).

## Regole per restare identici all'originale

- Testi, colori, misure e spaziature si prendono dalle tavole HTML, non si riscrivono. In caso di dubbio vale la tavola, poi la guida, poi il prototipo.
- Misure delle tavole: 390 px il telefono, 1280 px desktop e gestionale, 680 px le email. L'altezza è quella del contenuto.
- Font Inter, cifre tabellari per orari e date. Testo mai sotto i 13 px. Contrasto almeno 4,5:1.
- Il teal del testo è sempre `#00795f`; `#00ac8d` solo per superfici e icone grandi.
- Il logo di Ambulatorio Facile compare solo nel footer; in alto c'è sempre lo studio (logo caricato oppure le iniziali su quadrato navy).
- Niente trattini lunghi nei testi, niente emoji.

## Elenco completo delle tavole

**Copertina e componenti**

| Tavola | Titolo | Misura |
|---|---|---|
| X00-copertina | Copertina | 1280 x 1076 |
| X01-componenti | Componenti e token | 1280 x 5120 |
| X02-varianti-configurazione | Varianti di configurazione | 1280 x 1552 |

**Prenotazione da telefono**

| Tavola | Titolo | Misura |
|---|---|---|
| M01-benvenuto | 01 Benvenuto dello studio | 390 x 1520 |
| M02-prestazione | 02 Prestazione | 390 x 1008 |
| M03-professionista | 03 Professionista | 390 x 844 |
| M04-sede | 04 Sede | 390 x 844 |
| M05-orario | 05 Giorno e orario | 390 x 1224 |
| M06-per-chi | 06 Per chi è la visita | 390 x 1008 |
| M07-cellulare | 07 Verifica che sei tu | 390 x 1376 |
| M08-codice | 08 Verifica con WhatsApp | 390 x 1503 |
| M09-dati | 09 I tuoi dati | 390 x 2428 |
| M10-riepilogo | 10 Riepilogo | 390 x 1344 |
| M11-richiesta-inviata | 11 Richiesta inviata (nuovo paziente) | 390 x 1192 |
| M12-confermata | 12 Prenotazione confermata | 390 x 1504 |
| M13-senza-registrazione | 13 I tuoi dati, senza registrazione | 390 x 1772 |
| M14-riepilogo-senza-registrazione | 14 Riepilogo, senza registrazione | 390 x 1461 |
| M15-esito-senza-registrazione | 15 Richiesta inviata, senza registrazione | 390 x 1245 |

**Gestione, app e stati**

| Tavola | Titolo | Misura |
|---|---|---|
| G01-accedi | Accedi alle tue prenotazioni | 390 x 1146 |
| G02-mie-prenotazioni | Le mie prenotazioni | 390 x 936 |
| G03-dettaglio | Dettaglio prenotazione | 390 x 1768 |
| G04-sposta | Sposta | 390 x 1200 |
| G05-annulla | Annulla | 390 x 844 |
| G06-installa-android | Installa l'app su Android | 390 x 844 |
| G07-installa-ios | Installa l'app su iPhone | 390 x 1200 |
| G08-stati | Stati e casi limite | 1280 x 2820 |

**Prenotazione da computer**

| Tavola | Titolo | Misura |
|---|---|---|
| D01-orario-desktop | Desktop: giorno e orario | 1280 x 900 |
| D02-dati-desktop | Desktop: i tuoi dati | 1280 x 980 |
| D03-confermata-desktop | Desktop: confermata | 1280 x 900 |

**Pagina dello studio e configurazione**

| Tavola | Titolo | Misura |
|---|---|---|
| A01-regole | Studio: regole e protezioni | 1280 x 2796 |
| A02-richieste | Studio: richieste da confermare | 1280 x 1880 |
| A03-link-aspetto | Studio: link, QR e aspetto | 1280 x 3346 |
| A04-configurazione-flusso | Configurazione: percorso e aspetto | 1280 x 3956 |
| A05-configurazione-verifica | Configurazione: verifica e accesso | 1280 x 3321 |

**Email e messaggi**

| Tavola | Titolo | Misura |
|---|---|---|
| E01-P1-richiesta-ricevuta | P1 Richiesta ricevuta | 680 x 1300 |
| E02-P2-confermata | P2 Prenotazione confermata | 680 x 1290 |
| E03-P4-promemoria | P4 Promemoria | 680 x 1270 |
| E04-P5-spostata | P5 Appuntamento spostato | 680 x 1410 |
| E05-P6-annullata | P6 Appuntamento annullato | 680 x 1160 |
| E06-P7-codice | P7 Codice di verifica via email | 680 x 980 |
| E07-S1-nuova-prenotazione | S1 Allo studio: nuova prenotazione | 680 x 1390 |
| E08-S2-da-confermare | S2 Allo studio: da confermare | 680 x 1450 |
| E09-S5-attivita-anomala | S5 Allo studio: attività anomala | 680 x 1430 |
| E10-sms-whatsapp | Promemoria via WhatsApp e SMS | 1280 x 1576 |
