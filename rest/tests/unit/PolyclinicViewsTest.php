<?php
namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class PolyclinicViewsTest extends CIUnitTestCase
{
    public function testAllSectionsRenderWithoutPhpErrorsAndAllFormsHaveCsrf(): void
    {
        foreach (['accettazione','catalogo','documenti','report','integrazioni','requisiti'] as $section) {
            $process=proc_open([PHP_BINARY,'-d','xdebug.mode=off',dirname(APPPATH,2).'/ops/polyclinic-ui-preview.php',$section],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            fclose($pipes[0]); $html=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]); $code=proc_close($process);
            $this->assertSame(0,$code,$error); $this->assertSame('',$error);
            $dom=new \DOMDocument(); $previous=libxml_use_internal_errors(true);
            try { $this->assertTrue($dom->loadHTML($html)); } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
            $xp=new \DOMXPath($dom);
            $this->assertSame(0,$xp->query('//form//form')->length);
            $this->assertSame($xp->query('//form[@method="post"]')->length,$xp->query('//form[@method="post"]//input[@name="csrf_synthetic"]')->length);
            $this->assertSame(0,$xp->query('//sintetico')->length);
        }
    }
    public function testFeatureGateCoversAllAdministrationEndpoints(): void
    {
        foreach (['','/azione','/export','/xml/1','/pdf/1'] as $suffix) $this->assertSame('polyclinic_billing',\App\Libraries\TenantFeatureRegistry::resolveFeatureKeyFromRoutePath('admin/fatturazione-poliambulatori'.$suffix));
        $filters=new \Config\Filters();
        $this->assertSame(\App\Filters\BillingCsrfFilter::class,$filters->aliases['polycliniccsrf']);
        $this->assertContains('admin/fatturazione-poliambulatori/*',$filters->filters['polycliniccsrf']['before']);
        $this->assertContains('admin/fatturazione-poliambulatori/*',$filters->filters['polycliniccsrf']['after']);
    }
}
