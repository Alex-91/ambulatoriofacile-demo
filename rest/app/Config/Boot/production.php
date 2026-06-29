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
    'PLATFORM_DB_HOST'   => 'database.platform.hostname',
    'PLATFORM_DB_DATABASE' => 'database.platform.database',
    'PLATFORM_DB_USERNAME' => 'database.platform.username',
    'PLATFORM_DB_PASSWORD' => 'database.platform.password',
    'PLATFORM_DB_PORT'   => 'database.platform.port',
    'PLATFORM_DB_DRIVER' => 'database.platform.DBDriver',
    'PLATFORM_DB_PREFIX' => 'database.platform.DBPrefix',
    'PLATFORM_DB_ENCRYPTION_KEY' => 'database.platform.DB_ENCRYPTION_KEY',
    'APP_BASE_URL'       => 'app.baseURL',
    'EMAIL_FROM_ADDRESS' => 'email.fromEmail',
    'EMAIL_FROM_NAME'    => 'email.fromName',
    'EMAIL_RECIPIENTS'   => 'email.recipients',
    'EMAIL_PROTOCOL'     => 'email.protocol',
    'EMAIL_MAIL_PATH'    => 'email.mailPath',
    'EMAIL_SMTP_HOST'    => 'email.SMTPHost',
    'EMAIL_SMTP_USER'    => 'email.SMTPUser',
    'EMAIL_SMTP_PASS'    => 'email.SMTPPass',
    'EMAIL_SMTP_PORT'    => 'email.SMTPPort',
    'EMAIL_SMTP_TIMEOUT' => 'email.SMTPTimeout',
    'EMAIL_SMTP_KEEP_ALIVE' => 'email.SMTPKeepAlive',
    'EMAIL_SMTP_CRYPTO'  => 'email.SMTPCrypto',
    'EMAIL_WORD_WRAP'    => 'email.wordWrap',
    'EMAIL_WRAP_CHARS'   => 'email.wrapChars',
    'EMAIL_MAIL_TYPE'    => 'email.mailType',
    'EMAIL_CHARSET'      => 'email.charset',
    'EMAIL_VALIDATE'     => 'email.validate',
    'EMAIL_PRIORITY'     => 'email.priority',
    'EMAIL_CRLF'         => 'email.CRLF',
    'EMAIL_NEWLINE'      => 'email.newline',
    'EMAIL_BCC_BATCH_MODE' => 'email.BCCBatchMode',
    'EMAIL_BCC_BATCH_SIZE' => 'email.BCCBatchSize',
    'EMAIL_DSN'          => 'email.DSN',
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
