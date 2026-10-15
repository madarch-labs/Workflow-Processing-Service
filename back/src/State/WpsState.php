<?php

declare(strict_types=1);

namespace Wps\State;

use Wps\Config\AppConfig;
use Wps\Services\MetricsService;

final class WpsState
{
    public function __construct(
        public readonly AppConfig $config,
        public readonly MetricsService $metrics,
    ) {
    }
}
