<?php

declare(strict_types=1);

namespace Wps\Kernel;

use Psr\Log\LoggerInterface;
use Sidalex\SwooleApp\Application;
use Wps\Actions\ActionHandlerRegistry;
use Wps\Actions\ChangeTypes\AggregateChangeType;
use Wps\Actions\ChangeTypes\AppendChangeType;
use Wps\Actions\ChangeTypes\ClearChangeType;
use Wps\Actions\ChangeTypes\IncrementChangeType;
use Wps\Actions\ChangeTypes\MapChangeType;
use Wps\Actions\ChangeTypes\CalculateChangeType;
use Wps\Actions\ChangeTypes\ChangeTypeRegistry;
use Wps\Actions\ChangeTypes\DateShiftChangeType;
use Wps\Actions\ChangeTypes\SetFromVariableChangeType;
use Wps\Actions\ChangeTypes\SetValueChangeType;
use Wps\Actions\ChangeTypes\TemplateChangeType;
use Wps\Actions\DelayHandler;
use Wps\Actions\FindRecordsHandler;
use Wps\Actions\UpdateRecordsHandler;
use Wps\Actions\NotifyHandler;
use Wps\Actions\AssignHandler;
use Wps\Services\Assign\AssignmentStrategies;
use Wps\Services\Assign\UserPoolResolver;
use Wps\Notify\ChannelRegistry;
use Wps\Notify\Channels\CrmAlertChannel;
use Wps\Notify\Channels\SlackChannel;
use Wps\Notify\Channels\TeamsChannel;
use Wps\Notify\Channels\TelegramChannel;
use Wps\Notify\HttpChannelSupport;
use Wps\Notify\RecordLinks;
use Wps\Actions\HttpRequestHandler;
use Wps\Actions\LinkRecordsHandler;
use Wps\Actions\ModifyHandler;
use Wps\Actions\PreloadHandler;
use Wps\Actions\SaveHandler;
use Wps\Actions\SendEmailHandler;
use Wps\Actions\SetVariableHandler;
use Wps\Actions\StartProcessHandler;
use Wps\Auth\JwtService;
use Wps\Comparators\ComparatorRegistry;
use Wps\Config\AppConfig;
use Wps\Crm\CrmCapabilitiesService;
use Wps\Crm\FilterRulesValidator;
use Wps\Crm\FilterTranslator;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\Functions\DateFunctions;
use Wps\Expression\Functions\TypeFunctions;
use Wps\Infrastructure\Database;
use Wps\Http\Outbound\Auth\OAuth2TokenProvider;
use Wps\Http\Outbound\EgressGuard;
use Wps\Http\Outbound\OutboundHttpClient;
use Wps\Infrastructure\RedisConnection;
use Wps\LoadStrategies\LoadStrategyRegistry;
use Wps\Mail\MailerFactory;
use Wps\Mail\MailRateLimiter;
use Wps\Persistence\ExecutionActionRepository;
use Wps\Persistence\ExecutionRepository;
use Wps\Persistence\ExecutionWaitRepository;
use Wps\Persistence\ProcessObjectRunRepository;
use Wps\Persistence\ProcessRepository;
use Wps\Persistence\UserRepository;
use Wps\Program\Blocks\ConditionBlock;
use Wps\Program\Blocks\ForeachBlock;
use Wps\Program\Blocks\StopProcessBlock;
use Wps\Program\LoopItemsStore;
use Wps\Program\LoopRunner;
use Wps\Program\BlockTypeRegistry;
use Wps\Program\ProgramCompiler;
use Wps\Services\AuditService;
use Wps\Services\ConditionEngineService;
use Wps\Services\CrmObjectSaver;
use Wps\Services\DictionaryService;
use Wps\Services\EventProcessorService;
use Wps\Services\ExecutionEngineService;
use Wps\Services\ExecutionStateStore;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\MetricsService;
use Wps\Services\ProcessResolverService;
use Wps\Services\ProcessService;
use Wps\Services\StreamInfoService;
use Wps\Services\StreamService;
use Wps\Services\WaitService;
use Wps\Services\ProcessValidatorService;
use Wps\State\StateInitiation;
use Wps\State\WpsState;
use Wps\Secrets\SecretCipher;
use Wps\Secrets\SecretRepository;
use Wps\Secrets\SecretResolver;
use Wps\Secrets\SecretService;
use Wps\Secrets\SecretUsageScanner;
use Wps\SideEffects\SideEffectLedger;
use Wps\SideEffects\SideEffectRepository;
use Wps\Support\Logger;
use Wps\Template\TemplateEngine;
use Wps\Template\TemplateValidator;
use Wps\Time\Clock;
use Wps\Time\DateMath;
use Wps\Time\SystemClock;
use Wps\Types\TypeCaster;

