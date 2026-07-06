<?php

namespace App\Services;

class TsSupportLogSession
{
    private TsSupportLogService $service;
    private int $tenantId;

    /**
     * @var array<string, mixed>
     */
    private array $baseContext;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $steps = [];

    private string $timelinePath = '';
    private bool $closed = false;
    private float $startedAtMicrotime;

    /**
     * @param array<string, mixed> $baseContext
     */
    public function __construct(TsSupportLogService $service, int $tenantId, array $baseContext)
    {
        $this->service = $service;
        $this->tenantId = $tenantId;
        $this->baseContext = $baseContext;
        $this->startedAtMicrotime = microtime(true);

        $this->step('operation_started', 'Operazione TS avviata.', $baseContext['context'] ?? [], 'info');
    }

    public function getTraceId(): string
    {
        return trim((string) ($this->baseContext['trace_id'] ?? ''));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function step(string $code, string $message, array $context = [], string $level = 'info'): void
    {
        if ($this->closed) {
            return;
        }

        $entry = [
            'trace_id' => $this->getTraceId(),
            'operation' => trim((string) ($this->baseContext['operation'] ?? '')),
            'code' => trim($code),
            'message' => trim($message),
            'level' => trim($level) !== '' ? trim($level) : 'info',
            'elapsed_ms' => $this->elapsedMs(),
            'created_at' => date('c'),
            'context' => $this->service->sanitizeValue($context),
        ];

        $this->steps[] = $entry;
        $this->timelinePath = $this->service->appendTimelineEntry($this->tenantId, $entry);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function finish(string $status, string $message, array $result = [], ?\Throwable $exception = null): array
    {
        if ($this->closed) {
            return $this->service->buildReference($this->getTraceId(), '', $this->timelinePath);
        }

        $status = trim($status) !== '' ? trim($status) : 'info';
        $finishedAt = date('c');
        $summary = [
            'trace_id' => $this->getTraceId(),
            'operation' => trim((string) ($this->baseContext['operation'] ?? '')),
            'status' => $status,
            'message' => trim($message),
            'started_at' => (string) ($this->baseContext['started_at'] ?? ''),
            'finished_at' => $finishedAt,
            'duration_ms' => $this->elapsedMs(),
            'tenant' => $this->baseContext['tenant'] ?? [],
            'runtime' => $this->baseContext['runtime'] ?? [],
            'context' => $this->baseContext['context'] ?? [],
            'steps' => $this->steps,
            'result' => $this->service->sanitizeValue($result),
        ];

        if ($exception instanceof \Throwable) {
            $summary['exception'] = $this->service->sanitizeException($exception);
        }

        $summaryPath = $this->service->storeTraceSummary($this->tenantId, $this->getTraceId(), $summary);
        $reference = $this->service->buildReference($this->getTraceId(), $summaryPath, $this->timelinePath);

        $finalEntry = [
            'trace_id' => $this->getTraceId(),
            'operation' => trim((string) ($this->baseContext['operation'] ?? '')),
            'code' => 'operation_finished',
            'message' => trim($message),
            'level' => in_array($status, ['error', 'warning', 'blocked'], true) ? 'warning' : 'info',
            'status' => $status,
            'elapsed_ms' => $this->elapsedMs(),
            'created_at' => $finishedAt,
            'summary_path' => $summaryPath,
        ];

        $this->timelinePath = $this->service->appendTimelineEntry($this->tenantId, $finalEntry);
        $reference['timeline_path'] = $this->timelinePath;
        $this->closed = true;

        $logLevel = in_array($status, ['error', 'blocked'], true) ? 'error' : 'info';
        log_message(
            $logLevel,
            '[TS Support] operation={operation} trace={trace} status={status} tenant_id={tenantId} message={message} summary={summary}',
            [
                'operation' => trim((string) ($this->baseContext['operation'] ?? '')),
                'trace' => $this->getTraceId(),
                'status' => $status,
                'tenantId' => (int) (($this->baseContext['tenant']['id_tenant'] ?? 0)),
                'message' => trim($message),
                'summary' => $summaryPath,
            ]
        );

        return $reference;
    }

    private function elapsedMs(): int
    {
        return (int) round((microtime(true) - $this->startedAtMicrotime) * 1000);
    }
}
