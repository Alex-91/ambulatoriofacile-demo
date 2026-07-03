<?php

use App\Services\PendingNotificationNavigationService;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\URI;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PendingNotificationNavigationServiceTest extends CIUnitTestCase
{
    private PendingNotificationNavigationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PendingNotificationNavigationService();
        $this->service->clear();
    }

    protected function tearDown(): void
    {
        $this->service->clear();
        parent::tearDown();
    }

    public function testCaptureFromAgendaNotificationRequestStoresPendingRedirect(): void
    {
        $request = $this->buildRequest(
            'https://example.test/agenda?id_dot=7&data=2026-07-03&view=day&focus_appointment=91&notification_context=doctor_cross_booking'
        );

        $this->service->captureFromRequest($request);

        $pending = service('session')->get(PendingNotificationNavigationService::SESSION_KEY);
        $this->assertIsArray($pending);
        $this->assertSame(
            '/agenda?id_dot=7&data=2026-07-03&view=day&focus_appointment=91&notification_context=doctor_cross_booking',
            $pending['url'] ?? null
        );
    }

    public function testConsumeRedirectUrlWaitsUntilAuthenticatedDestinationIsReady(): void
    {
        service('session')->set(PendingNotificationNavigationService::SESSION_KEY, [
            'url' => '/agenda?id_dot=7&data=2026-07-03&view=day&focus_appointment=91&notification_context=doctor_cross_booking',
            'created_at' => time(),
        ]);

        $this->assertSame('auth', $this->service->consumeRedirectUrl('auth'));
        $this->assertNotNull(service('session')->get(PendingNotificationNavigationService::SESSION_KEY));
    }

    public function testConsumeRedirectUrlReturnsPendingAgendaDeepLinkWhenAllowed(): void
    {
        service('session')->set(PendingNotificationNavigationService::SESSION_KEY, [
            'url' => '/agenda?id_dot=7&data=2026-07-03&view=day&focus_appointment=91&notification_context=doctor_cross_booking',
            'created_at' => time(),
        ]);

        $this->assertSame(
            '/agenda?id_dot=7&data=2026-07-03&view=day&focus_appointment=91&notification_context=doctor_cross_booking',
            $this->service->consumeRedirectUrl('agenda')
        );
        $this->assertNull(service('session')->get(PendingNotificationNavigationService::SESSION_KEY));
    }

    private function buildRequest(string $url): RequestInterface
    {
        $uri = new URI($url);
        parse_str((string) $uri->getQuery(), $query);

        $request = $this->createMock(RequestInterface::class);
        $request->method('getMethod')->willReturn('get');
        $request->method('isAJAX')->willReturn(false);
        $request->method('getUri')->willReturn($uri);
        $request->method('getGet')->willReturnCallback(static function (?string $key = null) use ($query) {
            if ($key === null) {
                return $query;
            }

            return $query[$key] ?? null;
        });

        return $request;
    }
}
