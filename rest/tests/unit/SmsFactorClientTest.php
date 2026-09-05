<?php

namespace Tests\Unit;

use App\Services\SmsFactorClient;
use CodeIgniter\Test\CIUnitTestCase;

final class SmsFactorClientTest extends CIUnitTestCase
{
    public function testSendUsesBearerAuthenticationAndOfficialCampaignPayload(): void
    {
        $captured = [];
        $client = new SmsFactorClient(
            'test-token',
            SmsFactorClient::DEFAULT_BASE_URL,
            10,
            static function (string $method, string $url, string $body, array $headers) use (&$captured): array {
                $captured = compact('method', 'url', 'body', 'headers');

                return [
                    'status' => 200,
                    'body' => json_encode([
                        'status' => 1,
                        'message' => 'OK',
                        'ticket' => '14672468',
                        'sent' => 1,
                        'cost' => 1,
                        'credits' => 42,
                    ]),
                ];
            }
        );

        $result = $client->send('+39 333 123 4567', 'Promemoria visita', 'AmbFacile', [
            'tenant_id' => 17,
            'client_message_id' => 'af-17-test',
        ]);

        $payload = json_decode((string) $captured['body'], true);
        $this->assertTrue($result['ok']);
        $this->assertSame('SMSFactor', $result['provider']);
        $this->assertSame('14672468', $result['provider_id']);
        $this->assertSame('af-17-test', $result['client_message_id']);
        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://api.smsfactor.com/send', $captured['url']);
        $this->assertStringNotContainsString('test-token', $captured['url']);
        $this->assertSame('Bearer test-token', $captured['headers']['Authorization']);
        $this->assertSame('393331234567', $payload['sms']['recipients']['gsm'][0]['value']);
        $this->assertSame('af-17-test', $payload['sms']['recipients']['gsm'][0]['gsmsmsid']);
        $this->assertSame('alert', $payload['sms']['message']['pushtype']);
        $this->assertSame('AmbFacile', $payload['sms']['message']['sender']);
        $this->assertSame(0, $payload['sms']['message']['unicode']);
    }

    public function testModeratedCampaignIsAcceptedWithoutRetry(): void
    {
        $client = $this->clientReturning([
            'status' => -8,
            'message' => 'Campaign under moderation',
            'ticket' => '9001',
        ]);

        $result = $client->send('+393331234567', 'Promemoria visita', 'AmbFacile');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['moderated']);
        $this->assertNull($result['error']);
    }

    public function testFilteredCampaignIsNotReportedAsSent(): void
    {
        $client = $this->clientReturning([
            'status' => 1,
            'message' => 'OK',
            'ticket' => '9002',
            'sent' => 0,
            'blacklisted' => 1,
        ]);

        $result = $client->send('+393331234567', 'Promemoria visita', 'AmbFacile');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('blacklisted: 1', $result['error']);
    }

    public function testUnicodeIsEnabledOnlyWhenRequired(): void
    {
        $payloads = [];
        $client = new SmsFactorClient(
            'test-token',
            SmsFactorClient::DEFAULT_BASE_URL,
            10,
            static function (string $method, string $url, string $body) use (&$payloads): array {
                $payloads[] = json_decode($body, true);
                return ['status' => 200, 'body' => '{"status":1,"sent":1,"ticket":"1"}'];
            }
        );

        $client->send('+393331234567', 'Visita alle 10:30', 'AmbFacile');
        $client->send('+393331234567', 'Visita confermata ✅', 'AmbFacile');

        $this->assertSame(0, $payloads[0]['sms']['message']['unicode']);
        $this->assertSame(1, $payloads[1]['sms']['message']['unicode']);
    }

    public function testMissingTokenFailsBeforeCallingTheNetwork(): void
    {
        $called = false;
        $client = new SmsFactorClient('', SmsFactorClient::DEFAULT_BASE_URL, 10, static function () use (&$called): array {
            $called = true;
            return [];
        });

        $result = $client->send('+393331234567', 'Promemoria', 'AmbFacile');

        $this->assertFalse($result['ok']);
        $this->assertFalse($called);
        $this->assertStringContainsString('SMSFACTOR_API_TOKEN', $result['error']);
    }

    public function testInvalidSenderFailsBeforeCallingTheNetwork(): void
    {
        $called = false;
        $client = new SmsFactorClient('test-token', SmsFactorClient::DEFAULT_BASE_URL, 10, static function () use (&$called): array {
            $called = true;
            return [];
        });

        $result = $client->send('+393331234567', 'Promemoria appuntamento', 'Mittente troppo lungo');

        $this->assertFalse($result['ok']);
        $this->assertFalse($called);
        $this->assertStringContainsString('11 caratteri', $result['error']);
    }

    public function testBaseUrlMustUseHttps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SmsFactorClient('token', 'http://api.smsfactor.com');
    }

    /** @param array<string, mixed> $payload */
    private function clientReturning(array $payload): SmsFactorClient
    {
        return new SmsFactorClient(
            'test-token',
            SmsFactorClient::DEFAULT_BASE_URL,
            10,
            static fn(): array => [
                'status' => 200,
                'body' => json_encode($payload),
            ]
        );
    }
}
