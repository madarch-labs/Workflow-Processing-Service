<?php

declare(strict_types=1);

namespace Wps\Http;

use Swoole\Http\Response;
use Wps\Kernel\Env;
use Wps\Support\Json;

/**
 * Writes the standard envelope {success, data, error, meta} (TS §6.1).
 */
final class JsonResponder
{
    /**
     * @param array<string, mixed>|null $meta
     */
    public static function success(Response $response, mixed $data, int $status = 200, ?array $meta = null): Response
    {
        $payload = ['success' => true, 'data' => $data, 'error' => null];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        return self::send($response, $status, $payload);
    }

    public static function error(Response $response, int $status, string $code, string $message, mixed $details = null): Response
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }
        return self::send($response, $status, ['success' => false, 'data' => null, 'error' => $error]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function send(Response $response, int $status, array $payload): Response
    {
        self::cors($response);
        $response->setStatusCode($status);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->end(Json::encode($payload));
        return $response;
    }

    public static function text(Response $response, string $body, string $contentType, int $status = 200): Response
    {
        $response->setStatusCode($status);
        $response->setHeader('Content-Type', $contentType);
        $response->end($body);
        return $response;
    }

    public static function preflight(Response $response): Response
    {
        self::cors($response);
        $response->setHeader('Access-Control-Max-Age', '600');
        $response->setStatusCode(204);
        $response->end();
        return $response;
    }

    private static function cors(Response $response): void
    {
        $response->setHeader('Access-Control-Allow-Origin', Env::get('CORS_ALLOW_ORIGIN', '*') ?? '*');
        $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->setHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type');
    }
}
