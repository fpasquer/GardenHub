<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Command\TelegramDailySummaryCommand;
use App\Entity\Device;
use App\Entity\Measurement;
use App\Entity\Sensor;
use App\Kernel;
use App\Repository\MeasurementRepository;
use App\Tests\Monolog\FakeTelegramTransport;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

$failures = 0;

function check(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        echo "  PASS  $label\n";
    } else {
        echo "  FAIL  $label\n";
        ++$failures;
    }
}

function bootEntityManager(): EntityManagerInterface
{
    $kernel = new Kernel('test', true);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    /** @var EntityManagerInterface $entityManager */
    $entityManager = $container->get('doctrine')->getManager();

    $schemaTool = new SchemaTool($entityManager);
    $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
    $schemaTool->updateSchema($metadata);

    return $entityManager;
}

function resetTables(EntityManagerInterface $entityManager): void
{
    $connection = $entityManager->getConnection();
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
    foreach (['measurement', 'sensor', 'device'] as $table) {
        $connection->executeStatement("TRUNCATE TABLE {$table}");
    }
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    $entityManager->clear();
}

function makeDevice(EntityManagerInterface $entityManager, string $name): Device
{
    $device = (new Device())->setName($name);
    $entityManager->persist($device);

    return $device;
}

function makeSensor(EntityManagerInterface $entityManager, Device $device, string $type, string $unit, ?string $label = null): Sensor
{
    $sensor = (new Sensor())->setDevice($device)->setType($type)->setUnit($unit)->setLabel($label);
    $entityManager->persist($sensor);

    return $sensor;
}

function makeMeasurement(EntityManagerInterface $entityManager, Sensor $sensor, float $value, \DateTimeImmutable $measuredAt): void
{
    $measurement = (new Measurement())->setSensor($sensor)->setValue($value)->setMeasuredAt($measuredAt);
    $entityManager->persist($measurement);
}

function makeCommand(MeasurementRepository $repository, FakeTelegramTransport $transport, MockClock $clock, bool $enabled = true): TelegramDailySummaryCommand
{
    return new TelegramDailySummaryCommand($repository, $transport, $clock, $enabled, 'test-token', 'test-chat');
}

function runCommand(TelegramDailySummaryCommand $command, int $hours = 24): CommandTester
{
    $tester = new CommandTester($command);
    $tester->execute(['hours' => $hours]);

    return $tester;
}

function test_min_max_for_one_sensor(EntityManagerInterface $entityManager): void
{
    echo "Scenario: min/max for one sensor\n";
    resetTables($entityManager);
    $now = new \DateTimeImmutable('2026-09-21 12:00:00');
    $device = makeDevice($entityManager, 'SE01-Avocado');
    $sensor = makeSensor($entityManager, $device, 'soil_moisture', '%', 'Moisture');
    makeMeasurement($entityManager, $sensor, 31.2, $now->modify('-20 hours'));
    makeMeasurement($entityManager, $sensor, 25.0, $now->modify('-10 hours'));
    makeMeasurement($entityManager, $sensor, 28.7, $now->modify('-1 hours'));
    $entityManager->flush();

    $repository = $entityManager->getRepository(Measurement::class);
    $transport = new FakeTelegramTransport();
    $tester = runCommand(makeCommand($repository, $transport, new MockClock($now)));

    check(0 === $tester->getStatusCode(), 'command exits successfully');
    $text = $transport->calls[0]['text'] ?? '';
    check(str_contains($text, '3 measurements'), 'header reports the row count for the single sensor');
    check(str_contains($text, '25') && str_contains($text, '31.2'), 'row shows the min and max value, not first/latest');
}

