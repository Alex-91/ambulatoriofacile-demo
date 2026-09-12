<?php

namespace Tests\Unit;

use App\Libraries\DatabaseConfig;
use App\Services\NotificationRateLimiterService;
use App\Services\TenantCatalogService;
use App\Services\TenantDatabaseConnector;
use App\Services\TenantNotificationPolicyService;
use App\Services\WhatsAppCampaignPlan;
use App\Services\WhatsAppCampaignPrioritySchema;
use App\Services\WhatsAppCampaignService;
use App\Services\WhatsAppGatewayClient;
use App\Services\WhatsAppSmsFallbackService;
use CodeIgniter\Database\SQLite3\Connection;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;

class_alias(\CodeIgniter\Database\SQLite3\Builder::class, __NAMESPACE__ . '\\CampaignTestBuilder');
class_alias(\CodeIgniter\Database\SQLite3\Result::class, __NAMESPACE__ . '\\CampaignTestResult');

/** SQLite is isolated in memory; only MySQL's lock suffix is adapted. */
final class CampaignTestConnection extends Connection
{
    public bool $failOnPlanWrite = false;

    public function query(string $sql, $binds = null, bool $setEscapeFlags = true, string $queryClass = '')
    {
        if ($this->failOnPlanWrite && str_starts_with($sql, 'UPDATE') && str_contains($sql, 'priority_plan_json')) {
            throw new \RuntimeException('Simulated plan write failure');
        }
        $sql = preg_replace('/ FOR UPDATE$/', '', $sql);
        // Fixture names are plain text: only the existing MySQL decryption
        // expression is adapted; the appointment join/filter runs unchanged.
        $sql = preg_replace('/CONVERT\(CAST\(AES_DECRYPT\(UNHEX\((c\.\w+)\), @key_str, c\.vector_id\) AS CHAR CHARACTER SET latin1\) USING utf8mb4\)/', '$1', $sql);
        return parent::query($sql, $binds, $setEscapeFlags, $queryClass);
    }
}

final class WhatsAppCampaignPriorityTest extends CIUnitTestCase
{
    private CampaignTestConnection $campaignDb;

    protected function setUp(): void
    {
        parent::setUp();
        \Config\Database::connect(['database' => ':memory:', 'DBDriver' => 'SQLite3'], false);
        $this->campaignDb = new CampaignTestConnection(['database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBDebug' => true]);
        $this->campaignDb->initialize();
        $this->campaignDb->query('CREATE TABLE platform_whatsapp_campaigns (id_whatsapp_campaign INTEGER PRIMARY KEY, id_tenant INTEGER, audience_type TEXT, status TEXT, pending_recipients INTEGER, message_text TEXT, priority_plan_json TEXT)');
        $this->campaignDb->query('CREATE TABLE platform_whatsapp_campaign_recipients (id_whatsapp_campaign_recipient INTEGER PRIMARY KEY, id_whatsapp_campaign INTEGER, id_tenant INTEGER, id_client INTEGER, recipient_phone TEXT, patient_name TEXT, status TEXT, provider_message_id TEXT, sent_at TEXT, attempt_count INTEGER, send_order INTEGER DEFAULT 0)');
        $this->campaignDb->query('CREATE TABLE platform_whatsapp_campaign_rate_limits (id_tenant INTEGER, next_allowed_at TEXT)');
        $this->campaignDb->query('CREATE TABLE platform_notification_rate_limits (id_tenant INTEGER, channel TEXT, counter_date TEXT, sent_today INTEGER, next_allowed_at TEXT)');
        $this->campaignDb->table('platform_whatsapp_campaigns')->insert(['id_whatsapp_campaign' => 1, 'id_tenant' => 5, 'audience_type' => 'all_patients', 'status' => 'running', 'pending_recipients' => 3, 'message_text' => 'Messaggio sintetico originale']);
        foreach ([1 => 'sent', 2 => 'failed', 3 => 'pending', 4 => 'pending', 5 => 'pending'] as $id => $status) {
            $this->campaignDb->table('platform_whatsapp_campaign_recipients')->insert(['id_whatsapp_campaign_recipient' => $id, 'id_whatsapp_campaign' => 1, 'id_tenant' => 5, 'id_client' => $id, 'recipient_phone' => '+39300000000' . $id, 'patient_name' => 'Paziente sintetico ' . $id, 'status' => $status, 'provider_message_id' => $id === 1 ? 'provider-original' : null, 'sent_at' => $id === 1 ? '2026-09-12 10:00:00' : null, 'attempt_count' => $id <= 2 ? 1 : 0]);
        }
        $this->campaignDb->table('platform_whatsapp_campaign_recipients')->insert(['id_whatsapp_campaign_recipient' => 9, 'id_whatsapp_campaign' => 2, 'id_tenant' => 99, 'id_client' => 9, 'recipient_phone' => '+393000000009', 'patient_name' => 'Altro spazio', 'status' => 'pending']);
    }

