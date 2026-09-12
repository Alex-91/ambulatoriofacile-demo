<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class FseViewsTest extends CIUnitTestCase
{
    private function renderFixture(string $name): \DOMXPath
    {
        $process = proc_open([PHP_BINARY, dirname(APPPATH, 2) . '/ops/fse-validation/ui-preview.php', $name],
            [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        fclose($pipes[0]); $html = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        $this->assertSame(0, $exit, $error); $this->assertSame('', $error);
        $dom = new \DOMDocument(); $prior = libxml_use_internal_errors(true);
        try { $this->assertTrue($dom->loadHTML($html)); } finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
        return new \DOMXPath($dom);
    }

    public function testDashboardSeparatesReadinessFromAccreditation(): void
    {
        $xp = $this->renderFixture('dashboard');
        $this->assertStringContainsString('non attesta accreditamento', $xp->evaluate('string(//main)'));
        $this->assertSame(1, $xp->query('//form[@method="post"]//input[@name="csrf_test"]')->length);
    }

    public function testSignedOriginalHasDisabledClinicalFieldsAndCorrectionForm(): void
    {
        $xp = $this->renderFixture('signed');
        $this->assertSame(1, $xp->query('//fieldset[@disabled]')->length);
        $this->assertSame(1, $xp->query('//textarea[@name="revision_reason"]')->length);
        $this->assertSame(0, $xp->query('//form//form')->length);
        $this->assertStringNotContainsString('Pubblica PDF firmato', $xp->evaluate('string(//main)'));
    }

    public function testCorrectionCannotExposePublicationAction(): void
    {
        $xp = $this->renderFixture('revision');
        $this->assertSame(1, $xp->query('//input[@name="edit_token"]')->length);
        $this->assertSame(0, $xp->query('//fieldset[@disabled]')->length);
        $this->assertStringContainsString('Correzione locale del referto', $xp->evaluate('string(//main)'));
        $this->assertStringNotContainsString('Pubblica PDF firmato', $xp->evaluate('string(//main)'));
    }

    public function testOfflineLabClearlyLabelsAllEightScenarios(): void
    {
        $xp = $this->renderFixture('offline');
        $this->assertSame(8, $xp->query('//tbody/tr')->length);
        $this->assertStringContainsString('Solo simulazione offline', $xp->evaluate('string(//main)'));
    }

    public function testNewDocumentPreselectsItsDefaultProfileAndRegimeSafely(): void
    {
        $xp=$this->renderFixture('new');
        $this->assertSame('9',$xp->evaluate('string(//select[@name="id_fse_profile"]/option[@selected]/@value)'));
        $this->assertSame('SSR',$xp->evaluate('string(//select[@name="administrative_request"]/option[@selected]/@value)'));
        $this->assertSame(0,$xp->query('//option/test')->length);
        $this->assertStringContainsString('Sede <test>',$xp->evaluate('string(//select[@name="id_fse_profile"])'));
    }

    public function testRejectedResponseRemainsVisibleWithoutWorkflowAndIsNotGreen(): void
    {
        $xp=$this->renderFixture('rejected');
        $this->assertSame(1,$xp->query('//*[@data-fse-last-outcome]')->length);
        $this->assertSame(0,$xp->query('//*[contains(@class,"alert-success")]')->length);
        $this->assertStringContainsString('HTTP 403',$xp->evaluate('string(//*[@data-fse-last-outcome])'));
        $this->assertStringContainsString('autorizzazione FSE rifiutata',$xp->evaluate('string(//main)'));
    }

    public function testTimeoutWarnsAndDoesNotOfferAnotherValidation(): void
    {
        $xp=$this->renderFixture('timeout');
        $this->assertSame(0,$xp->query('//*[contains(@class,"alert-success")]')->length);
        $this->assertStringContainsString('Non ripetere',$xp->evaluate('string(//main)'));
        $this->assertStringNotContainsString('Valida PDF/CDA sul Gateway',$xp->evaluate('string(//main)'));
    }

    public function testToscanaLabLabelsSimulationAndProtectsEveryAction(): void
    {
        $xp=$this->renderFixture('toscana_lab');
        $this->assertStringContainsString('nessun referto reale modificato',$xp->evaluate('string(//main)'));
        $this->assertStringContainsString('Ricezione simulata, non esito finale',$xp->evaluate('string(//main)'));
        $this->assertSame(3,$xp->query('//form[@method="post"]')->length);
        $this->assertSame(3,$xp->query('//form[@method="post"]//input[@name="csrf_test"]')->length);
        $this->assertSame(0,$xp->query('//form//form')->length);
        $this->assertSame(0,$xp->query('//input[@name="command" and @value="begin_create"]')->length);
        $this->assertSame(1,$xp->query('//input[@name="command" and @value="confirm_ok"]')->length);
    }

    public function testToscanaLabTimeoutHasNoRetryOrUnlockButtons(): void
    {
        $xp=$this->renderFixture('toscana_timeout');
        $this->assertStringContainsString('Esito incerto: nessun reinvio o sblocco disponibile',$xp->evaluate('string(//main)'));
        $this->assertSame(0,$xp->query('//article//form')->length);
        $this->assertSame(1,$xp->query('//form')->length);
    }

    public function testDocumentBridgeAppearsOnlyWhenControllerSuppliesIsolatedLabCapability(): void
    {
        $normal=$this->renderFixture('signed'); $lab=$this->renderFixture('signed_lab');
        $this->assertSame(0,$normal->query('//*[@data-fse-synthetic-bridge]')->length);
        $this->assertSame(1,$lab->query('//*[@data-fse-synthetic-bridge]//form//input[@name="csrf_test"]')->length);
        $this->assertStringContainsString('senza modificare lo stato del referto',$lab->evaluate('string(//*[@data-fse-synthetic-bridge])'));
        $this->assertSame(0,$lab->query('//form//form')->length);
    }

    public function testImportedSnapshotIsClearlyHistoricalAndCannotInventAClinicalRevision(): void
    {
        $xp=$this->renderFixture('toscana_snapshot');
        $this->assertSame(1,$xp->query('//*[@data-fse-source-snapshot]')->length);
        $this->assertStringContainsString('vale al momento dell’importazione',$xp->evaluate('string(//main)'));
        $this->assertSame(0,$xp->query('//article//input[@name="command" and @value="revise"]')->length);
        $this->assertSame(1,$xp->query('//article//input[@name="command" and @value="begin_metadata"]')->length);
        $this->assertStringNotContainsString('PRIVATE',$xp->evaluate('string(//main)'));
    }
}
