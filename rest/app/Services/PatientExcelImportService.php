<?php

namespace App\Services;

use App\Models\PazientiModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

class PatientExcelImportService
{
    public const FEATURE_KEY = 'patient_excel_import';

    private const IMPORT_DIR = 'patient_excel_imports';
    private const MAX_PREVIEW_ROWS = 8;
    private const HEADER_SCAN_LIMIT = 10;
    private const UPLOAD_TTL_SECONDS = 86400;
    private const MAX_REPORTED_ERRORS = 50;
    private const ALLOWED_EXTENSIONS = ['xlsx', 'xlsm', 'xltx', 'xltm'];

    private ?PazientiModel $pazientiModel = null;
    private ?TenantContextService $tenantContextService = null;
    private ?TenantStoragePathService $tenantStoragePathService = null;
    private ?array $fieldDefinitions = null;

    public function __construct(
        ?PazientiModel $pazientiModel = null,
        ?TenantContextService $tenantContextService = null,
        ?TenantStoragePathService $tenantStoragePathService = null
    ) {
        $this->pazientiModel = $pazientiModel;
        $this->tenantContextService = $tenantContextService;
        $this->tenantStoragePathService = $tenantStoragePathService;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getTargetFieldDefinitions(): array
    {
        if ($this->fieldDefinitions !== null) {
            return $this->fieldDefinitions;
        }

        $definitions = [
            'denominazione' => [
                'label' => 'Denominazione',
                'payload_key' => 'denominazione',
                'aliases' => ['denominazione', 'ragione sociale', 'azienda', 'cliente'],
            ],
            'cognome' => [
                'label' => 'Cognome',
                'payload_key' => 'cognome',
                'aliases' => ['cognome', 'surname'],
            ],
            'nome' => [
                'label' => 'Nome',
                'payload_key' => 'nome',
                'aliases' => ['nome', 'first name'],
            ],
            'cod_fis' => [
                'label' => 'Codice fiscale',
                'payload_key' => 'cod_fis',
                'aliases' => ['codice fiscale', 'cod fiscale', 'codicefiscale', 'cf'],
            ],
            'partita_iva' => [
                'label' => 'Partita IVA',
                'payload_key' => 'partita_iva',
                'aliases' => ['partita iva', 'piva', 'p iva', 'p.iva'],
            ],
            'data_nascita' => [
                'label' => 'Data nascita',
                'payload_key' => 'data_nascita',
                'aliases' => ['data nascita', 'data di nascita', 'nascita'],
            ],
            'comune_nascita' => [
                'label' => 'Comune nascita',
                'payload_key' => 'comune_nascita',
                'aliases' => ['comune nascita', 'luogo nascita'],
            ],
            'provincia_nascita' => [
                'label' => 'Provincia nascita',
                'payload_key' => 'provincia_nascita',
                'aliases' => ['provincia nascita', 'prov nascita'],
            ],
            'telefono' => [
                'label' => 'Telefono',
                'payload_key' => 'telefono',
                'aliases' => ['telefono', 'tel', 'telefono fisso'],
            ],
            'cellulare' => [
                'label' => 'Cellulare',
                'payload_key' => 'cellulare',
                'aliases' => ['cellulare', 'mobile'],
            ],
            'email' => [
                'label' => 'Email',
                'payload_key' => 'email',
                'aliases' => ['email', 'e-mail', 'mail'],
            ],
            'email_pec' => [
                'label' => 'Email PEC',
                'payload_key' => 'email_pec',
                'aliases' => ['email pec', 'emailpec', 'pec'],
            ],
            'banca' => [
                'label' => 'Banca',
                'payload_key' => 'banca',
                'aliases' => ['banca', 'istituto bancario'],
            ],
            'condizioni_pagamento' => [
                'label' => 'Condizioni di pagamento',
                'payload_key' => 'condizioni_pagamento',
                'aliases' => ['condizioni di pagamento', 'condizioni pagamento', 'pagamento'],
            ],
            'codice_destinatario' => [
                'label' => 'Codice destinatario',
                'payload_key' => 'codice_destinatario',
                'aliases' => ['codice destinatario', 'codice ufficio destinatario', 'codice ufficio', 'codice sdi'],
            ],
            'iva_differita' => [
                'label' => 'IVA differita',
                'payload_key' => 'iva_differita',
                'aliases' => ['iva differita'],
            ],
            'note_cliente' => [
                'label' => 'Note cliente',
                'payload_key' => 'note_cliente',
                'aliases' => ['note cliente', 'note', 'annotazioni cliente'],
            ],
            'indirizzo' => [
                'label' => 'Indirizzo',
                'payload_key' => 'indirizzo',
                'aliases' => ['indirizzo', 'via'],
            ],
            'nr_civico' => [
                'label' => 'Nr. civico',
                'payload_key' => 'nr_civico',
                'aliases' => ['nr civico', 'nr. civico', 'numero civico', 'civico'],
            ],
            'citta' => [
                'label' => 'Comune',
                'payload_key' => 'citta',
                'aliases' => ['comune', 'citta', 'citta'],
            ],
            'cap' => [
                'label' => 'CAP',
                'payload_key' => 'cap',
                'aliases' => ['cap', 'cap principale'],
            ],
            'provincia' => [
                'label' => 'Provincia',
                'payload_key' => 'provincia',
                'aliases' => ['provincia', 'prov'],
            ],
            'indirizzo_secondario' => [
                'label' => '2o indirizzo',
                'payload_key' => 'indirizzo_secondario',
                'aliases' => ['2o indirizzo', '2o indirizzo ', 'secondo indirizzo', 'indirizzo secondario'],
            ],
            'nr_civico_secondario' => [
                'label' => '2o nr. civico',
                'payload_key' => 'nr_civico_secondario',
                'aliases' => ['2o nr civico', '2o nr. civico', '2o numero civico', 'nr civico secondario'],
            ],
            'comune_secondario' => [
                'label' => '2o comune',
                'payload_key' => 'comune_secondario',
                'aliases' => ['2o comune', 'comune secondario', '2a citta', '2o comune '],
            ],
            'cap_secondario' => [
                'label' => '2o CAP',
                'payload_key' => 'cap_secondario',
                'aliases' => ['2o cap', 'cap secondario'],
            ],
            'provincia_secondaria' => [
                'label' => '2a provincia',
                'payload_key' => 'provincia_secondaria',
                'aliases' => ['2a provincia', '2a prov', 'provincia secondaria'],
            ],
            'residenza_indirizzo' => [
                'label' => 'Residenza indirizzo',
                'payload_key' => 'residenza_indirizzo',
                'aliases' => ['residenza indirizzo', 'indirizzo residenza'],
            ],
            'residenza_comune' => [
                'label' => 'Residenza comune',
                'payload_key' => 'residenza_comune',
                'aliases' => ['residenza comune', 'comune residenza'],
            ],
            'residenza_cap' => [
                'label' => 'Residenza CAP',
                'payload_key' => 'residenza_cap',
                'aliases' => ['residenza cap', 'cap residenza'],
            ],
            'residenza_provincia' => [
                'label' => 'Residenza provincia',
                'payload_key' => 'residenza_provincia',
                'aliases' => ['residenza provincia', 'provincia residenza'],
            ],
            'appointment_reminder_sms_enabled' => [
                'label' => 'Promemoria SMS appuntamento',
                'payload_key' => 'appointment_reminder_sms_enabled',
                'aliases' => ['promemoria sms', 'sms appuntamento', 'promemoria appuntamento sms'],
            ],
            'cliente_attivo' => [
                'label' => 'Cliente attivo',
                'payload_key' => 'cliente_attivo',
                'aliases' => ['cliente attivo', 'attivo cliente', 'attivo'],
            ],
            'bloccato' => [
                'label' => 'Bloccato in agenda',
                'payload_key' => 'bloccato',
                'aliases' => ['bloccato', 'bloccato agenda'],
            ],
            'paz_spec' => [
                'label' => 'Paziente speciale',
                'payload_key' => 'paz_spec',
                'aliases' => ['paziente speciale', 'paz spec', 'paz_spec'],
            ],
        ];

        foreach ($definitions as $fieldKey => &$definition) {
            $aliases = (array) ($definition['aliases'] ?? []);
            $aliases[] = (string) ($definition['label'] ?? $fieldKey);
            $aliases[] = $fieldKey;

            $definition['normalized_aliases'] = array_values(array_unique(array_filter(array_map(
                fn(string $value): string => $this->normalizeHeaderLabel($value),
                $aliases
            ))));
        }
        unset($definition);

        return $this->fieldDefinitions = $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    public function prepareUploadedWorkbook(UploadedFile $file): array
    {
        $this->cleanupExpiredUploads();
        $this->assertSupportedUploadedFile($file);

        $token = bin2hex(random_bytes(16));
        $extension = strtolower((string) $file->getClientExtension());
        if ($extension === '') {
            $extension = 'xlsx';
        }

        $importsDir = $this->importsDir(true);
        $storedName = $token . '.' . $extension;
        $file->move($importsDir, $storedName);

        $storedPath = $importsDir . DIRECTORY_SEPARATOR . $storedName;

        try {
            $inspection = $this->inspectWorkbookFile($storedPath);
        } catch (\Throwable $e) {
            @unlink($storedPath);
            throw $e;
        }

        $metadata = [
            'token' => $token,
            'tenant_id' => $this->currentTenantId(),
            'uploaded_by_user_id' => $this->currentAppUserId(),
            'original_name' => trim((string) $file->getClientName()),
            'stored_name' => $storedName,
            'uploaded_at' => date('Y-m-d H:i:s'),
            'sheet_name' => (string) ($inspection['sheet_name'] ?? 'Foglio 1'),
            'worksheet_count' => (int) ($inspection['worksheet_count'] ?? 1),
            'header_row_number' => (int) ($inspection['header_row_number'] ?? 1),
            'columns' => array_values((array) ($inspection['columns'] ?? [])),
            'preview_rows' => array_values((array) ($inspection['preview_rows'] ?? [])),
            'data_row_count' => (int) ($inspection['data_row_count'] ?? 0),
            'warnings' => array_values((array) ($inspection['warnings'] ?? [])),
            'default_mapping' => $this->buildDefaultMapping((array) ($inspection['columns'] ?? [])),
        ];

        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($this->metadataPath($token), $json) === false) {
            @unlink($storedPath);
            throw new RuntimeException('Impossibile preparare i dati temporanei per l’importazione.');
        }

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadPreparedUpload(string $token): array
    {
        $token = $this->normalizeToken($token);
        if ($token === '') {
            throw new RuntimeException('Token importazione non valido.');
        }

        $metadataPath = $this->metadataPath($token);
        if (!is_file($metadataPath)) {
            throw new RuntimeException('Il file di importazione non è più disponibile. Caricalo di nuovo.');
        }

        $json = file_get_contents($metadataPath);
        $metadata = is_string($json)
            ? json_decode($json, true)
            : null;

        if (!is_array($metadata)) {
            throw new RuntimeException('I dati temporanei dell’importazione non sono leggibili.');
        }

        $this->assertPreparedUploadOwnership($metadata);

        $storedName = trim((string) ($metadata['stored_name'] ?? ''));
        if ($storedName === '') {
            throw new RuntimeException('File temporaneo dell’importazione mancante.');
        }

        $storedPath = $this->importsDir(false) . DIRECTORY_SEPARATOR . basename($storedName);
        if (!is_file($storedPath)) {
            throw new RuntimeException('Il file Excel temporaneo non è più disponibile. Caricalo di nuovo.');
        }

        $metadata['stored_path'] = $storedPath;
        $metadata['columns'] = array_values((array) ($metadata['columns'] ?? []));
        $metadata['preview_rows'] = array_values((array) ($metadata['preview_rows'] ?? []));
        $metadata['warnings'] = array_values((array) ($metadata['warnings'] ?? []));
        $metadata['default_mapping'] = is_array($metadata['default_mapping'] ?? null)
            ? $metadata['default_mapping']
            : $this->buildDefaultMapping($metadata['columns']);

        return $metadata;
    }

    /**
     * @param array<string, mixed> $columnMapping
     * @return array<string, mixed>
     */
    public function importPreparedWorkbook(string $token, int $idDot, array $columnMapping, int $actingUserId, bool $associateAllDoctors = false): array
    {
        if ($idDot <= 0) {
            throw new RuntimeException('Medico non valido per l’importazione.');
        }

        $prepared = $this->loadPreparedUpload($token);
        $mappingState = $this->validateColumnMapping($columnMapping, (array) ($prepared['columns'] ?? []));
        if ($mappingState['errors'] !== []) {
            throw new RuntimeException(implode(' ', $mappingState['errors']));
        }

        $activeMapping = array_filter(
            (array) ($mappingState['mapping'] ?? []),
            static fn(string $value): bool => trim($value) !== ''
        );

        $headerRowNumber = (int) ($prepared['header_row_number'] ?? 1);
        $result = [
            'rows_examined' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'error_count' => 0,
            'matched_count' => 0,
            'total_data_rows' => (int) ($prepared['data_row_count'] ?? 0),
            'warnings' => array_values(array_unique(array_merge(
                (array) ($prepared['warnings'] ?? []),
                (array) ($mappingState['warnings'] ?? [])
            ))),
            'errors' => [],
            'mapping' => $mappingState['mapping'],
        ];

        $workbook = $this->openWorkbookContext((string) ($prepared['stored_path'] ?? ''));
        $patientsModel = $this->patientsModel();

        $this->iterateSheetRows(
            (string) ($workbook['sheet_xml'] ?? ''),
            (array) ($workbook['shared_strings'] ?? []),
            function (int $rowNumber, array $cells) use (
                $headerRowNumber,
                $idDot,
                $actingUserId,
                $activeMapping,
                $associateAllDoctors,
                $patientsModel,
                &$result
            ): bool {
                if ($rowNumber <= $headerRowNumber) {
                    return true;
                }

                if (!$this->rowHasVisibleValues($cells)) {
                    return true;
                }

                $result['rows_examined']++;

                $payload = $this->buildRowPayload($cells, $activeMapping);
                if ($payload === []) {
                    $result['skipped_count']++;
                    return true;
                }

                $match = $patientsModel->resolveImportPatientMatch($payload, $idDot, $actingUserId);
                if (!empty($match['conflict'])) {
                    $result['error_count']++;
                    $this->appendImportError(
                        $result['errors'],
                        $rowNumber,
                        (string) ($match['message'] ?? 'Sono stati trovati più pazienti compatibili con gli stessi identificativi.')
                    );
                    return true;
                }

                $matchedRow = is_array($match['row'] ?? null) ? $match['row'] : null;
                $matchedId = (int) ($match['id_paziente'] ?? 0);
                if ($matchedId > 0) {
                    $payload['id_paziente'] = $matchedId;
                    $payload = $this->mergeExistingIdentityFields($payload, $matchedRow ?? []);
                    $result['matched_count']++;
                }

                if (!$this->hasValidIdentityPayload($payload)) {
                    $result['error_count']++;
                    $this->appendImportError(
                        $result['errors'],
                        $rowNumber,
                        'Riga senza dati anagrafici minimi: serve almeno la denominazione oppure nome e cognome.'
                    );
                    return true;
                }

                if ($associateAllDoctors) {
                    $payload['associate_all_doctors'] = 1;
                }

                try {
                    $savedId = $patientsModel->savePatientAndLink(
                        $payload,
                        $idDot,
                        $actingUserId,
                        $matchedId > 0
                    );
                    if ($savedId <= 0) {
                        throw new RuntimeException('Salvataggio paziente non riuscito.');
                    }

                    if ($matchedId > 0) {
                        $result['updated_count']++;
                    } else {
                        $result['created_count']++;
                    }
                } catch (\Throwable $e) {
                    $result['error_count']++;
                    $this->appendImportError($result['errors'], $rowNumber, $e->getMessage());
                }

                return true;
            }
        );

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectWorkbookFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('File Excel non trovato.');
        }

        $workbook = $this->openWorkbookContext($path);
        $headerDetection = $this->detectHeaderRow($workbook);
        $headerRowNumber = (int) ($headerDetection['row_number'] ?? 1);
        $headerCells = (array) ($headerDetection['cells'] ?? []);

        $columns = [];
        foreach ($headerCells as $columnIndex => $label) {
            $normalizedHeader = $this->normalizeHeaderLabel((string) $label);
            if ($normalizedHeader === '') {
                continue;
            }

            $suggestedField = $this->suggestFieldForHeader($normalizedHeader);
            $columns[] = [
                'index' => (int) $columnIndex,
                'letter' => $this->columnIndexToLetters((int) $columnIndex),
                'header' => trim((string) $label),
                'normalized_header' => $normalizedHeader,
                'suggested_field' => $suggestedField,
            ];
        }

        if ($columns === []) {
            throw new RuntimeException('Non ho trovato intestazioni colonne utilizzabili nel file Excel.');
        }

        $columnIndexes = array_map(
            static fn(array $column): int => (int) ($column['index'] ?? 0),
            $columns
        );
        $previewRows = [];
        $dataRowCount = 0;

        $this->iterateSheetRows(
            (string) ($workbook['sheet_xml'] ?? ''),
            (array) ($workbook['shared_strings'] ?? []),
            function (int $rowNumber, array $cells) use (
                $headerRowNumber,
                $columnIndexes,
                &$previewRows,
                &$dataRowCount
            ): bool {
                if ($rowNumber <= $headerRowNumber || !$this->rowHasVisibleValues($cells)) {
                    return true;
                }

                $dataRowCount++;

                if (count($previewRows) >= self::MAX_PREVIEW_ROWS) {
                    return true;
                }

                $rowValues = [];
                foreach ($columnIndexes as $columnIndex) {
                    $rowValues[$columnIndex] = $this->normalizePreviewValue((string) ($cells[$columnIndex] ?? ''));
                }

                $previewRows[] = [
                    'row_number' => $rowNumber,
                    'values' => $rowValues,
                ];

                return true;
            }
        );

        $warnings = array_values((array) ($headerDetection['warnings'] ?? []));
        if ((int) ($workbook['worksheet_count'] ?? 1) > 1) {
            $warnings[] = 'È stato letto il primo foglio del workbook. Se il file contiene altri fogli, per l’import conta solo il primo.';
        }

        return [
            'sheet_name' => (string) ($workbook['sheet_name'] ?? 'Foglio 1'),
            'worksheet_count' => (int) ($workbook['worksheet_count'] ?? 1),
            'header_row_number' => $headerRowNumber,
            'columns' => $columns,
            'preview_rows' => $previewRows,
            'data_row_count' => $dataRowCount,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<int, array<string, mixed>> $columns
     * @return array{mapping: array<int, string>, errors: array<int, string>, warnings: array<int, string>}
     */
    public function validateColumnMapping(array $mapping, array $columns): array
    {
        $definitions = $this->getTargetFieldDefinitions();
        $columnsByIndex = [];
        foreach ($columns as $column) {
            $columnIndex = (int) ($column['index'] ?? 0);
            if ($columnIndex > 0) {
                $columnsByIndex[$columnIndex] = $column;
            }
        }

        $normalizedMapping = [];
        $errors = [];
        $warnings = [];
        $usedFields = [];

        foreach ($columnsByIndex as $columnIndex => $column) {
            $fieldKey = trim((string) ($mapping[$columnIndex] ?? ''));
            if ($fieldKey === '') {
                $normalizedMapping[$columnIndex] = '';
                continue;
            }

            if (!isset($definitions[$fieldKey])) {
                $errors[] = 'Il campo selezionato per la colonna "' . (string) ($column['header'] ?? ('Colonna ' . $columnIndex)) . '" non è valido.';
                continue;
            }

            if (isset($usedFields[$fieldKey])) {
                $errors[] = 'Il campo "' . (string) ($definitions[$fieldKey]['label'] ?? $fieldKey) . '" è stato assegnato a più colonne Excel.';
                continue;
            }

            $normalizedMapping[$columnIndex] = $fieldKey;
            $usedFields[$fieldKey] = true;
        }

        if (array_filter($normalizedMapping, static fn(string $value): bool => $value !== '') === []) {
            $errors[] = 'Seleziona almeno una colonna da importare.';
        }

        $hasDenominazione = isset($usedFields['denominazione']);
        $hasCognome = isset($usedFields['cognome']);
        $hasNome = isset($usedFields['nome']);
        if (!$hasDenominazione && !($hasCognome && $hasNome)) {
            $errors[] = 'Per creare o aggiornare l’anagrafica serve almeno la denominazione oppure la coppia nome e cognome.';
        }

        if (!isset($usedFields['cod_fis']) && !isset($usedFields['partita_iva'])) {
            $warnings[] = 'Senza codice fiscale o partita IVA l’import può creare nuovi pazienti ma non aggiornare in modo affidabile quelli già esistenti.';
        }

        return [
            'mapping' => $normalizedMapping,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     * @return array<int, string>
     */
    public function buildDefaultMapping(array $columns): array
    {
        $mapping = [];
        $usedFields = [];

        foreach ($columns as $column) {
            $columnIndex = (int) ($column['index'] ?? 0);
            if ($columnIndex <= 0) {
                continue;
            }

            $suggestedField = trim((string) ($column['suggested_field'] ?? ''));
            if ($suggestedField !== '' && !isset($usedFields[$suggestedField])) {
                $mapping[$columnIndex] = $suggestedField;
                $usedFields[$suggestedField] = true;
                continue;
            }

            $mapping[$columnIndex] = '';
        }

        return $mapping;
    }

    private function patientsModel(): PazientiModel
    {
        if ($this->pazientiModel === null) {
            $this->pazientiModel = new PazientiModel();
        }

        return $this->pazientiModel;
    }

    private function tenantContextService(): TenantContextService
    {
        if ($this->tenantContextService === null) {
            $this->tenantContextService = new TenantContextService();
        }

        return $this->tenantContextService;
    }

    private function tenantStoragePathService(): TenantStoragePathService
    {
        if ($this->tenantStoragePathService === null) {
            $this->tenantStoragePathService = new TenantStoragePathService();
        }

        return $this->tenantStoragePathService;
    }

    private function currentTenantId(): int
    {
        try {
            $context = $this->tenantContextService()->getCurrentTenant();
        } catch (\Throwable $e) {
            return 0;
        }

        return $context !== null ? (int) $context->tenantId : 0;
    }

    private function currentAppUserId(): int
    {
        try {
            return (int) (session()->get('userId') ?? session()->get('id_user') ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function importsDir(bool $ensure): string
    {
        $root = rtrim(WRITEPATH, DIRECTORY_SEPARATOR);

        try {
            $context = $this->tenantContextService()->getCurrentTenant();
            if ($context !== null && $context->isValid()) {
                $root = $this->tenantStoragePathService()->writableRoot($context->toArray());
            }
        } catch (\Throwable $e) {
            $root = rtrim(WRITEPATH, DIRECTORY_SEPARATOR);
        }

        $path = $root . DIRECTORY_SEPARATOR . self::IMPORT_DIR;
        if ($ensure && !is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Impossibile creare la cartella temporanea per l’importazione Excel.');
        }

        return $path;
    }

    private function metadataPath(string $token): string
    {
        return $this->importsDir(true) . DIRECTORY_SEPARATOR . $token . '.json';
    }

    private function assertSupportedUploadedFile(UploadedFile $file): void
    {
        $extension = strtolower((string) $file->getClientExtension());
        if ($extension === '' || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Carica un file Excel in formato .xlsx o .xlsm.');
        }

        $zip = new ZipArchive();
        $status = $zip->open($file->getTempName());
        if ($status !== true) {
            throw new RuntimeException('Il file caricato non è un workbook Excel leggibile.');
        }

        $zip->close();
    }

    private function cleanupExpiredUploads(): void
    {
        $importsDir = $this->importsDir(false);
        if (!is_dir($importsDir)) {
            return;
        }

        $expireBefore = time() - self::UPLOAD_TTL_SECONDS;
        $entries = glob($importsDir . DIRECTORY_SEPARATOR . '*');
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if (!is_file($entry)) {
                continue;
            }

            $mtime = @filemtime($entry);
            if ($mtime !== false && $mtime < $expireBefore) {
                @unlink($entry);
            }
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function assertPreparedUploadOwnership(array $metadata): void
    {
        $ownerTenantId = (int) ($metadata['tenant_id'] ?? 0);
        $ownerUserId = (int) ($metadata['uploaded_by_user_id'] ?? 0);

        $currentTenantId = $this->currentTenantId();
        $currentUserId = $this->currentAppUserId();

        if ($ownerTenantId > 0 && $currentTenantId > 0 && $ownerTenantId !== $currentTenantId) {
            throw new RuntimeException('Questo file Excel appartiene a un altro spazio.');
        }

        if ($ownerUserId > 0 && $currentUserId > 0 && $ownerUserId !== $currentUserId) {
            throw new RuntimeException('Questo file Excel è stato caricato da un altro utente.');
        }
    }

    private function normalizeToken(string $token): string
    {
        return preg_match('/^[a-f0-9]{16,64}$/', trim($token)) ? trim($token) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function openWorkbookContext(string $path): array
    {
        $zip = new ZipArchive();
        $status = $zip->open($path);
        if ($status !== true) {
            throw new RuntimeException('Il file Excel non può essere aperto.');
        }

        try {
            $workbookXml = $zip->getFromName('xl/workbook.xml');
            $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

            if (!is_string($workbookXml) || trim($workbookXml) === '') {
                throw new RuntimeException('Workbook Excel non valido: manca xl/workbook.xml.');
            }

            if (!is_string($relsXml) || trim($relsXml) === '') {
                throw new RuntimeException('Workbook Excel non valido: manca xl/_rels/workbook.xml.rels.');
            }

            $worksheet = $this->resolveFirstWorksheet($workbookXml, $relsXml);
            $sheetXml = $zip->getFromName((string) ($worksheet['path'] ?? ''));

            if (!is_string($sheetXml) || trim($sheetXml) === '') {
                throw new RuntimeException('Il foglio Excel principale non è leggibile.');
            }

            return [
                'sheet_name' => (string) ($worksheet['name'] ?? 'Foglio 1'),
                'worksheet_count' => (int) ($worksheet['worksheet_count'] ?? 1),
                'sheet_xml' => $sheetXml,
                'shared_strings' => $this->loadSharedStringsFromZip($zip),
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFirstWorksheet(string $workbookXml, string $relsXml): array
    {
        $workbook = @simplexml_load_string($workbookXml);
        if (!$workbook instanceof SimpleXMLElement) {
            throw new RuntimeException('Workbook Excel non valido.');
        }

        $workbookNamespaces = $workbook->getNamespaces(true);
        $mainNs = $workbookNamespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relationNs = $workbookNamespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $workbook->registerXPathNamespace('s', $mainNs);
        $workbook->registerXPathNamespace('r', $relationNs);
        $sheetNodes = $workbook->xpath('/s:workbook/s:sheets/s:sheet');
        $sheetNodes = is_array($sheetNodes) ? $sheetNodes : [];
        $worksheetCount = count($sheetNodes);
        $firstSheet = $sheetNodes[0] ?? null;

        if (!$firstSheet instanceof SimpleXMLElement) {
            throw new RuntimeException('Workbook Excel senza fogli disponibili.');
        }

        $sheetName = trim((string) ($firstSheet['name'] ?? 'Foglio 1'));
        $sheetAttributes = $firstSheet->attributes('r', true);
        $sheetRelationId = trim((string) ($sheetAttributes['id'] ?? ''));
        if ($sheetRelationId === '') {
            throw new RuntimeException('Workbook Excel senza riferimento al foglio principale.');
        }

        $rels = @simplexml_load_string($relsXml);
        if (!$rels instanceof SimpleXMLElement) {
            throw new RuntimeException('Relazioni workbook Excel non valide.');
        }

        $relsNamespaces = $rels->getNamespaces(true);
        $packageNs = $relsNamespaces[''] ?? 'http://schemas.openxmlformats.org/package/2006/relationships';
        $rels->registerXPathNamespace('rel', $packageNs);
        $relations = $rels->xpath('/rel:Relationships/rel:Relationship');
        $relations = is_array($relations) ? $relations : [];

        $target = '';
        foreach ($relations as $relation) {
            if (trim((string) ($relation['Id'] ?? '')) === $sheetRelationId) {
                $target = trim((string) ($relation['Target'] ?? ''));
                break;
            }
        }

        if ($target === '') {
            throw new RuntimeException('Non riesco a trovare il foglio principale del file Excel.');
        }

        return [
            'name' => $sheetName !== '' ? $sheetName : 'Foglio 1',
            'worksheet_count' => max(1, $worksheetCount),
            'path' => $this->normalizeWorksheetPath($target),
        ];
    }

    private function normalizeWorksheetPath(string $target): string
    {
        $target = str_replace('\\', '/', trim($target));
        $parts = [];

        foreach (explode('/', $target) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        $normalized = implode('/', $parts);
        if ($normalized === '') {
            throw new RuntimeException('Percorso foglio Excel non valido.');
        }

        if (!str_starts_with($normalized, 'xl/')) {
            $normalized = 'xl/' . ltrim($normalized, '/');
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function loadSharedStringsFromZip(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($xml) || trim($xml) === '') {
            return [];
        }

        $root = @simplexml_load_string($xml);
        if (!$root instanceof SimpleXMLElement) {
            return [];
        }

        $strings = [];
        $namespaces = $root->getNamespaces(true);
        $mainNs = $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $root->registerXPathNamespace('s', $mainNs);
        $items = $root->xpath('/s:sst/s:si');
        $items = is_array($items) ? $items : [];

        foreach ($items as $item) {
            $strings[] = $this->extractSharedStringItem($item);
        }

        return $strings;
    }

    private function extractSharedStringItem(SimpleXMLElement $item): string
    {
        $children = $this->xmlChildren($item);
        if (isset($children->t)) {
            return $this->normalizeCellText((string) $children->t);
        }

        $parts = [];
        foreach ($children->r as $run) {
            $runChildren = $this->xmlChildren($run);
            $parts[] = (string) ($runChildren->t ?? '');
        }

        return $this->normalizeCellText(implode('', $parts));
    }

    /**
     * @param array<int, string> $sharedStrings
     */
    private function iterateSheetRows(string $sheetXml, array $sharedStrings, callable $callback): void
    {
        $reader = new XMLReader();
        if (!$reader->XML($sheetXml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Impossibile leggere il foglio Excel.');
        }

        $fallbackRowNumber = 0;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $rowXml = $reader->readOuterXml();
                if ($rowXml === '') {
                    continue;
                }

                $row = @simplexml_load_string($rowXml);
                if (!$row instanceof SimpleXMLElement) {
                    continue;
                }

                $fallbackRowNumber++;
                $rowAttributes = $row->attributes();
                $rowNumber = (int) ($rowAttributes['r'] ?? 0);
                if ($rowNumber <= 0) {
                    $rowNumber = $fallbackRowNumber;
                }

                $continue = $callback($rowNumber, $this->extractRowCells($row, $sharedStrings));
                if ($continue === false) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array<int, string>
     */
    private function extractRowCells(SimpleXMLElement $row, array $sharedStrings): array
    {
        $rowChildren = $this->xmlChildren($row);
        $cells = [];
        $fallbackColumnIndex = 0;

        foreach ($rowChildren->c as $cell) {
            $fallbackColumnIndex++;
            $cellAttributes = $cell->attributes();
            $reference = trim((string) ($cellAttributes['r'] ?? ''));
            $columnIndex = $this->columnIndexFromReference($reference);
            if ($columnIndex <= 0) {
                $columnIndex = $fallbackColumnIndex;
            }

            $cells[$columnIndex] = $this->extractCellValue($cell, $sharedStrings);
        }

        ksort($cells);

        return $cells;
    }

    /**
     * @param array<int, string> $sharedStrings
     */
    private function extractCellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $attributes = $cell->attributes();
        $type = strtolower(trim((string) ($attributes['t'] ?? '')));
        $children = $this->xmlChildren($cell);

        if ($type === 'inlineStr') {
            return $this->normalizeCellText($this->extractInlineString($children->is ?? null));
        }

        if ($type === 's') {
            $sharedIndex = isset($children->v) ? (int) $children->v : -1;
            return $this->normalizeCellText((string) ($sharedStrings[$sharedIndex] ?? ''));
        }

        if ($type === 'b') {
            return isset($children->v) && (string) $children->v === '1' ? '1' : '0';
        }

        if ($type === 'str') {
            return $this->normalizeCellText((string) ($children->v ?? ''));
        }

        if (isset($children->v)) {
            return $this->normalizeCellText((string) $children->v);
        }

        if (isset($children->is)) {
            return $this->normalizeCellText($this->extractInlineString($children->is));
        }

        return '';
    }

    private function extractInlineString($node): string
    {
        if (!$node instanceof SimpleXMLElement) {
            return '';
        }

        $children = $this->xmlChildren($node);
        if (isset($children->t)) {
            return (string) $children->t;
        }

        $parts = [];
        foreach ($children->r as $run) {
            $runChildren = $this->xmlChildren($run);
            $parts[] = (string) ($runChildren->t ?? '');
        }

        return implode('', $parts);
    }

    /**
     * @param array<string, mixed> $workbook
     * @return array<string, mixed>
     */
    private function detectHeaderRow(array $workbook): array
    {
        $candidates = [];

        $this->iterateSheetRows(
            (string) ($workbook['sheet_xml'] ?? ''),
            (array) ($workbook['shared_strings'] ?? []),
            function (int $rowNumber, array $cells) use (&$candidates): bool {
                if (!$this->rowHasVisibleValues($cells)) {
                    return true;
                }

                $candidates[] = [
                    'row_number' => $rowNumber,
                    'cells' => $cells,
                    'score' => $this->scoreHeaderCandidate($cells),
                    'filled_count' => $this->countFilledCells($cells),
                ];

                return count($candidates) < self::HEADER_SCAN_LIMIT;
            }
        );

        if ($candidates === []) {
            throw new RuntimeException('Il file Excel non contiene righe utilizzabili.');
        }

        usort($candidates, static function (array $left, array $right): int {
            if ((int) ($left['score'] ?? 0) !== (int) ($right['score'] ?? 0)) {
                return (int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0);
            }

            if ((int) ($left['filled_count'] ?? 0) !== (int) ($right['filled_count'] ?? 0)) {
                return (int) ($right['filled_count'] ?? 0) <=> (int) ($left['filled_count'] ?? 0);
            }

            return (int) ($left['row_number'] ?? 0) <=> (int) ($right['row_number'] ?? 0);
        });

        $selected = $candidates[0];
        $warnings = [];
        if ((int) ($selected['score'] ?? 0) < 2) {
            $warnings[] = 'Intestazione colonne rilevata con confidenza bassa. Controlla con attenzione il mapping prima di confermare l’importazione.';
        }

        return [
            'row_number' => (int) ($selected['row_number'] ?? 1),
            'cells' => (array) ($selected['cells'] ?? []),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int, string> $cells
     */
    private function scoreHeaderCandidate(array $cells): int
    {
        $aliasMap = $this->normalizedAliasMap();
        $score = 0;
        $matchedFields = [];

        foreach ($cells as $value) {
            $normalized = $this->normalizeHeaderLabel((string) $value);
            if ($normalized === '') {
                continue;
            }

            if (isset($aliasMap[$normalized]) && !isset($matchedFields[$aliasMap[$normalized]])) {
                $score += 2;
                $matchedFields[$aliasMap[$normalized]] = true;
                continue;
            }

            if (preg_match('/^[a-z0-9 ]{3,}$/', $normalized)) {
                $score++;
            }
        }

        return $score;
    }

    /**
     * @param array<int, string> $cells
     */
    private function countFilledCells(array $cells): int
    {
        $count = 0;
        foreach ($cells as $value) {
            if ($this->hasValue($value)) {
                $count++;
            }
        }

        return $count;
    }

    private function suggestFieldForHeader(string $normalizedHeader): string
    {
        return $this->normalizedAliasMap()[$normalizedHeader] ?? '';
    }

    /**
     * @return array<string, string>
     */
    private function normalizedAliasMap(): array
    {
        $map = [];
        foreach ($this->getTargetFieldDefinitions() as $fieldKey => $definition) {
            foreach ((array) ($definition['normalized_aliases'] ?? []) as $alias) {
                $alias = trim((string) $alias);
                if ($alias !== '' && !isset($map[$alias])) {
                    $map[$alias] = $fieldKey;
                }
            }
        }

        return $map;
    }

    private function normalizeHeaderLabel(string $value): string
    {
        $value = $this->normalizeCellText($value);
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($transliterated) && trim($transliterated) !== '') {
                $value = $transliterated;
            }
        }

        $value = str_replace(['°', 'º'], 'o', $value);
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeCellText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = str_replace("\0", '', $value);

        return $value;
    }

    /**
     * @param array<int, string> $cells
     * @param array<int, string> $mapping
     * @return array<string, mixed>
     */
    private function buildRowPayload(array $cells, array $mapping): array
    {
        $definitions = $this->getTargetFieldDefinitions();
        $payload = [];

        foreach ($mapping as $columnIndex => $fieldKey) {
            $fieldKey = trim((string) $fieldKey);
            if ($fieldKey === '' || !isset($definitions[$fieldKey])) {
                continue;
            }

            $rawValue = (string) ($cells[(int) $columnIndex] ?? '');
            if (!$this->hasValue($rawValue)) {
                continue;
            }

            $payloadKey = (string) ($definitions[$fieldKey]['payload_key'] ?? $fieldKey);
            $normalizedValue = $this->normalizeImportedFieldValue($payloadKey, $rawValue);

            if ($normalizedValue === null || $normalizedValue === '') {
                continue;
            }

            $payload[$payloadKey] = $normalizedValue;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $existingRow
     * @return array<string, mixed>
     */
    private function mergeExistingIdentityFields(array $payload, array $existingRow): array
    {
        if (!$this->hasValue($payload['cognome'] ?? null) && $this->hasValue($existingRow['cognome'] ?? null)) {
            $payload['cognome'] = trim((string) $existingRow['cognome']);
        }

        if (!$this->hasValue($payload['nome'] ?? null) && $this->hasValue($existingRow['nome'] ?? null)) {
            $payload['nome'] = trim((string) $existingRow['nome']);
        }

        if (
            !$this->hasValue($payload['denominazione'] ?? null)
            && !$this->hasValue($payload['cognome'] ?? null)
            && !$this->hasValue($payload['nome'] ?? null)
            && $this->hasValue($existingRow['denominazione'] ?? null)
        ) {
            $payload['denominazione'] = trim((string) $existingRow['denominazione']);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasValidIdentityPayload(array $payload): bool
    {
        if ($this->hasValue($payload['denominazione'] ?? null)) {
            return true;
        }

        return $this->hasValue($payload['cognome'] ?? null)
            && $this->hasValue($payload['nome'] ?? null);
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     */
    private function appendImportError(array &$errors, int $rowNumber, string $message): void
    {
        if (count($errors) >= self::MAX_REPORTED_ERRORS) {
            return;
        }

        $errors[] = [
            'row_number' => $rowNumber,
            'message' => trim($message) !== '' ? trim($message) : 'Errore non specificato durante l’importazione.',
        ];
    }

    /**
     * @return int|string|null
     */
    private function normalizeImportedFieldValue(string $payloadKey, string $rawValue)
    {
        $value = trim($this->normalizeCellText($rawValue));
        if ($value === '') {
            return null;
        }

        switch ($payloadKey) {
            case 'cod_fis':
            case 'codice_fiscale':
                $value = strtoupper($value);
                return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';

            case 'partita_iva':
                $value = strtoupper($value);
                return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';

            case 'email':
            case 'email_pec':
                return mb_strtolower($value, 'UTF-8');

            case 'provincia':
            case 'provincia_nascita':
            case 'provincia_secondaria':
            case 'residenza_provincia':
                return strtoupper($value);

            case 'cap':
            case 'cap_secondario':
            case 'residenza_cap':
                return $this->normalizePostalCode($value);

            case 'data_nascita':
                return $this->normalizeImportedDate($value);

            case 'cliente_attivo':
            case 'iva_differita':
            case 'bloccato':
            case 'appointment_reminder_sms_enabled':
                return $this->normalizeBooleanValue($value);

            default:
                return $value;
        }
    }

    private function normalizePostalCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (ctype_digit($value) && strlen($value) < 5) {
            return str_pad($value, 5, '0', STR_PAD_LEFT);
        }

        return strtoupper($value);
    }

    private function normalizeImportedDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial > 0) {
                $days = (int) floor($serial);
                $base = new \DateTimeImmutable('1899-12-30');
                return $base->modify('+' . $days . ' days')->format('Y-m-d');
            }
        }

        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d', 'm/d/Y'];
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof \DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }

        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return $value;
    }

    private function normalizeBooleanValue(string $value): int
    {
        $normalized = $this->normalizeHeaderLabel($value);
        if ($normalized === '') {
            return 0;
        }

        $truthy = ['1', 'si', 's i', 's', 'yes', 'true', 'vero', 'attivo', 'x'];
        if (in_array($normalized, $truthy, true)) {
            return 1;
        }

        if (is_numeric($normalized)) {
            return (float) $normalized > 0 ? 1 : 0;
        }

        return 0;
    }

    private function normalizePreviewValue(string $value): string
    {
        $value = trim($this->normalizeCellText($value));
        return mb_strlen($value, 'UTF-8') > 120
            ? mb_substr($value, 0, 117, 'UTF-8') . '...'
            : $value;
    }

    /**
     * @param array<int, string> $cells
     */
    private function rowHasVisibleValues(array $cells): bool
    {
        foreach ($cells as $value) {
            if ($this->hasValue($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private function hasValue($value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_int($value) || is_float($value)) {
            return true;
        }

        return trim((string) $value) !== '';
    }

    private function columnIndexFromReference(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)\d+$/i', $reference, $matches)) {
            return 0;
        }

        $letters = strtoupper((string) ($matches[1] ?? ''));
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index;
    }

    private function columnIndexToLetters(int $index): string
    {
        $letters = '';
        $index = max(1, $index);

        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)) . $letters;
            $index = (int) floor($index / 26);
        }

        return $letters;
    }

    private function xmlChildren(SimpleXMLElement $element): SimpleXMLElement
    {
        $children = $element->children();
        if (count($children) > 0) {
            return $children;
        }

        $namespaces = $element->getNamespaces(true);
        if (isset($namespaces[''])) {
            return $element->children($namespaces['']);
        }

        $firstNamespace = reset($namespaces);
        if (is_string($firstNamespace) && $firstNamespace !== '') {
            return $element->children($firstNamespace);
        }

        return $children;
    }
}
