<?php

declare(strict_types=1);

namespace Wps\Actions;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Wps\Config\AppConfig;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Mail\MailerFactory;
use Wps\Mail\MailRateLimiter;
use Wps\Mail\RecipientResolver;
use Wps\Mail\SmtpErrorClassifier;
use Wps\Mail\TrackingSmtpTransport;
use Wps\Secrets\SecretReference;
use Wps\Secrets\SecretResolver;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\MetricsService;
use Wps\Services\Validation\DefinitionScope;
use Wps\SideEffects\DeliveryPolicy;
use Wps\SideEffects\HasDeliveryPolicy;
use Wps\SideEffects\SideEffectLedger;
use Wps\Template\EscapeMode;
use Wps\Template\MissingPolicy;
use Wps\Template\TemplateEngine;
use Wps\Template\TemplateValidator;

/**
 * "Отправить письмо" (md/tasks_list/done/4.1-send-email.md): one letter to to / cc / bcc through SMTP from WPS
 * (the step's secret of kind smtp_dsn or MAILER_DSN). Subject, text, html, headers and names are templates; the
 * HTML body escapes substitutions. The call goes through the side-effect journal (0.4): a step repeated after a
 * crash does not send the letter again; Message-ID is stable for the step.
 */
final class SendEmailHandler implements ActionHandlerInterface, HasDeliveryPolicy
{
    private const LISTS = ['to', 'cc', 'bcc', 'reply_to'];
    private const FORBIDDEN_HEADERS = ['from', 'to', 'cc', 'bcc', 'subject', 'message-id', 'mime-version', 'reply-to', 'sender', 'return-path'];
    private const MAX_SUBJECT = 998;

    private readonly RecipientResolver $recipients;
    private readonly TemplateValidator $validator;

    public function __construct(
        private readonly TemplateEngine $templates,
        private readonly MailerFactory $mailer,
        private readonly SideEffectLedger $ledger,
        private readonly MailRateLimiter $limiter,
        private readonly AppConfig $config,
        private readonly ?ExternalApiAdapterService $crm = null,
        private readonly ?SecretResolver $secrets = null,
        private readonly ?MetricsService $metrics = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->recipients = new RecipientResolver($templates);
        $this->validator = new TemplateValidator($templates);
    }

    public function type(): string
    {
        return 'send_email';
    }

    public function label(): string
    {
        return 'Отправить письмо';
    }

    public function description(): string
    {
        return 'Отправляет письмо через SMTP: получатели, тема и тело — шаблоны с подстановками';
    }

