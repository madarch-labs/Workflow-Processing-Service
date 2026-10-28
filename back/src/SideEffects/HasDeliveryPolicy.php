<?php

declare(strict_types=1);

namespace Wps\SideEffects;

/**
 * Implemented by actions with external side effects (send_email, http_request, notify): they accept "delivery"
 * and run their external calls through SideEffectLedger.
 */
interface HasDeliveryPolicy
{
    /**
     * @param array<string, mixed> $config the step config
     * @return string DeliveryPolicy::AT_MOST_ONCE or AT_LEAST_ONCE
     */
    public function defaultDelivery(array $config): string;
}
