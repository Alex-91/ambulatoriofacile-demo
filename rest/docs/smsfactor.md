# Integrazione SMSFactor

AmbulatorioFacile usa l'API sincrona SMSFactor per SMS transazionali, OTP,
conferme e promemoria appuntamento. Il vecchio provider Aruba resta disponibile
solo come rollback impostando `SMS_PROVIDER=aruba`.

## Configurazione

Impostare i segreti nell'ambiente di runtime (Coolify in produzione), mai nel
repository:

```dotenv
SMS_PROVIDER=smsfactor
SMSFACTOR_API_TOKEN=token-generato-nel-portale
SMSFACTOR_BASE_URL=https://api.smsfactor.com
SMSFACTOR_TIMEOUT_SECONDS=30
SMSFACTOR_PUSH_TYPE=alert
SMSFACTOR_WEBHOOK_SIGNATURE=segreto-lungo-e-casuale
SMS_SENDER=AmbFacile
```

La password del portale non viene usata dall'applicazione. Il primo token API va
creato in `my.smsfactor.com/developers/api-tokens` e poi salvato come segreto
Coolify.

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
webhook `DLR` con la stessa firma di `SMSFACTOR_WEBHOOK_SIGNATURE` e uno dei
seguenti URL pubblici, in base al mount usato:

```text
https://ambulatoriofacile.it/api/smsfactor/dlr
https://ambulatoriofacile.it/login/api/smsfactor/dlr
https://ambulatoriofacile.it/demo/api/smsfactor/dlr
```

Le ricevute sono verificate tramite l'header `X-SMSFactor-Signature` e salvate in
`platform_sms_delivery_receipts`. Il payload non viene accettato se firma, stato,
numero o identificativi non sono validi.

## Verifiche prima dell'attivazione

1. Creare il token API e registrarlo nei segreti Coolify.
2. Registrare il mittente `AmbFacile` nel portale, se richiesto.
3. Eseguire le migration.
4. Configurare il webhook DLR e verificare che restituisca HTTP 200.
5. Usare la simulazione SMSFactor o un singolo numero controllato prima di
   abilitare i batch reali.