    public function defaultDelivery(array $config): string
    {
        return DeliveryPolicy::AT_MOST_ONCE;
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $resolver = static fn (string $path) => $context->resolve($path);
        $render = fn (string $template, EscapeMode $mode) => (string)$this->templates->render($template, $resolver, $mode, MissingPolicy::Empty)->value;

        $seen = [];
        $lists = [];
        $warnings = [];
        foreach (['to', 'cc', 'bcc'] as $key) {
            $list = $this->recipients->resolve(array_values((array)($actionConfig[$key] ?? [])), $context, $seen);
            $lists[$key] = $list->addresses;
            array_push($warnings, ...$list->warnings);
        }
        $noSeen = [];
        $replyTo = $this->recipients->resolve(array_values((array)($actionConfig['reply_to'] ?? [])), $context, $noSeen);
        $total = count($lists['to']) + count($lists['cc']) + count($lists['bcc']);
        if ($total > $this->config->mailerMaxRecipients) {
            throw new NonRecoverableException("Too many recipients ({$total} > {$this->config->mailerMaxRecipients})");
        }
        foreach (['to', 'cc', 'bcc'] as $key) {
            $filtered = $this->limiter->filterRecipients($lists[$key]);
            $lists[$key] = $filtered->addresses;
            array_push($warnings, ...$filtered->warnings);
        }
        $rateLimited = array_values(array_filter($warnings, static fn ($w) => $w['reason'] === 'rate_limited'));
        if ($rateLimited !== []) {
            $this->metrics?->inc(MetricsService::EMAILS_RATE_LIMITED, count($rateLimited));
        }

        $subject = trim(str_replace(["\r", "\n"], ' ', $render((string)($actionConfig['subject'] ?? ''), EscapeMode::Header)));
        if ($subject === '') {
            $subject = '(no subject)';
            $warnings[] = ['value' => 'subject', 'reason' => 'empty'];
        }
        $subject = mb_substr($subject, 0, self::MAX_SUBJECT);
        $html = isset($actionConfig['html']) ? $render((string)$actionConfig['html'], EscapeMode::Html) : '';
        $text = isset($actionConfig['text']) ? $render((string)$actionConfig['text'], EscapeMode::Text) : '';
        if ($text === '' && $html !== '') {
            $text = self::htmlToText($html);
        }
        if (trim($text) === '' && trim($html) === '') {
            throw new NonRecoverableException('Email body is empty');
        }
        [$fromEmail, $fromName] = $this->from($actionConfig['from'] ?? null, $render);

        $request = [
            'transport' => isset($actionConfig['transport']) ? (string)$actionConfig['transport'] : 'MAILER_DSN',
            'from' => $fromEmail,
            'to' => self::emails($lists['to']),
            'cc' => self::emails($lists['cc']),
            'bcc' => $lists['bcc'] === [] ? [] : [sprintf('***%d hidden***', count($lists['bcc']))],
            'subject' => $subject,
            'text_preview' => mb_substr($text, 0, 500),
            'html_size' => strlen($html),
        ];
        if ($lists['to'] === [] && $lists['cc'] === [] && $lists['bcc'] === []) {
            if (($actionConfig['on_empty_recipients'] ?? 'skip') === 'fail') {
                throw new NonRecoverableException('No valid recipients', ['recipient_warnings' => $warnings]);
            }
            return ActionResult::skipped('No valid recipients', $request + ['recipient_warnings' => $warnings]);
        }

        $delivery = DeliveryPolicy::resolve($actionConfig['delivery'] ?? null, $this->defaultDelivery($actionConfig));
        $effectKey = $this->ledger->effectKey($context);
        $messageId = sprintf('%s.%s@%s', $context->executionId ?? $context->eventId, preg_replace('/[^A-Za-z0-9]+/', '-', $effectKey), $this->config->mailMessageIdDomain);
        $request['message_id'] = "<{$messageId}>";

        $headers = [];
        foreach ((array)($actionConfig['headers'] ?? []) as $name => $value) {
            $headers[(string)$name] = $render((string)$value, EscapeMode::Header);
        }
        $all = array_merge($lists['to'], $lists['cc'], $lists['bcc']);
        $outcome = $this->ledger->run($context, $this->type(), '', $delivery, $request + ['bcc_list' => self::emails($lists['bcc']), 'headers' => $headers],
            function () use ($actionConfig, $context, $lists, $replyTo, $fromEmail, $fromName, $subject, $text, $html, $headers, $messageId, $delivery, $effectKey): array {
                [$transport, $hash] = $this->mailer->transport(isset($actionConfig['transport']) ? (string)$actionConfig['transport'] : null, $context->secretMasker);
                $this->limiter->checkTransport($hash);
                $email = (new Email())->from(new Address($fromEmail, $fromName))->subject($subject);
                $lists['to'] !== [] && $email->to(...$lists['to']);
                $lists['cc'] !== [] && $email->cc(...$lists['cc']);
                $lists['bcc'] !== [] && $email->bcc(...$lists['bcc']);
                $replyTo->addresses !== [] && $email->replyTo(...$replyTo->addresses);
                $text !== '' && $email->text($text);
                $html !== '' && $email->html($html);
                $email->getHeaders()->addIdHeader('Message-ID', $messageId);
                $email->getHeaders()->addTextHeader('X-WPS-Execution-Id', (string)($context->executionId ?? ''));
                foreach ($headers as $name => $value) {
                    $email->getHeaders()->addTextHeader($name, $value);
                }
                try {
                    $sent = $transport->send($email);
                } catch (\Throwable $e) {
                    $this->metrics?->inc(MetricsService::EMAILS_FAILED);
                    $afterData = $transport instanceof TrackingSmtpTransport && $transport->dataAccepted();
                    throw SmtpErrorClassifier::classify($e, $afterData, $delivery, $effectKey);
                }
                return ['sent' => true, 'message_id' => "<{$messageId}>", 'debug' => mb_substr((string)$sent?->getDebug(), -2000)];
            });

        $result = [
            'sent' => true,
            'message_id' => $outcome['response']['message_id'] ?? "<{$messageId}>",
            'recipients' => count($all),
            'replayed' => $outcome['replayed'],
        ];
        $invalid = array_values(array_filter($warnings, static fn ($w) => $w['reason'] !== 'rate_limited'));
        if ($invalid !== []) {
            $result['invalid_recipients'] = $invalid;
            $this->logger?->warning('Email recipients skipped', ['execution_id' => $context->executionId, 'skipped' => $invalid]);
        }
        if ($rateLimited !== []) {
            $result['rate_limited'] = array_column($rateLimited, 'value');
        }
        if (!$outcome['replayed']) {
            $this->metrics?->inc(MetricsService::EMAILS_SENT);
            $this->logger?->info('Email sent', ['execution_id' => $context->executionId, 'message_id' => $result['message_id'], 'recipients' => count($all)]);
        }
        if (is_array($actionConfig['save_to_crm'] ?? null) && !$outcome['replayed']) {
            $result += $this->saveHistory($context, (array)$actionConfig['save_to_crm'], [
                'subject' => $subject, 'text' => $text, 'html' => $html, 'from' => $fromEmail,
                'to' => implode('; ', self::emails($lists['to'])), 'cc' => implode('; ', self::emails($lists['cc'])),
                'bcc' => implode('; ', self::emails($lists['bcc'])), 'message_id' => $result['message_id'],
            ]);
        }
        return ActionResult::success($request, $result);
    }

    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void
    {
        foreach (self::LISTS as $key) {
            if (!array_key_exists($key, $actionConfig)) {
                if ($key === 'to') {
                    $scope->error("{$path}.to", 'At least one recipient is required');
                }
                continue;
            }
            $list = $actionConfig[$key];
            if (!is_array($list) || !array_is_list($list) || ($key === 'to' && $list === [])) {
                $scope->error("{$path}.{$key}", $key === 'to' ? 'At least one recipient is required' : 'Must be a list of recipients');
                continue;
            }
            foreach ($list as $i => $item) {
                $this->validateRecipient($item, $scope, "{$path}.{$key}[{$i}]");
            }
        }
        $subject = $actionConfig['subject'] ?? null;
        if (!is_string($subject) || trim($subject) === '') {
            $scope->error("{$path}.subject", 'Subject is required');
        } else {
            $this->validator->validate($subject, $scope, "{$path}.subject", EscapeMode::Header);
        }
        $hasBody = false;
        foreach (['text' => EscapeMode::Text, 'html' => EscapeMode::Html] as $key => $mode) {
            if (!array_key_exists($key, $actionConfig)) {
                continue;
            }
            if (!is_string($actionConfig[$key])) {
                $scope->error("{$path}.{$key}", 'Template must be a string');
                continue;
            }
            $hasBody = $hasBody || trim($actionConfig[$key]) !== '';
            $this->validator->validate($actionConfig[$key], $scope, "{$path}.{$key}", $mode);
        }
        if (!$hasBody) {
            $scope->error($path, 'Email body is required (text or html)');
        }
        $this->validateFrom($actionConfig['from'] ?? null, $scope, "{$path}.from");
        $this->validateTransport($actionConfig['transport'] ?? null, $scope, "{$path}.transport");
        if (array_key_exists('headers', $actionConfig)) {
            if (!is_array($actionConfig['headers']) || ($actionConfig['headers'] !== [] && array_is_list($actionConfig['headers']))) {
                $scope->error("{$path}.headers", 'Must be an object of header => template');
            } else {
                foreach ($actionConfig['headers'] as $name => $value) {
                    $name = (string)$name;
                    if (in_array(strtolower($name), self::FORBIDDEN_HEADERS, true) || str_starts_with(strtolower($name), 'content-')) {
                        $scope->error("{$path}.headers.{$name}", "Header \"{$name}\" cannot be set here");
                    } elseif (!preg_match('/^[A-Za-z0-9-]{1,76}$/', $name)) {
                        $scope->error("{$path}.headers.{$name}", 'Invalid header name');
                    } elseif (!is_string($value)) {
                        $scope->error("{$path}.headers.{$name}", 'Template must be a string');
                    } else {
                        $this->validator->validate($value, $scope, "{$path}.headers.{$name}", EscapeMode::Header);
                    }
                }
            }
        }
        if (isset($actionConfig['on_empty_recipients']) && !in_array($actionConfig['on_empty_recipients'], ['skip', 'fail'], true)) {
            $scope->error("{$path}.on_empty_recipients", 'Must be "skip" or "fail"');
        }
        foreach (['attachments' => 'Attachments are not supported yet', 'template' => 'CRM e-mail templates are not supported yet'] as $key => $message) {
            if (array_key_exists($key, $actionConfig)) {
                $scope->error("{$path}.{$key}", $message);
            }
        }
        if (array_key_exists('save_to_crm', $actionConfig)) {
            $this->validateHistory($actionConfig['save_to_crm'], $scope, "{$path}.save_to_crm");
        }
    }

