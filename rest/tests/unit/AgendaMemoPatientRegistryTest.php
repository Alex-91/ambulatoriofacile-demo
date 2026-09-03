<?php

use App\Controllers\Agenda;
use CodeIgniter\Test\CIUnitTestCase;

final class AgendaMemoPatientRegistryTest extends CIUnitTestCase
{
    private Agenda $controller;
    private ReflectionMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new ReflectionClass(Agenda::class);
        $this->controller = $reflection->newInstanceWithoutConstructor();
        $this->method = $reflection->getMethod('buildMemoPatientRegistryPayload');
    }

    public function testFeatureDisabledDoesNotPreparePatientSave(): void
    {
        self::assertNull($this->buildPayload([
            'cliente' => 'Rossi Mario',
            'visibile_in_anagrafica' => 1,
        ], false));
    }

    public function testUncheckedPreferenceDoesNotPreparePatientSave(): void
    {
        self::assertNull($this->buildPayload([
            'cliente' => 'Rossi Mario',
            'visibile_in_anagrafica' => 0,
        ], true));
    }

    public function testCheckedPreferenceMapsMemoFieldsToPatientPayload(): void
    {
        self::assertSame([
            'id_paziente' => 42,
            'denominazione' => 'Rossi Mario',
            'telefono' => '051 123456',
            'cellulare' => '333 1234567',
            'indirizzo' => 'Via Roma 1',
            'citta' => 'Bologna',
            'visibile_in_anagrafica' => 1,
        ], $this->buildPayload([
            'id_paziente' => '42',
            'cliente' => '  Rossi Mario  ',
            'telefono' => '  051 123456  ',
            'cellulare' => '  333 1234567 ',
            'indirizzo' => ' Via Roma 1 ',
            'citta' => ' Bologna ',
            'visibile_in_anagrafica' => 'on',
        ], true));
    }

    private function buildPayload(array $payload, bool $featureEnabled): ?array
    {
        /** @var array<string, mixed>|null $result */
        $result = $this->method->invoke($this->controller, $payload, $featureEnabled);
        return $result;
    }
}
