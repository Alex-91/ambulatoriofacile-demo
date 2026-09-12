<?php

namespace Tests\Unit;

use App\Services\FseCdaRsaBuilderService;
use App\Services\FsePdfEnvelopeService;
use CodeIgniter\Test\CIUnitTestCase;

final class FseCdaPdfTest extends CIUnitTestCase
{
    public function testAuthorDoesNotInventIscoQualificationFromJwtRole(): void
    {
        $data = require SUPPORTPATH . 'fse_synthetic.php';
        $xml = new \DOMDocument();
        $this->assertTrue($xml->loadXML((new FseCdaRsaBuilderService())->build($data), LIBXML_NONET));
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('h', 'urn:hl7-org:v3');
        $this->assertSame(0, $xpath->query('/h:ClinicalDocument/h:author/h:assignedAuthor/h:code')->length);
        $this->assertSame(strtoupper($data['author_cf']), $xpath->evaluate('string(/h:ClinicalDocument/h:author/h:assignedAuthor/h:id/@extension)'));
    }

    public function testImpossibleBirthDateIsRejected(): void
    {
        $data = require SUPPORTPATH . 'fse_synthetic.php';
        $data['patient_birth_date'] = '2026-02-31';
        $this->expectExceptionMessage('Data di nascita non valida');
        (new FseCdaRsaBuilderService())->build($data);
    }

    public function testUnmappedGenderCannotBeEmittedByTheBuilder(): void
    {
        $data=require SUPPORTPATH . 'fse_synthetic.php'; $data['patient_gender']='NB';
        $this->expectExceptionMessage('Sesso amministrativo non valido');
        (new FseCdaRsaBuilderService())->build($data);
    }

    public function testImpossibleServiceDateIsNotSilentlyNormalised(): void
    {
        $data = require SUPPORTPATH . 'fse_synthetic.php';
        $data['service_start'] = '2026-02-31 10:00:00';
        $this->expectExceptionMessage('Data clinica non valida');
        (new FseCdaRsaBuilderService())->build($data);
    }

    public function testEmptyEndTimeUsesStartAndItalianTimezone(): void
    {
        $data = require SUPPORTPATH . 'fse_synthetic.php';
        $data['service_end'] = '';
        $cda = (new FseCdaRsaBuilderService())->build($data);
        $this->assertStringContainsString('<high value="20260907100000+0200"/>', $cda);
    }

    public function testRsaCdaAndPdfAttachmentAreGenerated(): void
    {
        $data = [
            'document_unique_id' => 'AF.TEST.1', 'document_oid_root' => '1.2.3.4', 'set_id' => 'AF.TEST.1',
            'patient_cf' => 'RSSMRA80A01H501U', 'patient_first_name' => 'Mario', 'patient_last_name' => 'Rossi',
            'patient_birth_date' => '1980-01-01', 'patient_gender' => 'M', 'author_cf' => 'VRDLGI70A01H501X',
            'author_first_name' => 'Luigi', 'author_last_name' => 'Verdi', 'facility_name' => 'Studio Test',
            'facility_code' => 'ST01', 'facility_oid' => '1.2.3.5', 'service_start' => '2026-09-04 10:00:00',
            'report_text' => 'Esame specialistico senza rilievi.', 'document_title' => 'Referto specialistico',
            'service_description' => 'Visita specialistica sintetica',
        ];
        $cda = (new FseCdaRsaBuilderService())->build($data);
        $xml = new \DOMDocument();
        $this->assertTrue($xml->loadXML($cda, LIBXML_NONET));
        $this->assertSame('ClinicalDocument', $xml->documentElement?->localName);
        $this->assertStringContainsString('RSSMRA80A01H501U', $cda);
        $pdf = (new FsePdfEnvelopeService())->buildUnvalidatedPreview($cda, $data);
        $this->assertStringStartsWith('%PDF-1.7', $pdf);
        $this->assertStringContainsString('/EmbeddedFiles', $pdf);
        $this->assertStringContainsString('cda.xml', $pdf);
        $this->assertStringContainsString($cda, $pdf);
    }
}
