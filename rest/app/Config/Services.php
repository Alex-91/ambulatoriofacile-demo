<?php
namespace Config;

use App\Libraries\PushService;

class Services extends \CodeIgniter\Config\BaseService
{
    public static function push(bool $getShared = true): PushService
    {
        if ($getShared) {
            return static::getSharedInstance('push');
        }
        return new PushService(config('Push'));
    }
}
