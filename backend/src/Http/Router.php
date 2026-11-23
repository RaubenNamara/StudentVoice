<?php

declare(strict_types=1);

namespace StudentVoice\Http;

use StudentVoice\Auth\Auth;
use StudentVoice\Config;

/**
 * Maps METHOD /api/path/{param} to [Controller::class, 'method']. {id} matches digits only.
 *
 * Route options:
 * - 'auth' => false          public route
 * - 'auth' => 'student'      a student signed in with their voting number
 * - 'auth' => 'admin'        (default) a signed-in admin
 * - 'super' => true          super admins only (managing admins)
 * - 'before_password' => true   allowed while an admin still has to change their temporary password
 *
 * Signed-in POST/PATCH/PUT/DELETE requests must carry the session's X-CSRF-Token header.
 */
final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler, array $options = []): void
    {
        $this->add('GET', $path, $handler, $options);
    }

    public function post(string $path, array $handler, array $options = []): void
    {
        $this->add('POST', $path, $handler, $options);
    }

    public function patch(string $path, array $handler, array $options = []): void
    {
        $this->add('PATCH', $path, $handler, $options);
    }

    public function delete(string $path, array $handler, array $options = []): void
    {
        $this->add('DELETE', $path, $handler, $options);
    }

    private function add(string $method, string $path, array $handler, array $options): void
    {
        $regex = '#^' . preg_replace(['#\{id\}#', '#\{([a-z_]+)\}#'], ['(?P<id>\d+)', '(?P<$1>[^/]+)'], $path) . '$#';
        $this->routes[] = compact('method', 'regex', 'handler', 'options');
    }

    public function dispatch(Request $request): never
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $this->guard($request, $route['options']);
            [$class, $method] = $route['handler'];
            try {
                (new $class())->$method($request);
            } catch (\Throwable $e) {
                error_log('[studentvoice] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                Response::error(Config::isDebug() ? $e->getMessage() : 'Something went wrong. Please try again.', 500);
            }
            Response::ok();
        }
        Response::error($pathMatched ? 'Method not allowed' : 'Not found', $pathMatched ? 405 : 404);
    }

    private function guard(Request $request, array $options): void
    {
        $auth = $options['auth'] ?? 'admin';
        if ($auth === false) {
            return;
        }
        if ($auth === 'student') {
            if (!Auth::student()) {
                Response::error('Please sign in again with your registration number.', 401, ['code' => 'student_signed_out']);
            }
        } else {
            $admin = Auth::admin();
            if (!$admin) {
                Response::error('Please log in.', 401);
            }
            if ($admin['must_change_password'] && empty($options['before_password'])) {
                Response::error('Please change your temporary password first.', 403, ['code' => 'must_change_password']);
            }
            if (!empty($options['super']) && $admin['role'] !== 'super_admin') {
                Response::error('Only a super admin can do that.', 403);
            }
        }
        if ($request->method !== 'GET' && !hash_equals(Auth::csrfToken(), $request->header('X-CSRF-Token'))) {
            Response::error('Your session has expired. Please refresh the page.', 403, ['code' => 'session_expired']);
        }
    }
}
