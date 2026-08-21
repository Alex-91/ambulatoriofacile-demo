<?php

use App\Services\FiscalCodeValidator;
use CodeIgniter\Test\CIUnitTestCase;

final class FiscalCodeValidatorTest extends CIUnitTestCase
{
    private FiscalCodeValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new FiscalCodeValidator([
            'p' => [
                ['c' => 'RM', 'n' => 'Roma'],
                ['c' => 'MI', 'n' => 'Milano'],
            ],
            'f' => [
                ['n' => 'ROMA', 'p' => 'RM', 'b' => 'H501', 'd' => '', 'u' => ''],
                ['n' => 'MILANO', 'p' => 'MI', 'b' => 'F205', 'd' => '', 'u' => ''],
            ],
        ]);
    }

    public function testValidatesFormalCodeAndAllAvailablePersonalData(): void
    {
        $result = $this->validator->validate('rss mra 80a01 h501u', [
            'cognome' => 'Rossi',
            'nome' => 'Mario',
            'data_nascita' => '1980-01-01',
            'comune_nascita' => 'Roma',
            'provincia_nascita' => 'RM',
            'sesso' => 'M',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame('RSSMRA80A01H501U', $result['normalized']);
        $this->assertSame([], $result['errors']);
    }

    public function testAcceptsAValidOmocodiaCode(): void
    {
        $result = $this->validator->validate('RSSMRA8LA01H501F', [
            'cognome' => 'Rossi',
            'nome' => 'Mario',
            'data_nascita' => '1980-01-01',
            'comune_nascita' => 'Roma',
            'provincia_nascita' => 'Roma',
        ]);

        $this->assertTrue($result['valid']);
    }

    public function testReportsEveryInconsistentAvailableField(): void
    {
        $result = $this->validator->validate('RSSMRA80A01H501U', [
            'cognome' => 'Bianchi',
            'nome' => 'Luigi',
            'data_nascita' => '1981-02-02',
            'comune_nascita' => 'Milano',
            'provincia_nascita' => 'MI',
            'sesso' => 'F',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Il codice fiscale non è coerente con il cognome inserito.', $result['errors']);
        $this->assertContains('Il codice fiscale non è coerente con il nome inserito.', $result['errors']);
        $this->assertContains('Il codice fiscale non è coerente con la data di nascita inserita.', $result['errors']);
        $this->assertContains('Il codice fiscale non è coerente con il comune o Stato estero di nascita inserito.', $result['errors']);
        $this->assertContains('Il codice fiscale non è coerente con la provincia di nascita inserita.', $result['errors']);
        $this->assertContains('Il codice fiscale non è coerente con il sesso inserito.', $result['errors']);
    }

    public function testRejectsAnInvalidControlCharacter(): void
    {
        $result = $this->validator->validate('RSSMRA80A01H501X');

        $this->assertFalse($result['valid']);
        $this->assertContains('Il carattere di controllo finale non è corretto.', $result['errors']);
    }

    public function testAcceptsValidNumericTaxCodeForANonPhysicalPerson(): void
    {
        $result = $this->validator->validate('12345678903', [
            'denominazione' => 'Organizzazione di prova',
        ]);

        $this->assertTrue($result['valid']);
    }

    public function testRejectsNumericTaxCodeCombinedWithPhysicalPersonData(): void
    {
        $result = $this->validator->validate('12345678903', [
            'cognome' => 'Rossi',
            'nome' => 'Mario',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains(
            'Un codice fiscale numerico di 11 cifre non è coerente con i dati di una persona fisica.',
            $result['errors']
        );
    }

    public function testRejectsInvalidStructure(): void
    {
        $result = $this->validator->validate('CODICE-NON-VALIDO');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }
}
