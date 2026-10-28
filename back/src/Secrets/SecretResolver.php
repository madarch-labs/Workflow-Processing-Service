<?php

declare(strict_types=1);

namespace Wps\Secrets;

use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Services\MetricsService;

/**
 * Turns "secret://name[.field]" into the value at the moment a step runs (md/tasks_list/done/7.4-secrets.md §4.4).
 * Decrypted values are cached in the worker memory only (never in Redis) for SECRETS_CACHE_TTL seconds and are
 * registered in the masker of the step, so they never reach the audit or the logs.
 */
final class SecretResolver
{
    private const TOUCH_INTERVAL = 300;

    /** @var array<string, array{value: string|array<string, mixed>, kind: string, allowed_hosts: list<string>, expires: float}> */
    private array $cache = [];

    /** @var array<string, float> name => last time last_used_at was written */
    private array $touched = [];

    public function __construct(
        private readonly SecretRepository $secrets,
        private readonly SecretCipher $cipher,
        private readonly int $cacheTtl = 60,
        private readonly ?MetricsService $metrics = null,
    ) {
    }

    /**
     * @return string|array<string, mixed>
     */
    public function resolve(string $reference, ?SecretMasker $masker = null): string|array
    {
        $parsed = SecretReference::parse($reference) ?? throw new NonRecoverableException("Invalid secret reference \"{$reference}\"");
        try {
            $entry = $this->load($parsed->name);
        } catch (\Throwable $e) {
            $this->metrics?->inc(MetricsService::SECRET_RESOLVE_ERRORS);
            throw $e;
        }
        $this->metrics?->inc(MetricsService::SECRET_RESOLVE);
        $masker?->registerValue($entry['value']);
        if ($parsed->field === null) {
            return $entry['value'];
        }
        if (!is_array($entry['value']) || !array_key_exists($parsed->field, $entry['value'])) {
            $this->metrics?->inc(MetricsService::SECRET_RESOLVE_ERRORS);
            throw new NonRecoverableException("Secret \"{$parsed->name}\" has no field \"{$parsed->field}\"");
        }
        return is_scalar($entry['value'][$parsed->field]) ? (string)$entry['value'][$parsed->field] : $entry['value'][$parsed->field];
    }

    /**
     * Hosts the secret may be sent to (empty = any), for http_request / notify.
     *
     * @return list<string>
     */
    public function allowedHosts(string $reference): array
    {
        $parsed = SecretReference::parse($reference);
        return $parsed === null ? [] : $this->load($parsed->name)['allowed_hosts'];
    }

    public function kind(string $reference): ?string
    {
        $parsed = SecretReference::parse($reference);
        return $parsed === null ? null : $this->load($parsed->name)['kind'];
    }

    public function forget(string $name): void
    {
        unset($this->cache[$name]);
    }

    /**
     * @return array{value: string|array<string, mixed>, kind: string, allowed_hosts: list<string>, expires: float}
     */
    private function load(string $name): array
    {
        $now = microtime(true);
        if (isset($this->cache[$name]) && $this->cache[$name]['expires'] > $now) {
            return $this->cache[$name];
        }
        if (!$this->cipher->isConfigured()) {
            throw new NonRecoverableException('Secrets are not available: WPS_SECRETS_KEY is not configured');
        }
        $row = $this->secrets->find($name) ?? throw new NonRecoverableException("Secret \"{$name}\" not found");
        try {
            $plain = $this->cipher->decrypt($row['ciphertext'], $row['nonce'], $row['kek_id'], SecretCipher::associatedData($name, $row['version']));
        } catch (ConfigurationException $e) {
            throw new NonRecoverableException("Secret \"{$name}\": {$e->getMessage()}");
        }
        $decoded = json_decode($plain, true);
        if (!isset($this->touched[$name]) || $now - $this->touched[$name] > self::TOUCH_INTERVAL) {
            $this->touched[$name] = $now;
            $this->secrets->touch($name);
        }
        return $this->cache[$name] = [
            'value' => is_array($decoded) || is_string($decoded) ? $decoded : $plain,
            'kind' => $row['kind'],
            'allowed_hosts' => $row['allowed_hosts'],
            'expires' => $now + $this->cacheTtl,
        ];
    }
}
