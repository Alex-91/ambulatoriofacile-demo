<?php

namespace App\Services;

use App\Config\Fse2;

class FseGatewayClient
{
    private FseJwtService $jwt;
    private Fse2 $config;
    private FseGatewayTransport $transport;

    public function __construct(?FseJwtService $jwt = null, ?Fse2 $config = null, ?FseGatewayTransport $transport = null)
    {
        $this->jwt = $jwt ?? new FseJwtService();
        $this->config = $config ?? config(Fse2::class);
        $this->transport = $transport ?? new FseGatewayTransport();
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document @return array<string,mixed> */
    public function validate(array $profile, array $document, string $filePath, string $activity = 'VALIDATION'): array
    {
        $this->requireNationalOperation($profile);
        if (!in_array($activity, ['VERIFICA', 'VALIDATION'], true)) throw new \RuntimeException('Attività di validazione FSE non ammessa.');
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
        $this->requireNationalOperation($profile);
        return $this->multipart('POST', '/documents', $profile, $document, $filePath, $this->publicationBody($profile, $document), 'CREATE');
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document @return array<string,mixed> */
    public function status(array $profile, array $document, string $filePath): array
    {
        $this->requireNationalOperation($profile);
        $workflow = trim((string) ($document['workflow_instance_id'] ?? ''));
        if ($workflow === '') throw new \RuntimeException('Workflow FSE non ancora disponibile.');
        return $this->request('GET', '/status/' . rawurlencode($workflow), $profile, $document, $filePath, null, 'CREATE', false);
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document @return array<string,mixed> */
    public function delete(array $profile, array $document, string $filePath): array
    {
        $id = $this->documentIdentifier($profile, $document);
        return $this->request('DELETE', '/documents/' . rawurlencode($id), $profile, $document, $filePath, null, 'DELETE');
    }

    /** Transport primitive only: caller must retain the original and create a new CDA version. */
    public function replace(array $profile, array $document, string $filePath, string $previousIdentifier): array
    {
        $this->assertIdentifier($previousIdentifier);
        if ($previousIdentifier === $this->documentIdentifier($profile, $document)) {
            throw new \RuntimeException('La sostituzione richiede un nuovo identificativo documento.');
        }
        return $this->multipart('PUT', '/documents/' . rawurlencode($previousIdentifier), $profile, $document,
            $filePath, $this->publicationBody($profile, $document), 'UPDATE');
    }

    /** Transport primitive only. A new submission_id must be allocated by the calling workflow. */
    public function updateMetadata(array $profile, array $document, string $filePath): array
    {
        $body = $this->publicationBody($profile, $document);
        unset($body['identificativoDoc'], $body['identificativoRep'], $body['mode'], $body['healthDataFormat']);
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $this->request('PUT', '/documents/' . rawurlencode($this->documentIdentifier($profile, $document)) . '/metadata',
            $profile, $document, $filePath, $json, 'UPDATE');
    }

    private function requireNationalOperation(array $profile): void
    {
        if (($profile['access_mode'] ?? '') === 'toscana_privati') {
            throw new \RuntimeException('Operazione non prevista dalle specifiche Toscana Privati v1.5: usare i quattro metodi regionali.');
        }
    }

    private function documentIdentifier(array $profile, array $document): string
    {
        $root = trim((string) ($document['document_oid_root'] ?? '')) ?: trim((string) ($profile['document_oid_root'] ?? ''));
        $id = $root . '^' . trim((string) ($document['document_unique_id'] ?? ''));
        $this->assertIdentifier($id);
        return $id;
    }

    private function assertIdentifier(string $identifier): void
    {
        if (strlen($identifier) > 256 || !preg_match('/^[0-9]+(?:\.[0-9]+)+\^[^\s\x00-\x1F\x7F^]+$/D', $identifier)) {
            throw new \RuntimeException('Identificativo FSE atteso nel formato OID^estensione.');
        }
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document @param array<string,mixed> $body @return array<string,mixed> */
    private function multipart(string $method, string $path, array $profile, array $document, string $filePath, array $body, string $action): array
    {
        if (!is_file($filePath)) throw new \RuntimeException('PDF FSE da trasmettere non disponibile.');
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) throw new \RuntimeException('Metadati Gateway FSE non serializzabili.');
        $post = [
            // National GTW expects a form field, not a second uploaded file (filename=request.json).
            'requestBody' => ($profile['access_mode'] ?? 'gateway') === 'gateway' ? $json
                : (class_exists('CURLStringFile') ? new \CURLStringFile($json, 'request.json', 'application/json') : $json),
            'file' => new \CURLFile($filePath, 'application/pdf', basename($filePath)),
        ];
        return $this->request($method, $path, $profile, $document, $filePath, $post, $action);
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document @return array<string,mixed> */
    private function publicationBody(array $profile, array $document): array
    {
        return (new FseGatewayPayload())->publication($profile, $document);
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document @param array<string,mixed>|null $body @return array<string,mixed> */
    private function request(string $method, string $path, array $profile, array $document, string $filePath, array|string|null $body, string $action, bool $signatureRequired = true): array
    {
        if (!extension_loaded('curl')) throw new \RuntimeException('Estensione PHP cURL richiesta per il Gateway FSE.');
        if (($profile['environment'] ?? 'test') === 'production' && !$this->config->allowProduction) throw new \RuntimeException('Invio FSE production disabilitato.');
        $base = $this->config->gatewayUrlForProfile($profile);
        if (($profile['access_mode'] ?? '') === 'toscana_privati') {
            if (($profile['environment'] ?? 'test') !== 'test') throw new \RuntimeException('Toscana produzione non ancora disponibile: completare workflow e collaudo regionale.');
            if (!$this->config->allowToscanaStage) throw new \RuntimeException('Trasporto Toscana di collaudo disabilitato: richiede FSE2_ALLOW_TOSCANA_STAGE=true e autorizzazione CART.');
        }
        if ($this->config->gatewayCaBundle !== '' && (!is_file($this->config->gatewayCaBundle) || !is_readable($this->config->gatewayCaBundle))) {
            throw new \RuntimeException('Bundle CA del server Gateway non leggibile: verifica TLS obbligatoria.');
        }
        $tokens = $signatureRequired ? $this->jwt->createTokens($profile, $document, $filePath, $action) : null;
        $authorization = $signatureRequired
            ? (string) $tokens['authorization']
            : $this->jwt->createAuthorizationToken($profile, $document);
        $cert = $this->config->resolveCertificatePath((string) ($profile['auth_certificate_path'] ?? ''));
        $key = $this->config->resolveCertificatePath((string) ($profile['auth_private_key_path'] ?? ''));
        if (!is_readable($cert) || !is_readable($key)) throw new \RuntimeException('Certificato/chiave mTLS FSE non leggibili.');
        $options = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => $this->config->connectTimeout,
            CURLOPT_TIMEOUT => $this->config->requestTimeout, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLCERT => $cert, CURLOPT_SSLKEY => $key,
        ];
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $authorization];
        if ($this->config->gatewayCaBundle !== '') $options[CURLOPT_CAINFO] = $this->config->gatewayCaBundle;
        if ($signatureRequired) $headers[] = 'FSE-JWT-Signature: ' . $tokens['signature'];
        if (is_string($body)) $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $passphrase = (string) ($profile['auth_private_key_passphrase'] ?? '');
        if ($passphrase !== '') $options[CURLOPT_KEYPASSWD] = $passphrase;
        if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
        return $this->transport->send($base . $path, $options);
    }
}
