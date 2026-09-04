<?php

namespace Tests\Unit;

use App\Services\AppointmentNotificationSettingsService;
use App\Services\TenantNotificationPolicyService;
use CodeIgniter\Test\CIUnitTestCase;

final class TenantNotificationPolicyServiceTest extends CIUnitTestCase
{
    public function testDefaultsAreConservativeAndUseNoReplyDomain(): void
    {
        $service = new TenantNotificationPolicyService();
        $policy = $service->defaults('Studio Prova');

        $this->assertSame('noreply@ambulatoriofacile.it', $policy['email']['from_address']);
        $this->assertSame('noreply@ambulatoriofacile.it', $policy['email']['reply_to']);
        $this->assertSame(10, $policy['email']['messages_per_interval']);
        $this->assertSame(5, $policy['email']['interval_minutes']);
        $this->assertSame(1, $policy['whatsapp']['messages_per_interval']);
        $this->assertSame(5, $policy['whatsapp']['interval_minutes']);
        $this->assertTrue($policy['whatsapp']['sms_fallback_enabled']);
        $this->assertTrue($service->sanitize([], 'Studio Prova')['whatsapp']['sms_fallback_enabled']);
    }

    public function testRejectsSenderOutsideAmbulatorioFacileDomain(): void
    {
        $service = new TenantNotificationPolicyService();

        $this->expectException(\InvalidArgumentException::class);
        $service->sanitize([
            'email' => ['from_address' => 'pazienti@example.com'],
        ], 'Studio Prova', true);
    }

    public function testComputesEvenSpacingFromConfiguredWindow(): void
    {
        $service = new TenantNotificationPolicyService();
        $policy = $service->sanitize([
            'whatsapp' => [
                'messages_per_interval' => 5,
                'interval_minutes' => 10,
                'daily_limit' => 100,
                'sms_fallback_enabled' => true,
                'fallback_after_minutes' => 30,
            ],
        ], 'Studio Prova');

        $this->assertSame(
            120,
            $service->minimumSpacingSeconds($policy, AppointmentNotificationSettingsService::CHANNEL_WHATSAPP)
        );
    }

    public function testRejectsSmsSenderLongerThanElevenCharacters(): void
    {
        $service = new TenantNotificationPolicyService();

        $this->expectException(\InvalidArgumentException::class);
        $service->sanitize([
            'sms' => ['sender' => 'AmbulatorioFacile'],
        ], 'Studio Prova', true);
    }
}
