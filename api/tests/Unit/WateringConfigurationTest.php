<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Watering\MqttWateringPublisher;
use App\Watering\WateringConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unitest_gardenhub-watering-configuration')]
final class WateringConfigurationTest extends TestCase
{
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationRefusesAvailabilityAndPublication(array $invalid): void
    {
        $values = array_replace(['enabled' => true, 'environment' => 'prod',
            'host' => 'never-connect.invalid', 'port' => 1883, 'username' => 'test',
            'password' => 'test', 'topic' => 'isolated/pump'], $invalid);
        $configuration = new WateringConfiguration(...$values);
        $this->refuses(fn () => $configuration->assertAvailable());
        $this->refuses(fn () => (new MqttWateringPublisher($configuration))->publish(['state' => 'ON']));
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            self::fail('Unsafe production configuration accepted.');
        } catch (\LogicException|\RuntimeException $exception) {
            self::assertInstanceOf(\Throwable::class, $exception);
        }
    }

    public static function invalidConfigurations(): iterable
    {
        yield 'empty host' => [['host' => '']];
        yield 'zero port' => [['port' => 0]];
        yield 'empty username' => [['username' => '']];
        yield 'blank password' => [['password' => ' ']];
        yield 'empty topic' => [['topic' => '']];
        yield 'multi-level wildcard' => [['topic' => 'pump/#']];
        yield 'single-level wildcard' => [['topic' => 'pump/+']];
        yield 'command topic' => [['topic' => 'pump/set']];
        yield 'disabled' => [['enabled' => false]];
        yield 'test environment' => [['environment' => 'test']];
    }
}