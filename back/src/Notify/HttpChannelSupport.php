<?php

declare(strict_types=1);

namespace Wps\Notify;

use Wps\Exceptions\NonRecoverableException;
use Wps\Http\Outbound\HttpRequestException;
use Wps\Http\Outbound\OutboundHttpClient;
use Wps\Http\Outbound\OutboundResponse;
use Wps\Secrets\SecretMasker;
use Wps\Secrets\SecretReference;
use Wps\Secrets\SecretResolver;
use Wps\Services\Validation\DefinitionScope;
use Wps\SideEffects\DeliveryPolicy;
use Wps\SideEffects\UnknownOutcomeException;

/**
 * What the messenger channels share: the connection secret, the POST of a JSON body through OutboundHttpClient
 * (EgressGuard), the classification of answers. Messenger webhooks have no idempotency keys, so an answer that
 * leaves the outcome open (500 / 502 / 504, a timeout after sending) is repeated only with "delivery": "at_least_once".
 */
final class HttpChannelSupport
{
    private const RETRY_SAFE = [408, 425, 429, 503];
    private const RETRY_UNSURE = [500, 502, 504];

    public function __construct(
        private readonly OutboundHttpClient $http,
        private readonly ?SecretResolver $secrets,
        private readonly int $retryAfterMax = 300,
    ) {
    }

    public function secret(string $reference, ?SecretMasker $masker): string
    {
        $value = $this->secrets?->resolve($reference, $masker) ?? throw new NonRecoverableException('Secrets are not available');
        if (is_array($value)) {
            $value = $value['value'] ?? $value['url'] ?? $value['token'] ?? null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new NonRecoverableException('Secret "' . (SecretReference::parse($reference)?->name ?? $reference) . '" is empty');
        }
        return trim($value);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function post(string $url, array $body, string $service, string $delivery, string $effect, ?SecretMasker $masker): OutboundResponse
    {
        $masker?->register($url);
        try {
            $response = $this->http->send('POST', $url, ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'User-Agent' => 'WPS/1.0'],
                (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 15.0, 5.0);
        } catch (HttpRequestException $e) {
            if ($e->sent() && $delivery === DeliveryPolicy::AT_MOST_ONCE) {
                throw UnknownOutcomeException::forEffect($effect, null, "{$service}: " . $e->getMessage() . ': the message may have been delivered and is not repeated (delivery: at_most_once)');
            }
            throw $e;
        }
        return $response;
    }

    /**
     * Throws for an unsuccessful answer: 408/425/429/503 — recoverable (Retry-After respected), 500/502/504 —
     * recoverable only with at_least_once, other codes — the error of the service.
     */
    public function check(OutboundResponse $response, string $service, string $delivery, string $effect, ?string $error = null, ?int $retryAfter = null): void
    {
        $status = $response->status;
        if ($status >= 200 && $status < 300 && $error === null) {
            return;
        }
        $message = "{$service}: HTTP {$status}" . ($error !== null && $error !== '' ? " {$error}" : '');
        $details = ['response' => mb_substr($response->body, 0, 1000)];
        $retryAfter ??= isset($response->headers['retry-after']) && is_numeric($response->headers['retry-after']) ? (int)$response->headers['retry-after'] : null;
        if (in_array($status, self::RETRY_SAFE, true)) {
            throw new HttpRequestException($message, $status, true, true, $details + ($retryAfter !== null ? ['retry_after_seconds' => min($retryAfter, $this->retryAfterMax)] : []));
        }
        if (in_array($status, self::RETRY_UNSURE, true)) {
            if ($delivery === DeliveryPolicy::AT_LEAST_ONCE) {
                throw new HttpRequestException($message, $status, true, true, $details);
            }
            throw UnknownOutcomeException::forEffect($effect, null, "{$message}: the message may have been delivered and is not repeated (delivery: at_most_once)");
        }
        throw new HttpRequestException($message, $status, false, true, $details);
    }

    /**
     * "connection": a secret reference of one of the kinds.
     *
     * @param list<string> $kinds
     */
    public function validateConnection(mixed $connection, array $kinds, DefinitionScope $scope, string $field): void
    {
        if (!is_string($connection) || SecretReference::parse($connection) === null) {
            $scope->error($field, 'Connection secret is required (secret://name)');
            return;
        }
        try {
            $kind = $this->secrets?->kind($connection);
        } catch (\Throwable) {
            return; // existence of secrets is checked for the whole definition
        }
        if ($kind !== null && !in_array($kind, $kinds, true)) {
            $name = SecretReference::parse($connection)?->name;
            $scope->error($field, "Secret \"{$name}\" has kind \"{$kind}\", expected " . implode(' or ', $kinds));
        }
    }
}
