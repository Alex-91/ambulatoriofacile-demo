<?php

namespace App\Services;

/** Read-only campaign admission check; never dispatches a reminder. */
final class AppointmentReminderPriorityService
{
    private \Closure $probe;
    private string $stateDir;

    public function __construct(?\Closure $probe = null, ?string $stateDir = null)
    {
        $this->probe = $probe ?? static fn(array $options): array =>
            (new AppointmentReminderDispatchService())->run($options);
        $this->stateDir = $stateDir ?? (new TenantStoragePathService())->globalReminderStateDir();
    }

    public function hasPending(int $tenantId, ?\DateTimeImmutable $now = null): bool
    {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('Europe/Rome'));
        $today = $now->format('Y-m-d');
        $dates = [];
        $todayCompleted = false;
        foreach (glob($this->stateDir . DIRECTORY_SEPARATOR . 'appointment_reminder_scheduler_*.json') ?: [] as $file) {
            if (!preg_match('/_(\d{4}-\d{2}-\d{2})\.json$/', $file, $matches) || $matches[1] > $today) {
                continue;
            }
            $state = json_decode((string) file_get_contents($file), true);
            if (!is_array($state)) {
                throw new \RuntimeException('Stato scheduler reminder non leggibile.');
            }
            if (($state['status'] ?? '') !== 'completed') {
                $dates[$matches[1]] = true;
            } elseif ($matches[1] === $today) {
                $todayCompleted = true;
            }
        }
        // Reserve today's capacity even before 08:00. During the collection
        // window, newly added appointments still take precedence over campaigns.
        if (!$todayCompleted || (int) $now->format('H') < 9) {
            $dates[$today] = true;
        }
        foreach (array_keys($dates) as $date) {
            $summary = ($this->probe)([
                'send' => false,
                'pending_only' => true,
                'tenant_id' => $tenantId,
                'reference_date' => $date,
            ]);
            foreach ((array) ($summary['tenants'] ?? []) as $tenant) {
                if (!empty($tenant['error'])) {
                    throw new \RuntimeException('Verifica priorità reminder non disponibile.');
                }
                if ((int) ($tenant['pending'] ?? 0) > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function isFutureAppointment(string $date, string $time, ?\DateTimeImmutable $now = null): bool
    {
        $zone = new \DateTimeZone('Europe/Rome');
        $value = $date . ' ' . substr($time, 0, 5);
        $appointment = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, $zone);
        return $appointment !== false && $appointment->format('Y-m-d H:i') === $value
            && $appointment > ($now ?? new \DateTimeImmutable('now', $zone));
    }
}
