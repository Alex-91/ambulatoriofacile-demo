<?php

namespace App\Services;

use App\Config\Fse2;

class FseCdaRsaBuilderService
{
    /** @param array<string, mixed> $data */
    public function build(array $data): string
    {
        $required = [
            'document_unique_id', 'document_oid_root', 'set_id', 'patient_cf', 'patient_first_name',
            'patient_last_name', 'patient_birth_date', 'patient_gender', 'author_cf', 'author_first_name',
            'author_last_name', 'facility_name', 'facility_code', 'facility_oid', 'service_start', 'report_text',
        ];
        foreach ($required as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                throw new \InvalidArgumentException('Campo CDA RSA obbligatorio mancante: ' . $field . '.');
            }
        }

        $effectiveTime = $this->hl7Time((string) $data['service_start']);
        $serviceEnd = $this->hl7Time((string) ($data['service_end'] ?? $data['service_start']));
        $birthDate = preg_replace('/\D+/', '', (string) $data['patient_birth_date']) ?? '';
        $gender = strtoupper(substr(trim((string) $data['patient_gender']), 0, 1));
        if (!in_array($gender, ['M', 'F', 'UN'], true)) {
            $gender = 'UN';
        }

        $loincCode = trim((string) ($data['loinc_code'] ?? '11488-4'));
        $loincName = trim((string) ($data['loinc_display_name'] ?? 'Nota di consulto'));
        $documentTitle = trim((string) ($data['document_title'] ?? 'Referto di Specialistica Ambulatoriale'));
        $reportText = trim((string) $data['report_text']);
        $diagnosis = trim((string) ($data['diagnosis_text'] ?? ''));
        $conclusions = trim((string) ($data['conclusions_text'] ?? ''));
        $reason = trim((string) ($data['reason_text'] ?? ''));
        $history = trim((string) ($data['history_text'] ?? ''));
        $findings = trim((string) ($data['findings_text'] ?? ''));
        $documentRoot = trim((string) $data['document_oid_root']);
        $documentExtension = trim((string) $data['document_unique_id']);
        $setId = trim((string) $data['set_id']);
        $version = max(1, (int) ($data['version_number'] ?? 1));
        $patientAddress = trim((string) ($data['patient_address'] ?? ''));
        $patientCity = trim((string) ($data['patient_city'] ?? ''));
        $patientEmail = trim((string) ($data['patient_email'] ?? ''));
        $facilityName = trim((string) $data['facility_name']);
        $facilityCode = trim((string) $data['facility_code']);
        $facilityOid = trim((string) $data['facility_oid']);

