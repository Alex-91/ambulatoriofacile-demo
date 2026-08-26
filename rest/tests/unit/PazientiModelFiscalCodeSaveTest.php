<?php

use App\Models\PazientiModel;
use CodeIgniter\Test\CIUnitTestCase;

final class PazientiModelFiscalCodeSaveTest extends CIUnitTestCase
{
    public function testNewPatientRequiresConfirmationBeforeUpdatingFiscalCodeMatch(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(PazientiModel::SAVE_REQUIRES_EXISTING_PATIENT_CONFIRMATION);
        $this->expectExceptionMessage('verranno aggiornati sull’anagrafica già esistente');

        $this->resolveSaveTarget(0, false, [
            'id_paziente' => 123,
            'row' => [
                'id_paziente' => 123,
                'cognome' => 'ROSSI',
                'nome' => 'MARIO',
            ],
        ]);
    }

    public function testConfirmedNewPatientUsesExistingFiscalCodeMatch(): void
    {
        $result = $this->resolveSaveTarget(0, true, [
            'id_paziente' => 123,
            'row' => [
                'id_paziente' => 123,
                'denominazione' => 'ROSSI MARIO',
            ],
        ]);

        self::assertSame(123, $result['id_client']);
        self::assertTrue($result['allow_existing_outside_scope']);
    }

    public function testEditingPatientCannotTakeFiscalCodeFromAnotherRecord(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(PazientiModel::SAVE_FISCAL_CODE_CONFLICT);
        $this->expectExceptionMessage('appartiene già a un’altra anagrafica');

        $this->resolveSaveTarget(456, true, [
            'id_paziente' => 123,
            'row' => [
                'id_paziente' => 123,
                'cognome' => 'ROSSI',
                'nome' => 'MARIO',
            ],
        ]);
    }

    public function testExistingFiscalCodeDuplicatesStopAutomaticUpdate(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(PazientiModel::SAVE_FISCAL_CODE_CONFLICT);
        $this->expectExceptionMessage('occorre prima risolvere i doppioni');

        $this->resolveSaveTarget(0, true, [
            'conflict' => true,
            'message' => 'Esistono più pazienti con lo stesso codice fiscale: occorre prima risolvere i doppioni.',
        ]);
    }

    /**
     * @param array<string, mixed> $match
     * @return array{id_client: int, allow_existing_outside_scope: bool}
     */
    private function resolveSaveTarget(int $requestedPatientId, bool $confirmed, array $match): array
    {
        $reflection = new ReflectionClass(PazientiModel::class);
        $model = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolveFiscalCodeSaveTarget');
        $method->setAccessible(true);

        /** @var array{id_client: int, allow_existing_outside_scope: bool} $result */
        $result = $method->invoke(
            $model,
            $requestedPatientId,
            'RSSMRA80A01H501U',
            $match,
            $confirmed
        );

        return $result;
    }
}
