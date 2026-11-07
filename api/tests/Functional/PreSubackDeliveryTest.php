<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mqtt\WorkerMqttClientFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[Group('functional_gardenhub-mqtt-pre-suback')]
final class PreSubackDeliveryTest extends TestCase
{
    public function testPreSubackAndPostSubackDeliverOnceWithoutUnrelatedTopic(): void
    {
        $broker = new Process([PHP_BINARY, dirname(__DIR__).'/Support/pre_suback_broker.php']);
        $broker->setTimeout(25);
        $broker->start();
        try {
            self::assertTrue($broker->waitUntil(static fn (string $type, string $output): bool =>
                Process::OUT === $type && str_contains($output, "\n")));
            $ready = json_decode(trim($broker->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            $received = $this->receive((int) $ready['port']);
            foreach (['marker-a', 'marker-b', 'marker-d'] as $marker) {
                $deliveries = array_values(array_filter($received,
                    static fn (array $record): bool => $record[1] === $marker));
                self::assertSame([['tests/pre-suback/up', $marker]], $deliveries);
            }
            self::assertSame([], array_values(array_filter($received,
                static fn (array $record): bool => 'marker-c' === $record[1])));
            self::assertSame(0, $broker->wait(), substr($broker->getErrorOutput(), -65536));
        } finally {
            $broker->stop(0);
        }
    }

    private function receive(int $port): array
    {
        $received = [];
        $factory = new WorkerMqttClientFactory('127.0.0.1', $port, '', '');
        $client = $factory->create('tests-pre-suback', 'tests/pre-suback/up',
            static function (string $topic, string $message) use (&$received): void {
                $received[] = [$topic, $message];
            });
        try {
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline
                && !in_array('marker-d', array_column($received, 1), true)) {
                $client->loopOnce(microtime(true), false);
            }
        } finally {
            $client->disconnect();
        }

        return $received;
    }
}