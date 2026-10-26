<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Notify;

use PHPUnit\Framework\TestCase;
use Wps\Actions\NotifyHandler;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Http\Outbound\HttpRequestException;
use Wps\Notify\ChannelInterface;
use Wps\Notify\ChannelRegistry;
use Wps\Notify\Channels\CrmAlertChannel;
use Wps\Notify\Channels\SlackChannel;
use Wps\Notify\Channels\TeamsChannel;
use Wps\Notify\Delivery;
use Wps\Notify\NotifyMessage;
use Wps\Notify\RecordLinks;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\Validation\DefinitionScope;
use Wps\SideEffects\SideEffectLedger;
use Wps\SideEffects\SideEffectStore;
use Wps\Template\EscapeMode;
use Wps\Template\TemplateEngine;

/**
 * A channel whose deliveries succeed or throw what the test says.
 */
final class FakeChannel implements ChannelInterface
{
    /** @var list<string> */
    public array $sent = [];

    public function __construct(private readonly string $name, private readonly ?\Throwable $error = null)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->name;
    }

    public function secretKinds(): array
    {
        return [];
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
    }

    public function deliveries(array $config, NotifyMessage $message, ExecutionContext $context, string $delivery): array
    {
        if (!empty($config['none'])) {
            return [];
        }
        return [new Delivery($this->name, ['to' => $this->name], function () use ($message): array {
            if ($this->error !== null) {
                throw $this->error;
            }
            $this->sent[] = $message->plain();
            return ['ok' => true];
        })];
    }
}

final class NotifyTest extends TestCase
{
    private static function templates(): TemplateEngine
    {
        return new TemplateEngine(new ExpressionEngine());
    }

    private static function context(): ExecutionContext
    {
        return ExecutionContext::fromEvent('e', ['id' => 'L1', 'last_name' => 'Иванов <b>&', 'assigned_user_id' => 'u7', 'team' => ['u8', 'u7', '']], 'Leads');
    }

    private static function message(string $message, string $title = '', ?string $link = null): NotifyMessage
    {
        $context = self::context();
        return new NotifyMessage(self::templates(), $title, $message, static fn (string $p) => $context->resolve($p), $link, 'Leads', 'L1');
    }

    private function handler(ChannelInterface ...$channels): NotifyHandler
    {
        $registry = new ChannelRegistry();
        foreach ($channels as $c) {
            $registry->register($c);
        }
        return new NotifyHandler(self::templates(), $registry, new SideEffectLedger($this->createMock(SideEffectStore::class)), new RecordLinks());
    }

    public function testEscapingAndLength(): void
    {
        $m = self::message('Лид {{ object.last_name }}', 'Новый');
        self::assertSame('Лид Иванов &lt;b&gt;&amp;', $m->text(EscapeMode::Html)['message']);
        self::assertSame('Лид Иванов <b>&', $m->text()['message']);
        self::assertSame('Лид Иванов &lt;b&gt;&amp;', SlackChannel::escape($m->text()['message']));
        self::assertSame("Новый\nЛид Иванов <b>&", $m->plain());
        $long = self::message(str_repeat('я', 5000));
        self::assertSame(4000, mb_strlen($long->text()['message']));
        self::assertSame(['Message was cut to 4000 characters'], $long->warnings);
    }

    public function testLinksAndTeams(): void
    {
        self::assertSame('#/aos-contracts/record/c%201', (new RecordLinks())->relative('AOS_Contracts', 'c 1'));
        self::assertSame('index.php?module=Leads&action=DetailView&record=L1', (new RecordLinks('suitecrm7'))->relative('Leads', 'L1'));
        $links = new RecordLinks('suitecrm8', '', 'http://crm.local/');
        self::assertSame('http://crm.local/#/leads/record/L1', $links->absolute((string)$links->relative('Leads', 'L1')));
        self::assertNull((new RecordLinks('custom'))->relative('Leads', 'L1'));
        $card = TeamsChannel::body('adaptive_card', 'T', 'M', 'http://x/1');
        self::assertSame('application/vnd.microsoft.card.adaptive', $card['attachments'][0]['contentType']);
        self::assertSame(['T', 'M'], array_column($card['attachments'][0]['content']['body'], 'text'));
        self::assertSame('http://x/1', $card['attachments'][0]['content']['actions'][0]['url']);
        $legacy = TeamsChannel::body('message_card', '', 'M', null);
        self::assertSame(['MessageCard', 'M', 'M'], [$legacy['@type'], $legacy['text'], $legacy['summary']]);
    }

