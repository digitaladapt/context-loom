<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use App\Domain\RegistryEntry;
use App\Domain\ToolRun;

interface ToolExecutorInterface
{
    /**
     * Does this executor support the given tool type?
     */
    public function supports(string $type): bool;

    /**
     * Execute the given entry with the provided arguments.
     *
     * @return ToolRun the stream of output chunks
     */
    public function execute(RegistryEntry $entry, array $arguments): ToolRun;
}
