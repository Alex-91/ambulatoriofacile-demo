<?php

namespace App\Services;

use App\Config\TsBilling;

class TsSoapClientFactory
{
    private TsBilling $config;
    private TsSecretsService $secrets;

    public function __construct(?TsBilling $config = null, ?TsSecretsService $secrets = null)
    {
        $this->config = $config ?? config(TsBilling::class);
        $this->secrets = $secrets ?? new TsSecretsService();
    }

    public function createForDocumentProfile(array $profile): \SoapClient
    {
        return $this->createClientForProfile($profile, 'document');
    }

    public function createForReceiptsProfile(array $profile): \SoapClient
    {
        return $this->createClientForProfile($profile, 'receipts');
    }

    /**
     * @return array<string, mixed>
     */
    public function describeReceiptsContract(array $profile): array
    {
        $client = $this->createForReceiptsProfile($profile);
        $environment = trim((string) ($profile['environment'] ?? 'test'));
        $environmentConfig = $this->config->resolveEnvironmentConfig($environment);

        return [
            'wsdl' => $this->config->receiptsWsdl,
            'endpoint' => trim((string) ($environmentConfig['receipts_endpoint'] ?? '')),
            'configured_operation' => 'RicevutaPdf',
            'functions' => method_exists($client, '__getFunctions') ? array_values((array) $client->__getFunctions()) : [],
            'types' => method_exists($client, '__getTypes') ? array_values((array) $client->__getTypes()) : [],
        ];
    }

    private function createClientForProfile(array $profile, string $contract): \SoapClient
    {
        if (!extension_loaded('soap')) {
            throw new \RuntimeException('L estensione SOAP non e disponibile nel runtime attuale.');
        }

        $environment = trim((string) ($profile['environment'] ?? 'test'));
        $environmentConfig = $this->config->resolveEnvironmentConfig($environment);
        $wsdl = $contract === 'receipts' ? $this->config->receiptsWsdl : $this->config->documentSyncWsdl;
        if (!is_file($wsdl)) {
            throw new \RuntimeException(
                ($contract === 'receipts' ? 'WSDL ricevute TS non trovato: ' : 'WSDL documento TS non trovato: ') . $wsdl
            );
        }

        $endpoint = trim((string) ($contract === 'receipts'
            ? ($environmentConfig['receipts_endpoint'] ?? '')
            : ($environmentConfig['document_endpoint'] ?? '')));
        if ($endpoint === '') {
            throw new \RuntimeException(
                ($contract === 'receipts'
                    ? 'Endpoint ricevute TS non configurato per l ambiente "'
                    : 'Endpoint documento TS non configurato per l ambiente "')
                . $environment . '".'
            );
        }

        $isTestEnvironment = strtolower($environment) === 'test';

        $streamContext = stream_context_create([
            'http' => [
                'timeout' => $this->config->soapRequestTimeout,
            ],
            'ssl' => [
                // The public TS TEST endpoint currently presents a TLS chain
                // that may not be trusted by local Windows runtimes.
                'verify_peer' => !$isTestEnvironment,
                'verify_peer_name' => !$isTestEnvironment,
                'allow_self_signed' => $isTestEnvironment,
            ],
        ]);

        $options = [
            'cache_wsdl' => WSDL_CACHE_NONE,
            'connection_timeout' => $this->config->soapConnectionTimeout,
            'exceptions' => true,
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
            'location' => $endpoint,
            'stream_context' => $streamContext,
            'trace' => ($this->config->soapTrace || $isTestEnvironment) ? 1 : 0,
        ];

        $authUsername = trim((string) ($profile['auth_username'] ?? ''));
        $authPassword = $this->safeDecrypt((string) ($profile['auth_password_enc'] ?? ''));
        if ($authUsername !== '' && $authPassword !== '') {
            $options['login'] = $authUsername;
            $options['password'] = $authPassword;
            $options['authentication'] = SOAP_AUTHENTICATION_BASIC;
        }

        try {
            return new \SoapClient($wsdl, $options);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Inizializzazione client SOAP TS non riuscita: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function describeDocumentContract(array $profile): array
    {
        $client = $this->createForDocumentProfile($profile);
        $environment = trim((string) ($profile['environment'] ?? 'test'));
        $environmentConfig = $this->config->resolveEnvironmentConfig($environment);

        return [
            'wsdl' => $this->config->documentSyncWsdl,
            'endpoint' => trim((string) ($environmentConfig['document_endpoint'] ?? '')),
            'configured_operation' => $this->config->documentOperationName,
            'configured_request_root' => $this->config->documentRequestRoot,
            'functions' => method_exists($client, '__getFunctions') ? array_values((array) $client->__getFunctions()) : [],
            'types' => method_exists($client, '__getTypes') ? array_values((array) $client->__getTypes()) : [],
        ];
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
}
