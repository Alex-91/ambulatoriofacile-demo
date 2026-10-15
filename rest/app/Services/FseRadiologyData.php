<?php
namespace App\Services;

final class FseRadiologyData
{
    public const FIELDS = ['patient_local_id', 'patient_local_oid', 'order_id', 'order_oid', 'exam_code', 'exam_code_system'];

    public static function normalize(array $payload): array
    {
        $result = [];
        foreach (self::FIELDS as $field) {
            $value = $payload[$field] ?? '';
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 128 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \InvalidArgumentException('Campo RAD obbligatorio o non valido: ' . $field);
            }
            $result[$field] = trim($value);
        }
        foreach (['patient_local_oid', 'order_oid', 'exam_code_system'] as $field) {
            if (!preg_match('/\A[0-2](?:\.[0-9]+)+\z/', $result[$field])) throw new \InvalidArgumentException('OID RAD non valido: ' . $field);
        }
        if ($result['patient_local_oid'] === \App\Config\Fse2::CF_OID) throw new \InvalidArgumentException('Il dominio locale RAD deve essere distinto dal codice fiscale.');
        if (!in_array($result['exam_code_system'], ['2.16.840.1.113883.6.1', '2.16.840.1.113883.6.103'], true)) {
            throw new \InvalidArgumentException('Codifica esame RAD: LOINC o ICD-9-CM.');
        }
        return $result;
    }

    public static function assertLocalProfile(array $profile): void
    {
        if (($profile['access_mode'] ?? '') !== 'toscana_privati' || ($profile['environment'] ?? '') !== 'test') {
            throw new \RuntimeException('RAD disponibile per preparazione locale con profilo Toscana di test.');
        }
    }
}
