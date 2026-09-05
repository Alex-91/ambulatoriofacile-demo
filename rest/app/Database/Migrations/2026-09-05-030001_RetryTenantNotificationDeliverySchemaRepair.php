<?php

namespace App\Database\Migrations;

use App\Services\TenantNotificationSchemaService;
use CodeIgniter\Database\Migration;

class RetryTenantNotificationDeliverySchemaRepair extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        (new TenantNotificationSchemaService($this->db))->ensureReady();
    }

    public function down()
    {
        // Retry idempotente: non elimina strutture o dati delle notifiche.
    }
}
