<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * contextloom:serve — one process, one port: /mcp + /health (D18).
 *
 * Runs the Symfony HTTP kernel against PHP's built-in web server using
 * public/router.php. Built for dev; Phase 5 swaps this for FrankenPHP/Caddy
 * in a container.
 */
#[AsCommand(name: 'contextloom:serve', description: 'Run the context-loom HTTP server (dev; /mcp + /health)')]
final class ServeCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Bind host', '127.0.0.1')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Bind port', '8080');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $host = (string) $input->getOption('host');
        $port = (string) $input->getOption('port');

        $router = $this->projectDir.'/public/router.php';
        $command = \sprintf(
            'php -S %s:%s %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($router),
        );

        $io->info(\sprintf('Starting context-loom on http://%s:%s (Ctrl-C to stop)', $host, $port));
        $io->comment(\sprintf('MCP:      POST http://%s:%s/mcp', $host, $port));
        $io->comment(\sprintf('Health:   GET  http://%s:%s/health', $host, $port));

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!\is_resource($process)) {
            $io->error('Unable to start the built-in server.');

            return Command::FAILURE;
        }

        // Forward the server's output to the console and keep the command
        // alive until the child exits. Ctrl-C stops the child as well: both
        // share the terminal's process group.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $exitCode = Command::SUCCESS;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'] ?? 0;
                break;
            }

            foreach ([$pipes[1], $pipes[2]] as $pipe) {
                $chunk = fread($pipe, 4096);
                if (false !== $chunk && '' !== $chunk) {
                    $output->write($chunk, false, OutputInterface::OUTPUT_RAW);
                }
            }

            usleep(100_000);
        }

        // Drain anything the server wrote before exiting (e.g. an early
        // "port in use" failure) so nothing the child reports gets lost.
        foreach ([$pipes[1], $pipes[2]] as $pipe) {
            while (false !== ($chunk = fread($pipe, 8192)) && '' !== $chunk) {
                $output->write($chunk, false, OutputInterface::OUTPUT_RAW);
            }
        }

        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);

        return $exitCode;
    }
}
