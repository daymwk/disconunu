<?php
declare(strict_types=1);

namespace DN;

/**
 * Minimal router: {param} patterns, method matching, clean 404/405.
 * No dependency — a plain route table is enough at this scale.
 */
final class Router
{
    /** @var list<array{method:string,pattern:string,handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function any(string $pattern, callable $handler): void
    {
        $this->add('ANY', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        // /post/{slug} -> #^/post/(?P<slug>[^/]+)$#
        $regex = preg_replace_callback('/\{(\w+)\}/', static fn($m) => '(?P<' . $m[1] . '>[^/]+)', $pattern) ?? $pattern;
        $this->routes[] = ['method' => $method, 'pattern' => '#^' . $regex . '$#', 'handler' => $handler];
    }

    /** @param array<string,string> $params */
    public function dispatch(string $method, string $path, array $params = []): void
    {
        $pathMatched = false;
        foreach ($this->routes as $r) {
            if (!preg_match($r['pattern'], $path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($r['method'] !== 'ANY' && $r['method'] !== $method) {
                continue;
            }
            $args = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            ($r['handler'])($args, $params);
            return;
        }
        if ($pathMatched) {
            Http::abort(405, 'Method not allowed.');
        }
        Http::abort(404, 'Page introuvable');
    }
}
