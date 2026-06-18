<?php namespace App\Config;

use CodeIgniter\Config\BaseConfig;

class Crypto extends BaseConfig
{
    public string $keyHex = '';
    public string $ivHex  = '';

    public function __construct()
    {
        parent::__construct();
        $this->keyHex = env('crypto.key_hex', '');
        $this->ivHex  = env('crypto.iv_hex', '');
    }
}