# Integrazione SMSFactor

AmbulatorioFacile usa l'API sincrona SMSFactor per SMS transazionali, OTP,
conferme e promemoria appuntamento. Aruba SMS resta disponibile come provider
alternativo e rollback.

## Configurazione

La configurazione ordinaria si gestisce dal Super Master, nella pagina
**Notifiche appuntamenti**:

- **Provider SMS globale** definisce provider, mittente e credenziali predefinite;
- **Account SMS dello spazio** permette di ereditare l'account globale con un
  mittente diverso oppure usare credenziali dedicate per il singolo tenant;
- i campi segreti non vengono mai riletti nell'interfaccia: si vede soltanto se
  sono configurati e un valore vuoto conserva la credenziale esistente;
- token e password sono cifrati nel database piattaforma con AES-256-GCM.

Prima di salvare dall'interfaccia, eseguire le migration e configurare in tutti i
runtime `demo` e `login` la stessa chiave lunga e casuale:

```dotenv
SMS_PROVIDER_SECRET_KEY=segreto-lungo-casuale-e-condiviso
```

Le variabili seguenti restano disponibili come bootstrap e fallback di emergenza
quando non esiste ancora una configurazione globale nel database. Non inserirle
mai nel repository:

```dotenv
SMS_PROVIDER=smsfactor
SMS_PROVIDER_SECRET_KEY=segreto-lungo-casuale-e-condiviso
SMSFACTOR_API_TOKEN=token-generato-nel-portale
SMSFACTOR_BASE_URL=https://api.smsfactor.com
SMSFACTOR_TIMEOUT_SECONDS=30
SMSFACTOR_PUSH_TYPE=alert
SMSFACTOR_WEBHOOK_SIGNATURE=segreto-lungo-e-casuale
SMS_SENDER=AmbFacile
```

La password del portale SMSFactor non viene usata dall'applicazione. Il token API
va creato nel portale provider e poi inserito nel Super Master. Conservare una
chiave `SMS_PROVIDER_SECRET_KEY` stabile: cambiarla senza prima ricifrare i valori
rende illeggibili le credenziali già salvate.

Il mittente deve contenere al massimo 11 caratteri alfanumerici e deve essere
registrato/approvato nell'account SMSFactor per i Paesi che lo richiedono. I
promemoria e gli OTP usano il tipo `alert`; non usare `marketing` per aggirare
vincoli o consensi.

## Invio e tracciamento

Gli invii usano `POST https://api.smsfactor.com/send` con token Bearer. Ogni
destinatario riceve un `gsmsmsid` interno; la risposta conserva ticket, costo e
credito residuo nei log applicativi. Lo stato API `-8` è considerato accettato e
in moderazione, quindi non viene ritentato automaticamente per evitare duplicati.

Applicare le migration prima di attivare il webhook. Configurare nel portale un
webhook `DLR` con la stessa firma salvata nel Super Master (oppure nella variabile
fallback `SMSFACTOR_WEBHOOK_SIGNATURE`) e uno dei seguenti URL pubblici, in base
al mount usato:

```text
https://ambulatoriofacile.it/api/smsfactor/dlr
https://ambulatoriofacile.it/login/api/smsfactor/dlr
https://ambulatoriofacile.it/demo/api/smsfactor/dlr
```

Le ricevute sono verificate tramite l'header `X-SMSFactor-Signature` e salvate in
`platform_sms_delivery_receipts`. Il payload non viene accettato se firma, stato,
numero o identificativi non sono validi.

## Verifiche prima dell'attivazione

1. Configurare la stessa `SMS_PROVIDER_SECRET_KEY` su `demo` e `login`.
2. Eseguire le migration.
3. Creare il token API e salvarlo dal Super Master.
4. Registrare i mittenti usati dai tenant nel portale, se richiesto.
5. Configurare il webhook DLR e verificare che restituisca HTTP 200.
6. Usare la simulazione SMSFactor o un singolo numero controllato prima di
   abilitare i batch reali.
