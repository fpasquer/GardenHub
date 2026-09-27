<?php

declare(strict_types=1);

use App\Kernel;
use App\Watering\ProposalPolicy;
use App\Watering\WateringManager;
use App\Watering\WateringPublisher;

require '/app/vendor/autoload.php';

final class ConcurrentFakePublisher implements WateringPublisher
{
    public function publish(array $command): void { usleep(250000); }
}

$kernel = new Kernel('dev', true);
$kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getManager()->getConnection();
if ($db->getDatabase() !== 'watering_control_test') { exit(2); }
$policy = new ProposalPolicy($db, new WateringManager($db, new ConcurrentFakePublisher(), true, 'dev'), 15, 35, 35, 30, 3);
echo $policy->decide($argv[1], 'approve');
$kernel->shutdown();
