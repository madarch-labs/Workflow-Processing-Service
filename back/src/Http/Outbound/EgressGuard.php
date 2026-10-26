<?php

declare(strict_types=1);

namespace Wps\Http\Outbound;

use Wps\Exceptions\NonRecoverableException;
use Wps\Exceptions\RecoverableException;

/**
 * SSRF protection of outgoing requests (md/tasks_list/done/4.2-http-request.md §4.2): scheme, port, host lists, every
 * resolved address against private networks; the caller connects to the checked IP. Checked again on every redirect.
 */
final class EgressGuard
{
    private readonly CidrList $private;
    private readonly CidrList $allowedPrivate;

    /**
     * @param list<int> $denyPorts
     * @param list<int> $allowPorts empty = every port except $denyPorts
     * @param list<string> $allowHosts empty = any host; "*.company.ru" matches subdomains
     * @param list<string> $denyHosts always forbidden
     * @param list<string> $allowPrivateCidrs private networks that are allowed (internal systems)
     * @param (\Closure(string): list<string>)|null $resolver host → IP addresses (tests); Swoole getaddrinfo by default
     */
    public function __construct(
        private readonly bool $allowPlainHttp = true,
        private readonly array $denyPorts = [22, 25, 3306, 5432, 6379, 6380, 9200, 11211],
        private readonly array $allowPorts = [],
        private readonly array $allowHosts = [],
        private readonly array $denyHosts = ['localhost', 'metadata.google.internal'],
        array $allowPrivateCidrs = [],
        private readonly ?\Closure $resolver = null,
    ) {
        $this->private = new CidrList(CidrList::PRIVATE);
        $this->allowedPrivate = new CidrList($allowPrivateCidrs);
    }

    public function check(string $url): ResolvedTarget
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new NonRecoverableException("Invalid URL \"{$url}\" (http or https with a host is required)", ['error_code' => 'EGRESS_DENIED']);
        }
        if ($scheme === 'http' && !$this->allowPlainHttp) {
            throw new NonRecoverableException('Plain http is not allowed (HTTP_ALLOW_PLAIN_HTTP=false)', ['error_code' => 'EGRESS_DENIED']);
        }
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (($this->allowPorts !== [] && !in_array($port, $this->allowPorts, true)) || in_array($port, $this->denyPorts, true)) {
            throw new NonRecoverableException("Port {$port} is not allowed", ['error_code' => 'EGRESS_DENIED']);
        }
        if (self::hostListed($host, $this->denyHosts)) {
            throw new NonRecoverableException("Host \"{$host}\" is not allowed", ['error_code' => 'EGRESS_DENIED']);
        }
        if ($this->allowHosts !== [] && !self::hostListed($host, $this->allowHosts)) {
            throw new NonRecoverableException("Host \"{$host}\" is not in HTTP_ALLOW_HOSTS", ['error_code' => 'EGRESS_DENIED']);
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);
        if ($ips === []) {
            throw new RecoverableException("DNS lookup failed for \"{$host}\"", ['error_code' => 'DNS']);
        }
        foreach ($ips as $ip) {
            if ($this->private->contains($ip) && !$this->allowedPrivate->contains($ip)) {
                throw new NonRecoverableException('Destination address is not allowed (private network)', ['error_code' => 'EGRESS_DENIED', 'host' => $host]);
            }
        }
        usort($ips, static fn (string $a, string $b) => (int)str_contains($a, ':') <=> (int)str_contains($b, ':'));
        $path = ($parts['path'] ?? '') === '' ? '/' : (string)$parts['path'];
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }
        return new ResolvedTarget($scheme, $host, $port, $ips[0], $path);
    }

    /**
     * @param list<string> $list
     */
    public static function hostListed(string $host, array $list): bool
    {
        foreach ($list as $pattern) {
            $pattern = strtolower(trim($pattern));
            if ($pattern === '') {
                continue;
            }
            if ($pattern === $host || (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }
        $ips = [];
        foreach ([AF_INET, AF_INET6] as $family) {
            $found = \Swoole\Coroutine::getCid() > 0
                ? \Swoole\Coroutine\System::getaddrinfo($host, $family)
                : (($r = @gethostbynamel($host)) !== false && $family === AF_INET ? $r : []);
            foreach ((array)$found as $ip) {
                $ips[] = (string)$ip;
            }
        }
        return array_values(array_unique($ips));
    }
}
