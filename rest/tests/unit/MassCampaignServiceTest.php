<?php

namespace Tests\Unit;

use App\Libraries\DatabaseConfig;
use App\Services\AppointmentNotificationChannelService;
use App\Services\MassCampaignDeliveryService;
use App\Services\MassCampaignService;
use App\Services\TenantCatalogService;
use App\Services\TenantDatabaseConnector;
use App\Services\TenantFeatureService;
use CodeIgniter\Database\SQLite3\Connection;
use CodeIgniter\Database\SQLite3\Forge;
use CodeIgniter\Test\CIUnitTestCase;

class_alias(\CodeIgniter\Database\SQLite3\Builder::class, __NAMESPACE__ . '\\MassQueueBuilder');
class_alias(\CodeIgniter\Database\SQLite3\Result::class, __NAMESPACE__ . '\\MassQueueResult');

class MassQueueConnection extends Connection
{
    public function query(string $sql, $binds = null, bool $setEscapeFlags = true, string $queryClass = '')
    {
        // Only SQL syntax is adapted; these tests do not validate MySQL row locking.
        $sql = str_replace('ON DUPLICATE KEY UPDATE id_tenant = VALUES(id_tenant)', 'ON CONFLICT(id_tenant) DO NOTHING', $sql);
        if (str_starts_with($sql, 'SELECT c.id_client,')) {
            $sql = 'SELECT * FROM dap02_clients';
        }
        return parent::query(str_replace(' FOR UPDATE', '', $sql), $binds, $setEscapeFlags, $queryClass);
    }
}

