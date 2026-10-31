<?php

declare(strict_types=1);

namespace ContextGate\Source;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/** Lists routes (research R2). Rule names come from FormRequest keys; no request is ever built. */
final class RoutesSource implements Source
{
    /** Debugging and framework routes, never listed. Config can add patterns, never remove these (FR-002). */
    public const DEFAULT_EXCLUDES = [
        'telescope', 'telescope/*', 'horizon', 'horizon/*', '_ignition', '_ignition/*', '_debugbar', '_debugbar/*',
        'pulse', 'pulse/*', 'sanctum', 'sanctum/*', 'livewire', 'livewire/*',
    ];

    /** A path segment this long of key-like characters is treated as a secret (FR-003). */
    private const SECRET_SEGMENT = '/^[A-Za-z0-9_.-]{16,}$/';

    public function name(): string
    {
        return 'routes.list';
    }

    public function read(SourceRequest $request): SourceResult
    {
        $excludes = self::DEFAULT_EXCLUDES;
        $configured = config('context-gate.sources.routes.exclude', []);
        if (is_array($configured)) {
            foreach ($configured as $pattern) {
                if (is_string($pattern)) {
                    $excludes[] = $pattern;
                }
            }
        }

        $routes = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (Str::is($excludes, $route->uri())) {
                continue;
            }

            $action = $route->getActionName();
            $routes[] = [
                'method' => implode('|', array_diff($route->methods(), ['HEAD'])),
                'uri' => $this->maskSecrets($route->uri()),
                'name' => $route->getName(),
                'controller' => $action === 'Closure' ? null : $action,
                'rule_names' => $this->ruleNames($route),
                'middleware' => array_map(
                    static fn (string $middleware): string => explode(':', $middleware, 2)[0],
                    $route->middleware(),
                ),
            ];
        }

        return SourceResult::ok(['routes' => $routes]);
    }

    private function maskSecrets(string $uri): string
    {
        $segments = array_map(
            static fn (string $segment): string => preg_match(self::SECRET_SEGMENT, $segment) === 1 ? '<SECRET>' : $segment,
            explode('/', $uri),
        );

        return implode('/', $segments);
    }

    /** @return list<string> keys of the FormRequest rules() of the action, empty when unresolvable */
    private function ruleNames(Route $route): array
    {
        $controller = $route->getControllerClass();
        $method = $route->getActionMethod();
        if ($controller === null || !method_exists($controller, $method)) {
            return [];
        }

        foreach ((new ReflectionMethod($controller, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $name = $type->getName();
            if (is_a($name, FormRequest::class, true)) {
                return $this->rulesKeys($name);
            }
        }

        return [];
    }

    /**
     * Reads the source of rules() and takes the top-level keys of its first returned array literal.
     * The class is never constructed and rules() is never called.
     *
     * @param class-string $requestClass
     * @return list<string>
     */
    private function rulesKeys(string $requestClass): array
    {
        $reflection = new ReflectionClass($requestClass);
        if (!$reflection->hasMethod('rules')) {
            return [];
        }

        $method = $reflection->getMethod('rules');
        $file = $method->getFileName();
        $lines = $file === false ? false : file($file);
        if ($lines === false) {
            return [];
        }

        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if ($start === false || $end === false) {
            return [];
        }

        $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        return $this->arrayLiteralKeys('<?php ' . $body);
    }

    /** @return list<string> */
    private function arrayLiteralKeys(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn (mixed $token): bool => !is_array($token)
                || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $start = null;
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_RETURN) {
                $start = $index + 1;
                break;
            }
        }
        if ($start === null || ($tokens[$start] ?? null) !== '[') {
            return [];
        }

        $keys = [];
        $depth = 0;
        $count = count($tokens);
        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '[' || $token === '(' || $token === '{') {
                $depth++;
            } elseif ($token === ']' || $token === ')' || $token === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($depth === 1 && is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $next = $tokens[$i + 1] ?? null;
                if (is_array($next) && $next[0] === T_DOUBLE_ARROW) {
                    $keys[] = trim($token[1], '\'"');
                }
            }
        }

        return $keys;
    }
}
