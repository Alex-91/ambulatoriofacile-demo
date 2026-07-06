<?php

namespace App\Services;

use App\Config\TsBilling;
use App\Models\TsDocumentModel;

class TsDispatchService
{
    private TsDocumentModel $documents;
    private TsProfileService $profiles;
    private TsDocumentValidationService $validation;
    private TsPayloadBuilderService $payloadBuilder;
    private TsSoapClientFactory $soapFactory;
    private TsStorageService $storage;
    private TsAuditService $audit;
    private TsSupportLogService $supportLogs;
    private TsTenantDatabaseContextService $tenantDbContext;
    private TsBilling $config;

    public function __construct(
        ?TsDocumentModel $documents = null,
        ?TsProfileService $profiles = null,
        ?TsDocumentValidationService $validation = null,
        ?TsPayloadBuilderService $payloadBuilder = null,
        ?TsSoapClientFactory $soapFactory = null,
        ?TsStorageService $storage = null,
        ?TsAuditService $audit = null,
        ?TsSupportLogService $supportLogs = null,
        ?TsTenantDatabaseContextService $tenantDbContext = null,
        ?TsBilling $config = null
    ) {
        $this->documents = $documents ?? new TsDocumentModel();
        $this->profiles = $profiles ?? new TsProfileService();
        $this->validation = $validation ?? new TsDocumentValidationService();
        $this->payloadBuilder = $payloadBuilder ?? new TsPayloadBuilderService();
        $this->soapFactory = $soapFactory ?? new TsSoapClientFactory();
        $this->storage = $storage ?? new TsStorageService();
        $this->audit = $audit ?? new TsAuditService();
        $this->supportLogs = $supportLogs ?? new TsSupportLogService($this->storage);
        $this->tenantDbContext = $tenantDbContext ?? new TsTenantDatabaseContextService();
        $this->config = $config ?? config(TsBilling::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function dispatchDocument(int $tenantId, int $documentId, int $userId = 0): array
    {
        if ($tenantId <= 0 || $documentId <= 0) {
            throw new \InvalidArgumentException('Documento TS non valido per il tentativo di invio.');
        }

        $context = $this->tenantDbContext->resolveTenantContext($tenantId);
        /** @var TsDocumentModel $documents */
        $documents = $context['documents'];
        /** @var TsAuditService $audit */
        $audit = $context['audit'];

        $document = $documents->find($documentId);
        if (!is_array($document)) {
            throw new \RuntimeException('Documento TS non trovato.');
        }

        $operation = $this->resolveDocumentOperation($document);

        $supportLog = $this->supportLogs->startOperation($tenantId, 'document_dispatch', [
            'document_id' => $documentId,
            'user_id' => $userId,
            'local_state' => trim((string) ($document['local_state'] ?? 'draft')),
            'source_type' => $operation['source_type'],
            'soap_operation' => $operation['soap_operation'],
        ]);
        $supportLog->step('document_loaded', 'Documento TS caricato per il tentativo di invio.', [
            'operation' => $operation,
            'document' => $this->buildDocumentLogContext($document),
        ]);

        $localState = trim((string) ($document['local_state'] ?? 'draft'));
        if ($localState === 'sent') {
            $supportLog->step('state_blocked', 'Documento gia inviato: nuovo invio non consentito.', [
                'local_state' => $localState,
            ], 'warning');
            $supportLog->finish('blocked', 'Documento TS gia inviato: invio bloccato.', [
                'document' => $this->buildDocumentLogContext($document),
            ]);
            throw new \RuntimeException('Il documento TS risulta gia inviato.');
        }

        if ($localState === 'sending') {
            $supportLog->step('state_blocked', 'Documento gia in stato di invio.', [
                'local_state' => $localState,
            ], 'warning');
            $supportLog->finish('blocked', 'Documento TS gia in stato di invio.', [
                'document' => $this->buildDocumentLogContext($document),
            ]);
            throw new \RuntimeException('Il documento TS risulta gia in stato di invio.');
        }

        if ($localState !== 'ready') {
            $supportLog->step('state_blocked', 'Documento non nello stato Pronto.', [
                'local_state' => $localState,
            ], 'warning');
            $supportLog->finish('blocked', 'Documento TS non pronto per l invio.', [
                'document' => $this->buildDocumentLogContext($document),
            ]);
            throw new \RuntimeException('Il documento deve essere nello stato Pronto prima del tentativo di invio TS.');
        }

        $profileId = (int) ($document['id_ts_profile'] ?? 0);
        $profile = $profileId > 0
            ? $this->profiles->findProfileById($profileId, $tenantId)
            : $this->profiles->getDefaultProfileForTenant($tenantId);

        if (!is_array($profile) || (int) ($profile['id_ts_profile'] ?? 0) <= 0) {
            $supportLog->step('profile_missing', 'Profilo TS del documento non disponibile.', [], 'error');
            $supportLog->finish('error', 'Invio TS interrotto: profilo documento non disponibile.', [
                'document' => $this->buildDocumentLogContext($document),
            ]);
            throw new \RuntimeException('Profilo TS del documento non disponibile.');
        }

        if ((int) ($profile['is_enabled'] ?? 0) !== 1) {
            $supportLog->step('profile_disabled', 'Profilo TS collegato al documento non attivo.', [
                'profile_id' => (int) ($profile['id_ts_profile'] ?? 0),
            ], 'error');
            $supportLog->finish('error', 'Invio TS interrotto: profilo documento non attivo.', [
                'document' => $this->buildDocumentLogContext($document),
                'profile' => $this->buildProfileLogContext($profile),
            ]);
            throw new \RuntimeException('Il profilo TS collegato al documento non e attivo.');
        }

        $supportLog->step('profile_loaded', 'Profilo TS collegato al documento risolto.', [
            'operation' => $operation,
            'profile' => $this->buildProfileLogContext($profile),
        ]);

        $validation = $this->validation->validateDraft(
            $this->payloadBuilder->buildValidationPayload($document, $profile),
            $profile,
            false,
            (string) ($operation['source_type'] ?? 'manual')
        );

        $dispatchPayload = $this->payloadBuilder->buildDispatchPayload($document, $profile);
        $storedSnapshot = $this->payloadBuilder->buildStoredSnapshot($dispatchPayload);
        $supportLog->step('payload_built', 'Payload TS costruito e snapshot sanificato disponibile.', [
            'operation' => $operation,
            'snapshot' => $storedSnapshot,
        ]);

        if (empty($validation['valid'])) {
            $supportLog->step('validation_failed', 'Validazione locale non superata prima dell invio TS.', [
                'validation' => $validation,
            ], 'warning');
            $validationJson = $this->encodeJson([
                'valid' => false,
                'errors' => array_values((array) ($validation['errors'] ?? [])),
                'warnings' => array_values((array) ($validation['warnings'] ?? [])),
                'validated_at' => date('Y-m-d H:i:s'),
                'requested_mode' => 'send',
            ]);
            $message = implode(' ', (array) ($validation['errors'] ?? []));

            $documents->update($documentId, [
                'local_state' => 'to_validate',
                'validation_json' => $validationJson,
                'request_payload_json' => $this->encodeJson($storedSnapshot),
                'last_error_code' => 'LOCAL_VALIDATION',
                'last_error_message' => $message !== '' ? $message : 'Validazione locale TS non superata.',
                'updated_by' => $userId > 0 ? $userId : null,
            ]);

            $audit->record(
                $documentId,
                'dispatch_blocked',
                'Tentativo invio TS bloccato dalla validazione locale.',
                'warning',
                [
                    'trace_id' => $supportLog->getTraceId(),
                    'errors' => array_values((array) ($validation['errors'] ?? [])),
                    'warnings' => array_values((array) ($validation['warnings'] ?? [])),
                ],
                $userId
            );

            $supportReference = $supportLog->finish('blocked', 'Tentativo invio TS bloccato dalla validazione locale.', [
                'validation' => $validation,
                'document' => $this->buildDocumentLogContext($documents->find($documentId) ?? $document),
            ]);

            return [
                'status' => 'blocked',
                'message' => 'Il documento non supera piu la validazione locale.',
                'document' => $documents->find($documentId),
                'validation' => $validation,
                'support_log' => $supportReference,
            ];
        }

        $supportLog->step('validation_passed', 'Validazione locale superata, procedo con il canale SOAP TS.', [
            'operation' => $operation,
            'warnings' => array_values((array) ($validation['warnings'] ?? [])),
        ]);

        $documents->update($documentId, [
            'local_state' => 'sending',
            'validation_json' => $this->encodeJson([
                'valid' => true,
                'errors' => [],
                'warnings' => array_values((array) ($validation['warnings'] ?? [])),
                'validated_at' => date('Y-m-d H:i:s'),
                'requested_mode' => 'send',
            ]),
            'request_payload_json' => $this->encodeJson($storedSnapshot),
            'last_error_code' => null,
            'last_error_message' => null,
            'updated_by' => $userId > 0 ? $userId : null,
        ]);

        $audit->record(
            $documentId,
            'dispatch_started',
            (string) ($operation['started_message'] ?? 'Tentativo invio TS avviato.'),
            'info',
            [
                'trace_id' => $supportLog->getTraceId(),
                'environment' => (string) ($profile['environment'] ?? 'test'),
                'operation' => $operation['soap_operation'] ?? '',
            ],
            $userId
        );

        $transport = [];
        $client = null;

        try {
            $transport = $this->soapFactory->describeDocumentContract($profile);
            $transport['probed_at'] = date('Y-m-d H:i:s');
            $supportLog->step('contract_described', 'Contratto documento TS risolto.', [
                'transport' => $transport,
            ]);

            if ($this->config->storeDebugArtifacts) {
                $transport['artifact_path'] = $this->storage->saveJsonArtifact(
                    $tenantId,
                    'payloads',
                    'ts-document-' . $documentId . '-request',
                    $storedSnapshot
                );
                $supportLog->step('request_artifact_saved', 'Artifact snapshot richiesta TS salvato.', [
                    'artifact_path' => $transport['artifact_path'],
                ]);
            }

            if (trim((string) ($operation['soap_operation'] ?? '')) === '') {
                throw new \RuntimeException(
                    'Preflight SOAP completato: client e WSDL risultano caricabili, ma il nome operazione ufficiale TS non e ancora configurato.'
                );
            }

            $client = $this->soapFactory->createForDocumentProfile($profile);
            $supportLog->step('soap_client_created', 'Client SOAP TS inizializzato correttamente.', [
                'operation' => $operation['soap_operation'],
                'request_root' => $operation['request_root'],
            ]);
            $soapRequest = $this->payloadBuilder->buildSoapRequestPayload(
                $dispatchPayload,
                (string) ($operation['request_root'] ?? ''),
                (string) ($operation['source_type'] ?? 'manual')
            );
            $soapResponse = $client->__soapCall((string) ($operation['soap_operation'] ?? ''), [$soapRequest]);
            $normalizedResponse = $this->normalizeSoapValue($soapResponse);
            $responseNode = $this->resolveDocumentResponseNode($normalizedResponse);
            $responseMessages = $this->extractResponseMessages($responseNode);
            $responseMessage = $this->extractResponseMessage($responseNode);
            $protocol = $this->extractResponseProtocol($responseNode);
            $soapAccepted = $this->evaluateSoapSuccess($responseNode);
            $outcome = $this->extractOutcome($responseNode);
            $soapDebug = $this->extractSoapDebug($client);
            $supportLog->step('soap_response_received', 'Risposta SOAP TS ricevuta e normalizzata.', [
                'outcome' => $outcome,
                'protocol' => $protocol,
                'messages' => $responseMessages,
                'soap_debug' => $soapDebug,
            ]);

            $responsePayload = [
                'status' => $soapAccepted ? 'ok' : 'error',
                'message' => $responseMessage !== '' ? $responseMessage : ($soapAccepted
                    ? 'Invio SOAP completato con risposta riconosciuta dal tracciato TS.'
                    : 'Risposta SOAP TS ricevuta ma classificata come non valida dal parser.'),
                'esito_chiamata' => $outcome,
                'protocollo' => $protocol,
                'operation' => $operation,
                'messages' => $responseMessages,
                'transport' => $transport,
                'response' => $normalizedResponse,
                'received_at' => date('Y-m-d H:i:s'),
            ];

            if ($this->config->storeDebugArtifacts) {
                $responsePayload['artifact_path'] = $this->storage->saveJsonArtifact(
                    $tenantId,
                    'responses',
                    'ts-document-' . $documentId . '-response',
                    $responsePayload
                );
                $supportLog->step('response_artifact_saved', 'Artifact risposta TS salvato.', [
                    'artifact_path' => $responsePayload['artifact_path'],
                ]);
            }

            if (!$soapAccepted) {
                throw new \RuntimeException($responsePayload['message']);
            }

            $supportReference = $supportLog->finish('success', 'Invio TS completato con esito positivo.', [
                'transport' => $transport,
                'response' => $normalizedResponse,
                'protocol' => $protocol,
                'outcome' => $outcome,
                'soap_debug' => $soapDebug,
            ]);
            $responsePayload['support_log'] = $supportReference;

            $documents->update($documentId, [
                'local_state' => 'sent',
                'ts_state' => 'accepted',
                'response_payload_json' => $this->encodeJson($responsePayload),
                'ts_protocol' => $protocol !== null ? (string) $protocol : null,
                'ts_sent_at' => date('Y-m-d H:i:s'),
                'last_error_code' => null,
                'last_error_message' => null,
                'updated_by' => $userId > 0 ? $userId : null,
            ]);

            $this->syncParentDocumentAfterSuccessfulDispatch($documents, $audit, $document, $operation, $protocol, $userId);

            $audit->record(
                $documentId,
                (string) ($operation['success_event'] ?? 'dispatch_sent'),
                (string) ($operation['success_audit_message'] ?? 'Invio TS completato con esito positivo.'),
                'info',
                [
                    'trace_id' => $supportReference['trace_id'] ?? $supportLog->getTraceId(),
                    'protocol' => $protocol,
                    'operation' => $operation['soap_operation'] ?? '',
                ],
                $userId
            );

            return [
                'status' => 'ok',
                'message' => (string) ($operation['success_message'] ?? 'Documento TS inviato con successo.'),
                'document' => $documents->find($documentId),
                'validation' => $validation,
                'transport' => $transport,
                'support_log' => $supportReference,
            ];
        } catch (\Throwable $e) {
            $soapDebug = $this->extractSoapDebug($client);
            $responsePayload = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'transport' => $transport,
                'failed_at' => date('Y-m-d H:i:s'),
            ];
            $supportLog->step('dispatch_exception', 'Tentativo invio TS terminato con eccezione.', [
                'message' => $e->getMessage(),
                'transport' => $transport,
                'soap_debug' => $soapDebug,
            ], 'error');

            if ($this->config->storeDebugArtifacts) {
                try {
                    $responsePayload['artifact_path'] = $this->storage->saveJsonArtifact(
                        $tenantId,
                        'responses',
                        'ts-document-' . $documentId . '-response',
                        $responsePayload
                    );
                } catch (\Throwable $storageError) {
                    $responsePayload['artifact_error'] = $storageError->getMessage();
                }
            }

            $supportReference = $supportLog->finish('error', 'Tentativo invio TS fallito.', [
                'transport' => $transport,
                'soap_debug' => $soapDebug,
                'validation' => $validation,
            ], $e);
            $responsePayload['support_log'] = $supportReference;

            $documents->update($documentId, [
                'local_state' => 'ready',
                'response_payload_json' => $this->encodeJson($responsePayload),
                'last_error_code' => 'TS_SEND_FAILED',
                'last_error_message' => $e->getMessage(),
                'updated_by' => $userId > 0 ? $userId : null,
            ]);

            $audit->record(
                $documentId,
                'dispatch_failed',
                'Tentativo invio TS terminato con esito tecnico negativo.',
                'error',
                [
                    'trace_id' => $supportReference['trace_id'] ?? $supportLog->getTraceId(),
                    'message' => $e->getMessage(),
                    'transport' => $transport,
                ],
                $userId
            );

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'document' => $documents->find($documentId),
                'validation' => $validation,
                'transport' => $transport,
                'debug' => $soapDebug,
                'support_log' => $supportReference,
            ];
        }
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, string>
     */
    private function resolveDocumentOperation(array $document): array
    {
        $sourceType = trim((string) ($document['source_type'] ?? 'manual'));

        return match ($sourceType) {
            'ts_variation' => [
                'source_type' => 'ts_variation',
                'request_root' => 'variazioneDocumentoSpesaRequest',
                'soap_operation' => 'Variazione',
                'started_message' => 'Tentativo variazione TS avviato.',
                'success_event' => 'variation_sent',
                'success_audit_message' => 'Variazione TS completata con esito positivo.',
                'success_message' => 'Variazione TS inviata correttamente.',
            ],
            'ts_cancellation' => [
                'source_type' => 'ts_cancellation',
                'request_root' => 'cancellazioneDocumentoSpesaRequest',
                'soap_operation' => 'Cancellazione',
                'started_message' => 'Tentativo cancellazione TS avviato.',
                'success_event' => 'cancellation_sent',
                'success_audit_message' => 'Cancellazione TS completata con esito positivo.',
                'success_message' => 'Cancellazione TS inviata correttamente.',
            ],
            default => [
                'source_type' => 'manual',
                'request_root' => $this->config->documentRequestRoot,
                'soap_operation' => $this->config->documentOperationName,
                'started_message' => 'Tentativo invio TS avviato.',
                'success_event' => 'dispatch_sent',
                'success_audit_message' => 'Invio TS completato con esito positivo.',
                'success_message' => 'Documento TS inviato correttamente.',
            ],
        };
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, string> $operation
     */
    private function syncParentDocumentAfterSuccessfulDispatch(
        TsDocumentModel $documents,
        TsAuditService $audit,
        array $document,
        array $operation,
        ?string $protocol,
        int $userId
    ): void {
        $sourceType = trim((string) ($operation['source_type'] ?? 'manual'));
        $parentId = (int) ($document['source_ref_id'] ?? 0);
        if ($sourceType === 'manual' || $parentId <= 0) {
            return;
        }

        $parent = $documents->find($parentId);
        if (!is_array($parent)) {
            return;
        }

        if ($sourceType === 'ts_variation') {
            $documents->update($parentId, [
                'ts_state' => 'varied',
                'updated_by' => $userId > 0 ? $userId : null,
            ]);
            $audit->record(
                $parentId,
                'variation_applied',
                'Una variazione TS collegata al documento e stata inviata con successo.',
                'info',
                [
                    'child_document_id' => (int) ($document['id_ts_document'] ?? 0),
                    'protocol' => $protocol,
                ],
                $userId
            );

            return;
        }

        if ($sourceType === 'ts_cancellation') {
            $documents->update($parentId, [
                'ts_state' => 'cancelled',
                'updated_by' => $userId > 0 ? $userId : null,
            ]);
            $audit->record(
                $parentId,
                'cancellation_applied',
                'Una cancellazione TS collegata al documento e stata inviata con successo.',
                'warning',
                [
                    'child_document_id' => (int) ($document['id_ts_document'] ?? 0),
                    'protocol' => $protocol,
                ],
                $userId
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): ?string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
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
            return $this->normalizeSoapValue(get_object_vars($value));
        }

        return $value;
    }

    /**
     * @param mixed $payload
     * @return mixed
     */
    private function extractPathValue($payload, string $path)
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('.', $path), static fn(string $segment): bool => trim($segment) !== ''));
        $cursor = $payload;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param mixed $response
     */
    private function evaluateSoapSuccess($response): bool
    {
        if ($this->config->assumeSuccessOnSoapReturn) {
            return true;
        }

        $outcome = $this->extractOutcome($response);
        $normalizedOutcome = strtolower(trim((string) $outcome));
        if ($normalizedOutcome !== '' && in_array($normalizedOutcome, $this->config->documentResponseOkValues, true)) {
            return true;
        }

        if ($normalizedOutcome !== '' && in_array($normalizedOutcome, ['ko', 'error', 'errore', 'false', 'no'], true)) {
            return false;
        }

        $protocol = trim((string) ($this->extractResponseProtocol($response) ?? ''));
        if ($protocol === '') {
            return false;
        }

        return !$this->containsBlockingMessages($this->extractResponseMessages($response));
    }

