<?php

namespace App\Models;

use CodeIgniter\Model;

class PushDeliveryLogModel extends Model
{
    protected $table      = 'push_delivery_logs';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'event_type',
        'user_id',
        'endpoint_hash',
        'success',
        'provider_status',
        'error_message',
        'payload_json',
        'provider_response',
        'created_at',
    ];

    protected $useTimestamps = false;
}

