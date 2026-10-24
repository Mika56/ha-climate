<?php

declare(strict_types=1);

namespace App\Application\Port\HomeAssistant;

/**
 * Handle on an active entity subscription.
 */
interface SubscriptionInterface
{
    /**
     * Stops receiving changes. Idempotent; never throws when the connection is down.
     */
    public function unsubscribe(): void;
}
