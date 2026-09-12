<?php

namespace Tests\Unit;

use App\Config\Fse2;
use App\Services\FseGatewayClient;
use App\Services\FseGatewayPayload;
use App\Services\FseGatewayTransport;
use App\Services\FseJwtService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FseGatewayPayloadTest extends CIUnitTestCase
{
    private function profile(): array
    {
        return ['facility_type' => 'Territorio', 'organizational_setting' => 'AD_PSC001',
            'document_oid_root' => '1.2.3', 'submission_oid_root' => '1.2.4', 'repository_id' => '1.2.5'];
    }

    private function document(): array
    {
        return ['service_start' => '2026-09-11 10:00:00', 'document_unique_id' => 'TEST.2',
            'submission_id' => 'TEST.SUB.2', 'administrative_request' => 'SSR'];
    }

    public function testValidPayloadPreservesWallTimeAndUsesNationalArrayContract(): void
    {
        $before = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $body = (new FseGatewayPayload())->publication($this->profile(), $this->document());
            $this->assertSame('20260911100000', $body['dataInizioPrestazione']);
            $this->assertSame($body['dataInizioPrestazione'], $body['dataFinePrestazione']);
            $this->assertSame(['SSR'], $body['administrativeRequest']);
            $this->assertSame('1.2.4^TEST.SUB.2', $body['identificativoSottomissione']);
            $this->assertSame('REF', $body['tipoDocumentoLivAlto']);
        } finally { date_default_timezone_set($before); }
    }

    public function testHtmlLocalDateAndLeapDayAreAcceptedWithoutRollover(): void
    {
        $body = (new FseGatewayPayload())->publication($this->profile(), array_replace($this->document(), [
            'service_start' => '2024-02-29T10:00', 'service_end' => '2024-02-29T10:30:45',
        ]));
        $this->assertSame('20240229100000', $body['dataInizioPrestazione']);
        $this->assertSame('20240229103045', $body['dataFinePrestazione']);
    }

    public static function invalidPayloads(): array
    {
        return [
            [[], ['service_start' => '']], [[], ['service_start' => null]],
            [[], ['service_start' => 'tomorrow']], [[], ['service_start' => '2026-02-30 10:00:00']],
            [[], ['service_start' => "2026-09-11 10:00:0\0"]],
            [[], ['service_start' => '2026-09-11 25:00:00']], [[], ['service_start' => '2026-09-11']],
            [[], ['service_start' => '2026-09-11T10:00:00+02:00']], [[], ['service_start' => ['bad']]],
            [[], ['service_end' => '2026-09-11 09:59:59']], [[], ['service_end' => 'bad']],
            [['facility_type' => 'UNKNOWN'], []], [['organizational_setting' => 'AD_PSC016'], []],
            [['clinical_activity' => ''], []], [[], ['administrative_request' => 'PRIVATE']],
            [['submission_oid_root' => ''], []], [[], ['submission_id' => '']],
            [[], ['submission_id' => str_repeat('x', 100)]], [[], ['submission_id' => "ID\r\nPRIVATE"]],
            [['repository_id' => ''], []], [['repository_id' => str_repeat('x', 101)], []],
            [[], ['document_unique_id' => 'ID^OTHER']], [[], ['document_oid_root' => 'bad']],
            [[], ['access_rules_json' => '{}']], [[], ['access_rules_json' => '{bad']],
            [[], ['access_rules_json' => '[null]']], [[], ['access_rules_json' => '["a\\nPRIVATE"]']],
            [[], ['access_rules_json' => json_encode(array_fill(0, 101, 'RULE'))]],
            [[], ['access_rules_json' => json_encode([str_repeat('x', 1001)])]],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedInputIsRejectedBeforeJwtOrTransport(array $profile, array $document): void
    {
        $jwt = $this->createMock(FseJwtService::class);
        $jwt->expects($this->never())->method('createTokens');
        $transport = $this->createMock(FseGatewayTransport::class);
        $transport->expects($this->never())->method('send');
        $this->expectException(\RuntimeException::class);
        (new FseGatewayClient($jwt, new Fse2(), $transport))->validateAndCreate(
            array_replace($this->profile(), $profile), array_replace($this->document(), $document), __FILE__);
    }

    public function testTechnicalLengthBoundsAreInclusive(): void
    {
        $body = (new FseGatewayPayload())->publication(array_replace($this->profile(), ['repository_id' => str_repeat('1', 100)]),
            array_replace($this->document(), ['submission_id' => str_repeat('s', 94),
                'document_unique_id' => str_repeat('d', 250), 'access_rules_json' => json_encode([str_repeat('x', 1000)])]));
        $this->assertSame(100, strlen($body['identificativoSottomissione']));
        $this->assertSame(256, strlen($body['identificativoDoc']));
        $this->assertCount(1, $body['attiCliniciRegoleAccesso']);
    }
}
