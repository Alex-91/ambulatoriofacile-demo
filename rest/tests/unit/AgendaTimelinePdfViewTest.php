<?php

use CodeIgniter\Test\CIUnitTestCase;

final class AgendaTimelinePdfViewTest extends CIUnitTestCase
{
    public function testBookedTeamAppointmentPaintsTheEntireRowspanCell(): void
    {
        $html = view('agenda/timeline_pdf', [
            'title' => 'Agenda giornaliera team',
            'columns' => [
                [
                    'label' => 'Saletti Virginia',
                    'sub_label' => '02/09/2026',
                    'header_badges' => [],
                ],
            ],
            'rows' => [
                [
                    'time_label' => '09:00',
                    'cells' => [[
                        'rowspan' => 4,
                        'class' => 'is-booked',
                        'time_range' => '09:00 - 10:00',
                        'primary_label' => 'Appuntamento',
                        'secondary_label' => '60 min',
                    ]],
                ],
                ['time_label' => '09:15', 'cells' => [null]],
                ['time_label' => '09:30', 'cells' => [null]],
                ['time_label' => '09:45', 'cells' => [null]],
            ],
            'rowHeightPx' => 48,
            'pageMode' => 'team_day',
        ]);

        self::assertStringContainsString(
            'rowspan="4" class="timeline-cell is-booked"',
            $html
        );
        self::assertMatchesRegularExpression(
            '/\.timeline-cell\.is-booked,\s*\.timeline-cell\.is-booked \.cell-inner\s*\{/',
            $html
        );
    }
}
