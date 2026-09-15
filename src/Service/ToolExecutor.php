<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\RegistryEntry;
use App\Domain\ToolRun;
use App\Infrastructure\Executor\ToolExecutorInterface;
use Psr\Log\LoggerInterface;

/**
 * Dispatcher that selects the correct executor for a given entry type.
 *
 * SPEC §5: ToolExecutor is the application service — it doesn't know protocols,
 * just selects the right executor and returns a ToolRun stream.
 */
final class ToolExecutor
{
    /** @var list<ToolExecutorInterface> */
    private array $executors;

    public function __construct(
        iterable $executors,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->executors = [];
        foreach ($executors as $executor) {
            if ($executor instanceof ToolExecutorInterface) {
                $this->executors[] = $executor;
            }
        }
    }

    /**
     * Execute a registry entry.
     *
     * @return ToolRun The tool run stream.
     */
    public function execute(RegistryEntry $entry, array $arguments): ToolRun
    {
        foreach ($this->executors as $executor) {
            if ($executor->supports($entry->type)) {
                $this->logger?->info('Executing tool.', [
                    'entry' => $entry->name,
                    'type' => $entry->type,
                    'arguments' => $arguments,
                ]);

                return $executor->execute($entry, $arguments);
            }
        }

        throw new \RuntimeException(
            \sprintf('No executor found for tool type "%s" (entry "%s").', $entry->type, $entry->name)
        );
    }
}
