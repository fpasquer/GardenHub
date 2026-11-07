<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once Command::root().'/tests/watering-control/support.php';

final class WateringHarness
{
    public readonly SchedulerKernel $kernel;
    public readonly EntityManagerInterface $manager;
    public readonly Connection $connection;
    private TemporaryWorkspace $workspace;

    public function __construct()
    {
        $this->workspace = new TemporaryWorkspace();
        $this->kernel = new SchedulerKernel($this->workspace->path);
        $this->manager = \bootTestEntityManager($this->kernel);
        $this->connection = $this->manager->getConnection();
        if ('1' !== getenv('GARDENHUB_LIFECYCLE_TESTS')) {
            throw new \RuntimeException('Refusing to modify schema outside isolated tests.');
        }
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $status = $application->run(new ArrayInput([
            'command' => 'doctrine:migrations:migrate', '--no-interaction' => true,
        ]), $output);
        Assert::assertSame(0, $status, $output->fetch());
        $this->connection->close();
        $this->connection->executeStatement('DELETE FROM watering_proposal');
        \clearWateringRuns($this->connection);
        $this->connection->executeStatement('DELETE FROM watering_proposal_state');
        $this->connection->executeStatement('DELETE FROM measurement');
        $this->connection->executeStatement('DELETE FROM sensor');
        $this->connection->executeStatement('DELETE FROM device');
        $this->manager->clear();
    }

    public function shutdown(): void
    {
        $this->kernel->shutdown();
        unset($this->workspace);
    }
}