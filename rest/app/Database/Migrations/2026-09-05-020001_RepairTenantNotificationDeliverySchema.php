<?php

namespace App\Database\Migrations;

use App\Services\TenantNotificationSchemaService;
use CodeIgniter\Database\Migration;

class RepairTenantNotificationDeliverySchema extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        (new TenantNotificationSchemaService($this->db))->ensureReady();
    }

    public function down()
    {
        // Migration di riparazione: non rimuove strutture o dati già in uso.
    }
}
