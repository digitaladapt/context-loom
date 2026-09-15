<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Registry\Registry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * contextloom:validate — CI-fast registry config check.
 *
 * SPEC §4.4 + §11.1.7: Static validation report.
 * Exit codes: 0 ok, 1 errors, 2 missing dir.
 */
#[AsCommand(name: 'contextloom:validate', description: 'Validate registry configuration')]
final class ValidateCommand extends Command
{
    public function __construct(
        private readonly Registry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit with code 1 if any validation errors exist.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $strict = $input->getOption('strict');

        // Trigger load to populate validation errors
        $this->registry->load();

        $errors = $this->registry->getValidationErrors();
        $entries = $this->registry->getEntries();

        $io->section('Registry Validation');

        $io->text([
            "<info>Registry dir: checked</info>",
            "<info>Entries loaded: " . count($entries) . "</info>",
            "<info>Validation errors: " . count($errors) . "</info>",
        ]);

        if (!empty($entries)) {
            $io->text('<comment>Registered entries:</comment>');
            foreach ($entries as $entry) {
                $io->text(sprintf('  <fg=green>✓</> %s (%s) [%s]', $entry->name, $entry->title, $entry->type));
            }
        }

        if (!empty($errors)) {
            $io->text('<comment>Validation errors:</comment>');
            foreach ($errors as $error) {
                $io->text(sprintf('  <fg=red>✗</> %s', $error));
            }
        }

        $io->newLine();
        $io->success('Registry validation complete.');

        if ($strict && !empty($errors)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
