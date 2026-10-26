<?php

declare(strict_types=1);

namespace Wps\Http\Outbound;

/**
 * IPv4 / IPv6 networks; IPv4-mapped IPv6 addresses (::ffff:10.0.0.1) are checked as IPv4.
 */
final class CidrList
{
    /** private, loopback, link-local, reserved and documentation ranges (md/tasks_list/done/4.2-http-request.md §4.2) */
    public const PRIVATE = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4',
        '240.0.0.0/4', '255.255.255.255/32', '::/128', '::1/128', '64:ff9b::/96', '100::/64', '2001:db8::/32',
        'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** @var list<array{0: string, 1: int}> [binary network, prefix length] */
    private array $networks = [];

    /**
     * @param list<string> $cidrs
     */
    public function __construct(array $cidrs)
    {
        foreach ($cidrs as $cidr) {
            $parsed = self::parse($cidr);
            if ($parsed !== null) {
                $this->networks[] = $parsed;
            }
        }
    }

    public static function valid(string $cidr): bool
    {
        return self::parse($cidr) !== null;
    }

    public function contains(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }
        // ::ffff:a.b.c.d → a.b.c.d
        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
            $binary = substr($binary, 12);
        }
        foreach ($this->networks as [$network, $prefix]) {
            if (strlen($network) === strlen($binary) && self::matches($binary, $network, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function parse(string $cidr): ?array
    {
        $cidr = trim($cidr);
        [$address, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        $binary = @inet_pton((string)$address);
        if ($binary === false) {
            return null;
        }
        $max = strlen($binary) * 8;
        $length = $prefix === null ? $max : (ctype_digit($prefix) ? (int)$prefix : -1);
        return $length < 0 || $length > $max ? null : [$binary, $length];
    }

    private static function matches(string $ip, string $network, int $prefix): bool
    {
        $bytes = intdiv($prefix, 8);
        if (substr($ip, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $bits = $prefix % 8;
        if ($bits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $bits)) & 0xFF;
        return (ord($ip[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
