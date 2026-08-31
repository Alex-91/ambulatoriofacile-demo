<?php

namespace Tests\Unit;

use App\Services\AppointmentNotificationChannelService;
use App\Services\AppointmentNotificationSettingsService;
use CodeIgniter\Test\CIUnitTestCase;

final class AppointmentNotificationChannelServiceTest extends CIUnitTestCase
{
    private string|false $originalProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalProvider = getenv('WHATSAPP_PROVIDER');
    }

    protected function tearDown(): void
    {
        if ($this->originalProvider === false) {
            putenv('WHATSAPP_PROVIDER');
            unset($_ENV['WHATSAPP_PROVIDER'], $_SERVER['WHATSAPP_PROVIDER']);
        } else {
            putenv('WHATSAPP_PROVIDER=' . $this->originalProvider);
            $_ENV['WHATSAPP_PROVIDER'] = $this->originalProvider;
            $_SERVER['WHATSAPP_PROVIDER'] = $this->originalProvider;
        }
        parent::tearDown();
    }

    public function testUltraMsgRemainsTheDefaultProvider(): void
    {
        putenv('WHATSAPP_PROVIDER');
        unset($_ENV['WHATSAPP_PROVIDER'], $_SERVER['WHATSAPP_PROVIDER']);

        $service = new AppointmentNotificationChannelService();

        $this->assertSame(
            'UltraMsg',
            $service->providerLabel(AppointmentNotificationSettingsService::CHANNEL_WHATSAPP)
        );
    }

    public function testHybridModeIsExplicitInProviderLabel(): void
    {
        putenv('WHATSAPP_PROVIDER=hybrid');
        $_ENV['WHATSAPP_PROVIDER'] = 'hybrid';
        $_SERVER['WHATSAPP_PROVIDER'] = 'hybrid';

        $service = new AppointmentNotificationChannelService();

        $this->assertSame(
            'UltraMsg / AmbulatorioFacile WhatsApp Gateway',
            $service->providerLabel(AppointmentNotificationSettingsService::CHANNEL_WHATSAPP)
        );
    }
}
