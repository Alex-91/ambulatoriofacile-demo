<?php

namespace Tests\Unit;

use App\Services\{FseGatewayResponse, FseGatewayStatusService};
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FseGatewayStatusTest extends CIUnitTestCase
{
    public function testGatewayPayloadDropsClinicalAndSoapFields(): void
    {
        $result = (new FseGatewayResponse())->result(json_encode(['detail'=>'PRIVATE CLINICAL',
            'transactionData'=>[['eventType'=>'PUBLICATION', 'eventStatus'=>'SUCCESS', 'workflowInstanceId'=>'WF.1', 'message'=>'PRIVATE SOAP', 'subject'=>'PRIVATE CF']]]), 200);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
        $this->assertSame('WF.1', $result['payload']['transactionData'][0]['workflowInstanceId']);
    }

    public static function nonFinalEvents(): array
    {
        return [[['eventStatus'=>'SUCCESS']], [['eventType'=>'VALIDATION','workflowInstanceId'=>'WF.1','eventStatus'=>'SUCCESS']],
            [['eventType'=>'PUBLICATION','workflowInstanceId'=>'WF.1','eventStatus'=>'SUCCESS']],
            [['eventType'=>'SEND_TO_INI','workflowInstanceId'=>'WF.1','eventStatus'=>'SUCCESS']],
            [['eventType'=>'UAR_FINAL_STATUS','workflowInstanceId'=>'WF.OTHER','eventStatus'=>'SUCCESS']]];
    }

    #[DataProvider('nonFinalEvents')]
    public function testGenericOrMismatchedSuccessDoesNotCompletePublication(array $event): void
    {
        $result = (new FseGatewayStatusService())->resolve(['local_state'=>'publishing', 'workflow_instance_id'=>'WF.1'], [$event]);
        $this->assertSame('publishing', $result['state']);
    }

    public function testCorrelatedFinalEventCompletesPublication(): void
    {
        $result = (new FseGatewayStatusService())->resolve(['local_state'=>'publishing', 'workflow_instance_id'=>'WF.1'], [['eventType'=>'UAR_FINAL_STATUS', 'workflowInstanceId'=>'WF.1', 'eventStatus'=>'SUCCESS']]);
        $this->assertSame('published', $result['state']);
    }

    public function testContradictoryFinalEventsRemainPending(): void
    {
        $result = (new FseGatewayStatusService())->resolve(['local_state'=>'publishing', 'workflow_instance_id'=>'WF.1'], [
            ['eventType'=>'PUBLICATION', 'workflowInstanceId'=>'WF.1', 'eventStatus'=>'BLOCKING_ERROR'],
            ['eventType'=>'UAR_FINAL_STATUS', 'workflowInstanceId'=>'WF.1', 'eventStatus'=>'SUCCESS']]);
        $this->assertSame('publishing', $result['state']);
    }
}
