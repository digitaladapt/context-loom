<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\ProbeResult;
use App\Service\HealthRegistry;
use App\Service\Registry\Registry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;

/**
 * contextloom:probe — run connectivity probes on demand.
 *
 * SPEC §6.3 + §11.1.7: Per-protocol connectivity probes.
 */
#[AsCommand(name: 'contextloom:probe', description: 'Probe backend connectivity')]
final class ProbeCommand extends Command
{
    public function __construct(
        private readonly Registry $registry,
        private readonly HealthRegistry $healthRegistry,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('domain', 'd', InputOption::VALUE_REQUIRED, 'Only probe entries in this domain.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->registry->load();
        $entries = $this->registry->getEntries();

        if ($input->getOption('domain')) {
            $domain = $input->getOption('domain');
            $entries = array_values(array_filter($entries, static fn ($e) => $e->domain === $domain));
        }

        if (empty($entries)) {
            $io->comment('No entries to probe.');

            return Command::SUCCESS;
        }

        $httpClient = new HttpClient([
            'timeout' => 3,
        ]);

        $results = [];

        $io->section('Connectivity Probes');

        foreach ($entries as $entry) {
            if (null === $entry->probe || ($entry->probe->level ?? 'connectivity') === 'none') {
                $io->text(\sprintf('  <comment>○</> %s <dim>(probe disabled)</dim>', $entry->name));
                $results[$entry->name] = new ProbeResult('unknown');
                $this->healthRegistry->add([
                    'name' => $entry->name,
                    'status' => 'unknown',
                ]);

                continue;
            }

            try {
                $url = $entry->resolveTemplate($entry->probe->url);
                $method = strtoupper($entry->probe->method);

                $response = $httpClient->request($method, $url, [
                    'timeout' => $entry->probe->timeout,
                ]);

                $statusCode = $response->getStatusCode();

                if ($statusCode >= 200 && $statusCode < 300) {
                    $status = 'ok';
                    $emoji = '<fg=green>✓</>';
                } else {
                    $status = 'degraded';
                    $emoji = '<fg=yellow>⚠</>';
                }

                $io->text(\sprintf('  %s %s <dim>(HTTP %d)</dim>', $emoji, $entry->name, $statusCode));

                $this->healthRegistry->add([
                    'name' => $entry->name,
                    'status' => $status,
                    'checkedAt' => new \DateTimeImmutable(),
                ]);
                $results[$entry->name] = new ProbeResult($status, new \DateTimeImmutable());
            } catch (\Throwable $e) {
                $io->text(\sprintf('  <fg=red>✗</> %s <dim>(%s)</dim>', $entry->name, $e->getMessage()));

                $this->healthRegistry->add([
                    'name' => $entry->name,
                    'status' => 'down',
                    'detail' => $e->getMessage(),
                    'checkedAt' => new \DateTimeImmutable(),
                ]);
                $results[$entry->name] = new ProbeResult('down', new \DateTimeImmutable(), $e->getMessage());
            }
        }

        $io->newLine();
        $io->success('Probes complete.');

        return Command::SUCCESS;
    }
}
