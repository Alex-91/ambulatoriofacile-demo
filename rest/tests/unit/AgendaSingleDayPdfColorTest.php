<?php

use App\Controllers\Agenda;
use CodeIgniter\Test\CIUnitTestCase;

final class AgendaSingleDayPdfColorTest extends CIUnitTestCase
{
    private Agenda $controller;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reflection = new ReflectionClass(Agenda::class);
        $this->controller = $this->reflection->newInstanceWithoutConstructor();
    }

    public function testVisitTypeFeatureDisabledLeavesTheAppointmentRowUncolored(): void
    {
        $rows = $this->invoke('buildSingleDayPdfRows', [
            [$this->appointmentSlot()],
            false,
            false,
            [7 => '#F39C12'],
        ]);

        self::assertSame('', $rows[0]['cell_style']);
    }

    public function testVisitTypeFeatureEnabledUsesTheConfiguredColorAndReadableText(): void
    {
        $rows = $this->invoke('buildSingleDayPdfRows', [
            [$this->appointmentSlot()],
            false,
            true,
            [7 => '#F39C12'],
        ]);

        self::assertSame(
            'background-color:#F39C12;color:#1F2D3D;',
            $rows[0]['cell_style']
        );
    }

    public function testColorMapHonorsThePerTypeSlotColorPreference(): void
    {
        $colors = $this->invoke('buildAgendaPdfVisitTypeColorMap', [[
            ['id_tipo_visita' => 7, 'colore' => '#F39C12', 'usa_colore_tipo_visita_slot' => 1],
            ['id_tipo_visita' => 8, 'colore' => '#27AE60', 'usa_colore_tipo_visita_slot' => 0],
            ['id_tipo_visita' => 9, 'colore' => 'invalid', 'usa_colore_tipo_visita_slot' => 1],
        ]]);

        self::assertSame([7 => '#F39C12'], $colors);
    }

    private function appointmentSlot(): array
    {
        return [
            'id_slot' => 10,
            'id_appuntamento' => 20,
            'id_tipo_visita' => 7,
            'tipo_visita_label' => 'Prima visita',
            'appointment_is_primary_slot' => 1,
            'appointment_ora_fine' => '2026-09-02 10:00:00',
            'ora_inizio' => '2026-09-02 09:00:00',
            'ora_fine' => '2026-09-02 09:15:00',
            'stato' => 'PRENOTATO',
            'cognome' => 'ROSSI',
            'nome' => 'MARIO',
            'telefono' => '',
            'cellulare' => '',
            'note' => '',
        ];
    }

    private function invoke(string $methodName, array $arguments)
    {
        $method = $this->reflection->getMethod($methodName);
        return $method->invokeArgs($this->controller, $arguments);
    }
}