function test_grouping_by_device_and_sensor(EntityManagerInterface $entityManager): void
{
    echo "Scenario: grouping across sensors and devices\n";
    resetTables($entityManager);
    $now = new \DateTimeImmutable('2026-09-21 12:00:00');
    $deviceA = makeDevice($entityManager, 'SE01-Avocado');
    $moisture = makeSensor($entityManager, $deviceA, 'soil_moisture', '%', 'Moisture');
    $temperature = makeSensor($entityManager, $deviceA, 'soil_temperature', '°C', 'Temperature');
    $deviceB = makeDevice($entityManager, 'SE02-Tomato');
    $conductivity = makeSensor($entityManager, $deviceB, 'soil_conductivity', 'µS/cm', 'Conductivity');
    makeMeasurement($entityManager, $moisture, 30.0, $now->modify('-5 hours'));
    makeMeasurement($entityManager, $temperature, 22.9, $now->modify('-5 hours'));
    makeMeasurement($entityManager, $conductivity, 410.0, $now->modify('-5 hours'));
    $entityManager->flush();

    $repository = $entityManager->getRepository(Measurement::class);
    $transport = new FakeTelegramTransport();
    runCommand(makeCommand($repository, $transport, new MockClock($now)));

    $text = $transport->calls[0]['text'] ?? '';
    check(str_contains($text, 'SE01-Avocado'), 'message contains the first device section');
    check(str_contains($text, 'SE02-Tomato'), 'message contains the second device section');
    check(str_contains($text, 'Moisture') && str_contains($text, 'Temperature') && str_contains($text, 'Conductivity'), 'message lists every sensor');
}

function test_window_boundaries_are_half_open(EntityManagerInterface $entityManager): void
{
    echo "Scenario: half-open window boundaries\n";
    resetTables($entityManager);
    $now = new \DateTimeImmutable('2026-09-21 12:00:00');
    $device = makeDevice($entityManager, 'SE01-Avocado');
    $sensor = makeSensor($entityManager, $device, 'soil_moisture', '%', 'Moisture');
    makeMeasurement($entityManager, $sensor, 999.0, $now->modify('-24 hours -1 second')); // just before since: excluded
    makeMeasurement($entityManager, $sensor, 10.0, $now->modify('-24 hours')); // exactly since: included
    makeMeasurement($entityManager, $sensor, 20.0, $now->modify('-1 hours')); // inside window: included
    makeMeasurement($entityManager, $sensor, 999.0, $now); // exactly until: excluded
    $entityManager->flush();

    $repository = $entityManager->getRepository(Measurement::class);
    $transport = new FakeTelegramTransport();
    runCommand(makeCommand($repository, $transport, new MockClock($now)));

    $text = $transport->calls[0]['text'] ?? '';
    check(str_contains($text, '2 measurements'), 'only the two in-window rows are counted');
    check(!str_contains($text, '999'), 'the out-of-window sentinel value never appears');
}

function test_zero_measurements(EntityManagerInterface $entityManager): void
{
    echo "Scenario: zero measurements\n";
    resetTables($entityManager);
    $entityManager->flush();

    $repository = $entityManager->getRepository(Measurement::class);
    $transport = new FakeTelegramTransport();
    runCommand(makeCommand($repository, $transport, new MockClock(new \DateTimeImmutable())));

    $text = $transport->calls[0]['text'] ?? '';
    check('🌱 24h · 0 measurements' === $text, 'the zero-measurement message has no <pre> block');
}

function test_html_escaping_after_padding(EntityManagerInterface $entityManager): void
{
    echo "Scenario: HTML escaping happens after padding\n";
    resetTables($entityManager);
    $now = new \DateTimeImmutable('2026-09-21 12:00:00');
    $device = makeDevice($entityManager, 'SE03 <script>');
    $sensor = makeSensor($entityManager, $device, 'soil_moisture', '%', 'A & B');
    makeMeasurement($entityManager, $sensor, 1.0, $now->modify('-1 hours'));
    $entityManager->flush();

    $repository = $entityManager->getRepository(Measurement::class);
    $transport = new FakeTelegramTransport();
    runCommand(makeCommand($repository, $transport, new MockClock($now)));

    $text = $transport->calls[0]['text'] ?? '';
    check(str_contains($text, '&lt;script&gt;'), 'dangerous device name is escaped');
    check(str_contains($text, 'A &amp; B'), 'sensor label ampersand is escaped');
    check(!str_contains($text, '<script>'), 'raw script tag never reaches the message');
    check('HTML' === ($transport->calls[0]['parseMode'] ?? null), 'message is sent with parse_mode=HTML');
}

