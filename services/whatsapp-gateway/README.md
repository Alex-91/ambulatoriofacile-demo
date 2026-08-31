# AmbulatorioFacile WhatsApp Gateway

Servizio interno di `ambulatoriofacile.it` basato su `whatsmeow`. Appartiene allo stesso repository dell'applicazione CodeIgniter, ma viene eseguito come processo/container separato per isolare connessioni WhatsApp, sessioni multi-device e riavvii dal monolite PHP.

## Confine del milestone 1

Il servizio fornisce:

- un account WhatsApp `primary` (o altro identificativo configurato) per ciascun `id_tenant` già esistente nel catalogo AmbulatorioFacile;
- pairing tramite QR, stato connessione, riconnessione e logout;
- invio di messaggi testuali e raccolta dei messaggi in ingresso;
- storage persistente delle sessioni `whatsmeow` e della timeline conversazioni in SQLite;
- autenticazione HMAC, finestra temporale e protezione anti-replay;
- health check e readiness check per Coolify.

Non contiene un secondo catalogo utenti o tenant. `tenant_id` è l'identificativo di `platform_tenants`; ruoli e autorizzazioni restano responsabilità dell'applicazione CodeIgniter. Il pannello CodeIgniter offre una timeline testuale essenziale; chatbot, download dei media, webhook push e gestione avanzata delle conversazioni appartengono ai milestone successivi.

UltraMsg non viene rimosso: `WHATSAPP_PROVIDER=ultramsg` resta il default del monolite. La modalità `hybrid` consente di pilotare singoli tenant elencati in `WHATSAPP_GATEWAY_TENANT_IDS`, mantenendo tutti gli altri su UltraMsg.

## API interna

Gli endpoint di servizio sono:

| Metodo | Percorso | Uso |
| --- | --- | --- |
| `GET` | `/healthz` | liveness pubblico per il runtime |
| `GET` | `/readyz` | verifica archivio sessioni |
| `GET` | `/v1/accounts/{account_id}` | stato account del tenant firmato |
| `POST` | `/v1/accounts/{account_id}/pair` | avvia il pairing QR |
| `GET` | `/v1/accounts/{account_id}/qr` | legge il QR corrente; il codice è raw e va renderizzato dal pannello |
| `POST` | `/v1/accounts/{account_id}/connect` | forza la riconnessione |
| `DELETE` | `/v1/accounts/{account_id}/session` | logout e rimozione della sessione |
| `GET` | `/v1/accounts/{account_id}/messages?limit=50` | ultimi messaggi ricevuti (massimo 100) |
| `GET` | `/v1/accounts/{account_id}/messages?limit=100&direction=all` | timeline ricevuti/inviati per il pannello conversazioni |
| `POST` | `/v1/accounts/{account_id}/messages/text` | invio testo |

I messaggi in ingresso e quelli inviati dal gateway sono salvati con isolamento per tenant e account. La risposta espone direzione, interlocutore normalizzato, ID WhatsApp, mittente, destinatario, nome visualizzato, testo o didascalia, tipo del contenuto e data dell'evento. La chiave composta su tenant, account, chat e ID rende idempotente la ricezione dopo una riconnessione.

Le chiamate `/v1` richiedono gli header:

- `X-AmbulatorioFacile-Key-ID`
- `X-AmbulatorioFacile-Tenant-ID`
- `X-AmbulatorioFacile-Timestamp`
- `X-AmbulatorioFacile-Request-ID`
- `X-AmbulatorioFacile-Signature`

La firma è HMAC-SHA256 esadecimale del payload canonico:

```text
METHOD
/path?query
tenant_id
unix_timestamp
request_id
sha256_hex_body
```

Il client PHP in `rest/app/Services/WhatsAppGatewayClient.php` implementa lo stesso contratto.

## Configurazione del container gateway

```dotenv
WHATSAPP_GATEWAY_LISTEN_ADDR=:8080
WHATSAPP_GATEWAY_DATABASE_DSN=file:/data/whatsapp-gateway.db?_foreign_keys=on&_busy_timeout=5000&_journal_mode=WAL
WHATSAPP_GATEWAY_API_KEY_ID=ambulatoriofacile-app
WHATSAPP_GATEWAY_API_SECRET=GENERARE_UN_SEGRETO_CASUALE_DI_ALMENO_32_CARATTERI
WHATSAPP_GATEWAY_ALLOWED_CLOCK_SKEW_SECONDS=300
WHATSAPP_GATEWAY_SHUTDOWN_TIMEOUT_SECONDS=20
WHATSAPP_GATEWAY_LOG_LEVEL=INFO
```

`/data` deve essere un volume persistente e dedicato. Non condividere il file SQLite tra più repliche contemporanee: in questo milestone il gateway deve avere una sola replica, perché una sessione WhatsApp non può essere gestita in parallelo da processi indipendenti.

In Coolify configurare queste variabili come **runtime only**, con l'opzione build time disabilitata. Lasciare disabilitato l'health check HTTP aggiuntivo di Coolify: l'immagine contiene già un `HEALTHCHECK` nativo che esegue il sottocomando `healthcheck`, mentre `/healthz` e `/readyz` restano disponibili per gli smoke test HTTPS dal reverse proxy.

## Build e test

La dipendenza `whatsmeow` è fissata a una revisione precisa nel `go.mod`.

```bash
go test ./...
docker build -t ambulatoriofacile-whatsapp-gateway .
```

Il Dockerfile esegue i test durante la build. Il servizio richiede accesso HTTPS in uscita verso WhatsApp e non deve essere esposto direttamente su Internet: pubblicare solo tramite rete privata/reverse proxy controllato. L'URL applicativo ufficiale previsto è configurabile, ad esempio `https://whatsapp-gateway.ambulatoriofacile.it`.

`whatsmeow` usa il protocollo WhatsApp Web multi-device, non l'API Business ufficiale di Meta. Prima dell'attivazione commerciale vanno verificati termini d'uso, affidabilità operativa e piano di ripristino dell'account.
