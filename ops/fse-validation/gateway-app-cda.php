<?php
// Exercises the actual app builder using only identities from the pinned official test example.
require __DIR__ . '/gateway-bootstrap.php';
require __DIR__ . '/../../rest/app/Services/FseCdaRsaBuilderService.php';
$source = __DIR__ . '/../.local/fse-accreditamento/official-sources/d937255fd7e9c079c5641c537da17fe98a2f2259/rsa-case-24.xml';
if (!hash_equals('79655e058ea7ff4e09d71b9d0645318f55df30d816b2ec682699acb63ab63324', hash_file('sha256', $source))) throw new RuntimeException('Official example modified.');
$xml = new DOMDocument();
$xml->load($source, LIBXML_NONET);
$xpath = new DOMXPath($xml); $xpath->registerNamespace('h', 'urn:hl7-org:v3');
$value = static fn(string $path): string => (string) $xpath->evaluate('string(/h:ClinicalDocument/' . $path . ')');
$birth = $value('h:recordTarget/h:patientRole/h:patient/h:birthTime/@value');
$data = [
    'document_unique_id' => 'AF.GTW.PREPARATION.20260909', 'set_id' => 'AF.GTW.PREPARATION.20260909',
    'document_oid_root' => $value('h:id/@root'),
    'patient_cf' => $value('h:recordTarget/h:patientRole/h:id/@extension'),
    'patient_first_name' => $value('h:recordTarget/h:patientRole/h:patient/h:name/h:given'),
    'patient_last_name' => $value('h:recordTarget/h:patientRole/h:patient/h:name/h:family'),
    'patient_birth_date' => substr($birth, 0, 4) . '-' . substr($birth, 4, 2) . '-' . substr($birth, 6, 2),
    'patient_gender' => $value('h:recordTarget/h:patientRole/h:patient/h:administrativeGenderCode/@code'),
    'author_cf' => $value('h:author/h:assignedAuthor/h:id/@extension'),
    'author_first_name' => $value('h:author/h:assignedAuthor/h:assignedPerson/h:name/h:given'),
    'author_last_name' => $value('h:author/h:assignedAuthor/h:assignedPerson/h:name/h:family'),
    'facility_oid' => $value('h:custodian/h:assignedCustodian/h:representedCustodianOrganization/h:id/@root'),
    'facility_code' => $value('h:custodian/h:assignedCustodian/h:representedCustodianOrganization/h:id/@extension'),
    'facility_name' => $value('h:custodian/h:assignedCustodian/h:representedCustodianOrganization/h:name'),
    'service_start' => '2026-09-09 18:00:00', 'service_description' => 'Prestazione sintetica per verifica tecnica',
    'report_text' => 'Documento sintetico generato da Ambulatorio Facile. Nessun paziente reale. Non utilizzare per finalita cliniche.',
    'document_title' => 'TEST TECNICO FSE - NON È UN REFERTO CLINICO',
];
if (in_array('--with-sections', $argv ?? [], true)) {
    $data['reason_text'] = 'Quesito sintetico per controlli tecnici. Nessun dato clinico reale.';
    $data['history_text'] = 'Anamnesi narrativa sintetica. Nessuna osservazione clinica strutturata.';
}
echo (new \App\Services\FseCdaRsaBuilderService())->build($data);
