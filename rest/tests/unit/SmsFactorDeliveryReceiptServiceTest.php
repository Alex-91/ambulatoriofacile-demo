<?php

namespace Tests\Unit;

use App\Services\SmsFactorDeliveryReceiptService;
use CodeIgniter\Test\CIUnitTestCase;

final class SmsFactorDeliveryReceiptServiceTest extends CIUnitTestCase
{
    public function testSignatureMustBeConfiguredAndMatchExactly(): void
    {
        $this->assertTrue(SmsFactorDeliveryReceiptService::signatureMatches('secret-value', 'secret-value'));
        $this->assertFalse(SmsFactorDeliveryReceiptService::signatureMatches('', 'secret-value'));
        $this->assertFalse(SmsFactorDeliveryReceiptService::signatureMatches('secret-value', ''));
        $this->assertFalse(SmsFactorDeliveryReceiptService::signatureMatches('different', 'secret-value'));
    }

    public function testDeliveryReportIsNormalizedAndTenantIsRecovered(): void
    {
        $result = SmsFactorDeliveryReceiptService::normalizePayload([
            'date' => '2026-09-05T10:30:45+02:00',
            'status' => 3,
            'destination' => '+39 333 123 4567',
            'message_id' => 'af-42-0123456789abcdef',
            'campaign_id' => '15948909',
            'country_code' => 'it',
        ]);

        $this->assertSame(42, $result['tenant_id']);
        $this->assertSame('delivered', $result['delivery_status']);
        $this->assertSame('393331234567', $result['destination']);
        $this->assertSame('IT', $result['country_code']);
        $this->assertSame('2026-09-05 10:30:45', $result['occurred_at']);
    }

    public function testUnknownDeliveryStatusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SmsFactorDeliveryReceiptService::normalizePayload([
            'status' => 99,
            'destination' => '393331234567',
            'campaign_id' => '15948909',
        ]);
    }
}
