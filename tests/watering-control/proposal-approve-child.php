<?php

declare(strict_types=1);

use App\Kernel;
use App\Watering\MqttWateringPublisher;
use App\Watering\InterfaceWateringPublisher;

require '/app/vendor/autoload.php';
require __DIR__.'/support.php';

final class ConcurrentFakePublisher implements InterfaceWateringPublisher
{
    public function publish(array $command): void { usleep(250000); }
}

$kernel = new Kernel('dev', true);
try {
    $em = bootTestEntityManager($kernel);
} catch (RuntimeException) {
    exit(2);
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
