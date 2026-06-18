<?php

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database Configuration
 */
class Database extends Config
{
    /**
     * The directory that holds the Migrations and Seeds directories.
     */
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    /**
     * Lets you choose which connection group to use if no other is specified.
     */
    public string $defaultGroup = 'default';

    /**
     * The default database connection.
     *
     * @var array<string, mixed>
     */
    public array $default = [];

    /**
     * Constructor to initialize the default database connection.
     */
    public function __construct()
    {
        parent::__construct();
       
        // Set the default group to 'tests' if we're running automated tests
        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }

        // Initialize the default database connection settings
        $this->default = [
            'DSN'          => '',
            'hostname'     => getenv('database.default.hostname') ?: 'localhost',
            'username'     => getenv('database.default.username') ?: 'root',
            'password'     => getenv('database.default.password') ?: 'root',
            'database'     => getenv('database.default.database') ?: 'mail',
            'DBDriver'     => getenv('database.default.DBDriver') ?: 'MySQLi',
            'DBPrefix'     => '',
            'pConnect'     => false,
            'DBDebug'      => (getenv('CI_ENVIRONMENT') === 'development'),
            'charset'      => 'latin1',
            'DBCollat'     => 'latin1_swedish_ci',
            'swapPre'      => '',
            'encrypt'      => false,
            'compress'     => false,
            'strictOn'     => false,
            'failover'     => [],
            'port'         => (int) (getenv('database.default.port') ?: 3306),
            'numberNative' => false,
            'foundRows'    => false,
            'dateFormat'   => [
                'date'     => 'Y-m-d',
                'datetime' => 'Y-m-d H:i:s',
                'time'     => 'H:i:s',
            ],
            'options'  => [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, // Abilita le eccezioni sugli errori PDO
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES latin1, lc_time_names = 'it_IT', block_encryption_mode = 'aes-256-cbc', @key_str = SHA2('".getenv('database.default.DB_ENCRYPTION_KEY')."', 512), @init_vector = RANDOM_BYTES(16)",
            \PDO::ATTR_AUTOCOMMIT => false, // Disabilita l'autocompletamento delle transazioni
        ],
        ];

     
    }

    /**
     * SQLite3 test database configuration.
     */
    public array $tests = [
        'DSN'         => '',
        'hostname'    => 'localhost',
        'username'    => 'root',
        'password'    => 'root',
        'database'    => 'mail',
        'DBDriver'    => 'MySQLi',
        'DBPrefix'    => '',  // Needed to ensure we're working correctly with prefixes live. DO NOT REMOVE FOR CI DEVS
        'pConnect'    => false,
        'DBDebug'     => true,
        'charset'     => 'utf8',
        'DBCollat'    => '',
        'swapPre'     => '',
        'encrypt'     => false,
        'compress'    => false,
        'strictOn'    => false,
        'failover'    => [],
        'port'        => 3306,
        'foreignKeys' => true,
        'busyTimeout' => 1000,
        'dateFormat'  => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
        'options'  => [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, // Abilita le eccezioni sugli errori PDO
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8, lc_time_names = 'it_IT', block_encryption_mode = 'aes-256-cbc', @key_str = SHA2('PartitaIVA22', 512), @init_vector = RANDOM_BYTES(16)",
            \PDO::ATTR_AUTOCOMMIT => false, // Disabilita l'autocompletamento delle transazioni
        ],
    ];
}