/** Isolated in-memory queue tests: no configured database and no real transports. */
final class MassCampaignServiceTest extends CIUnitTestCase
{
    private Connection $queueDb;
    private MassCampaignService $service;
    private array $sent = [];
    private array $outcomes = [];
    private ?\Closure $duringSend = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queueDb = new MassQueueConnection(['database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true]);
        $this->queueDb->initialize();
        $this->queueDb->query('CREATE TABLE platform_tenants (id_tenant INTEGER PRIMARY KEY)');
        $this->queueDb->query('CREATE TABLE platform_users (id_platform_user INTEGER PRIMARY KEY)');
        $this->queueDb->table('platform_tenants')->insertBatch([['id_tenant' => 1], ['id_tenant' => 2]]);
        require_once APPPATH . 'Database/Migrations/2026-09-22-100001_CreateMassCampaignTables.php';
        $migration = new class(new Forge($this->queueDb)) extends \App\Database\Migrations\CreateMassCampaignTables {
            protected $DBGroup = null;
        };
        $migration->up();
        $catalog = $this->getMockBuilder(TenantCatalogService::class)->disableOriginalConstructor()->onlyMethods(['getTenantById'])->getMock();
        $catalog->method('getTenantById')->willReturn(['is_active' => 1]);
        $delivery = $this->getMockBuilder(MassCampaignDeliveryService::class)->onlyMethods(['send', 'smsEnabled'])->getMock();
        $delivery->method('smsEnabled')->willReturn(true);
        $delivery->method('send')->willReturnCallback(function ($tenant, $channel, $recipient) {
            $this->sent[] = [$tenant, $channel, $recipient['id_mass_campaign_recipient']];
            if ($this->duringSend) { ($this->duringSend)(); }
            return array_shift($this->outcomes) ?? ['ok' => true];
        });
        $connector = $this->getMockBuilder(TenantDatabaseConnector::class)->disableOriginalConstructor()->onlyMethods(['connect'])->getMock();
        $connector->method('connect')->willReturn($this->queueDb);
        $this->service = new MassCampaignService(
            $this->queueDb, $catalog,
            $connector,
            $this->getMockBuilder(DatabaseConfig::class)->disableOriginalConstructor()->getMock(),
            $delivery
        );
    }

    protected function tearDown(): void
    {
        $this->queueDb->close();
        parent::tearDown();
    }

    public function testSuccessfulPrimaryDoesNotSendFallbackAndSharesMinuteAcrossCampaigns(): void
    {
        $this->enqueue(1, ['email', 'sms']);
        $this->enqueue(1, ['sms']);
        self::assertSame('sent', $this->service->runOne()['status']);
        self::assertSame('idle', $this->service->runOne()['status']);
        self::assertSame([[1, 'email', 1]], $this->sent);
        $limit = $this->queueDb->table('platform_mass_campaign_rate_limits')->get()->getRowArray();
        self::assertGreaterThanOrEqual(time() + 58, strtotime($limit['next_allowed_at']));
        $this->advanceMinute();
        self::assertSame('sent', $this->service->runOne()['status']);
        self::assertSame([[1, 'email', 1], [1, 'sms', 2]], $this->sent);
    }

    public function testEmailCampaignIncludesPatientsWithoutPhoneAndExcludesInvalidEmail(): void
    {
        $this->queueDb->query('CREATE TABLE dap02_clients (id_client INTEGER, email TEXT, cellulare TEXT, telefono TEXT, patient_name TEXT)');
        $this->queueDb->resetDataCache();
        $this->queueDb->table('dap02_clients')->insertBatch([
            ['id_client' => 1, 'email' => 'test@example.org', 'cellulare' => '', 'telefono' => '', 'patient_name' => 'Email only'],
            ['id_client' => 1, 'email' => 'test@example.org', 'cellulare' => '', 'telefono' => '', 'patient_name' => 'Duplicate'],
            ['id_client' => 2, 'email' => 'invalid', 'cellulare' => '3331234567', 'telefono' => '', 'patient_name' => 'SMS only'],
        ]);
        $campaign = $this->service->createCampaign(1, ['audience_type' => 'all_patients', 'message_text' => 'Test', 'email_enabled' => true], 0);
        self::assertSame(1, (int) $campaign['total_recipients']);
        self::assertSame(['email'], json_decode($campaign['channels_json'], true));
        $recipient = $this->service->dashboard(1)['recipients'][0];
        self::assertSame('', $recipient['recipient_phone']);
        self::assertSame('test@example.org', $recipient['recipient_email']);
        self::assertSame('sent', $this->service->runOne()['status']);
    }

    public function testFallbackWaitsOneMinuteAndStopsAfterSecondFailure(): void
    {
        $this->enqueue(1, ['sms', 'email']);
        $this->outcomes = [['ok' => false, 'error' => 'SMS rifiutato'], ['ok' => false, 'error' => 'Email rifiutata']];
        self::assertSame('fallback_pending', $this->service->runOne()['status']);
        self::assertSame('idle', $this->service->runOne()['status']);
        $this->advanceMinute();
        self::assertSame('failed', $this->service->runOne()['status']);
        $this->advanceMinute();
        self::assertSame('idle', $this->service->runOne()['status']);
        self::assertSame([[1, 'sms', 1], [1, 'email', 1]], $this->sent);
        $row = $this->queueDb->table('platform_mass_campaign_recipients')->get()->getRowArray();
        self::assertCount(2, json_decode($row['attempts_json'], true));
        self::assertSame('failed', $row['status']);
    }

    public function testSuccessfulFallbackCompletesCampaign(): void
    {
        $this->enqueue(1, ['email', 'sms']);
        $this->outcomes = [['ok' => false, 'error' => 'Email mancante'], ['ok' => true]];
        self::assertSame('fallback_pending', $this->service->runOne()['status']);
        $this->advanceMinute();
        self::assertSame('sent', $this->service->runOne()['status']);
        $campaign = $this->queueDb->table('platform_mass_campaigns')->get()->getRowArray();
        self::assertSame('completed', $campaign['status']);
        self::assertSame(1, (int) $campaign['sent_recipients']);
        self::assertSame(0, (int) $campaign['failed_recipients']);
    }

    public function testSpacesHaveIndependentMinuteSlotsAndDashboardScope(): void
    {
        $this->enqueue(1, ['email']);
        $this->enqueue(2, ['sms']);
        self::assertSame('sent', $this->service->runOne()['status']);
        self::assertSame('sent', $this->service->runOne()['status']);
        self::assertSame([1, 2], array_column($this->sent, 0));
        self::assertNull($this->service->dashboard(1, 2)['selected_campaign']);
    }

    public function testInterruptedAttemptIsNotAutomaticallySentAgain(): void
    {
        $this->enqueue(1, ['email', 'sms']);
        $this->queueDb->table('platform_mass_campaign_recipients')->update(['status' => 'processing', 'updated_at' => '2000-01-01 00:00:00']);
        self::assertSame('idle', $this->service->runOne()['status']);
        self::assertSame([], $this->sent);
        $row = $this->queueDb->table('platform_mass_campaign_recipients')->get()->getRowArray();
        self::assertSame('failed', $row['status']);
        self::assertStringContainsString('Esito incerto', $row['error_text']);
    }

    public function testChannelSelectionSupportsAllOrdersWithoutWhatsApp(): void
    {
        $service = new MassCampaignDeliveryService();
        self::assertSame(['email'], $service->channelOrder(['email_enabled' => true], false));
        self::assertSame(['sms'], $service->channelOrder(['sms_enabled' => true], true));
        self::assertSame(['sms', 'email'], $service->channelOrder(['email_enabled' => true, 'sms_enabled' => true, 'first_channel' => 'sms'], true));
        self::assertSame(['email', 'sms'], $service->channelOrder(['email_enabled' => true, 'sms_enabled' => true, 'first_channel' => 'email'], true));
        foreach ([[], ['sms_enabled' => true], ['email_enabled' => true, 'sms_enabled' => true, 'first_channel' => 'wa']] as $payload) {
            try {
                $service->channelOrder($payload, isset($payload['first_channel']));
                self::fail('Invalid selection was accepted');
            } catch (\InvalidArgumentException $expected) {
                self::assertNotEmpty($expected->getMessage());
            }
        }
    }

    public function testDisabledSmsAndWhatsAppNeverReachTransportButEmailAlwaysCan(): void
    {
        $features = $this->getMockBuilder(TenantFeatureService::class)->disableOriginalConstructor()->onlyMethods(['resolveEffectiveFeatureMapForTenant'])->getMock();
        $features->method('resolveEffectiveFeatureMapForTenant')->willReturn([]);
        $channels = $this->getMockBuilder(AppointmentNotificationChannelService::class)->disableOriginalConstructor()->onlyMethods(['send'])->getMock();
        $channels->expects(self::once())->method('send')->with('email', self::anything(), 'Test', self::anything())->willReturn(['ok' => true]);
        $service = new MassCampaignDeliveryService($features, $channels);
        self::assertFalse($service->send(1, 'sms', [], 'Test')['ok']);
        self::assertFalse($service->send(1, 'wa', [], 'Test')['ok']);
        self::assertTrue($service->send(1, 'email', ['recipient_email' => 'test@example.org'], 'Test')['ok']);
    }

    public function testCampaignFormAllowsEmailWithoutSmsOrWhatsApp(): void
    {
        $renderer = $this->getMockBuilder(\CodeIgniter\View\View::class)->disableOriginalConstructor()->onlyMethods(['render'])->getMock();
        $renderer->method('render')->willReturn('');
        \Config\Services::injectMock('renderer', $renderer);
        $dashboard = ['schema_ready' => true];
        $smsEnabled = false;
        ob_start();
        try {
            include APPPATH . 'Views/tenant/whatsapp_campaigns.php';
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
            \Config\Services::resetSingle('renderer');
        }
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        self::assertSame(1, $xpath->query('//input[@name="email_enabled" and @checked and not(@disabled)]')->length);
        self::assertSame(1, $xpath->query('//input[@name="sms_enabled" and @disabled]')->length);
        self::assertSame(2, $xpath->query('//select[@name="first_channel"]/option')->length);
        self::assertSame(1, $xpath->query('//button[@id="campaign-submit" and not(@disabled)]')->length);
        self::assertStringContainsString('invii-massivi/create', $xpath->query('//form')->item(0)->getAttribute('action'));
        self::assertStringNotContainsString('whatsapp-device', $html);
    }

    public function testRunningCampaignResumesWithoutResendingCompletedRecipientsOrResettingRate(): void
    {
        $this->enqueue(1, ['email']);
        $recipient = $this->queueDb->table('platform_mass_campaign_recipients')->get()->getRowArray();
        unset($recipient['id_mass_campaign_recipient']);
        $recipient['recipient_email'] = 'second@example.org';
        $this->queueDb->table('platform_mass_campaign_recipients')->insert($recipient);
        self::assertSame('sent', $this->service->runOne()['status']);
        $limit = $this->service->nextPendingDueAt();
        $this->service->setPaused(1, 1, true);
        $this->service->setPaused(1, 1, true);
        self::assertNull($this->service->nextPendingDueAt());
        self::assertSame('paused', $this->service->dashboard(1)['selected_campaign']['status']);
        self::assertSame('idle', $this->service->runOne()['status']);
        $this->service->setPaused(1, 1, false);
        self::assertSame($limit, $this->service->nextPendingDueAt());
        self::assertSame('idle', $this->service->runOne()['status']);
        $this->advanceMinute();
        self::assertSame('sent', $this->service->runOne()['status']);
        self::assertSame([[1, 'email', 1], [1, 'email', 2]], $this->sent);
    }

    public function testPauseDuringPrimaryFailureSurvivesCompletionAndRetainsFallback(): void
    {
        $this->enqueue(1, ['email', 'sms']);
        $this->outcomes = [['ok' => false, 'error' => 'Email rifiutata'], ['ok' => true]];
        $this->duringSend = function () { $this->service->setPaused(1, 1, true); };
        self::assertSame('fallback_pending', $this->service->runOne()['status']);
        self::assertSame('paused', $this->service->dashboard(1)['selected_campaign']['status']);
        $this->advanceMinute();
        self::assertSame('idle', $this->service->runOne()['status']);
        $this->duringSend = null;
        $this->service->setPaused(1, 1, false);
        self::assertSame('sent', $this->service->runOne()['status']);
        self::assertSame([[1, 'email', 1], [1, 'sms', 1]], $this->sent);
    }

    public function testQueuedPauseDoesNotBlockOtherCampaignsAndResumesAsQueued(): void
    {
        $this->enqueue(1, ['email']);
        $this->enqueue(1, ['sms']);
        $this->service->setPaused(1, 1, true);
        self::assertSame('sent', $this->service->runOne()['status']);
        self::assertSame([[1, 'sms', 2]], $this->sent);
        $this->service->setPaused(1, 1, false);
        self::assertSame('queued', $this->service->dashboard(1, 1)['selected_campaign']['status']);
    }

    public function testOtherSpacesAndCompletedCampaignsCannotBePausedOrResumed(): void
    {
        $this->enqueue(1, ['email']);
        foreach ([true, false] as $paused) {
            try {
                $this->service->setPaused(2, 1, $paused);
                self::fail('Cross-space mutation accepted');
            } catch (\RuntimeException $expected) {
                self::assertSame('queued', $this->service->dashboard(1)['selected_campaign']['status']);
            }
        }
        self::assertSame('sent', $this->service->runOne()['status']);
        foreach ([true, false] as $paused) {
            try {
                $this->service->setPaused(1, 1, $paused);
                self::fail('Completed campaign reopened');
            } catch (\RuntimeException $expected) {
                self::assertSame('completed', $this->service->dashboard(1)['selected_campaign']['status']);
            }
        }
    }

    public function testControlsExposePauseOrResumeOnlyForEligibleCampaigns(): void
    {
        helper('portal');
        foreach (['queued' => 'pause', 'running' => 'pause', 'paused' => 'resume', 'completed' => null] as $status => $action) {
            $campaign = ['id_mass_campaign' => 17, 'status' => $status];
            ob_start();
            try {
                include APPPATH . 'Views/tenant/mass_campaign_controls.php';
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if ($action === null) {
                self::assertStringNotContainsString('<form', $html);
            } else {
                self::assertStringContainsString('invii-massivi/' . $action, html_entity_decode($html));
                self::assertStringContainsString('method="post"', $html);
                self::assertStringContainsString('name="campaign_id" value="17"', $html);
            }
        }
    }

    private function enqueue(int $tenant, array $channels): void
    {
        $now = date('Y-m-d H:i:s');
        $this->queueDb->table('platform_mass_campaigns')->insert([
            'id_tenant' => $tenant, 'audience_type' => 'all_patients', 'message_text' => 'Test', 'channels_json' => json_encode($channels),
            'total_recipients' => 1, 'pending_recipients' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = $this->queueDb->insertID();
        $this->queueDb->table('platform_mass_campaign_recipients')->insert([
            'id_mass_campaign' => $id, 'id_tenant' => $tenant, 'recipient_phone' => '+393331234567', 'recipient_email' => 'test@example.org',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if (!$this->queueDb->table('platform_mass_campaign_rate_limits')->where('id_tenant', $tenant)->countAllResults()) {
            $this->queueDb->table('platform_mass_campaign_rate_limits')->insert(['id_tenant' => $tenant, 'updated_at' => $now]);
        }
    }

    private function advanceMinute(): void
    {
        $this->queueDb->table('platform_mass_campaign_rate_limits')->update(['next_allowed_at' => '2000-01-01 00:00:00']);
    }
}