    private function validateRecipient(mixed $item, DefinitionScope $scope, string $field): void
    {
        if (is_string($item) && trim($item) !== '') {
            if (str_contains($item, '{{')) {
                $this->validator->validate($item, $scope, $field, EscapeMode::Header);
                return;
            }
            foreach (preg_split('/[,;]/', $item) ?: [] as $email) {
                if (trim($email) !== '' && RecipientResolver::normalize($email) === null) {
                    $scope->error($field, sprintf('Invalid email address "%s"', trim($email)));
                }
            }
            return;
        }
        if (is_array($item) && isset($item['path']) && is_string($item['path'])) {
            $scope->checkReadPath($item['path'], "{$field}.path");
            if (isset($item['name'])) {
                $this->validator->validate($item['name'], $scope, "{$field}.name", EscapeMode::Header);
            }
            return;
        }
        $scope->error($field, 'Recipient must be an email, a template or {"path": ...}');
    }

    private function validateFrom(mixed $from, DefinitionScope $scope, string $field): void
    {
        if ($from === null) {
            if ($this->config->mailerFrom === '') {
                $scope->error($field, 'Sender is required (set "from" or MAILER_FROM)');
            }
            return;
        }
        $email = is_array($from) ? ($from['email'] ?? null) : $from;
        if (!is_string($email) || RecipientResolver::normalize($email) === null) {
            $scope->error($field, 'Sender must be an email or {"email": ..., "name": ...}');
            return;
        }
        if (!$this->senderAllowed($email)) {
            $scope->error($field, 'Sender address is not allowed');
        }
        if (is_array($from) && isset($from['name'])) {
            $this->validator->validate($from['name'], $scope, "{$field}.name", EscapeMode::Header);
        }
    }