    public function testPreviewDoesNotWriteAndApplyPreservesRecipientsAndCompletedSends(): void
    {
        $service = $this->service();
        $before = $this->rows();
        $preview = $service->prioritizePending(5, 1);
        $this->assertSame('preview', $preview['status']);
        $this->assertSame($before, $this->rows());
        $this->assertNull($this->campaignDb->table('platform_whatsapp_campaigns')->get()->getRowArray()['priority_plan_json']);
        $applied = $service->prioritizePending(5, 1, true);
        $this->assertSame(2, $applied['plan']['priority_recipients']);
        $after = $this->rows();
        foreach ($after as $index => $row) {
            unset($row['send_order']);
            $original = $before[$index]; unset($original['send_order']);
            $this->assertSame($original, $row);
        }
        $order = $this->campaignDb->table('platform_whatsapp_campaign_recipients')->where('id_tenant', 5)->where('status', 'pending')->orderBy('send_order')->get()->getResultArray();
        $this->assertSame([5, 3, 4], array_map('intval', array_column($order, 'id_whatsapp_campaign_recipient')));
        $second = $service->prioritizePending(5, 1, true);
        $this->assertSame('already_planned', $second['status']);
        $this->assertSame($applied['plan'], $second['plan']);
        $this->assertSame($after, $this->rows());
    }

