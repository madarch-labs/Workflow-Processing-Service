<?php

declare(strict_types=1);

namespace Wps\Secrets;

/**
 * Removes secret values from what goes to the audit and logs (md/tasks_list/done/7.4-secrets.md §4.5).
 * One masker per executed step: every resolved secret registers its value and derived forms (base64 of Basic
 * credentials, URL-encoded, JSON-escaped). Sensitive headers and query parameters are masked by name always.
 */
final class SecretMasker
{
    public const MASK = '***';
    public const MIN_LENGTH = 4;

    private const SENSITIVE_KEYS = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key', 'x-auth-key',
        'api_key', 'token', 'access_token', 'client_secret', 'password'];

    /** @var array<string, true> */
    private array $values = [];

    public function register(string $value): void
    {
        if (mb_strlen($value) < self::MIN_LENGTH) {
            return; // masking short strings would corrupt any text; the validator does not allow such secrets
        }
        foreach ([$value, rawurlencode($value), urlencode($value), substr((string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1), base64_encode($value)] as $form) {
            if ($form !== '' && mb_strlen($form) >= self::MIN_LENGTH) {
                $this->values[$form] = true;
            }
        }
    }

    /**
     * A resolved value (string or composite) and, for basic credentials, the base64 "user:password" form.
     *
     * @param string|array<string, mixed> $value
     */
    public function registerValue(string|array $value): void
    {
        if (is_array($value)) {
            foreach ($value as $field) {
                if (is_scalar($field)) {
                    $this->register((string)$field);
                }
            }
            if (isset($value['username'], $value['password'])) {
                $this->register(base64_encode($value['username'] . ':' . $value['password']));
            }
            return;
        }
        $this->register($value);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function mask(mixed $data): mixed
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true) && $value !== null && $value !== ''
                    ? (is_array($value) ? array_map(static fn () => self::MASK, $value) : self::MASK)
                    : $this->mask($value);
            }
            return $result;
        }
        if (!is_string($data) || $this->values === []) {
            return $data;
        }
        // longest first, so a value is not partially replaced by its own substring form
        $forms = array_keys($this->values);
        usort($forms, static fn (string $a, string $b) => strlen($b) <=> strlen($a));
        return str_replace($forms, self::MASK, $data);
    }
}