    /**
     * @param mixed $response
     */
    private function extractResponseMessage($response): string
    {
        $messages = $this->extractResponseMessages($response);
        if ($messages !== []) {
            $lines = [];
            foreach ($messages as $message) {
                $description = trim((string) ($message['descrizione'] ?? ''));
                $code = trim((string) ($message['codice'] ?? ''));
                $type = strtoupper(trim((string) ($message['tipo'] ?? '')));
                $line = $description;

                if ($code !== '') {
                    $line = '[' . $code . '] ' . $line;
                }

                if ($type !== '') {
                    $line = ($line !== '' ? $line . ' ' : '') . '(' . $type . ')';
                }

                if ($line !== '') {
                    $lines[] = trim($line);
                }
            }

            if ($lines !== []) {
                return implode(' ', $lines);
            }
        }

        $outcome = trim((string) $this->extractOutcome($response));
        $protocol = trim((string) ($this->extractResponseProtocol($response) ?? ''));
        if ($outcome !== '' && $protocol !== '') {
            return 'Esito TS ' . $outcome . ' con protocollo ' . $protocol . '.';
        }

        if ($outcome !== '') {
            return 'Esito TS ' . $outcome . '.';
        }

        return '';
    }

    /**
     * @param mixed $response
     * @return array<string, mixed>
     */
    private function resolveDocumentResponseNode($response): array
    {
        if (!is_array($response)) {
            return [];
        }

        if ($this->looksLikeDocumentResponseNode($response)) {
            return $response;
        }

        foreach ($response as $value) {
            if (!is_array($value)) {
                continue;
            }

            if ($this->looksLikeDocumentResponseNode($value)) {
                return $value;
            }
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function looksLikeDocumentResponseNode(array $payload): bool
    {
        foreach (['esitoChiamata', 'protocollo', 'listaMessaggi'] as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $response
     */
    private function extractOutcome($response): ?string
    {
        $outcome = $this->extractPathValue($response, $this->config->documentResponseOutcomePath);
        if ($outcome === null) {
            return null;
        }

        $outcome = trim((string) $outcome);

        return $outcome !== '' ? $outcome : null;
    }

    /**
     * @param mixed $response
     */
    private function extractResponseProtocol($response): ?string
    {
        $protocol = $this->extractPathValue($response, $this->config->documentResponseProtocolPath);
        if ($protocol === null) {
            return null;
        }

        $protocol = trim((string) $protocol);

        return $protocol !== '' ? $protocol : null;
    }

    /**
     * @param mixed $response
     * @return array<int, array<string, string>>
     */
    private function extractResponseMessages($response): array
    {
        $messagesNode = $this->extractPathValue($response, $this->config->documentResponseMessagePath);
        if ($messagesNode === null) {
            return [];
        }

        if (is_array($messagesNode) && array_key_exists('codice', $messagesNode)) {
            $messagesNode = [$messagesNode];
        }

        if (!is_array($messagesNode)) {
            return [];
        }

        $messages = [];
        foreach ($messagesNode as $message) {
            if (!is_array($message)) {
                continue;
            }

            $normalized = [
                'codice' => trim((string) ($message['codice'] ?? '')),
                'descrizione' => trim((string) ($message['descrizione'] ?? '')),
                'tipo' => trim((string) ($message['tipo'] ?? '')),
            ];

            if ($normalized['codice'] === '' && $normalized['descrizione'] === '' && $normalized['tipo'] === '') {
                continue;
            }

            $messages[] = $normalized;
        }

        return $messages;
    }

    /**
     * @param array<int, array<string, string>> $messages
     */
    private function containsBlockingMessages(array $messages): bool
    {
        foreach ($messages as $message) {
            $type = strtolower(trim((string) ($message['tipo'] ?? '')));
            if (in_array($type, ['e', 'error', 'errore', 'ko', 'fatal'], true)) {
                return true;
            }
        }

        return false;
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

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function buildDocumentLogContext(array $document): array
    {
        return [
            'id_ts_document' => (int) ($document['id_ts_document'] ?? 0),
            'source_type' => trim((string) ($document['source_type'] ?? 'manual')),
            'source_ref_id' => (int) ($document['source_ref_id'] ?? 0),
            'document_number' => trim((string) ($document['document_number'] ?? '')),
            'document_device' => (int) ($document['document_device'] ?? 0),
            'issue_date' => trim((string) ($document['issue_date'] ?? '')),
            'payment_date' => trim((string) ($document['payment_date'] ?? '')),
            'document_type' => trim((string) ($document['document_type'] ?? '')),
            'expense_type_code' => trim((string) ($document['expense_type_code'] ?? '')),
            'amount_total' => (float) ($document['amount_total'] ?? 0),
            'local_state' => trim((string) ($document['local_state'] ?? '')),
            'ts_state' => trim((string) ($document['ts_state'] ?? '')),
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
            'id_tenant' => (int) ($profile['id_tenant'] ?? 0),
            'environment' => trim((string) ($profile['environment'] ?? '')),
            'sender_type' => trim((string) ($profile['sender_type'] ?? '')),
            'owner_piva' => trim((string) ($profile['owner_piva'] ?? '')),
            'region_code' => trim((string) ($profile['region_code'] ?? '')),
            'asl_code' => trim((string) ($profile['asl_code'] ?? '')),
            'ssa_code' => trim((string) ($profile['ssa_code'] ?? '')),
            'is_enabled' => (int) ($profile['is_enabled'] ?? 0),
        ];
    }
}
