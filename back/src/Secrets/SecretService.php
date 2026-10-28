<?php

declare(strict_types=1);

namespace Wps\Secrets;

use Psr\Log\LoggerInterface;
use Wps\Http\ApiException;

/**
 * Secret management for the REST API (md/tasks_list/done/7.4-secrets.md): validation, encryption, masks,
 * usage checks and key rotation. A value is write-only: no method returns it.
 */
final class SecretService
{
    public const NAME_PATTERN = '/^' . SecretReference::NAME_PATTERN . '$/';

    public function __construct(
        private readonly SecretRepository $secrets,
        private readonly SecretCipher $cipher,
        private readonly SecretUsageScanner $usages,
        private readonly SecretResolver $resolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_map(fn (array $row) => self::publicView($row) + ['usages_count' => count($this->usages->usages($row['name']))], $this->secrets->list());
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $name): array
    {
        $row = $this->secrets->find($name) ?? throw ApiException::notFound('NOT_FOUND', "Secret \"{$name}\" not found");
        return self::publicView($row) + ['usages' => $this->usages->usages($name)];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input, ?int $userId): array
    {
        $this->assertKey();
        $errors = [];
        $name = $input['name'] ?? null;
        if (!is_string($name) || !preg_match(self::NAME_PATTERN, $name)) {
            $errors[] = ['field' => 'name', 'message' => 'Secret name must match ^[a-z][a-z0-9_]{1,63}$'];
        }
        $kind = is_string($input['kind'] ?? null) ? SecretKind::tryFrom($input['kind']) : (array_key_exists('kind', $input) ? null : SecretKind::Generic);
        if ($kind === null) {
            $errors[] = ['field' => 'kind', 'message' => 'Unknown secret kind (allowed: ' . implode(', ', array_map(static fn (SecretKind $k) => $k->value, SecretKind::cases())) . ')'];
        }
        if (!array_key_exists('value', $input)) {
            $errors[] = ['field' => 'value', 'message' => 'Secret value is required'];
        } elseif ($kind !== null) {
            $errors = array_merge($errors, self::valueErrors($kind, $input['value']));
        }
        $errors = array_merge($errors, self::commonErrors($input));
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        if ($this->secrets->find($name) !== null) {
            throw ApiException::conflict('SECRET_EXISTS', "Secret \"{$name}\" already exists");
        }
        $encrypted = $this->cipher->encrypt((string)json_encode($input['value'], JSON_UNESCAPED_UNICODE), SecretCipher::associatedData($name, 1));
        $row = $this->secrets->create([
            'name' => $name,
            'kind' => $kind->value,
            'description' => (string)($input['description'] ?? ''),
            'mask' => self::mask($kind, $input['value']),
            'allowed_hosts' => self::hosts($input['allowed_hosts'] ?? []),
            'user_id' => $userId,
        ] + $encrypted);
        $this->logger->info('Secret created', ['name' => $name, 'kind' => $kind->value, 'user_id' => $userId]);
        return self::publicView($row);
    }

