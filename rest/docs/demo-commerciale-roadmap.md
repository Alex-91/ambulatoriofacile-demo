# Demo commerciale roadmap

## Obiettivo

Preparare una demo commerciale credibile per i due verticali scelti:

1. `medical / poliambulatorio`
2. `sport rehab / fisioterapia / riabilitazione`

La demo deve:

- sembrare un prodotto vendibile
- evitare riferimenti a clienti reali
- riusare il piu possibile cio che il software gia fa bene
- non introdurre rischi per la versione attuale in uso

## Principio guida

Non partire cambiando tutta l'app.

Partire invece da:

1. branding neutro
2. dataset finto
3. due percorsi demo chiari
4. un piccolo layer di terminologia configurabile

## Due verticali demo

### Verticale 1: medical

Posizionamento:

- studio medico evoluto
- centro specialistico
- piccolo poliambulatorio

Punti da mostrare in demo:

- agenda multi-operatore
- sedi e stanze
- prenotazioni e slot
- reminder
- posta e chat interna
- OTP e dispositivi
- gestione personale

### Verticale 2: sport rehab

Posizionamento:

- centro riabilitazione
- fisioterapia
- recupero sportivo
- medicina dello sport

Punti da mostrare in demo:

- agenda terapisti e specialisti
- sale e postazioni
- gestione appuntamenti
- comunicazione col paziente
- reminder
- team interno e ruoli

## Percorsi demo consigliati

### Percorso A: front office operativo

Da mostrare:

1. login
2. dashboard moduli
3. agenda del giorno
4. ricerca disponibilita
5. inserimento appuntamento
6. reminder
7. ticket o riepilogo appuntamento

### Percorso B: coordinamento team

Da mostrare:

1. gestione staff
2. visibilita moduli
3. collegamenti tra operatori
4. posta interna
5. chat
6. notifiche push

### Percorso C: esperienza utente finale

Da mostrare:

1. accesso con OTP
2. gestione prenotazione
3. messaggio ricevuto
4. reminder

## Dataset demo da preparare

### Per il verticale medical

- 1 struttura
- 2 sedi
- 4 professionisti
- 2 segreterie
- 1 infermiere o assistente
- 20 pazienti finti
- 2 giorni di agenda piena
- 1 giorno con slot liberi
- 4 thread posta
- 3 conversazioni chat

### Per il verticale sport rehab

- 1 centro
- 3 sale
- 3 terapisti
- 1 medico sportivo
- 1 coordinatore
- 20 clienti finti
- 2 giorni di agenda piena
- 1 agenda con trattamenti ricorrenti
- 3 reminder
- 3 thread comunicazione

## Cosa non usare nella demo

- nomi reali
- dati reali
- riferimenti farmacia
- riferimenti cliente-specifici
- messaggi con testo troppo clinico se stiamo mostrando sport rehab

## Primo layer di neutralizzazione da costruire

### Branding

Da neutralizzare in seguito:

- `AMBULATORI.Cloud`
- `AmbulatoriCLOUD`
- descrizioni PWA e notifiche
- oggetti email e testi OTP

### Terminologia

Da rendere configurabile:

- dottore
- paziente
- segreteria
- infermiere
- ambulatorio
- specialista

### Etichette per verticale

Medical:

- dottore
- paziente
- segreteria
- infermiere
- sede
- stanza

Sport rehab:

- professionista
- cliente
- coordinatore
- assistente
- centro
- sala

## Ordine di lavoro consigliato

1. creare brand demo neutro
2. censire tutti i punti di branding e linguaggio esposto
3. preparare i due profili verticali
4. definire dataset finto
5. adattare login, home e titoli piu visibili
6. creare script demo guidato

## Deliverable minimi

Per considerare pronta la demo commerciale servono almeno:

- nome demo neutro
- due profili verticali scritti
- elenco punti da rinominare
- dataset demo pianificato
- script di presentazione

## Output gia pronti in questo repository

Questa roadmap lavora insieme a:

- `docs/analisi-prodotto-commercializzazione.md`
- `docs/priorita-verticali-commerciali.md`

## Prossimo passo operativo

Il prossimo passo tecnico consigliato e:

`creare i profili demo dei due verticali e la mappa di branding da neutralizzare`
