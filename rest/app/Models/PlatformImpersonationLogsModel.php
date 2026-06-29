<?php

namespace App\Models;

use CodeIgniter\Model;

class PlatformImpersonationLogsModel extends Model
{
    protected $DBGroup = 'platform';
    protected $table = 'platform_impersonation_logs';
    protected $primaryKey = 'id_platform_impersonation_log';
    protected $returnType = 'array';
    protected $allowedFields = [
        'id_platform_user',
        'id_tenant',
        'app_user_id',
        'target_username',
        'target_display_name',
        'target_tenant_role',
        'target_tipo_user',
        'reason_text',
        'session_token',
        'origin_login_source',
        'origin_path',
        'origin_ip',
        'origin_user_agent',
        'started_at',
        'expires_at',
        'ended_at',
        'end_reason',
        'metadata_json',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
