# Audit runtime demo

## Obiettivo

Tenere traccia dello stato reale della copia demo/commerciale senza toccare farmacia.

## Stato validato

- database demo dedicato: `dottorapp_demo`
- seed demo dedicato: `tools/SeedDemoData.php`
- runtime separata generata: `dist/demo-runtime`
- overview commerciale pubblica: `demo`
- percorsi guidati pubblici:
  - `demo/vertical/medical`
  - `demo/vertical/sport-rehab`
- verifica CLI dalla root riuscita con:

```bash
php dist/demo-runtime/spark routes
```

## Report piu recente

- runtime report: `writable/demo_setup/demo_runtime_20260606_185840.json`
- seed report: `writable/demo_setup/demo_seed_20260606_184429.json`

## Gap frontend emersi

L'audit statico piu recente segnala soprattutto questi bucket:

- `public/dist`: 124 referenze
- `public/bootstrap`: 80 referenze
- `public/plugins`: 76 referenze
- `public/assets`: 31 referenze
- `public/css`: 18 referenze

Questi numeri indicano che il collo di bottiglia attuale non e il backend demo:

- la struttura separata esiste
- i dati finti esistono
- le rotte applicative si caricano

Il collo di bottiglia e il frontend legacy mancante nel repository.

## Strategia consigliata

Ordine consigliato per rendere la demo vendibile:

1. usare `demo` come entry point commerciale pulito
2. usare `demo/vertical/medical` e `demo/vertical/sport-rehab` come script guidati di vendita
3. rifinire login e accesso demo con asset minimi controllati
4. decidere se recuperare bundle legacy mancanti oppure sostituire progressivamente le schermate critiche
5. concentrare la demo guidata sui due verticali scelti:
   - `Medical / Poliambulatorio`
   - `Sport Medical / Fisioterapia / Riabilitazione`

## Regola invariata

Tutto questo lavoro resta confinato a:

- repository corrente
- `dottorapp_demo`
- `dist/demo-runtime`

Mai alla copia farmacia o al database farmacia.
