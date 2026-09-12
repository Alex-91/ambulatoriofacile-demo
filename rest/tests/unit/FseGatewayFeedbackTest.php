<?php
namespace Tests\Unit;

use App\Services\{FseGatewayFeedback, FseGatewayResponse};
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FseGatewayFeedbackTest extends CIUnitTestCase
{
    public static function failures(): array
    {
        return [[400,'INVALID_REQUEST','error'],[401,'ACCESS_DENIED','error'],[403,'ACCESS_DENIED','error'],
            [408,'OUTCOME_UNCERTAIN','warning'],[409,'IDENTIFIER_CONFLICT','error'],[422,'CDA_SEMANTIC','error'],
            [429,'RATE_LIMITED','error'],[500,'OUTCOME_UNCERTAIN','warning'],[504,'OUTCOME_UNCERTAIN','warning']];
    }

    #[DataProvider('failures')]
    public function testSafeGuidanceNeverLeaksRemoteDetailOrRetries(int $http, string $code, string $severity): void
    {
        $result = (new FseGatewayResponse())->result('{"detail":"PRIVATE_PATIENT <script>secret</script>","traceID":"TEST.TRACE"}', $http);
        $feedback = FseGatewayFeedback::describe($result, 'validation');
        $this->assertSame($code, $feedback['code']); $this->assertSame($severity, $feedback['severity']);
        $this->assertFalse($feedback['automatic_retry']);
        $this->assertStringNotContainsString('PRIVATE_PATIENT', json_encode([$result,$feedback]));
    }

    public function testOnlyKnownVocabularyErrorIsClassifiedAndDetailDiscarded(): void
    {
        $response = new FseGatewayResponse();
        $known = $response->result('{"type":"/msg/vocabulary","detail":"PRIVATE"}',400);
        $this->assertSame('CDA_VOCABULARY',FseGatewayFeedback::describe($known,'validation')['code']);
        $unknown = $response->result('{"type":"/msg/vocabulary/PRIVATE"}',400);
        $this->assertNull($unknown['error_category']);
        $this->assertStringNotContainsString('PRIVATE',json_encode([$known,$unknown]));
    }

    public function testReceiptAndValidationAreNotPublicationConfirmation(): void
    {
        $accepted=['ok'=>true,'http_status'=>201];
        $pending=FseGatewayFeedback::describe($accepted,'publish','publishing');
        $this->assertSame('REMOTE_PENDING',$pending['code']); $this->assertSame('warning',$pending['severity']);
        $validated=FseGatewayFeedback::describe($accepted,'validation','validated');
        $this->assertStringContainsString('non è ancora pubblicato',$validated['message']);
        $failure=FseGatewayFeedback::describe($accepted,'status','published','INI_DELETE_BLOCKING_ERROR');
        $this->assertSame('REMOTE_OPERATION_REJECTED',$failure['code']); $this->assertSame('error',$failure['severity']);
    }

    public function testJwtFailurePlaceholderIsNotTreatedAsWorkflow(): void
    {
        $result=(new FseGatewayResponse())->result('{"workflowInstanceId":"UNKNOWN_WORKFLOW_ID","traceID":"REAL.TEST.TRACE"}',403);
        $this->assertArrayNotHasKey('workflowInstanceId',$result['payload']);
        $this->assertSame('REAL.TEST.TRACE',$result['payload']['traceID']);
    }
}
