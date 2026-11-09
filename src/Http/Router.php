<?php
declare(strict_types=1);

namespace App\Http;

/** Tiny router: `GET /surveys/{id}` style patterns, JSON in / JSON out. */
final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    /** @return array{0:int,1:array} [status, body] */
    public function dispatch(string $method, string $path, array $body = []): array
    {
        foreach ($this->routes as [$m, $regex, $handler]) {
            if ($m === $method && preg_match($regex, $path, $match)) {
                $params = array_map('intval', array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY));
                try {
                    return $handler($params, $body);
                } catch (ValidationException $e) {
                    return [422, ['message' => $e->getMessage(), 'errors' => $e->errors]];
                } catch (\OutOfBoundsException $e) {
                    return [404, ['message' => $e->getMessage()]];
                }
            }
        }
        return [404, ['message' => 'route not found']];
    }
}
