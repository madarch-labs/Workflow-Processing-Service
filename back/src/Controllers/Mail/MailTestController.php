<?php

declare(strict_types=1);

namespace Wps\Controllers\Mail;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Symfony\Component\Mime\Email;
use Wps\Controllers\BaseController;
use Wps\Exceptions\ExecutionException;
use Wps\Http\ApiException;
use Wps\Mail\RecipientResolver;
use Wps\Mail\SmtpErrorClassifier;
use Wps\Secrets\SecretMasker;
use Wps\SideEffects\DeliveryPolicy;

/**
 * POST /api/mail/test (admin): {"to": "a@b.ru", "transport"?: "secret://smtp_main"} — sends a test letter to check
 * the mail settings without a process (md/tasks_list/done/4.1-send-email.md §7). The SMTP error text is returned.
 */
#[Route(uri: '/api/mail/test', method: 'POST')]
final class MailTestController extends BaseController
{
    protected function handle(): Response
    {
        $body = $this->body();
        $to = RecipientResolver::normalize((string)($body['to'] ?? ''));
        if ($to === null) {
            throw ApiException::validation([['field' => 'to', 'message' => 'Invalid email address']]);
        }
        $config = $this->container()->config();
        $masker = new SecretMasker();
        try {
            [$transport] = $this->container()->mailer()->transport(isset($body['transport']) ? (string)$body['transport'] : null, $masker);
            $email = (new Email())->from($config->mailerFrom !== '' ? $config->mailerFrom : 'wps@wps.local')->to($to)
                ->subject('WPS: проверка отправки почты')->text("Это тестовое письмо WPS.\nНастройки почты работают.");
            try {
                $sent = $transport->send($email);
            } catch (\Throwable $e) {
                throw SmtpErrorClassifier::classify($e, false, DeliveryPolicy::AT_LEAST_ONCE);
            }
        } catch (ExecutionException $e) {
            return $this->ok(['sent' => false, 'error' => $masker->mask($e->getMessage()), 'error_code' => $e->getDetails()['error_code'] ?? null]);
        }
        return $this->ok(['sent' => true, 'to' => $to, 'message_id' => $sent?->getMessageId()]);
    }
}
