<?php

namespace Tests\Unit;

use App\Services\FseGatewayResponse;
use App\Services\FseGatewayTransport;
use CodeIgniter\Test\CIUnitTestCase;

final class FseGatewayLimitsTest extends CIUnitTestCase
{
    public function testChunkedResponseIsParsedWithoutRetainingArbitraryMessages(): void
    {
        $response = new FseGatewayResponse();
        $chunk = '{"workflowInstanceId":"abc"';
        $this->assertSame(strlen($chunk), $response->captureBody($chunk));
        $response->captureBody(',"message":"PRIVATE"}');
        $result = $response->result($response->body(), 202);
        $this->assertTrue($result['ok']);
        $this->assertSame(['workflowInstanceId' => 'abc'], $result['payload']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
    }

    public function testBodyOverflowAbortsPreservesCartIdAndRequiresReconciliation(): void
    {
        $response = new FseGatewayResponse();
        $response->captureHeader("X-CART-id: synthetic-correlation\r\n");
        $chunk = str_repeat('x', FseGatewayResponse::MAX_BODY_BYTES);
        $this->assertSame(strlen($chunk), $response->captureBody($chunk));
        $this->assertSame(0, $response->captureBody('x'));
        $this->assertSame(0, $response->captureBody('{}'));
        $result = $response->result($response->body(), 202, CURLE_WRITE_ERROR);
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['outcome_uncertain']);
        $this->assertSame('synthetic-correlation', $result['x_cart_id']);
        $this->assertSame('RESPONSE_LIMIT', $result['error_category']);
        $this->assertSame([], $result['payload']);
    }

    public function testHeaderBudgetIncludesInterimResponses(): void
    {
        $response = new FseGatewayResponse();
        $response->captureHeader("HTTP/1.1 100 Continue\r\n");
        $response->captureHeader("X-CART-id: interim\r\n");
        $response->captureHeader("HTTP/1.1 202 Accepted\r\n");
        $response->captureHeader("X-CART-id: final-id\r\n");
        $this->assertSame(0, $response->captureHeader(str_repeat('x', FseGatewayResponse::MAX_HEADER_BYTES)));
        $result = $response->result('{}', 202);
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['outcome_uncertain']);
        $this->assertSame('final-id', $result['x_cart_id']);
    }

    public function testDirectOversizeParsingAlsoFailsClosed(): void
    {
        $result = (new FseGatewayResponse())->result(str_repeat(' ', FseGatewayResponse::MAX_BODY_BYTES) . '{}', 200);
        $this->assertTrue($result['outcome_uncertain']);
        $this->assertSame('RESPONSE_LIMIT', $result['error_category']);
    }

    public function testNonHttpsSchemeIsDeniedByRealCurlWithoutNetwork(): void
    {
        $result = (new FseGatewayTransport())->send('file:///' . str_replace('\\', '/', __FILE__), [CURLOPT_RETURNTRANSFER => true]);
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['outcome_uncertain']);
        $this->assertSame(CURLE_UNSUPPORTED_PROTOCOL, $result['curl_error_code']);
    }
}
