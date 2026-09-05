<?php

namespace Tests\Unit;

use App\Services\AppointmentNotificationLogExplorerService;
use CodeIgniter\Test\CIUnitTestCase;

final class AppointmentNotificationLogExplorerServiceTest extends CIUnitTestCase
{
    public function testEmailAcceptedExplainsThatMailboxDeliveryIsNotConfirmed(): void
    {
        $result = (new AppointmentNotificationLogExplorerService())->build([[
            'event_id' => 'evt_email',
            'tenant_id' => 7,
            'tenant_name' => 'Studio Test',
            'message_type' => 'patient_booking_confirmation',
            'channel' => 'email',
            'status' => 'sent',
            'recipient' => 'patient@example.test',
            'response' => [
                'subject' => 'Conferma appuntamento',
                'authorization' => 'Bearer secret-value',
                'nested' => ['api_token' => 'hidden-token'],
            ],
            'created_at' => '2026-09-05T12:00:00+00:00',
        ]]);

        $row = $result['rows'][0];
        $this->assertSame('accepted', $row['status_group']);
        $this->assertStringContainsString('trasporto email ha accettato', $row['diagnostic_message']);
        $this->assertStringContainsString('[redacted]', $row['response_preview']);
        $this->assertStringNotContainsString('secret-value', $row['response_preview']);
        $this->assertStringNotContainsString('hidden-token', $row['response_preview']);
    }

    public function testFiltersProblemsByTenantAndChannel(): void
    {
        $entries = [
            [
                'event_id' => 'evt_failed_email',
                'tenant_id' => 7,
                'channel' => 'email',
                'status' => 'failed',
                'error' => 'Autenticazione SMTP non riuscita',
                'recipient' => 'patient@example.test',
                'created_at' => '2026-09-05T13:00:00+00:00',
            ],
            [
                'event_id' => 'evt_sent_sms',
                'tenant_id' => 7,
                'channel' => 'sms',
                'status' => 'sent',
                'recipient' => '+393331234567',
                'created_at' => '2026-09-05T12:00:00+00:00',
            ],
            [
                'event_id' => 'evt_other_tenant',
                'tenant_id' => 8,
                'channel' => 'email',
                'status' => 'failed',
                'created_at' => '2026-09-05T11:00:00+00:00',
            ],
        ];

        $result = (new AppointmentNotificationLogExplorerService())->build($entries, [
            'tenant_id' => 7,
            'channel' => 'email',
            'status' => 'problem',
            'query' => 'autenticazione',
        ]);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('evt_failed_email', $result['rows'][0]['event_id']);
        $this->assertSame(1, $result['summary']['problems']);
        $this->assertSame(1, $result['summary']['email']);
    }

    public function testSmsDeliveryReceiptTakesPrecedenceOverAcceptedStatus(): void
    {
        $result = (new AppointmentNotificationLogExplorerService())->build([[
            'event_id' => 'evt_sms',
            'tenant_id' => 7,
            'channel' => 'sms',
            'status' => 'sent',
            'delivery_status' => 'delivered',
            'created_at' => '2026-09-05T12:00:00+00:00',
        ]]);

        $this->assertSame('delivered', $result['rows'][0]['status_group']);
        $this->assertSame(1, $result['summary']['delivered']);
        $this->assertSame(0, $result['summary']['accepted']);
    }
}
