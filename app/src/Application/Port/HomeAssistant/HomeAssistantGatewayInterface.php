<?php

declare(strict_types=1);

namespace App\Application\Port\HomeAssistant;

/**
 * Access to Home Assistant over its WebSocket API.
 *
 * Implementations are fiber based: methods look synchronous but must be called from within the Revolt
 * event loop (or the main fiber with the loop available).
 */
interface HomeAssistantGatewayInterface
{
    /**
     * Opens the connection and authenticates. Idempotent.
     *
     * @throws AuthenticationFailedException
     * @throws ConnectionLostException
     */
    public function connect(): void;

    /**
     * Closes the connection for good: no reconnection, subscriptions are dropped.
     */
    public function disconnect(): void;

    /**
     * @throws HomeAssistantException
     */
    public function getConfig(): HomeAssistantConfig;

    /**
     * @return list<EntityState>
     *
     * @throws HomeAssistantException
     */
    public function getStates(): array;

    /**
     * Subscribes to the given entities only (WS command `subscribe_entities`).
     *
     * The listener first receives the current state of each entity (old = null), then every change.
     * After a reconnection the subscription is re-established and the fresh states are reported as
     * changes against the last known ones. Listeners are called sequentially per subscription, outside
     * the connection reader, so they may call the gateway.
     *
     * @param list<string>                      $entityIds
     * @param \Closure(EntityStateChange): void $listener
     *
     * @throws HomeAssistantException
     */
    public function subscribeEntities(array $entityIds, \Closure $listener): SubscriptionInterface;

    /**
     * @param array<string, mixed> $serviceData
     * @param array<string, mixed> $target
     *
     * @throws HomeAssistantException
     */
    public function callService(string $domain, string $service, array $serviceData = [], array $target = []): ServiceCallResult;

    /**
     * @return array<string, array<string, ServiceDescription>> indexed by domain, then by service
     *
     * @throws HomeAssistantException
     */
    public function getServices(): array;

    /**
     * @return list<Area>
     *
     * @throws HomeAssistantException
     */
    public function listAreas(): array;

    /**
     * @return list<DeviceEntry>
     *
     * @throws HomeAssistantException
     */
    public function listDevices(): array;

    /**
     * @return list<EntityRegistryEntry>
     *
     * @throws HomeAssistantException
     */
    public function listEntityRegistry(): array;

    /**
     * Registers a hook called when the connection drops unexpectedly (once per outage).
     *
     * @param \Closure(): void $hook
     */
    public function onConnectionLost(\Closure $hook): void;

    /**
     * Registers a hook called once the connection is back, authenticated and subscriptions restored.
     *
     * @param \Closure(): void $hook
     */
    public function onReconnected(\Closure $hook): void;
}
