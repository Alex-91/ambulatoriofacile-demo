<?php

// Standalone, synthetic/in-memory suite. An existing Composer install may be
// supplied for a worktree; it supplies libraries, never application DB settings.
$rest = dirname(__DIR__, 2);
$autoload = getenv('AF_CAMPAIGN_TEST_AUTOLOAD') ?: $rest . '/vendor/autoload.php';
if (!is_file($autoload)) { throw new RuntimeException('Composer autoload not available for campaign tests.'); }
define('COMPOSER_PATH', realpath($autoload));
foreach (['cache', 'logs', 'session', 'debugbar'] as $directory) {
    $path = $rest . '/writable/' . $directory;
    if (!is_dir($path)) { mkdir($path, 0770, true); }
}
require $rest . '/system/Test/bootstrap.php';
