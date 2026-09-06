<?php

use App\Services\AgendaFontSizeService;
use CodeIgniter\Test\CIUnitTestCase;

final class AgendaFontSizePreferencesTest extends CIUnitTestCase
{
    public function testDefaultsMatchTheCurrentAgendaTypography(): void
    {
        $service = (new ReflectionClass(AgendaFontSizeService::class))->newInstanceWithoutConstructor();

        self::assertSame([
            'day_headings' => 14,
            'time_labels' => 14,
            'appointment_title' => 12,
            'appointment_time' => 11,
            'appointment_details' => 10,
            'team_professionals' => 14,
            'controls' => 12,
            'mini_calendar' => 13,
            'notes' => 12,
        ], $service->defaultSizes());
    }

    public function testSubmittedSizesAreClampedAndUnknownKeysAreIgnored(): void
    {
        $service = (new ReflectionClass(AgendaFontSizeService::class))->newInstanceWithoutConstructor();

        $sizes = $service->sanitizeSizes([
            'day_headings' => 999,
            'appointment_title' => 9,
            'controls' => '16',
            'unknown' => 42,
        ]);

        self::assertSame(22, $sizes['day_headings']);
        self::assertSame(10, $sizes['appointment_title']);
        self::assertSame(16, $sizes['controls']);
        self::assertArrayNotHasKey('unknown', $sizes);
        self::assertSame(14, $sizes['time_labels']);
    }

    public function testPreferencesAreStoredPerTenantInThePlatformDatabase(): void
    {
        self::assertSame('platform_tenant_agenda_font_preferences', AgendaFontSizeService::TABLE);

        $serviceSource = file_get_contents(APPPATH . 'Services/AgendaFontSizeService.php');
        self::assertIsString($serviceSource);
        self::assertStringContainsString("connect('platform')", $serviceSource);
        self::assertStringContainsString("where('id_tenant', \$tenantId)", $serviceSource);
        self::assertStringNotContainsString("where('id_user'", $serviceSource);
    }

    public function testOperationalSpaceProfileAndAgendaExposeEveryTypographyFamily(): void
    {
        $profileSource = file_get_contents(APPPATH . 'Views/profilo/_agenda_font_preferences.php');
        $preferencesPageSource = file_get_contents(APPPATH . 'Views/admin/agenda_font_preferences.php');
        $adminSidebarSource = file_get_contents(APPPATH . 'Views/partials/sidebar_admin.php');
        $personalProfileSource = file_get_contents(APPPATH . 'Views/profilo/index.php');
        $agendaSource = file_get_contents(APPPATH . 'Views/agenda/index.php');

        self::assertIsString($profileSource);
        self::assertIsString($preferencesPageSource);
        self::assertIsString($adminSidebarSource);
        self::assertIsString($personalProfileSource);
        self::assertIsString($agendaSource);
        self::assertStringContainsString('data-agenda-font-setting', $profileSource);
        self::assertStringContainsString('data-agenda-font-preset="comfortable"', $profileSource);
        self::assertStringContainsString('data-agenda-font-preset="large"', $profileSource);
        self::assertStringContainsString('Impostazione dello spazio', $profileSource);
        self::assertStringContainsString("view('profilo/_agenda_font_preferences'", $preferencesPageSource);
        self::assertStringContainsString("site_url('admin/preferenze-agenda')", $preferencesPageSource);
        self::assertStringContainsString('Dimensioni testi agenda', $adminSidebarSource);
        self::assertStringContainsString("site_url('admin/preferenze-agenda')", $adminSidebarSource);
        self::assertStringNotContainsString("view('profilo/_agenda_font_preferences'", $personalProfileSource);

        foreach ([
            '--agenda-font-day-heading',
            '--agenda-font-time-label',
            '--agenda-font-appointment-title',
            '--agenda-font-appointment-time',
            '--agenda-font-appointment-details',
            '--agenda-font-team-professional',
            '--agenda-font-controls',
            '--agenda-font-mini-calendar',
            '--agenda-font-notes',
        ] as $cssVariable) {
            self::assertStringContainsString($cssVariable, $agendaSource);
        }
    }
}
