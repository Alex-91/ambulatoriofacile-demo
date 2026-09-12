<?php
// Standalone CLI bootstrap: deliberately no app bootstrap, .env, routes or database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
defined('WRITEPATH') || define('WRITEPATH', __DIR__ . '/../../rest/writable/');
if (!function_exists('env')) {
    function env(string $key, $default = null) { return $default; } // Ignore ambient production settings.
}
require_once __DIR__ . '/../../rest/system/Config/BaseConfig.php';
require_once __DIR__ . '/../../rest/app/Config/Fse2.php';
foreach (['FseCertificateInspector', 'FseJwtService', 'FseGatewayResponse', 'FseGatewayTransport', 'FseGatewayClient'] as $service) {
    require_once __DIR__ . '/../../rest/app/Services/' . $service . '.php';
}
