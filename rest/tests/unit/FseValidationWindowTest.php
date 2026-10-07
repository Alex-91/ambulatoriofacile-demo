<?php

namespace Tests\Unit;

use App\Services\FseValidationWindow;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FseValidationWindowTest extends TestCase
{
    public static function timestamps(): array
    {
        return [[null, false], ['', false], ['not-a-date', false], ['2026-02-30 10:00:00', false],
            ['2026-10-07 10:00:01', false], ['2026-10-07 10:00:00', true],
            ['2026-10-02 10:00:01', true], ['2026-10-02 10:00:00', false],
            ['2026-10-02 09:59:59', false], ['2026-10-07 10:00:00 UTC', false], ["2026-10-07\0 0:00:00", false]];
    }

    #[DataProvider('timestamps')]
    public function testStrictFiveDayWindow(?string $stamp, bool $fresh): void
    {
        $this->assertSame($fresh, FseValidationWindow::isFresh(['validated_at' => $stamp], strtotime('2026-10-07 10:00:00')));
    }

    public function testRevalidationOnlyForUnpublishedSignedOriginal(): void
    {
        $doc = ['local_state' => 'signed', 'signed_pdf_path' => 'synthetic', 'validated_at' => null];
        $this->assertTrue(FseValidationWindow::canRevalidate($doc));
        foreach (['published_at' => '2026-10-01 00:00:00', 'deleted_at' => '2026-10-01 00:00:00',
            'previous_document_id' => 1, 'signed_pdf_path' => null] as $key => $value) {
            $this->assertFalse(FseValidationWindow::canRevalidate(array_replace($doc, [$key => $value])));
        }
        foreach (['draft', 'validated', 'rejected', 'validating', 'publishing', 'published', 'deleting', 'deleted'] as $state) {
            $this->assertFalse(FseValidationWindow::canRevalidate(array_replace($doc, ['local_state' => $state])));
        }
    }
}
