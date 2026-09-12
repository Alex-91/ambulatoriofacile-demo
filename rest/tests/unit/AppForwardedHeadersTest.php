<?php
namespace Tests\Unit;

use App\Libraries\TrustedProxyPolicy;
use CodeIgniter\Test\CIUnitTestCase;

/** No HTTP listener, external proxy or DB: exercises the actual application URL policy. */
final class AppForwardedHeadersTest extends CIUnitTestCase
{
    private array $savedServer;
    protected function setUp(): void { parent::setUp(); $this->savedServer = $_SERVER; }
    protected function tearDown(): void { $_SERVER = $this->savedServer; parent::tearDown(); }

    private function origin(array $server, array $proxies = []): string
    {
        $_SERVER = $server + ['HTTP_HOST' => 'service.example', 'SCRIPT_NAME' => '/rest/index.php', 'REQUEST_SCHEME' => 'http'];
        $app = new \Config\App(); $app->proxyIPs = $proxies;
        return (new \ReflectionMethod($app, 'detectRequestBaseUrl'))->invoke($app);
    }

    public function testUntrustedForwardedHostAndSchemeAreIgnored(): void
    {
        $this->assertSame('http://service.example/rest/', $this->origin(['REMOTE_ADDR' => '192.0.2.10',
            'HTTP_X_FORWARDED_HOST' => 'untrusted.example', 'HTTP_X_FORWARDED_PROTO' => 'https']));
    }

    public function testTrustedPeerPreservesProxyHttpsAndFirstAuthority(): void
    {
        $this->assertSame('https://public.example:8443/rest/', $this->origin(['REMOTE_ADDR' => '192.0.2.10',
            'HTTP_X_FORWARDED_HOST' => 'public.example:8443, internal.example', 'HTTP_X_FORWARDED_PROTO' => 'https, http'], ['192.0.2.10' => 'X-Forwarded-For']));
    }

    public function testDirectTlsAndInvalidForwardedValues(): void
    {
        foreach (['user@public.example', 'public.example/path', 'public.example?x=1', "public.example\r\nX-Test: value", 'public.example:99999', 'public.example\\path'] as $host) {
            $this->assertSame('https://service.example/rest/', $this->origin(['REMOTE_ADDR' => '192.0.2.10', 'HTTPS' => 'on',
                'HTTP_X_FORWARDED_HOST' => $host, 'HTTP_X_FORWARDED_PROTO' => 'javascript'], ['192.0.2.10' => 'X-Forwarded-For']));
        }
    }

    public function testPeerCannotBeSpoofedThroughForwardedFor(): void
    {
        $this->assertSame('http://service.example/rest/', $this->origin(['REMOTE_ADDR' => '198.51.100.5',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.10', 'HTTP_X_FORWARDED_PROTO' => 'https'], ['192.0.2.10' => 'X-Forwarded-For']));
    }

    public function testIpv4AndIpv6BoundariesFailClosed(): void
    {
        foreach ([['192.0.2.10','192.0.2.0/24',true], ['192.0.3.1','192.0.2.0/24',false],
            ['192.0.2.127','192.0.2.0/25',true], ['192.0.2.128','192.0.2.0/25',false],
            ['2001:db8::1','2001:db8::/32',true], ['2001:db9::1','2001:db8::/32',false],
            ['::1','0:0:0:0:0:0:0:1',true], ['::1','127.0.0.1',false],
            ['192.0.2.10','0.0.0.0/0',false], ['::1','::/0',false], ['::1','::/129',false],
            ['192.0.2.10','192.0.2.0/33',false], ['192.0.2.10','192.0.2.0/-1',false],
            ['bad-peer','192.0.2.0/24',false]] as [$peer, $range, $expected]) {
            $this->assertSame($expected, TrustedProxyPolicy::matches($peer, [$range => 'X-Forwarded-For']));
        }
        $this->assertFalse(TrustedProxyPolicy::matches('192.0.2.10', ['192.0.2.10']));
    }
}
