<?php

declare(strict_types=1);

namespace Wps\Mail;

use Symfony\Component\Mime\Address;
use Wps\Context\ExecutionContext;
use Wps\Template\EscapeMode;
use Wps\Template\TemplateEngine;

/**
 * Recipients of a letter (md/tasks_list/done/4.1-send-email.md §4.2): "a@b.ru", a template "{{ object.email1 }}"
 * (one or several addresses split by "," / ";"), {"path": "owner.email1", "name": "{{ owner.last_name }}"} — the path
 * may hold a list. Invalid and missing addresses are skipped with a warning, duplicates are removed.
 */
final class RecipientResolver
{
    public function __construct(private readonly TemplateEngine $templates)
    {
    }

    /**
     * @param list<mixed> $items
     * @param array<string, true> $seen addresses already used in this letter (to → cc → bcc)
     */
    public function resolve(array $items, ExecutionContext $context, array &$seen = []): RecipientList
    {
        $addresses = [];
        $warnings = [];
        $resolver = static fn (string $path) => $context->resolve($path);
        foreach ($items as $item) {
            $name = '';
            if (is_array($item)) {
                [$found, $value] = $context->resolve((string)($item['path'] ?? ''));
                if (!$found || $value === null || $value === '' || $value === []) {
                    $warnings[] = ['value' => (string)($item['path'] ?? ''), 'reason' => 'missing'];
                    continue;
                }
                $raw = is_array($value) ? array_map(static fn ($v) => is_scalar($v) ? (string)$v : '', array_values($value)) : self::split((string)$value);
                if (isset($item['name']) && is_string($item['name'])) {
                    $name = trim((string)$this->templates->render($item['name'], $resolver, EscapeMode::Header)->value);
                }
            } else {
                $text = (string)$item;
                $raw = self::split(str_contains($text, '{{') ? (string)$this->templates->render($text, $resolver, EscapeMode::Header)->value : $text);
                if ($raw === []) {
                    $warnings[] = ['value' => $text, 'reason' => 'missing'];
                }
            }
            foreach ($raw as $email) {
                $normalized = self::normalize($email);
                if ($normalized === null) {
                    $warnings[] = ['value' => $email, 'reason' => 'invalid'];
                    continue;
                }
                $key = strtolower($normalized);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $addresses[] = new Address($normalized, $name);
            }
        }
        return new RecipientList($addresses, $warnings);
    }

    /**
     * Trimmed address with a lower-case domain, or null when it is not a valid address.
     */
    public static function normalize(string $email): ?string
    {
        $email = trim($email, " \t\n\r\0\x0B<>");
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return null;
        }
        $email = substr($email, 0, $at) . '@' . mb_strtolower(substr($email, $at + 1));
        try {
            // RFC validation of symfony/mime (egulias/email-validator), internationalized domains included
            new Address($email);
        } catch (\Throwable) {
            return null;
        }
        return $email;
    }

    /**
     * @return list<string>
     */
    private static function split(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;]/', $value) ?: []), static fn ($v) => $v !== ''));
    }
}
