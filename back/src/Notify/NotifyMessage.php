<?php

declare(strict_types=1);

namespace Wps\Notify;

use Wps\Template\EscapeMode;
use Wps\Template\TemplateEngine;

/**
 * One notification for all channels: the title and message templates rendered on demand with the escaping of a
 * channel (Telegram — HTML, Slack — mrkdwn via text, Teams — JSON, CRM — plain text), and the record link.
 */
final class NotifyMessage
{
    public const MAX_LENGTH = 4000;

    /** @var array<string, array{title: string, message: string}> */
    private array $rendered = [];

    /** @var list<string> */
    public array $warnings = [];

    /**
     * @param callable(string): array{0: bool, 1: mixed} $resolver
     */
    public function __construct(
        private readonly TemplateEngine $templates,
        private readonly string $titleTemplate,
        private readonly string $messageTemplate,
        private readonly mixed $resolver,
        /** absolute or CRM-relative link to the record, null without a link */
        public readonly ?string $link = null,
        /** module of the linked record (target_module of CRM alerts) */
        public readonly ?string $linkModule = null,
        public readonly ?string $linkId = null,
    ) {
    }

    /**
     * @return array{title: string, message: string}
     */
    public function text(EscapeMode $mode = EscapeMode::Text): array
    {
        if (isset($this->rendered[$mode->value])) {
            return $this->rendered[$mode->value];
        }
        $title = trim((string)$this->templates->render($this->titleTemplate, $this->resolver, $mode)->value);
        $message = trim((string)$this->templates->render($this->messageTemplate, $this->resolver, $mode)->value);
        if (mb_strlen($message) > self::MAX_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_LENGTH - 1) . '…';
            $this->warnings[] = 'Message was cut to ' . self::MAX_LENGTH . ' characters';
            $this->warnings = array_values(array_unique($this->warnings));
        }
        return $this->rendered[$mode->value] = ['title' => $title, 'message' => $message];
    }

    /** Plain text, title first: for previews and fallbacks. */
    public function plain(): string
    {
        $text = $this->text();
        return trim(($text['title'] !== '' ? $text['title'] . "\n" : '') . $text['message']);
    }
}
