<?php

use CodeIgniter\Test\CIUnitTestCase;

final class AgendaMemoAutocompleteViewTest extends CIUnitTestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $source = file_get_contents(APPPATH . 'Views/agenda/index.php');
        self::assertIsString($source);
        $this->source = $source;
    }

    public function testMemoSelectionUsesThePatientReturnedByTheApi(): void
    {
        self::assertStringContainsString('var notePatientAutocompleteRows = {};', $this->source);
        self::assertStringContainsString('notePatientAutocompleteRows[patientId] = row;', $this->source);
        self::assertStringContainsString('var patient = notePatientAutocompleteRows[idPaziente];', $this->source);
        self::assertStringContainsString("$('#nota_telefono').val(patient.telefono || '');", $this->source);
        self::assertStringContainsString("$('#nota_cellulare').val(patient.cellulare || '');", $this->source);
    }

    public function testAppointmentAutocompleteHandlerIsScopedToItsOwnResults(): void
    {
        self::assertStringContainsString(
            "$(document).on('click', '#patientAutocomplete .agenda-autocomplete-item'",
            $this->source
        );
        self::assertStringNotContainsString(
            "$(document).on('click', '.agenda-autocomplete-item'",
            $this->source
        );
    }

    public function testHiddenAutocompleteContainersCannotRenderAsEmptyLines(): void
    {
        self::assertStringContainsString('.agenda-autocomplete.d-none,', $this->source);
        self::assertStringContainsString('.agenda-autocomplete-vd.d-none {', $this->source);
        self::assertStringContainsString('display: none !important;', $this->source);
    }
}
