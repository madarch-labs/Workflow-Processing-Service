<?php

declare(strict_types=1);

namespace Wps\Mail;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Wps\Config\AppConfig;
use Wps\Exceptions\ConfigurationException;
use Wps\Secrets\SecretMasker;
use Wps\Secrets\SecretResolver;

/**
 * A new mail transport per letter: the step's secret (kind smtp_dsn) or MAILER_DSN. smtp:// and smtps:// are built
 * as TrackingSmtpTransport (the DATA stage is known for error classification); other schemes go to
 * Transport::fromDsn() and need the provider bridge package.
 */
final class MailerFactory
{
    public function __construct(
        private readonly AppConfig $config,
        private readonly ?SecretResolver $secrets = null,
    ) {
    }

    public function hasDefault(): bool
    {
        return $this->config->mailerDsn !== '';
    }

    /**
     * @return array{0: TransportInterface, 1: string} [transport, DSN hash for rate limits]
     */
    public function transport(?string $reference, ?SecretMasker $masker = null): array
    {
        $dsn = $reference !== null && $reference !== ''
            ? $this->fromSecret($reference, $masker)
            : ($this->config->mailerDsn !== '' ? $this->config->mailerDsn : throw new ConfigurationException('No mail transport configured (set MAILER_DSN or "transport")'));
        try {
            $parsed = Dsn::fromString($dsn);
        } catch (\Throwable) {
            throw new ConfigurationException('Invalid mail transport DSN');
        }
        if ($parsed->getPassword() !== null && $parsed->getPassword() !== '') {
            $masker?->register($parsed->getPassword());
        }
        $hash = sha1($parsed->getScheme() . '://' . $parsed->getHost() . ':' . ($parsed->getPort() ?? 0) . '/' . ($parsed->getUser() ?? ''));
        if (in_array($parsed->getScheme(), ['smtp', 'smtps'], true)) {
            $tls = $parsed->getScheme() === 'smtps' ? true : null;
            $transport = new TrackingSmtpTransport($parsed->getHost(), $parsed->getPort() ?? 0, $tls);
            if ($parsed->getUser() !== null && $parsed->getUser() !== '') {
                $transport->setUsername($parsed->getUser());
                $transport->setPassword((string)$parsed->getPassword());
            }
            $stream = $transport->getStream();
            if ($stream instanceof Transport\Smtp\Stream\SocketStream) {
                $stream->setTimeout((float)($parsed->getOption('timeout') ?? $this->config->mailerTimeout));
            }
            return [$transport, $hash];
        }
        try {
            return [Transport::fromDsn($dsn), $hash];
        } catch (\Throwable $e) {
            throw new ConfigurationException('Mail transport is not supported: ' . $e->getMessage());
        }
    }

    private function fromSecret(string $reference, ?SecretMasker $masker): string
    {
        if ($this->secrets === null) {
            throw new ConfigurationException('Secrets are not available');
        }
        $value = $this->secrets->resolve($reference, $masker);
        if (!is_string($value) || $value === '') {
            throw new ConfigurationException("Secret {$reference} does not hold a mail DSN");
        }
        return $value;
    }
}
