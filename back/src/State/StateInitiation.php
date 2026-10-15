<?php

declare(strict_types=1);

namespace Wps\State;

use Sidalex\SwooleApp\Application;
use Sidalex\SwooleApp\Classes\Initiation\AbstractContainerInitiator;
use Wps\Config\AppConfig;
use Wps\Services\MetricsService;

/**
 * Runs once in the master process (inside Application::__construct, before workers fork).
 * Puts into the SwooleApp state container everything that must be built once and shared:
 *  - the parsed configuration;
 *  - the shared-memory metrics table (Swoole\Table must be created before fork).
 */
final class StateInitiation extends AbstractContainerInitiator
{
    public const KEY = 'wps';

    protected string $key = self::KEY;

    public function init(Application $param): void
    {
        $this->result = new WpsState(
            config: AppConfig::fromEnv(),
            metrics: MetricsService::createShared(),
        );
    }
}
