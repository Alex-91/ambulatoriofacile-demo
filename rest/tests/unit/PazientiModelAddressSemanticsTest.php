<?php

use App\Models\PazientiModel;
use CodeIgniter\Test\CIUnitTestCase;

final class PazientiModelAddressSemanticsTest extends CIUnitTestCase
{
    private PazientiModel $model;
    private ReflectionMethod $resolvePayload;
    private ReflectionMethod $applySemantics;

    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new ReflectionClass(PazientiModel::class);
        $this->model = $reflection->newInstanceWithoutConstructor();
        $this->resolvePayload = $reflection->getMethod('resolvePatientAddressPayload');
        $this->applySemantics = $reflection->getMethod('applyPatientAddressSemantics');
    }

    public function testLegacyMainOnlyIsCopiedToResidenceAndDomicile(): void
    {
        $result = $this->resolve([
            'indirizzo' => 'Via Roma',
            'nr_civico' => '12',
            'citta' => 'Bologna',
            'cap' => '40121',
            'provincia' => 'BO',
        ]);

        self::assertSame('Via Roma', $result['residenza_indirizzo']);
        self::assertSame('12', $result['residenza_nr_civico']);
        self::assertSame('Via Roma', $result['indirizzo_secondario']);
        self::assertSame('12', $result['nr_civico_secondario']);
        self::assertTrue($result['provided_fields']['residenza_indirizzo']);
        self::assertTrue($result['provided_fields']['indirizzo_secondario']);
    }

    public function testLegacyMainBecomesResidenceAndSecondAddressBecomesDomicile(): void
    {
        $result = $this->resolve([
            'indirizzo' => 'Via Residenza',
            'nr_civico' => '1',
            'citta' => 'Modena',
            'cap' => '41121',
            'provincia' => 'MO',
            'indirizzo_secondario' => 'Via Domicilio',
            'nr_civico_secondario' => '2',
            'comune_secondario' => 'Carpi',
            'cap_secondario' => '41012',
            'provincia_secondaria' => 'MO',
        ]);

        self::assertSame('Via Residenza', $result['residenza_indirizzo']);
        self::assertSame('Via Domicilio', $result['indirizzo_secondario']);
        self::assertSame('Carpi', $result['comune_secondario']);
    }

    public function testCompleteNewPayloadSynchronizesLegacyBillingAddress(): void
    {
        $result = $this->resolve([
            'address_payload_complete' => 1,
            'residenza_indirizzo' => 'Via Residenza',
            'residenza_nr_civico' => '7',
            'residenza_comune' => 'Parma',
            'residenza_cap' => '43121',
            'residenza_provincia' => 'PR',
            'domicilio_indirizzo' => 'Via Domicilio',
            'domicilio_nr_civico' => '9',
            'domicilio_comune' => 'Fidenza',
            'domicilio_cap' => '43036',
            'domicilio_provincia' => 'PR',
        ]);

        self::assertSame('Via Residenza', $result['indirizzo']);
        self::assertSame('7', $result['nr_civico']);
        self::assertSame('Via Domicilio', $result['indirizzo_secondario']);
        self::assertTrue($result['provided_fields']['indirizzo']);
    }

    public function testPartialResidenceImportKeepsLegacyConsumersSynchronized(): void
    {
        $result = $this->resolve([
            'residenza_indirizzo' => 'Via Importata',
            'residenza_comune' => 'Ravenna',
        ]);

        self::assertSame('Via Importata', $result['indirizzo']);
        self::assertSame('Ravenna', $result['citta']);
        self::assertTrue($result['provided_fields']['indirizzo']);
        self::assertTrue($result['provided_fields']['citta']);
        self::assertFalse($result['provided_fields']['cap']);
    }

    public function testReadAliasesUseResidenceThenDomicileFallback(): void
    {
        $residence = $this->apply([
            'indirizzo' => 'Via Storica',
            'residenza_indirizzo' => 'Via Residenza',
            'residenza_comune' => 'Ferrara',
            'indirizzo_secondario' => 'Via Domicilio',
            'comune_secondario' => 'Cento',
        ]);
        self::assertSame('Via Residenza', $residence['indirizzo']);
        self::assertSame('Ferrara', $residence['citta']);
        self::assertSame('Via Domicilio', $residence['domicilio_indirizzo']);

        $domicile = $this->apply([
            'indirizzo' => '',
            'residenza_indirizzo' => '',
            'residenza_comune' => '',
            'indirizzo_secondario' => 'Via Domicilio',
            'comune_secondario' => 'Cento',
        ]);
        self::assertSame('Via Domicilio', $domicile['indirizzo']);
        self::assertSame('Cento', $domicile['citta']);
    }

    /** @return array<string, mixed> */
    private function resolve(array $payload): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->resolvePayload->invoke($this->model, $payload);
        return $result;
    }

    /** @return array<string, mixed> */
    private function apply(array $row): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->applySemantics->invoke($this->model, $row);
        return $result;
    }
}
