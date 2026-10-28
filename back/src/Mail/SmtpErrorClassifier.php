<?php

declare(strict_types=1);

namespace Wps\Mail;

use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\ExecutionException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Exceptions\RecoverableException;
use Wps\SideEffects\DeliveryPolicy;
use Wps\SideEffects\UnknownOutcomeException;

/**
 * Mail transport errors (md/tasks_list/done/4.1-send-email.md §4.4):
 *  - connection problems and temporary SMTP replies (4xx) before the body was accepted → recoverable;
 *  - permanent replies (5xx) → not repeated; 535 → authentication failed (configuration);
 *  - a broken connection after DATA → the outcome is unknown: at_most_once fails with UNKNOWN_OUTCOME,
 *    at_least_once repeats.
 */
final class SmtpErrorClassifier
{
    public static function classify(\Throwable $e, bool $afterData, string $delivery, string $effectKey = ''): ExecutionException
    {
        if ($e instanceof ExecutionException) {
            return $e;
        }
        $code = self::smtpCode($e);
        $message = 'Mail transport: ' . mb_substr($e->getMessage(), 0, 500);
        $details = ['error_code' => $code !== null ? "SMTP_{$code}" : 'SMTP_CONNECTION', 'smtp_code' => $code];
        if ($code === 535 || $code === 534 || $code === 530) {
            return new ConfigurationException('Mail transport authentication failed: ' . mb_substr($e->getMessage(), 0, 300), ['error_code' => 'SMTP_AUTH', 'smtp_code' => $code]);
        }
        if ($code !== null && $code >= 500) {
            return new NonRecoverableException($message, $details);
        }
        if ($code !== null && $code >= 400) {
            return new RecoverableException($message, $details);
        }
        // no SMTP reply: connection failed, timeout, connection lost
        if ($afterData && $delivery === DeliveryPolicy::AT_MOST_ONCE) {
            return UnknownOutcomeException::forEffect($effectKey, null, 'The connection broke after the letter was handed over: it may have been sent; the step is not repeated (delivery: at_most_once)');
        }
        return new RecoverableException($message, $details);
    }

    public static function smtpCode(\Throwable $e): ?int
    {
        $code = $e->getCode();
        if (is_int($code) && $code >= 200 && $code < 600) {
            return $code;
        }
        if (preg_match('/got code "(\d{3})"/', $e->getMessage(), $m) || preg_match('/^(\d{3})[ -]/', $e->getMessage(), $m)) {
            return (int)$m[1];
        }
        return null;
    }
}
