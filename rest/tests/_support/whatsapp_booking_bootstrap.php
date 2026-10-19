<?php
// CLI-only isolated framework boot; never loads project .env or production DB config.
if(PHP_SAPI!=='cli')exit;
define('ENVIRONMENT','testing');$_SERVER['CI_ENVIRONMENT']='testing';$_SERVER['HTTP_HOST']='booking-test.invalid';
$_SERVER['app.baseURL']='http://booking-test.invalid/';$_SERVER['CODEIGNITER_SCREAM_DEPRECATIONS']='0';
$root=realpath(__DIR__.'/../..').DIRECTORY_SEPARATOR;
foreach(['HOMEPATH'=>$root,'CONFIGPATH'=>$root.'app/Config/','PUBLICPATH'=>$root.'public/','APPPATH'=>$root.'app/','ROOTPATH'=>$root,'SYSTEMPATH'=>$root.'system/','WRITEPATH'=>$root.'writable/','TESTPATH'=>$root.'tests/','CIPATH'=>$root,'FCPATH'=>$root.'public/','SUPPORTPATH'=>$root.'tests/_support/','COMPOSER_PATH'=>$root.'vendor/autoload.php','VENDORPATH'=>$root.'vendor/'] as $name=>$value)defined($name)||define($name,$value);
foreach(array_unique(array_merge(array_keys(getenv()),array_keys($_ENV),array_keys($_SERVER))) as $key)if(preg_match('/^(database\.|DB_|PLATFORM_DB_|TENANT_DB_|encryption\.)/i',$key)){putenv($key);unset($_ENV[$key],$_SERVER[$key]);}
require CONFIGPATH.'Paths.php';require SYSTEMPATH.'Boot.php';
final class WhatsAppSyntheticBoot extends \CodeIgniter\Boot {protected static function loadDotEnv(\Config\Paths $paths): void {}}
$paths=new \Config\Paths();$paths->systemDirectory=SYSTEMPATH;$paths->writableDirectory=WRITEPATH;WhatsAppSyntheticBoot::bootTest($paths);
require __DIR__.'/whatsapp_booking_database.php';
