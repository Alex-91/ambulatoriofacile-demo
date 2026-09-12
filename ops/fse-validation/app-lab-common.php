<?php
// This file is not a public application endpoint. Dedicated CLI/server entry points below own it.
function fse_lab_config(): array
{
    $root = realpath((string) getenv('FSE_LAB_ROOT'));
    $base = realpath(__DIR__.'/../../rest/writable');
    if (!$root || !$base || !preg_match('#^'.preg_quote(str_replace('\\','/',$base), '#').'/fse-app-labs/[a-f0-9]{32}$#D', str_replace('\\','/',$root))) throw new RuntimeException('Invalid synthetic lab root.');
    $config = json_decode(file_get_contents($root.'/lab.json'), true, flags:JSON_THROW_ON_ERROR);
    if (($config['mode']??'') !== 'FSE_SYNTHETIC_APP_LAB' || ($config['port']??0)!==33079 || ($config['web_port']??0)!==8088) throw new RuntimeException('Not an isolated FSE lab.');
    return $config + ['root'=>$root];
}
function fse_lab_environment(array $lab): void
{
    // Never inherit real database/SMTP/encryption/tenant settings from the launching shell.
    foreach (array_unique(array_merge(array_keys(getenv()), array_keys($_SERVER), array_keys($_ENV))) as $key) {
        if (preg_match('/^(database\.|DB_|PLATFORM_DB_|TENANT_|tenant\.|crypto\.|encryption\.|FSE2_|TS_BILLING_|email\.|EMAIL_|SMTP_)/i', $key)) { putenv($key); unset($_ENV[$key], $_SERVER[$key]); }
    }
    $root = realpath(__DIR__.'/../..');
    $linux = ($lab['runtime'] ?? '') === 'linux-container';
    if ($linux && (PHP_OS_FAMILY !== 'Linux' || !is_file('/opt/fse/linux-lab-image'))) {
        throw new RuntimeException('Linux synthetic runtime not installed.');
    }
    foreach (['CI_ENVIRONMENT'=>'development', 'app.baseURL'=>'http://127.0.0.1:8088/', 'APP_BASE_URL'=>'http://127.0.0.1:8088/',
        'app.indexPage'=>'', 'DB_ENCRYPTION_KEY'=>$lab['secret_key'], 'FSE2_SECRET_KEY'=>$lab['secret_key'], 'FSE_LAB_DB_PASSWORD'=>$lab['password'],
        'FSE2_ALLOW_PRODUCTION'=>'false', 'FSE2_ALLOW_TOSCANA_STAGE'=>'false', 'TS_BILLING_SECRET_KEY'=>$lab['secret_key'],
        'FSE2_TEST_GATEWAY_URL'=>'https://127.0.0.1:1/blocked', 'FSE2_PRODUCTION_GATEWAY_URL'=>'https://127.0.0.1:1/blocked',
        'TS_BILLING_TEST_DOCUMENT_ENDPOINT'=>'https://127.0.0.1:1/blocked', 'TS_BILLING_TEST_RECEIPTS_ENDPOINT'=>'https://127.0.0.1:1/blocked',
        'TS_BILLING_PRODUCTION_DOCUMENT_ENDPOINT'=>'https://127.0.0.1:1/blocked', 'TS_BILLING_PRODUCTION_RECEIPTS_ENDPOINT'=>'https://127.0.0.1:1/blocked',
        'FSE2_VALIDATOR_PYTHON'=>$linux ? '/opt/fse/venv/bin/python' : $root.'/rest/writable/fse-validation-venv/Scripts/python.exe',
        'FSE2_VALIDATOR_SETTINGS'=>is_file($lab['root'].'/signing/settings.json') ? $lab['root'].'/signing/settings.json' : ($linux ? '/opt/fse/settings.json' : $root.'/rest/writable/fse-validator-settings.json'),
        'XDEBUG_MODE'=>'off'] as $key=>$value) { putenv($key.'='.$value); $_ENV[$key]=$_SERVER[$key]=$value; }
}
function fse_lab_boot(bool $web = false): void
{
    $lab = fse_lab_config(); $GLOBALS['fse_synthetic_lab'] = $lab; fse_lab_environment($lab);
    define('FSE_SYNTHETIC_APP_LAB_BOOT', $lab['root']);
    if (($lab['runtime'] ?? '') === 'linux-container') {
        // The product also loads root Composer packages; the isolated router replaces its entry point.
        require_once __DIR__.'/../../vendor/autoload.php';
    }
    if (getenv('FSE_DEPENDENCY_LAB')) {
        define('WRITEPATH', $lab['root'].'/writable/');
        require __DIR__.'/app-lab-dependencies.php';
    }
    if (getenv('FSE_FRAMEWORK_LAB')) {
        defined('WRITEPATH') || define('WRITEPATH', $lab['root'].'/writable/');
        require __DIR__.'/framework-bootstrap.php';
    }
    $root = realpath(__DIR__.'/../../rest').DIRECTORY_SEPARATOR;
    foreach (['HOMEPATH'=>$root, 'CONFIGPATH'=>$root.'app/Config/', 'PUBLICPATH'=>dirname($root).'/public/',
        'APPPATH'=>$root.'app/', 'ROOTPATH'=>$root, 'SYSTEMPATH'=>$root.'system/', 'WRITEPATH'=>$lab['root'].'/writable/',
        'TESTPATH'=>$root.'tests/', 'CIPATH'=>$root, 'FCPATH'=>realpath(__DIR__.'/../..').'/', 'SUPPORTPATH'=>$root.'tests/_support/',
        'COMPOSER_PATH'=>$root.'vendor/autoload.php', 'VENDORPATH'=>$root.'vendor/'] as $key=>$value) defined($key) || define($key,$value);
    define('ENVIRONMENT','development');
    require CONFIGPATH.'Paths.php'; require SYSTEMPATH.'Boot.php';
    require __DIR__.'/app-lab-boot.php';
    $paths = new \Config\Paths(); $paths->writableDirectory = WRITEPATH; $paths->systemDirectory = SYSTEMPATH;
    if ($web) FseLabBoot::bootWeb($paths);
    else { FseLabBoot::bootTest($paths); service('routes')->loadRoutes(); }
}
