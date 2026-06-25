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
     * Stable connection to the central platform catalog.
     *
     * @var array<string, mixed>
     */
    public array $platform = [];

    /**
     * Per-request tenant runtime connection used after tenant context bootstrap.
     *
     * @var array<string, mixed>
     */
    public array $tenantRuntime = [];

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

        $defaultEncryptionKey = (string) (getenv('database.default.DB_ENCRYPTION_KEY') ?: '');
        $platformEncryptionKey = (string) (getenv('database.platform.DB_ENCRYPTION_KEY') ?: $defaultEncryptionKey);

        // Initialize the default database connection settings
        $baseConfig = [
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
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES latin1, lc_time_names = 'it_IT', block_encryption_mode = 'aes-256-cbc', @key_str = SHA2('".$defaultEncryptionKey."', 512), @init_vector = RANDOM_BYTES(16)",
            \PDO::ATTR_AUTOCOMMIT => false, // Disabilita l'autocompletamento delle transazioni
        ],
        ];

        $platformConfig = $baseConfig;
        $platformConfig['hostname'] = getenv('database.platform.hostname') ?: $baseConfig['hostname'];
        $platformConfig['username'] = getenv('database.platform.username') ?: $baseConfig['username'];
        $platformConfig['password'] = getenv('database.platform.password') ?: $baseConfig['password'];
        $platformConfig['database'] = getenv('database.platform.database') ?: $baseConfig['database'];
        $platformConfig['DBDriver'] = getenv('database.platform.DBDriver') ?: $baseConfig['DBDriver'];
        $platformConfig['DBPrefix'] = getenv('database.platform.DBPrefix') ?: $baseConfig['DBPrefix'];
        $platformConfig['port'] = (int) (getenv('database.platform.port') ?: $baseConfig['port']);
        $platformConfig['options'] = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES latin1, lc_time_names = 'it_IT', block_encryption_mode = 'aes-256-cbc', @key_str = SHA2('".$platformEncryptionKey."', 512), @init_vector = RANDOM_BYTES(16)",
            \PDO::ATTR_AUTOCOMMIT => false,
        ];

        $this->default = $baseConfig;
        $this->platform = $platformConfig;

     
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
