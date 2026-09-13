<?php
namespace Tests\Pacs;

use App\Services\ClinicalRecordService;
use App\Services\Pacs\PacsOrderService;

/** Synthetic fixtures reuse the order suite's isolated database and canonical patient lookup. */
trait PacsWorkflowCases
{
    public function testTenantMasterCanAcceptAcrossDoctorsButCannotExecuteOrRefert(): void
    {
        require_once __DIR__.'/../_support/ClinicalMasterPlatformFixture.php';
        $platform=new \Tests\Support\ClinicalMasterPlatformFixture();
        try {
            $this->db->table('dap01_users')->insert(['id_user'=>6,'is_active'=>1]);
            $this->db->table('dap03_personale')->insert(['id_personale'=>60,'id_user'=>6,'tipo'=>4,'is_active'=>1]);
            [$id]=$this->appointmentOrder(); $doctor=$this->service(); $doctor->approve(100,$id,1,true);
            $master=$this->service(6); $queue=$master->queue('2026-09-20');
            $this->assertSame(4,$queue['role']); $this->assertCount(1,$queue['rows']);
            $this->assertFalse($queue['rows'][0]['clinical_owner']);
            foreach (['payload','reason','report_entry_id','study_uid','patient_birth_date'] as $key) $this->assertArrayNotHasKey($key,$queue['rows'][0]);
            $master->advance(100,$id,2,'accepted');
            $this->assertSame('accepted',$doctor->read(100,$id)['workflow_stage']);
            $this->denied(fn()=>$master->advance(100,$id,3,'in_progress'));
            $this->denied(fn()=>$master->read(100,$id));
            $this->denied(fn()=>$master->saveReport(100,$id,3,['title'=>'x','body'=>'x']));
            $this->denied(fn()=>$master->export(100,$id,3,'dicom'));
            $this->denied(fn()=>$master->cancel(100,$id,3));
            $this->denied(fn()=>$this->pacs(6)->overview(100));
            $this->denied(fn()=>$this->service(6,43)->queue('2026-09-20'));
            $this->assertSame(6,(int)$this->db->table('pacs_audit')->where('event','pacs_stage_accepted')->get()->getRowArray()['actor_user_id']);
            $platform->db->table('platform_user_tenants')->update(['tenant_role'=>'tenant_staff']);
            $this->denied(fn()=>$master->queue('2026-09-20'));
            $platform->db->table('platform_user_tenants')->update(['tenant_role'=>'tenant_master']);
            $this->gate->enabled=false;
            $this->denied(fn()=>$master->queue('2026-09-20'));
        } finally {$platform->close();}
    }
    private function workflowFixtures(): void
    {
        $this->db->query('CREATE TABLE dap11_agenda_slot (id_slot INTEGER PRIMARY KEY,data_slot TEXT,ora_inizio TEXT)');
        $this->db->query('CREATE TABLE dap12_agenda_appuntamenti (id_appuntamento INTEGER PRIMARY KEY,id_client INTEGER,id_dot INTEGER,id_slot INTEGER,id_tipo_visita INTEGER,tipo_visita_label TEXT,stato TEXT)');
        $this->db->table('dap11_agenda_slot')->insert(['id_slot'=>10,'data_slot'=>'2026-09-20','ora_inizio'=>'10:30:00']);
        $this->db->table('dap12_agenda_appuntamenti')->insertBatch([
            ['id_appuntamento'=>10,'id_client'=>100,'id_dot'=>10,'id_slot'=>10,'id_tipo_visita'=>1,'tipo_visita_label'=>'Ecografia da agenda','stato'=>'CONFERMATO'],
            ['id_appuntamento'=>20,'id_client'=>200,'id_dot'=>50,'id_slot'=>10,'id_tipo_visita'=>1,'tipo_visita_label'=>'Altra prestazione','stato'=>'CONFERMATO'],
        ]);
        unset($this->db->dataCache['table_names']);
        require_once APPPATH.'Database/Migrations/2026-09-12-160001_CreateClinicalRecords.php';
        (new \App\Database\Migrations\CreateClinicalRecords(\Config\Database::forge($this->db)))->up();
    }
    private function appointmentOrder(): array
    {
        $this->workflowFixtures();
        $binding=$this->pacs()->bind(100,'cloud','P-100','TEST-HOSPITAL',0,true);
        $id=$this->service()->create(100,$binding,['appointment_id'=>10]+$this->input(),bin2hex(random_bytes(16)));
        return [$id,$binding];
    }
    private function performedOrder(): array
    {
        [$id,$binding]=$this->appointmentOrder(); $s=$this->service();
        $s->approve(100,$id,1,true);
        $this->service(3)->advance(100,$id,2,'accepted');
        $this->service(4)->advance(100,$id,3,'in_progress');
        $this->service(4)->advance(100,$id,4,'performed');
        return [$id,$binding];
    }
    public function testAppointmentPrefillIsAuthoritativeAndRescheduleInvalidatesReadyOrder(): void
    {
        [$id]=$this->appointmentOrder(); $s=$this->service();
        $row=$s->read(100,$id);
        $this->assertSame('Ecografia da agenda',$row['payload']['description']);
        $this->assertSame('2026-09-20T10:30',$row['payload']['scheduled_at']);
        $this->assertSame(10,(int)$row['appointment_id']);
        $this->denied(fn()=>$s->appointmentDraft(100,20));
        $this->denied(fn()=>$this->service(2)->appointmentDraft(100,10));
        $this->db->table('dap11_agenda_slot')->where('id_slot',10)->update(['ora_inizio'=>'11:30:00']);
        $this->denied(fn()=>$s->approve(100,$id,1,true));
        $s->update(100,$id,$this->input(),1);
        $this->assertSame('2026-09-20T11:30',$s->read(100,$id)['payload']['scheduled_at']);
        $s->approve(100,$id,2,true);
        $this->db->table('dap12_agenda_appuntamenti')->where('id_appuntamento',10)->update(['tipo_visita_label'=>'Prestazione corretta']);
        $this->denied(fn()=>$s->export(100,$id,3,'json'));
        $this->denied(fn()=>$this->service(3)->advance(100,$id,3,'accepted'));
        $this->assertNotSame('',$this->service(3)->queue('2026-09-20')['rows'][0]['problem']);
        $s->cancel(100,$id,3);
        $this->assertSame([],$this->service(3)->queue('2026-09-20')['rows']);
    }
    public function testReceptionHasOnlyOperationalFieldsAndCannotReadOrWriteClinicalContent(): void
    {
        [$id]=$this->appointmentOrder(); $s=$this->service();
        $this->assertSame([],$this->service(3)->queue('2026-09-20')['rows']);
        $s->approve(100,$id,1,true);
        $queue=$this->service(3)->queue('2026-09-20');
        $this->assertCount(1,$queue['rows']);
        $json=json_encode($queue,JSON_THROW_ON_ERROR);
        foreach (['Nota interna','TEST-HOSPITAL','P-100','birth_date','study_uid','payload','report_entry_id','binding_id'] as $secret) $this->assertStringNotContainsString($secret,$json);
        $this->assertFalse($queue['rows'][0]['clinical_owner']);
        foreach ([2,5] as $user) $this->assertSame([],$this->service($user)->queue('2026-09-20')['rows']);
        $this->assertSame([],$this->service(3,43)->queue('2026-09-20')['rows']);
        $this->denied(fn()=>$this->service(3)->read(100,$id));
        $this->denied(fn()=>$this->service(3)->history(100,$id));
        $this->denied(fn()=>$this->service(3)->saveReport(100,$id,2,[]));
        $this->denied(fn()=>$this->service(3)->advance(100,$id,2,'performed'));
        $this->service(3)->advance(100,$id,2,'accepted');
        $this->denied(fn()=>$this->service(3)->advance(100,$id,2,'accepted'));
        $this->denied(fn()=>$this->service(3)->advance(100,$id,3,'in_progress'));
        $this->service(4)->advance(100,$id,3,'in_progress');
        $this->denied(fn()=>$s->export(100,$id,4,'dicom'));
        $this->service(4)->advance(100,$id,4,'performed');
        $this->assertSame([],$this->service(3)->queue('2026-09-20')['rows']);
        $this->assertCount(1,$this->service(3)->queue('2026-09-20',1,true)['rows']);
        $this->gate->enabled=false;
        $this->denied(fn()=>$this->service(3)->queue('2026-09-20'));
        $this->denied(fn()=>$s->history(100,$id));
    }
    public function testAppointmentCancellationAndInactiveStaffBlockOperationalActions(): void
    {
        [$id]=$this->appointmentOrder(); $s=$this->service(); $s->approve(100,$id,1,true);
        $this->db->table('dap12_agenda_appuntamenti')->where('id_appuntamento',10)->update(['stato'=>'ANNULLATO']);
        $this->denied(fn()=>$this->service(3)->advance(100,$id,2,'accepted'));
        $this->denied(fn()=>$s->export(100,$id,2,'dicom'));
        $this->db->table('dap01_users')->where('id_user',3)->update(['is_active'=>0]);
        $this->denied(fn()=>$this->service(3)->queue('2026-09-20'));
        $s->cancel(100,$id,2);
    }
    public function testReportUsesRealChartAndCannotBeRetargetedOrDuplicated(): void
    {
        [$id]=$this->performedOrder(); $s=$this->service();
        $input=['body'=>'Testo sintetico di collaudo.','occurred_at'=>'2026-09-20T11:30'];
        $entry=$s->saveReport(100,$id,5,$input);
        $report=$s->report(100,$id);
        $this->assertSame($entry,(int)$report['id']); $this->assertSame('draft',$report['state']);
        $this->assertSame(10,(int)$report['appointment_id']); $this->assertSame('report',$report['kind']);
        $this->assertStringContainsString($s->read(100,$id)['accession'],$report['content']['title']);
        $this->denied(fn()=>$s->saveReport(100,$id,5,$input));
        $this->assertSame(1,$this->db->table('clinical_entries')->countAllResults());
        $s->saveReport(100,$id,6,['entry_revision'=>1,'body'=>'Bozza aggiornata.']+$input);
        $this->assertSame(2,(int)$s->report(100,$id)['revision']);
        $clinical=new ClinicalRecordService($this->db,42,1);
        foreach (['kind'=>'note','appointment_id'=>0] as $field=>$bad) {
            $data=['id'=>$entry,'revision'=>2,'kind'=>'report','appointment_id'=>10,'title'=>'Referto']+$input;
            $data[$field]=$bad; $this->denied(fn()=>$clinical->saveEntry(100,$data));
        }
        $this->db->table('clinical_entries')->where('id',$entry)->update(['state'=>'final']);
        $revision=$clinical->saveEntry(100,['previous_entry_id'=>$entry,'kind'=>'report','appointment_id'=>10,'title'=>'Correzione']+$input);
        $this->assertSame($revision,(int)$s->report(100,$id)['id']);
        $this->assertSame('draft',$s->report(100,$id)['state']);
        $this->denied(fn()=>$clinical->saveEntry(100,['id'=>$revision,'revision'=>1,'kind'=>'note','appointment_id'=>10,'title'=>'Modifica vietata']+$input));
        $s->saveReport(100,$id,7,['entry_revision'=>1,'body'=>'Correzione in corso']+$input);
        $this->assertSame($entry,(int)$s->read(100,$id)['report_entry_id']);
        $this->db->table('clinical_consents')->insert(['id'=>1,'id_client'=>100,'kind'=>'dossier','decision'=>'granted','evidence_object_id'=>'proof']);
        $this->assertSame($entry,(int)$this->service(2)->report(100,$id)['id']);
        $this->assertSame('final',$this->service(2)->report(100,$id)['state']);
        $events=array_column($s->history(100,$id)['rows'],'event');
        foreach (['pacs_order_created','pacs_order_approved','pacs_stage_accepted','pacs_stage_in_progress','pacs_stage_performed','pacs_report_saved'] as $event) $this->assertContains($event,$events);
    }
    public function testClinicalEntryAndOrderAssociationRollBackTogetherWhenAuditFails(): void
    {
        [$id]=$this->performedOrder();
        $this->db->query("CREATE TRIGGER reject_workflow_audit BEFORE INSERT ON pacs_audit WHEN NEW.event = 'pacs_report_saved' BEGIN SELECT RAISE(ABORT, 'synthetic audit failure'); END");
        $this->denied(fn()=>$this->service()->saveReport(100,$id,5,['body'=>'Solo test','occurred_at'=>'2026-09-20T11:30']));
        $this->assertSame(0,$this->db->table('clinical_entries')->countAllResults());
        $row=$this->db->table('pacs_orders')->where('id',$id)->get()->getRowArray();
        $this->assertNull($row['report_entry_id']); $this->assertSame(5,(int)$row['revision']);
    }
    public function testImageLinkRequiresEveryIdentifierAndRollsBackAfterConcurrentCancel(): void
    {
        [$id]=$this->performedOrder(); $s=$this->service(); $row=$s->read(100,$id);
        $study=MemoryPacsTransport::study('P-100','TEST-HOSPITAL',$row['study_uid']);
        $this->transport->replies=[MemoryPacsTransport::json([$study])];
        $this->denied(fn()=>$s->linkImages(100,$id,5));
        $this->assertSame(0,$this->db->table('pacs_study_links')->countAllResults());
        $study['00080050']['Value'][0]=$row['accession'];
        foreach (['00100020'=>'OTHER','00100021'=>'OTHER','0020000D'=>'1.2.3'] as $tag=>$bad) {
            $wrong=$study; $wrong[$tag]['Value'][0]=$bad;
            $this->transport->replies=[MemoryPacsTransport::json([$wrong])];
            $this->denied(fn()=>$s->linkImages(100,$id,5));
        }
        $this->transport->replies=[MemoryPacsTransport::json([$study])];
        $this->transport->onGet=function () use ($s,$id) { $this->transport->onGet=null; $s->cancel(100,$id,5); };
        $this->denied(fn()=>$s->linkImages(100,$id,5));
        $this->assertSame(0,$this->db->table('pacs_study_links')->countAllResults());
        $row=$this->db->table('pacs_orders')->where('id',$id)->get()->getRowArray();
        $this->assertSame('cancelled',$row['state']); $this->assertNull($row['study_link_id']);
    }
    public function testImagesLinkWithoutChangingPerformedOrReportState(): void
    {
        [$id]=$this->performedOrder(); $s=$this->service(); $row=$s->read(100,$id);
        $study=MemoryPacsTransport::study('P-100','TEST-HOSPITAL',$row['study_uid']);
        $study['00080050']['Value'][0]=$row['accession'];
        $this->transport->replies=[MemoryPacsTransport::json([$study])];
        $link=$s->linkImages(100,$id,5);
        $row=$s->read(100,$id); $this->assertSame($link,$row['study_link_id']);
        $this->assertSame('performed',$row['workflow_stage']); $this->assertNull($s->report(100,$id));
        $this->assertStringContainsString(rawurlencode($row['study_uid']),$this->transport->calls[0]['url']);
        $this->transport->replies=[MemoryPacsTransport::json([$study])];
        $this->assertSame($link,$s->linkImages(100,$id,6));
        $this->assertSame(1,$this->db->table('pacs_study_links')->countAllResults());
    }
    public function testReportPreservesStructuredFieldsEditedInTheChart(): void
    {
        [$id]=$this->performedOrder(); $s=$this->service();
        $input=['body'=>'Testo sintetico','occurred_at'=>'2026-09-20T11:30'];
        $entry=$s->saveReport(100,$id,5,$input);
        (new ClinicalRecordService($this->db,42,1))->saveEntry(100,['id'=>$entry,'revision'=>1,'kind'=>'report','appointment_id'=>10,
            'title'=>'Referto','findings'=>'Reperti sintetici','diagnosis'=>'Diagnosi sintetica']+$input);
        $s->saveReport(100,$id,6,['entry_revision'=>2,'body'=>'Testo modificato']+$input);
        $report=$s->report(100,$id);
        $this->assertSame('Reperti sintetici',$report['content']['findings']);
        $this->assertSame('Diagnosi sintetica',$report['content']['diagnosis']);
        $this->assertSame('Testo modificato',$report['content']['body']);
        $this->assertSame('Referto',$report['content']['title']);
    }
    public function testImageAssociationAuditFailureLeavesNoOrphanAndRemoteChangesBlockViewing(): void
    {
        [$id]=$this->performedOrder(); $s=$this->service(); $row=$s->read(100,$id);
        $study=MemoryPacsTransport::study('P-100','TEST-HOSPITAL',$row['study_uid']);
        $study['00080050']['Value'][0]=$row['accession'];
        $this->db->query("CREATE TRIGGER reject_image_audit BEFORE INSERT ON pacs_audit WHEN NEW.event = 'pacs_order_images_linked' BEGIN SELECT RAISE(ABORT, 'synthetic audit failure'); END");
        $this->transport->replies=[MemoryPacsTransport::json([$study])];
        $this->denied(fn()=>$s->linkImages(100,$id,5));
        $this->assertSame(0,$this->db->table('pacs_study_links')->countAllResults());
        $this->assertNull($s->read(100,$id)['study_link_id']);
        $this->db->resetTransStatus(); $this->db->query('DROP TRIGGER reject_image_audit');
        $this->transport->replies=[MemoryPacsTransport::json([$study])];
        $link=$s->linkImages(100,$id,5);
        $study['00080050']['Value'][0]='OTHER-REQUEST';
        $this->transport->replies=[MemoryPacsTransport::json([$study])];
        $this->denied(fn()=>$this->pacs()->viewer(100,$link));
    }
    public function testLegacyDateFilterAndAppointmentDoctorMapping(): void
    {
        [$id]=$this->appointmentOrder(); $s=$this->service();
        $this->denied(fn()=>$s->saveReport(100,$id,1,['body'=>'Test','occurred_at'=>'2026-09-20T11:30']));
        $this->db->query('ALTER TABLE dap03_personale ADD COLUMN legacy_id_dot INTEGER');
        unset($this->db->dataCache['field_names']['dap03_personale']);
        $this->db->table('dap03_personale')->where('id_user',1)->update(['legacy_id_dot'=>1010]);
        $this->denied(fn()=>$s->appointmentDraft(100,10));
        $this->db->table('dap12_agenda_appuntamenti')->where('id_appuntamento',10)->update(['id_dot'=>1010]);
        $s->update(100,$id,$this->input(),1); $s->approve(100,$id,2,true);
        $this->db->table('pacs_orders')->where('id',$id)->update(['scheduled_date'=>null]);
        $this->assertSame([],$this->service(3)->queue('2026-09-19')['rows']);
        $this->assertCount(1,$this->service(3)->queue('2026-09-20')['rows']);
        $this->denied(fn()=>$this->service(3)->queue('2026-02-30'));
        $history=$s->history(100,$id)['rows'];
        $this->assertSame([3,2,1],array_map('intval',array_column($history,'order_revision')));
    }

}
