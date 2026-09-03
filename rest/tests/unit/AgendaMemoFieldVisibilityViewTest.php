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
}
