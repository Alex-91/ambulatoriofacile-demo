<?php

namespace App\Services;

final class AppointmentReminderScheduledRunService
{
    public const TIMEZONE = 'Europe/Rome';
    public const START_HOUR = 8;
    public const START_WINDOW_MINUTES = 60;

    private \Closure $dispatcher;
    private string $stateDir;
    private string $lockDir;

    public function __construct(
        ?\Closure $dispatcher = null,
        ?string $stateDir = null,
        ?string $lockDir = null
    ) {
        $this->dispatcher = $dispatcher ?? static fn(array $options): array =>
            (new AppointmentReminderDispatchService())->run($options);
        $this->stateDir = $stateDir
            ?? (new TenantStoragePathService())->globalReminderStateDir();
        $this->lockDir = $lockDir
            ?? (rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'locks');
    }

    /**
     * Runs the oldest unfinished daily reminder batch. When no backlog exists,
     * today's batch becomes eligible at 08:00 Europe/Rome.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $this->ensureDirectory($this->stateDir);
        $this->ensureDirectory($this->lockDir);

        $lockPath = $this->lockDir . DIRECTORY_SEPARATOR . 'appointment_reminders_scheduler.lock';
        $lockHandle = fopen($lockPath, 'c+');
        if ($lockHandle === false) {
            throw new \RuntimeException('Impossibile aprire il lock dello scheduler reminder.');
        }
        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return [
                'ok' => true,
                'status' => 'already_running',
                'message' => 'Un batch reminder è già in esecuzione.',
            ];
        }

        $referenceDate = null;
        $statePath = null;
        $attempt = 0;

        try {
            $timezone = new \DateTimeZone(self::TIMEZONE);
            $now = $this->resolveNow($options['now'] ?? null, $timezone);
            $today = $now->format('Y-m-d');
            $dryRun = !empty($options['dry_run']);
            $force = !empty($options['force']);
            $requestedReferenceDate = $this->normalizeDate((string) ($options['reference_date'] ?? ''));

            if ($dryRun) {
                $referenceDate = $requestedReferenceDate ?: $today;
                $summary = ($this->dispatcher)([
                    'send' => false,
                    'reference_date' => $referenceDate,
                ]);

                return [
                    'ok' => true,
                    'status' => 'dry_run',
                    'reference_date' => $referenceDate,
                    'timezone' => self::TIMEZONE,
                    'summary' => $summary,
                ];
            }

            $referenceDate = $requestedReferenceDate ?: $this->oldestIncompleteReferenceDate($today);
            if ($referenceDate === null) {
                if (!$force && !$this->isInsideStartWindow($now)) {
                    return [
                        'ok' => true,
                        'status' => 'not_due',
                        'reference_date' => $today,
                        'timezone' => self::TIMEZONE,
                        'next_run_at' => $this->nextStartAt($now)->format('Y-m-d H:i:s'),
                    ];
                }
                $referenceDate = $today;
            }

            $statePath = $this->statePath($referenceDate);
            $state = $this->loadState($statePath);
            $canRecheckCompletedToday = $requestedReferenceDate === null
                && $referenceDate === $today
                && $this->isInsideStartWindow($now);
            if (($state['status'] ?? '') === 'completed' && !$force && !$canRecheckCompletedToday) {
                return [
                    'ok' => true,
                    'status' => 'already_completed',
                    'reference_date' => $referenceDate,
                    'timezone' => self::TIMEZONE,
                    'completed_at' => $state['completed_at'] ?? null,
                ];
            }

            $attempt = max(0, (int) ($state['attempt'] ?? 0)) + 1;
            $this->saveState($statePath, [
                'status' => 'running',
                'reference_date' => $referenceDate,
                'timezone' => self::TIMEZONE,
                'scheduled_start' => '08:00',
                'attempt' => $attempt,
                'started_at' => $now->format(DATE_ATOM),
                'updated_at' => $now->format(DATE_ATOM),
            ]);

            $summary = ($this->dispatcher)([
                'send' => true,
                'reference_date' => $referenceDate,
            ]);
            $tenantErrors = $this->tenantErrors((array) ($summary['tenants'] ?? []));
            $completed = (int) ($summary['failed'] ?? 0) === 0
                && (int) ($summary['deferred'] ?? 0) === 0
                && $tenantErrors === [];
            $finishedAt = new \DateTimeImmutable('now', $timezone);
            $status = $completed ? 'completed' : 'retry_required';

            $this->saveState($statePath, [
                'status' => $status,
                'reference_date' => $referenceDate,
                'timezone' => self::TIMEZONE,
                'scheduled_start' => '08:00',
                'attempt' => $attempt,
                'started_at' => (string) ($state['started_at'] ?? $now->format(DATE_ATOM)),
                'updated_at' => $finishedAt->format(DATE_ATOM),
                'completed_at' => $completed ? $finishedAt->format(DATE_ATOM) : null,
                'summary' => $this->compactSummary($summary),
                'tenant_errors' => $tenantErrors,
            ]);

            return [
                'ok' => $completed,
                'status' => $status,
                'reference_date' => $referenceDate,
                'timezone' => self::TIMEZONE,
                'attempt' => $attempt,
                'summary' => $summary,
                'tenant_errors' => $tenantErrors,
            ];
        } catch (\Throwable $e) {
            if ($referenceDate !== null && $statePath !== null) {
                $failedState = $this->loadState($statePath);
                $this->saveState($statePath, array_merge($failedState, [
                    'status' => 'retry_required',
                    'reference_date' => $referenceDate,
                    'timezone' => self::TIMEZONE,
                    'scheduled_start' => '08:00',
                    'attempt' => $attempt,
                    'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format(DATE_ATOM),
                    'error' => mb_substr($e->getMessage(), 0, 2000),
                ]));
            }
            throw $e;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function resolveNow($value, \DateTimeZone $timezone): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return (new \DateTimeImmutable($value->format(DATE_ATOM)))->setTimezone($timezone);
        }

        return new \DateTimeImmutable('now', $timezone);
    }

    private function isInsideStartWindow(\DateTimeImmutable $now): bool
    {
        $start = $now->setTime(self::START_HOUR, 0);
        $end = $start->modify('+' . self::START_WINDOW_MINUTES . ' minutes');

        return $now >= $start && $now < $end;
    }

    private function nextStartAt(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $start = $now->setTime(self::START_HOUR, 0);

        return $now < $start ? $start : $start->modify('+1 day');
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(self::TIMEZONE));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('La data di riferimento deve essere nel formato YYYY-MM-DD.');
        }

        return $value;
    }

    private function oldestIncompleteReferenceDate(string $today): ?string
    {
        $dates = [];
        $files = glob($this->stateDir . DIRECTORY_SEPARATOR . 'appointment_reminder_scheduler_*.json') ?: [];
        foreach ($files as $file) {
            $basename = basename($file);
            if (preg_match('/^appointment_reminder_scheduler_(\d{4}-\d{2}-\d{2})\.json$/', $basename, $matches) !== 1) {
                continue;
            }
            $date = (string) $matches[1];
            if ($date > $today) {
                continue;
            }
            $state = $this->loadState($file);
            if (($state['status'] ?? '') !== 'completed') {
                $dates[] = $date;
            }
        }

        sort($dates, SORT_STRING);
        return $dates[0] ?? null;
    }

    /** @param array<int, array<string, mixed>> $tenants */
    private function tenantErrors(array $tenants): array
    {
        $errors = [];
        foreach ($tenants as $tenant) {
            $message = trim((string) ($tenant['error'] ?? ''));
            if ($message === '') {
                continue;
            }
            $errors[] = [
                'tenant_id' => (int) ($tenant['tenant_id'] ?? 0),
                'tenant_name' => (string) ($tenant['tenant_name'] ?? ''),
                'error' => mb_substr($message, 0, 2000),
            ];
        }

        return $errors;
    }

    /** @param array<string, mixed> $summary */
    private function compactSummary(array $summary): array
    {
        return array_intersect_key($summary, array_flip([
            'mode',
            'tenant_count',
            'processed_tenants',
            'candidates',
            'sent',
            'failed',
            'deferred',
            'already_sent',
            'invalid_recipient',
        ]));
    }

    private function statePath(string $referenceDate): string
    {
        return $this->stateDir . DIRECTORY_SEPARATOR . 'appointment_reminder_scheduler_' . $referenceDate . '.json';
    }

    /** @return array<string, mixed> */
    private function loadState(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $json = file_get_contents($path);
        if (!is_string($json) || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $state */
    private function saveState(string $path, array $state): void
    {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || file_put_contents($path, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Salvataggio dello stato scheduler reminder non riuscito.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Impossibile creare la cartella scheduler reminder: ' . $path);
        }
    }
}
