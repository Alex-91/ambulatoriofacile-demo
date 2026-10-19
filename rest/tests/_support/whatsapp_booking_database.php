<?php
namespace Config;
class Database extends \CodeIgniter\Database\Config
{
    public string $filesPath=APPPATH.'Database/';public string $defaultGroup='tests';public array $default;public array $platform;public array $tenantRuntime;public array $tests;
    public function __construct(){ $this->default=$this->platform=$this->tenantRuntime=$this->tests=['DSN'=>'','DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true,'foreignKeys'=>true,'busyTimeout'=>1000,'dateFormat'=>['date'=>'Y-m-d','datetime'=>'Y-m-d H:i:s','time'=>'H:i:s']]; }
}
