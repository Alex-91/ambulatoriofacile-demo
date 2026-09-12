<?php

use CodeIgniter\Test\CIUnitTestCase;

final class AgendaMemoFieldVisibilityViewTest extends CIUnitTestCase
{
    public function testAgendaPopupKeepsEveryConfigurableInputInTheDom(): void
    {
        $source = file_get_contents(APPPATH . 'Views/agenda/index.php');
        self::assertIsString($source);

        foreach ([
            'validity_date' => 'nota_data_inizio_validita',
            'phone' => 'nota_telefono',
            'mobile' => 'nota_cellulare',
            'address' => 'nota_indirizzo',
            'city' => 'nota_citta',
            'patient_registry' => 'nota_visibile_in_anagrafica',
            'notes' => 'nota_note',
            'completed' => 'nota_fatta',
        ] as $fieldKey => $inputId) {
            self::assertStringContainsString("agendaMemoFieldIsVisible('{$fieldKey}')", $source);
            self::assertStringContainsString('id="' . $inputId . '"', $source);
        }

        self::assertStringContainsString("$('#nota_telefono').val(row.telefono || '');", $source);
        self::assertStringContainsString("telefono: $('#nota_telefono').val(),", $source);
    }

    public function testTenantMasterPagePostsTheMemoVisibilitySelection(): void
    {
        $source = file_get_contents(APPPATH . 'Views/tenant/space_features.php');
        self::assertIsString($source);

        self::assertStringContainsString('name="agenda_memo_field_visibility_form"', $source);
        self::assertStringContainsString('name="agenda_memo_field_visibility_enabled"', $source);
        self::assertStringContainsString('name="agenda_memo_visible_fields[]"', $source);
    }

    public function testActiveMemoCardsUseTheSameVisibilityMapAsThePopup(): void
    {
        $source = file_get_contents(APPPATH . 'Views/agenda/index.php');
        self::assertIsString($source);

        self::assertStringContainsString('memoFieldVisibility:', $source);
        self::assertStringContainsString('function isAgendaMemoFieldVisible(fieldKey)', $source);

        foreach (['validity_date', 'phone', 'mobile', 'address', 'city', 'notes', 'completed'] as $fieldKey) {
            self::assertStringContainsString("isAgendaMemoFieldVisible('{$fieldKey}')", $source);
        }
    }

    public function testHistoryAndPdfReceiveAndApplyMemoVisibilitySettings(): void
    {
        $controllerSource = file_get_contents(APPPATH . 'Controllers/Agenda.php');
        $historySource = file_get_contents(APPPATH . 'Views/agenda/storico_memo.php');
        $pdfSource = file_get_contents(APPPATH . 'Views/agenda/memo_pdf.php');
        self::assertIsString($controllerSource);
        self::assertIsString($historySource);
        self::assertIsString($pdfSource);

        self::assertGreaterThanOrEqual(
            2,
            substr_count($controllerSource, "'agendaMemoFieldVisibilitySettings' => \$this->getCurrentAgendaMemoFieldVisibilitySettings()")
        );

        foreach (['validity_date', 'phone', 'mobile', 'address', 'city', 'notes', 'completed'] as $fieldKey) {
            self::assertStringContainsString("\$agendaMemoFieldIsVisible('{$fieldKey}')", $historySource);
        }

        foreach (['validity_date', 'phone', 'mobile', 'address', 'city', 'notes'] as $fieldKey) {
            self::assertStringContainsString("\$agendaMemoFieldIsVisible('{$fieldKey}')", $pdfSource);
        }
    }

    public function testMemoPdfDoesNotRenderFieldsConfiguredAsHidden(): void
    {
        $html = view('agenda/memo_pdf', [
            'doctorLabel' => 'Mario Rossi',
            'generatedAt' => '09/09/2026 12:00',
            'todayLabel' => '09/09/2026',
            'totalNotes' => 1,
            'notes' => [[
                'cliente_label' => 'Cliente Prova',
                'telefono' => '02123456',
                'cellulare' => '3391234567',
                'indirizzo' => 'Via Roma 1',
                'citta' => 'Milano',
                'note' => 'Nota riservata',
                'data_validita_label' => '10/09/2026',
                'created_at_label' => '09/09/2026 11:30',
                'created_by_username' => 'utente.prova',
                'status_class' => 'status-futura',
                'status_badge_class' => 'badge-futura',
                'status_label' => 'Futura',
            ]],
            'agendaMemoFieldVisibilitySettings' => [
                'effective_field_visibility' => [
                    'validity_date' => false,
                    'phone' => false,
                    'mobile' => true,
                    'address' => false,
                    'city' => false,
                    'notes' => false,
                ],
            ],
        ]);

        self::assertStringContainsString('<th>Cellulare</th>', $html);
        self::assertStringContainsString('3391234567', $html);
        self::assertStringNotContainsString('<th>Telefono</th>', $html);
        self::assertStringNotContainsString('02123456', $html);
        self::assertStringNotContainsString('<th>Indirizzo</th>', $html);
        self::assertStringNotContainsString('Via Roma 1', $html);
        self::assertStringNotContainsString('<th>Città</th>', $html);
        self::assertStringNotContainsString('Milano', $html);
        self::assertStringNotContainsString('<th>Note</th>', $html);
        self::assertStringNotContainsString('Nota riservata', $html);
        self::assertStringNotContainsString('Valida dal:', $html);
        self::assertStringNotContainsString('Riferimento stato:', $html);
        self::assertStringNotContainsString('Futura', $html);
    }
}
