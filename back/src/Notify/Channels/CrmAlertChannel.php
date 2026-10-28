<?php

declare(strict_types=1);

namespace Wps\Notify\Channels;

use Wps\Context\ExecutionContext;
use Wps\Notify\ChannelInterface;
use Wps\Notify\Delivery;
use Wps\Notify\NotifyMessage;
use Wps\Notify\RecordLinks;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\Validation\DefinitionScope;
use Wps\Template\TemplateEngine;
use Wps\Template\TemplateValidator;

/**
 * Notification in the CRM bell: one Alerts record per user through POST /{module} of the contract. The fields follow
 * the profile (SuiteCRM 8 — a superset; SuiteCRM 7 ignores the extra fields) and can be overridden with "fields".
 */
final class CrmAlertChannel implements ChannelInterface
{
    public const PROFILES = ['suitecrm8', 'suitecrm7', 'custom'];
    private const MAX_USERS = 50;

    private readonly TemplateValidator $validator;

    public function __construct(
        private readonly ExternalApiAdapterService $crm,
        private readonly TemplateEngine $templates,
        private readonly RecordLinks $links,
    ) {
        $this->validator = new TemplateValidator($templates);
    }

    public function name(): string
    {
        return 'crm_alert';
    }

    public function label(): string
    {
        return 'Уведомление в CRM';
    }

    public function secretKinds(): array
    {
        return [];
    }

    public function deliveries(array $config, NotifyMessage $message, ExecutionContext $context, string $delivery): array
    {
        $module = (string)($config['module'] ?? 'Alerts');
        $profile = (string)($config['profile'] ?? $this->links->profile());
        $text = $message->text();
        $result = [];
        foreach ($this->users($config, $context) as $user) {
            $fields = $this->fields($profile, $text, $message, $user);
            foreach ((array)($config['fields'] ?? []) as $field => $template) {
                $fields[(string)$field] = (string)$this->templates->render((string)$template, static fn (string $p) => $context->resolve($p))->value;
            }
            $result[] = new Delivery("crm_alert:{$user}", ['module' => $module, 'user' => $user],
                function (string $key) use ($module, $fields): array {
                    $record = $this->crm->create($module, $fields);
                    return ['record' => (string)($record['id'] ?? '')];
                });
        }
        return $result;
    }

    /**
     * @param array{title: string, message: string} $text
     * @return array<string, mixed>
     */
    private function fields(string $profile, array $text, NotifyMessage $message, string $user): array
    {
        $fields = [
            'name' => $text['title'] !== '' ? $text['title'] : mb_substr($text['message'], 0, 80),
            'description' => $text['message'],
            'assigned_user_id' => $user,
            'is_read' => '0',
            'type' => 'info',
            'target_module' => $message->linkModule ?? '',
            'url_redirect' => $message->link ?? '',
        ];
        if ($profile === 'suitecrm8') {
            $fields += ['snooze' => null, 'date_start' => gmdate('Y-m-d H:i:s'), 'status' => ''];
        }
        return $profile === 'custom' ? [] : $fields;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function users(array $config, ExecutionContext $context): array
    {
        $ids = [];
        foreach ((array)($config['users'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['path'])) {
                $value = $context->get((string)$entry['path']);
                foreach (is_array($value) ? $value : [$value] as $v) {
                    $ids[] = is_scalar($v) ? trim((string)$v) : '';
                }
            } elseif (is_string($entry)) {
                $ids[] = str_contains($entry, '{{')
                    ? trim((string)$this->templates->render($entry, static fn (string $p) => $context->resolve($p))->value)
                    : trim($entry);
            }
        }
        return array_slice(array_values(array_unique(array_filter($ids, static fn ($id) => $id !== ''))), 0, self::MAX_USERS);
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        $users = $config['users'] ?? null;
        if (!is_array($users) || $users === [] || !array_is_list($users)) {
            $scope->error("{$path}.users", 'At least one user is required');
        } else {
            foreach ($users as $i => $entry) {
                if (is_array($entry) && isset($entry['path'])) {
                    $scope->checkReadPath($entry['path'], "{$path}.users[{$i}].path", allowWholeVariable: true);
                } elseif (is_string($entry) && trim($entry) !== '') {
                    $this->validator->validate($entry, $scope, "{$path}.users[{$i}]");
                } else {
                    $scope->error("{$path}.users[{$i}]", 'User id, template or {"path": ...} is required');
                }
            }
        }
        if (isset($config['module']) && (!is_string($config['module']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $config['module']))) {
            $scope->error("{$path}.module", 'Invalid CRM module');
        }
        if (isset($config['profile']) && !in_array($config['profile'], self::PROFILES, true)) {
            $scope->error("{$path}.profile", 'Must be one of: ' . implode(', ', self::PROFILES));
        }
        if (array_key_exists('fields', $config)) {
            if (!is_array($config['fields']) || array_is_list($config['fields']) && $config['fields'] !== []) {
                $scope->error("{$path}.fields", 'Must be a map "CRM field" → template');
            } else {
                foreach ($config['fields'] as $field => $template) {
                    if (!is_string($template)) {
                        $scope->error("{$path}.fields.{$field}", 'Must be a template string');
                    } else {
                        $this->validator->validate($template, $scope, "{$path}.fields.{$field}");
                    }
                }
            }
        }
        if (($config['profile'] ?? null) === 'custom' && empty($config['fields'])) {
            $scope->error("{$path}.fields", 'Profile "custom" needs "fields"');
        }
    }
}
