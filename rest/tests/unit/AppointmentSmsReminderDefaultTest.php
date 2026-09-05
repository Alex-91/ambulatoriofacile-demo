<?php

namespace Tests\Unit;

use App\Services\AppointmentNotificationSettingsService;
use App\Services\AppointmentReminderDispatchService;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;

final class AppointmentSmsReminderDefaultTest extends CIUnitTestCase
{
    public function testPlatformConfigStoresSmsReminderDefaultWithoutLosingOtherSettings(): void
    {
        $reflection = new ReflectionClass(AppointmentNotificationSettingsService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $existing = [
            'message_type_controls' => [
                AppointmentNotificationSettingsService::TYPE_REMINDER => ['enabled' => true],
            ],
            'channel_controls' => [
                AppointmentNotificationSettingsService::CHANNEL_SMS => ['enabled' => true],
            ],
        ];

        $merged = $service->mergePlatformPatientSmsReminderDefaultIntoConfig($existing, true);

        $this->assertTrue($merged['patient_sms_reminder']['default_enabled']);
        $this->assertSame($existing['message_type_controls'], $merged['message_type_controls']);
        $this->assertSame($existing['channel_controls'], $merged['channel_controls']);
    }

    public function testSmsChannelRequiresPatientOptInWhenAutomaticDefaultIsDisabled(): void
    {
        $channels = $this->filterChannels(
            [
                AppointmentNotificationSettingsService::CHANNEL_SMS,
                AppointmentNotificationSettingsService::CHANNEL_EMAIL,
            ],
            ['appointment_reminder_sms_enabled' => 0],
            false
        );

        $this->assertSame([AppointmentNotificationSettingsService::CHANNEL_EMAIL], $channels);
    }

    public function testAutomaticDefaultAllowsSmsWithoutPatientOptIn(): void
    {
        $channels = $this->filterChannels(
            [
                AppointmentNotificationSettingsService::CHANNEL_SMS,
                AppointmentNotificationSettingsService::CHANNEL_EMAIL,
            ],
            ['appointment_reminder_sms_enabled' => 0],
            true
        );

        $this->assertSame([
            AppointmentNotificationSettingsService::CHANNEL_SMS,
            AppointmentNotificationSettingsService::CHANNEL_EMAIL,
        ], $channels);
    }

    /**
     * @param array<int, string> $channels
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    private function filterChannels(array $channels, array $row, bool $defaultEnabled): array
    {
        $reflection = new ReflectionClass(AppointmentReminderDispatchService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('filterReminderChannelsForRow');
        $method->setAccessible(true);

        return $method->invoke($service, $channels, $row, $defaultEnabled);
    }
}
