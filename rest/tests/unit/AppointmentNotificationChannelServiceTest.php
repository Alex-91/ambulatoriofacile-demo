<?php

namespace Tests\Unit;

use App\Services\AppointmentNotificationChannelService;
use App\Services\AppointmentNotificationSettingsService;
use CodeIgniter\Test\CIUnitTestCase;

final class AppointmentNotificationChannelServiceTest extends CIUnitTestCase
{
    private string|false $originalProvider;
    private string|false $originalSmsProvider;
    private string|false $originalSmsFactorToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalProvider = getenv('WHATSAPP_PROVIDER');
        $this->originalSmsProvider = getenv('SMS_PROVIDER');
        $this->originalSmsFactorToken = getenv('SMSFACTOR_API_TOKEN');
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
        if ($this->originalSmsProvider === false) {
            putenv('SMS_PROVIDER');
            unset($_ENV['SMS_PROVIDER'], $_SERVER['SMS_PROVIDER']);
        } else {
            putenv('SMS_PROVIDER=' . $this->originalSmsProvider);
            $_ENV['SMS_PROVIDER'] = $this->originalSmsProvider;
            $_SERVER['SMS_PROVIDER'] = $this->originalSmsProvider;
        }
        if ($this->originalSmsFactorToken === false) {
            putenv('SMSFACTOR_API_TOKEN');
            unset($_ENV['SMSFACTOR_API_TOKEN'], $_SERVER['SMSFACTOR_API_TOKEN']);
        } else {
            putenv('SMSFACTOR_API_TOKEN=' . $this->originalSmsFactorToken);
            $_ENV['SMSFACTOR_API_TOKEN'] = $this->originalSmsFactorToken;
            $_SERVER['SMSFACTOR_API_TOKEN'] = $this->originalSmsFactorToken;
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

    public function testSmsFactorProviderIsExplicitInProviderLabel(): void
    {
        putenv('SMS_PROVIDER=smsfactor');
        $_ENV['SMS_PROVIDER'] = 'smsfactor';
        $_SERVER['SMS_PROVIDER'] = 'smsfactor';

        $service = new AppointmentNotificationChannelService();

        $this->assertSame(
            'SMSFactor',
            $service->providerLabel(AppointmentNotificationSettingsService::CHANNEL_SMS)
        );
    }

    public function testSmsFactorFailsClosedWhenTokenIsMissing(): void
    {
        putenv('SMS_PROVIDER=smsfactor');
        putenv('SMSFACTOR_API_TOKEN');
        $_ENV['SMS_PROVIDER'] = 'smsfactor';
        $_SERVER['SMS_PROVIDER'] = 'smsfactor';
        unset($_ENV['SMSFACTOR_API_TOKEN'], $_SERVER['SMSFACTOR_API_TOKEN']);

        $result = (new AppointmentNotificationChannelService())->send(
            AppointmentNotificationSettingsService::CHANNEL_SMS,
            '+393331234567',
            'Promemoria appuntamento'
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('SMSFactor', $result['provider']);
        $this->assertStringContainsString('SMSFACTOR_API_TOKEN', $result['error']);
    }
}
