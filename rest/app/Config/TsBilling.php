<?php

namespace App\Config;

use CodeIgniter\Config\BaseConfig;

class TsBilling extends BaseConfig
{
    public const FEATURE_KEY = 'ts_billing';

    /**
     * @var array<int, string>
     */
    public array $localStates = [
        'draft',
        'to_validate',
        'ready',
        'sending',
        'sent',
        'rejected',
        'cancelled',
    ];

    /**
     * @var array<string, string>
     */
    public array $uiStateLabels = [
        'draft' => 'Bozza',
        'to_validate' => 'Da validare',
        'ready' => 'Pronto',
        'sending' => 'In invio',
        'sent' => 'Inviato',
        'rejected' => 'Scartato',
        'cancelled' => 'Annullato',
    ];

    /**
     * @var array<string, string>
     */
    public array $sourceTypeLabels = [
        'manual' => 'Documento principale',
        'billing' => 'Fattura da Fatturazione',
        'ts_variation' => 'Variazione TS',
        'ts_cancellation' => 'Cancellazione TS',
    ];

    /**
     * @var array<string, string>
     */
    public array $supportedExpenseTypes = [
        'TK' => 'Ticket',
        'FC' => 'Farmaci',
        'FV' => 'Farmaci veterinari',
        'AS' => 'Altre spese sanitarie',
        'SR' => 'Prestazioni sanitarie specialistiche',
        'CT' => 'Codice TS CT',
        'PI' => 'Codice TS PI',
        'IC' => 'Codice TS IC',
        'AA' => 'Codice TS AA',
        'AD' => 'Codice TS AD',
        'SV' => 'Codice TS SV',
        'SP' => 'Spese sanitarie generiche',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    public array $expenseTypeMetadata = [
        'TK' => [
            'confidence' => 'verified',
            'note' => 'Descrizione stabile e allineata all uso corrente del tracciato TS.',
        ],
        'FC' => [
            'confidence' => 'verified',
            'note' => 'Descrizione stabile e allineata all uso corrente del tracciato TS.',
        ],
        'FV' => [
            'confidence' => 'verified',
            'note' => 'Descrizione stabile e allineata all uso corrente del tracciato TS.',
        ],
        'AS' => [
            'confidence' => 'verified',
            'note' => 'Descrizione stabile e allineata all uso corrente del tracciato TS.',
        ],
        'SR' => [
            'confidence' => 'verified',
            'note' => 'Descrizione stabile e gia collaudata nel flusso TEST reale del modulo.',
        ],
        'CT' => [
            'confidence' => 'provisional',
            'note' => 'Codice ufficiale presente nell XSD TS; la descrizione estesa resta prudente finche non la validiamo su casi reali o documentazione piu esplicita.',
        ],
        'PI' => [
            'confidence' => 'provisional',
            'note' => 'Codice ufficiale presente nell XSD TS; la descrizione estesa resta prudente finche non la validiamo su casi reali o documentazione piu esplicita.',
        ],
        'IC' => [
            'confidence' => 'provisional',
            'note' => 'Codice ufficiale presente nell XSD TS; la descrizione estesa resta prudente finche non la validiamo su casi reali o documentazione piu esplicita.',
        ],
        'AA' => [
            'confidence' => 'provisional',
            'note' => 'Codice ufficiale presente nell XSD TS; la descrizione estesa resta prudente finche non la validiamo su casi reali o documentazione piu esplicita.',
        ],
        'AD' => [
            'confidence' => 'provisional',
            'note' => 'Codice ufficiale presente nell XSD TS; la descrizione estesa resta prudente finche non la validiamo su casi reali o documentazione piu esplicita.',
        ],
        'SV' => [
            'confidence' => 'provisional',
            'note' => 'Codice ufficiale presente nell XSD TS; la descrizione estesa resta prudente finche non la validiamo su casi reali o documentazione piu esplicita.',
        ],
        'SP' => [
            'confidence' => 'verified',
            'note' => 'Descrizione stabile e allineata all uso corrente del tracciato TS.',
        ],
    ];

    /**
     * @var array<string, string>
     */
    public array $supportedDocumentTypes = [
        'F' => 'Fattura',
        'D' => 'Documento commerciale',
    ];

    /**
     * @var array<string, string>
     */
    public array $senderTypes = [
        'medico' => 'Medico',
        'struttura_autorizzata' => 'Struttura autorizzata',
        'struttura_accreditata' => 'Struttura accreditata',
        'studio_associato' => 'Studio associato',
    ];

    /**
     * @var array<string, string>
     */
    public array $paymentModes = [
        'tracciato' => 'Pagamento tracciato',
        'contanti' => 'Pagamento in contanti',
    ];

    /**
     * @var array<string, string>
     */
    public array $defaultAssets = [
        'document_sync_wsdl' => 'wsdl/DocumentoSpesa730p.wsdl',
        'receipts_wsdl' => 'wsdl/RicevutaPdf730Service.wsdl',
        'public_cert' => 'certs/SanitelCF.cer',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    public array $environments = [];

    public string $thirdPartyBasePath;
    public string $tenantStorageRoot;
    public string $tenantStorageSegment = 'ts';
    public string $documentSyncWsdl;
    public string $receiptsWsdl;
    public string $publicCertPath;
    public string $testPresetsPath;
    public string $documentOperationName = 'Inserimento';
    public string $documentRequestRoot = 'inserimentoDocumentoSpesaRequest';
    public string $documentResponseProtocolPath = 'protocollo';
    public string $documentResponseOutcomePath = 'esitoChiamata';
    public string $documentResponseMessagePath = 'listaMessaggi.messaggio';
    public bool $assumeSuccessOnSoapReturn = false;
    /** @var array<int, string> */
    public array $documentResponseOkValues = [];
    public int $soapConnectionTimeout = 20;
    public int $soapRequestTimeout = 60;
    public bool $soapTrace = false;
    public bool $storeDebugArtifacts = false;

    public function __construct()
    {
        $this->thirdPartyBasePath = APPPATH . 'ThirdParty' . DIRECTORY_SEPARATOR . 'TesseraSanitaria';
        $this->tenantStorageRoot = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'tenants';
        $this->testPresetsPath = $this->resolveTestPresetsPath((string) env('TS_BILLING_TEST_PRESETS_PATH', ''));

        $this->documentSyncWsdl = $this->resolveAssetPath(
            (string) env('TS_BILLING_DOCUMENT_SYNC_WSDL', ''),
            $this->defaultAssets['document_sync_wsdl']
        );
        $this->receiptsWsdl = $this->resolveAssetPath(
            (string) env('TS_BILLING_RECEIPTS_WSDL', ''),
            $this->defaultAssets['receipts_wsdl']
        );
        $this->publicCertPath = $this->resolveAssetPath(
            (string) env('TS_BILLING_PUBLIC_CERT', ''),
            $this->defaultAssets['public_cert']
        );

        $this->documentOperationName = trim((string) env('TS_BILLING_DOCUMENT_OPERATION', 'Inserimento'));
        $this->documentRequestRoot = trim((string) env('TS_BILLING_DOCUMENT_REQUEST_ROOT', 'inserimentoDocumentoSpesaRequest'));
        $this->documentResponseProtocolPath = trim((string) env('TS_BILLING_DOCUMENT_RESPONSE_PROTOCOL_PATH', 'protocollo'));
        $this->documentResponseOutcomePath = trim((string) env('TS_BILLING_DOCUMENT_RESPONSE_OUTCOME_PATH', 'esitoChiamata'));
        $this->documentResponseMessagePath = trim((string) env('TS_BILLING_DOCUMENT_RESPONSE_MESSAGE_PATH', 'listaMessaggi.messaggio'));
        $this->assumeSuccessOnSoapReturn = $this->toBoolean(env('TS_BILLING_ASSUME_SUCCESS_ON_SOAP_RETURN', false));
        $this->documentResponseOkValues = $this->parseCsvStrings((string) env('TS_BILLING_DOCUMENT_RESPONSE_OK_VALUES', 'ok,success,successo,0,000,true,si'));

        $this->soapConnectionTimeout = max(1, (int) env('TS_BILLING_SOAP_CONNECTION_TIMEOUT', 20));
        $this->soapRequestTimeout = max(1, (int) env('TS_BILLING_SOAP_REQUEST_TIMEOUT', 60));
        $this->soapTrace = $this->toBoolean(env('TS_BILLING_SOAP_TRACE', false));
        $this->storeDebugArtifacts = $this->toBoolean(env('TS_BILLING_STORE_DEBUG_ARTIFACTS', false));

        $this->environments = [
            'test' => [
                'document_endpoint' => trim((string) env(
                    'TS_BILLING_TEST_DOCUMENT_ENDPOINT',
                    'https://invioSS730pTest.sanita.finanze.it/DocumentoSpesa730pWeb/DocumentoSpesa730pPort'
                )),
                'receipts_endpoint' => trim((string) env(
                    'TS_BILLING_TEST_RECEIPTS_ENDPOINT',
                    'https://invioSS730pTest.sanita.finanze.it/Ricevute730ServiceWeb/ricevutePdf'
                )),
            ],
            'production' => [
                'document_endpoint' => trim((string) env(
                    'TS_BILLING_PRODUCTION_DOCUMENT_ENDPOINT',
                    'https://invioSS730p.sanita.finanze.it/DocumentoSpesa730pWeb/DocumentoSpesa730pPort'
                )),
                'receipts_endpoint' => trim((string) env(
                    'TS_BILLING_PRODUCTION_RECEIPTS_ENDPOINT',
                    'https://invioSS730p.sanita.finanze.it/Ricevute730ServiceWeb/ricevutePdf'
                )),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function resolveEnvironmentConfig(string $environment): array
    {
        $environment = trim(strtolower($environment));

        return $this->environments[$environment] ?? $this->environments['test'];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function resolveExpenseTypeDetails(): array
    {
        $details = [];

        foreach ($this->supportedExpenseTypes as $code => $label) {
            $metadata = $this->expenseTypeMetadata[$code] ?? [];
            $confidence = trim((string) ($metadata['confidence'] ?? 'verified'));

            $details[$code] = [
                'code' => $code,
                'label' => $label,
                'confidence' => $confidence,
                'confidence_label' => $confidence === 'provisional'
                    ? 'Descrizione prudente'
                    : 'Descrizione verificata',
                'note' => trim((string) ($metadata['note'] ?? '')),
            ];
        }

        return $details;
    }

    private function resolveTestPresetsPath(string $configuredPath): string
    {
        $configuredPath = trim($configuredPath);
        if ($configuredPath !== '') {
            if ($this->isAbsolutePath($configuredPath)) {
                return $configuredPath;
            }

            return dirname(rtrim(ROOTPATH, '\\/')) . DIRECTORY_SEPARATOR . str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                trim($configuredPath, '/\\')
            );
        }

        $projectRoot = dirname(rtrim(ROOTPATH, '\\/'));
        $localPath = $projectRoot . DIRECTORY_SEPARATOR . 'ops' . DIRECTORY_SEPARATOR . '.local' . DIRECTORY_SEPARATOR . 'ts-test-presets.json';
        if (is_file($localPath)) {
            return $localPath;
        }

        return rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'ts' . DIRECTORY_SEPARATOR . 'ts-test-presets.json';
    }

    private function resolveAssetPath(string $configuredPath, string $defaultRelativePath): string
    {
        $configuredPath = trim($configuredPath);
        if ($configuredPath !== '') {
            if ($this->isAbsolutePath($configuredPath)) {
                return $configuredPath;
            }

            return $this->thirdPartyBasePath . DIRECTORY_SEPARATOR . str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                trim($configuredPath, '/\\')
            );
        }

        return $this->thirdPartyBasePath . DIRECTORY_SEPARATOR . str_replace(
            ['/', '\\'],
            DIRECTORY_SEPARATOR,
            trim($defaultRelativePath, '/\\')
        );
    }

    private function isAbsolutePath(string $path): bool
    {
        return (bool) preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path);
    }

    /**
     * @param mixed $value
     */
    private function toBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? false;
    }

    /**
     * @return array<int, string>
     */
    private function parseCsvStrings(string $value): array
    {
        $items = array_map(
            static fn(string $item): string => trim(strtolower($item)),
            explode(',', $value)
        );

        return array_values(array_filter($items, static fn(string $item): bool => $item !== ''));
    }
}
