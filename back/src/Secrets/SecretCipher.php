<?php

declare(strict_types=1);

namespace Wps\Secrets;

use Wps\Exceptions\ConfigurationException;

/**
 * Encryption of secret values: XChaCha20-Poly1305 (libsodium AEAD) with a key from WPS_SECRETS_KEY.
 * The associated data binds a ciphertext to its row ("wps-secret|name|version"): a ciphertext copied into another
 * row does not decrypt. kek_id (first 8 hex of sha256(key)) selects the key; WPS_SECRETS_KEY_PREVIOUS still
 * decrypts rows of the old key until they are re-encrypted (POST /api/secrets/rotate).
 */
final class SecretCipher
{
    /** @var array<string, string> kek_id => key */
    private array $keys = [];
    private ?string $currentKekId = null;

    public function __construct(?string $key, ?string $previousKey = null)
    {
        if ($key !== null) {
            $this->currentKekId = self::kekId($key);
            $this->keys[$this->currentKekId] = $key;
        }
        if ($previousKey !== null) {
            $this->keys[self::kekId($previousKey)] ??= $previousKey;
        }
    }

    public function __destruct()
    {
        foreach ($this->keys as $id => $key) {
            sodium_memzero($key);
            unset($this->keys[$id]);
        }
    }

    public static function kekId(string $key): string
    {
        return substr(hash('sha256', $key), 0, 8);
    }

    public static function associatedData(string $name, int $version): string
    {
        return "wps-secret|{$name}|{$version}";
    }

    public function isConfigured(): bool
    {
        return $this->currentKekId !== null;
    }

    public function currentKekId(): ?string
    {
        return $this->currentKekId;
    }

    /**
     * @return array{ciphertext: string, nonce: string, kek_id: string} base64 strings
     */
    public function encrypt(string $plain, string $ad): array
    {
        if ($this->currentKekId === null) {
            throw new ConfigurationException('WPS_SECRETS_KEY is not configured');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $ad, $nonce, $this->keys[$this->currentKekId]);
        return ['ciphertext' => base64_encode($cipher), 'nonce' => base64_encode($nonce), 'kek_id' => $this->currentKekId];
    }

    public function decrypt(string $ciphertext, string $nonce, string $kekId, string $ad): string
    {
        $key = $this->keys[$kekId] ?? throw new ConfigurationException("No key for kek_id {$kekId} (set WPS_SECRETS_KEY_PREVIOUS to the old key)");
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt((string)base64_decode($ciphertext, true), $ad, (string)base64_decode($nonce, true), $key);
        if ($plain === false) {
            throw new ConfigurationException('Secret cannot be decrypted (wrong key or the row was tampered with)');
        }
        return $plain;
    }

    /**
     * Key from configuration: base64 of 32 bytes (php bin/secrets-key.php).
     */
    public static function decodeKey(string $base64, string $variable): string
    {
        $key = base64_decode(trim($base64), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \InvalidArgumentException("{$variable}: expected base64 of 32 bytes (generate with: php bin/secrets-key.php)");
        }
        return $key;
    }
}
