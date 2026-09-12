<?php
// Operator-only command. Never bootstrap the app, .env or a database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (array_diff(array_slice($argv, 1), ['--apply'])) { fwrite(STDERR, "Usage: php cleanup-jobs.php [--apply]\n"); exit(2); }
define('WRITEPATH', realpath(__DIR__ . '/../../rest/writable') . DIRECTORY_SEPARATOR);
require __DIR__ . '/../../rest/app/Services/FseValidationJobs.php';
try {
    echo json_encode((new \App\Services\FseValidationJobs())->cleanup(in_array('--apply', $argv, true)), JSON_PRETTY_PRINT) . PHP_EOL;
} catch (\Throwable $e) { fwrite(STDERR, "FSE temporary cleanup unavailable. No retry forced.\n"); exit(1); }
