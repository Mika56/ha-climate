<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Port\HomeAssistant\Area;
use App\Application\Port\HomeAssistant\ConnectionLostException;
use App\Application\Port\HomeAssistant\DeviceEntry;
use App\Application\Port\HomeAssistant\EntityRegistryEntry;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\HomeAssistantConfig;
use App\Application\Port\HomeAssistant\HomeAssistantException;
use App\Application\Port\HomeAssistant\HomeAssistantGatewayInterface;
use App\Application\Port\HomeAssistant\ServiceCallResult;
use App\Application\Port\HomeAssistant\ServiceDescription;
use App\Application\Port\HomeAssistant\StateContext;
use App\Application\Port\HomeAssistant\SubscriptionInterface;

/**
 * In-memory Home Assistant for tests of the engine and everything above the gateway.
 *
 * Typical use:
 *
 *     $ha = new FakeHomeAssistantGateway();
 *     $ha->seedState('sensor.living_temp', '19.5');
 *     $ha->connect();
 *     $ha->subscribeEntities(['sensor.living_temp'], $listener);   // listener gets the current state at once
 *     $ha->pushStateChange('sensor.living_temp', '18.9');          // listener called synchronously
 *     $ha->callService('climate', 'set_temperature', [...], ['entity_id' => 'climate.living']);
 *     $ha->serviceCalls();                                          // what the code under test sent
 *
 * Differences with the real gateway, on purpose: listeners run synchronously (no fibers, no event loop
 * needed), time is the injected `$now` closure (default: real clock), and service calls only get recorded
 * (use {@see pushStateChange()} to simulate the device reacting).
 *
 * Connection loss: {@see simulateConnectionLoss()} makes every call throw {@see ConnectionLostException},
 * keeps accepting {@see pushStateChange()} silently, and {@see simulateReconnect()} then reports to each
 * subscription what changed while it was down, like the real resubscription does.
 */
final class FakeHomeAssistantGateway implements HomeAssistantGatewayInterface
{
    private bool $connected = false;
    private bool $connectionAvailable = true;
    private int $contextCounter = 0;
    private ?HomeAssistantException $nextServiceFailure = null;

    /** Counts how often tests looked at the fake: two looks are not assumed to see the same thing by static analysis. */
    private int $inspections = 0;

    /** @var array<string, EntityState> */
    private array $states = [];

    /** @var list<RecordedServiceCall> */
    private array $serviceCalls = [];

    /** @var array<int, FakeSubscription> */
    private array $subscriptions = [];
    private int $nextSubscriptionId = 0;

    /** @var list<\Closure(): void> */
    private array $lostHooks = [];

    /** @var list<\Closure(): void> */
    private array $reconnectedHooks = [];

    private HomeAssistantConfig $config;

    /** @var array<string, array<string, ServiceDescription>> */
    private array $services = [];

    /** @var list<Area> */
    private array $areas = [];

    /** @var list<DeviceEntry> */
    private array $devices = [];

    /** @var list<EntityRegistryEntry> */
    private array $entityRegistry = [];

    /**
     * @param \Closure(): \DateTimeImmutable|null $now
     */
    public function __construct(
        private ?\Closure $now = null,
    ) {
        $this->config = new HomeAssistantConfig('en', 'UTC', '°C', '2025.1.0');
    }

    // ---------------------------------------------------------------- test API: seeding

    /**
     * Makes `last_changed` and `last_updated` follow the given clock instead of the real one.
     *
     * @param \Closure(): \DateTimeImmutable $now
     */
    public function followingClock(\Closure $now): self
    {
        $this->now = $now;

        return $this;
    }

    public function withConfig(HomeAssistantConfig $config): self
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @param array<string, array<string, ServiceDescription>> $services
     */
    public function withServices(array $services): self
    {
        $this->services = $services;

        return $this;
    }

    /**
     * @param list<Area>                $areas
     * @param list<DeviceEntry>         $devices
     * @param list<EntityRegistryEntry> $entityRegistry
     */
    public function withRegistries(array $areas = [], array $devices = [], array $entityRegistry = []): self
    {
        $this->areas = $areas;
        $this->devices = $devices;
        $this->entityRegistry = $entityRegistry;

        return $this;
    }

    /**
     * Sets a state without notifying anybody (use before subscribing).
     *
     * @param array<string, mixed> $attributes
     */
    public function seedState(string $entityId, string $state, array $attributes = [], ?StateContext $context = null): EntityState
    {
        return $this->store($entityId, $state, $attributes, $context);
    }

    // ---------------------------------------------------------------- test API: driving

    /**
     * Changes an entity as if Home Assistant had reported it, and notifies the subscribers.
     *
     * @param array<string, mixed>|null $attributes null keeps the current attributes
     * @param StateContext|null         $context    pass a context with a user id to simulate a manual change;
     *                                              default is a fresh context unrelated to any service call
     */
    public function pushStateChange(string $entityId, string $state, ?array $attributes = null, ?StateContext $context = null): EntityStateChange
    {
        $old = $this->states[$entityId] ?? null;
        $new = $this->store($entityId, $state, $attributes ?? $old->attributes ?? [], $context);
        $change = new EntityStateChange($entityId, $old, $new);
        $this->notify($change);

        return $change;
    }