    private function validateTransport(mixed $transport, DefinitionScope $scope, string $field): void
    {
        if ($transport === null) {
            if (!$this->mailer->hasDefault()) {
                $scope->error($field, 'No mail transport configured (set MAILER_DSN or "transport")');
            }
            return;
        }
        if (!is_string($transport) || SecretReference::parse($transport) === null) {
            $scope->error($field, 'Transport must be a secret reference (secret://name)');
            return;
        }
        // existence of the secret is checked for the whole definition (ProcessValidatorService)
        $kind = $this->secrets?->kind($transport);
        if ($kind !== null && $kind !== 'smtp_dsn' && $kind !== 'generic') {
            $scope->error($field, "Secret must be of kind smtp_dsn, got {$kind}");
        }
    }

    private function validateHistory(mixed $config, DefinitionScope $scope, string $field): void
    {
        if (!is_array($config) || array_is_list($config)) {
            $scope->error($field, 'Must be an object {"module": ..., "parent": ...}');
            return;
        }
        if (!is_string($config['module'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $config['module'])) {
            $scope->error("{$field}.module", 'Invalid CRM module');
        }
        if (isset($config['parent'])) {
            if (!is_string($config['parent']) || !ExecutionContext::isValidVariableName($config['parent'])) {
                $scope->error("{$field}.parent", 'Must be a variable name');
            } elseif (!$scope->isDeclared($config['parent'])) {
                $scope->error("{$field}.parent", "Variable \"{$config['parent']}\" is not defined at this point");
            }
        }
        if (isset($config['fields'])) {
            if (!is_array($config['fields']) || array_is_list($config['fields'])) {
                $scope->error("{$field}.fields", 'Must be an object of field => template');
            } else {
                foreach ($config['fields'] as $name => $template) {
                    // "mail.*" are the values of the sent letter
                    $paths = is_string($template) ? $this->templates->paths($template) : [];
                    if (!is_string($template)) {
                        $scope->error("{$field}.fields.{$name}", 'Template must be a string');
                        continue;
                    }
                    foreach ($paths as $p) {
                        if (!str_starts_with($p, 'mail.')) {
                            $scope->checkReadPath($p, "{$field}.fields.{$name}");
                        }
                    }
                }
            }
        }
    }

    /**
     * @param \Closure(string, EscapeMode): string $render
     * @return array{0: string, 1: string}
     */
    private function from(mixed $from, \Closure $render): array
    {
        if ($from === null) {
            return [$this->config->mailerFrom, $this->config->mailerFromName];
        }
        $email = (string)(is_array($from) ? ($from['email'] ?? '') : $from);
        $name = is_array($from) && isset($from['name']) ? $render((string)$from['name'], EscapeMode::Header) : '';
        if (!$this->senderAllowed($email)) {
            throw new NonRecoverableException('Sender address is not allowed');
        }
        return [(string)RecipientResolver::normalize($email), $name];
    }

    private function senderAllowed(string $email): bool
    {
        if ($this->config->mailerAllowedFrom === []) {
            return true;
        }
        $email = strtolower(trim($email));
        $domain = '@' . substr($email, (int)strrpos($email, '@') + 1);
        return in_array($email, $this->config->mailerAllowedFrom, true) || in_array($domain, $this->config->mailerAllowedFrom, true);
    }

    /**
     * History record of the letter in the CRM; an error here does not fail the step (the letter is sent).
     *
     * @param array<string, mixed> $config
     * @param array<string, string> $mail
     * @return array<string, mixed>
     */
    private function saveHistory(ExecutionContext $context, array $config, array $mail): array
    {
        if ($this->crm === null) {
            return [];
        }
        try {
            if (isset($config['fields']) && is_array($config['fields'])) {
                $resolver = static fn (string $path) => str_starts_with($path, 'mail.')
                    ? [array_key_exists(substr($path, 5), $mail), $mail[substr($path, 5)] ?? null]
                    : $context->resolve($path);
                $attributes = array_map(fn ($t) => (string)$this->templates->render((string)$t, $resolver)->value, $config['fields']);
            } else {
                $attributes = [
                    'name' => $mail['subject'], 'description' => $mail['text'], 'description_html' => $mail['html'],
                    'from_addr' => $mail['from'], 'to_addrs' => $mail['to'], 'cc_addrs' => $mail['cc'], 'bcc_addrs' => $mail['bcc'],
                    'status' => 'sent', 'type' => 'out', 'date_sent_received' => gmdate('Y-m-d H:i:s'), 'message_id' => $mail['message_id'],
                ];
                $parent = (string)($config['parent'] ?? '');
                if ($parent !== '' && ($id = $context->get("{$parent}.id")) !== null && $id !== '') {
                    $attributes['parent_type'] = (string)($context->getModule($parent) ?? '');
                    $attributes['parent_id'] = (string)$id;
                }
            }
            $created = $this->crm->create((string)$config['module'], $attributes);
            return ['crm_history_id' => $created['id'] ?? null];
        } catch (\Throwable $e) {
            $this->logger?->warning('Email history was not saved to the CRM', ['execution_id' => $context->executionId, 'error' => $e->getMessage()]);
            return ['crm_history_error' => mb_substr($e->getMessage(), 0, 500)];
        }
    }

    /**
     * @param list<Address> $addresses
     * @return list<string>
     */
    private static function emails(array $addresses): array
    {
        return array_map(static fn (Address $a) => $a->getAddress(), $addresses);
    }

    public static function htmlToText(string $html): string
    {
        $text = preg_replace(['#<br\s*/?>#i', '#</(p|div|h[1-6]|li|tr)>#i'], ["\n", "\n"], $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text) ?? $text) ?? $text);
    }
}
