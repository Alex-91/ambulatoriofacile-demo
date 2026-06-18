<?php

namespace App\Commands;

use App\Models\DoctorPatientSearchModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class RebuildDoctorPatientSearch extends BaseCommand
{
    protected $group = 'Search';
    protected $name = 'search:rebuild-doctor-patients';
    protected $description = 'Rigenera l\'indice di ricerca pazienti per dottore usato dall\'agenda.';

    public function run(array $params)
    {
        $model = new DoctorPatientSearchModel();

        CLI::write('Rigenerazione indice pazienti per dottore in corso...', 'yellow');
        $startedAt = microtime(true);

        $stats = $model->rebuildAll();

        $elapsed = microtime(true) - $startedAt;

        CLI::write('Indice rigenerato.', 'green');
        CLI::write('Righe inserite: ' . (int) ($stats['inserted_rows'] ?? 0));
        CLI::write('Righe totali: ' . (int) ($stats['total_rows'] ?? 0));
        CLI::write('Tempo: ' . number_format($elapsed, 2) . 's');
    }
}
