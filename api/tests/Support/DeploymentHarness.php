<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class DeploymentHarness
{
    private TemporaryWorkspace $workspace;

    public function __construct()
    {
        $this->workspace = new TemporaryWorkspace();
        foreach (['docker', 'curl'] as $name) {
            $this->workspace->write($name, sprintf(
                "#!/bin/sh\nexec python3 \"%s\" %s \"$@\"\n",
                __DIR__.'/fake_cli.py', $name,
            ), true);
        }
    }

    public function execute(string $target, array $model, array $overrides = []): array
    {
        $environment = array_replace(
            array_fill_keys(array_keys(getenv()), false),
            ComposeModel::synthetic(), [
                'PATH' => sprintf('%s:%s', $this->workspace->path, getenv('PATH')),
                'FAKE_MODEL' => $this->workspace->write('model.json',
                    json_encode($model, JSON_THROW_ON_ERROR)),
                'FAKE_LOG' => $this->workspace->write('commands.jsonl', ''),
                'FAKE_COUNTER' => $this->workspace->write('counter', '0'),
            ], $overrides,
        );
        $result = Command::run([
            'make', '--no-print-directory', $target,
            'COMPOSE=docker compose --env-file /dev/null',
            'DEPLOY_HEALTH_TIMEOUT=0',
        ], Command::root(), $environment);
        $result['commands'] = array_map(static fn (string $line): array =>
            json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($environment['FAKE_LOG'], FILE_IGNORE_NEW_LINES),
        );

        return $result;
    }
}