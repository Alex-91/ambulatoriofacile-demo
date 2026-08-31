<?php

namespace Tests\Unit;

use App\Services\WhatsAppTenantConsoleService;
use CodeIgniter\Test\CIUnitTestCase;

final class WhatsAppTenantConsoleServiceTest extends CIUnitTestCase
{
    public function testDeliveryLogContainsOnlyOutgoingMessagesAndNormalizesStatuses(): void
    {
        $service = new WhatsAppTenantConsoleService();
        $rows = $service->normalizeDeliveryLog([
            [
                'message_id' => 'incoming-1',
                'direction' => 'incoming',
                'from' => '+393331111111',
                'text' => 'Risposta paziente',
            ],
            [
                'message_id' => 'outgoing-1',
                'direction' => 'outgoing',
                'to' => '+393332222222',
                'text' => 'Promemoria appuntamento',
                'delivery_status' => 'delivered',
                'received_at' => '2026-08-31T16:00:00Z',
                'delivered_at' => '2026-08-31T16:00:10Z',
            ],
            [
                'message_id' => 'outgoing-legacy',
                'direction' => 'outgoing',
                'peer' => '+393333333333',
                'text' => 'Conferma appuntamento',
                'received_at' => '2026-08-31T15:00:00Z',
            ],
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame('outgoing-1', $rows[0]['message_id']);
        $this->assertSame('Consegnato', $rows[0]['status_label']);
        $this->assertSame('+393332222222', $rows[0]['recipient']);
        $this->assertSame('sent', $rows[1]['status']);
        $this->assertSame('Inviato', $rows[1]['status_label']);
    }

    public function testDeliverySummaryKeepsSentDeliveredReadAndFailedSeparate(): void
    {
        $service = new WhatsAppTenantConsoleService();
        $summary = $service->buildDeliverySummary([
            ['status' => 'read', 'sent_at' => '2026-08-31T18:30:00Z'],
            ['status' => 'delivered', 'sent_at' => '2026-08-31T18:00:00Z'],
            ['status' => 'sent', 'sent_at' => '2026-08-31T17:30:00Z'],
            ['status' => 'failed', 'sent_at' => '2026-08-31T17:00:00Z'],
        ]);

        $this->assertSame(4, $summary['total']);
        $this->assertSame(1, $summary['sent']);
        $this->assertSame(1, $summary['delivered']);
        $this->assertSame(1, $summary['read']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame('2026-08-31T18:30:00Z', $summary['last_sent_at']);
    }
}
