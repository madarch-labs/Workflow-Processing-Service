<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

/**
 * Mail transports for send_email: whether MAILER_DSN is set and the names of smtp_dsn secrets (no values).
 */
#[Route(uri: '/api/dictionaries/mail-transports', method: 'GET')]
final class MailTransportsController extends BaseController
{
    protected function handle(): Response
    {
        $secrets = array_values(array_map(
            static fn (array $s) => (string)$s['name'],
            array_filter($this->container()->secretRepository()->list(), static fn (array $s) => ($s['kind'] ?? '') === 'smtp_dsn'),
        ));
        return $this->ok(['default_configured' => $this->container()->mailer()->hasDefault(), 'secrets' => $secrets]);
    }
}
