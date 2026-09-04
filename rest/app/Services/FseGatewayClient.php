<?php

namespace App\Services;

use App\Config\Fse2;

class FseGatewayClient
{
    private FseJwtService $jwt;
    private Fse2 $config;

    public function __construct(?FseJwtService $jwt = null, ?Fse2 $config = null)
    {
        $this->jwt = $jwt ?? new FseJwtService();
        $this->config = $config ?? config(Fse2::class);
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document @return array<string,mixed> */
    public function validate(array $profile, array $document, string $filePath, string $activity = 'VALIDATION'): array
    {
        return $this->multipart('POST', '/documents/validation', $profile, $document, $filePath, [
            'healthDataFormat' => 'CDA', 'mode' => 'ATTACHMENT', 'activity' => $activity,
        ], 'CREATE');
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document @return array<string,mixed> */
    public function validateAndCreate(array $profile, array $document, string $filePath): array
    {
        return $this->multipart('POST', '/documents/validate-and-create', $profile, $document, $filePath, $this->publicationBody($profile, $document), 'CREATE');
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document @return array<string,mixed> */
    public function create(array $profile, array $document, string $filePath): array
    {
        return $this->multipart('POST', '/documents', $profile, $document, $filePath, $this->publicationBody($profile, $document), 'CREATE');
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document @return array<string,mixed> */
    public function status(array $profile, array $document, string $filePath): array
    {
        $workflow = trim((string) ($document['workflow_instance_id'] ?? ''));
        if ($workflow === '') throw new \RuntimeException('Workflow FSE non ancora disponibile.');
        return $this->request('GET', '/status/' . rawurlencode($workflow), $profile, $document, $filePath, null, 'CREATE', false);
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document @return array<string,mixed> */
    public function delete(array $profile, array $document, string $filePath): array
    {
        $id = trim((string) ($profile['document_oid_root'] ?? '')) . '^' . trim((string) ($document['document_unique_id'] ?? ''));
        return $this->request('DELETE', '/documents/' . rawurlencode($id), $profile, $document, $filePath, null, 'DELETE');
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document @param array<string,mixed> $body @return array<string,mixed> */
    private function multipart(string $method, string $path, array $profile, array $document, string $filePath, array $body, string $action): array
    {
        if (!is_file($filePath)) throw new \RuntimeException('PDF FSE da trasmettere non disponibile.');
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) throw new \RuntimeException('Metadati Gateway FSE non serializzabili.');
        $post = [
            'requestBody' => class_exists('CURLStringFile') ? new \CURLStringFile($json, 'request.json', 'application/json') : $json,
            'file' => new \CURLFile($filePath, 'application/pdf', basename($filePath)),
        ];
        return $this->request($method, $path, $profile, $document, $filePath, $post, $action);
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document @return array<string,mixed> */
    private function publicationBody(array $profile, array $document): array
    {
        $start = new \DateTimeImmutable((string) $document['service_start']);
        $end = trim((string) ($document['service_end'] ?? '')) !== '' ? new \DateTimeImmutable((string) $document['service_end']) : $start;
        return [
            'tipologiaStruttura' => (string) $profile['facility_type'], 'attiCliniciRegoleAccesso' => [],
            'tipoDocumentoLivAlto' => 'REF', 'assettoOrganizzativo' => (string) $profile['organizational_setting'],
            'dataInizioPrestazione' => $start->format('YmdHis'), 'dataFinePrestazione' => $end->format('YmdHis'),
            'tipoAttivitaClinica' => (string) ($profile['clinical_activity'] ?? 'ERP'),
            'identificativoSottomissione' => (string) $profile['submission_oid_root'] . '^' . (string) $document['submission_id'],
            'administrativeRequest' => [(string) $document['administrative_request']],
            'identificativoDoc' => (string) $profile['document_oid_root'] . '^' . (string) $document['document_unique_id'],
            'identificativoRep' => (string) $profile['repository_id'], 'mode' => 'ATTACHMENT', 'healthDataFormat' => 'CDA',
        ];
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document @param array<string,mixed>|null $body @return array<string,mixed> */
    private function request(string $method, string $path, array $profile, array $document, string $filePath, ?array $body, string $action, bool $signatureRequired = true): array
    {
        if (!extension_loaded('curl')) throw new \RuntimeException('Estensione PHP cURL richiesta per il Gateway FSE.');
        if (($profile['environment'] ?? 'test') === 'production' && !$this->config->allowProduction) throw new \RuntimeException('Invio FSE production disabilitato.');
        $base = $this->config->gatewayUrl((string) ($profile['environment'] ?? 'test'), (string) ($profile['gateway_base_url'] ?? ''));
        if (!str_starts_with(strtolower($base), 'https://')) throw new \RuntimeException('Il Gateway FSE deve usare HTTPS.');
        $tokens = $signatureRequired ? $this->jwt->createTokens($profile, $document, $filePath, $action) : null;
        $authorization = $signatureRequired
            ? (string) $tokens['authorization']
            : $this->jwt->createAuthorizationToken($profile, $document);
        $cert = $this->config->resolveCertificatePath((string) ($profile['auth_certificate_path'] ?? ''));
        $key = $this->config->resolveCertificatePath((string) ($profile['auth_private_key_path'] ?? ''));
        if (!is_readable($cert) || !is_readable($key)) throw new \RuntimeException('Certificato/chiave mTLS FSE non leggibili.');
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => $this->config->connectTimeout,
            CURLOPT_TIMEOUT => $this->config->requestTimeout, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLCERT => $cert, CURLOPT_SSLKEY => $key,
        ]);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $authorization];
        if ($signatureRequired) $headers[] = 'FSE-JWT-Signature: ' . $tokens['signature'];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $passphrase = (string) ($profile['auth_private_key_passphrase'] ?? '');
        if ($passphrase !== '') curl_setopt($ch, CURLOPT_KEYPASSWD, $passphrase);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) throw new \RuntimeException('Connessione Gateway FSE non riuscita: ' . $error);
        $payload = json_decode((string) $raw, true);
        $payload = is_array($payload) ? $payload : ['raw' => substr((string) $raw, 0, 8000)];
        return ['ok' => $status >= 200 && $status < 300, 'http_status' => $status, 'payload' => $payload, 'message' => $this->message($payload, $status)];
    }

    /** @param array<string,mixed> $payload */
    private function message(array $payload, int $status): string
    {
        foreach (['detail', 'message', 'title', 'warning'] as $key) {
            if (trim((string) ($payload[$key] ?? '')) !== '') return trim((string) $payload[$key]);
        }
        return 'Gateway FSE HTTP ' . $status;
    }
}
