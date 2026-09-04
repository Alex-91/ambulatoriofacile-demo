# Integrazione WhatsApp Gateway in AmbulatorioFacile

## Decisione architetturale

`whatsmeow` è una libreria Go che mantiene WebSocket e stato crittografico long-lived. Per questo il gateway vive in `services/whatsapp-gateway` come servizio dello stesso repository, invece di essere incorporato nel processo PHP/Apache.

Il monolite resta il punto di autorizzazione:

1. l'utente accede con il sistema esistente;
2. `TenantContextService` risolve la membership e l'`id_tenant`;
3. per le notifiche configurate dalla piattaforma, l'attivazione del canale WhatsApp instrada automaticamente lo spazio sul gateway AmbulatorioFacile;
4. il client PHP firma la richiesta includendo l'`id_tenant`;
5. il gateway usa la coppia `(tenant_id, account_id)` come chiave di isolamento della sessione WhatsApp.

Non viene introdotta alcuna tabella `tenants` nel gateway. La piccola tabella locale `gateway_accounts` è soltanto un indice tecnico tra l'`id_tenant` centrale e il JID del device `whatsmeow`.

## Attivazione e parametri per spazio

Configurazione iniziale del monolite:

```dotenv
WHATSAPP_PROVIDER=ultramsg
WHATSAPP_GATEWAY_TENANT_IDS=
WHATSAPP_GATEWAY_BASE_URL=https://whatsapp-gateway.ambulatoriofacile.it
WHATSAPP_GATEWAY_ACCOUNT_ID=primary
WHATSAPP_GATEWAY_API_KEY_ID=ambulatoriofacile-app
WHATSAPP_GATEWAY_API_SECRET=LO_STESSO_SEGRETO_CASUALE_CONFIGURATO_NEL_GATEWAY
WHATSAPP_GATEWAY_TIMEOUT_SECONDS=90
```

Nel container del gateway configurare anche il callback verso l'applicazione dello stesso ambiente:

```dotenv
WHATSAPP_GATEWAY_WEBHOOK_URL=https://ambulatoriofacile.it/login/api/whatsapp-gateway/incoming
WHATSAPP_GATEWAY_WEBHOOK_TIMEOUT_SECONDS=10
```

Per l'ambiente demo usare il percorso `/demo/api/whatsapp-gateway/incoming`. Il callback è firmato HMAC con `WHATSAPP_GATEWAY_API_KEY_ID` e `WHATSAPP_GATEWAY_API_SECRET`; non deve puntare all'altro ambiente.

## Chatbot per tenant

Il tenant master trova **Chatbot WhatsApp** nella console del proprio spazio. La configurazione è isolata per `id_tenant` e permette di:

- attivare o sospendere il bot senza scollegare il dispositivo;
- scegliere se aprire l'attesa sul reminder o anche sulla conferma di nuova prenotazione;
- configurare risposte esatte, alias, ordine delle regole e messaggio di fallback;
- associare una regola a conferma appuntamento, annullamento appuntamento o semplice risposta;
- consultare richieste pendenti, messaggi elaborati ed errori dello spazio.

Quando parte un messaggio WhatsApp configurato, l'app salva l'ID esatto dell'appuntamento e il numero normalizzato. Il webhook può agire soltanto su quel contesto ancora valido. `message_id` e stato della richiesta rendono idempotenti i ritentativi e impediscono che la stessa risposta aggiorni due volte l'agenda.

Aprire **Piattaforma → Spazi cliente** e abilitare il canale WhatsApp per lo spazio: il routing verso il gateway AmbulatorioFacile viene salvato automaticamente nel `config_json` dell'override tenant e diventa effettivo senza redeploy. La configurazione legacy UltraMsg resta disponibile soltanto per i flussi storici non ancora migrati.

L'override dello spazio ha priorità anche se il valore globale `WHATSAPP_PROVIDER` è ancora `ultramsg`; questo permette la migrazione progressiva senza lasciare al tenant attivo una strada di invio diversa dal gateway AmbulatorioFacile.

In **Piattaforma → Notifiche appuntamenti → Parametri di consegna per spazio** il master piattaforma configura:

- quantità e intervallo degli invii WhatsApp;
- limite giornaliero;
- attivazione del fallback SMS;
- minuti di attesa prima del fallback.

Il worker delle campagne e dei reminder usa gli stessi limiti centrali. Se WhatsApp viene rifiutato subito, il fallback SMS può essere eseguito immediatamente; altrimenti viene inviato solo se il messaggio non risulta `delivered` o `read` entro il termine configurato.

Prima di attivare il primo spazio:

1. distribuito il servizio con volume persistente `/data`;
2. verificato `/healthz` e `/readyz`;
3. creato e associato via QR l'account `primary` per il tenant;
4. eseguito un invio controllato;
5. verificato log applicativi e stato sessione.

Il routing del singolo spazio resta reversibile disattivando il canale WhatsApp dalla console piattaforma; nessun dato legacy UltraMsg viene eliminato.

## Deployment Coolify

Creare una risorsa applicativa separata nello stesso progetto Coolify e nello stesso repository Git:

- base directory: `/services/whatsapp-gateway`;
- Dockerfile: `/Dockerfile`;
- porta interna: `8080`;
- health check applicativo Coolify: disabilitato; il Dockerfile contiene un `HEALTHCHECK` nativo che esegue il comando `healthcheck` del gateway;
- smoke check HTTPS esterno: `/healthz` e `/readyz`;
- replica: `1`;
- volume persistente: `/data`;
- dominio/reverse proxy: `https://whatsapp-gateway.ambulatoriofacile.it`, oppure sola rete privata se il monolite lo raggiunge direttamente.

In Coolify tutte le variabili `WHATSAPP_GATEWAY_*` devono essere abilitate a runtime e disabilitate a build time. In particolare il segreto HMAC non deve diventare un `ARG` o `ENV` dell'immagine Docker. Il segreto deve essere diverso da password DB, token UltraMsg e segreti di login e non va salvato nel repository.

## Parti volutamente non modificate

- callback legacy `checkMessaggio`, `aggiornaNoteApp` e `checkAppMultiplo`;
- `LegacyWhatsappAppointmentController` e relativo modello;
- cron legacy e monitor UltraMsg;
- autenticazione, ruoli e feature flag;
- ambiente demo congelato.

## Milestone successivo consigliato

Estendere il builder con rami multi-step, allegati e azioni applicative aggiuntive. Se il volume dei messaggi cresce, introdurre nel gateway un outbox persistente per consegna webhook oltre ai ritentativi immediati già presenti.
