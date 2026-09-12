<?php
namespace Tests\Unit;

use App\Services\{BillingDocumentEmailService,BillingDocumentService,BillingDocumentSettingsService,TenantPatientLookupService};
use CodeIgniter\Test\CIUnitTestCase;

final class BillingEmailClosureTest extends CIUnitTestCase
{
    private function fixture(string $state='issued',string $payment='unpaid',bool $accepted=true,bool $logFails=false): BillingDocumentEmailService
    {
        $documents=$this->getMockBuilder(BillingDocumentService::class)->disableOriginalConstructor()->getMock();
        $documents->method('buildPreviewContext')->willReturn(['document'=>['local_state'=>$state,'payment_status'=>$payment,
            'patient_name'=>"Mario\r\nBcc: other@example.invalid",'document_number'=>'SYNTHETIC-1','patient_email'=>'patient@example.invalid']]);
        $documents->expects($state==='issued' && $payment!=='paid' ? $this->once() : $this->never())->method('recordEmailDeliveryForTenant')
            ->willReturnCallback(static function($tenant,$id,$type,$recipient,$subject,$body,$sent) use($accepted,$logFails) {
                self::assertSame($accepted,$sent);self::assertSame(42,$tenant);
                if($logFails) throw new \RuntimeException('Synthetic local history failure');
            });
        $settings=$this->getMockBuilder(BillingDocumentSettingsService::class)->disableOriginalConstructor()->getMock();
        $settings->method('resolveTenantSettings')->willReturn(['config'=>['email_delivery'=>['attach_pdf'=>false]]]);
        $mailer=$this->getMockBuilder(\CodeIgniter\Email\Email::class)->disableOriginalConstructor()->getMock();
        $allowed=$state==='issued' && $payment!=='paid';
        $mailer->expects($allowed ? $this->once() : $this->never())->method('send')->willReturn($accepted);
        if($allowed) {
            $mailer->expects($this->once())->method('setSubject')->with($this->callback(static fn($s)=>!str_contains($s,"\n") && !str_contains($s,"\r")));
            $mailer->expects($this->once())->method('setMessage')->with($this->callback(static fn($s)=>str_contains($s,'&lt;script&gt;') && !str_contains($s,'<script>')));
        }
        return new BillingDocumentEmailService($documents,$settings,$this->getMockBuilder(TenantPatientLookupService::class)->disableOriginalConstructor()->getMock(),static fn()=>$mailer);
    }
    public function testIssuedEmailEscapesBodyAndSanitizesExpandedSubject(): void
    {
        $result=$this->fixture()->send(42,1,'invoice','patient@example.invalid','Fattura {paziente}','<script>alert(1)</script>',7);
        $this->assertTrue($result['sent']);$this->assertArrayNotHasKey('warning',$result);
    }
    public function testAcceptedEmailWithFailedHistoryDoesNotInviteBlindRetry(): void
    {
        $result=$this->fixture(logFails:true)->send(42,1,'invoice','patient@example.invalid','Fattura {paziente}','<script>alert(1)</script>',7);
        $this->assertTrue($result['sent']);$this->assertStringContainsString('prima di ripetere',$result['warning']);
    }
    public function testTransportFailureIsNotMarkedSent(): void
    {
        $service=$this->fixture(accepted:false);$this->expectException(\RuntimeException::class);
        $service->send(42,1,'invoice','patient@example.invalid','Fattura {paziente}','<script>alert(1)</script>',7);
    }
    public function testPaidInvoiceCannotReceivePaymentReminder(): void
    {
        $service=$this->fixture(payment:'paid');$this->expectException(\RuntimeException::class);
        $service->send(42,1,'reminder','patient@example.invalid','Sollecito','Test',7);
    }
    public function testDraftCannotBeEmailed(): void
    {
        $service=$this->fixture(state:'draft');$this->expectException(\RuntimeException::class);
        $service->send(42,1,'invoice','patient@example.invalid','Fattura','Test',7);
    }
}
