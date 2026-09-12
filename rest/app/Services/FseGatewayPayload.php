<?php

namespace App\Services;

/** Pure local payload preparation. Does not authorize a profile or certify CART conformity. */
final class FseGatewayPayload
{
    // PublicationCreationReqDTO / PublicationUpdateReqDTO, official GTW OpenAPI checked 2026-09-11.
    private const SETTINGS = '001 002 003 004 005 006 007 008 009 010 011 012 013 014 015 018 019 020 021 024 025 026 027 028 029 030 031 032 033 034 035 036 037 038 039 040 041 042 043 046 047 048 049 050 051 052 054 055 056 057 058 060 061 062 064 065 066 067 068 069 070 071 072 073 074 075 076 077 078 094 096 097 098 099 100 101 102 103 104 107 109 121 122 126 129 130 131 199 999';

    public function publication(array $profile, array $document): array
    {
        $start = $this->serviceDate($document['service_start'] ?? null);
        $endValue = $document['service_end'] ?? '';
        $end = $endValue === '' || $endValue === null ? $start : $this->serviceDate($endValue);
        if ($end < $start) throw new \RuntimeException('La fine della prestazione FSE precede l’inizio.');
        $facility = $this->oneOf($profile['facility_type'] ?? null, ['Ospedale', 'Prevenzione', 'Territorio', 'SistemaTS', 'Cittadino', 'MdsPN_DGC'], 'tipologia struttura');
        $setting = $this->oneOf($profile['organizational_setting'] ?? null, array_map(static fn ($id) => 'AD_PSC' . $id, explode(' ', self::SETTINGS)), 'assetto organizzativo');
        $activity = $this->oneOf($profile['clinical_activity'] ?? 'ERP', ['PHR', 'CON', 'DIS', 'ERP', 'Sistema_TS', 'INI', 'PN_DGC', 'OBS'], 'attività clinica');
        $regime = $this->oneOf($document['administrative_request'] ?? null, ['SSN', 'INPATIENT', 'NOSSN', 'SSR', 'DONOR', 'AUTO'], 'regime amministrativo');
        // The application allocates root^extension IDs. Do not invent missing submission IDs.
        $submission = $this->identifier($profile['submission_oid_root'] ?? null, $document['submission_id'] ?? null, 100);
        $repository = $this->technicalString($profile['repository_id'] ?? null, 100, 'repository');
        $root = $document['document_oid_root'] ?? '';
        if ($root === '') $root = $profile['document_oid_root'] ?? null;
        return [
            'tipologiaStruttura' => $facility, 'attiCliniciRegoleAccesso' => $this->accessRules($document),
            'tipoDocumentoLivAlto' => 'REF', 'assettoOrganizzativo' => $setting,
            'dataInizioPrestazione' => $start->format('YmdHis'), 'dataFinePrestazione' => $end->format('YmdHis'),
            'tipoAttivitaClinica' => $activity, 'identificativoSottomissione' => $submission,
            'administrativeRequest' => [$regime],
            'identificativoDoc' => $this->identifier($root, $document['document_unique_id'] ?? null, 256),
            'identificativoRep' => $repository, 'mode' => 'ATTACHMENT', 'healthDataFormat' => 'CDA',
        ];
    }

    private function serviceDate(mixed $value): \DateTimeImmutable
    {
        if (is_string($value) && in_array(strlen($value), [16, 19], true) && !str_contains($value, "\0")) {
            // Values are application-local wall times, not UTC conversions. Reject PHP's
            // relative-date parsing and silent calendar rollover (e.g. 30 February).
            foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
                $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('UTC'));
                $errors = \DateTimeImmutable::getLastErrors();
                if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                    && $date->format($format) === $value && (int) $date->format('Y') > 0) return $date;
            }
        }
        throw new \RuntimeException('Data e ora della prestazione FSE non valide.');
    }

    private function oneOf(mixed $value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) throw new \RuntimeException('Valore FSE non ammesso: ' . $field . '.');
        return $value;
    }

    private function technicalString(mixed $value, int $max, string $field): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $max || preg_match('/[\s\x00-\x1F\x7F]/u', $value) !== 0) {
            throw new \RuntimeException('Identificativo FSE non valido: ' . $field . '.');
        }
        return $value;
    }

    private function identifier(mixed $root, mixed $extension, int $max): string
    {
        $root = $this->technicalString($root, $max, 'OID');
        $extension = $this->technicalString($extension, $max, 'estensione');
        if (!preg_match('/^[0-9]+(?:\.[0-9]+)+$/D', $root) || str_contains($extension, '^') || strlen($root . '^' . $extension) > $max) {
            throw new \RuntimeException('Identificativo FSE atteso nel formato OID^estensione entro la lunghezza consentita.');
        }
        return $root . '^' . $extension;
    }

    private function accessRules(array $document): array
    {
        $json = $document['access_rules_json'] ?? '';
        if ($json === '' || $json === null) return [];
        if (!is_string($json) || strlen($json) > 110000) throw new \RuntimeException('Regole di accesso FSE non valide.');
        try { $rules = json_decode($json, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new \RuntimeException('Regole di accesso FSE non valide.'); }
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 100 || !str_starts_with(ltrim($json), '[')) {
            throw new \RuntimeException('Regole di accesso FSE non valide.');
        }
        foreach ($rules as $rule) {
            if (!is_string($rule) || trim($rule) === '' || mb_strlen($rule, 'UTF-8') > 1000 || preg_match('/[\x00-\x1F\x7F]/u', $rule) !== 0) {
                throw new \RuntimeException('Regola di accesso FSE non valida.');
            }
        }
        return $rules;
    }
}
