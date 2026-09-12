<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/** Deterministic planning: no database writes and no gateway calls. */
final class WhatsAppCampaignPlan
{
    public const INTERVAL_SECONDS = 600;
    public const TIMEZONE = 'Europe/Rome';

    public function __construct(private ?DateTimeImmutable $clock = null) {}

    public function now(): DateTimeImmutable
    {
        return ($this->clock ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(self::TIMEZONE));
    }

    public function isOpen(DateTimeImmutable $at): bool
    {
        $time = $at->setTimezone(new DateTimeZone(self::TIMEZONE))->format('H:i:s');
        return $time >= '07:30:00' && $time < '22:30:00';
    }

    public function nextSlot(DateTimeImmutable $at): DateTimeImmutable
    {
        $at = $at->setTimezone(new DateTimeZone(self::TIMEZONE));
        $open = $at->setTime(7, 30);
        if ($at <= $open) {
            return $open;
        }
        $steps = (int) ceil(($at->getTimestamp() - $open->getTimestamp()) / self::INTERVAL_SECONDS);
        $slot = $open->modify('+' . ($steps * self::INTERVAL_SECONDS) . ' seconds');
        return $slot < $at->setTime(22, 30) ? $slot : $open->modify('+1 day');
    }

    public function estimateCompletion(int $count, DateTimeImmutable $earliest, int $spacing, int $dailyLimit, int $usedToday = 0, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now = ($now ?? $this->now())->setTimezone(new DateTimeZone(self::TIMEZONE));
        $slot = $this->nextSlot($earliest);
        $day = $now->format('Y-m-d');
        $used = max(0, $usedToday);
        $spacing = max(self::INTERVAL_SECONDS, $spacing);
        $dailyLimit = max(1, $dailyLimit);
        for ($i = 0; $i < max(1, $count); $i++) {
            if ($slot->format('Y-m-d') !== $day) {
                $day = $slot->format('Y-m-d');
                $used = 0;
            }
            if ($used >= $dailyLimit) {
                $slot = $slot->modify('+1 day')->setTime(7, 30);
                $day = $slot->format('Y-m-d');
                $used = 0;
            }
            $used++;
            if ($i + 1 < $count) {
                $slot = $this->nextSlot($slot->modify('+' . $spacing . ' seconds'));
            }
        }
        return $slot;
    }

    /**
     * Rows already contain normalized phone numbers. Sort before deduplicating so
     * a shared family number inherits the earliest eligible appointment.
     * @return array{recipients:array,summary:array}
     */
    public function build(array $rows, DateTimeImmutable $now, DateTimeImmutable $earliest, int $spacing, int $dailyLimit, int $usedToday = 0, int $ahead = 0): array
    {
        $now = $now->setTimezone(new DateTimeZone(self::TIMEZONE));
        $count = count(array_unique(array_column($rows, 'phone')));
        $end = $this->estimateCompletion($count + $ahead, $earliest, $spacing, $dailyLimit, $usedToday, $now);
        $cutoff = $end->format('Y-m-d');
        $collator = new \Collator('it_IT');
        $collator->setStrength(\Collator::SECONDARY);
        foreach ($rows as &$row) {
            $appointment = (string) ($row['next_appointment_at'] ?? '');
            $row['_priority'] = $appointment !== '' && $appointment >= $now->format('Y-m-d H:i:s') && substr($appointment, 0, 10) <= $cutoff;
        }
        unset($row);
        usort($rows, static function (array $a, array $b) use ($collator): int {
            $comparison = (int) $b['_priority'] <=> (int) $a['_priority'];
            if ($comparison !== 0) { return $comparison; }
            if ($a['_priority']) {
                $comparison = strcmp((string) $a['next_appointment_at'], (string) $b['next_appointment_at']);
                if ($comparison !== 0) { return $comparison; }
            }
            foreach (['sort_surname', 'sort_name', 'patient_name'] as $key) {
                $comparison = $collator->compare((string) ($a[$key] ?? ''), (string) ($b[$key] ?? ''));
                if ($comparison) { return $comparison; }
            }
            return ((int) ($a['id_client'] ?? 0) <=> (int) ($b['id_client'] ?? 0)) ?: strcmp($a['phone'], $b['phone']);
        });
        $unique = [];
        $priorityCount = 0;
        foreach ($rows as $row) {
            if (isset($unique[$row['phone']])) { continue; }
            $priorityCount += (int) $row['_priority'];
            unset($row['_priority']);
            $row['send_order'] = count($unique) + 1;
            $unique[$row['phone']] = $row;
        }
        return ['recipients' => array_values($unique), 'summary' => [
            'version' => 1,
            'planned_at' => $now->format(DATE_ATOM),
            'cutoff_date' => $cutoff,
            'estimated_completion_at' => $end->format(DATE_ATOM),
            'timezone' => self::TIMEZONE,
            'spacing_seconds' => max(self::INTERVAL_SECONDS, $spacing),
            'daily_limit' => $dailyLimit,
            'recipients_ahead' => $ahead,
            'planned_recipients' => count($unique),
            'priority_recipients' => $priorityCount,
            'other_recipients' => count($unique) - $priorityCount,
        ]];
    }
}
