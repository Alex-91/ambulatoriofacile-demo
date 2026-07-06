<?php

namespace App\Services;

use App\Models\TsDocumentModel;
use App\Models\TsDocumentReceiptModel;

class TsReceiptService
{
    private TsDocumentModel $documents;
    private TsDocumentReceiptModel $receipts;
    private TsProfileService $profiles;
    private TsSoapClientFactory $soapFactory;
    private TsStorageService $storage;
    private TsAuditService $audit;
    private TsCryptoService $crypto;
    private TsSecretsService $secrets;
    private TsSupportLogService $supportLogs;
    private TsTenantDatabaseContextService $tenantDbContext;

    public function __construct(
        ?TsDocumentModel $documents = null,
        ?TsDocumentReceiptModel $receipts = null,
        ?TsProfileService $profiles = null,
        ?TsSoapClientFactory $soapFactory = null,
        ?TsStorageService $storage = null,
        ?TsAuditService $audit = null,
        ?TsCryptoService $crypto = null,
        ?TsSecretsService $secrets = null,
        ?TsSupportLogService $supportLogs = null
    ) {
        $this->documents = $documents ?? new TsDocumentModel();
        $this->receipts = $receipts ?? new TsDocumentReceiptModel();
        $this->profiles = $profiles ?? new TsProfileService();
        $this->soapFactory = $soapFactory ?? new TsSoapClientFactory();
        $this->storage = $storage ?? new TsStorageService();
        $this->audit = $audit ?? new TsAuditService();
        $this->crypto = $crypto ?? new TsCryptoService();
        $this->secrets = $secrets ?? new TsSecretsService();
        $this->supportLogs = $supportLogs ?? new TsSupportLogService($this->storage);
        $this->tenantDbContext = new TsTenantDatabaseContextService();
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchReceiptPdfForDocument(int $tenantId, int $documentId, int $userId = 0): array
    {
        if ($tenantId <= 0 || $documentId <= 0) {
            throw new \InvalidArgumentException('Documento TS non valido per il recupero ricevuta.');
        }

        $context = $this->tenantDbContext->resolveTenantContext($tenantId);
        /** @var TsDocumentModel $documents */
        $documents = $context['documents'];
        /** @var TsDocumentReceiptModel $receipts */
        $receipts = $context['receipts'];
        /** @var TsAuditService $audit */
        $audit = $context['audit'];

        $document = $documents->find($documentId);
        if (!is_array($document)) {
            throw new \RuntimeException('Documento TS non trovato.');
        }

        $supportLog = $this->supportLogs->startOperation($tenantId, 'receipt_fetch', [
            'document_id' => $documentId,
            'user_id' => $userId,
        ]);
        $supportLog->step('document_loaded', 'Documento TS caricato per il recupero ricevuta.', [
            'document' => $this->buildDocumentLogContext($document),
        ]);

        $protocol = trim((string) ($document['ts_protocol'] ?? ''));
        if ($protocol === '') {
            $supportLog->step('protocol_missing', 'Documento senza protocollo TS disponibile.', [
                'document' => $this->buildDocumentLogContext($document),
            ], 'warning');
            $supportLog->finish('blocked', 'Recupero ricevuta bloccato: protocollo TS assente.', [
                'document' => $this->buildDocumentLogContext($document),
            ]);
            throw new \RuntimeException('Il documento non ha ancora un protocollo TS disponibile.');
        }

        $existingReceipt = $receipts->findLatestForDocumentAndType($documentId, 'pdf', $protocol);
        if (is_array($existingReceipt) && is_file((string) ($existingReceipt['storage_path'] ?? ''))) {
            $supportReference = $supportLog->finish('success', 'Ricevuta TS gia disponibile in cache locale.', [
                'receipt' => $this->buildReceiptLogContext($existingReceipt),
                'cached' => true,
            ]);
            return [
                'status' => 'cached',
                'message' => 'Ricevuta TS gia disponibile localmente.',
                'receipt' => $existingReceipt,
                'document' => $document,
                'support_log' => $supportReference,
            ];
        }

        $profileId = (int) ($document['id_ts_profile'] ?? 0);
        $profile = $profileId > 0
            ? $this->profiles->findProfileById($profileId, $tenantId)
            : $this->profiles->getDefaultProfileForTenant($tenantId);

        if (!is_array($profile) || (int) ($profile['id_ts_profile'] ?? 0) <= 0) {
            $supportLog->step('profile_missing', 'Profilo TS del documento non disponibile.', [], 'error');
            $supportLog->finish('error', 'Recupero ricevuta interrotto: profilo documento non disponibile.', [
                'document' => $this->buildDocumentLogContext($document),
            ]);
            throw new \RuntimeException('Profilo TS del documento non disponibile.');
        }

        if ((int) ($profile['is_enabled'] ?? 0) !== 1) {
            $supportLog->step('profile_disabled', 'Profilo TS collegato al documento non attivo.', [
                'profile_id' => (int) ($profile['id_ts_profile'] ?? 0),
            ], 'error');
            $supportLog->finish('error', 'Recupero ricevuta interrotto: profilo documento non attivo.', [
                'document' => $this->buildDocumentLogContext($document),
                'profile' => $this->buildProfileLogContext($profile),
            ]);
            throw new \RuntimeException('Il profilo TS collegato al documento non e attivo.');
        }

        $supportLog->step('profile_loaded', 'Profilo TS collegato al documento risolto per il recupero ricevuta.', [
            'profile' => $this->buildProfileLogContext($profile),
        ]);

        $pincode = $this->safeDecrypt((string) ($profile['pincode_enc'] ?? ''));
        if ($pincode === '') {
            $supportLog->step('pincode_missing', 'PINCODE TS non disponibile per il recupero ricevuta.', [
                'profile_id' => (int) ($profile['id_ts_profile'] ?? 0),
            ], 'error');
            $supportLog->finish('error', 'Recupero ricevuta interrotto: PINCODE TS assente.', [
                'document' => $this->buildDocumentLogContext($document),
                'profile' => $this->buildProfileLogContext($profile),
            ]);
            throw new \RuntimeException('PINCODE TS non disponibile per il recupero ricevuta.');
        }

        $client = $this->soapFactory->createForReceiptsProfile($profile);
        $supportLog->step('soap_client_created', 'Client SOAP ricevute TS inizializzato correttamente.', [
            'transport' => $this->soapFactory->describeReceiptsContract($profile),
        ]);
        $soapRequest = [
            'DatiInputRichiesta' => [
                'pinCode' => $this->crypto->encryptRequired($pincode),
                'protocollo' => $protocol,
            ],
        ];

        try {
            $soapResponse = $client->__soapCall('RicevutaPdf', [$soapRequest]);
            $normalizedResponse = $this->normalizeSoapValue($soapResponse);
            $output = is_array($normalizedResponse['DatiOutputRichiesta'] ?? null)
                ? $normalizedResponse['DatiOutputRichiesta']
                : [];
            $supportLog->step('soap_response_received', 'Risposta SOAP ricevute TS ricevuta e normalizzata.', [
                'output' => $output,
                'soap_debug' => $this->extractSoapDebug($client),
            ]);

            $outcome = trim((string) ($output['esitoChiamata'] ?? ''));
            if ($outcome !== '' && $outcome !== '0') {
                throw new \RuntimeException($this->buildReceiptErrorMessage($output));
            }

            $pdfPayload = $output['esitiPositivi']['dettagliEsito']['pdf'] ?? null;
            $pdfBinary = $this->normalizePdfBinary($pdfPayload);
            if ($pdfBinary === '') {
                throw new \RuntimeException('La risposta ricevute TS non contiene un PDF valido.');
            }

            $storedFile = $this->storage->saveBinaryArtifact(
                $tenantId,
                'receipts',
                'ts-receipt-' . $documentId . '-' . $protocol,
                'pdf',
                $pdfBinary
            );

            $receiptId = (int) $receipts->insert([
                'id_ts_document' => $documentId,
                'receipt_type' => 'pdf',
                'ts_protocol' => $protocol,
                'storage_path' => $storedFile['path'],
                'mime_type' => 'application/pdf',
                'file_size' => $storedFile['file_size'],
                'checksum_sha256' => $storedFile['checksum_sha256'],
            ]);

            $receipt = $receiptId > 0 ? $receipts->find($receiptId) : null;
            if (!is_array($receipt)) {
                throw new \RuntimeException('Ricevuta TS salvata ma non piu reperibile.');
            }

            $supportReference = $supportLog->finish('success', 'Ricevuta TS recuperata con successo.', [
                'receipt' => $this->buildReceiptLogContext($receipt),
                'output' => $output,
                'soap_debug' => $this->extractSoapDebug($client),
            ]);

            $audit->record(
                $documentId,
                'receipt_downloaded',
                'Ricevuta PDF TS recuperata e salvata localmente.',
                'info',
                [
                    'trace_id' => $supportReference['trace_id'] ?? $supportLog->getTraceId(),
                    'protocol' => $protocol,
                    'file_size' => (int) ($receipt['file_size'] ?? 0),
                ],
                $userId
            );

            return [
                'status' => 'ok',
                'message' => 'Ricevuta TS recuperata con successo.',
                'receipt' => $receipt,
                'document' => $document,
                'response' => $output,
                'support_log' => $supportReference,
            ];
        } catch (\Throwable $e) {
            $supportLog->step('receipt_exception', 'Recupero ricevuta TS terminato con eccezione.', [
                'protocol' => $protocol,
                'message' => $e->getMessage(),
                'soap_debug' => $this->extractSoapDebug($client),
            ], 'error');
            $supportReference = $supportLog->finish('error', 'Recupero ricevuta TS fallito.', [
                'protocol' => $protocol,
                'soap_debug' => $this->extractSoapDebug($client),
            ], $e);
            $audit->record(
                $documentId,
                'receipt_failed',
                'Recupero ricevuta TS non completato.',
                'warning',
                [
                    'trace_id' => $supportReference['trace_id'] ?? $supportLog->getTraceId(),
                    'protocol' => $protocol,
                    'message' => $e->getMessage(),
                ],
                $userId
            );

            throw $e;
        }
    }

    private function safeDecrypt(string $payload): string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return '';
        }

