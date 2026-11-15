<?php
declare(strict_types=1);
namespace App\Core;

final class Router
{
    private array $routes = ['GET' => [], 'POST' => []];
    public function get(string $path, callable|array $handler): void { $this->routes['GET'][$path] = $handler; }
    public function post(string $path, callable|array $handler): void { $this->routes['POST'][$path] = $handler; }
    public function dispatch(string $method, string $path): void
    {
        $handler = $this->routes[$method][$path] ?? null;
        if (!$handler) { http_response_code(404); echo \App\Core\t('404 - Page not found'); return; }
        // Route handlers are [ClassName, 'instanceMethod'] pairs; instantiate the
        // controller so non-static action methods are invoked on an object.
        if (
            is_array($handler)
            && is_string($handler[0] ?? null) && is_string($handler[1] ?? null)
            && class_exists($handler[0]) && method_exists($handler[0], $handler[1])
            && !(new \ReflectionMethod($handler[0], $handler[1]))->isStatic()
        ) {
            $handler = [new $handler[0], $handler[1]];
        }
        call_user_func($handler);
    }
}
