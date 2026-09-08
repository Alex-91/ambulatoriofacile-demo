<?php

use App\Controllers\Agenda;
use CodeIgniter\Test\CIUnitTestCase;

final class AgendaTeamDayPdfColorTest extends CIUnitTestCase
{
    public function testTeamPdfUsesSingleDayColorsAcrossTheFullAppointment(): void
    {
        $controller = $this->getMockBuilder(Agenda::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveVisibleAppointmentCreatorUsername'])
            ->getMock();
        $controller->method('resolveVisibleAppointmentCreatorUsername')->willReturn('');
        $reflection = new ReflectionClass(Agenda::class);
        $colors = $reflection->getMethod('buildAgendaPdfVisitTypeColorMap')->invoke($controller, [
            ['id_tipo_visita' => 7, 'colore' => '#F39C12'],
            ['id_tipo_visita' => 8, 'colore' => '#123456'],
            ['id_tipo_visita' => 9, 'colore' => '#27AE60', 'usa_colore_tipo_visita_slot' => 0],
        ]);
        $slot = [
            'id_appuntamento' => 20,
            'appointment_is_primary_slot' => 1,
            'id_tipo_visita' => 7,
            'tipo_visita_label' => 'Accesso',
            'ora_inizio' => '2026-09-08 09:00:00',
            'ora_fine' => '2026-09-08 09:15:00',
            'appointment_ora_fine' => '2026-09-08 10:00:00',
            'stato' => 'PRENOTATO',
            'cognome' => 'Paziente',
            'nome' => 'Esempio',
        ];
        $columns = [];
        foreach ([7, 8, 9, 0] as $typeId) {
            $columns[] = ['label' => 'Professionista ' . $typeId, 'has_slots' => true,
                'slots' => [array_replace($slot, ['id_tipo_visita' => $typeId])]];
        }
        $table = $reflection->getMethod('buildTimelinePdfTable')
            ->invoke($controller, $columns, 15, '09:00', '10:00', $colors);
        $cells = $table['rows'][0]['cells'];
        self::assertSame('background-color:#F39C12;color:#1F2D3D;', $cells[0]['cell_style']);
        self::assertSame('background-color:#123456;color:#FFFFFF;', $cells[1]['cell_style']);
        self::assertSame('', $cells[2]['cell_style']);
        self::assertSame('', $cells[3]['cell_style']);
        self::assertSame(4, $cells[0]['rowspan']);
        self::assertSame([null, null, null, null], $table['rows'][1]['cells']);

        $single = $reflection->getMethod('buildSingleDayPdfRows')->invoke($controller, [$slot], false, true, $colors);
        self::assertSame($single[0]['cell_style'], $cells[0]['cell_style']);

        $html = view('agenda/timeline_pdf', [
            'columns' => $columns, 'rows' => $table['rows'], 'pageMode' => 'team_day',
        ]);
        // Both the rowspan cell and its inner box must override the generic booked CSS.
        self::assertStringContainsString('rowspan="4" class="timeline-cell is-booked" style="' . $cells[0]['cell_style'] . '"', $html);
        self::assertStringContainsString('<div class="cell-inner" style="' . $cells[0]['cell_style'] . '">', $html);
        self::assertStringContainsString('<div class="cell-inner" style="' . $cells[1]['cell_style'] . '">', $html);

        $uncolored = $reflection->getMethod('buildTimelinePdfTable')
            ->invoke($controller, $columns, 15, '09:00', '10:00');
        self::assertSame('', $uncolored['rows'][0]['cells'][0]['cell_style']);
    }

    public function testFreeAndBlockedSlotsKeepTheirExistingAppearance(): void
    {
        $reflection = new ReflectionClass(Agenda::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildTimelinePdfSlotCell');
        $slot = ['ora_inizio' => '09:00', 'ora_fine' => '09:15', 'id_tipo_visita' => 7];
        foreach ([['LIBERO', false, 'is-free'], ['CHIUSO', false, 'is-blocked'], ['LIBERO', true, 'is-blocked']] as [$state, $locked, $class]) {
            $cell = $method->invoke($controller, $slot + ['stato' => $state], $locked, 15, 4, [7 => '#F39C12']);
            self::assertSame($class, $cell['class']);
            self::assertEmpty($cell['cell_style'] ?? '');
        }
    }
}
