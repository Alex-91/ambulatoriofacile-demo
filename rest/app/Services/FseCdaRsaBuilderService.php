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
            'author_last_name', 'facility_name', 'facility_code', 'facility_oid', 'service_start', 'service_description', 'report_text',
        ];
        foreach ($required as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                throw new \InvalidArgumentException('Campo CDA RSA obbligatorio mancante: ' . $field . '.');
            }
        }

        $effectiveTime = $this->hl7Time((string) $data['service_start']);
        $serviceEnd = $this->hl7Time(trim((string) ($data['service_end'] ?? '')) ?: (string) $data['service_start']);
        $birthDate = preg_replace('/\D+/', '', (string) $data['patient_birth_date']) ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $data['patient_birth_date'])
            || !checkdate((int) substr($birthDate, 4, 2), (int) substr($birthDate, 6, 2), (int) substr($birthDate, 0, 4))) {
            throw new \InvalidArgumentException('Data di nascita non valida per CDA RSA.');
        }
        $zone = new \DateTimeZone('Europe/Rome');
        if (new \DateTimeImmutable(trim((string) ($data['service_end'] ?? '')) ?: (string) $data['service_start'], $zone)
            < new \DateTimeImmutable((string) $data['service_start'], $zone)) {
            throw new \InvalidArgumentException('La fine prestazione precede l’inizio.');
        }
        $gender = strtoupper(trim((string) $data['patient_gender']));
        if (!in_array($gender, ['M', 'F', 'UN'], true)) {
            throw new \InvalidArgumentException('Sesso amministrativo non valido per CDA RSA.');
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
        $related = '';
        if ($version > 1) {
            $parent = $data['previous_document'] ?? null;
            if (!is_array($parent) || empty($parent['document_unique_id']) || empty($parent['document_oid_root'])
                || $parent['document_unique_id'] === $documentExtension || ($parent['set_id'] ?? '') !== $setId
                || ($parent['document_oid_root'] ?? '') !== $documentRoot
                || (int) ($parent['version_number'] ?? 0) !== $version - 1) {
                throw new \InvalidArgumentException('La revisione richiede il riferimento coerente alla versione precedente.');
            }
            $related = '<relatedDocument typeCode="RPLC"><parentDocument><id root="' . $this->e($parent['document_oid_root'])
                . '" extension="' . $this->e($parent['document_unique_id']) . '"/><setId root="' . $this->e($documentRoot)
                . '" extension="' . $this->e($setId) . '"/><versionNumber value="' . ($version - 1) . '"/></parentDocument></relatedDocument>';
        } elseif (!empty($data['previous_document'])) {
            throw new \InvalidArgumentException('La prima versione non può sostituire un documento.');
        }
        $patientAddress = trim((string) ($data['patient_address'] ?? ''));
        $patientCity = trim((string) ($data['patient_city'] ?? ''));
        $patientEmail = trim((string) ($data['patient_email'] ?? ''));
        $facilityName = trim((string) $data['facility_name']);
        $facilityCode = trim((string) $data['facility_code']);
        $facilityOid = trim((string) $data['facility_oid']);

        $sections = [
            ['29299-5', 'Motivo della visita', $reason],
            ['11329-0', 'Anamnesi', $history],
            ['29545-1', 'Reperti', $findings],
            ['47045-0', 'Referto', $reportText],
            ['29548-5', 'Diagnosi', $diagnosis],
            ['55110-1', 'Conclusioni', $conclusions],
        ];
        $sectionXml = '<component><section><code code="62387-6" codeSystem="' . Fse2::LOINC_OID . '"/>'
            . '<title>Prestazioni</title><text><paragraph ID="prestazione">' . $this->e((string) $data['service_description']) . '</paragraph></text>'
            . '<entry><act classCode="ACT" moodCode="EVN"><code nullFlavor="OTH"><originalText><reference value="#prestazione"/></originalText></code>'
            . '<effectiveTime value="' . $effectiveTime . '"/></act></entry></section></component>';
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
            . ($patientEmail !== '' ? '<telecom use="HP" value="mailto:' . $this->e($patientEmail) . '"/>' : '<telecom use="HP" nullFlavor="UNK"/>')
            . '<patient><name><family>' . $this->e((string) $data['patient_last_name']) . '</family><given>' . $this->e((string) $data['patient_first_name']) . '</given></name>'
            . '<administrativeGenderCode code="' . $gender . '" codeSystem="2.16.840.1.113883.5.1"/><birthTime value="' . $birthDate . '"/>'
            . '</patient></patientRole></recordTarget>'
            . '<author><time value="' . $effectiveTime . '"/><assignedAuthor><id extension="' . $this->e(strtoupper((string) $data['author_cf'])) . '" root="' . Fse2::CF_OID . '"/>'
            // assignedAuthor/code is optional (official RSA case 24 omits it).
            // DRS is a JWT role, not an ISCO-08 profession: never invent a qualification.
            . '<telecom use="WP" nullFlavor="UNK"/>'
            . '<assignedPerson><name><family>' . $this->e((string) $data['author_last_name']) . '</family><given>' . $this->e((string) $data['author_first_name']) . '</given></name></assignedPerson>'
            . '<representedOrganization><id root="' . $this->e($facilityOid) . '" extension="' . $this->e($facilityCode) . '"/><name>' . $this->e($facilityName) . '</name></representedOrganization>'
            . '</assignedAuthor></author>'
            . '<custodian><assignedCustodian><representedCustodianOrganization><id root="' . $this->e($facilityOid) . '" extension="' . $this->e($facilityCode) . '"/><name>' . $this->e($facilityName) . '</name></representedCustodianOrganization></assignedCustodian></custodian>'
            . '<legalAuthenticator><time value="' . $effectiveTime . '"/><signatureCode code="S"/><assignedEntity><id extension="' . $this->e(strtoupper((string) $data['author_cf'])) . '" root="' . Fse2::CF_OID . '"/><assignedPerson><name><family>' . $this->e((string) $data['author_last_name']) . '</family><given>' . $this->e((string) $data['author_first_name']) . '</given></name></assignedPerson></assignedEntity></legalAuthenticator>'
            // documentationOf is optional. Do not invent PROG/DIR when the access mode is unknown.
            . $related
            . '<componentOf><encompassingEncounter><effectiveTime><low value="' . $effectiveTime . '"/><high value="' . $serviceEnd . '"/></effectiveTime>'
            . '<location><healthCareFacility><id root="' . $this->e($facilityOid) . '" extension="' . $this->e($facilityCode) . '"/>'
            . '<serviceProviderOrganization><id root="' . $this->e($facilityOid) . '" extension="' . $this->e($facilityCode) . '"/><name>' . $this->e($facilityName) . '</name></serviceProviderOrganization>'
            . '</healthCareFacility></location></encompassingEncounter></componentOf>'
            . '<component><structuredBody>' . $sectionXml . '</structuredBody></component></ClinicalDocument>';
    }

    private function hl7Time(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/D', $value)) {
            throw new \InvalidArgumentException('Data clinica non valida per CDA RSA.');
        }
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('Europe/Rome'));
            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) throw new \InvalidArgumentException('Data inesistente.');
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
