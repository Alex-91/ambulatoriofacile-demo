<?php

namespace Tests\Unit;

use App\Services\AppointmentNotificationSettingsService;
use App\Services\WhatsAppChatbotRuleEngine;
use App\Services\WhatsAppChatbotService;
use CodeIgniter\Test\CIUnitTestCase;

final class WhatsAppChatbotRuleEngineTest extends CIUnitTestCase
{
    public function testDefaultRulesRecognizeConfirmationAndCancellation(): void
    {
        $engine = new WhatsAppChatbotRuleEngine();
        $config = $engine->defaultConfig();

        $this->assertSame(
            WhatsAppChatbotRuleEngine::ACTION_CONFIRM,
            $engine->match($config, ' SÌ! ')['action'] ?? null
        );
        $this->assertSame(
            WhatsAppChatbotRuleEngine::ACTION_CANCEL,
            $engine->match($config, '2')['action'] ?? null
        );
    }

    public function testSanitizationRejectsUnsupportedActionsAndNormalizesAnswers(): void
    {
        $engine = new WhatsAppChatbotRuleEngine();
        $config = $engine->sanitizeConfig([
            'enabled' => '1',
            'response_window_hours' => 900,
            'open_on' => [AppointmentNotificationSettingsService::TYPE_REMINDER, 'unknown'],
            'rules' => [
                [
                    'name' => 'Informazioni',
                    'enabled' => '1',
                    'answers' => " INFO, info; Orari ",
                    'action' => WhatsAppChatbotRuleEngine::ACTION_REPLY,
                    'reply' => 'Siamo aperti.',
                ],
                [
                    'name' => 'Pericolosa',
                    'enabled' => '1',
                    'answers' => 'esegui',
                    'action' => 'execute_code',
                ],
            ],
        ]);

        $this->assertTrue($config['enabled']);
        $this->assertSame(720, $config['response_window_hours']);
        $this->assertSame([AppointmentNotificationSettingsService::TYPE_REMINDER], $config['open_on']);
        $this->assertCount(1, $config['rules']);
        $this->assertSame(['info', 'orari'], $config['rules'][0]['answers']);
    }

    public function testDisabledRuleDoesNotMatch(): void
    {
        $engine = new WhatsAppChatbotRuleEngine();
        $config = $engine->sanitizeConfig([
            'rules' => [[
                'name' => 'Non attiva',
                'enabled' => false,
                'answers' => 'ciao',
                'action' => WhatsAppChatbotRuleEngine::ACTION_REPLY,
                'reply' => 'Ciao',
            ]],
        ]);

        $this->assertNull($engine->match($config, 'ciao'));
    }

    public function testPhoneKeyNormalizesItalianMobileNumbers(): void
    {
        $this->assertSame('393331234567', WhatsAppChatbotService::phoneKey('+39 333 123 4567'));
        $this->assertSame('393331234567', WhatsAppChatbotService::phoneKey('3331234567'));
        $this->assertSame('', WhatsAppChatbotService::phoneKey('123'));
    }
}
