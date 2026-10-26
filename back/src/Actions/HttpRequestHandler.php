<?php

declare(strict_types=1);

namespace Wps\Actions;

use Psr\Log\LoggerInterface;
use Wps\Config\AppConfig;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Http\Outbound\Auth\AuthApplier;
use Wps\Http\Outbound\Auth\OAuth2TokenProvider;
use Wps\Http\Outbound\HttpRequestException;
use Wps\Http\Outbound\JsonPathExtractor;
use Wps\Http\Outbound\OutboundHttpClient;
use Wps\Http\Outbound\OutboundResponse;
use Wps\Secrets\SecretReference;
use Wps\Secrets\SecretResolver;
use Wps\Services\MetricsService;
use Wps\Services\Validation\DefinitionScope;
use Wps\SideEffects\DeliveryPolicy;
use Wps\SideEffects\HasDeliveryPolicy;
use Wps\SideEffects\SideEffectLedger;
use Wps\SideEffects\UnknownOutcomeException;
use Wps\Template\EscapeMode;
use Wps\Template\MissingPolicy;
use Wps\Template\TemplateEngine;
use Wps\Template\TemplateValidator;

/**
 * "HTTP-запрос" (md/tasks_list/done/4.2-http-request.md): a call of an external API with templates in the URL path and
 * query, headers and body, credentials from secrets, SSRF protection (EgressGuard), the response in a variable with
 * JSONPath extraction. The call goes through the side-effect journal (0.4): a step repeated after a crash returns
 * the stored response (done) or fails with UNKNOWN_OUTCOME (at_most_once) instead of sending the request again.
 */
final class HttpRequestHandler implements ActionHandlerInterface, HasDeliveryPolicy
{
    public const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const FORBIDDEN_HEADERS = ['host', 'content-length', 'transfer-encoding', 'connection'];
    private const RESERVED_FIELDS = ['status', 'ok', 'headers', 'body', 'body_text', 'body_truncated'];
    private const HIDDEN_RESPONSE_HEADERS = ['set-cookie', 'authorization', 'proxy-authorization', 'www-authenticate'];
    /** safe to repeat: the server did not process the request */
    private const RETRY_SAFE = [408, 425, 429, 503];
    /** the server may have processed the request */
    private const RETRY_UNSURE = [500, 502, 504];

    private readonly TemplateValidator $validator;

    public function __construct(
        private readonly TemplateEngine $templates,
        private readonly OutboundHttpClient $http,
        private readonly SideEffectLedger $ledger,
        private readonly AppConfig $config,
        private readonly ?SecretResolver $secrets = null,
        private readonly ?OAuth2TokenProvider $oauth = null,
        private readonly ?MetricsService $metrics = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->validator = new TemplateValidator($templates);
    }

    public function type(): string
    {
        return 'http_request';
    }

    public function label(): string
    {
        return 'HTTP-запрос';
    }

    public function description(): string
    {
        return 'Вызывает внешний API (вебхук): URL, заголовки и тело — шаблоны, ответ сохраняется в переменную';
    }

