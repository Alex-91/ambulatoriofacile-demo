<?php

namespace Tests\Unit;

use App\Services\{FseOfflineLab, FseToscanaSimulation, FseReconciliationService, FseHealthcheckService, FseArtifactValidationService, FseProfileService};
use App\Models\PlatformTenantFseProfilesModel;
use CodeIgniter\Test\CIUnitTestCase;

final class FseOfflinePreparationTest extends CIUnitTestCase
{
    public function testFixedLabNeverClaimsAccreditationOrNetworkActivity(): void
    {
        $report = (new FseOfflineLab())->run();
        $this->assertTrue($report['all_passed']); $this->assertCount(8, $report['scenarios']);
        $this->assertFalse($report['official_accreditation_evidence']); $this->assertSame(0, $report['network_calls']);
        $this->assertStringNotContainsString('patient_cf', json_encode($report));
    }

    public function testSimulationRejectsUnsignedPublication(): void
    {
        $s = new FseToscanaSimulation(); $s->prepare();
        $this->expectExceptionMessage('SIM_STATE'); $s->begin('create');
    }

    public function testSimulationRejectsDuplicateAfterTimeout(): void
    {
        $s = new FseToscanaSimulation(); $s->prepare(); $s->sign(); $s->begin('create'); $s->reply('uncertain');
        $this->expectExceptionMessage('SIM_STATE'); $s->begin('create');
    }

    public function testSimulationRejectsValidationWorkflowAsPublicationEvidence(): void
    {
        $s = new FseToscanaSimulation(); $s->prepare(); $s->sign(); $s->begin('create'); $s->reply('accepted', 'SIM.PUBLICATION');
        $this->expectExceptionMessage('SIM_CORRELATION'); $s->confirm('create', 'SIM.VALIDATION', true);
    }

    public function testSimulationRejectsWrongOperationConfirmation(): void
    {
        $s = new FseToscanaSimulation(); $s->prepare(); $s->sign(); $s->begin('create'); $s->reply('accepted', 'SIM.1');
        $this->expectExceptionMessage('SIM_CORRELATION'); $s->confirm('delete', 'SIM.1', true);
    }

    public function testSimulationRequiresNewReplacementId(): void
    {
        $s = new FseToscanaSimulation(); $s->prepare(); $s->sign();
        $this->expectExceptionMessage('SIM_REPLACEMENT_ID'); $s->begin('replace', 'SAME', 'SAME');
    }

    public function testUncertainDiagnosisNeverIncludesClinicalPayloadOrUnlock(): void
    {
        $document = ['id_fse_document'=>9, 'local_state'=>'publishing', 'patient_cf'=>'SECRET',
            'last_response_json'=>json_encode(['payload'=>['message'=>'CLINICAL PRIVATE'], 'transport'=>['outcome_uncertain'=>true, 'x_cart_id'=>'CART.1']])];
        $result = (new FseReconciliationService())->inspect($document);
        $this->assertSame('REMOTE_OUTCOME_UNCERTAIN', $result['code']);
        $this->assertFalse($result['retry_allowed']); $this->assertSame('CART.1', $result['technical']['x_cart_id']);
        $this->assertStringNotContainsString('SECRET', json_encode($result)); $this->assertStringNotContainsString('CLINICAL', json_encode($result));
    }

    public function testStaleLocalCheckIsDiagnosedWithoutMutatingState(): void
    {
        $document = ['local_state'=>'checking_signature', 'updated_at'=>'2026-09-07 10:00:00'];
        $result = (new FseReconciliationService())->inspect($document, strtotime('2026-09-07 11:00:00'));
        $this->assertSame('LOCAL_CHECK_STALE', $result['code']); $this->assertFalse($result['retry_allowed']);
        $this->assertSame('checking_signature', $document['local_state']);
    }

    public function testHealthcheckNeverEquatesTechnicalSuccessWithAccreditation(): void
    {
        $profile = $this->getMockBuilder(FseProfileService::class)->disableOriginalConstructor()->onlyMethods(['runtimeProfileForTenant', 'validate'])->getMock();
        $profile->method('runtimeProfileForTenant')->with(42)->willReturn(['id_fse_profile'=>7, 'is_enabled'=>1, 'access_mode'=>'toscana_privati']);
        $profile->method('validate')->willReturn([]);
        $model = $this->getMockBuilder(PlatformTenantFseProfilesModel::class)->disableOriginalConstructor()->onlyMethods(['update'])->getMock();
        $model->expects($this->never())->method('update');
        $validation = $this->createMock(FseArtifactValidationService::class);
        $validation->expects($this->once())->method('readiness')->with(true)->willReturn(['runtime'=>'configured', 'artifacts'=>'passed', 'trust_material'=>'configured']);
        $result = (new FseHealthcheckService($profile, $model, validation:$validation))->runForTenant(42, true, false);
        $this->assertFalse($result['operational_ready']); $this->assertNotSame('ok', $result['status']);
        $this->assertContains('Accreditamento nazionale', array_column($result['checks'], 'label'));
        $this->assertContains('Percorso Toscana', array_column($result['checks'], 'label'));
    }
}
