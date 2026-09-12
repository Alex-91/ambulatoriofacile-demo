# Backup cifrato e ripristino completo

`full-backup.py` esporta i database dichiarati e tutti i file delle radici
configurate. Ogni contenuto e il manifest sono cifrati con AES-256-GCM; al termine
viene verificato l'intero archivio. Il ripristino confronta conteggi e checksum
di tutte le tabelle. Non è un servizio di conservazione documentale.

## Preparazione dell'operatore

- Python con `cryptography`, client `mysql` e `mysqldump` compatibili con il server.
- Configurazione JSON privata, fuori dal repository, con credenziali dedicate.
- Chiave casuale binaria di esattamente 32 byte, conservata separatamente
  dall'archivio e copiata in un secondo luogo protetto. Non rigenerarla per
  leggere archivi precedenti. Senza chiave il ripristino non è possibile.
- Directory temporanea e destinazione backup accessibili solo all'operatore.
  Su Windows predisporre ACL NTFS: `chmod` non sostituisce le ACL.
- Arrestare applicazione, code, cron, sincronizzazioni e tutti gli scrittori
  di database/upload per l'intera esportazione; MySQL resta acceso.

Schema di configurazione (valori esemplificativi, non eseguire invariato):

```json
{
  "mysql_client": "/usr/bin/mysql",
  "mysqldump": "/usr/bin/mysqldump",
  "mysql": {"host": "mysql-source", "port": 3306, "user": "backup_operator", "password": "CONFIGURARE_IN_FILE_PRIVATO"},
  "databases": ["platform_database", "tenant_database_1"],
  "roots": {
    "application": "/srv/application-release",
    "uploads": "/srv/persistent/upload",
    "writable": "/srv/persistent/writable",
    "configuration": "/srv/private/runtime-config"
  }
}
```

Includere esplicitamente **tutti** i database tenant, codice della release,
dipendenze/lockfile, upload, `rest/writable`, archivi FSE/TS eventualmente
collocati altrove, configurazioni e chiavi applicative. Le variabili del
container e i secret manager non vengono esportati automaticamente: salvarne
la configurazione necessaria in una radice privata. La chiave backup rimane
separata. Evitare radici sovrapposte, collegamenti simbolici e la destinazione
backup dentro una radice sorgente. Lo script non scopre nuovi tenant da solo.

## Esecuzione

```text
python ops/full-backup.py backup --config /private/source.json --archive /backup/release-date.afbackup --key-file /private/backup.key --source-stopped
python ops/full-backup.py verify --archive /backup/release-date.afbackup --key-file /private/backup.key
python ops/full-backup.py restore --config /private/restore.json --archive /backup/release-date.afbackup --key-file /private/backup.key --destination /restore/new-directory
```

`restore.json` indica un'istanza MySQL **nuova, vuota e separata**: non deve
contenere database applicativi. Lo script ricrea i nomi originali per mantenere
coerenti viste, trigger e routine; non effettua il restore sopra un ambiente
esistente. Prima di scrivere controlla autenticità di tutti i contenuti e
rifiuta destinazione uguale alla sorgente o cartella file già presente.

L'esportazione comprende routine, eventi e trigger. Usare server compatibili;
prima di avviare l'applicazione ripristinare utenti/grant necessari, configurare
host e percorsi per il nuovo ambiente, verificare chiavi applicative, permessi
storage e lettura effettiva dei documenti. Utenti/grant MySQL non sono copiati
automaticamente. Tenere disattivati gli invii esterni nell'ambiente di prova.
Un restore interrotto richiede una nuova destinazione vuota; non sovrascrivere
alla cieca quella parzialmente ripristinata.

## Evidenza del 12 settembre 2026

Prova isolata: **4 database, 740 file**, archivio verificato e ripristino su
seconda istanza MySQL. Rifiutati chiave errata, archivio alterato, destinazione
uguale alla sorgente e destinazione non vuota. Conteggi/checksum coincidenti.
La cartella ripristinata decifra i contenuti, restituisce entrambe le firme
PAdES/CAdES e mantiene lo storico della revoca del consenso.

Rapporto locale: `rest/writable/fse-app-labs/5a698e6c209d491283906f6ad65a050b/full-backup-drill/report.json`.
La prova contiene esclusivamente dati sintetici e le radici dichiarate nel
driver `ops/fse-validation/app-lab-full-backup.py`; non è un backup della
produzione né dell'intero repository con tutti i suoi ambienti. Fotografia
realizzata prima delle ultime correzioni TS/email: verifica il ripristino del
modulo clinico, non attesta una copia finale pronta per il rilascio.
Pianificazione, retention e seconda copia remota vanno configurate nell'ambiente
operativo; nessun job produttivo è stato attivato da questo task.
