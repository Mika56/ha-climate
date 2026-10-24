<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Engine\Lifecycle\ShutdownWaiterInterface;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\HomeAssistantException;
use App\Application\Port\HomeAssistant\HomeAssistantGatewayInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Amp\delay;

/**
 * Debug helper: prints the decoded changes of some entities, straight from the Home Assistant gateway.
 * Handy to check the real `subscribe_entities` format against a dev Home Assistant (standalone mode with
 * HA_URL and HA_TOKEN).
 */
#[AsCommand(name: 'app:debug:watch', description: 'Print the decoded state changes of the given entities')]
final class WatchEntitiesCommand extends Command
{
    public function __construct(
        private readonly HomeAssistantGatewayInterface $gateway,
        private readonly ShutdownWaiterInterface $shutdown,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('entity_ids', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Entity ids to watch (at least one)')
            ->addOption('duration', 'd', InputOption::VALUE_REQUIRED, 'Stop after this many seconds (default: until Ctrl+C)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'One JSON object per change');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $entityIds */
        $entityIds = $input->getArgument('entity_ids');
        $duration = $input->getOption('duration');
        $asJson = (bool) $input->getOption('json');

        try {
            $this->gateway->connect();
            $subscription = $this->gateway->subscribeEntities($entityIds, function (EntityStateChange $change) use ($output, $asJson): void {
                $output->writeln($asJson ? self::json($change) : self::line($change), OutputInterface::OUTPUT_RAW);
            });

            try {
                is_numeric($duration) ? delay((float) $duration) : $this->shutdown->waitForShutdown();
            } finally {
                $subscription->unsubscribe();
            }
        } catch (HomeAssistantException $e) {
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        } finally {
            $this->gateway->disconnect();
        }

        return Command::SUCCESS;
    }

    private static function line(EntityStateChange $change): string
    {
        if (null === $change->new) {
            return \sprintf('%s removed (was %s)', $change->entityId, $change->old->state ?? '?');
        }

        $changedAttributes = [];
        foreach ([...array_keys($change->new->attributes), ...array_keys($change->old->attributes ?? [])] as $key) {
            if (($change->old->attributes[$key] ?? null) !== ($change->new->attributes[$key] ?? null)) {
                $changedAttributes[$key] = true;
            }
        }

        return \sprintf(
            '%s %s -> %s [attributes changed: %s] context=%s user=%s',
            $change->entityId,
            $change->old->state ?? '(new)',
            $change->new->state,
            [] === $changedAttributes ? 'none' : implode(',', array_keys($changedAttributes)),
            $change->new->context->id,
            $change->new->context->userId ?? '-',
        );
    }

    private static function json(EntityStateChange $change): string
    {
        return json_encode([
            'entityId' => $change->entityId,
            'old' => null === $change->old ? null : self::stateArray($change->old),
            'new' => null === $change->new ? null : self::stateArray($change->new),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private static function stateArray(EntityState $state): array
    {
        return [
            'state' => $state->state,
            'attributes' => $state->attributes,
            'lastChanged' => $state->lastChanged->format('Y-m-d\TH:i:s.uP'),
            'lastUpdated' => $state->lastUpdated->format('Y-m-d\TH:i:s.uP'),
            'context' => ['id' => $state->context->id, 'parentId' => $state->context->parentId, 'userId' => $state->context->userId],
        ];
    }
}
