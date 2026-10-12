<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\GrafanaSubject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('functional_gardenhub-grafana-provisioning')]
#[Group('functional_gardenhub-grafana')]
final class GrafanaProvisioningTest extends TestCase
{
    public function testTelegramContactPointStoresChatIdAsString(): void
    {
        $response = (new GrafanaSubject())->http('/api/v1/provisioning/contact-points');
        $matching = array_values(array_filter($response,
            static fn (array $point): bool => 'GardenHub Telegram' === $point['name']));
        self::assertCount(1, $matching);
        self::assertIsString($matching[0]['settings']['chatid']);
    }
}