    public function testCrmAlertFields(): void
    {
        $crm = (new \ReflectionClass(ExternalApiAdapterService::class))->newInstanceWithoutConstructor();
        $channel = new CrmAlertChannel($crm, self::templates(), new RecordLinks());
        $context = self::context();
        $deliveries = $channel->deliveries(['users' => ['{{ object.assigned_user_id }}', ['path' => 'object.team'], '1']], self::message('Вам лид', '', '#/leads/record/L1'), $context, 'at_most_once');
        self::assertSame(['crm_alert:u7', 'crm_alert:u8', 'crm_alert:1'], array_map(static fn (Delivery $d) => $d->sub, $deliveries), 'templates, paths to lists, duplicates and empty ids removed');
        $fields = (new \ReflectionMethod(CrmAlertChannel::class, 'fields'))->invoke($channel, 'suitecrm7', ['title' => '', 'message' => 'Вам лид'], self::message('x', '', '#/l'), 'u7');
        self::assertSame(['name' => 'Вам лид', 'description' => 'Вам лид', 'assigned_user_id' => 'u7', 'is_read' => '0', 'type' => 'info', 'target_module' => 'Leads', 'url_redirect' => '#/l'], $fields);
        $fields8 = (new \ReflectionMethod(CrmAlertChannel::class, 'fields'))->invoke($channel, 'suitecrm8', ['title' => 'T', 'message' => 'M'], self::message('x'), 'u7');
        self::assertArrayHasKey('snooze', $fields8);
        self::assertSame('T', $fields8['name']);
    }

    public function testOutcome(): void
    {
        $step = fn (array $channels, string $failOn = 'any') => ['type' => 'notify', 'message' => 'Лид {{ object.last_name }}', 'channels' => $channels, 'fail_on' => $failOn];
        $ok = new FakeChannel('ok');
        $bad = new FakeChannel('bad', new HttpRequestException('slack: HTTP 404 no_service', 404, false, true));
        $busy = new FakeChannel('busy', new HttpRequestException('telegram: HTTP 429', 429, true, true, ['retry_after_seconds' => 3]));
        $handler = $this->handler($ok, $bad, $busy);

        $r = $handler->execute(self::context(), $step([['channel' => 'ok'], ['channel' => 'ok', 'none' => true]]));
        self::assertSame(['success', 'skipped'], array_column($r->result['channels'], 'status'));

        $r = $handler->execute(self::context(), $step([['channel' => 'bad'], ['channel' => 'ok']], 'all'));
        self::assertSame('success', $r->status, 'fail_on all: something was delivered');
        self::assertSame(['failed', 'success'], array_column($r->result['channels'], 'status'));

        try {
            $handler->execute(self::context(), $step([['channel' => 'bad'], ['channel' => 'ok']]));
            self::fail('fail_on any');
        } catch (NonRecoverableException $e) {
            self::assertSame('notify: 1 of 2 channels failed: bad: slack: HTTP 404 no_service', $e->getMessage());
            self::assertSame('success', $e->getDetails()['channels'][1]['status']);
        }
        try {
            $handler->execute(self::context(), $step([['channel' => 'busy'], ['channel' => 'bad']]));
            self::fail('recoverable');
        } catch (HttpRequestException $e) {
            self::assertTrue($e->isRecoverable(), 'a recoverable channel error repeats the step');
        }
        self::assertCount(3, $ok->sent, 'the other channels were still sent');
    }

    public function testValidation(): void
    {
        $crm = (new \ReflectionClass(ExternalApiAdapterService::class))->newInstanceWithoutConstructor();
        $handler = $this->handler(new CrmAlertChannel($crm, self::templates(), new RecordLinks()), new FakeChannel('ok'));
        $scope = new DefinitionScope();
        $handler->validate(['message' => '', 'channels' => [], 'fail_on' => 'some', 'link' => 'x'], $scope, 'a');
        $handler->validate(['message' => 'Hi {{ v9.x }}', 'link' => ['record' => 'v8'], 'channels' => [
            ['channel' => 'viber'],
            ['channel' => 'crm_alert', 'users' => [], 'profile' => 'x', 'fields' => ['name' => 5]],
            ['channel' => 'crm_alert', 'users' => [['path' => 'v7.id'], 7]],
        ]], $scope, 'b');
        self::assertSame([
            'a.message: Message is required',
            'a.link: Use {"record": "<variable>"} or {"url": "<template>"}',
            'a.channels: From 1 to 10 channels are required',
            'a.fail_on: Must be "any" or "all"',
            'b.message: Variable "v9" is not defined at this point',
            'b.link.record: Variable "v8" is not defined at this point',
            'b.channels[0].channel: Unknown channel "viber"',
            'b.channels[1].users: At least one user is required',
            'b.channels[1].profile: Must be one of: suitecrm8, suitecrm7, custom',
            'b.channels[1].fields.name: Must be a template string',
            'b.channels[2].users[0].path: Variable "v7" is not defined at this point',
            'b.channels[2].users[1]: User id, template or {"path": ...} is required',
        ], array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $scope->errors()));
    }
}
