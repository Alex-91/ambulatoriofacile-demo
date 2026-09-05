<?php

namespace Tests\Unit;

use App\Services\SmsProviderSecretsService;
use CodeIgniter\Test\CIUnitTestCase;

final class SmsProviderSecretsServiceTest extends CIUnitTestCase
{
    public function testCredentialsUseAuthenticatedEncryption(): void
    {
        $service = new SmsProviderSecretsService('unit-test-sms-key');
        $encrypted = $service->encrypt('token-super-segreto');

        $this->assertNotNull($encrypted);
        $this->assertStringStartsWith('smssec:v1:', $encrypted);
        $this->assertStringNotContainsString('token-super-segreto', $encrypted);
        $this->assertSame('token-super-segreto', $service->decrypt($encrypted));
    }

    public function testUnknownSecretFormatIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SmsProviderSecretsService('unit-test-sms-key'))->decrypt('token-in-chiaro');
    }
}
