<?php

use App\Services\{BillingBusinessRecipient, BillingDocumentService, BillingDocumentSettingsService, BillingTenantDatabaseContextService, BillingTenantSchemaService, TsFeatureService, TsProfileService, BillingTsBridgeService};
use App\Models\BillingDocumentModel;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class BillingBusinessTest extends CIUnitTestCase
{
    public function testBusinessRoutesAreRegisteredAndProtectedByBillingCsrf(): void
    {
        $routes = service('routes');
        $this->assertStringContainsString('businessCreate', $routes->getRoutes('GET')['admin/fatturazione-strutture/nuovo']);
        $this->assertStringContainsString('businessSave', $routes->getRoutes('POST')['admin/fatturazione-strutture/save']);
        foreach (['admin/fatturazione-strutture','admin/fatturazione-strutture/nuovo','admin/fatturazione-strutture/save'] as $path) {
            $filters = new \CodeIgniter\Filters\Filters(new \Config\Filters(), service('request'), service('response'));
            $active = $filters->initialize($path)->getFilters();
            $this->assertContains('billingcsrf', $active['before']);
            $this->assertContains('billingcsrf', $active['after']);
        }
        $billing = \App\Libraries\TenantFeatureRegistry::definitions()['billing'];
        $this->assertContains('admin/fatturazione-strutture', $billing['route_prefixes']);
        $this->assertContains('fatturazione-strutture', $billing['menu_prefixes']);
    }

    private function fixture(): array
    {
        $db = Database::connect(['DBDriver'=>'SQLite3', 'database'=>':memory:', 'DBPrefix'=>'', 'DBDebug'=>true], false);
        $fields = (new ReflectionClass(BillingDocumentModel::class))->getDefaultProperties()['allowedFields'];
        $db->query('CREATE TABLE billing_documents (id_billing_document INTEGER PRIMARY KEY AUTOINCREMENT,'.implode(',',array_map(static fn($f)=>$f.' TEXT', $fields)).')');
        require_once APPPATH.'Database/Migrations/2026-10-08-100001_ProtectBillingNumbering.php';
        (new \App\Database\Migrations\ProtectBillingNumbering(Database::forge($db)))->up();
        $documents = new BillingDocumentModel($db);
        $context = $this->getMockBuilder(BillingTenantDatabaseContextService::class)->disableOriginalConstructor()->onlyMethods(['resolveTenantContext'])->getMock();
        $context->method('resolveTenantContext')->willReturn(['db'=>$db,'documents'=>$documents]);
        $settings = $this->getMockBuilder(BillingDocumentSettingsService::class)->disableOriginalConstructor()->onlyMethods(['resolveTenantSettings','rememberServiceCatalogItems'])->getMock();
        $settings->method('resolveTenantSettings')->willReturn(['config'=>['document_code_prefix'=>'FT','fiscal_data'=>['business_name'=>'Dott. Medico di prova','vat_number'=>'12345678903']]]);
        $schema = $this->getMockBuilder(BillingTenantSchemaService::class)->disableOriginalConstructor()->onlyMethods(['ensureTenantSchemaReady'])->getMock();
        $schema->method('ensureTenantSchemaReady')->willReturn(['ready'=>true]);
        $features = $this->getMockBuilder(TsFeatureService::class)->disableOriginalConstructor()->onlyMethods(['isEnabledForTenant'])->getMock();
        $features->method('isEnabledForTenant')->willReturn(false);
        $profiles = $this->getMockBuilder(TsProfileService::class)->disableOriginalConstructor()->onlyMethods(['resolveExpenseTypeForLineItems','resolveServiceExpenseTypeMapForTenant'])->getMock();
        $profiles->method('resolveExpenseTypeForLineItems')->willReturn(null);
        $profiles->method('resolveServiceExpenseTypeMapForTenant')->willReturn([]);
        return [new BillingDocumentService($settings,$context,$features,config(\App\Config\TsBilling::class),$schema,tsProfiles:$profiles),$documents,$db];
    }

    private function payload(): array
    {
        return ['recipient_type'=>'business','patient_name'=>'Clinica Aurora <S.r.l.>', 'business_vat_number'=>'12345678903',
            'business_country'=>'IT','patient_address'=>'Via di prova 12','patient_city'=>'Firenze','business_postal_code'=>'50100','business_province'=>'FI',
            'business_recipient_code'=>'ABC1234','business_pec'=>'contabilita@example.test',
            'issue_date'=>'2026-10-18','item_description'=>['Prestazioni professionali di ottobre'],'item_qty'=>[2],'item_unit_amount'=>['100'],
            'vat_rate'=>'22','stamp_duty_amount'=>'2', 'id_client'=>999,'ts_sync_enabled'=>1,'patient_last_name'=>'NON USARE'];
    }

    public function testSaveReopenIssueAndPrintWithoutPatientOrTs(): void
    {
        [$service,$documents] = $this->fixture();
        $payload = $this->payload();
        $draft = $service->saveDraftForTenant(1,$payload)['document'];
        $id = (int)$draft['id_billing_document'];
        $this->assertNull($draft['id_client']);
        $this->assertSame(0,(int)$draft['ts_sync_enabled']);
        $this->assertSame('Clinica Aurora <S.r.l.>',$draft['patient_name']);
        $this->assertEquals(246,$draft['amount_total']);
        $form = $service->buildFormContext(1,$id);
        $this->assertSame('12345678903',$form['document']['business_vat_number']);
        $this->assertSame('50100',$form['document']['business_postal_code']);
        $this->assertTrue(BillingBusinessRecipient::isBusiness($form['document']));
        $payload['id_billing_document']=$id;
        $issued=$service->saveDraftForTenant(1,$payload,0,'final')['document'];
        $this->assertSame('FT-2026-0001',$issued['document_number']);
        $bridge=(new ReflectionClass(BillingTsBridgeService::class))->newInstanceWithoutConstructor();
        $eligibility=(new ReflectionMethod($bridge,'validateBillingEligibility'))->invoke($bridge,$issued);
        $this->assertTrue($eligibility['blocked']);
        $html=view('admin/billing/document_pdf',['preview'=>[
            'document'=>$issued,'template'=>json_decode($issued['template_snapshot_json'],true),
            'line_items'=>json_decode($issued['line_items_json'],true),'payment_method_label'=>'Bonifico']]);
        $this->assertStringContainsString('Clinica Aurora &lt;S.r.l.&gt;',$html);
        $this->assertStringContainsString('ABC1234',$html);
        $this->assertStringContainsString('EUR 246,00',$html);
        $this->assertStringContainsString('EUR 44,00',$html);
        $this->assertStringNotContainsString('Paziente',$html);
        require_once dirname(ROOTPATH).'/vendor/autoload.php';
        $pdf=new \Dompdf\Dompdf();$pdf->loadHtml($html);$pdf->render();
        $this->assertStringStartsWith('%PDF-', $pdf->output());
        if (!is_dir(ROOTPATH.'build/business-billing')) mkdir(ROOTPATH.'build/business-billing',0777,true);
        file_put_contents(ROOTPATH.'build/business-billing/fattura-struttura.pdf',$pdf->output());
        file_put_contents(ROOTPATH.'build/business-billing/fattura-struttura.html',$html);
    }

    public function testBusinessListFiltersBeforeLimitAndSharesNumbering(): void
    {
        [$service,$documents] = $this->fixture();
        $business = $service->saveDraftForTenant(1,$this->payload(),0,'final')['document'];
        $patient=$this->payload();unset($patient['recipient_type']);$patient['id_client']=0;$patient['ts_sync_enabled']=0;
        $patient['patient_name']='Paziente di prova';$patient['patient_last_name']='';
        $invoice=$service->saveDraftForTenant(1,$patient,0,'final')['document'];
        $this->assertSame('FT-2026-0002',$invoice['document_number']);
        $this->assertEquals(202,$invoice['amount_total']); // Existing patient calculation unchanged.
        $list=$service->listDocumentsForTenant(1,1,true);
        $this->assertCount(1,$list['documents']);
        $this->assertSame($business['id_billing_document'],$list['documents'][0]['id_billing_document']);
        [$other] = $this->fixture();
        $this->assertSame([], $other->listDocumentsForTenant(2,50,true)['documents']);
    }

    public function testWrongSectionCannotOverwriteBusinessDraft(): void
    {
        [$service] = $this->fixture();
        $payload=$this->payload();$draft=$service->saveDraftForTenant(1,$payload)['document'];
        $payload['id_billing_document']=$draft['id_billing_document'];unset($payload['recipient_type']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tipo di intestatario');
        $service->saveDraftForTenant(1,$payload);
    }

    public function testMissingFiscalIdentityCannotBeSaved(): void
    {
        [$service] = $this->fixture();$payload=$this->payload();$payload['business_vat_number']='';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('partita IVA o il codice fiscale');
        $service->saveDraftForTenant(1,$payload);
    }
}
