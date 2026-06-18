<?php

$compatEnvMap = [
    'DB_HOST'            => 'database.default.hostname',
    'DB_DATABASE'        => 'database.default.database',
    'DB_USERNAME'        => 'database.default.username',
    'DB_PASSWORD'        => 'database.default.password',
    'DB_PORT'            => 'database.default.port',
    'DB_DRIVER'          => 'database.default.DBDriver',
    'DB_PREFIX'          => 'database.default.DBPrefix',
    'DB_ENCRYPTION_KEY'  => 'database.default.DB_ENCRYPTION_KEY',
    'APP_BASE_URL'       => 'app.baseURL',
    'EMAIL_FROM_ADDRESS' => 'email.fromEmail',
    'EMAIL_FROM_NAME'    => 'email.fromName',
    'EMAIL_PROTOCOL'     => 'email.protocol',
];

foreach ($compatEnvMap as $sourceKey => $targetKey) {
    $sourceValue = getenv($sourceKey);
    $targetValue = getenv($targetKey);

    if ($sourceValue === false || $sourceValue === '') {
        continue;
    }

    if ($targetValue !== false && $targetValue !== '') {
        continue;
    }

    putenv($targetKey . '=' . $sourceValue);
    $_ENV[$targetKey]    = $sourceValue;
    $_SERVER[$targetKey] = $sourceValue;
}

/*
 |--------------------------------------------------------------------------
 | ERROR DISPLAY
 |--------------------------------------------------------------------------
 | Don't show ANY in production environments. Instead, let the system catch
 | it and display a generic error message.
 |
 | If you set 'display_errors' to '1', CI4's detailed error report will show.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
// If you want to suppress more types of errors.
// error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT & ~E_USER_NOTICE & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

/*
 |--------------------------------------------------------------------------
 | DEBUG MODE
 |--------------------------------------------------------------------------
 | Debug mode is an experimental flag that can allow changes throughout
 | the system. It's not widely used currently, and may not survive
 | release of the framework.
 */
defined('CI_DEBUG') || define('CI_DEBUG', false);
