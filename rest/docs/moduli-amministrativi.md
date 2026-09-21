# Moduli amministrativi

Accesso: **Gestisci funzioni → Apri amministrazione**, oppure
`/app/admin/amministrazione` sul target login. Accesso operativo al master
effettivamente associato allo spazio; nessuna abilitazione implicita da un
profilo medico, segreteria o amministratore di piattaforma.

Il super amministratore attiva separatamente questi moduli, disattivati di default:

| Chiave | Modulo | Dipendenze |
|---|---|---|
| `admin_quotes` | Preventivi e listini | Fatturazione |
| `admin_payers` | Convenzioni, assicurazioni e fondi | Fatturazione, Preventivi |
| `admin_compensation` | Spettanze professionisti | Fatturazione |
| `admin_ssn` | Registro amministrativo SSN | Fatturazione, Preventivi |

Le dipendenze sono verificate anche sul server a ogni operazione. Nascondere un
collegamento non sostituisce il controllo. Il master prepara gli archivi tramite
il pulsante dedicato, che applica la migration `CreateAdministrationModules`
al solo database dello spazio selezionato. Le tabelle non vengono create durante
una semplice visita alla pagina. Dati contrattuali e contenuti dei documenti sono
cifrati; chiavi e configurazioni sono necessarie anche al ripristino.

## Preventivi e listini

- Voci con codice, nome, listino, prezzo finale, nota fiscale e validità.
- Modifica/archiviazione di una voce con controllo della revisione.
- Preventivo per paziente, fino a 50 righe, quantità intere e sconto percentuale.
- Importi calcolati in centesimi; arrotondamento della percentuale al centesimo.
- Copia dei valori nel preventivo: cambiare il listino non cambia i documenti.
- Bozza, consegna, accettazione, rifiuto e annullamento con riferimento e storico.
- PDF non fiscale e precompilazione del modulo fattura esistente. Quest'ultima
  apre una nuova bozza: **non emette, non invia al TS e non impedisce da sola di
  fatturare due volte**. L'operatore verifica i documenti già emessi. In presenza
  di sconto la bozza raggruppa le prestazioni in una riga con il totale concordato;
  prima dell'emissione va verificato il trattamento fiscale.
- Modifiche al contenuto di un preventivo richiedono un nuovo preventivo; non
  si riscrivono importi già consegnati. La revisione riguarda le operazioni
  registrate, non una numerazione fiscale o una firma dell'accettazione.

## Convenzioni e fondi

Gli accordi hanno percentuale, franchigia e massimale **per pratica**. Formula:
`quota ente = min(totale, massimale se presente, max(0, totale × percentuale − franchigia))`.
Il residuo è la quota paziente. Non viene gestito un massimale annuale per assistito.
Una pratica per preventivo accettato conserva una copia dell'accordo applicato.
Autorizzazione, invio effettuato esternamente e riconciliazione si registrano
con riferimento. Incassi separati per paziente ed ente, controllo del residuo,
storno motivato e riapertura della riconciliazione se si storna un incasso.
Lo storno corregge il registro; non rimborsa denaro né emette una nota di credito.
Il registro CSV è un export generico, non un tracciato di un fondo o gestionale.

## Registro SSN

Registra prescrizione, ente, esenzione, ticket/ quota paziente e quota ente
indicati dall'operatore. Riutilizza pratica, incassi e riconciliazione.
Non interroga ricette elettroniche, non certifica esenzioni, non determina
automaticamente tariffe/ticket e non trasmette flussi regionali. Questi passaggi
richiedono regole, canale e abilitazioni della struttura.

## Spettanze

Regole per professionista: percentuale sul totale fattura più quota fissa,
maturazione su fattura emessa o saldata. L'operatore indica la fattura e il
riferimento che attribuisce la prestazione al professionista. Il modulo non
deduce la titolarità clinica dalla sola fattura, non calcola ritenute/contributi
e non effettua bonifici. Una sola posizione per fattura/professionista; ogni
posizione deve essere positiva e non superiore alla fattura. Non è applicato
un limite aggregato ai compensi di più professionisti sulla stessa fattura.

Approvazione e registrazione del pagamento verificano che la fattura non sia
cambiata. Una posizione stornata può essere ricalcolata conservando importi,
regola e storico del calcolo precedente. La fatturazione può comunque essere
modificata dopo la registrazione: la verifica delle rettifiche resta necessaria.

## Verifiche

`ops/pacs-validation/run-acceptance.ps1` comprende la suite amministrativa
isolata SQLite: autorizzazioni reali, dipendenze dei moduli, CSRF, cifratura,
importi, revisioni obsolete/future, duplicati, rollback, coperture, incassi,
SSN, spettanze e viste. Non usa database clienti.

Il collaudo operativo deve verificare sul tenant test: preparazione, salvataggio
listino, preventivo/PDF, precompilazione fattura senza emissione, accordo,
pratica/incasso/storno e CSV. La migrazione non ha rollback distruttivo automatico:
un ritorno alla release precedente lascia gli archivi da conservare.
