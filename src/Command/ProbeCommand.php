<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * contextloom:probe — functional probe (Phase 0 stub).
 * Phase 4 fills this with per-protocol connectivity probes.
 */
#[AsCommand(name: 'contextloom:probe', description: 'Probe backend connectivity (stub)')]
final class ProbeCommand extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->comment('No providers to probe yet (Phase 0 skeleton).');

        return Command::SUCCESS;
    }
}
