# Cartella clinica: installazione e utilizzo

## Installazione per spazio

Preparare prima una copia ripristinabile di database, storage e chiavi, quindi
eseguire dalla cartella `rest`, con la configurazione dell'ambiente desiderato:

```text
php spark clinical:install <tenant-id>
php spark clinical:install <tenant-id> --apply
```

Il primo comando controlla soltanto. Il secondo applica la migration dedicata
`2026-09-12-160001_CreateClinicalRecords.php` al database del tenant esplicito.
Non crea una cartella clinica nel database della piattaforma. La migration può
essere rieseguita; non cancella documenti né prevede rollback distruttivo.
In questo task è stata applicata soltanto ai database sintetici di laboratorio.

Servono la chiave applicativa già gestita da `FseSecretsService`, Dompdf e storage
privato persistente scrivibile. Includere nel backup `rest/writable/clinical-private`,
`rest/writable/ts-reconciliation-private`, archivi FSE/TS e le chiavi applicative.
I file cifrati non devono essere esposti dal web server. Cache PDF in
`rest/writable/billing-pdf-runtime/<tenant>`; i PDF clinici disabilitano risorse remote.

## Percorso dell'operatore

In **Pazienti → Cartella e consensi**:

1. Registrare visita, anamnesi, allergie, terapia, diagnosi, nota o referto.
   È possibile collegare un appuntamento già associato al paziente.
2. Salvare la bozza e modificarla; una revisione concorrente richiede di riaprire
   il documento, per evitare di sovrascrivere il lavoro di un altro accesso.
3. Il medico autore può rendere il documento definitivo e scaricare il PDF.
4. Dopo la finalizzazione le correzioni diventano nuovi documenti collegati:
   l'originale e l'eventuale firma precedente restano conservati.
5. Allegati PDF/JPEG/PNG fino a 15 MB, archiviati cifrati. I download ricontrollano
   paziente, ruolo, visibilità del documento e hash del contenuto.

Lo storico comprende gli episodi clinici, gli appuntamenti con `id_client`
corretto e i documenti FSE del paziente. I vecchi referti FSE si scaricano con
verifica di percorso privato e hash. Se un vecchio documento non ha ancora un
PDF valido il download resta bloccato. Le anagrafiche legacy senza collegamento
univoco non vengono abbinate tramite nome o telefono: serve una bonifica dati
separata e verificata.

## Ruoli e consensi

- Il medico deve avere una relazione di cura registrata con il paziente.
- L'infermiere delegato può scrivere note; finalizzazione e firma sono del medico.
- La segreteria delegata gestisce consensi e relativi documenti, senza leggere
  il contenuto clinico. Essere amministratore della piattaforma non concede
  automaticamente accesso alle cartelle.
- Senza condivisione del dossier sono visibili i propri documenti. Un consenso
  registrato con prova documentale consente la consultazione dei definitivi
  agli altri professionisti già autorizzati sul paziente. Le bozze altrui
  restano escluse; la revoca disattiva la condivisione nelle richieste successive.

Il responsabile dello spazio, con profilo operativo abilitato, pubblica i testi
usati dalla struttura. Ogni modello ha versione immutabile. Concessione, diniego,
revoca e presa visione conservano versione, dichiarante, qualità, operatore e
data. Il consenso concesso richiede un allegato sottoscritto; la presa visione
dell'informativa è distinta dalla concessione. Il sistema registra le prove
caricate dall'operatore, senza attestare automaticamente l'identità di un
firmatario su una scansione. I modelli devono essere quelli approvati dalla
struttura; non sono stati inventati testi legali standard.

L'audit registra consultazioni, download e modifiche. La cancellazione ordinaria
dell'anagrafica è bloccata quando esiste una cartella: eliminazione o gestione
della conservazione richiedono un processo dedicato.

## Firma indipendente dal produttore

Il flusso usa file standard: scaricare l'originale, firmarlo nel software del
proprio token/smart card o servizio remoto, caricare **PAdES PDF** o **CAdES P7M**.
Non occorrono credenziali del provider nel gestionale. Non è implementata
un'API remota universale, perché ogni servizio ha modalità proprie.

Il verificatore controlla il contenuto originale, la firma crittografica, il
codice fiscale del medico associato all'utente, la catena e le prove di revoca.
Conserva originale, file sottoscritto, hash, evidenze tecniche e audit. Rifiuta
documenti alterati, firmatario diverso, certificati scaduti/revocati e materiale
fiduciario insufficiente.

Configurazione del runtime comune descritta in `ops/fse-validation/README.md`:
`FSE2_VALIDATOR_PYTHON` e `FSE2_VALIDATOR_SETTINGS`, percorsi assoluti privati;
root CA e CRL/OCSP attendibili e aggiornati. Le denominazioni FSE delle variabili
derivano dal verificatore condiviso: firmare una cartella non richiede invio FSE.
L'operatore deve gestire l'aggiornamento del materiale di revoca: non viene
scaricato automaticamente durante il controllo del documento.

**Limiti di compatibilità verificati:** un firmatario; CAdES con PDF incorporato
identico all'originale; PAdES con firma aggiunta incrementalmente all'originale
predisposto dal gestionale. Formati detached, firme multiple, documenti riscritti
dal software di firma e ulteriori revisioni/LTV non sono dichiarati supportati.
I test dimostrano interoperabilità dei formati con certificati sintetici;
non dimostrano la compatibilità commerciale con ogni prodotto/versione.
Eseguire una prova con un file reale del prodotto scelto prima dell'attivazione.

La verifica tecnica conserva `qualified_signature = not_assessed`: non è una
certificazione della qualifica legale, un servizio di conservazione o un
accreditamento. Queste attivazioni restano distinte dallo sviluppo.

## Sistema TS: esiti incerti

Un timeout dopo l'avvio dell'invio lascia il documento bloccato. Nella pagina
TS l'operatore può registrare la verifica eseguita nell'area riservata,
allegando riscontro PDF, nota e protocollo quando acquisito. Solo una mancata
acquisizione confermata rende possibile il reinvio. Lo storico distingue
esplicitamente verifica manuale e risposta SOAP; la prova rimane cifrata.

Variazioni/annullamenti sono serializzati sul documento di origine. Un'operazione
locale ancora modificabile si può abbandonare senza inviare nulla a TS. Non si
può abbandonare un invio in corso o dall'esito incerto. Esito positivo e stato
del documento padre vengono salvati nella stessa transazione.
