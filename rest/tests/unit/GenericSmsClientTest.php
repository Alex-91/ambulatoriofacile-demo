<?php

namespace Tests\Unit;

use App\Services\GenericSmsClient;
use CodeIgniter\Test\CIUnitTestCase;

final class GenericSmsClientTest extends CIUnitTestCase
{
    public function testNestedTemplatePreservesQuotesUnicodeAndLiteralPlaceholders(): void
    {
        $message = 'Test "ciao" è {sender}';
        $body = GenericSmsClient::payload(['recipients' => ['{recipient}'], 'text' => '{message}', 'sender' => '{sender}'],
            ['{recipient}' => '+393331234567', '{message}' => $message, '{sender}' => 'Studio']);
        $this->assertSame($message, $body['text']);
        $this->assertSame(['+393331234567'], $body['recipients']);
        $this->assertSame($body, json_decode(json_encode($body), true));
        $this->assertSame('42', GenericSmsClient::responseValue(['data' => ['id' => '42']], 'data.id'));
        $this->assertNull(GenericSmsClient::responseValue(['data' => []], 'data.ok'));
    }

    public function testRejectsNonHttpsEndpoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GenericSmsClient::validate(['url' => 'http://sms.example.com']);
    }

    public function testRejectsHeaderInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GenericSmsClient::validate(['url' => 'https://sms.example.com',
            'body' => ['to' => '{recipient}', 'message' => '{message}'],
            'headers' => ['Authorization' => "token\r\nHost: evil.example"],
            'success_path' => 'ok', 'success_value' => true]);
    }
}
