<?php

namespace App\Controllers;

class EntryController extends BaseController
{
    public function index()
    {
        $originalPath = $this->resolveOriginalPath();
        $entryMode = strtolower(trim((string) env('APP_ROOT_ENTRY', '')));

        if ($originalPath === 'login') {
            return $this->delegate(\App\Controllers\Login\LoginController::class);
        }

        if ($originalPath === 'app') {
            return $this->delegate(Home::class);
        }

        if ($entryMode === 'login') {
            return $this->delegate(\App\Controllers\Login\LoginController::class);
        }

        if ($entryMode === 'app') {
            return $this->delegate(Home::class);
        }

        return $this->delegate(DemoController::class);
    }

    private function resolveOriginalPath(): string
    {
        $requestUri = (string) ($_SERVER['AF_ORIGINAL_REQUEST_URI'] ?? $_SERVER['REQUEST_URI'] ?? '');

        return trim((string) parse_url($requestUri, PHP_URL_PATH), '/');
    }

    /**
     * @param class-string<BaseController|\CodeIgniter\Controller> $controllerClass
     */
    private function delegate(string $controllerClass)
    {
        $controller = new $controllerClass();
        $controller->initController($this->request, $this->response, service('logger'));

        return $controller->index();
    }
}
