<?php

namespace Tests\Unit;

use App\Services\TenantNotificationSecretsService;
use CodeIgniter\Test\CIUnitTestCase;

final class TenantNotificationSecretsServiceTest extends CIUnitTestCase
{
    public function testSmtpPasswordRoundTripUsesAuthenticatedEncryption(): void
    {
        $service = new TenantNotificationSecretsService('unit-test-notification-key');
        $encrypted = $service->encrypt('password SMTP molto segreta');

        $this->assertNotNull($encrypted);
        $this->assertStringStartsWith('notifsec:v1:', $encrypted);
        $this->assertNotSame('password SMTP molto segreta', $encrypted);
        $this->assertSame('password SMTP molto segreta', $service->decrypt($encrypted));
    }

    public function testRejectsUnknownSecretFormat(): void
    {
        $service = new TenantNotificationSecretsService('unit-test-notification-key');

        $this->expectException(\RuntimeException::class);
        $service->decrypt('password-in-chiaro');
    }
}
