<?php

namespace Tests\Unit;

use App\Config\Fse2;
use App\Services\FseArtifactValidationService;
use CodeIgniter\Test\CIUnitTestCase;

final class FseArtifactValidationTest extends CIUnitTestCase
{
    public function testMissingRuntimeFailsClosed(): void
    {
        $config = new Fse2();
        $config->validatorPython = '';
        $this->expectExceptionMessage('Validatore locale FSE non configurato');
        (new FseArtifactValidationService($config))->buildPdf('<ClinicalDocument/>');
    }

    public function testWorkerSuccessWithWrongHashIsNotAccepted(): void
    {
        $service = new class extends FseArtifactValidationService {
            protected function run(array $job): array { return ['ok' => true, 'pdfa' => '3b', 'signature' => 'valid', 'pdf_sha256' => str_repeat('0', 64), 'cda_sha256' => hash('sha256', 'cda')]; }
        };
        $this->expectExceptionMessage('non verificabile');
        $service->check('cda', 'pdf', 'unsigned', 'VRDLGI70A01H501X');
    }

    public function testOnlySafeEvidenceIsReturned(): void
    {
        $service = new class extends FseArtifactValidationService {
            protected function run(array $job): array { return ['ok' => true, 'pdfa' => '3b', 'signature' => 'valid', 'pdf_sha256' => hash('sha256', 'pdf'), 'cda_sha256' => hash('sha256', 'cda'), 'author_cf' => 'NOT-TO-BE-LOGGED', 'pdf' => 'NOT-TO-BE-LOGGED']; }
        };
        $evidence = $service->check('cda', 'pdf', 'unsigned', 'VRDLGI70A01H501X');
        $this->assertArrayNotHasKey('author_cf', $evidence);
        $this->assertArrayNotHasKey('pdf', $evidence);
    }

    public function testRealWorkerReadinessIsTechnicalOnly(): void
    {
        if (getenv('FSE2_RUN_ARTIFACT_INTEGRATION') !== '1') $this->markTestSkipped('Enable the isolated artifact runtime explicitly.');
        $result = (new FseArtifactValidationService())->readiness(true);
        $this->assertSame('configured', $result['runtime']); $this->assertSame('passed', $result['artifacts']);
        $this->assertSame('not_assessed', $result['qualified_signature']);
        $this->assertArrayNotHasKey('pdf', $result);
    }

    public function testRealPythonWorkerBuildsVerifiedSyntheticPdf(): void
    {
        if (getenv('FSE2_RUN_ARTIFACT_INTEGRATION') !== '1') $this->markTestSkipped('Enable the isolated artifact runtime explicitly.');
        $config = new Fse2();
        $service = new FseArtifactValidationService($config);
        $cda = (new \App\Services\FseCdaRsaBuilderService())->build(require SUPPORTPATH . 'fse_synthetic.php');
        $pdf = $service->buildPdf($cda);
        $this->assertStringStartsWith('%PDF-1.7', $pdf);
        $evidence = $service->check($cda, $pdf);
        $this->assertSame('3b', $evidence['pdfa']);
        $this->assertSame(hash('sha256', $cda), $evidence['cda_sha256']);
    }

    public function testRealPythonAndJavaIgnoreParentInterpreterOptions(): void
    {
        if (getenv('FSE2_RUN_ARTIFACT_INTEGRATION') !== '1') $this->markTestSkipped('Enable the isolated artifact runtime explicitly.');
        $before = getenv('JAVA_TOOL_OPTIONS');
        // Java would reject this invented option if the worker inherited it.
        putenv('JAVA_TOOL_OPTIONS=-FSE_SYNTHETIC_INVALID_OPTION');
        try {
            $result = (new FseArtifactValidationService())->readiness(true);
            $this->assertSame('passed', $result['artifacts']);
            $this->assertSame('-FSE_SYNTHETIC_INVALID_OPTION', getenv('JAVA_TOOL_OPTIONS'));
        } finally { putenv($before === false ? 'JAVA_TOOL_OPTIONS' : 'JAVA_TOOL_OPTIONS='.$before); }
    }
}
