<?php

declare(strict_types=1);

namespace Wps\Auth;

use Wps\Config\AppConfig;
use Wps\Http\ApiException;

/**
 * Minimal HS256 JWT (access + refresh tokens), no external library.
 */
final class JwtService
{
    public const ACCESS = 'access';
    public const REFRESH = 'refresh';

    public function __construct(private readonly AppConfig $config)
    {
    }

    /**
     * @param array{id: int, email: string, role: string} $user
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function issuePair(array $user): array
    {
        return [
            'access_token' => $this->issue($user, self::ACCESS, $this->config->jwtAccessTtl),
            'refresh_token' => $this->issue($user, self::REFRESH, $this->config->jwtRefreshTtl),
            'token_type' => 'Bearer',
            'expires_in' => $this->config->jwtAccessTtl,
        ];
    }

    /**
     * @param array{id: int, email: string, role: string} $user
     */
    public function issue(array $user, string $type, int $ttl): string
    {
        $now = time();
        $payload = [
            'sub' => (int)$user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'type' => $type,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(8)),
        ];
        $segments = [
            self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])),
            self::b64((string)json_encode($payload)),
        ];
        $segments[] = self::b64(hash_hmac('sha256', implode('.', $segments), $this->config->jwtSecret, true));
        return implode('.', $segments);
    }

    /**
     * @return array{sub: int, email: string, role: string, type: string, exp: int}
     */
    public function verify(string $token, string $expectedType): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw ApiException::unauthorized('Malformed token', 'INVALID_TOKEN');
        }
        [$header, $payload, $signature] = $parts;
        $expected = self::b64(hash_hmac('sha256', "{$header}.{$payload}", $this->config->jwtSecret, true));
        if (!hash_equals($expected, $signature)) {
            throw ApiException::unauthorized('Invalid token signature', 'INVALID_TOKEN');
        }
        $headerData = json_decode(self::unb64($header), true);
        if (!is_array($headerData) || ($headerData['alg'] ?? null) !== 'HS256') {
            throw ApiException::unauthorized('Unsupported token', 'INVALID_TOKEN');
        }
        $data = json_decode(self::unb64($payload), true);
        if (!is_array($data) || !isset($data['sub'], $data['exp'], $data['type'], $data['role'])) {
            throw ApiException::unauthorized('Invalid token payload', 'INVALID_TOKEN');
        }
        if ((int)$data['exp'] < time()) {
            throw ApiException::unauthorized('Token expired', 'TOKEN_EXPIRED');
        }
        if ($data['type'] !== $expectedType) {
            throw ApiException::unauthorized('Wrong token type', 'INVALID_TOKEN');
        }
        return $data;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        return (string)base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    }
}
