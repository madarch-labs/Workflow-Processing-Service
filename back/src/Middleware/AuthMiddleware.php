<?php

declare(strict_types=1);

namespace Wps\Middleware;

use Sidalex\SwooleApp\Application;
use Sidalex\SwooleApp\Classes\Middleware\AbstractMiddleware;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Wps\Auth\AccessPolicy;
use Wps\Auth\JwtService;
use Wps\Http\ApiException;
use Wps\Http\AuthContext;
use Wps\Http\JsonResponder;
use Wps\Kernel\Container;

/**
 * Global middleware (config.json → globalMiddlewares): JWT authentication + role check by AccessPolicy.
 */
final class AuthMiddleware extends AbstractMiddleware
{
    public function process(Request $request, Response $response, Application $application, callable $next): Response
    {
        $path = (string)($request->server['request_uri'] ?? '/');
        $roles = AccessPolicy::rolesFor($request->getMethod(), rtrim($path, '/') ?: '/');
        if ($roles === AccessPolicy::PUBLIC) {
            return $next($request, $response);
        }

        try {
            $header = (string)($request->header['authorization'] ?? '');
            if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
                throw ApiException::unauthorized();
            }
            $claims = Container::get()->jwt()->verify($m[1], JwtService::ACCESS);
            if (!in_array($claims['role'], $roles, true)) {
                throw ApiException::forbidden();
            }
        } catch (ApiException $e) {
            return JsonResponder::error($response, $e->getStatus(), $e->getErrorCode(), $e->getMessage());
        }

        AuthContext::set([
            'id' => (int)$claims['sub'],
            'email' => (string)$claims['email'],
            'role' => (string)$claims['role'],
        ]);
        return $next($request, $response);
    }
}
