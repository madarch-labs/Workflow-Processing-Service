<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Address;
use Wps\Actions\SendEmailHandler;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Exceptions\RecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Mail\MailRateLimiter;
use Wps\Mail\RecipientResolver;
use Wps\Mail\SmtpErrorClassifier;
use Wps\SideEffects\UnknownOutcomeException;
use Wps\Template\TemplateEngine;

final class MailTest extends TestCase
{
    private static function context(): ExecutionContext
    {
        $context = ExecutionContext::fromEvent('e', [
            'id' => '1', 'email1' => 'Ivan@Client.RU', 'emails' => 'a@x.ru; b@x.ru, a@x.ru', 'bad' => 'not-an-email', 'empty' => '',
        ], 'Leads');
        $context->setVariable('owner', ['email1' => 'owner@company.ru', 'first_name' => 'Олег', 'last_name' => 'Петров'], 'Users');
        $context->setVariable('team', ['emails' => ['t1@x.ru', 't2@x.ru']], 'Users');
        return $context;
    }

    public function testRecipients(): void
    {
        $resolver = new RecipientResolver(new TemplateEngine(new ExpressionEngine()));
        $seen = [];
        $to = $resolver->resolve([
            'plain@company.ru',
            '{{ object.email1 }}',
            '{{ object.emails }}',
            ['path' => 'owner.email1', 'name' => '{{ owner.first_name }} {{ owner.last_name }}'],
            ['path' => 'team.emails'],
            ['path' => 'object.empty'],
            ['path' => 'object.none'],
            '{{ object.bad }}',
        ], self::context(), $seen);
        self::assertSame(['plain@company.ru', 'Ivan@client.ru', 'a@x.ru', 'b@x.ru', 'owner@company.ru', 't1@x.ru', 't2@x.ru'], $to->emails());
        self::assertSame('Олег Петров', $to->addresses[4]->getName());
        self::assertSame([
            ['value' => 'object.empty', 'reason' => 'missing'],
            ['value' => 'object.none', 'reason' => 'missing'],
            ['value' => 'not-an-email', 'reason' => 'invalid'],
        ], $to->warnings);
        $cc = $resolver->resolve(['owner@company.ru', 'new@x.ru'], self::context(), $seen);
        self::assertSame(['new@x.ru'], $cc->emails(), 'an address already in "to" is dropped from "cc"');
    }

    public function testNormalize(): void
    {
        self::assertSame('User@example.com', RecipientResolver::normalize('  <User@EXAMPLE.com> '));
        self::assertSame('иван@пример.рф', RecipientResolver::normalize('иван@ПРИМЕР.рф'));
        foreach (['abc', '@x.ru', 'a@', 'a b@x.ru', ''] as $bad) {
            self::assertNull(RecipientResolver::normalize($bad), $bad);
        }
    }

    public function testSmtpErrors(): void
    {
        self::assertInstanceOf(RecoverableException::class, SmtpErrorClassifier::classify(new TransportException('Connection could not be established with host "smtp:25"'), false, 'at_most_once'));
        self::assertInstanceOf(RecoverableException::class, SmtpErrorClassifier::classify(new UnexpectedResponseException('Expected response code "250" but got code "421", with message "421 busy"', 421), false, 'at_most_once'));
        $permanent = SmtpErrorClassifier::classify(new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 no such user"', 550), true, 'at_most_once');
        self::assertInstanceOf(NonRecoverableException::class, $permanent);
        self::assertSame('SMTP_550', $permanent->getDetails()['error_code']);
        $auth = SmtpErrorClassifier::classify(new TransportException('Failed to authenticate on SMTP server with username "x" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 bad credentials"."'), false, 'at_most_once');
        self::assertInstanceOf(ConfigurationException::class, $auth);
        self::assertStringStartsWith('Mail transport authentication failed', $auth->getMessage());
        self::assertInstanceOf(UnknownOutcomeException::class, SmtpErrorClassifier::classify(new TransportException('Connection to "smtp:25" timed out.'), true, 'at_most_once', '3'));
        self::assertInstanceOf(RecoverableException::class, SmtpErrorClassifier::classify(new TransportException('Connection to "smtp:25" timed out.'), true, 'at_least_once'));
        self::assertInstanceOf(RecoverableException::class, SmtpErrorClassifier::classify(new UnexpectedResponseException('got code "451"', 451), true, 'at_most_once'), '4xx after DATA: the letter was refused, a retry is safe');
    }

    public function testRateLimiter(): void
    {
        $counts = [];
        $counter = static function (string $key, int $ttl) use (&$counts): int {
            return $counts[$key] = ($counts[$key] ?? 0) + 1;
        };
        $limiter = new MailRateLimiter(null, 2, 1, $counter);
        $limiter->checkTransport('t');
        $limiter->checkTransport('t');
        $first = $limiter->filterRecipients([new Address('a@x.ru'), new Address('b@x.ru')]);
        self::assertCount(2, $first->addresses);
        $second = $limiter->filterRecipients([new Address('a@x.ru'), new Address('c@x.ru')]);
        self::assertSame(['c@x.ru'], $second->emails());
        self::assertSame([['value' => 'a@x.ru', 'reason' => 'rate_limited']], $second->warnings);
        $this->expectException(RecoverableException::class);
        $this->expectExceptionMessage('Mail rate limit exceeded');
        $limiter->checkTransport('t');
    }

    public function testHtmlToText(): void
    {
        self::assertSame("Здравствуйте, Иван!\nСумма: 1 000 &\n\nСпасибо", SendEmailHandler::htmlToText('<p>Здравствуйте, <b>Иван</b>!</p><p>Сумма: 1 000 &amp;</p><br>Спасибо'));
    }
}
