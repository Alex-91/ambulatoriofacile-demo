<?php
namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/** Regression found by real synthetic login on the candidate framework. */
final class FseFrameworkCompatibilityTest extends CIUnitTestCase
{
    public function testJsonResponsesRemainAvailableForLoginAndFseApis(): void
    {
        $this->assertSame(512, (new \Config\Format())->jsonEncodeDepth);
        $payload = ['status' => 'synthetic-only', 'tenant' => ['id' => 42], 'text' => 'più / test'];
        $response = service('response')->setJSON($payload);
        $this->assertSame($payload, json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }
}
