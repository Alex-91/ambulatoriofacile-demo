<?php

namespace Tests\Unit;

use App\Services\AppointmentMessageTemplateService;
use App\Services\AppointmentNotificationSettingsService;
use CodeIgniter\Test\CIUnitTestCase;

final class AppointmentMessageTemplateServiceTest extends CIUnitTestCase
{
    public function testDefaultConfirmationKeepsCurrentMessageAndOmitsEmptyNotes(): void
    {
        $service = new AppointmentMessageTemplateService();

        $message = $service->render(
            AppointmentNotificationSettingsService::TYPE_PATIENT_BOOKING,
            '',
            [
                'paziente' => 'Mario Rossi',
                'dottore' => 'Dott.ssa Laura Bianchi',
                'data' => '15/09/2026',
                'ora' => '10:30',
                'data_ora' => '15/09/2026 10:30',
                'note' => '',
                'nome_spazio' => 'Poliambulatorio Verdi',
            ]
        );

        $this->assertSame(
            "Gentile Mario Rossi,\nil suo appuntamento è stato registrato con Dott.ssa Laura Bianchi.\nData e ora: 15/09/2026 10:30.\nAmbulatorioFacile",
            $message
        );
    }

    public function testReminderOmitsOptionalLinesWhenTheirValuesAreEmpty(): void
    {
        $service = new AppointmentMessageTemplateService();

        $message = $service->render(
            AppointmentNotificationSettingsService::TYPE_REMINDER,
            '',
            [
                'data' => '15/09/2026',
                'ora' => '10:30',
                'dottore' => 'Dott.ssa Laura Bianchi',
                'sede' => '',
                'istruzioni_conferma' => '',
            ]
        );

        $this->assertSame(
            "Promemoria appuntamento\nData: 15/09/2026 ore 10:30\nDottore: Dott.ssa Laura Bianchi",
            $message
        );
    }

    public function testCustomTemplateRendersSupportedTokens(): void
    {
        $service = new AppointmentMessageTemplateService();
        $template = "Ciao {{paziente}}, ti aspettiamo il {{data}} alle {{ora}}.\n{{nome_spazio}}";

        $service->assertValid(AppointmentNotificationSettingsService::TYPE_REMINDER, $template);

        $this->assertSame(
            "Ciao Mario Rossi, ti aspettiamo il 15/09/2026 alle 10:30.\nPoliambulatorio Verdi",
            $service->render(
                AppointmentNotificationSettingsService::TYPE_REMINDER,
                $template,
                [
                    'paziente' => 'Mario Rossi',
                    'data' => '15/09/2026',
                    'ora' => '10:30',
                    'nome_spazio' => 'Poliambulatorio Verdi',
                ]
            )
        );
    }

    public function testUnknownTokenIsRejected(): void
    {
        $service = new AppointmentMessageTemplateService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('{{codice_sconosciuto}}');

        $service->assertValid(
            AppointmentNotificationSettingsService::TYPE_PATIENT_BOOKING,
            'Ciao {{paziente}}, codice {{codice_sconosciuto}}.'
        );
    }
}
