<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\ServeCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Guards `contextloom:serve`'s output forwarding.
 *
 * The command crashed the first time the dev server wrote a line of output:
 * it called SymfonyStyle::getOutput(), which does not exist. A fake `php`
 * binary on PATH stands in for the built-in server — it writes a banner,
 * lingers briefly (so output must be forwarded while the child is still
 * running, the exact crash path), then exits non-zero.
 */
final class ServeCommandTest extends TestCase
{
    public function test_forwards_child_output_and_propagates_exit_code(): void
    {
        $shimDir = sys_get_temp_dir().'/contextloom-shim-'.bin2hex(random_bytes(4));
        mkdir($shimDir);
        $shim = $shimDir.'/php';
        file_put_contents($shim, <<<'SH'
#!/bin/sh
echo 'fake-serve: stdout line'
echo 'fake-serve: stderr banner' >&2
sleep 1
exit 1
SH);
        chmod($shim, 0o755);

        $previousPath = getenv('PATH');

        try {
            putenv('PATH='.$shimDir.\PATH_SEPARATOR.(false !== $previousPath ? $previousPath : ''));

            // Safety net: if the shim is not what `php` resolves to, the real
            // built-in server would start (and block) — skip instead of hang.
            if ($shim !== trim((string) shell_exec('command -v php 2>/dev/null'))) {
                self::markTestSkipped('PATH shim for php is not effective in this environment.');
            }

            $tester = new CommandTester(new ServeCommand(sys_get_temp_dir()));
            $exitCode = $tester->execute([]);

            $display = $tester->getDisplay();
            self::assertStringContainsString('fake-serve: stdout line', $display, 'child stdout must be forwarded');
            self::assertStringContainsString('fake-serve: stderr banner', $display, 'child stderr must be forwarded');
            self::assertSame(1, $exitCode, 'the child exit code must be propagated');
        } finally {
            if (false !== $previousPath) {
                putenv('PATH='.$previousPath);
            }
            @unlink($shim);
            @rmdir($shimDir);
        }
    }
}
