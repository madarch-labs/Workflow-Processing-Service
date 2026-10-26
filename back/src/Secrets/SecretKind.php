<?php

declare(strict_types=1);

namespace Wps\Secrets;

/**
 * Kind of a secret: a hint for validation and the future UI (md/tasks_list/done/7.4-secrets.md §4.1).
 * Composite kinds keep a JSON object; the listed "public" fields are not secret and are shown as is.
 */
enum SecretKind: string
{
    case Generic = 'generic';
    case SmtpDsn = 'smtp_dsn';
    case BasicAuth = 'basic_auth';
    case Bearer = 'bearer';
    case ApiKey = 'api_key';
    case OAuth2Client = 'oauth2_client';
    case WebhookUrl = 'webhook_url';
    case TelegramBot = 'telegram_bot';

    public function label(): string
    {
        return match ($this) {
            self::Generic => 'Произвольный',
            self::SmtpDsn => 'SMTP (DSN)',
            self::BasicAuth => 'Basic-авторизация',
            self::Bearer => 'Bearer-токен',
            self::ApiKey => 'API-ключ',
            self::OAuth2Client => 'OAuth2 client credentials',
            self::WebhookUrl => 'URL вебхука',
            self::TelegramBot => 'Токен Telegram-бота',
        };
    }

    public function isComposite(): bool
    {
        return $this->fields() !== [];
    }

    /**
     * Fields of a composite value: name => required.
     *
     * @return array<string, bool>
     */
    public function fields(): array
    {
        return match ($this) {
            self::BasicAuth => ['username' => true, 'password' => true],
            self::OAuth2Client => ['token_url' => true, 'client_id' => true, 'client_secret' => true, 'scope' => false],
            default => [],
        };
    }

    /**
     * Fields that are not secret (returned unmasked in the list).
     *
     * @return list<string>
     */
    public function publicFields(): array
    {
        return match ($this) {
            self::BasicAuth => ['username'],
            self::OAuth2Client => ['token_url', 'client_id', 'scope'],
            default => [],
        };
    }
}
