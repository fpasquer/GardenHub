<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\BooleanFixtures;
use App\Tests\Support\PythonSubject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\EnvVarProcessor;

#[Group('unitest_gardenhub-compose-selection')]
final class ComposeSelectionTest extends TestCase
{
    public function testFullBooleanMatrixMatchesNativeSymfony(): void
    {
        $fixtures = BooleanFixtures::expanded();
        $environments = array_map(static fn (array $fixture): array|object =>
            null === $fixture[0] ? (object) [] : ['WATERING_ENABLED' => $fixture[0]],
            $fixtures,
        );
        $result = PythonSubject::invoke([
            'operation' => 'enabled', 'environments' => $environments,
        ]);
        self::assertNull($result['exception']);
        self::assertCount(count($fixtures), $result['value']);
        $processor = new EnvVarProcessor(new Container());
        foreach ($fixtures as $index => [$value, $expected]) {
            $label = json_encode($value, JSON_THROW_ON_ERROR);
            $native = $processor->getEnv(
                'bool', 'WATERING_ENABLED', static fn () => $value ?? 'false',
            );
            self::assertSame($expected, $native, $label);
            self::assertSame($native, $result['value'][$index], $label);
        }
    }
}