    public function testDaytimeAndInFlightMessagesPreventApplication(): void
    {
        $before = $this->rows();
        try { $this->service('2026-09-12T12:00:00+02:00')->prioritizePending(5, 1, true); $this->fail('Daytime apply should fail'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('pausa', $e->getMessage()); }
        $this->assertSame($before, $this->rows());
        $this->campaignDb->table('platform_whatsapp_campaign_recipients')->where('id_whatsapp_campaign_recipient', 3)->update(['status' => 'processing']);
        $before = $this->rows();
        try { $this->service()->prioritizePending(5, 1, true); $this->fail('In-flight apply should fail'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('ancora in corso', $e->getMessage()); }
        $this->assertSame($before, $this->rows());
    }

    public function testWrongTenantCannotReorderCampaign(): void
    {
        $before = $this->rows();
        try { $this->service()->prioritizePending(99, 1, true); $this->fail('Tenant mismatch should fail'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('non trovata', $e->getMessage()); }
        $this->assertSame($before, $this->rows());
    }

    public function testNightWorkerDoesNotCallGateway(): void
    {
        $this->assertSame('outside_window', $this->service()->runOne()['status']);
    }

    public function testSchemaInstallationIsIdempotent(): void
    {
        $this->campaignDb->query('ALTER TABLE platform_whatsapp_campaigns DROP COLUMN priority_plan_json');
        $this->campaignDb->query('ALTER TABLE platform_whatsapp_campaign_recipients DROP COLUMN send_order');
        $this->campaignDb->resetDataCache();
        WhatsAppCampaignPrioritySchema::install($this->campaignDb);
        WhatsAppCampaignPrioritySchema::install($this->campaignDb);
        $this->assertTrue(WhatsAppCampaignPrioritySchema::ready($this->campaignDb));
        $this->assertCount(6, $this->rows());
    }

    public function testFailureAfterRankUpdatesRollsBackTheEntirePlan(): void
    {
        $before = $this->rows();
        $this->campaignDb->failOnPlanWrite = true;
        try { $this->service()->prioritizePending(5, 1, true); $this->fail('Expected simulated failure'); }
        catch (\RuntimeException $e) { $this->assertSame('Simulated plan write failure', $e->getMessage()); }
        $this->assertSame($before, $this->rows());
        $this->assertNull($this->campaignDb->table('platform_whatsapp_campaigns')->get()->getRowArray()['priority_plan_json']);
    }

    public function testAppointmentReaderIgnoresCancelledAndPastVisitsAndUsesActualStartTime(): void
    {
        $db = $this->campaignDb;
        $db->connID->createFunction('TIMESTAMP', static fn($date, $time) => $date . ' ' . $time, 2);
        $db->connID->createFunction('CONCAT_WS', static fn($separator, ...$parts) => implode($separator, array_filter($parts, static fn($v) => $v !== null)), -1);
        $db->query('CREATE TABLE dap02_clients (id_client INTEGER PRIMARY KEY, nome TEXT, cognome TEXT, cellulare TEXT, telefono TEXT)');
        $db->query('CREATE TABLE dap11_agenda_slot (id_slot INTEGER PRIMARY KEY, data_slot TEXT, ora_inizio TEXT)');
        $db->query('CREATE TABLE dap12_agenda_appuntamenti (id_appuntamento INTEGER PRIMARY KEY, id_slot INTEGER, id_client INTEGER, id_paziente INTEGER, stato TEXT, ora_inizio_appuntamento TEXT)');
        foreach ([1, 2, 3] as $id) { $db->table('dap02_clients')->insert(['id_client' => $id, 'nome' => 'Test', 'cognome' => 'Persona ' . $id, 'cellulare' => '+39300000000' . $id]); }
        foreach ([1 => ['2026-09-11', '10:00:00'], 2 => ['2026-09-12', '09:00:00'], 3 => ['2026-09-12', '10:00:00'], 4 => ['2026-09-13', '10:00:00'], 5 => ['2026-09-12', '08:00:00']] as $id => [$date, $time]) {
            $db->table('dap11_agenda_slot')->insert(['id_slot' => $id, 'data_slot' => $date, 'ora_inizio' => $time]);
        }
        foreach ([
            [1, 1, 1, null, 'PRENOTATO', null],
            [2, 2, 1, null, 'ANNULLATO', null],
            [3, 3, 1, null, 'PRENOTATO', '11:15:00'],
            [4, 4, 1, null, 'PRENOTATO', null],
            [5, 5, 0, 2, 'PRENOTATO', '07:15:00'],
        ] as [$id, $slot, $client, $legacy, $state, $start]) {
            $db->table('dap12_agenda_appuntamenti')->insert(['id_appuntamento' => $id, 'id_slot' => $slot, 'id_client' => $client, 'id_paziente' => $legacy, 'stato' => $state, 'ora_inizio_appuntamento' => $start]);
        }
        $service = (new \ReflectionClass(WhatsAppCampaignService::class))->newInstanceWithoutConstructor();
        $reader = new \ReflectionMethod($service, 'allPatientRecipients');
        $rows = $reader->invoke($service, $db, new DateTimeImmutable('2026-09-12T07:30:00+02:00'));
        $this->assertCount(3, $rows);
        $this->assertSame('2026-09-12 11:15:00', $rows[0]['next_appointment_at']);
        $this->assertNull($rows[1]['next_appointment_at']);
        $this->assertNull($rows[2]['next_appointment_at']);
        $db->table('dap12_agenda_appuntamenti')->where('id_appuntamento', 5)->update(['ora_inizio_appuntamento' => '12:00:00']);
        $rows = $reader->invoke($service, $db, new DateTimeImmutable('2026-09-12T07:30:00+02:00'));
        $this->assertSame('2026-09-12 12:00:00', $rows[1]['next_appointment_at']);
    }

    private function rows(): array { return $this->campaignDb->table('platform_whatsapp_campaign_recipients')->orderBy('id_whatsapp_campaign_recipient')->get()->getResultArray(); }

    private function service(string $clock = '2026-09-12T23:00:00+02:00'): WhatsAppCampaignService
    {
        $catalog = $this->getMockBuilder(TenantCatalogService::class)->disableOriginalConstructor()->onlyMethods(['getTenantById'])->getMock();
        $catalog->method('getTenantById')->willReturn(['id_tenant' => 5, 'tenant_name' => 'Studio sintetico']);
        $connector = $this->getMockBuilder(TenantDatabaseConnector::class)->disableOriginalConstructor()->onlyMethods(['connect'])->getMock();
        $connector->method('connect')->willReturn($this->campaignDb);
        $encryption = $this->getMockBuilder(DatabaseConfig::class)->disableOriginalConstructor()->onlyMethods(['setEncryptionConfig'])->getMock();
        $gateway = $this->getMockBuilder(WhatsAppGatewayClient::class)->disableOriginalConstructor()->onlyMethods(['sendText'])->getMock();
        $gateway->expects($this->never())->method('sendText');
        $policies = $this->getMockBuilder(TenantNotificationPolicyService::class)->disableOriginalConstructor()->onlyMethods(['resolve'])->getMock();
        $policies->method('resolve')->willReturn(['whatsapp' => ['messages_per_interval' => 1, 'interval_minutes' => 5, 'daily_limit' => 250]]);
        $limiter = $this->getMockBuilder(NotificationRateLimiterService::class)->disableOriginalConstructor()->getMock();
        $fallbacks = $this->getMockBuilder(WhatsAppSmsFallbackService::class)->disableOriginalConstructor()->getMock();
        $service = $this->getMockBuilder(WhatsAppCampaignService::class)->setConstructorArgs([$this->campaignDb, $catalog, $connector, $encryption, $gateway, $policies, $limiter, $fallbacks, new WhatsAppCampaignPlan(new DateTimeImmutable($clock))])->onlyMethods(['allPatientRecipients'])->getMock();
        $hints = [];
        foreach ([3 => ['Zeta', '2026-09-13 12:00:00'], 4 => ['Alfa', null], 5 => ['Verdi', '2026-09-13 09:00:00']] as $id => [$surname, $appointment]) {
            $hints[] = ['id_client' => $id, 'cellulare' => '+39300000000' . $id, 'telefono' => '', 'sort_surname' => $surname, 'sort_name' => 'Test', 'next_appointment_at' => $appointment];
        }
        $service->method('allPatientRecipients')->willReturn($hints);
        return $service;
    }
}
