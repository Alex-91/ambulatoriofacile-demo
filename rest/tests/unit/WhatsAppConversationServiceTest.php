<?php

namespace Tests\Unit;

use App\Services\WhatsAppConversationService;
use CodeIgniter\Test\CIUnitTestCase;

class WhatsAppConversationServiceTest extends CIUnitTestCase
{
    public function testItGroupsAndSortsIncomingAndOutgoingMessages(): void
    {
        $dashboard = (new WhatsAppConversationService())->buildDashboard([
            [
                'message_id' => 'a2',
                'peer' => '+393331111111',
                'from' => '+393331111111',
                'sender_name' => 'Alessio',
                'text' => 'Seconda risposta',
                'message_type' => 'text',
                'direction' => 'incoming',
                'received_at' => '2026-08-31T10:03:00Z',
            ],
            [
                'message_id' => 'b1',
                'peer' => '+393332222222',
                'from' => '+393332222222',
                'sender_name' => 'Simone',
                'text' => 'Conversazione più recente',
                'message_type' => 'text',
                'direction' => 'incoming',
                'received_at' => '2026-08-31T10:05:00Z',
            ],
            [
                'message_id' => 'a1',
                'peer' => '+393331111111',
                'to' => '+393331111111',
                'text' => 'Messaggio inviato',
                'message_type' => 'text',
                'direction' => 'outgoing',
                'received_at' => '2026-08-31T10:01:00Z',
            ],
        ]);

        $this->assertSame(3, $dashboard['total_messages']);
        $this->assertSame(2, $dashboard['total_conversations']);
        $this->assertSame('+393332222222', $dashboard['conversations'][0]['peer']);
        $this->assertSame('Simone', $dashboard['conversations'][0]['label']);
        $this->assertSame('Alessio', $dashboard['conversations'][1]['label']);
        $this->assertSame('outgoing', $dashboard['conversations'][1]['messages'][0]['direction']);
        $this->assertSame('incoming', $dashboard['conversations'][1]['messages'][1]['direction']);
        $this->assertSame('Seconda risposta', $dashboard['conversations'][1]['last_message']);
    }

    public function testItProvidesPreviewForNonTextMessages(): void
    {
        $dashboard = (new WhatsAppConversationService())->buildDashboard([
            [
                'message_id' => 'voice-1',
                'peer' => '+393339999999',
                'from' => '+393339999999',
                'text' => '',
                'message_type' => 'audio',
                'direction' => 'incoming',
                'received_at' => '2026-08-31T10:00:00Z',
            ],
        ]);

        $this->assertSame('Messaggio audio', $dashboard['conversations'][0]['last_message']);
    }
}
