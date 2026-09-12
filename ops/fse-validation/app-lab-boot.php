<?php
final class FseLabBoot extends \CodeIgniter\Boot
{
    protected static function loadDotEnv(\Config\Paths $paths): void { /* No .env is read. */ }
    protected static function loadAutoloader(): void
    {
        parent::loadAutoloader();
        if (PHP_SAPI === 'cli-server') header('X-FSE-Framework-Version: ' . \CodeIgniter\CodeIgniter::CI_VERSION);
        if (PHP_SAPI === 'cli-server' && isset($GLOBALS['fse_lab_dependencies'])) {
            header('X-FSE-Dependency-Variant: ' . $GLOBALS['fse_lab_dependencies']['variant']);
            header('X-FSE-Dependency-Request: ' . $GLOBALS['fse_lab_dependencies']['request_id']);
        }
        require __DIR__.'/app-lab-database.php';
        $app = config(\Config\App::class); $app->baseURL='http://127.0.0.1:8088/'; $app->indexPage=''; $app->forceGlobalSecureRequests=false;
        $session = config(\Config\Session::class); $session->cookieName='fse_synthetic_lab'; $session->savePath=WRITEPATH.'session';
        $cookie = config(\Config\Cookie::class); $cookie->secure=false;
        config(\Config\Optimize::class)->configCacheEnabled=false;
        // Keep the real authentication/FSE filters; only omit the debug toolbar
        // because its separate endpoint is intentionally outside this lab.
        $filters = config(\Config\Filters::class);
        foreach (['required','globals'] as $group) {
            $filters->{$group}['after'] = array_values(array_filter($filters->{$group}['after'] ?? [], static fn($filter) => $filter !== 'toolbar'));
        }
    }
}
