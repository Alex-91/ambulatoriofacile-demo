<?php namespace App\Filters;

use App\Config\Crypto;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class CryptoDbVarsFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $db  = db_connect();
        $cfg = new Crypto();

        if (!$cfg->keyHex || !$cfg->ivHex) {
           // log_message('error', 'Crypto keys not configured in .env');
            return;
        }

        $db->query('SET @key_str = UNHEX(?)', [$cfg->keyHex]);
        $db->query('SET @init_vector = UNHEX(?)', [$cfg->ivHex]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
