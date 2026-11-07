<?php

declare(strict_types=1);

use App\Kernel;
use App\Watering\MqttWateringPublisher;
use App\Watering\InterfaceWateringPublisher;
use Symfony\Component\Clock\MockClock;

require '/app/vendor/autoload.php';
require __DIR__.'/support.php';

final class ConcurrentFakePublisher implements InterfaceWateringPublisher
{
    public function __construct(private readonly ?string $path = null)
    {
    }

    public function publish(array $command): void
    {
        if (null !== $this->path) {
            file_put_contents($this->path,
                json_encode($command, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        }
        usleep(250000);
    }
}

$kernel = new Kernel('dev', true);
try {
    $em = bootTestEntityManager($kernel);
} catch (RuntimeException) {
    exit(2);
}
if ('--advance' === ($argv[1] ?? null)) {
    $clock = isset($argv[3]) ? new MockClock($argv[3]) : null;
    $manager = newTestManager(
        $em, new ConcurrentFakePublisher($argv[2]), clock: $clock,
    );
    $manager->heartbeat();
    $manager->advanceCycle();
    $kernel->shutdown();
    exit(0);
}
$policy = newTestPolicy($em, newTestManager($em, new ConcurrentFakePublisher()), MqttWateringPublisher::DEFAULT_TOPIC);
if ('--notify' === ($argv[1] ?? null)) {
    $gateway = new \App\Tests\Support\RecordingTelegramGateway();
    file_put_contents($argv[2], json_encode([
        'connectionId' => (int) $em->getConnection()->fetchOne('SELECT CONNECTION_ID()'),
    ], JSON_THROW_ON_ERROR));
    newTestBot($em, $policy, $gateway)->evaluateAndNotify();
    echo json_encode($gateway->sent, JSON_THROW_ON_ERROR);
    $kernel->shutdown();
    exit(0);
}
$em->getConnection()->executeStatement('UPDATE watering_control SET monitor_seen_at = UTC_TIMESTAMP() WHERE id = 1');
echo $policy->decide((int) $argv[1], 'approve');
$kernel->shutdown();
