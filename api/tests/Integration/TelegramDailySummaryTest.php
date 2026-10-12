<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\TelegramDailySummaryCommand;
use App\Entity\Device;
use App\Entity\Measurement;
use App\Entity\Sensor;
use App\Tests\Monolog\FakeTelegramTransport;
use App\Tests\Support\GuardedSchema;
use App\Tests\Support\SchedulerKernel;
use App\Tests\Support\TemporaryWorkspace;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

#[Group('functional_gardenhub-telegram-summary')]
final class TelegramDailySummaryTest extends TestCase
{
    private SchedulerKernel $kernel;
    private TemporaryWorkspace $workspace;
    private EntityManagerInterface $manager;
    private FakeTelegramTransport $transport;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->workspace = new TemporaryWorkspace();
        $this->kernel = new SchedulerKernel($this->workspace->path);
        $this->kernel->boot();
        $this->manager = $this->kernel->getContainer()->get('doctrine')->getManager();
        GuardedSchema::create($this->manager, 'telegram_summary');
        GuardedSchema::resetMeasurements($this->manager, 'telegram_summary');
        $this->transport = new FakeTelegramTransport();
        $this->now = new \DateTimeImmutable('2026-09-21 12:00:00', new \DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        unset($this->workspace);
    }

    public function testMinMaxRatherThanFirstAndLatest(): void
    {
        $sensor = $this->sensor('SE01-Avocado', 'soil_moisture', '%', 'Moisture');
        $this->measure($sensor, 31.2, '-20 hours');
        $this->measure($sensor, 25.0, '-10 hours');
        $this->measure($sensor, 28.7, '-1 hours');
        self::assertSame(Command::SUCCESS, $this->execute()->getStatusCode());
        $this->contains(['3 events', '25', '31.2']);
    }

    public function testGroupingIncludesEveryDeviceAndSensor(): void
    {
        $moisture = $this->sensor('SE01-Avocado', 'soil_moisture', '%', 'Moisture');
        $temperature = $this->sensor('SE01-Avocado', 'soil_temperature', "\u{00B0}C", 'Temperature');
        $conductivity = $this->sensor('SE02-Tomato', 'soil_conductivity', "\u{00B5}S/cm", 'Conductivity');
        $this->measure($moisture, 30.0, '-5 hours');
        $this->measure($temperature, 22.9, '-5 hours');
        $this->measure($conductivity, 410.0, '-5 hours');
        $this->execute();
        $this->contains(['SE01-Avocado', 'SE02-Tomato', 'Moisture', 'Temperature', 'Conductivity']);
    }

    public function testTwoUplinksCountAsTwoEventsNotFourMeasurements(): void
    {
        $moisture = $this->sensor('SE01-Avocado', 'soil_moisture', '%', 'Moisture');
        $temperature = $this->sensor('SE01-Avocado', 'soil_temperature', "\u{00B0}C", 'Temperature');
        $uplinkA = (string) Uuid::v4();
        $uplinkB = (string) Uuid::v4();
        $this->measure($moisture, 25.0, '-10 hours', $uplinkA);
        $this->measure($temperature, 22.9, '-10 hours', $uplinkA);
        $this->measure($moisture, 31.2, '-1 hours', $uplinkB);
        $this->measure($temperature, 23.4, '-1 hours', $uplinkB);
        $this->execute();
        $this->contains(['2 events', '25', '31.2', '22.9', '23.4']);
    }

    public function testWindowIsHalfOpen(): void
    {
        $sensor = $this->sensor('SE01-Avocado', 'soil_moisture', '%', 'Moisture');
        $this->measure($sensor, 999.0, '-24 hours -1 second');
        $this->measure($sensor, 10.0, '-24 hours');
        $this->measure($sensor, 20.0, '-1 hours');
        $this->measure($sensor, 999.0, '+0 seconds');
        $this->execute();
        $this->contains(['2 events']);
        self::assertStringNotContainsString('999', $this->text());
    }

    public function testEmptyWindowHasNoTable(): void
    {
        $this->execute();
        self::assertSame("\u{1F331} 24h \u{00B7} 0 events", $this->text());
    }

    public function testHtmlIsEscapedAfterPadding(): void
    {
        $sensor = $this->sensor('SE03 <script>', 'soil_moisture', '%', 'A & B');
        $this->measure($sensor, 1.0, '-1 hours');
        $this->execute();
        $this->contains(['&lt;script&gt;', 'A &amp; B']);
        self::assertStringNotContainsString('<script>', $this->text());
        self::assertSame('HTML', $this->transport->calls[0]['parseMode']);
    }

    public function testDeliveryFailureFailsTheCommand(): void
    {
        $sensor = $this->sensor('SE01-Avocado', 'soil_moisture', '%', 'Moisture');
        $this->measure($sensor, 30.0, '-1 hours');
        $this->transport->armToThrow();
        self::assertSame(Command::FAILURE, $this->execute()->getStatusCode());
    }

    public function testDisabledTelegramNeverSends(): void
    {
        self::assertSame(Command::SUCCESS, $this->execute([], false)->getStatusCode());
        self::assertSame([], $this->transport->calls);
    }

    public function testHoursControlsTheWindow(): void
    {
        $sensor = $this->sensor('SE01-Avocado', 'soil_moisture', '%', 'Moisture');
        $this->measure($sensor, 30.0, '-2 hours');
        $this->measure($sensor, 40.0, '-10 hours');
        $this->execute(['--hours' => 3]);
        $this->contains(["3h \u{00B7} 1 events"]);
    }

    public function testOmittedHoursDefaultsTo24(): void
    {
        $sensor = $this->sensor('SE01-Avocado', 'soil_moisture', '%', 'Moisture');
        $this->measure($sensor, 30.0, '-23 hours');
        $this->execute();
        $this->contains(["24h \u{00B7} 1 events"]);
    }

    #[DataProvider('invalidHours')]
    public function testInvalidHoursNeverReachTransport(string $hours): void
    {
        self::assertSame(Command::INVALID,
            $this->execute(['--hours' => $hours])->getStatusCode());
        self::assertSame([], $this->transport->calls);
    }

    public static function invalidHours(): iterable
    {
        foreach (['0', '-5', 'abc', '12abc', '1.5', '99999999999999999999'] as $hours) {
            yield $hours => [$hours];
        }
    }

    private function sensor(string $name, string $type, string $unit, string $label): Sensor
    {
        $device = $this->manager->getRepository(Device::class)->findOneBy(['name' => $name]);
        if (null === $device) {
            $device = (new Device())->setName($name);
            $this->manager->persist($device);
            $this->manager->flush();
        }
        $sensor = (new Sensor())->setDevice($device)->setType($type)
            ->setUnit($unit)->setLabel($label);
        $this->manager->persist($sensor);

        return $sensor;
    }

    private function measure(Sensor $sensor, float $value, string $offset, ?string $id = null): void
    {
        $this->manager->persist((new Measurement())->setSensor($sensor)
            ->setValue($value)->setMeasuredAt($this->now->modify($offset))
            ->setDeduplicationId($id ?? (string) Uuid::v4()));
    }

    private function execute(array $options = [], bool $enabled = true): CommandTester
    {
        $this->manager->flush();
        $command = new TelegramDailySummaryCommand(
            $this->manager->getRepository(Measurement::class), $this->transport,
            new MockClock($this->now), $enabled, 'test-token', 'test-chat',
        );
        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }

    private function contains(array $values): void
    {
        foreach ($values as $value) {
            self::assertStringContainsString($value, $this->text());
        }
    }

    private function text(): string
    {
        return $this->transport->calls[0]['text'] ?? '';
    }
}