<?php

/*
 *---------------------------------------------------------------
 * CHECK PHP VERSION
 *---------------------------------------------------------------
 */
$ciEnvironment = strtolower((string) (getenv('CI_ENVIRONMENT') ?: 'development'));
define('CI_DEBUG', $ciEnvironment !== 'production');
$minPhpVersion = '8.1'; // If you update this, don't forget to update `spark`.
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    $message = sprintf(
        'Your PHP version must be %s or higher to run CodeIgniter. Current version: %s',
        $minPhpVersion,
        PHP_VERSION,
    );

    header('HTTP/1.1 503 Service Unavailable.', true, 503);
    echo $message;

    exit(1);
}
if (DIRECTORY_SEPARATOR === '\\') {
    $opensslConf = 'C:\\xampp_82\\php\\extras\\openssl\\openssl.cnf';
    if (is_file($opensslConf)) {
        putenv('OPENSSL_CONF=' . $opensslConf);
    }
}

require __DIR__ . '/vendor/autoload.php';
// Scegline una delle due, in base a dove si trova davvero il file:
//putenv('OPENSSL_CONF=C:\xampp_82\apache\bin\openssl.cnf');

if (PHP_SAPI !== 'cli') {
    normalizeVisibleAppRequest();
}

/*
 *---------------------------------------------------------------
 * SET THE CURRENT DIRECTORY
 *---------------------------------------------------------------
 */

// Path to the front controller (this file)
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);

// Ensure the current directory is pointing to the front controller's directory
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

/*
 *---------------------------------------------------------------
 * BOOTSTRAP THE APPLICATION
 *---------------------------------------------------------------
 * This process sets up the path constants, loads and registers
 * our autoloader, along with Composer's, loads our constants
 * and fires up an environment-specific bootstrapping.
 */

// LOAD OUR PATHS CONFIG FILE
// This is the line that might need to be changed, depending on your folder structure.
require FCPATH . './rest/app/Config/Paths.php';
// ^^^ Change this line if you move your application folder

$paths = new Config\Paths();

// LOAD THE FRAMEWORK BOOTSTRAP FILE
require $paths->systemDirectory . '/Boot.php';


exit(CodeIgniter\Boot::bootWeb($paths));

function normalizeVisibleAppRequest(): void
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($requestUri === '') {
        return;
    }

    $canonicalUrl = trim((string) (getenv('APP_CANONICAL_URL') ?: getenv('APP_BASE_URL') ?: ''));
    $visibleAppPath = trim((string) parse_url($canonicalUrl, PHP_URL_PATH), '/');
    if ($visibleAppPath === '') {
        return;
    }

    $parts = parse_url($requestUri);
    if ($parts === false) {
        return;
    }

    $path = (string) ($parts['path'] ?? '');
    $prefix = '/' . $visibleAppPath;
    if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
        return;
    }

    file_put_contents(
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'af_request_debug.log',
        json_encode([
            'stage' => 'before-normalize',
            'request_uri' => $requestUri,
            'path' => $path,
            'prefix' => $prefix,
            'script_name' => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
            'path_info' => (string) ($_SERVER['PATH_INFO'] ?? ''),
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );

    $_SERVER['AF_ORIGINAL_REQUEST_URI'] = $requestUri;

    $normalizedPath = substr($path, strlen($prefix));
    if ($normalizedPath === false || $normalizedPath === '') {
        $normalizedPath = '/';
    }

    $normalizedRequestUri = $normalizedPath;
    if (isset($parts['query']) && $parts['query'] !== '') {
        $normalizedRequestUri .= '?' . $parts['query'];
    }

    $_SERVER['REQUEST_URI'] = $normalizedRequestUri;

    file_put_contents(
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'af_request_debug.log',
        json_encode([
            'stage' => 'after-normalize',
            'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'original_request_uri' => (string) ($_SERVER['AF_ORIGINAL_REQUEST_URI'] ?? ''),
            'script_name' => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
            'path_info' => (string) ($_SERVER['PATH_INFO'] ?? ''),
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}