    /**
     * Removes an entity from Home Assistant and notifies the subscribers.
     */
    public function removeEntity(string $entityId): void
    {
        $old = $this->states[$entityId] ?? null;
        if (null === $old) {
            return;
        }
        unset($this->states[$entityId]);
        $this->notify(new EntityStateChange($entityId, $old, null));
    }

    /**
     * Makes the next service call fail with the given error (once), as Home Assistant rejecting it would.
     */
    public function failNextServiceCall(HomeAssistantException $failure): void
    {
        $this->nextServiceFailure = $failure;
    }

    public function simulateConnectionLoss(): void
    {
        if (!$this->connectionAvailable) {
            return;
        }
        $this->connectionAvailable = false;
        $this->runHooks($this->lostHooks);
    }

    public function simulateReconnect(): void
    {
        if ($this->connectionAvailable) {
            return;
        }
        $this->connectionAvailable = true;
        foreach ($this->subscriptions as $subscription) {
            foreach ($subscription->resync($this->states) as $change) {
                ($subscription->listener)($change);
            }
        }
        $this->runHooks($this->reconnectedHooks);
    }

    // ---------------------------------------------------------------- test API: inspecting

    /**
     * @return list<RecordedServiceCall>
     *
     * @phpstan-impure
     */
    public function serviceCalls(): array
    {
        ++$this->inspections;

        return $this->serviceCalls;
    }

    /**
     * @return list<RecordedServiceCall>
     *
     * @phpstan-impure
     */
    public function serviceCallsTo(string $domain, string $service): array
    {
        return array_values(array_filter(
            $this->serviceCalls,
            static fn (RecordedServiceCall $call): bool => $call->domain === $domain && $call->service === $service,
        ));
    }

    public function clearServiceCalls(): void
    {
        $this->serviceCalls = [];
    }

    /**
     * @phpstan-impure
     */
    public function state(string $entityId): ?EntityState
    {
        ++$this->inspections;

        return $this->states[$entityId] ?? null;
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->connectionAvailable;
    }

    public function subscriptionCount(): int
    {
        return \count($this->subscriptions);
    }

    // ---------------------------------------------------------------- port

    public function connect(): void
    {
        $this->assertAvailable();
        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->connected = false;
        $this->subscriptions = [];
    }

    public function getConfig(): HomeAssistantConfig
    {
        $this->assertReady();

        return $this->config;
    }

    public function getStates(): array
    {
        $this->assertReady();

        return array_values($this->states);
    }

    public function subscribeEntities(array $entityIds, \Closure $listener): SubscriptionInterface
    {
        $this->assertReady();

        $id = $this->nextSubscriptionId++;
        $subscription = new FakeSubscription($entityIds, $listener, function () use ($id): void {
            unset($this->subscriptions[$id]);
        });
        $this->subscriptions[$id] = $subscription;

        foreach ($subscription->resync($this->states) as $change) {
            $listener($change);
        }

        return $subscription;
    }

    public function callService(string $domain, string $service, array $serviceData = [], array $target = []): ServiceCallResult
    {
        $this->assertReady();
        if (null !== $this->nextServiceFailure) {
            $failure = $this->nextServiceFailure;
            $this->nextServiceFailure = null;

            throw $failure;
        }

        $context = new StateContext(\sprintf('fake-context-%04d', ++$this->contextCounter));
        $this->serviceCalls[] = new RecordedServiceCall($domain, $service, $serviceData, $target, $context);

        return new ServiceCallResult($context);
    }

    public function getServices(): array
    {
        $this->assertReady();

        return $this->services;
    }

    public function listAreas(): array
    {
        $this->assertReady();

        return $this->areas;
    }

    public function listDevices(): array
    {
        $this->assertReady();

        return $this->devices;
    }

    public function listEntityRegistry(): array
    {
        $this->assertReady();

        return $this->entityRegistry;
    }

    public function onConnectionLost(\Closure $hook): void
    {
        $this->lostHooks[] = $hook;
    }

    public function onReconnected(\Closure $hook): void
    {
        $this->reconnectedHooks[] = $hook;
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param array<string, mixed> $attributes
     */
    private function store(string $entityId, string $state, array $attributes, ?StateContext $context): EntityState
    {
        $now = null !== $this->now ? ($this->now)() : new \DateTimeImmutable();
        $previous = $this->states[$entityId] ?? null;
        $lastChanged = null !== $previous && $previous->state === $state ? $previous->lastChanged : $now;

        return $this->states[$entityId] = new EntityState(
            $entityId,
            $state,
            $attributes,
            $lastChanged,
            $now,
            $context ?? new StateContext(\sprintf('fake-context-%04d', ++$this->contextCounter)),
        );
    }

    private function notify(EntityStateChange $change): void
    {
        if (!$this->connectionAvailable) {
            return; // reported by simulateReconnect()
        }
        foreach ($this->subscriptions as $subscription) {
            if ($subscription->accepts($change->entityId)) {
                $subscription->remember($change);
                ($subscription->listener)($change);
            }
        }
    }

    /**
     * @param list<\Closure(): void> $hooks
     */
    private function runHooks(array $hooks): void
    {
        foreach ($hooks as $hook) {
            $hook();
        }
    }

    private function assertAvailable(): void
    {
        if (!$this->connectionAvailable) {
            throw new ConnectionLostException('Fake Home Assistant connection is down.');
        }
    }

    private function assertReady(): void
    {
        $this->assertAvailable();
        if (!$this->connected) {
            throw new ConnectionLostException('Fake Home Assistant is not connected.');
        }
    }
}
