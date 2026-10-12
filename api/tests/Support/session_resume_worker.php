<?php

declare(strict_types=1);

use App\Command\MqttConsumeCommand;
use App\Tests\Support\AsyncMqttKernel;
use App\Tests\Support\TemporaryWorkspace;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$workspace = new TemporaryWorkspace();
$kernel = new AsyncMqttKernel($workspace->path);
$kernel->boot();
$container = $kernel->getContainer();
$manager = $container->get('doctrine')->getManager();
if ('1' !== getenv('GARDENHUB_LIFECYCLE_TESTS')
    || 'worker_lifecycle' !== $manager->getConnection()->getDatabase()) {
    throw new RuntimeException('Refusing to use a non-test database.');
}
$command = new MqttConsumeCommand($container->get('test.messenger.default_bus'),
    new NullLogger(), 'mqtt', 1883, '', '', 'tests-session-resume',
    'tests/session-resume/up', $container->get('test.services_resetter'));
exit($command->run(new ArrayInput([]), new NullOutput()));