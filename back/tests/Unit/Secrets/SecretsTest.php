<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Secrets;

use PHPUnit\Framework\TestCase;
use Wps\Exceptions\ConfigurationException;
use Wps\Secrets\SecretCipher;
use Wps\Secrets\SecretKind;
use Wps\Secrets\SecretMasker;
use Wps\Secrets\SecretReference;
use Wps\Secrets\SecretService;
use Wps\Secrets\SecretUsageScanner;

final class SecretsTest extends TestCase
{
    private static function key(): string
    {
        return sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
    }

    public function testEncryptDecrypt(): void
    {
        $cipher = new SecretCipher(self::key());
        $ad = SecretCipher::associatedData('erp_token', 1);
        $encrypted = $cipher->encrypt('"s3cr3t-value"', $ad);
        self::assertNotSame('"s3cr3t-value"', base64_decode($encrypted['ciphertext']));
        self::assertSame('"s3cr3t-value"', $cipher->decrypt($encrypted['ciphertext'], $encrypted['nonce'], $encrypted['kek_id'], $ad));
        self::assertNotSame($encrypted['nonce'], $cipher->encrypt('"s3cr3t-value"', $ad)['nonce'], 'a fresh nonce every time');
    }

    public function testCiphertextIsBoundToItsRow(): void
    {
        $cipher = new SecretCipher(self::key());
        $encrypted = $cipher->encrypt('"value"', SecretCipher::associatedData('a', 1));
        $this->expectException(ConfigurationException::class);
        $cipher->decrypt($encrypted['ciphertext'], $encrypted['nonce'], $encrypted['kek_id'], SecretCipher::associatedData('b', 1));
    }

    public function testWrongKeyFails(): void
    {
        $encrypted = (new SecretCipher(self::key()))->encrypt('"value"', 'ad');
        $other = new SecretCipher(self::key());
        $this->expectException(ConfigurationException::class);
        $other->decrypt($encrypted['ciphertext'], $encrypted['nonce'], $encrypted['kek_id'], 'ad');
    }

    public function testPreviousKeyStillDecrypts(): void
    {
        $old = self::key();
        $encrypted = (new SecretCipher($old))->encrypt('"value"', 'ad');
        $rotated = new SecretCipher(self::key(), $old);
        self::assertSame('"value"', $rotated->decrypt($encrypted['ciphertext'], $encrypted['nonce'], $encrypted['kek_id'], 'ad'));
        self::assertNotSame($encrypted['kek_id'], $rotated->encrypt('"value"', 'ad')['kek_id'], 'new rows use the new key');
    }

    public function testNotConfigured(): void
    {
        $cipher = new SecretCipher(null);
        self::assertFalse($cipher->isConfigured());
        $this->expectException(ConfigurationException::class);
        $cipher->encrypt('x', 'ad');
    }

    public function testDecodeKey(): void
    {
        $key = self::key();
        self::assertSame($key, SecretCipher::decodeKey(base64_encode($key), 'WPS_SECRETS_KEY'));
        $this->expectException(\InvalidArgumentException::class);
        SecretCipher::decodeKey(base64_encode('short'), 'WPS_SECRETS_KEY');
    }

    public function testParseReference(): void
    {
        $ref = SecretReference::parse('secret://erp_oauth.client_id');
        self::assertSame('erp_oauth', $ref?->name);
        self::assertSame('client_id', $ref?->field);
        self::assertNull(SecretReference::parse('secret://erp_token')?->field);
        self::assertNull(SecretReference::parse('secret://Bad'));
        self::assertNull(SecretReference::parse('secret://a'), 'names have 2+ characters');
        self::assertNull(SecretReference::parse('Bearer secret://token'));
        self::assertTrue(SecretReference::isReference('secret://x_y'));
        self::assertFalse(SecretReference::isReference('https://x'));
    }

    public function testMasker(): void
    {
        $masker = new SecretMasker();
        $masker->register('tok-12345');
        $masker->register('abc'); // too short, ignored
        $masker->registerValue(['username' => 'erp', 'password' => 'p@ss word']);
        $data = [
            'url' => 'https://api.example.com/?t=tok-12345',
            'nested' => ['msg' => 'error for tok-12345 and p%40ss%20word and p%40ss+word'],
            'basic' => 'Basic ' . base64_encode('erp:p@ss word'),
            'json' => '{"secret":"tok-12345"}',
            'headers' => ['Authorization' => 'Bearer anything', 'X-Auth-Key' => 'k', 'Accept' => 'json'],
            'short' => 'abc stays',
            'number' => 42,
        ];
        $masked = $masker->mask($data);
        self::assertSame('https://api.example.com/?t=***', $masked['url']);
        self::assertSame('error for *** and *** and ***', $masked['nested']['msg']);
        self::assertSame('Basic ***', $masked['basic']);
        self::assertSame('{"secret":"***"}', $masked['json']);
        self::assertSame(['Authorization' => '***', 'X-Auth-Key' => '***', 'Accept' => 'json'], $masked['headers']);
        self::assertSame('abc stays', $masked['short']);
        self::assertSame(42, $masked['number']);
    }

    public function testSensitiveKeysAreMaskedWithoutRegistration(): void
    {
        $masker = new SecretMasker();
        self::assertTrue($masker->isEmpty());
        self::assertSame(['password' => '***', 'token' => '', 'name' => 'x'], $masker->mask(['password' => 'p', 'token' => '', 'name' => 'x']));
    }

    public function testMask(): void
    {
        self::assertSame('****cdef', SecretService::mask(SecretKind::Bearer, '0123456789abcdef'));
        self::assertSame('********', SecretService::mask(SecretKind::Bearer, 'short-token'));
        self::assertSame(['username' => 'erp', 'password' => '********'], json_decode(SecretService::mask(SecretKind::BasicAuth, ['username' => 'erp', 'password' => 'p@ssw0rd']), true));
        $oauth = json_decode(SecretService::mask(SecretKind::OAuth2Client, ['token_url' => 'https://idp/token', 'client_id' => 'wps', 'client_secret' => 'very-secret-value']), true);
        self::assertSame('wps', $oauth['client_id']);
        self::assertSame('****alue', $oauth['client_secret']);
    }

    public function testUsagePaths(): void
    {
        $definition = ['actions' => [
            ['type' => 'http_request', 'auth' => ['type' => 'bearer', 'token' => 'secret://erp_token']],
            ['type' => 'condition', 'branches' => [['conditions' => [], 'actions' => [['type' => 'send_email', 'transport' => 'secret://erp_token_v2']]]]],
            ['type' => 'http_request', 'auth' => ['type' => 'basic', 'credentials' => 'secret://erp_token.password']],
        ]];
        self::assertSame(
            ['definition.actions[0].auth.token', 'definition.actions[2].auth.credentials'],
            SecretUsageScanner::paths($definition, 'erp_token'),
        );
        self::assertSame(['definition.actions[1].branches[0].actions[0].transport'], SecretUsageScanner::paths($definition, 'erp_token_v2'));
        self::assertSame([], SecretUsageScanner::paths($definition, 'erp'));
    }

    public function testKindsDictionary(): void
    {
        $kinds = array_column(SecretService::kinds(), null, 'value');
        self::assertTrue($kinds['basic_auth']['composite']);
        self::assertSame(['name' => 'username', 'required' => true, 'secret' => false], $kinds['basic_auth']['fields'][0]);
        self::assertSame([], $kinds['bearer']['fields']);
    }
}
