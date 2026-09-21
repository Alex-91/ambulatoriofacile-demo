<?php

namespace Tests\Unit;

use App\Services\WhatsAppCampaignPlan;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class WhatsAppCampaignPlanTest extends TestCase
{
    private function at(string $value): DateTimeImmutable { return new DateTimeImmutable($value, new DateTimeZone('Europe/Rome')); }

    public function testTenMinuteCampaignResumesEveryMorningAndHasExpectedCompletion(): void
    {
        $plan = new WhatsAppCampaignPlan();
        $start = $this->at('2026-09-12 07:30:00');
        $this->assertSame('2026-09-26 14:00', $plan->estimateCompletion(1300, $start, 600, 250, 0, $start)->format('Y-m-d H:i'));
        $this->assertSame('2026-09-12 15:00', $plan->estimateCompletion(91, $start, 300, 250, 0, $start)->format('Y-m-d H:i'));
        $this->assertSame('2026-09-13 07:30', $plan->estimateCompletion(181, $start, 300, 250, 0, $start)->format('Y-m-d H:i'));
    }

    public function testTenantSpacingSupportsSubMinuteAndNonRoundIntervals(): void
    {
        $plan = new WhatsAppCampaignPlan();
        $start = $this->at('2026-09-12 07:33:17');
        foreach ([30, 90, 300, 900] as $spacing) {
            $result = $plan->build([$this->row(1, 'Alfa'), $this->row(2, 'Beta')], $start, $start, $spacing, 250);
            $this->assertSame($spacing, $result['summary']['spacing_seconds']);
            $this->assertSame($start->modify('+' . $spacing . ' seconds')->format(DATE_ATOM), $result['summary']['estimated_completion_at']);
        }
        $this->assertSame('2026-09-13 07:30:00', $plan->estimateCompletion(2, $this->at('2026-09-12 22:29:40'), 30, 250)->format('Y-m-d H:i:s'));
    }

    public function testPartialDayRateLimitAndPreviouslyQueuedRecipientsAffectCutoff(): void
    {
        $plan = new WhatsAppCampaignPlan();
        $now = $this->at('2026-09-12 21:00');
        $this->assertSame('2026-09-13 07:30', $plan->estimateCompletion(2, $now, 600, 90, 89, $now)->format('Y-m-d H:i'));
        $result = $plan->build([$this->row(1, 'Alfa')], $this->at('2026-09-12 07:00'), $this->at('2026-09-12 07:00'), 600, 250, 0, 90);
        $this->assertSame('2026-09-13', $result['summary']['cutoff_date']);
        $this->assertSame(90, $result['summary']['recipients_ahead']);
    }

    public function testItalianWindowBoundariesAndDaylightSaving(): void
    {
        $plan = new WhatsAppCampaignPlan();
        foreach (['2026-09-12T05:30:00Z', '2026-12-12T06:30:00Z'] as $time) {
            $this->assertTrue($plan->isOpen(new DateTimeImmutable($time)));
        }
        foreach (['2026-09-12T05:29:59Z', '2026-09-12T20:30:00Z', '2026-12-12T21:30:00Z'] as $time) {
            $this->assertFalse($plan->isOpen(new DateTimeImmutable($time)));
        }
        $slot = $plan->nextSlot($this->at('2026-10-24 23:00'));
        $this->assertSame('2026-10-25T07:30:00+01:00', $slot->format(DATE_ATOM));
        $this->assertSame('2026-09-13 07:30', $plan->nextSlot($this->at('2026-09-12 22:30'))->format('Y-m-d H:i'));
    }

    public function testAppointmentsWithinFixedCutoffComeFirstThenEverybodyElseAlphabetically(): void
    {
        $now = $this->at('2026-09-12 07:30');
        $rows = [
            $this->row(1, 'Alfa', '2026-09-20 09:00:00'),
            $this->row(2, 'Zeta', '2026-09-12 12:00:00'),
            $this->row(3, 'Beta'),
            $this->row(4, 'Gamma', '2026-09-11 10:00:00'),
            $this->row(5, 'Verdi', '2026-09-12 09:00:00'),
        ];
        $result = (new WhatsAppCampaignPlan())->build($rows, $now, $now, 600, 250);
        $this->assertSame([5, 2, 1, 3, 4], array_column($result['recipients'], 'id_client'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($result['recipients'], 'send_order'));
        $this->assertSame('2026-09-12', $result['summary']['cutoff_date']);
        $this->assertSame(2, $result['summary']['priority_recipients']);
        $this->assertSame(3, $result['summary']['other_recipients']);
    }

    public function testSharedNumberUsesEarliestAppointmentAndCountsOnlyOneRecipient(): void
    {
        $now = $this->at('2026-09-12 07:30');
        $rows = [$this->row(1, 'Alfa'), $this->row(2, 'Zeta', '2026-09-12 10:00:00'), $this->row(3, 'Beta', '2026-09-12 09:00:00')];
        $rows[1]['phone'] = $rows[0]['phone'];
        $result = (new WhatsAppCampaignPlan())->build($rows, $now, $now, 600, 250);
        $this->assertSame([3, 2], array_column($result['recipients'], 'id_client'));
        $this->assertSame(2, $result['summary']['planned_recipients']);
        $this->assertSame('2026-09-12T07:40:00+02:00', $result['summary']['estimated_completion_at']);
    }

    private function row(int $id, string $surname, ?string $appointment = null): array
    {
        return ['id_client' => $id, 'phone' => '+39300000000' . $id, 'sort_surname' => $surname, 'sort_name' => 'Test', 'patient_name' => 'Test ' . $surname, 'next_appointment_at' => $appointment];
    }
}
