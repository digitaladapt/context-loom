<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * contextloom:validate — CI-fast registry config check (Phase 0 stub).
 * Phase 1 fills this with real YAML loading + static validation.
 */
#[AsCommand(name: 'contextloom:validate', description: 'Validate registry configuration (stub)')]
final class ValidateCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->success('No registry entries to validate yet (Phase 0 skeleton).');

        return Command::SUCCESS;
    }
}
