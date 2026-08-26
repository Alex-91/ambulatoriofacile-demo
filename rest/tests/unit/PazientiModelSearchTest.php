<?php

use App\Models\PazientiModel;
use CodeIgniter\Test\CIUnitTestCase;

final class PazientiModelSearchTest extends CIUnitTestCase
{
    public function testFallbackSearchSplitsSurnameAndNameInitialIntoTokens(): void
    {
        [$sql, $params] = $this->buildSearch('  BALSARIN   C  ');

        self::assertSame([
            '%balsarin%',
            '%balsarin%',
            '%c%',
            '%c%',
            '%balsarin c%',
            '%balsarin c%',
            '%balsarin c%',
            '%balsarin c%',
            '%balsarin c%',
        ], $params);
        self::assertSame(2, substr_count($sql, "c.cognome"));
        self::assertSame(2, substr_count($sql, "c.nome"));
        self::assertStringContainsString(")\n                    AND (", $sql);
    }

    public function testFallbackSearchKeepsSingleTermContactLookup(): void
    {
        [$sql, $params] = $this->buildSearch('3403204666');

        self::assertCount(7, $params);
        self::assertSame(['%3403204666%'], array_values(array_unique($params)));
        self::assertStringContainsString('c.codice_fiscale', $sql);
        self::assertStringContainsString('c.telefono', $sql);
        self::assertStringContainsString('c.cellulare', $sql);
        self::assertStringContainsString('c.email', $sql);
        self::assertStringContainsString('c.paz_spec', $sql);
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function buildSearch(string $term): array
    {
        $reflection = new \ReflectionClass(PazientiModel::class);
        $model = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildPatientSearchWhereSql');
        $method->setAccessible(true);

        /** @var array{0:string,1:array<int,string>} $result */
        $result = $method->invoke($model, 'c', $term);
        return $result;
    }
}
