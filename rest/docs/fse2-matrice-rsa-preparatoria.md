# RSA: matrice di preparazione, non checklist di accreditamento

Aggiornamento: 9 settembre 2026. Versione dichiarata per le prove SIMONE_FROSINI /
AMBULATORIOFACILE / 1.0.0; sorgenti locali non ancora rilasciati.

## Perimetro delle evidenze

Sono state lette le cartelle originali della checklist V9.0.0 e RSA OK/KO,
incluse le celle colorate e la legenda KO. Snapshot GitHub
`d937255fd7e9c079c5641c537da17fe98a2f2259`, URL/SHA-256 nel manifest privato.
Il materiale originale non è stato modificato. La skill Spreadsheets è stata
usata per l'ispezione in sola lettura: nessuna checklist con PASS presunti.

La tabella seguente copre le **23 righe RSA del foglio TestCases**. Il foglio
Summary menziona anche ID 157, assente nelle righe RSA TestCases di questo
snapshot: discrepanza da chiedere al Team, non un caso da inventare. La scheda
KO 26 presenta sia una codifica di familiarità non valida sia una marcatura
di tempo allergia mancante: confermare la combinazione esatta alla riapertura.

Fonti: [checklist e cartelle di test nazionali](https://github.com/ministero-salute/it-fse-accreditamento/tree/d937255fd7e9c079c5641c537da17fe98a2f2259/Test%20Case),
[scheda RSA KO originale](https://github.com/ministero-salute/it-fse-accreditamento/blob/d937255fd7e9c079c5641c537da17fe98a2f2259/Test%20Case/Validazione/7-Referto%20Specialistico%20Ambulatoriale/CDA2_Referto_Specialistica_Ambulatoriale_KO.xlsx).

## Analisi riga per riga

Le motivazioni indicano il comportamento attuale del codice; **non sono una
decisione formale SI/NO di applicabilità**. I difetti iniettati dall'harness
avvengono dopo il builder: non sono funzioni disponibili al medico.

| ID | Riferimento RSA | Comportamento / preparazione effettiva |
| --- | --- | --- |
| 32 | JWT senza purpose_of_use | Claim obbligatorio generato dall'app; fault solo CLI. Rifiuto reale 403 osservato. |
| 40 | JWT action_id=TEST | Fault solo CLI. Rifiuto reale 403 osservato; token regolarmente firmato, claim non ammesso. |
| 48 | Timeout | Test simulati di trasporto, HTTP 408/504, risposta incompleta, eccezione. Stato in corso conservato; niente retry automatico o sblocco manuale DB. |
| 152 | KO 6: CF minuscolo | Builder normalizza maiuscole. Mutazione post-builder respinta dallo Schematron locale. |
| 154 | KO 8: manca città | Elemento emesso dal builder; assenza simulata respinta localmente. Il valore predefinito non sostituisce la raccolta dell'indirizzo corretto. |
| 155 | KO 9: manca given | Nome obbligatorio nel builder. Mutazione respinta localmente e dal Gateway con 422, errore semantico sul nome. |
| 156 | KO 10: codice sesso NB | Builder consente M/F/UN e rifiuta NB. Mutazione non rilevata da XSD/Schematron, ma respinta dal Gateway con 400, vocabolario. |
| 159 | KO 13: manca order/id | Prescrizione strutturata inFulfillmentOf non implementata; valutare il perimetro SSR della cliente e l'applicabilità con il Team. |
| 160 | KO 14: manca act/code | Entry prestazione emessa automaticamente con codice nullFlavor OTH/riferimento narrativo; rimozione respinta da XSD. Codifica strutturata delle prestazioni da concordare, non inventare. |
| 161 | KO 15: manca sezione Referto | Sezione sempre emessa e testo obbligatorio. Rimozione respinta dallo Schematron. |
| 162 | KO 16: manca testo Quesito | Sezione narrativa emessa solo con testo. Fixture con quesito del builder; rimozione del testo respinta dallo Schematron. |
| 163 | KO 17: manca entry Prestazioni | Entry automatica. Rimozione respinta dallo Schematron. |
| 164 | KO 18: manca tempo osservazione anamnestica | Anamnesi attualmente narrativa, non observation strutturata: valutare non applicabilità motivata o ampliamento clinico concordato. |
| 165 | KO 19: manca codice legame familiare | Familiarità strutturata non implementata; stessa verifica di perimetro. |
| 166 | KO 20: manca tempo osservazione allergia | Allergie strutturate non implementate; stessa verifica di perimetro. |
| 167 | KO 21: manca participant allergia | Allergie strutturate non implementate; stessa verifica di perimetro. |
| 168 | KO 22: diagnosi ICD-9-CM errata | Testo diagnostico supportato, codifica strutturata non implementata; non associare codici per deduzione al testo medico. |
| 169 | KO 23: signatureCode diverso da S | Valore automatico S nel CDA. Mutazione respinta localmente; S nel CDA non attesta la firma crittografica PAdES. |
| 448 | OK 24 | Esempio XML originale verificato in VERIFICA/200. È obbligatorio nella checklist consultata, ma VALIDATION/201 e caso applicativo completo non eseguiti. |
| 449 | OK 25 | Esempio XML originale verificato in VERIFICA/200; non equivale al caso applicativo completo VALIDATION/201. |
| 461 | KO 26 | Sezione strutturata non implementata e marcature da chiarire; nessuna selezione arbitraria del difetto. |
| 468 | KO 27: manca confidentialityCode | Elemento automatico N. Rimozione respinta da XSD; regole di riservatezza/oscuramento regionale ancora da concordare. |
| 475 | KO 28: priorità ordine errata | Ordine/priorità strutturati non implementati; da valutare nel perimetro concordato. |

Esito delle 10 mutazioni CDA preparate: 9 respinte da XSD/Schematron; 1
terminologica rilevata dal Gateway, con blocco preventivo già nel builder.
Questo non dimostra copertura completa delle terminologie o dei casi ufficiali.

## Cinque nuove chiamate nazionali di test

Tutte in VERIFICA, con mTLS, JWT autoverificati, TLS peer/host verificati,
PDF/A-3b e identità dei soli esempi ufficiali. Nessuna firma clinica, pubblicazione,
chiamata CART o modifica a database. Le mutazioni CDA derivano dallo stesso
documento applicativo sintetico con quesito e anamnesi narrativa del controllo.

| Scenario | UTC / run privato | HTTP | Trace ID |
| --- | --- | --- | --- |
| Controllo positivo del builder | 17:40:05 / 20260909-174005-b5f333dc | 200 | ad4a4c81ef286b348b4b47ec2231ff96 |
| action_id errato | 17:40:23 / 20260909-174023-e5e244d3 | 403 | cb7b8085093d3760558ef889635ea7a9 |
| Nome mancante | 17:40:23 / 20260909-174023-e6a65205 | 422 | 266ef03aa6d0a8f3b44c0b55109465f3 |
| Codifica non ammessa | 17:40:24 / 20260909-174024-9c82aad8 | 400 | ae68268588669de2cb6651e085a543c9 |
| purpose_of_use mancante | 17:40:25 / 20260909-174025-ed48c638 | 403 | e157f2a67b92bcbddc0c4722a83d835d |

Cartella radice privata: `ops/.local/fse-accreditamento/gateway-runs/`.
Ogni run conserva request.pdf, hash e risultato diagnostico. I JWT e le chiavi
non sono inclusi nel dossier. Il valore remoto UNKNOWN_WORKFLOW_ID nei due
scarti JWT è un segnaposto, non una transazione interrogabile: ora il parser
applicativo lo scarta conservando il trace ID. I rapporti originali restano intatti.
Le prove fallite precedenti non sono cancellate o convertite in successi.

La [specifica API nazionale](https://github.com/ministero-salute/it-fse-support/blob/main/openapi/gateway/swagger_gtw.yaml)
distingue esito 200 per VERIFICA e 201 per VALIDATION. Il dispatcher operatore,
che richiede VALIDATION, non considera più 200 una validazione pronta alla firma.

## Gestione degli errori applicativa verificata separatamente

- Rifiuti in rosso, esiti incerti/in attesa in giallo, successi in verde.
- Esito persistente visibile anche senza workflow ID. Testo remoto libero escluso da UI/audit ordinari.
- 401/403: assistenza su certificati/token/abilitazioni, non modifica del referto.
- 400 terminologico e 422 semantico: indicazioni diverse, senza suggerire codici clinici casuali.
- 408/5xx/cURL/risposta non interpretabile: blocco mantenuto, no invio duplicato.
- Trace/span di una precedente operazione non vengono attribuiti a un nuovo tentativo senza correlazione.
- Conferma di ricezione non presentata come pubblicazione definitiva; cancellazione remota fallita presentata come errore anche con risposta HTTP positiva alla richiesta di stato.

Prove con controller reali e sessione sintetica, dispatcher con SQLite in memoria,
trasporto mock, viste HTML e firme JWT con chiavi inventate. Non sono un E2E del
gestionale configurato con il cliente o del suo dispositivo di firma.

## Restano necessari

Verifica finale del codice: 166 test PHP, 830 asserzioni e 24 test Python,
nessun errore o test saltato. Rapporto privato ripetibile tramite collect-evidence:
`rest/writable/fse-validation-reports/20260909-195016-a5d25846/summary.json`.

Nuove istruzioni nazionali, perimetro e applicabilità concordati, versione congelata,
casi ufficiali applicativi e attività corretta, firma e policy fiduciaria pertinente,
presentazione e valutazione formale. Per Toscana: autorizzazione CART, dati struttura,
workflow operativo regionale completo e collaudo reale separato. Non basta attivare un flag.
