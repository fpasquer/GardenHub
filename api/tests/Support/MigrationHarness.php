<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class MigrationHarness
{
    public Connection $connection;
    public DependencyFactory $factory;
    private MigrationKernel $kernel;
    private TemporaryWorkspace $workspace;
    private Application $application;

    public function __construct()
    {
        $this->workspace = new TemporaryWorkspace();
        $this->kernel = new MigrationKernel($this->workspace->path);
        $this->reboot();
    }

    public function reboot(): void
    {
        $this->kernel->shutdown();
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $this->connection = $container->get('doctrine')->getManager()->getConnection();
        if ('1' !== getenv('GARDENHUB_LIFECYCLE_TESTS')
            || 'worker_lifecycle' !== $this->connection->getDatabase()) {
            throw new \RuntimeException('Refusing to modify a non-test database.');
        }
        $this->factory = $container->get('test.migrations_dependency_factory');
        $this->application = new Application($this->kernel);
        $this->application->setAutoExit(false);
    }

    public function reset(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $tables = $this->connection->fetchFirstColumn(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', ['worker_lifecycle']);
            foreach ($tables as $table) {
                $this->connection->executeStatement('DROP TABLE IF EXISTS '.$this->connection->quoteIdentifier($table));
            }
        } finally {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function step(string $version, bool $up = true, bool $success = true): array
    {
        return $this->run(['command' => 'doctrine:migrations:execute',
            'versions' => ['DoctrineMigrations\\'.$version],
            $up ? '--up' : '--down' => true, '--no-interaction' => true], $success);
    }

    public function migrate(?string $version = null): void
    {
        $input = ['command' => 'doctrine:migrations:migrate', '--no-interaction' => true];
        if (null !== $version) {
            $input['version'] = 'DoctrineMigrations\\'.$version;
        }
        $this->run($input);
    }

    public function shutdown(): void
    {
        $this->kernel->shutdown();
        unset($this->workspace);
    }

    private function run(array $input, bool $success = true): array
    {
        $output = new BufferedOutput();
        $status = $this->application->run(new ArrayInput($input), $output);
        $text = $output->fetch();
        if ($success) {
            Assert::assertSame(0, $status, $text);
        }

        return [$status, $text];
    }
}