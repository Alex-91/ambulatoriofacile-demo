<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
final class InstallWhatsAppBookingSchema extends BaseCommand
{
    protected $group='WhatsApp';
    protected $name='whatsapp:install-bookings';
    protected $description='Installa le tabelle delle prenotazioni WhatsApp nel database platform configurato.';
    public function run(array $params)
    {
        require_once APPPATH.'Database/Migrations/2026-10-19-100001_CreateWhatsAppBookings.php';
        $db=\Config\Database::connect('platform');
        if(!$db->tableExists('platform_whatsapp_chatbots'))throw new \RuntimeException('Installare prima lo schema chatbot WhatsApp esistente.');
        (new \App\Database\Migrations\CreateWhatsAppBookings())->up();$db->resetDataCache();
        if(!(new \App\Services\WhatsAppBookingService($db))->ready())throw new \RuntimeException('Schema prenotazioni incompleto.');
        CLI::write('Schema prenotazioni WhatsApp disponibile. Nessun chatbot è stato attivato.','green');
    }
}
