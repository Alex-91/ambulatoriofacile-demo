<?php

namespace App\Commands;

use App\Services\WhatsAppCampaignService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class PrioritizeWhatsAppCampaign extends BaseCommand
{
    protected $group = 'WhatsApp';
    protected $name = 'whatsapp-campaigns:prioritize';
    protected $description = 'Anteprima del riordino per appuntamento; --apply lo applica solo nella pausa notturna.';
    protected $options = ['--tenant' => 'ID spazio obbligatorio', '--campaign' => 'ID campagna', '--active' => 'Seleziona esclusivamente una singola campagna attiva nello spazio', '--apply' => 'Salva il riordino dei soli destinatari pending'];

    public function run(array $params)
    {
        $tenant = (int) CLI::getOption('tenant');
        $campaign = (int) CLI::getOption('campaign');
        if ($tenant > 0 && $campaign === 0 && CLI::getOption('active') !== null) {
            $rows = Database::connect('platform')->table('platform_whatsapp_campaigns')->select('id_whatsapp_campaign')
                ->where('id_tenant', $tenant)->where('audience_type', 'all_patients')->whereIn('status', ['queued', 'running'])->get(2)->getResultArray();
            if (count($rows) !== 1) { throw new \RuntimeException('La selezione della campagna attiva non è univoca. Specificare --campaign.'); }
            $campaign = (int) $rows[0]['id_whatsapp_campaign'];
        }
        if ($tenant <= 0 || $campaign <= 0) { throw new \InvalidArgumentException('Specificare --tenant e --campaign con ID positivi.'); }
        $result = (new WhatsAppCampaignService())->prioritizePending($tenant, $campaign, CLI::getOption('apply') !== null);
        CLI::write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
