<?php

declare(strict_types=1);

namespace Wps\Http\Outbound\Auth;

use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Http\Outbound\EgressGuard;
use Wps\Secrets\SecretMasker;
use Wps\Secrets\SecretReference;
use Wps\Secrets\SecretResolver;

/**
 * "auth" of http_request (md/tasks_list/done/4.2-http-request.md §4.4): credentials come only from secrets; a secret
 * with allowed_hosts may be sent only to those hosts. Resolved values are registered in the step's masker.
 */
final class AuthApplier
{
    public const TYPES = ['none', 'bearer', 'basic', 'api_key', 'oauth2_client_credentials'];
    /** field holding the secret reference, by type */
    public const SECRET_FIELD = ['bearer' => 'token', 'basic' => 'credentials', 'api_key' => 'key', 'oauth2_client_credentials' => 'client'];

    public function __construct(
        private readonly ?SecretResolver $secrets,
        private readonly ?OAuth2TokenProvider $oauth = null,
    ) {
    }

    /**
     * @param array<string, mixed> $auth
     * @return array{headers: array<string, string>, query: array<string, string>, oauth: array<string, mixed>|null}
     */
    public function apply(array $auth, string $host, ?SecretMasker $masker): array
    {
        $type = (string)($auth['type'] ?? 'none');
        if ($type === 'none') {
            return ['headers' => [], 'query' => [], 'oauth' => null];
        }
        $reference = (string)($auth[self::SECRET_FIELD[$type] ?? ''] ?? '');
        $this->assertHost($reference, $host);
        $value = $this->secrets?->resolve($reference, $masker) ?? throw new ConfigurationException('Secrets are not available');
        switch ($type) {
            case 'bearer':
                return ['headers' => ['Authorization' => 'Bearer ' . self::scalar($value, 'token')], 'query' => [], 'oauth' => null];
            case 'basic':
                if (!is_array($value) || !isset($value['username'], $value['password'])) {
                    throw new ConfigurationException("Secret {$reference} must be of kind basic_auth");
                }
                $encoded = base64_encode($value['username'] . ':' . $value['password']);
                $masker?->register($encoded);
                return ['headers' => ['Authorization' => "Basic {$encoded}"], 'query' => [], 'oauth' => null];
            case 'api_key':
                $name = (string)($auth['name'] ?? 'X-Api-Key');
                $key = self::scalar($value, 'key');
                return ($auth['in'] ?? 'header') === 'query'
                    ? ['headers' => [], 'query' => [$name => $key], 'oauth' => null]
                    : ['headers' => [$name => $key], 'query' => [], 'oauth' => null];
            case 'oauth2_client_credentials':
                if (!is_array($value) || !isset($value['token_url'], $value['client_id'], $value['client_secret'])) {
                    throw new ConfigurationException("Secret {$reference} must be of kind oauth2_client");
                }
                $this->oauth ?? throw new ConfigurationException('OAuth2 is not available');
                $token = $this->oauth->token($value, $reference, (string)($auth['auth_style'] ?? 'header'));
                $masker?->register($token);
                return ['headers' => ['Authorization' => "Bearer {$token}"], 'query' => [], 'oauth' => ['client' => $value, 'key' => $reference, 'style' => (string)($auth['auth_style'] ?? 'header')]];
        }
        throw new ConfigurationException("Unknown auth type \"{$type}\"");
    }

    /**
     * Save-time errors of "auth": field → message.
     *
     * @return array<string, string>
     */
    public static function errors(mixed $auth): array
    {
        if (!is_array($auth) || array_is_list($auth)) {
            return ['' => 'Must be an object {"type": ...}'];
        }
        $type = $auth['type'] ?? null;
        if (!in_array($type, self::TYPES, true)) {
            return ['type' => 'Must be one of: ' . implode(', ', self::TYPES)];
        }
        if ($type === 'none') {
            return [];
        }
        $field = self::SECRET_FIELD[$type];
        $errors = [];
        if (!is_string($auth[$field] ?? null) || SecretReference::parse($auth[$field]) === null) {
            $errors[$field] = 'Credentials must reference a secret (secret://name)';
        }
        if ($type === 'api_key') {
            if (isset($auth['in']) && !in_array($auth['in'], ['header', 'query'], true)) {
                $errors['in'] = 'Must be "header" or "query"';
            }
            if (isset($auth['name']) && (!is_string($auth['name']) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $auth['name']))) {
                $errors['name'] = 'Invalid header or parameter name';
            }
        }
        if ($type === 'oauth2_client_credentials' && isset($auth['auth_style']) && !in_array($auth['auth_style'], ['header', 'body'], true)) {
            $errors['auth_style'] = 'Must be "header" or "body"';
        }
        return $errors;
    }

    private function assertHost(string $reference, string $host): void
    {
        $allowed = $this->secrets?->allowedHosts($reference) ?? [];
        if ($allowed !== [] && !EgressGuard::hostListed($host, $allowed)) {
            $name = SecretReference::parse($reference)?->name ?? $reference;
            throw new NonRecoverableException("Secret \"{$name}\" is not allowed for host \"{$host}\"", ['error_code' => 'SECRET_HOST']);
        }
    }

    private static function scalar(mixed $value, string $what): string
    {
        if (is_array($value)) {
            $value = $value[$what] ?? $value['value'] ?? null;
        }
        if (!is_string($value) || $value === '') {
            throw new ConfigurationException("Secret does not hold a {$what}");
        }
        return $value;
    }
}