    public function defaultDelivery(array $config): string
    {
        return in_array(strtoupper((string)($config['method'] ?? 'GET')), ['POST', 'PATCH'], true) ? DeliveryPolicy::AT_MOST_ONCE : DeliveryPolicy::AT_LEAST_ONCE;
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $resolver = static fn (string $path) => $context->resolve($path);
        $method = strtoupper((string)($actionConfig['method'] ?? 'GET'));
        $masker = $context->secretMasker;

        // URL: a template whose scheme and host are literal, or a whole secret of kind webhook_url
        $urlConfig = (string)($actionConfig['url'] ?? '');
        if (SecretReference::parse($urlConfig) !== null) {
            $url = (string)($this->secrets?->resolve($urlConfig, $masker) ?? '');
        } else {
            $url = (string)$this->templates->render($urlConfig, $resolver, EscapeMode::Url, MissingPolicy::Error)->value;
        }
        $query = [];
        foreach ((array)($actionConfig['query'] ?? []) as $name => $template) {
            $rendered = $this->templates->render((string)$template, $resolver, EscapeMode::Text);
            if ($rendered->missing !== [] && $rendered->value === '') {
                continue; // a missing path skips the parameter, an empty value is sent empty
            }
            $query[(string)$name] = (string)$rendered->value;
        }
        $headers = [];
        foreach ((array)($actionConfig['headers'] ?? []) as $name => $template) {
            $headers[(string)$name] = (string)$this->templates->render((string)$template, $resolver, EscapeMode::Header)->value;
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        $auth = (new AuthApplier($this->secrets, $this->oauth))->apply((array)($actionConfig['auth'] ?? []), $host, $masker);
        $headers = $auth['headers'] + $headers;
        $query = $auth['query'] + $query;
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        [$body, $contentType] = $this->body($actionConfig['body'] ?? null, $resolver);
        if ($body !== null) {
            if (strlen($body) > $this->config->httpMaxRequestBytes) {
                throw new NonRecoverableException("Request body is too large (more than {$this->config->httpMaxRequestBytes} bytes)");
            }
            $headers += ['Content-Type' => $contentType];
        }
        $headers += ['Accept' => 'application/json, text/plain;q=0.9, */*;q=0.5', 'User-Agent' => 'WPS/1.0'];

        $delivery = DeliveryPolicy::resolve($actionConfig['delivery'] ?? null, $this->defaultDelivery($actionConfig));
        $idempotencyHeader = array_key_exists('idempotency_header', $actionConfig)
            ? $actionConfig['idempotency_header']
            : (in_array($method, ['POST', 'PATCH'], true) ? 'Idempotency-Key' : null);
        $request = [
            'method' => $method,
            'url' => $url,
            'headers' => array_keys($headers),
            'body_size' => $body === null ? 0 : strlen($body),
            'body_preview' => $body === null ? null : mb_substr($body, 0, 500),
            'delivery' => $delivery,
        ];

        $startedAt = microtime(true);
        $effectKey = $this->ledger->effectKey($context);
        $withKey = is_string($idempotencyHeader) && $idempotencyHeader !== '';
        $outcome = $this->ledger->run($context, $this->type(), '', $delivery, $request + ['body' => $body],
            function (string $key) use ($method, $url, $headers, $body, $actionConfig, $delivery, $idempotencyHeader, $withKey, $auth, $context, $effectKey): array {
                if ($withKey) {
                    $headers[$idempotencyHeader] = $key;
                }
                try {
                    $response = $this->send($method, $url, $headers, $body, $actionConfig);
                } catch (HttpRequestException $e) {
                    // the request reached the server but no answer came: without an idempotency key a repeat may
                    // execute it twice
                    if ($e->sent() && $delivery === DeliveryPolicy::AT_MOST_ONCE && !$withKey) {
                        throw UnknownOutcomeException::forEffect($effectKey, null, $e->getMessage() . ': the request may have been processed and is not repeated (delivery: at_most_once)');
                    }
                    throw $e;
                }
                if ($response->status === 401 && $auth['oauth'] !== null) {
                    // the cached token may have been revoked: a new one, one more attempt
                    $this->oauth?->forget($auth['oauth']['client'], $auth['oauth']['key']);
                    $headers['Authorization'] = 'Bearer ' . $this->oauth?->token($auth['oauth']['client'], $auth['oauth']['key'], $auth['oauth']['style']);
                    $context->secretMasker?->register(substr($headers['Authorization'], 7));
                    $response = $this->send($method, $url, $headers, $body, $actionConfig);
                }
                $this->checkStatus($response, $actionConfig, $delivery, $withKey, $effectKey);
                return $this->variable($response, $actionConfig);
            });

        // a response may echo a secret back (a token, a key): it must not reach the variable, the snapshot or the audit
        $data = (array)($context->secretMasker?->mask($outcome['response']) ?? $outcome['response']);
        $var = $actionConfig['var'] ?? null;
        if (is_string($var) && $var !== '') {
            $context->setVariable($var, $data, null, 'http');
        }
        $this->metrics?->inc(MetricsService::HTTP_REQUESTS);
        $this->logger?->info('HTTP request done', ['execution_id' => $context->executionId, 'method' => $method, 'host' => $host,
            'status' => $data['status'] ?? null, 'replayed' => $outcome['replayed']]);
        return ActionResult::success($request, [
            'status' => $data['status'] ?? null,
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'var' => is_string($var) && $var !== '' ? $var : null,
            'replayed' => $outcome['replayed'],
            'response' => self::preview($data),
        ]);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $actionConfig
     */
    private function send(string $method, string $url, array $headers, ?string $body, array $actionConfig): OutboundResponse
    {
        $timeout = (float)min($this->config->httpMaxTimeout, max(1, (int)($actionConfig['timeout'] ?? 30)));
        $credentialHeaders = ['authorization', 'proxy-authorization', 'cookie'];
        $authConfig = (array)($actionConfig['auth'] ?? []);
        if (($authConfig['type'] ?? null) === 'api_key' && ($authConfig['in'] ?? 'header') === 'header') {
            $credentialHeaders[] = strtolower((string)($authConfig['name'] ?? 'X-Api-Key'));
        }
        return $this->http->send($method, $url, $headers, $body, $timeout, (float)max(1, (int)($actionConfig['connect_timeout'] ?? 5)),
            max(0, min(5, (int)($actionConfig['follow_redirects'] ?? 0))), $credentialHeaders);
    }

    /**
     * @param array<string, mixed> $actionConfig
     */
    private function checkStatus(OutboundResponse $response, array $actionConfig, string $delivery, bool $withKey, string $effectKey): void
    {
        $status = $response->status;
        if (self::matchesCodes($status, $actionConfig['success_codes'] ?? '2xx')) {
            return;
        }
        $host = (string)parse_url($response->url, PHP_URL_HOST);
        $message = "HTTP {$status} from {$host}";
        $details = ['response' => mb_substr($response->body, 0, 2048)];
        if (in_array($status, self::RETRY_SAFE, true)) {
            $retryAfter = self::retryAfter($response->headers['retry-after'] ?? null);
            throw new HttpRequestException($message, $status, true, true, $details
                + ($retryAfter !== null ? ['retry_after_seconds' => min($retryAfter, $this->config->httpRetryAfterMax)] : []));
        }
        if (in_array($status, self::RETRY_UNSURE, true)) {
            if ($withKey || $delivery === DeliveryPolicy::AT_LEAST_ONCE) {
                throw new HttpRequestException($message, $status, true, true, $details);
            }
            throw UnknownOutcomeException::forEffect($effectKey, null, "{$message}: the request may have been processed and is not repeated without an idempotency key (delivery: at_most_once)");
        }
        throw new HttpRequestException($message, $status, false, true, $details);
    }

    /**
     * What goes to the variable and to the journal.
     *
     * @param array<string, mixed> $actionConfig
     * @return array<string, mixed>
     */
    private function variable(OutboundResponse $response, array $actionConfig): array
    {
        $headers = array_filter($response->headers, static fn ($v, $k) => !in_array($k, self::HIDDEN_RESPONSE_HEADERS, true), ARRAY_FILTER_USE_BOTH);
        $data = ['status' => $response->status, 'ok' => $response->status >= 200 && $response->status < 300, 'headers' => $headers];
        $type = (string)($actionConfig['response']['type'] ?? 'auto');
        $isJson = str_contains(strtolower($response->headers['content-type'] ?? ''), 'json');
        $parsed = null;
        if ($type === 'json' || ($type === 'auto' && $isJson)) {
            if (trim($response->body) !== '') {
                $parsed = json_decode(self::utf8($response), true);
                if ($parsed === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new NonRecoverableException('Response is not valid JSON', ['response' => mb_substr($response->body, 0, 500)]);
                }
            }
            if (strlen($response->body) <= $this->config->httpMaxStoredBodyBytes) {
                $data['body'] = $parsed;
            } else {
                $data['body'] = null;
                $data['body_truncated'] = true;
            }
        } elseif ($type !== 'none') {
            $text = self::utf8($response);
            $data['body_text'] = strlen($text) <= $this->config->httpMaxStoredBodyBytes ? $text : mb_substr($text, 0, 1000);
            if (strlen($text) > $this->config->httpMaxStoredBodyBytes) {
                $data['body_truncated'] = true;
            }
        }
        foreach ((array)($actionConfig['response']['extract'] ?? []) as $field => $path) {
            $data[(string)$field] = JsonPathExtractor::extract($parsed, (string)$path);
        }
        return $data;
    }

    /**
     * @param \Closure(string): array{0: bool, 1: mixed} $resolver
     * @return array{0: string|null, 1: string}
     */
    private function body(mixed $body, \Closure $resolver): array
    {
        if (!is_array($body)) {
            return [null, ''];
        }
        switch ($body['type'] ?? 'json') {
            case 'json':
                $value = $this->templates->renderStructure($body['value'] ?? null, $resolver)->value;
                return [(string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), 'application/json'];
            case 'form':
                $fields = [];
                foreach ((array)($body['value'] ?? []) as $name => $template) {
                    $fields[(string)$name] = (string)$this->templates->render((string)$template, $resolver)->value;
                }
                return [http_build_query($fields, '', '&', PHP_QUERY_RFC3986), 'application/x-www-form-urlencoded'];
            default:
                return [(string)$this->templates->render((string)($body['value'] ?? ''), $resolver)->value, (string)($body['content_type'] ?? 'text/plain')];
        }
    }

    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void
    {
        $method = $actionConfig['method'] ?? null;
        if (!is_string($method) || !in_array(strtoupper($method), self::METHODS, true)) {
            $scope->error("{$path}.method", 'Method must be one of: ' . implode(', ', self::METHODS));
            $method = 'GET';
        }
        $method = strtoupper($method);
        $url = $actionConfig['url'] ?? null;
        if (!is_string($url) || trim($url) === '') {
            $scope->error("{$path}.url", 'URL is required');
        } elseif (str_starts_with($url, SecretReference::PREFIX)) {
            // a whole URL from a secret of kind webhook_url; existence is checked for the whole definition
            $kind = SecretReference::parse($url) !== null ? $this->secrets?->kind($url) : null;
            if ($kind !== null && !in_array($kind, ['webhook_url', 'generic'], true)) {
                $scope->error("{$path}.url", "Secret must be of kind webhook_url, got {$kind}");
            }
        } elseif (!preg_match('#^https?://[^/{}?\#]+#i', $url)) {
            $scope->error("{$path}.url", 'Scheme and host of the URL must be literal (https://host/...)');
        } else {
            if (str_starts_with(strtolower($url), 'http://') && !$this->config->httpAllowPlainHttp) {
                $scope->error("{$path}.url", 'Plain http is not allowed (HTTP_ALLOW_PLAIN_HTTP=false)');
            }
            $this->validator->validate($url, $scope, "{$path}.url", EscapeMode::Url);
        }
        foreach (['query' => EscapeMode::Text, 'headers' => EscapeMode::Header] as $key => $mode) {
            if (!array_key_exists($key, $actionConfig)) {
                continue;
            }
            if (!is_array($actionConfig[$key]) || ($actionConfig[$key] !== [] && array_is_list($actionConfig[$key]))) {
                $scope->error("{$path}.{$key}", 'Must be an object of name => template');
                continue;
            }
            foreach ($actionConfig[$key] as $name => $template) {
                $name = (string)$name;
                if ($key === 'headers' && (in_array(strtolower($name), self::FORBIDDEN_HEADERS, true) || !preg_match('/^[A-Za-z0-9-]{1,64}$/', $name))) {
                    $scope->error("{$path}.headers.{$name}", "Header \"{$name}\" cannot be set here");
                    continue;
                }
                if (!is_string($template) && !is_int($template)) {
                    $scope->error("{$path}.{$key}.{$name}", 'Template must be a string');
                    continue;
                }
                $this->validator->validate((string)$template, $scope, "{$path}.{$key}.{$name}", $mode);
            }
        }
        if (array_key_exists('auth', $actionConfig)) {
            foreach (AuthApplier::errors($actionConfig['auth']) as $field => $message) {
                $scope->error("{$path}.auth" . ($field === '' ? '' : ".{$field}"), $message);
            }
        }
        if (array_key_exists('body', $actionConfig)) {
            $this->validateBody($actionConfig['body'], $method, $scope, "{$path}.body");
        }
        $this->validateResponse($actionConfig['response'] ?? null, $scope, "{$path}.response");
        if (array_key_exists('success_codes', $actionConfig) && !self::validCodes($actionConfig['success_codes'])) {
            $scope->error("{$path}.success_codes", 'Must be "2xx" or a list of codes and ranges (200, "2xx")');
        }
        foreach (['timeout' => [1, $this->config->httpMaxTimeout], 'connect_timeout' => [1, 30], 'follow_redirects' => [0, 5]] as $key => [$min, $max]) {
            if (array_key_exists($key, $actionConfig) && (!is_int($actionConfig[$key]) || $actionConfig[$key] < $min || $actionConfig[$key] > $max)) {
                $scope->error("{$path}.{$key}", "Must be an integer between {$min} and {$max}");
            }
        }
        if (array_key_exists('idempotency_header', $actionConfig) && $actionConfig['idempotency_header'] !== null
            && (!is_string($actionConfig['idempotency_header']) || !preg_match('/^[A-Za-z0-9-]{1,64}$/', $actionConfig['idempotency_header']))) {
            $scope->error("{$path}.idempotency_header", 'Must be a header name or null');
        }
        if (array_key_exists('var', $actionConfig)) {
            $var = $actionConfig['var'];
            if (!is_string($var) || !ExecutionContext::isValidVariableName($var) || in_array($var, [ExecutionContext::OBJECT, ...ExecutionContext::READ_ONLY], true)) {
                $scope->error("{$path}.var", 'Variable name is required (letters, digits, "_"; not object, object_before, sys)');
            } else {
                $scope->declare($var, 'http');
            }
        }
    }

    private function validateBody(mixed $body, string $method, DefinitionScope $scope, string $field): void
    {
        if (in_array($method, ['GET', 'HEAD', 'DELETE'], true)) {
            $scope->error($field, "A body is not allowed for {$method}");
            return;
        }
        if (!is_array($body) || array_is_list($body)) {
            $scope->error($field, 'Must be an object {"type": "json" | "form" | "raw", "value": ...}');
            return;
        }
        $type = $body['type'] ?? 'json';
        switch ($type) {
            case 'json':
                $value = $body['value'] ?? null;
                if (is_string($value)) {
                    $this->validator->validate($value, $scope, "{$field}.value", EscapeMode::Json);
                } elseif (is_array($value)) {
                    $this->validator->validate($value, $scope, "{$field}.value", EscapeMode::Json);
                }
                return;
            case 'form':
                if (!is_array($body['value'] ?? null) || array_is_list($body['value'])) {
                    $scope->error("{$field}.value", 'Must be an object of name => template');
                    return;
                }
                foreach ($body['value'] as $name => $template) {
                    $this->validator->validate(is_scalar($template) ? (string)$template : $template, $scope, "{$field}.value.{$name}");
                }
                return;
            case 'raw':
                if (!is_string($body['content_type'] ?? null) || !preg_match('#^[\w.+-]+/[\w.+-]+#', $body['content_type'])) {
                    $scope->error("{$field}.content_type", 'Content type is required for a raw body');
                }
                $this->validator->validate($body['value'] ?? '', $scope, "{$field}.value");
                return;
            case 'multipart':
                $scope->error("{$field}.type", 'Multipart body is not supported yet');
                return;
        }
        $scope->error("{$field}.type", 'Must be one of: json, form, raw');
    }

    private function validateResponse(mixed $response, DefinitionScope $scope, string $field): void
    {
        if ($response === null) {
            return;
        }
        if (!is_array($response) || array_is_list($response)) {
            $scope->error($field, 'Must be an object {"type": ..., "extract": {...}}');
            return;
        }
        if (isset($response['type']) && !in_array($response['type'], ['auto', 'json', 'text', 'none'], true)) {
            $scope->error("{$field}.type", 'Must be one of: auto, json, text, none');
        }
        if (!array_key_exists('extract', $response)) {
            return;
        }
        if (!is_array($response['extract']) || array_is_list($response['extract'])) {
            $scope->error("{$field}.extract", 'Must be an object of field => JSONPath');
            return;
        }
        foreach ($response['extract'] as $name => $path) {
            $name = (string)$name;
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) || in_array($name, self::RESERVED_FIELDS, true)) {
                $scope->error("{$field}.extract.{$name}", 'Invalid field name (reserved: ' . implode(', ', self::RESERVED_FIELDS) . ')');
            } elseif (!is_string($path) || ($error = JsonPathExtractor::error($path)) !== null) {
                $scope->error("{$field}.extract.{$name}", $error ?? 'JSONPath must be a string');
            }
        }
    }

