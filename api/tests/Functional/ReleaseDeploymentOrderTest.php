<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\Command;
use App\Tests\Support\TemporaryWorkspace;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('functional_gardenhub-release-deployment-order')]
final class ReleaseDeploymentOrderTest extends TestCase
{
    public function testCiRefusalPrecedesBuildStopAndMigrations(): void
    {
        $workspace = new TemporaryWorkspace();
        $workspace->write('.git/placeholder', '');
        $workspace->write('.env', '');
        $workspace->write('Makefile', file_get_contents(Command::root().'/Makefile'));
        $workspace->write('scripts/check-release-ci.py', "raise SystemExit(1)\n");
        $this->writeFakeTools($workspace);
        $log = $workspace->write('commands', '');
        $result = Command::run(['make', '--no-print-directory', 'deploy'],
            $workspace->path, [
                'PATH' => sprintf('%s/tools:%s', $workspace->path, getenv('PATH')),
                'COMMAND_LOG' => $log,
            ]);
        self::assertNotSame(0, $result['status']);
        $commands = file($log, FILE_IGNORE_NEW_LINES);
        self::assertNotEmpty($commands);
        foreach ($commands as $command) {
            self::assertStringNotContainsString(' build ', sprintf(' %s ', $command));
            self::assertStringNotContainsString(' stop ', sprintf(' %s ', $command));
            self::assertStringNotContainsString('doctrine:migrations:migrate', $command);
        }
    }

    private function writeFakeTools(TemporaryWorkspace $workspace): void
    {
        $workspace->write('tools/git', <<<'SH'
#!/bin/sh
case "$*" in
  *--git-path*) echo .git/gardenhub-deploy.lock;;
  *--abbrev-ref*) echo master;;
  *rev-parse*) printf "%040d\n" 0;;
esac
SH, true);
        $workspace->write('tools/docker', <<<'SH'
#!/bin/sh
printf "%s\n" "$*" >> "$COMMAND_LOG"
SH, true);
    }
}