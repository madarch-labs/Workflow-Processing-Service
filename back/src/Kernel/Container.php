<?php

declare(strict_types=1);

namespace Wps\Kernel;

use Psr\Log\LoggerInterface;
use Sidalex\SwooleApp\Application;
use Wps\Actions\ActionHandlerRegistry;
use Wps\Actions\ChangeTypes\CalculateChangeType;
use Wps\Actions\ChangeTypes\ChangeTypeRegistry;
use Wps\Actions\ChangeTypes\SetFromVariableChangeType;
use Wps\Actions\ChangeTypes\SetValueChangeType;
use Wps\Actions\ModifyHandler;
use Wps\Actions\PreloadHandler;
use Wps\Actions\SaveHandler;
use Wps\Actions\StartProcessHandler;
use Wps\Auth\JwtService;
use Wps\Comparators\ComparatorRegistry;
use Wps\Config\AppConfig;
use Wps\Expression\ExpressionEngine;
use Wps\Infrastructure\Database;
use Wps\Infrastructure\RedisConnection;
use Wps\LoadStrategies\LoadStrategyRegistry;
use Wps\Persistence\ExecutionActionRepository;
use Wps\Persistence\ExecutionRepository;
use Wps\Persistence\ProcessObjectRunRepository;
use Wps\Persistence\ProcessRepository;
use Wps\Persistence\UserRepository;
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
use Wps\Services\ProcessValidatorService;
use Wps\State\StateInitiation;
use Wps\State\WpsState;
use Wps\Support\Logger;

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

    public function jwt(): JwtService
    {
        return $this->share(JwtService::class, fn () => new JwtService($this->config()));
    }

    public function comparators(): ComparatorRegistry
    {
        return $this->share(ComparatorRegistry::class, static fn () => ComparatorRegistry::default());
    }

    public function expressions(): ExpressionEngine
    {
        return $this->share(ExpressionEngine::class, static fn () => new ExpressionEngine());
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
            return $registry;
        });
    }

    public function crm(): ExternalApiAdapterService
    {
        return $this->share(ExternalApiAdapterService::class, fn () => new ExternalApiAdapterService($this->config(), $this->logger()));
    }

    public function actions(): ActionHandlerRegistry
    {
        return $this->share(ActionHandlerRegistry::class, function () {
            $saver = new CrmObjectSaver($this->crm());
            $registry = new ActionHandlerRegistry();
            $registry->register(new PreloadHandler($this->crm(), $this->loadStrategies()));
            $registry->register(new ModifyHandler($this->changeTypes(), $saver));
            $registry->register(new SaveHandler($saver));
            $registry->register(new StartProcessHandler(
                $this->processes(),
                fn (): ExecutionEngineService => $this->engine(),
                $this->config()->maxChildDepth,
            ));
            return $registry;
        });
    }

    public function conditions(): ConditionEngineService
    {
        return $this->share(ConditionEngineService::class, fn () => new ConditionEngineService($this->comparators()));
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