        $sections = [
            ['29299-5', 'Motivo della visita', $reason],
            ['11348-0', 'Anamnesi', $history],
            ['18782-3', 'Reperti', $findings],
            ['47045-0', 'Referto', $reportText],
            ['29548-5', 'Diagnosi', $diagnosis],
            ['55110-1', 'Conclusioni', $conclusions],
        ];
        $sectionXml = '';
        foreach ($sections as [$code, $title, $text]) {
            if ($text === '' && $title !== 'Referto') {
                continue;
            }
            $sectionXml .= '<component typeCode="COMP"><section>'
                . '<code code="' . $this->e($code) . '" codeSystem="' . Fse2::LOINC_OID . '" codeSystemName="LOINC"/>'
                . '<title>' . $this->e($title) . '</title><text><paragraph>' . $this->e($text) . '</paragraph></text>'
                . '</section></component>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ClinicalDocument xmlns="urn:hl7-org:v3" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:sdtc="urn:hl7-org:sdtc">'
            . '<realmCode code="IT"/><typeId root="2.16.840.1.113883.1.3" extension="POCD_MT000040UV02"/>'
            . '<templateId root="2.16.840.1.113883.2.9.10.1.9.1" extension="1.1" assigningAuthorityName="HL7 Italia"/>'
            . '<id root="' . $this->e($documentRoot) . '" extension="' . $this->e($documentExtension) . '"/>'
            . '<code code="' . $this->e($loincCode) . '" codeSystem="' . Fse2::LOINC_OID . '" codeSystemName="LOINC" displayName="' . $this->e($loincName) . '"/>'
            . '<title>' . $this->e($documentTitle) . '</title><sdtc:statusCode code="active"/>'
            . '<effectiveTime value="' . $effectiveTime . '"/><confidentialityCode code="N" codeSystem="2.16.840.1.113883.5.25"/>'
            . '<languageCode code="it-IT"/><setId root="' . $this->e($documentRoot) . '" extension="' . $this->e($setId) . '"/>'
            . '<versionNumber value="' . $version . '"/>'
            . '<recordTarget><patientRole><id extension="' . $this->e(strtoupper((string) $data['patient_cf'])) . '" root="' . Fse2::CF_OID . '"/>'
            . '<addr use="HP"><streetAddressLine>' . $this->e($patientAddress ?: 'NON DISPONIBILE') . '</streetAddressLine><city>' . $this->e($patientCity ?: 'NON DISPONIBILE') . '</city><country>IT</country></addr>'
            . ($patientEmail !== '' ? '<telecom use="HP" value="mailto:' . $this->e($patientEmail) . '"/>' : '<telecom nullFlavor="UNK"/>')
            . '<patient><name><family>' . $this->e((string) $data['patient_last_name']) . '</family><given>' . $this->e((string) $data['patient_first_name']) . '</given></name>'
            . '<administrativeGenderCode code="' . $gender . '" codeSystem="2.16.840.1.113883.5.1"/><birthTime value="' . $birthDate . '"/>'
            . '</patient></patientRole></recordTarget>'
            . '<author><time value="' . $effectiveTime . '"/><assignedAuthor><id extension="' . $this->e(strtoupper((string) $data['author_cf'])) . '" root="' . Fse2::CF_OID . '"/>'
            . '<code code="DRS" codeSystem="2.16.840.1.113883.2.9.6.2.7" displayName="Medico specialista"/>'
            . '<assignedPerson><name><family>' . $this->e((string) $data['author_last_name']) . '</family><given>' . $this->e((string) $data['author_first_name']) . '</given></name></assignedPerson>'
            . '<representedOrganization><id root="' . $this->e($facilityOid) . '" extension="' . $this->e($facilityCode) . '"/><name>' . $this->e($facilityName) . '</name></representedOrganization>'
            . '</assignedAuthor></author>'
            . '<custodian><assignedCustodian><representedCustodianOrganization><id root="' . $this->e($facilityOid) . '" extension="' . $this->e($facilityCode) . '"/><name>' . $this->e($facilityName) . '</name></representedCustodianOrganization></assignedCustodian></custodian>'
            . '<legalAuthenticator><time value="' . $effectiveTime . '"/><signatureCode code="S"/><assignedEntity><id extension="' . $this->e(strtoupper((string) $data['author_cf'])) . '" root="' . Fse2::CF_OID . '"/><assignedPerson><name><family>' . $this->e((string) $data['author_last_name']) . '</family><given>' . $this->e((string) $data['author_first_name']) . '</given></name></assignedPerson></assignedEntity></legalAuthenticator>'
            . '<documentationOf><serviceEvent classCode="ACT"><code code="AMB" displayName="Prestazione ambulatoriale"/><effectiveTime><low value="' . $effectiveTime . '"/><high value="' . $serviceEnd . '"/></effectiveTime>'
            . '<performer typeCode="PRF"><assignedEntity><id extension="' . $this->e(strtoupper((string) $data['author_cf'])) . '" root="' . Fse2::CF_OID . '"/></assignedEntity></performer>'
            . '</serviceEvent></documentationOf>'
            . '<component><structuredBody>' . $sectionXml . '</structuredBody></component></ClinicalDocument>';
    }

    private function hl7Time(string $value): string
    {
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Data clinica non valida per CDA RSA.');
        }

        return $date->format('YmdHisO');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
