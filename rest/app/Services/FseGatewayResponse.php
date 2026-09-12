<?php

namespace App\Services;

/** Keeps only diagnostic headers: never cookies, JWTs or request headers. */
class FseGatewayResponse
{
    public const MAX_BODY_BYTES = 4194304;
    public const MAX_HEADER_BYTES = 65536;
    private array $headers = [];
    private string $body = '';
    private int $headerBytes = 0;
    private bool $limitExceeded = false;

    /** cURL write callback: returning zero aborts the read, never retries the write. */
    public function captureBody(string $chunk): int
    {
        if ($this->limitExceeded || strlen($this->body) + strlen($chunk) > self::MAX_BODY_BYTES) {
            $this->limitExceeded = true;
            $this->body = '';
            return 0;
        }
        $this->body .= $chunk;
        return strlen($chunk);
    }

    public function body(): string|false { return $this->limitExceeded ? false : $this->body; }

    public function captureHeader(string $line): int
    {
        $this->headerBytes += strlen($line);
        if ($this->headerBytes > self::MAX_HEADER_BYTES) {
            $this->limitExceeded = true;
            $this->body = '';
            return 0;
        }
        if (preg_match('#^HTTP/\S+\s+\d{3}#i', $line)) {
            // A proxy/100 Continue block must not leak into the final response.
            $this->headers = [];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            if (in_array($name, ['x-cart-id', 'x-request-id', 'retry-after'], true)) {
                $this->headers[$name] = substr(preg_replace('/[\x00-\x1F\x7F]/', '', trim($value)) ?? '', 0, 512);
            }
        }
        return strlen($line);
    }

    /** @return array<string,mixed> */
    public function result(string|false $raw, int $status, int $curlError = 0): array
    {
        if ($this->limitExceeded || (is_string($raw) && strlen($raw) > self::MAX_BODY_BYTES)) {
            $this->limitExceeded = true;
            $raw = false;
        }
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        $validJson = is_array($payload);
        // A truncated response or server error may follow a successful remote write.
        $uncertain = $raw === false || $curlError !== 0 || $status === 0 || $status === 408 || $status >= 500
            || ($status >= 200 && $status < 300 && !$validJson);
        $ok = !$uncertain && $status >= 200 && $status < 300;
        // Do not persist arbitrary HTML/proxy bodies (which can contain credentials).
        // Only this observed national error URI is classified; arbitrary remote text is discarded.
        $category = !$ok && $status === 400 && ($payload['type'] ?? null) === '/msg/vocabulary' ? 'VOCABULARY' : null;
        $payload = $validJson ? self::technicalPayload($payload) : [];
        $message = 'Gateway FSE HTTP ' . $status;
        if ($uncertain) $message = 'Esito Gateway non determinabile: verificare la transazione prima di ripetere l’invio. HTTP ' . $status . ', cURL ' . $curlError . '.';
        return [
            'ok' => $ok, 'http_status' => $status, 'payload' => $payload, 'message' => $message,
            'response_headers' => $this->headers, 'x_cart_id' => $this->headers['x-cart-id'] ?? null,
            'outcome_uncertain' => $uncertain,
            'error_category' => $this->limitExceeded ? 'RESPONSE_LIMIT' : $category,
        ];
    }

    /** Gateway messages and SOAP bodies can contain health data. Persist only correlation/status fields. */
    public static function technicalPayload(array $payload, int $depth = 0): array
    {
        if ($depth > 2) return [];
        if (array_is_list($payload)) return array_map(static fn($item) => is_array($item) ? self::technicalPayload($item, $depth + 1) : [], array_slice($payload, 0, 1000));
        $safe = [];
        foreach (['workflowInstanceId', 'traceID', 'traceId', 'spanID', 'spanId', 'eventType', 'eventStatus', 'status', 'type'] as $key) {
            $value = $payload[$key] ?? null;
            // Observed on rejected JWTs: a placeholder is not a pollable transaction ID.
            if ($key === 'workflowInstanceId' && $value === 'UNKNOWN_WORKFLOW_ID') continue;
            $pattern = in_array($key, ['eventType', 'eventStatus', 'status', 'type'], true) ? '/^[A-Z_]{1,80}$/D' : '/^[A-Za-z0-9._:^&=+@\/#-]{1,500}$/D';
            if (is_string($value) && preg_match($pattern, $value)) $safe[$key] = $value;
        }
        foreach (['transactionData', 'events'] as $key) if (isset($payload[$key]) && is_array($payload[$key])) $safe[$key] = self::technicalPayload($payload[$key], $depth + 1);
        return $safe;
    }
}
