<?php

declare(strict_types=1);

use App\Kernel;
use App\Watering\MqttWateringPublisher;
use App\Watering\WateringPublisher;

require '/app/vendor/autoload.php';
require __DIR__.'/support.php';

final class ConcurrentFakePublisher implements WateringPublisher
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
echo $policy->decide((int) $argv[1], 'approve');
$kernel->shutdown();