    private static function matchesCodes(int $status, mixed $codes): bool
    {
        foreach (is_array($codes) ? $codes : [$codes] as $code) {
            if (is_int($code) && $code === $status) {
                return true;
            }
            if (is_string($code) && preg_match('/^([1-5])xx$/i', $code, $m) && intdiv($status, 100) === (int)$m[1]) {
                return true;
            }
            if (is_string($code) && ctype_digit($code) && (int)$code === $status) {
                return true;
            }
        }
        return false;
    }

    private static function validCodes(mixed $codes): bool
    {
        $list = is_array($codes) ? $codes : [$codes];
        if ($list === [] || (is_array($codes) && !array_is_list($codes))) {
            return false;
        }
        foreach ($list as $code) {
            $ok = (is_int($code) && $code >= 100 && $code <= 599) || (is_string($code) && (preg_match('/^[1-5]xx$/i', $code) || preg_match('/^[1-5]\d{2}$/', $code)));
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    private static function retryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (ctype_digit(trim($value))) {
            return (int)trim($value);
        }
        $time = strtotime($value);
        return $time === false ? null : max(0, $time - time());
    }

    private static function utf8(OutboundResponse $response): string
    {
        if (preg_match('/charset=([\w-]+)/i', $response->headers['content-type'] ?? '', $m) && strtolower($m[1]) !== 'utf-8') {
            $converted = @mb_convert_encoding($response->body, 'UTF-8', $m[1]);
            return is_string($converted) ? $converted : $response->body;
        }
        return $response->body;
    }

    /**
     * Short form of the response for the step result (the full one is in the variable).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function preview(array $data): array
    {
        $json = (string)json_encode($data['body'] ?? $data['body_text'] ?? null, JSON_UNESCAPED_UNICODE);
        $preview = $data;
        if (strlen($json) > 2000) {
            unset($preview['body']);
            $preview['body_preview'] = mb_substr($json, 0, 2000);
        }
        unset($preview['body_text']);
        return $preview;
    }
}
