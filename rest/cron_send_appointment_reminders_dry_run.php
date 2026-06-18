<?php
declare(strict_types=1);

require __DIR__ . '/cron_web_auth.php';
cronRequireWebToken(__DIR__ . '/.env');
cronPrepareBackgroundExecution('Dry-run reminder batch avviato.');

define('REMINDER_WEB_ALLOWED', true);

$argv = cronBuildArgvFromHttp([
    __FILE__,
    '--dry-run',
], [
    'date',
    'start-date',
    'days-count',
    'days-ahead',
    'doctor',
    'limit',
    'delay-ms',
    'force-recipient',
    'channel',
]);

require __DIR__ . '/cron_send_appointment_reminders.php';
