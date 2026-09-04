<?php

use App\Services\PatientExcelImportService;
use CodeIgniter\Test\CIUnitTestCase;

final class PatientExcelImportAddressFieldsTest extends CIUnitTestCase
{
    public function testImportOffersOnlyResidenceAndDomicileAddressTargets(): void
    {
        $definitions = (new PatientExcelImportService())->getTargetFieldDefinitions();

        foreach ([
            'residenza_indirizzo',
            'residenza_nr_civico',
            'residenza_comune',
            'residenza_cap',
            'residenza_provincia',
            'domicilio_indirizzo',
            'domicilio_nr_civico',
            'domicilio_comune',
            'domicilio_cap',
            'domicilio_provincia',
        ] as $field) {
            self::assertArrayHasKey($field, $definitions);
        }

        foreach ([
            'indirizzo',
            'nr_civico',
            'citta',
            'cap',
            'provincia',
            'indirizzo_secondario',
            'nr_civico_secondario',
            'comune_secondario',
            'cap_secondario',
            'provincia_secondaria',
        ] as $legacyField) {
            self::assertArrayNotHasKey($legacyField, $definitions);
        }
    }

    public function testLegacyExcelHeadersMapToNewSemanticFields(): void
    {
        $service = new PatientExcelImportService();
        $method = new ReflectionMethod(PatientExcelImportService::class, 'suggestFieldForHeader');

        self::assertSame('residenza_indirizzo', $method->invoke($service, 'indirizzo'));
        self::assertSame('domicilio_indirizzo', $method->invoke($service, '2o indirizzo'));
    }
}
