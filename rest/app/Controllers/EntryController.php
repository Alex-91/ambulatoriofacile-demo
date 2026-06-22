<?php

namespace App\Controllers;

class EntryController extends BaseController
{
    public function index()
    {
        return $this->dispatch('index');
    }

    public function submit()
    {
        return $this->dispatch('login');
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

    private function dispatch(string $loginMethod)
    {
        $originalPath = $this->resolveOriginalPath();
        $entryMode = strtolower(trim((string) env('APP_ROOT_ENTRY', '')));

        if ($originalPath === 'login') {
            return $this->delegateLoginController($loginMethod);
        }

        if ($originalPath === 'app') {
            return $this->delegate(Home::class);
        }

        if ($entryMode === 'login') {
            return $this->delegateLoginController($loginMethod);
        }

        if ($entryMode === 'app') {
            return $this->delegate(Home::class);
        }

        return $this->delegate(DemoController::class);
    }

    private function delegateLoginController(string $method)
    {
        $controller = new \App\Controllers\Login\LoginController();
        $controller->initController($this->request, $this->response, service('logger'));

        return $controller->{$method}();
    }
}
