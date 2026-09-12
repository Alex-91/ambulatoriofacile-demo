<?php
// Dedicated synthetic suite: no local .env and no production Database configuration.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Opt-in only for the marked, private framework compatibility laboratory.
if (getenv('FSE_FRAMEWORK_LAB') && !defined('FSE_FRAMEWORK_BOOTSTRAPPING')) {
    require __DIR__ . '/framework-bootstrap.php';
    return;
}
define('ENVIRONMENT', 'testing');
$_SERVER['CI_ENVIRONMENT'] = 'testing';
$_SERVER['HTTP_HOST'] = 'fse-synthetic.invalid';
$_SERVER['app.baseURL'] = 'http://fse-synthetic.invalid/';
$_SERVER['CODEIGNITER_SCREAM_DEPRECATIONS'] = '0';
$root = realpath(__DIR__ . '/../../rest') . DIRECTORY_SEPARATOR;
foreach (['HOMEPATH'=>$root, 'CONFIGPATH'=>$root.'app/Config/', 'PUBLICPATH'=>$root.'public/',
    'APPPATH'=>$root.'app/', 'ROOTPATH'=>$root, 'SYSTEMPATH'=>$root.'system/', 'WRITEPATH'=>$root.'writable/',
    'TESTPATH'=>$root.'tests/', 'CIPATH'=>$root, 'FCPATH'=>$root.'public/', 'SUPPORTPATH'=>$root.'tests/_support/',
    'COMPOSER_PATH'=>$root.'vendor/autoload.php', 'VENDORPATH'=>$root.'vendor/'] as $name=>$value) {
    defined($name) || define($name, $value);
}
foreach (array_unique(array_merge(array_keys(getenv()), array_keys($_ENV), array_keys($_SERVER))) as $key) {
    if (preg_match('/^(database\.|DB_|PLATFORM_DB_|TENANT_DB_|encryption\.)/i', $key)) {
        putenv($key); unset($_ENV[$key], $_SERVER[$key]);
    }
}
require CONFIGPATH . 'Paths.php';
require SYSTEMPATH . 'Boot.php';
final class FseSyntheticBoot extends \CodeIgniter\Boot
{
    protected static function loadDotEnv(\Config\Paths $paths): void { /* Intentionally never read .env. */ }
}
$syntheticPaths = new \Config\Paths();
$syntheticPaths->systemDirectory = SYSTEMPATH;
$syntheticPaths->writableDirectory = WRITEPATH;
FseSyntheticBoot::bootTest($syntheticPaths);
require __DIR__ . '/synthetic-database.php';
service('routes')->loadRoutes();
