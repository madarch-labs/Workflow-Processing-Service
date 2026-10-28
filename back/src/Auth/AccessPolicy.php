<?php

declare(strict_types=1);

namespace Wps\Auth;

/**
 * Route-level RBAC (TS Appendix B; deletion of processes is admin-only).
 * Rules are checked in order; the first match wins. Unlisted /api routes need any authenticated role.
 */
final class AccessPolicy
{
    public const PUBLIC = 'public';

    private const ALL = ['admin', 'editor', 'viewer'];
    private const EDITORS = ['admin', 'editor'];
    private const ADMIN = ['admin'];

    /** @var list<array{0: string, 1: string, 2: string|list<string>}> [method, regex, roles] */
    private const RULES = [
        ['*', '#^/api/health$#', self::PUBLIC],
        ['*', '#^/metrics$#', self::PUBLIC],
        ['POST', '#^/api/auth/(login|refresh)$#', self::PUBLIC],
        ['*', '#^/api/users(/.*)?$#', self::ADMIN],
        // secrets: editors pick them in processes (names and masks only), admins manage them; viewers have no access
        ['GET', '#^/api/secrets(/[^/]+)?$#', self::EDITORS],
        ['*', '#^/api/secrets(/.*)?$#', self::ADMIN],
        // send_email: the test letter is for admins; transport secret names — like the secrets list, editors only
        ['*', '#^/api/mail(/.*)?$#', self::ADMIN],
        ['GET', '#^/api/dictionaries/mail-transports$#', self::EDITORS],
        ['DELETE', '#^/api/processes/[^/]+/runs/[^/]+/[^/]+$#', self::EDITORS],
        ['DELETE', '#^/api/processes/[^/]+$#', self::ADMIN],
        ['POST', '#^/api/processes$#', self::EDITORS],
        ['PUT', '#^/api/processes/[^/]+$#', self::EDITORS],
        ['POST', '#^/api/processes/[^/]+/(clone|run)$#', self::EDITORS],
        ['POST', '#^/api/dictionaries/modules/refresh$#', self::EDITORS],
        ['GET', '#^/api/.*$#', self::ALL],
    ];

    /**
     * @return string|list<string> PUBLIC or the list of roles allowed
     */
    public static function rolesFor(string $method, string $path): string|array
    {
        $method = strtoupper($method);
        foreach (self::RULES as [$ruleMethod, $regex, $roles]) {
            if (($ruleMethod === '*' || $ruleMethod === $method) && preg_match($regex, $path)) {
                return $roles;
            }
        }
        // any other write operation that is not listed explicitly: editors and admins
        return $method === 'GET' ? self::ALL : self::EDITORS;
    }
}
