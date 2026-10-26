<?php

declare(strict_types=1);

namespace Wps\Mail;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * SMTP transport that remembers whether the message body was handed over (the DATA command was accepted): an error
 * before that means the letter was certainly not sent, after it the outcome may be unknown (md/tasks_list/done/4.1-send-email.md §4.4).
 */
final class TrackingSmtpTransport extends EsmtpTransport
{
    private bool $dataAccepted = false;

    public function executeCommand(string $command, array $codes): string
    {
        $response = parent::executeCommand($command, $codes);
        if ($command === "DATA\r\n") {
            $this->dataAccepted = true;
        }
        return $response;
    }

    public function dataAccepted(): bool
    {
        return $this->dataAccepted;
    }
}