    /**
     * @param array<string, mixed> $input value?, description?, allowed_hosts? (the name and the kind cannot change)
     * @return array<string, mixed>
     */
    public function update(string $name, array $input, ?int $userId): array
    {
        $row = $this->secrets->find($name) ?? throw ApiException::notFound('NOT_FOUND', "Secret \"{$name}\" not found");
        $kind = SecretKind::from($row['kind']);
        $errors = self::commonErrors($input);
        if (isset($input['name']) && $input['name'] !== $name) {
            $errors[] = ['field' => 'name', 'message' => 'A secret cannot be renamed (processes refer to it by name)'];
        }
        if (isset($input['kind']) && $input['kind'] !== $row['kind']) {
            $errors[] = ['field' => 'kind', 'message' => 'The kind of a secret cannot be changed'];
        }
        if (array_key_exists('value', $input)) {
            $errors = array_merge($errors, self::valueErrors($kind, $input['value']));
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $fields = [];
        if (array_key_exists('value', $input)) {
            $this->assertKey();
            $version = $row['version'] + 1;
            $fields = $this->cipher->encrypt((string)json_encode($input['value'], JSON_UNESCAPED_UNICODE), SecretCipher::associatedData($name, $version))
                + ['version' => $version, 'mask' => self::mask($kind, $input['value'])];
        }
        if (array_key_exists('description', $input)) {
            $fields['description'] = (string)$input['description'];
        }
        if (array_key_exists('allowed_hosts', $input)) {
            $fields['allowed_hosts'] = self::hosts($input['allowed_hosts']);
        }
        $updated = $fields === [] ? $row : ($this->secrets->update($name, $fields, $userId) ?? $row);
        $this->resolver->forget($name);
        $this->logger->info('Secret updated', ['name' => $name, 'value_changed' => array_key_exists('value', $input), 'user_id' => $userId]);
        return self::publicView($updated);
    }

    public function delete(string $name, ?int $userId): void
    {
        $this->secrets->find($name) ?? throw ApiException::notFound('NOT_FOUND', "Secret \"{$name}\" not found");
        $usages = $this->usages->usages($name);
        if ($usages !== []) {
            throw ApiException::conflict('SECRET_IN_USE', "Secret \"{$name}\" is used by processes", ['usages' => $usages]);
        }
        $this->secrets->delete($name);
        $this->resolver->forget($name);
        $this->logger->info('Secret deleted', ['name' => $name, 'user_id' => $userId]);
    }

    /**
     * Re-encrypts every secret with the current key (after WPS_SECRETS_KEY was replaced and the old one moved to
     * WPS_SECRETS_KEY_PREVIOUS).
     */
    public function rotate(?int $userId): int
    {
        $this->assertKey();
        $count = 0;
        foreach ($this->secrets->list() as $row) {
            if ($row['kek_id'] === $this->cipher->currentKekId()) {
                continue;
            }
            $ad = SecretCipher::associatedData($row['name'], $row['version']);
            try {
                $plain = $this->cipher->decrypt($row['ciphertext'], $row['nonce'], $row['kek_id'], $ad);
            } catch (\Throwable $e) {
                throw new ApiException(409, 'ROTATION_FAILED', "Secret \"{$row['name']}\" cannot be decrypted: {$e->getMessage()}");
            }
            $this->secrets->update($row['name'], $this->cipher->encrypt($plain, $ad), $userId);
            $this->resolver->forget($row['name']);
            $count++;
        }
        $this->logger->info('Secrets re-encrypted', ['count' => $count, 'user_id' => $userId]);
        return $count;
    }

    /**
     * Kinds and their fields (GET /api/dictionaries/secret-kinds).
     *
     * @return list<array<string, mixed>>
     */
    public static function kinds(): array
    {
        return array_map(static fn (SecretKind $k) => [
            'value' => $k->value,
            'label' => $k->label(),
            'composite' => $k->isComposite(),
            'fields' => array_map(static fn (string $f, bool $required) => ['name' => $f, 'required' => $required, 'secret' => !in_array($f, $k->publicFields(), true)], array_keys($k->fields()), $k->fields()),
        ], SecretKind::cases());
    }

    /**
     * What may leave the service: never the value or the ciphertext.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function publicView(array $row): array
    {
        $kind = SecretKind::tryFrom($row['kind']) ?? SecretKind::Generic;
        return [
            'name' => $row['name'],
            'kind' => $row['kind'],
            'description' => $row['description'],
            'mask' => $kind->isComposite() ? json_decode($row['mask'], true) : $row['mask'],
            'allowed_hosts' => $row['allowed_hosts'],
            'version' => $row['version'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'last_used_at' => $row['last_used_at'],
        ];
    }

    /**
     * "****" + the last 4 characters for values of 12+ characters, otherwise "********"; composite values keep
     * their public fields (username, client_id, ...) as is.
     */
    public static function mask(SecretKind $kind, mixed $value): string
    {
        $maskString = static fn (string $v): string => mb_strlen($v) >= 12 ? '****' . mb_substr($v, -4) : '********';
        if ($kind->isComposite() && is_array($value)) {
            $masked = [];
            foreach ($value as $field => $fieldValue) {
                $masked[$field] = in_array($field, $kind->publicFields(), true) ? $fieldValue : $maskString((string)$fieldValue);
            }
            return (string)json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $maskString(is_scalar($value) ? (string)$value : '');
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private static function valueErrors(SecretKind $kind, mixed $value): array
    {
        $tooShort = 'Secret value is too short (min ' . SecretMasker::MIN_LENGTH . ' characters)';
        if ($kind->isComposite()) {
            if (!is_array($value) || array_is_list($value)) {
                return [['field' => 'value', 'message' => "Value must be an object for kind \"{$kind->value}\""]];
            }
            $errors = [];
            foreach ($kind->fields() as $field => $required) {
                $fieldValue = $value[$field] ?? null;
                if ($fieldValue === null || $fieldValue === '') {
                    if ($required) {
                        $errors[] = ['field' => "value.{$field}", 'message' => "Field \"{$field}\" is required for kind \"{$kind->value}\""];
                    }
                    continue;
                }
                if (!is_string($fieldValue)) {
                    $errors[] = ['field' => "value.{$field}", 'message' => 'Must be a string'];
                } elseif (!in_array($field, $kind->publicFields(), true) && mb_strlen($fieldValue) < SecretMasker::MIN_LENGTH) {
                    $errors[] = ['field' => "value.{$field}", 'message' => $tooShort];
                }
            }
            foreach (array_keys($value) as $field) {
                if (!array_key_exists((string)$field, $kind->fields())) {
                    $errors[] = ['field' => "value.{$field}", 'message' => "Unknown field \"{$field}\" for kind \"{$kind->value}\""];
                }
            }
            if ($kind === SecretKind::OAuth2Client && is_string($value['token_url'] ?? null) && !str_starts_with($value['token_url'], 'https://')) {
                $errors[] = ['field' => 'value.token_url', 'message' => 'Token URL must use https'];
            }
            return $errors;
        }
        if (!is_string($value)) {
            return [['field' => 'value', 'message' => 'Value must be a string']];
        }
        if (mb_strlen($value) < SecretMasker::MIN_LENGTH) {
            return [['field' => 'value', 'message' => $tooShort]];
        }
        if ($kind === SecretKind::SmtpDsn && (!preg_match('#^[a-z][a-z0-9+.-]*://[^\s]+$#i', $value) || parse_url($value) === false)) {
            return [['field' => 'value', 'message' => 'Invalid mailer DSN (expected e.g. smtp://user:pass@smtp.example.com:587)']];
        }
        if ($kind === SecretKind::WebhookUrl && (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value))) {
            return [['field' => 'value', 'message' => 'Value must be an http(s) URL']];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array{field: string, message: string}>
     */
    private static function commonErrors(array $input): array
    {
        $errors = [];
        if (array_key_exists('description', $input) && !is_string($input['description'])) {
            $errors[] = ['field' => 'description', 'message' => 'Must be a string'];
        }
        if (array_key_exists('allowed_hosts', $input)) {
            $hosts = $input['allowed_hosts'];
            if (!is_array($hosts) || !array_is_list($hosts)) {
                $errors[] = ['field' => 'allowed_hosts', 'message' => 'Must be a list of host names'];
            } else {
                foreach ($hosts as $i => $host) {
                    if (!is_string($host) || !preg_match('/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:\d{1,5})?$/i', $host)) {
                        $errors[] = ['field' => "allowed_hosts[{$i}]", 'message' => 'Invalid host "' . (is_scalar($host) ? $host : gettype($host)) . '" (a host name without scheme, e.g. api.example.com)'];
                    }
                }
            }
        }
        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function hosts(mixed $hosts): array
    {
        return is_array($hosts) ? array_values(array_unique(array_map(static fn ($h) => strtolower((string)$h), $hosts))) : [];
    }

    private function assertKey(): void
    {
        if (!$this->cipher->isConfigured()) {
            throw new ApiException(503, 'SECRETS_KEY_NOT_CONFIGURED', 'Secrets are not available: WPS_SECRETS_KEY is not configured');
        }
    }
}