function test_delivery_failure_fails_the_command(EntityManagerInterface $entityManager): void
{
    echo "Scenario: Telegram delivery failure\n";
    resetTables($entityManager);
    $now = new \DateTimeImmutable('2026-09-21 12:00:00');
    $device = makeDevice($entityManager, 'SE01-Avocado');
    $sensor = makeSensor($entityManager, $device, 'soil_moisture', '%', 'Moisture');
    makeMeasurement($entityManager, $sensor, 30.0, $now->modify('-1 hours'));
    $entityManager->flush();

    $repository = $entityManager->getRepository(Measurement::class);
    $transport = new FakeTelegramTransport();
    $transport->armToThrow();
    $tester = runCommand(makeCommand($repository, $transport, new MockClock($now)));

    check(Symfony\Component\Console\Command\Command::FAILURE === $tester->getStatusCode(), 'command exits unsuccessfully when Telegram delivery fails');
}

function test_disabled_never_sends(EntityManagerInterface $entityManager): void
{
    echo "Scenario: disabled\n";
    resetTables($entityManager);
    $entityManager->flush();

    $repository = $entityManager->getRepository(Measurement::class);
    $transport = new FakeTelegramTransport();
    $tester = runCommand(makeCommand($repository, $transport, new MockClock(new \DateTimeImmutable()), false));

    check(0 === $tester->getStatusCode(), 'command still exits successfully when disabled');
    check([] === $transport->calls, 'transport is never invoked when TELEGRAM_ENABLED=false');
}

function test_hours_argument_controls_the_window(EntityManagerInterface $entityManager): void
{
    echo "Scenario: hours argument controls the window\n";
    resetTables($entityManager);
    $now = new \DateTimeImmutable('2026-09-21 12:00:00');
    $device = makeDevice($entityManager, 'SE01-Avocado');
    $sensor = makeSensor($entityManager, $device, 'soil_moisture', '%', 'Moisture');
    makeMeasurement($entityManager, $sensor, 30.0, $now->modify('-2 hours'));
    makeMeasurement($entityManager, $sensor, 40.0, $now->modify('-10 hours'));
    $entityManager->flush();

    $repository = $entityManager->getRepository(Measurement::class);
    $transport = new FakeTelegramTransport();
    runCommand(makeCommand($repository, $transport, new MockClock($now)), 3);

    $text = $transport->calls[0]['text'] ?? '';
    check(str_contains($text, '3h · 1 measurements'), 'a 3-hour window only counts the row within the last 3 hours');
}

function test_hours_argument_is_required(EntityManagerInterface $entityManager): void
{
    echo "Scenario: hours argument is required\n";
    resetTables($entityManager);
    $entityManager->flush();

    $repository = $entityManager->getRepository(Measurement::class);
    $transport = new FakeTelegramTransport();
    $command = makeCommand($repository, $transport, new MockClock(new \DateTimeImmutable()));

    try {
        (new CommandTester($command))->execute([]);
        check(false, 'running without the "hours" argument throws');
    } catch (\RuntimeException) {
        check(true, 'running without the "hours" argument throws');
    }
}

$entityManager = bootEntityManager();

test_min_max_for_one_sensor($entityManager);
test_grouping_by_device_and_sensor($entityManager);
test_window_boundaries_are_half_open($entityManager);
test_zero_measurements($entityManager);
test_html_escaping_after_padding($entityManager);
test_delivery_failure_fails_the_command($entityManager);
test_disabled_never_sends($entityManager);
test_hours_argument_controls_the_window($entityManager);
test_hours_argument_is_required($entityManager);

echo "\n";
if ($failures > 0) {
    echo "{$failures} assertion(s) FAILED.\n";
    exit(1);
}

echo "All assertions passed.\n";
exit(0);
