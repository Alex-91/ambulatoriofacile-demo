<?php

namespace App\Services;

/** Fixed invented scenarios only. Cannot accept uploaded documents or real Gateway responses. */
final class FseOfflineLab
{
    public function run(): array
    {
        $cases = [
            ['CREATE', 'Firma prima dell’invio e conferma correlata', function ($s) { $s->prepare(); $s->sign(); $s->begin('create'); $s->reply('accepted', 'SIM.CREATE'); $s->confirm('create', 'SIM.CREATE', true); }, 'published'],
            ['ACCEPTED', 'Accettazione HTTP non equivale a pubblicazione', function ($s) { $s->prepare(); $s->sign(); $s->begin('create'); $s->reply('accepted', 'SIM.PENDING'); }, 'pending'],
            ['REPLACE', 'Correzione con identificativi distinti', function ($s) { $s->prepare(); $s->sign(); $s->begin('replace', 'SIM.NEW', 'SIM.OLD'); $s->reply('accepted', 'SIM.REPLACE'); $s->confirm('replace', 'SIM.REPLACE', true); }, 'published'],
            ['DELETE', 'Cancellazione confermata', function ($s) { $this->published($s); $s->begin('delete'); $s->reply('accepted', 'SIM.DELETE'); $s->confirm('delete', 'SIM.DELETE', true); }, 'deleted'],
            ['DELETE_KO', 'Cancellazione fallita conserva il pubblicato', function ($s) { $this->published($s); $s->begin('delete'); $s->reply('rejected'); }, 'published'],
            ['METADATA', 'Metadati con transazione separata', function ($s) { $this->published($s); $s->begin('metadata'); $s->reply('accepted', 'SIM.METADATA'); $s->confirm('metadata', 'SIM.METADATA', true); }, 'published'],
            ['TIMEOUT', 'Timeout mantiene il blocco', function ($s) { $s->prepare(); $s->sign(); $s->begin('create'); $s->reply('uncertain'); }, 'pending'],
            ['NO_WORKFLOW', 'Risposta incompleta richiede riconciliazione', function ($s) { $s->prepare(); $s->sign(); $s->begin('create'); $s->reply('accepted'); }, 'pending'],
        ];
        $rows = [];
        foreach ($cases as [$id, $title, $execute, $expected]) {
            $simulation = new FseToscanaSimulation();
            try {
                $execute($simulation); $snapshot = $simulation->snapshot();
                $passed = $snapshot['state'] === $expected;
            } catch (\Throwable $e) { $snapshot = []; $passed = false; }
            $rows[] = ['id' => $id, 'title' => $title, 'passed' => $passed, 'result' => $snapshot];
        }
        return ['schema_version' => 1, 'mode' => 'OFFLINE_SYNTHETIC_ONLY', 'generated_at' => gmdate('c'),
            'official_accreditation_evidence' => false, 'network_calls' => 0, 'clinical_documents_used' => 0,
            'all_passed' => !in_array(false, array_column($rows, 'passed'), true), 'scenarios' => $rows,
            'limitations' => ['Eventi finali simulati, non parser di risposte CART.', 'Firma sintetica nel modello; verifica crittografica coperta dalla suite documentale separata.', 'Test regionali e accreditamento non eseguiti.']];
    }

    private function published(FseToscanaSimulation $s): void
    {
        $s->prepare(); $s->sign(); $s->begin('create'); $s->reply('accepted', 'SIM.ORIGINAL'); $s->confirm('create', 'SIM.ORIGINAL', true);
    }
}
