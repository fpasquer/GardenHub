<?php

declare(strict_types=1);

namespace Tests\MqttLifecycle;

/**
 * Test-only ordered event log shared by CollisionMiddleware (outside DI reach,
 * instantiated deep inside the DBAL driver) and ScenarioSubscriber (a normal
 * DI service), so a single scenario can assert the exact sequence of events
 * rather than only the final database state.
 */
final class ScenarioRecorder
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function record(string $name, array $context = []): void
    {
        self::$events[] = [$name, $context];
    }

    /**
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    public static function all(): array
    {
        return self::$events;
    }

    /**
     * Position of the first occurrence of $name, for asserting relative order.
     */
    public static function indexOf(string $name): int
    {
        foreach (self::$events as $index => [$eventName]) {
            if ($eventName === $name) {
                return $index;
            }
        }

        throw new \RuntimeException(\sprintf('Expected scenario event "%s" was never recorded. Recorded: %s', $name, implode(', ', array_column(self::$events, 0))));
    }
}
