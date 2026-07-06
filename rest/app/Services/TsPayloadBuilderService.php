<?php

namespace App\Services;

class TsPayloadBuilderService
{
    private TsSecretsService $secrets;
    private TsCryptoService $crypto;

    public function __construct(?TsSecretsService $secrets = null, ?TsCryptoService $crypto = null)
    {
        $this->secrets = $secrets ?? new TsSecretsService();
        $this->crypto = $crypto ?? new TsCryptoService();
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function buildDispatchPayload(array $document, array $profile): array
    {
        $senderPiva = trim((string) ($document['sender_piva_snapshot'] ?? ($profile['owner_piva'] ?? '')));
        $senderCf = $this->safeDecrypt((string) ($document['sender_cf_snapshot_enc'] ?? ($profile['owner_cf_enc'] ?? '')));
        $patientCf = strtoupper($this->safeDecrypt((string) ($document['patient_cf_enc'] ?? '')));
        $patientLabel = $this->safeDecrypt((string) ($document['patient_label_snapshot_enc'] ?? ''));

        return [
            'profile' => [
                'id_ts_profile' => (int) ($profile['id_ts_profile'] ?? 0),
                'id_tenant' => (int) ($profile['id_tenant'] ?? 0),
                'environment' => trim((string) ($profile['environment'] ?? 'test')),
                'sender_type' => trim((string) ($document['sender_type_snapshot'] ?? ($profile['sender_type'] ?? ''))),
                'pincode' => $this->safeDecrypt((string) ($profile['pincode_enc'] ?? '')),
                'region_code' => trim((string) ($profile['region_code'] ?? '')),
                'asl_code' => trim((string) ($profile['asl_code'] ?? '')),
                'ssa_code' => trim((string) ($profile['ssa_code'] ?? '')),
            ],
            'sender' => [
                'piva' => $senderPiva,
                'cf' => $senderCf,
            ],
            'patient' => [
                'cf' => $patientCf,
                'label' => $patientLabel,
            ],
            'document' => [
                'id_ts_document' => (int) ($document['id_ts_document'] ?? 0),
                'source_type' => trim((string) ($document['source_type'] ?? 'manual')),
                'source_ref_id' => (int) ($document['source_ref_id'] ?? 0),
                'document_identifier_hash' => trim((string) ($document['document_identifier_hash'] ?? '')),
                'document_number' => trim((string) ($document['document_number'] ?? '')),
                'document_device' => (int) ($document['document_device'] ?? 0) > 0
                    ? (int) ($document['document_device'] ?? 0)
                    : null,
                'issue_date' => trim((string) ($document['issue_date'] ?? '')),
                'payment_date' => trim((string) ($document['payment_date'] ?? '')),
                'document_type' => trim((string) ($document['document_type'] ?? 'F')) !== ''
                    ? trim((string) ($document['document_type'] ?? 'F'))
                    : 'F',
                'expense_type_code' => trim((string) ($document['expense_type_code'] ?? 'SP')),
                'payment_mode' => trim((string) ($document['payment_mode'] ?? '')),
                'amount_total' => round((float) ($document['amount_total'] ?? 0), 2),
                'vat_rate' => $document['vat_rate'] !== null && $document['vat_rate'] !== ''
                    ? round((float) $document['vat_rate'], 2)
                    : null,
                'vat_nature' => strtoupper(trim((string) ($document['vat_nature'] ?? ''))),
                'opposition_flag' => (int) ($document['opposition_flag'] ?? 0) === 1 ? 1 : 0,
                'notes' => trim((string) ($document['notes'] ?? '')),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $dispatchPayload
     * @return array<string, mixed>
     */
    public function buildStoredSnapshot(array $dispatchPayload): array
    {
        $profile = is_array($dispatchPayload['profile'] ?? null) ? $dispatchPayload['profile'] : [];
        $sender = is_array($dispatchPayload['sender'] ?? null) ? $dispatchPayload['sender'] : [];
        $patient = is_array($dispatchPayload['patient'] ?? null) ? $dispatchPayload['patient'] : [];
        $document = is_array($dispatchPayload['document'] ?? null) ? $dispatchPayload['document'] : [];

        return [
            'profile' => [
                'id_ts_profile' => (int) ($profile['id_ts_profile'] ?? 0),
                'id_tenant' => (int) ($profile['id_tenant'] ?? 0),
                'environment' => trim((string) ($profile['environment'] ?? '')),
                'sender_type' => trim((string) ($profile['sender_type'] ?? '')),
                'region_code' => trim((string) ($profile['region_code'] ?? '')),
                'asl_code' => trim((string) ($profile['asl_code'] ?? '')),
                'ssa_code' => trim((string) ($profile['ssa_code'] ?? '')),
            ],
            'sender' => [
                'piva' => trim((string) ($sender['piva'] ?? '')),
                'cf_masked' => $this->maskSensitiveValue((string) ($sender['cf'] ?? '')),
            ],
            'patient' => [
                'cf_masked' => $this->maskSensitiveValue((string) ($patient['cf'] ?? '')),
                'label_present' => trim((string) ($patient['label'] ?? '')) !== '',
            ],
            'document' => $document,
        ];
    }

    /**
     * @param array<string, mixed> $dispatchPayload
     * @return array<string, mixed>
     */
    public function buildSoapRequestPayload(array $dispatchPayload, string $requestRoot = '', string $sourceType = 'manual'): array
    {
        $sourceType = trim(strtolower($sourceType));

        return match ($sourceType) {
            'ts_variation' => $this->buildVariazioneRequest($dispatchPayload),
            'ts_cancellation' => $this->buildCancellazioneRequest($dispatchPayload),
            default => $this->buildInserimentoRequest($dispatchPayload),
        };
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function buildValidationPayload(array $document, array $profile): array
    {
        $dispatchPayload = $this->buildDispatchPayload($document, $profile);
        $sender = is_array($dispatchPayload['sender'] ?? null) ? $dispatchPayload['sender'] : [];
        $patient = is_array($dispatchPayload['patient'] ?? null) ? $dispatchPayload['patient'] : [];
        $doc = is_array($dispatchPayload['document'] ?? null) ? $dispatchPayload['document'] : [];

        return [
            'sender_piva_snapshot' => trim((string) ($sender['piva'] ?? '')),
            'patient_cf_plain' => trim((string) ($patient['cf'] ?? '')),
            'document_number' => trim((string) ($doc['document_number'] ?? '')),
            'document_device' => (int) ($doc['document_device'] ?? 0),
            'issue_date' => trim((string) ($doc['issue_date'] ?? '')),
            'payment_date' => trim((string) ($doc['payment_date'] ?? '')),
            'document_type' => trim((string) ($doc['document_type'] ?? 'F')),
            'expense_type_code' => trim((string) ($doc['expense_type_code'] ?? 'SP')),
            'payment_mode' => trim((string) ($doc['payment_mode'] ?? '')),
            'amount_total' => (float) ($doc['amount_total'] ?? 0),
            'vat_rate' => $doc['vat_rate'] ?? null,
            'vat_nature' => trim((string) ($doc['vat_nature'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $dispatchPayload
     * @return array<string, mixed>
     */
    private function buildInserimentoRequest(array $dispatchPayload): array
    {
        $profile = is_array($dispatchPayload['profile'] ?? null) ? $dispatchPayload['profile'] : [];
        $sender = is_array($dispatchPayload['sender'] ?? null) ? $dispatchPayload['sender'] : [];

        $request = [
            'pincode' => $this->crypto->encryptRequired((string) ($profile['pincode'] ?? '')),
            'idInserimentoDocumentoFiscale' => $this->buildDocumentoSpesaPayload($dispatchPayload),
        ];

        $ownerPayload = $this->buildOwnerPayload($profile, $sender);
        if ($ownerPayload !== []) {
            $request['Proprietario'] = $ownerPayload;
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $dispatchPayload
     * @return array<string, mixed>
     */
    private function buildVariazioneRequest(array $dispatchPayload): array
    {
        $profile = is_array($dispatchPayload['profile'] ?? null) ? $dispatchPayload['profile'] : [];
        $sender = is_array($dispatchPayload['sender'] ?? null) ? $dispatchPayload['sender'] : [];

        $request = [
            'pincode' => $this->crypto->encryptRequired((string) ($profile['pincode'] ?? '')),
            'idVariazioneDocumentoFiscale' => $this->buildDocumentoSpesaPayload($dispatchPayload),
        ];

        $ownerPayload = $this->buildOwnerPayload($profile, $sender);
        if ($ownerPayload !== []) {
            $request['Proprietario'] = $ownerPayload;
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $dispatchPayload
     * @return array<string, mixed>
     */
    private function buildCancellazioneRequest(array $dispatchPayload): array
    {
        $profile = is_array($dispatchPayload['profile'] ?? null) ? $dispatchPayload['profile'] : [];
        $sender = is_array($dispatchPayload['sender'] ?? null) ? $dispatchPayload['sender'] : [];
        $document = is_array($dispatchPayload['document'] ?? null) ? $dispatchPayload['document'] : [];

        $issueDate = trim((string) ($document['issue_date'] ?? ''));
        $documentDevice = max(0, (int) ($document['document_device'] ?? 0));

        $request = [
            'pincode' => $this->crypto->encryptRequired((string) ($profile['pincode'] ?? '')),
            'idCancellazioneDocumentoFiscale' => [
                'pIva' => $this->asSoapString(trim((string) ($sender['piva'] ?? ''))),
                'dataEmissione' => $issueDate,
                'numDocumentoFiscale' => [
                    'dispositivo' => $documentDevice,
                    'numDocumento' => trim((string) ($document['document_number'] ?? '')),
                ],
            ],
        ];

        $ownerPayload = $this->buildOwnerPayload($profile, $sender);
        if ($ownerPayload !== []) {
            $request['Proprietario'] = $ownerPayload;
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $dispatchPayload
     * @return array<string, mixed>
     */
    private function buildDocumentoSpesaPayload(array $dispatchPayload): array
    {
        $sender = is_array($dispatchPayload['sender'] ?? null) ? $dispatchPayload['sender'] : [];
        $patient = is_array($dispatchPayload['patient'] ?? null) ? $dispatchPayload['patient'] : [];
        $document = is_array($dispatchPayload['document'] ?? null) ? $dispatchPayload['document'] : [];

        $issueDate = trim((string) ($document['issue_date'] ?? ''));
        $paymentDate = trim((string) ($document['payment_date'] ?? ''));
        $documentDevice = max(0, (int) ($document['document_device'] ?? 0));
        $paymentMode = trim((string) ($document['payment_mode'] ?? ''));
        $documentType = trim((string) ($document['document_type'] ?? 'F'));
        $vatRate = $document['vat_rate'] ?? null;
        $vatNature = strtoupper(trim((string) ($document['vat_nature'] ?? '')));

        $payload = [
            'idSpesa' => [
                'pIva' => $this->asSoapString(trim((string) ($sender['piva'] ?? ''))),
                'dataEmissione' => $issueDate,
                'numDocumentoFiscale' => [
                    'dispositivo' => $documentDevice,
                    'numDocumento' => trim((string) ($document['document_number'] ?? '')),
                ],
            ],
            'dataPagamento' => $paymentDate,
            'cfCittadino' => $this->crypto->encryptRequired((string) ($patient['cf'] ?? '')),
            'voceSpesa' => [
                'tipoSpesa' => trim((string) ($document['expense_type_code'] ?? 'SP')),
                'importo' => $this->formatAmount((float) ($document['amount_total'] ?? 0)),
            ],
            'pagamentoTracciato' => $paymentMode === 'tracciato' ? 'SI' : 'NO',
            'tipoDocumento' => $documentType !== '' ? $documentType : 'F',
            'flagOpposizione' => (int) ($document['opposition_flag'] ?? 0) === 1 ? '1' : '0',
        ];

        if ($vatRate !== null && $vatRate !== '') {
            $payload['voceSpesa']['aliquotaIVA'] = $this->formatRate((float) $vatRate);
        } elseif ($vatNature !== '') {
            $payload['voceSpesa']['naturaIVA'] = $vatNature;
        }

        if ($this->shouldFlagPagamentoAnticipato($issueDate, $paymentDate)) {
            $payload['flagPagamentoAnticipato'] = '1';
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $sender
     * @return array<string, mixed>
     */
    private function buildOwnerPayload(array $profile, array $sender): array
    {
        $payload = [];

        foreach ([
            'codiceRegione' => trim((string) ($profile['region_code'] ?? '')),
            'codiceAsl' => trim((string) ($profile['asl_code'] ?? '')),
            'codiceSSA' => trim((string) ($profile['ssa_code'] ?? '')),
        ] as $key => $value) {
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        $ownerCf = trim((string) ($sender['cf'] ?? ''));
        if ($ownerCf !== '') {
            $payload['cfProprietario'] = $this->crypto->encryptRequired($ownerCf);
        }

        return $payload;
    }

    private function shouldFlagPagamentoAnticipato(string $issueDate, string $paymentDate): bool
    {
        return $issueDate !== '' && $paymentDate !== '' && $paymentDate < $issueDate;
    }

    private function formatAmount(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    private function formatRate(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    private function asSoapString(string $value): \SoapVar
    {
        return new \SoapVar($value, XSD_STRING);
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

    private function maskSensitiveValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', max(0, $length - 4)) . substr($value, -4);
    }
}
