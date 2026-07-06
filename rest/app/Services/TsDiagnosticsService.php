<?php

namespace App\Services;

class TsDiagnosticsService
{
    private TsStorageService $storage;

    public function __construct(?TsStorageService $storage = null)
    {
        $this->storage = $storage ?? new TsStorageService();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchTraces(int $tenantId, array $filters = [], int $limit = 60): array
    {
        $filters = $this->normalizeFilters($filters);
        $limit = max(1, min(200, $limit));
        $files = $this->listTraceFiles($tenantId);
        $matches = [];
        $stats = [
            'total_scanned' => 0,
            'total_matched' => 0,
            'by_status' => [],
        ];

        foreach ($files as $path) {
            $summary = $this->loadTraceSummary($path);
            if (!is_array($summary)) {
                continue;
            }

            $stats['total_scanned']++;
            $entry = $this->buildTraceEntry($summary, $path);
            if (!$this->matchesFilters($entry, $filters)) {
                continue;
            }

            $stats['total_matched']++;
            $status = trim((string) ($entry['status'] ?? 'unknown'));
            if ($status === '') {
                $status = 'unknown';
            }

            $stats['by_status'][$status] = (int) ($stats['by_status'][$status] ?? 0) + 1;
            $matches[] = $entry;
        }

        usort($matches, function (array $left, array $right): int {
            $leftKey = trim((string) ($left['started_at'] ?? $left['last_modified_at'] ?? ''));
            $rightKey = trim((string) ($right['started_at'] ?? $right['last_modified_at'] ?? ''));

            return strcmp($rightKey, $leftKey);
        });

        return [
            'filters' => $filters,
            'results' => array_slice($matches, 0, $limit),
            'stats' => $stats,
            'has_logs' => $files !== [],
            'truncated' => count($matches) > $limit,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTraceDetail(int $tenantId, string $traceId): ?array
    {
        $path = $this->findTraceFileById($tenantId, $traceId);
        if ($path === null) {
            return null;
        }

        $summary = $this->loadTraceSummary($path);
        if (!is_array($summary)) {
            return null;
        }

        return [
            'entry' => $this->buildTraceEntry($summary, $path),
            'summary' => $summary,
            'raw_json' => $this->encodePrettyJson($summary),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    public function resolveTraceDownload(int $tenantId, string $traceId): ?array
    {
        $path = $this->findTraceFileById($tenantId, $traceId);
        if ($path === null || !is_file($path)) {
            return null;
        }

        return [
            'path' => $path,
            'file_name' => 'ts-trace-' . trim($traceId) . '.json',
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function buildTraceEntry(array $summary, string $path): array
    {
        $steps = is_array($summary['steps'] ?? null) ? $summary['steps'] : [];
        $document = $this->extractDocumentContext($summary, $steps);
        $documentId = (int) ($summary['context']['document_id'] ?? 0);
        if ($documentId <= 0) {
            $documentId = (int) ($document['id_ts_document'] ?? 0);
        }

        $documentNumber = trim((string) ($document['document_number'] ?? ''));
        $protocol = $this->extractProtocol($summary, $steps, $document);
        $timelinePath = $this->guessTimelinePath($summary, $path);
        $lastModifiedAt = @date('c', (int) @filemtime($path)) ?: '';

        return [
            'trace_id' => trim((string) ($summary['trace_id'] ?? '')),
            'operation' => trim((string) ($summary['operation'] ?? '')),
            'status' => trim((string) ($summary['status'] ?? '')),
            'message' => trim((string) ($summary['message'] ?? '')),
            'started_at' => trim((string) ($summary['started_at'] ?? '')),
            'finished_at' => trim((string) ($summary['finished_at'] ?? '')),
            'duration_ms' => (int) ($summary['duration_ms'] ?? 0),
            'document_id' => $documentId,
            'document_number' => $documentNumber,
            'document_type' => trim((string) ($document['document_type'] ?? '')),
            'issue_date' => trim((string) ($document['issue_date'] ?? '')),
            'payment_date' => trim((string) ($document['payment_date'] ?? '')),
            'local_state' => trim((string) ($document['local_state'] ?? ($summary['context']['local_state'] ?? ''))),
            'ts_state' => trim((string) ($document['ts_state'] ?? '')),
            'protocol' => $protocol,
            'outcome' => $this->extractOutcome($summary, $steps),
            'step_count' => count($steps),
            'has_exception' => is_array($summary['exception'] ?? null),
            'exception_message' => trim((string) ($summary['exception']['message'] ?? '')),
            'tenant_name' => trim((string) ($summary['tenant']['tenant_name'] ?? '')),
            'summary_path' => $path,
            'timeline_path' => $timelinePath,
            'last_modified_at' => $lastModifiedAt,
            'file_size' => (int) @filesize($path),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $normalizeDate = static function ($value): string {
            $value = trim((string) $value);
            if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return '';
            }

            return $value;
        };

        return [
            'trace_id' => trim((string) ($filters['trace_id'] ?? '')),
            'document_id' => max(0, (int) ($filters['document_id'] ?? 0)),
            'document_number' => trim((string) ($filters['document_number'] ?? '')),
            'protocol' => trim((string) ($filters['protocol'] ?? '')),
            'operation' => trim((string) ($filters['operation'] ?? '')),
            'status' => trim((string) ($filters['status'] ?? '')),
            'date_from' => $normalizeDate($filters['date_from'] ?? ''),
            'date_to' => $normalizeDate($filters['date_to'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $filters
     */
    private function matchesFilters(array $entry, array $filters): bool
    {
        if ($filters['trace_id'] !== '' && !$this->containsValue($entry['trace_id'] ?? '', $filters['trace_id'])) {
            return false;
        }

        if ((int) ($filters['document_id'] ?? 0) > 0 && (int) ($entry['document_id'] ?? 0) !== (int) $filters['document_id']) {
            return false;
        }

        if ($filters['document_number'] !== '' && !$this->containsValue($entry['document_number'] ?? '', $filters['document_number'])) {
            return false;
        }

        if ($filters['protocol'] !== '' && !$this->containsValue($entry['protocol'] ?? '', $filters['protocol'])) {
            return false;
        }

        if ($filters['operation'] !== '' && strcasecmp((string) ($entry['operation'] ?? ''), (string) $filters['operation']) !== 0) {
            return false;
        }

        if ($filters['status'] !== '' && strcasecmp((string) ($entry['status'] ?? ''), (string) $filters['status']) !== 0) {
            return false;
        }

        $entryDate = $this->resolveEntryDate($entry);
        if ($filters['date_from'] !== '' && ($entryDate === '' || strcmp($entryDate, (string) $filters['date_from']) < 0)) {
            return false;
        }

        if ($filters['date_to'] !== '' && ($entryDate === '' || strcmp($entryDate, (string) $filters['date_to']) > 0)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function listTraceFiles(int $tenantId): array
    {
        $root = $this->storage->ensureTenantDirectory($tenantId, 'logs');
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $name = trim((string) $fileInfo->getFilename());
            if ($name === '' || !str_ends_with($name, '-trace.json')) {
                continue;
            }

            $files[] = $fileInfo->getPathname();
        }

        usort($files, static function (string $left, string $right): int {
            $leftTime = (int) @filemtime($left);
            $rightTime = (int) @filemtime($right);
            if ($leftTime === $rightTime) {
                return strcmp($right, $left);
            }

            return $rightTime <=> $leftTime;
        });

        return $files;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadTraceSummary(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function findTraceFileById(int $tenantId, string $traceId): ?string
    {
        $traceId = trim($traceId);
        if ($traceId === '' || preg_match('/^[A-Za-z0-9-]+$/', $traceId) !== 1) {
            return null;
        }

        foreach ($this->listTraceFiles($tenantId) as $path) {
            if (str_contains(strtolower(basename($path)), strtolower($traceId . '-trace.json'))) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<int, mixed> $steps
     * @param array<string, mixed> $document
     */
    private function extractProtocol(array $summary, array $steps, array $document): string
    {
        $value = $this->firstPresentScalar([
            $summary['result']['receipt']['ts_protocol'] ?? null,
            $summary['result']['protocol'] ?? null,
            $summary['result']['response']['protocollo'] ?? null,
            $summary['result']['output']['protocollo'] ?? null,
            $document['ts_protocol'] ?? null,
        ]);

        if ($value !== null) {
            return trim((string) $value);
        }

        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $stepValue = $this->firstPresentScalar([
                $this->extractPathValue($step, 'context.protocol'),
                $this->extractPathValue($step, 'context.document.ts_protocol'),
                $this->extractPathValue($step, 'context.output.protocollo'),
                $this->extractPathValue($step, 'context.receipt.ts_protocol'),
            ]);

            if ($stepValue !== null) {
                return trim((string) $stepValue);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<int, mixed> $steps
     * @return array<string, mixed>
     */
    private function extractDocumentContext(array $summary, array $steps): array
    {
        $document = [];
        $candidates = [];

        if (is_array($summary['context']['document'] ?? null)) {
            $candidates[] = $summary['context']['document'];
        }

        if (is_array($summary['result']['document'] ?? null)) {
            $candidates[] = $summary['result']['document'];
        }

        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            if (is_array($step['context']['document'] ?? null)) {
                $candidates[] = $step['context']['document'];
            }

            if (is_array($step['context']['snapshot']['document'] ?? null)) {
                $candidates[] = $step['context']['snapshot']['document'];
            }
        }

        foreach ($candidates as $candidate) {
            $document = $this->mergeContext($document, $candidate);
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<int, mixed> $steps
     */
    private function extractOutcome(array $summary, array $steps): string
    {
        $value = $this->firstPresentScalar([
            $summary['result']['outcome'] ?? null,
            $summary['result']['output']['esitoChiamata'] ?? null,
            $summary['result']['response']['esitoChiamata'] ?? null,
        ]);

        if ($value !== null) {
            return trim((string) $value);
        }

        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $stepValue = $this->firstPresentScalar([
                $this->extractPathValue($step, 'context.outcome'),
                $this->extractPathValue($step, 'context.output.esitoChiamata'),
            ]);

            if ($stepValue !== null) {
                return trim((string) $stepValue);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function guessTimelinePath(array $summary, string $summaryPath): string
    {
        $startedAt = trim((string) ($summary['started_at'] ?? ''));
        $yearMonth = '';
        if ($startedAt !== '' && preg_match('/^(\d{4}-\d{2})-\d{2}/', $startedAt, $matches) === 1) {
            $yearMonth = $matches[1];
        }

        if ($yearMonth === '') {
            return '';
        }

        return dirname($summaryPath) . DIRECTORY_SEPARATOR . 'ts-support-' . $yearMonth . '.jsonl';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function mergeContext(array $payload, array $candidate): array
    {
        foreach ($candidate as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }

            if (is_array($value)) {
                $existing = is_array($payload[$key] ?? null) ? $payload[$key] : [];
                $payload[$key] = $this->mergeContext($existing, $value);
                continue;
            }

            if (!array_key_exists($key, $payload) || $this->isValueEmpty($payload[$key])) {
                $payload[$key] = $value;
                continue;
            }

            if (!$this->isValueEmpty($value)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function extractPathValue($value, string $path)
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('.', $path), static fn(string $segment): bool => trim($segment) !== ''));
        $cursor = $value;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param array<int, mixed> $values
     * @return mixed
     */
    private function firstPresentScalar(array $values)
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function isValueEmpty($value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private function containsValue(string $haystack, string $needle): bool
    {
        return str_contains(mb_strtolower(trim($haystack)), mb_strtolower(trim($needle)));
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function resolveEntryDate(array $entry): string
    {
        $startedAt = trim((string) ($entry['started_at'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $startedAt, $matches) === 1) {
            return $matches[0];
        }

        $lastModifiedAt = trim((string) ($entry['last_modified_at'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $lastModifiedAt, $matches) === 1) {
            return $matches[0];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePrettyJson(array $payload): string
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }
}
