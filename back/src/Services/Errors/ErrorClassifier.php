<?php

declare(strict_types=1);

namespace Wps\Services\Errors;

use Wps\Crm\CrmCapabilityMissingException;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\CrmApiException;
use Wps\Exceptions\ExecutionException;
use Wps\Infrastructure\Database;
use Wps\SideEffects\UnknownOutcomeException;

/**
 * Throwable → ErrorInfo: error code, transient or not, HTTP status, Retry-After.
 */
final class ErrorClassifier
{
    public static function classify(\Throwable $e): ErrorInfo
    {
        $message = $e->getMessage();
        $details = $e instanceof ExecutionException ? $e->getDetails() : [];
        $retryAfter = isset($details['retry_after_seconds']) ? (int)$details['retry_after_seconds'] : null;

        if ($e instanceof UnknownOutcomeException) {
            return new ErrorInfo(UnknownOutcomeException::CODE, $message, false, permanent: true, details: $details);
        }
        if ($e instanceof ConfigurationException || $e instanceof CrmCapabilityMissingException) {
            return new ErrorInfo((string)($details['error_code'] ?? 'CONFIG'), $message, false, permanent: true, details: $details);
        }
        if ($e instanceof CrmApiException) {
            $status = $e->getHttpStatus();
            if ($status <= 0) {
                return new ErrorInfo($status === -2 ? 'TIMEOUT' : 'TRANSPORT', $message, true, details: $details);
            }
            return new ErrorInfo("HTTP_{$status}", $message, $e->isRecoverable(), $status, $retryAfter, details: $details);
        }
        if ($e instanceof ExecutionException) {
            $code = (string)($details['error_code'] ?? ($e->isRecoverable() ? 'TRANSIENT' : 'ERROR'));
            $status = isset($details['http_status']) ? (int)$details['http_status'] : null;
            return new ErrorInfo($code, $message, $e->isRecoverable(), $status, $retryAfter, details: $details);
        }
        if ($e instanceof \RedisException) {
            return new ErrorInfo('REDIS', $message, true);
        }
        if (Database::isConnectionError($e)) {
            return new ErrorInfo('DATABASE', $message, true);
        }
        return new ErrorInfo('INTERNAL', $message, false);
    }
}
