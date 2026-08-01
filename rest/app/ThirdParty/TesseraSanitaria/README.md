# Tessera Sanitaria assets

Questa cartella ospita gli asset tecnici pubblici usati dal modulo `Fatturazione TS`.

## Struttura prevista

- `wsdl/`
- `xsd/`
- `certs/`

## File attesi dal primo slice

- `wsdl/DocumentoSpesa730p.wsdl`
- `wsdl/DocumentoSpesa730pSchema.xsd`
- `wsdl/RicevutaPdf730Service.wsdl`
- `wsdl/RicevutaPdf730Service_schema.xsd`
- `certs/SanitelCF.cer`

I nomi degli asset possono essere ridefiniti tramite le variabili ambiente del file `Config/TsBilling.php`.

## Provenienza asset correnti

Aggiornato il: `2026-07-05`

- kit ufficiale pubblico `kit730P_ver_20240214.zip`
- portale Sistema TS, sezione `Strumenti per lo sviluppo`

Uso corrente nel modulo:

- `DocumentoSpesa730p.wsdl` per l’operazione sincrona `Inserimento`
- `SanitelCF.cer` per la cifratura dei campi sensibili richiesta dal kit TS

## Preset TEST locali

Per i collaudi tecnici del modulo usiamo un file non versionato:

- `ops/.local/ts-test-presets.json`
- fallback runtime persistente in produzione: `rest/writable/ts/ts-test-presets.json`

Questo file può contenere le utenze di prova ufficiali del kit TS e viene letto dalla schermata tenant `spazio/fatturazione-ts`.

## Regole

- versionare qui solo artefatti tecnici pubblici o strettamente necessari al runtime
- non salvare mai credenziali, pin, password o export contenenti dati sensibili
- se importiamo un kit ufficiale, aggiungere una nota con data e provenienza del pacchetto
