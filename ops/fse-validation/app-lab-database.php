<?php
namespace Config;
/** No external hosts or default credentials: only the dedicated, synthetic MySQL instance. */
class Database extends \CodeIgniter\Database\Config
{
    public string $filesPath = APPPATH.'Database/';
    public string $defaultGroup = 'default';
    public array $default; public array $platform; public array $tenantRuntime; public array $tests;
    public function __construct()
    {
        $lab = $GLOBALS['fse_synthetic_lab'];
        $base = ['DSN'=>'','hostname'=>'127.0.0.1','username'=>'root','password'=>$lab['password'],'database'=>'fselab_a',
            'DBDriver'=>'MySQLi','DBPrefix'=>'','pConnect'=>false,'DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci',
            'port'=>33079,'strictOn'=>true,'failover'=>[], 'dateFormat'=>['date'=>'Y-m-d','datetime'=>'Y-m-d H:i:s','time'=>'H:i:s']];
        $this->default=$this->tenantRuntime=$this->tests=$base; $this->platform=array_replace($base,['database'=>'fselab_platform']);
    }
}