        try {
            return trim((string) $this->secrets->decrypt($payload));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalizeSoapValue($value)
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeSoapValue($item);
            }

            return $normalized;
        }

        if (is_object($value)) {
            $normalized = [];
            foreach (get_object_vars($value) as $key => $item) {
                $normalized[$key] = $this->normalizeSoapValue($item);
            }

            return $normalized;
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private function normalizePdfBinary($value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        if (str_starts_with($value, '%PDF')) {
            return $value;
        }

        $decoded = base64_decode($value, true);
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $output
     */
    private function buildReceiptErrorMessage(array $output): string
    {
        $messages = [];
        $negatives = $output['esitiNegativi']['dettaglioEsitoNegativo'] ?? [];

        if (is_array($negatives) && array_key_exists('codice', $negatives)) {
            $negatives = [$negatives];
        }

        foreach ((array) $negatives as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = trim((string) ($row['codice'] ?? ''));
            $description = trim((string) ($row['descrizione'] ?? ''));
            if ($code !== '' || $description !== '') {
                $messages[] = trim(($code !== '' ? '[' . $code . '] ' : '') . $description);
            }
        }

        if ($messages !== []) {
            return implode(' ', $messages);
        }

        $outcome = trim((string) ($output['esitoChiamata'] ?? ''));

        return $outcome !== ''
            ? 'Recupero ricevuta TS fallito con esito ' . $outcome . '.'
            : 'Recupero ricevuta TS non riuscito.';
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function buildDocumentLogContext(array $document): array
    {
        return [
            'id_ts_document' => (int) ($document['id_ts_document'] ?? 0),
            'document_number' => trim((string) ($document['document_number'] ?? '')),
            'document_type' => trim((string) ($document['document_type'] ?? '')),
            'expense_type_code' => trim((string) ($document['expense_type_code'] ?? '')),
            'local_state' => trim((string) ($document['local_state'] ?? '')),
            'ts_protocol' => trim((string) ($document['ts_protocol'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function buildProfileLogContext(array $profile): array
    {
        return [
            'id_ts_profile' => (int) ($profile['id_ts_profile'] ?? 0),
            'environment' => trim((string) ($profile['environment'] ?? '')),
            'sender_type' => trim((string) ($profile['sender_type'] ?? '')),
            'owner_piva' => trim((string) ($profile['owner_piva'] ?? '')),
            'region_code' => trim((string) ($profile['region_code'] ?? '')),
            'asl_code' => trim((string) ($profile['asl_code'] ?? '')),
            'ssa_code' => trim((string) ($profile['ssa_code'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    private function buildReceiptLogContext(array $receipt): array
    {
        return [
            'id_ts_receipt' => (int) ($receipt['id_ts_receipt'] ?? 0),
            'receipt_type' => trim((string) ($receipt['receipt_type'] ?? '')),
            'ts_protocol' => trim((string) ($receipt['ts_protocol'] ?? '')),
            'storage_path' => trim((string) ($receipt['storage_path'] ?? '')),
            'file_size' => (int) ($receipt['file_size'] ?? 0),
            'checksum_sha256' => trim((string) ($receipt['checksum_sha256'] ?? '')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractSoapDebug($client): array
    {
        if (!$client instanceof \SoapClient) {
            return [];
        }

        $debug = [];
        foreach ([
            'last_request_headers' => '__getLastRequestHeaders',
            'last_request' => '__getLastRequest',
            'last_response_headers' => '__getLastResponseHeaders',
            'last_response' => '__getLastResponse',
        ] as $key => $method) {
            if (!method_exists($client, $method)) {
                continue;
            }

            try {
                $value = $client->{$method}();
            } catch (\Throwable $e) {
                $value = null;
            }

            if (is_string($value) && trim($value) !== '') {
                $debug[$key] = $value;
            }
        }

        return $debug;
    }
}
