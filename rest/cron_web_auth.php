<?php
declare(strict_types=1);

function cronRequireWebToken(string $envPath): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $expected = cronReadEnvValue($envPath, 'CRON_ACCESS_TOKEN');
    $provided = '';

    if (isset($_GET['token'])) {
        $provided = trim((string) $_GET['token']);
    } elseif (isset($_SERVER['HTTP_X_CRON_TOKEN'])) {
        $provided = trim((string) $_SERVER['HTTP_X_CRON_TOKEN']);
    }

    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo 'Forbidden';
        exit(1);
    }
}

function cronPrepareBackgroundExecution(string $message = 'Accepted'): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    ignore_user_abort(true);
    @set_time_limit(0);

    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Connection: close');
        header('Content-Length: ' . strlen($message));
    }

    echo $message;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
    }

    if (function_exists('flush')) {
        @flush();
    }
}

function cronBuildArgvFromHttp(array $baseArgv, array $allowedKeys): array
{
    if (PHP_SAPI === 'cli') {
        return $baseArgv;
    }

    foreach ($allowedKeys as $key) {
        if (!isset($_GET[$key])) {
            continue;
        }

        $value = trim((string) $_GET[$key]);
        if ($value === '') {
            continue;
        }

        $baseArgv[] = '--' . $key . '=' . $value;
    }

    return $baseArgv;
}

function cronReadEnvValue(string $envPath, string $targetKey): string
{
    if (!is_file($envPath)) {
        return '';
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return '';
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eqPos));
        if ($key !== $targetKey) {
            continue;
        }

        $value = trim(substr($line, $eqPos + 1));
        $value = cronStripTrailingComment($value);
        $value = trim($value);

        $first = $value[0] ?? '';
        $last = substr($value, -1);
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    return '';
}

function cronStripTrailingComment(string $value): string
{
    $inSingle = false;
    $inDouble = false;
    $length = strlen($value);

    for ($i = 0; $i < $length; $i++) {
        $char = $value[$i];
        $prev = $i > 0 ? $value[$i - 1] : '';

        if ($char === "'" && !$inDouble && $prev !== '\\') {
            $inSingle = !$inSingle;
            continue;
        }

        if ($char === '"' && !$inSingle && $prev !== '\\') {
            $inDouble = !$inDouble;
            continue;
        }

        if ($char === '#' && !$inSingle && !$inDouble && $i > 0 && ctype_space($prev)) {
            return rtrim(substr($value, 0, $i));
        }
    }

    return $value;
}
