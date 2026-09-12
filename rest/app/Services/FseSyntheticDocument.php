<?php

namespace App\Services;

/** Invented identities for the isolated self-test only. Not an official Gateway dataset. */
final class FseSyntheticDocument
{
    public static function data(): array
    {
        return [
            'document_unique_id' => 'AF.OFFLINE.1', 'document_oid_root' => '1.2.3.4', 'set_id' => 'AF.OFFLINE.1',
            'patient_cf' => 'RSSMRA80A01H501U', 'patient_first_name' => 'Mario', 'patient_last_name' => 'Rossi',
            'patient_birth_date' => '1980-01-01', 'patient_gender' => 'M', 'author_cf' => 'VRDLGI70A01H501X',
            'author_first_name' => 'Luigi', 'author_last_name' => 'Verdi', 'facility_name' => 'Struttura Fittizia - TEST',
            'facility_code' => 'ST01', 'facility_oid' => '1.2.3.5', 'service_start' => '2026-09-07 10:00:00',
            'service_description' => 'Prestazione sintetica per collaudo offline',
            'report_text' => 'NESSUN DATO CLINICO REALE. Documento escluso dagli invii ufficiali.',
            'document_title' => 'AUTOTEST FSE - NON UTILIZZARE COME REFERTO',
        ];
    }
}
