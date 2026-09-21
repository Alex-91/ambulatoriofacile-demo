# Preparazione tecnica del referto radiologico

È disponibile `FseCdaRadBuilderService`, distinto dal generatore RSA.
Produce CDA con template RAD `2.16.840.1.113883.2.9.10.1.7.1`, versione 1.1,
codice documento LOINC `68604-8`, sezioni Esame eseguito e Referto radiologico,
identificativo locale del paziente e riferimento dell'ordine.

Il builder è una componente tecnica collaudata offline: **non è ancora un
percorso RAD selezionabile nella schermata FSE, né un collegamento Umbria attivo**.
Il percorso produttivo di creazione/invio dei documenti rimane RSA. Non cambiare
soltanto il codice LOINC di un RSA per presentarlo come referto radiologico.

Oltre ai dati RSA di intestazione, il generatore RAD richiede:

| Campo | Significato |
|---|---|
| `patient_local_id`, `patient_local_oid` | Identità paziente nel dominio della struttura |
| `order_id`, `order_oid` | Identità della richiesta nel relativo dominio |
| `exam_code`, `exam_code_system` | Prestazione codificata LOINC o ICD-9-CM |

Questi valori non vengono inventati o ricavati da un codice fiscale. La
corrispondenza con PACS, accession number e identità regionali va concordata.
Il supporto a pazienti STP/ENI o privi di codice fiscale non è implementato da
questo builder. Il referto comprende la prestazione e il testo/conclusioni;
non viene generato un manifesto DICOM delle immagini.

Il validatore seleziona lo Schematron in base al template e rifiuta template
RSA/RAD ambigui. Catalogo Ministero fissato alla revisione
`687cf371e1d0caf4f5f9f7bcc80eab3a97f09885`: RSA v8.3 e RAD v4.1, file verificati
con SHA-256. Il vecchio catalogo RSA a 12 file resta leggibile per RSA, ma non
è sufficiente per RAD; il nuovo catalogo contiene 13 file. L'immagine di
produzione installa gli asset dal lockfile. Aggiornamenti normativi del catalogo
richiedono una revisione esplicita e nuove prove.

Prove ripetibili:

```text
pwsh ops/fse-validation/fetch-catalog.ps1 -Destination <nuova-cartella>
# Impostare FSE2_VALIDATOR_SETTINGS a un file privato con catalog e runtime locali.
python -m unittest discover -s ops/fse-validation -p test_rad.py -v
```

La suite genera solo dati sintetici: verifica XSD/Schematron RAD, regressione
RSA, rifiuto di ordine/identità locale mancanti, template incoerenti e PDF/A-3b
con CDA allegato e testo visibile. Non invia documenti a servizi esterni.

Prima del percorso operativo servono la mappatura dei campi nella cartella,
il profilo documentale autorizzato, firma reale e collaudi richiesti dal canale
di accesso FSE. Il superamento delle prove locali non equivale ad accreditamento.

Fonti ufficiali:

- [Schematron RAD del Ministero, revisione utilizzata](https://github.com/ministero-salute/it-fse-catalogs/blob/687cf371e1d0caf4f5f9f7bcc80eab3a97f09885/schematron/schematronFSE_RAD_v4.1.sch)
- [Esempio ministeriale RAD](https://github.com/ministero-salute/it-fse-support/blob/main/doc/esempi/CDA/RAD.xml)
- [Procedura di accreditamento](https://github.com/ministero-salute/it-fse-accreditamento)
