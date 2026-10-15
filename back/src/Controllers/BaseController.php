<?php

declare(strict_types=1);

namespace Wps\Controllers;

use Sidalex\SwooleApp\Classes\Controllers\AbstractController;
use Swoole\Http\Response;
use Wps\Http\ApiException;
use Wps\Http\AuthContext;
use Wps\Http\JsonResponder;
use Wps\Kernel\Container;
use Wps\Support\Json;

/**
 * Base for all API controllers (one class per endpoint, as SwooleApp routes by class attribute).
 * - applies the global middlewares from config.json before the controller-specific ones
 *   (the stock AbstractController parses them but never runs them);
 * - renders ApiException / unexpected errors in the standard error envelope.
 */
abstract class BaseController extends AbstractController
{
    public function getMiddlewares(): array
    {
        $global = isset($this->application) ? $this->application->getGlobalMiddlewares() : [];
        return array_merge($global, $this->middlewares);
    }

    final public function execute(): Response
    {
        try {
            return $this->handle();
        } catch (ApiException $e) {
            return JsonResponder::error($this->response, $e->getStatus(), $e->getErrorCode(), $e->getMessage(), $e->getDetails());
        } catch (\Throwable $e) {
            $this->container()->logger()->error('Request failed', [
                'uri' => $this->request->server['request_uri'] ?? '', 'error' => $e,
            ]);
            $message = $this->container()->config()->debug ? $e->getMessage() : 'Internal server error';
            return JsonResponder::error($this->response, 500, 'INTERNAL_ERROR', $message);
        }
    }

    abstract protected function handle(): Response;

    protected function container(): Container
    {
        return Container::get();
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    protected function ok(mixed $data, int $status = 200, ?array $meta = null): Response
    {
        return JsonResponder::success($this->response, $data, $status, $meta);
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(): array
    {
        $raw = (string)$this->request->rawContent();
        if (trim($raw) === '') {
            return [];
        }
        $data = Json::decode($raw);
        if (!is_array($data)) {
            throw ApiException::badRequest('Request body must be a JSON object');
        }
        return $data;
    }

    protected function query(string $key, ?string $default = null): ?string
    {
        $value = $this->request->get[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : $default;
    }

    protected function queryBool(string $key): ?bool
    {
        $value = $this->query($key);
        return match ($value) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    /**
     * @return array{0: int, 1: int} [page, per_page]
     */
    protected function pagination(int $defaultPerPage = 20, int $maxPerPage = 1000): array
    {
        $page = max(1, (int)($this->query('page') ?? 1));
        $perPage = (int)($this->query('per_page') ?? $defaultPerPage);
        $perPage = min($maxPerPage, max(1, $perPage));
        return [$page, $perPage];
    }

    /**
     * @return array{page: int, per_page: int, total: int}
     */
    protected function meta(int $page, int $perPage, int $total): array
    {
        return ['page' => $page, 'per_page' => $perPage, 'total' => $total];
    }

    protected function intParam(string $name): int
    {
        $value = (string)($this->uri_params[$name] ?? '');
        if (!ctype_digit($value) || strlen($value) > 18) {
            throw ApiException::notFound('NOT_FOUND', "Invalid {$name}");
        }
        return (int)$value;
    }

    protected function param(string $name): string
    {
        return (string)($this->uri_params[$name] ?? '');
    }

    /**
     * @return array{id: int, email: string, role: string}
     */
    protected function user(): array
    {
        return AuthContext::user() ?? throw ApiException::unauthorized();
    }
}
