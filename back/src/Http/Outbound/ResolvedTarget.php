<?php

declare(strict_types=1);

namespace Wps\Http\Outbound;

/**
 * A checked destination: the request goes to $ip (no second DNS lookup — DNS rebinding), Host / SNI is $host.
 */
final class ResolvedTarget
{
    public function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly string $ip,
        /** path with the query string */
        public readonly string $path,
    ) {
    }

    public function ssl(): bool
    {
        return $this->scheme === 'https';
    }

    public function origin(): string
    {
        return "{$this->scheme}://{$this->host}:{$this->port}";
    }

    public function hostHeader(): string
    {
        $default = $this->ssl() ? 443 : 80;
        $host = str_contains($this->host, ':') ? "[{$this->host}]" : $this->host;
        return $this->port === $default ? $host : "{$host}:{$this->port}";
    }
}