/**
 * Per-worker service locator. Built lazily on first use inside a worker (after fork), so every
 * worker gets its own connection pools. Registries are the extension points for new
 * comparators / actions / change types / load strategies (a restart is required — answers #25, #26).
 */
final class Container
{
    private static ?self $instance = null;
    private static ?int $pid = null;

    /** @var array<string, object> */
    private array $services = [];

    private function __construct(private readonly WpsState $state)
    {
    }

    public static function get(): self
    {
        $pid = getmypid();
        if (self::$instance === null || self::$pid !== $pid) {
            $state = Application::getInstance()?->getStateContainer()->getContainer(StateInitiation::KEY);
            if (!$state instanceof WpsState) {
                throw new \RuntimeException('WPS state is not initialised (check StateContainerInitiation in config.json)');
            }
            self::$instance = new self($state);
            self::$pid = $pid;
        }
        return self::$instance;
    }

    /**
     * For CLI tools/tests without a running Application.
     */
    public static function forState(WpsState $state): self
    {
        self::$instance = new self($state);
        self::$pid = getmypid();
        return self::$instance;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param callable(): T $factory
     * @return T
     */
    private function share(string $id, callable $factory): object
    {
        return $this->services[$id] ??= $factory();
    }

    public function config(): AppConfig
    {
        return $this->state->config;
    }

    public function metrics(): MetricsService
    {
        return $this->state->metrics;
    }

    public function logger(): LoggerInterface
    {
        return $this->share(Logger::class, fn () => new Logger($this->config()->logLevel));
    }

    public function db(): Database
    {
        return $this->share(Database::class, fn () => new Database($this->config()));
    }

    public function redisData(): RedisConnection
    {
        return $this->share('redis.data', function () {
            $c = $this->config();
            return new RedisConnection($c->dataHost, $c->dataPort, $c->dataPassword, $c->dataDb, 32);
        });
    }

    public function redisStream(): RedisConnection
    {
        return $this->share('redis.stream', function () {
            $c = $this->config();
            // XREADGROUP BLOCK needs a read timeout longer than the block time
            return new RedisConnection($c->streamHost, $c->streamPort, $c->streamPassword, 0, 8, max(5.0, $c->streamBlockMs / 1000 + 3));
        });
    }

    public function processes(): ProcessRepository
    {
        return $this->share(ProcessRepository::class, fn () => new ProcessRepository($this->db()));
    }

    public function executions(): ExecutionRepository
    {
        return $this->share(ExecutionRepository::class, fn () => new ExecutionRepository($this->db()));
    }

    public function processRuns(): ProcessObjectRunRepository
    {
        return $this->share(ProcessObjectRunRepository::class, fn () => new ProcessObjectRunRepository($this->db()));
    }

    public function executionActions(): ExecutionActionRepository
    {
        return $this->share(ExecutionActionRepository::class, fn () => new ExecutionActionRepository($this->db()));
    }

    public function users(): UserRepository
    {
        return $this->share(UserRepository::class, fn () => new UserRepository($this->db()));
    }

    public function secretCipher(): SecretCipher
    {
        return $this->share(SecretCipher::class, fn () => new SecretCipher($this->config()->secretsKey, $this->config()->secretsKeyPrevious));
    }

    public function secretRepository(): SecretRepository
    {
        return $this->share(SecretRepository::class, fn () => new SecretRepository($this->db()));
    }

    /**
     * secret://name references in credential fields of actions (decrypted values stay in the worker memory).
     */
    public function secretResolver(): SecretResolver
    {
        return $this->share(SecretResolver::class, fn () => new SecretResolver(
            $this->secretRepository(),
            $this->secretCipher(),
            $this->config()->secretsCacheTtl,
            $this->metrics(),
        ));
    }

    public function secrets(): SecretService
    {
        return $this->share(SecretService::class, fn () => new SecretService(
            $this->secretRepository(),
            $this->secretCipher(),
            new SecretUsageScanner($this->secretRepository()),
            $this->secretResolver(),
            $this->logger(),
        ));
    }

    /**
     * Journal of external side effects for actions with "delivery" (send_email, http_request, notify).
     */
    public function sideEffects(): SideEffectLedger
    {
        return $this->share(SideEffectLedger::class, fn () => new SideEffectLedger(
            new SideEffectRepository($this->db()),
            $this->metrics(),
            $this->logger(),
        ));
    }

    public function jwt(): JwtService
    {
        return $this->share(JwtService::class, fn () => new JwtService($this->config()));
    }

    public function typeCaster(): TypeCaster
    {
        return $this->share(TypeCaster::class, fn () => new TypeCaster(new \DateTimeZone($this->config()->timezone)));
    }

    public function comparators(): ComparatorRegistry
    {
        return $this->share(ComparatorRegistry::class, fn () => ComparatorRegistry::default($this->typeCaster()));
    }

    public function expressions(): ExpressionEngine
    {
        return $this->share(ExpressionEngine::class, function (): ExpressionEngine {
            $engine = new ExpressionEngine($this->typeCaster());
            DateFunctions::register($engine, $this->dateMath(), $this->clock());
            TypeFunctions::register($engine, $this->typeCaster(), $this->dateMath());
            return $engine;
        });
    }

    public function templates(): TemplateEngine
    {
        return $this->share(TemplateEngine::class, fn () => new TemplateEngine($this->expressions(), $this->metrics()));
    }

    public function templateValidator(): TemplateValidator
    {
        return $this->share(TemplateValidator::class, fn () => new TemplateValidator($this->templates()));
    }

    public function executionWaits(): ExecutionWaitRepository
    {
        return $this->share(ExecutionWaitRepository::class, fn () => new ExecutionWaitRepository($this->db()));
    }

    public function waits(): WaitService
    {
        return $this->share(WaitService::class, fn () => new WaitService(
            $this->executionWaits(),
            $this->stateStore(),
            $this->executions(),
            $this->config(),
            $this->metrics(),
            $this->logger(),
            fn (): ExecutionEngineService => $this->engine(),
            $this->loopItemsStore(),
        ));
    }

    public function recordLinks(): RecordLinks
    {
        return $this->share(RecordLinks::class, fn () => new RecordLinks($this->config()->crmAlertProfile, $this->config()->crmRecordUrlTemplate, $this->config()->crmUiBaseUrl));
    }

    /**
     * Channels of the notify action (md/tasks_list/4.8-notify.md).
     */
    public function notifyChannels(): ChannelRegistry
    {
        return $this->share(ChannelRegistry::class, function (): ChannelRegistry {
            $http = new HttpChannelSupport($this->outboundHttp(), $this->secretResolver(), $this->config()->httpRetryAfterMax);
            $registry = new ChannelRegistry();
            $registry->register(new CrmAlertChannel($this->crm(), $this->templates(), $this->recordLinks()));
            $registry->register(new TelegramChannel($http, $this->templates(), $this->recordLinks(), $this->config()->telegramApiBase));
            $registry->register(new SlackChannel($http, $this->recordLinks()));
            $registry->register(new TeamsChannel($http, $this->recordLinks()));
            return $registry;
        });
    }

    public function loopItemsStore(): LoopItemsStore
    {
        return $this->share(LoopItemsStore::class, fn () => new LoopItemsStore($this->redisData(), $this->config()->executionTtlSeconds));
    }

    public function loopRunner(): LoopRunner
    {
        return $this->share(LoopRunner::class, fn () => new LoopRunner(
            $this->conditions(),
            $this->expressions(),
            $this->loopItemsStore(),
            $this->config()->loopMaxItems,
            $this->metrics(),
            $this->logger(),
        ));
    }

    public function outboundHttp(): OutboundHttpClient
    {
        return $this->share(OutboundHttpClient::class, function (): OutboundHttpClient {
            $c = $this->config();
            return new OutboundHttpClient(
                new EgressGuard($c->httpAllowPlainHttp, $c->httpDenyPorts, $c->httpAllowPorts, $c->httpAllowHosts, $c->httpDenyHosts, $c->httpAllowPrivateCidrs),
                $c->httpTlsVerify,
                $c->httpCaFile,
                $c->httpMaxResponseBytes,
            );
        });
    }

    public function mailer(): MailerFactory
    {
        return $this->share(MailerFactory::class, fn () => new MailerFactory($this->config(), $this->secretResolver()));
    }

    public function clock(): Clock
    {
        return $this->share(Clock::class, fn () => new SystemClock());
    }

    public function dateMath(): DateMath
    {
        return $this->share(DateMath::class, fn () => new DateMath(new \DateTimeZone($this->config()->timezone)));
    }

    public function loadStrategies(): LoadStrategyRegistry
    {
        return $this->share(LoadStrategyRegistry::class, static fn () => LoadStrategyRegistry::default());
    }

    public function changeTypes(): ChangeTypeRegistry
    {
        return $this->share(ChangeTypeRegistry::class, function () {
            $registry = new ChangeTypeRegistry();
            $registry->register(new SetValueChangeType());
            $registry->register(new SetFromVariableChangeType());
            $registry->register(new CalculateChangeType($this->expressions()));
            $registry->register(new TemplateChangeType($this->templates(), $this->templateValidator()));
            $registry->register(new DateShiftChangeType($this->dateMath(), $this->clock()));
            $registry->register(new IncrementChangeType($this->typeCaster(), 1));
            $registry->register(new IncrementChangeType($this->typeCaster(), -1));
            $registry->register(new ClearChangeType());
            foreach (array_keys(AppendChangeType::OPERATIONS) as $operation) {
                $registry->register(new AppendChangeType($this->templates(), $this->typeCaster(), $operation));
            }
            $registry->register(new MapChangeType($this->typeCaster(), $this->templates()));
            $registry->register(new AggregateChangeType($this->crm(), new FilterTranslator($this->typeCaster(), $this->clock()),
                new FilterRulesValidator($this->comparators(), $this->typeCaster()), $this->typeCaster(), $this->config()->aggregateMaxRecords, $this->metrics()));
            return $registry;
        });
    }

    public function crm(): ExternalApiAdapterService
    {
        return $this->share(ExternalApiAdapterService::class, fn () => new ExternalApiAdapterService(
            $this->config(),
            $this->logger(),
            fn () => $this->crmCapabilities()->get(),
        ));
    }

    /**
     * What the CRM plugin implements (contract 1.0 / 2.0), cached in Redis.
     */
    public function crmCapabilities(): CrmCapabilitiesService
    {
        return $this->share(CrmCapabilitiesService::class, fn () => new CrmCapabilitiesService($this->crm(), $this->redisData(), $this->config(), $this->logger()));
    }

    public function actions(): ActionHandlerRegistry
    {
        return $this->share(ActionHandlerRegistry::class, function () {
            $saver = new CrmObjectSaver($this->crm());
            $registry = new ActionHandlerRegistry();
            $registry->register(new PreloadHandler($this->crm(), $this->loadStrategies()));
            $registry->register(new ModifyHandler($this->changeTypes(), $saver));
            $registry->register(new SaveHandler($saver));
            $registry->register(new SetVariableHandler($this->changeTypes()));
            $registry->register(new FindRecordsHandler($this->crm(), new FilterTranslator($this->typeCaster(), $this->clock()), $this->conditions(),
                $this->comparators(), $this->typeCaster(), $this->config()));
            $registry->register(new UpdateRecordsHandler($this->changeTypes(), $this->crm(), $this->config(), $this->metrics()));
            $registry->register(new DelayHandler($this->expressions(), $this->dateMath(), $this->clock(), $this->config()));
            $registry->register(new SendEmailHandler(
                $this->templates(),
                $this->mailer(),
                $this->sideEffects(),
                new MailRateLimiter($this->redisData(), $this->config()->mailerRatePerMinute, $this->config()->mailerRatePerRecipientHour),
                $this->config(),
                $this->crm(),
                $this->secretResolver(),
                $this->metrics(),
                $this->logger(),
            ));
            $registry->register(new HttpRequestHandler(
                $this->templates(),
                $this->outboundHttp(),
                $this->sideEffects(),
                $this->config(),
                $this->secretResolver(),
                new OAuth2TokenProvider($this->outboundHttp(), $this->redisData()),
                $this->metrics(),
                $this->logger(),
            ));
            $registry->register(new AssignHandler(
                new UserPoolResolver($this->crm(), $this->redisData(), $this->config()->assignPoolCacheTtl),
                new AssignmentStrategies($this->redisData(), $this->crm()),
                new FilterTranslator($this->typeCaster(), $this->clock()),
                new FilterRulesValidator($this->comparators(), $this->typeCaster()),
                $saver,
                $this->redisData(),
                $this->config(),
                $this->metrics(),
            ));
            $registry->register(new NotifyHandler($this->templates(), $this->notifyChannels(), $this->sideEffects(), $this->recordLinks(), $this->metrics(), $this->logger()));
            foreach ([LinkRecordsHandler::LINK, LinkRecordsHandler::UNLINK] as $mode) {
                $registry->register(new LinkRecordsHandler($this->crm(), $this->crmCapabilities(), $this->config()->linkRecordsMax, $mode, $this->metrics()));
            }
            $registry->register(new StartProcessHandler(
                $this->processes(),
                fn (): ExecutionEngineService => $this->engine(),
                $this->config()->maxChildDepth,
            ));
            return $registry;
        });
    }

    /**
     * Control-flow blocks of process definitions (condition, foreach, stop_process).
     */
    public function blocks(): BlockTypeRegistry
    {
        return $this->share(BlockTypeRegistry::class, function () {
            $registry = new BlockTypeRegistry();
            $registry->register(new ConditionBlock());
            $registry->register(new StopProcessBlock($this->templates()));
            $registry->register(new ForeachBlock($this->expressions(), $this->config()->loopMaxItems));
            foreach (array_column($registry->dictionary(), 'value') as $type) {
                if ($this->actions()->has($type)) {
                    throw new \LogicException("\"{$type}\" is registered both as an action and as a block");
                }
            }
            return $registry;
        });
    }

    public function programCompiler(): ProgramCompiler
    {
        return $this->share(ProgramCompiler::class, fn () => new ProgramCompiler($this->blocks(), $this->config()->programMaxDepth, $this->metrics()));
    }

    public function conditions(): ConditionEngineService
    {
        return $this->share(ConditionEngineService::class, fn () => new ConditionEngineService(
            $this->comparators(),
            $this->typeCaster(),
            $this->metrics(),
            $this->logger(),
            $this->clock(),
            $this->expressions(),
        ));
    }

    public function stateStore(): ExecutionStateStore
    {
        return $this->share(ExecutionStateStore::class, fn () => new ExecutionStateStore($this->redisData(), $this->config()));
    }

    public function audit(): AuditService
    {
        return $this->share(AuditService::class, fn () => new AuditService($this->executionActions(), $this->metrics()));
    }

    public function engine(): ExecutionEngineService
    {
        return $this->share(ExecutionEngineService::class, fn () => new ExecutionEngineService(
            $this->config(),
            $this->actions(),
            $this->conditions(),
            $this->executions(),
            $this->processRuns(),
            $this->audit(),
            $this->stateStore(),
            $this->metrics(),
            $this->logger(),
            $this->programCompiler(),
            $this->templates(),
            fn (): WaitService => $this->waits(),
            $this->loopRunner(),
            $this->clock(),
        ));
    }

    public function resolver(): ProcessResolverService
    {
        return $this->share(ProcessResolverService::class, fn () => new ProcessResolverService(
            $this->processes(),
            $this->redisData(),
            $this->config(),
            $this->logger(),
        ));
    }

    public function eventProcessor(): EventProcessorService
    {
        return $this->share(EventProcessorService::class, fn () => new EventProcessorService(
            $this->resolver(),
            $this->engine(),
            $this->metrics(),
            $this->logger(),
        ));
    }

    public function validator(): ProcessValidatorService
    {
        return $this->share(ProcessValidatorService::class, fn () => new ProcessValidatorService(
            $this->actions(),
            $this->comparators(),
            $this->processes(),
            $this->typeCaster(),
            $this->blocks(),
            $this->config()->programMaxDepth,
            $this->secretRepository(),
            $this->expressions(),
        ));
    }

    public function dictionaries(): DictionaryService
    {
        return $this->share(DictionaryService::class, fn () => new DictionaryService(
            $this->crm(),
            $this->redisData(),
            $this->config(),
            $this->logger(),
        ));
    }

    public function processService(): ProcessService
    {
        return $this->share(ProcessService::class, fn () => new ProcessService(
            $this->processes(),
            $this->validator(),
            $this->resolver(),
            $this->executionWaits(),
        ));
    }

    public function streamInfo(): StreamInfoService
    {
        return $this->share(StreamInfoService::class, fn () => new StreamInfoService($this->redisStream(), $this->config()));
    }

    public function stream(): StreamService
    {
        return $this->share(StreamService::class, fn () => new StreamService(
            $this->redisStream(),
            $this->eventProcessor(),
            $this->config(),
            $this->logger(),
        ));
    }
}
