<?php

declare(strict_types=1);

/*
 * Per-uplink invalid-reading aggregation (finding 3) and durable uplink
 * replay protection (alert_processed_uplink), driven through the real
 * ChirpStackUplinkHandler against a real MySQL schema.
 *
 * Covered behaviors:
 *  - mixed valid/invalid uplink: valid measurement persists, ONE breach
 *  - field order does not matter (invalid first vs. valid first)
 *  - consecutive valid uplinks advance recovery at most once per uplink
 *  - duplicate delivery: no extra recovery, no extra measurements
 *  - empty and unmapped-only payloads: no signal at all
 *  - non-numeric mapped field: breach, and no recovery credit
 *  - replay A after same-measuredAt B: A is skipped (identity-based dedup)
 *  - replayed rejected uplink after resolution: no recreated incident
 */

use App\Alert\AlertLifecycleService;
use App\Alert\InvalidReading\InvalidReadingAlertService;
use App\Alert\Message\SendTelegramNotification;
use App\Entity\AlertIncident;
use App\Entity\ProcessedUplink;
use App\Kernel;
use App\Mqtt\ChirpStackUplink;
use App\Mqtt\ChirpStackUplinkHandler;
use App\Repository\ProcessedUplinkRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Uid\Uuid;

require '/app/vendor/autoload.php';

const TEST_DATABASE = 'telegram_alerts';

final class InvalidReadingKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-invalid-reading/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-invalid-reading/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('test.messenger.default_bus', 'messenger.default_bus')->setPublic(true);
        $container->setAlias('test.messenger_serializer', 'messenger.default_serializer')->setPublic(true);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function resetDatabase(Connection $connection): void
{
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Refusing to reset schema outside the isolated test Compose file.');
    check(TEST_DATABASE === $connection->getDatabase(), 'Refusing to reset a non-test database.');

    $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
    try {
        $tables = $connection->fetchFirstColumn(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [TEST_DATABASE],
        );
        foreach ($tables as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS '.$connection->quoteIdentifier($table));
        }
    } finally {
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}

function runMigrations(Application $application): void
{
    $output = new BufferedOutput();
    $exitCode = $application->run(new ArrayInput([
        'command' => 'doctrine:migrations:migrate',
        '--no-interaction' => true,
    ]), $output);
    check(0 === $exitCode, 'Migrations failed: '.$output->fetch());
}

/** @return list<SendTelegramNotification> */
function notificationsFor(Connection $connection, SerializerInterface $serializer, string $subjectKey): array
{
    $rows = $connection->fetchAllAssociative(
        "SELECT body, headers FROM messenger_messages WHERE queue_name = 'telegram_notifications' ORDER BY id"
    );

    $messages = [];
    foreach ($rows as $row) {
        $envelope = $serializer->decode(['body' => $row['body'], 'headers' => json_decode($row['headers'], true, flags: JSON_THROW_ON_ERROR)]);
        $message = $envelope->getMessage();
        if ($message instanceof SendTelegramNotification && $subjectKey === $message->subjectKey) {
            $messages[] = $message;
        }
    }

    return $messages;
}

/** @return array<string, mixed>|null */
function invalidReadingIncident(Connection $connection, int $deviceId): ?array
{
    $row = $connection->fetchAssociative(
        "SELECT * FROM alert_incident WHERE alert_type = 'invalid_reading' AND subject_key = ? ORDER BY id DESC LIMIT 1",
        [sprintf('device:%d', $deviceId)],
    );

    return is_array($row) ? $row : null;
}

function deviceId(Connection $connection, string $devEui): int
{
    return (int) $connection->fetchOne('SELECT id FROM device WHERE dev_eui = ?', [$devEui]);
}

function measurementCount(Connection $connection, string $deduplicationId): int
{
    return (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$deduplicationId]);
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');
    $kernel = new InvalidReadingKernel('dev', true);
    $kernel->boot();
    $container = $kernel->getContainer();

    /** @var EntityManagerInterface $entityManager */
    $entityManager = $container->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    resetDatabase($connection);

    $application = new Application($kernel);
    $application->setAutoExit(false);
    runMigrations($application);
    $connection->close();

    /** @var ChirpStackUplinkHandler $handler */
    $handler = $container->get(ChirpStackUplinkHandler::class);
    $serializer = $container->get('test.messenger_serializer');
    $messageBus = $container->get('test.messenger.default_bus');

    $uplink = static function (string $devEui, array $payload, string $measuredAt, ?string $dedupId = null): ChirpStackUplink {
        return new ChirpStackUplink(
            devEui: $devEui,
            payload: $payload,
            measuredAt: new \DateTimeImmutable($measuredAt),
            deduplicationId: $dedupId ?? Uuid::v4()->toRfc4122(),
        );
    };

    // -- Mixed uplink: valid battery + out-of-range soil moisture ---------
    $mixedId = Uuid::v4()->toRfc4122();
    $handler($uplink('mixed-device', ['BatV' => 3.7, 'water_SOIL' => 150.0], '2026-01-01T00:00:00Z', $mixedId));
    check(1 === measurementCount($connection, $mixedId), 'The valid battery field must be persisted despite the invalid sibling.');
    $incident = invalidReadingIncident($connection, deviceId($connection, 'mixed-device'));
    check(null !== $incident && AlertIncident::STATUS_ACTIVE === $incident['status'], 'The mixed uplink must open exactly one ACTIVE invalid_reading incident.');
    check(1 === (int) $incident['confirmation_count'], 'The mixed uplink must count as exactly one breach.');

    // -- Field order swapped: invalid first, valid second -----------------
    $swappedId = Uuid::v4()->toRfc4122();
    $handler($uplink('swapped-device', ['water_SOIL' => 150.0, 'BatV' => 3.7], '2026-01-01T00:00:00Z', $swappedId));
    check(1 === measurementCount($connection, $swappedId), 'The valid field must persist regardless of payload order.');
    $swappedIncident = invalidReadingIncident($connection, deviceId($connection, 'swapped-device'));
    check(null !== $swappedIncident && 1 === (int) $swappedIncident['confirmation_count'], 'Field order must not change the aggregated breach.');
    echo "PASS mixed-and-order: one aggregated breach per uplink, valid siblings persist, field order is irrelevant\n";

    // -- Consecutive valid uplinks: at most one recovery per uplink -------
    $handler($uplink('recovery-device', ['water_SOIL' => 150.0], '2026-01-01T00:00:00Z'));
    $recoveryDevice = deviceId($connection, 'recovery-device');
    $recoverySubject = sprintf('device:%d', $recoveryDevice);

    $handler($uplink('recovery-device', ['BatV' => 3.7, 'water_SOIL' => 45.0], '2026-01-01T00:10:00Z'));
    $row = invalidReadingIncident($connection, $recoveryDevice);
    check(AlertIncident::STATUS_ACTIVE === $row['status'] && 1 === (int) $row['recovery_count'], 'One two-field valid uplink must count as exactly one recovery. Got: '.$row['recovery_count']);

    $handler($uplink('recovery-device', ['BatV' => 3.7, 'water_SOIL' => 45.0], '2026-01-01T00:20:00Z'));
    $row = invalidReadingIncident($connection, $recoveryDevice);
    check(AlertIncident::STATUS_ACTIVE === $row['status'] && 2 === (int) $row['recovery_count'], 'The second valid uplink must advance recovery to 2.');

    $handler($uplink('recovery-device', ['BatV' => 3.7, 'water_SOIL' => 45.0], '2026-01-01T00:30:00Z'));
    $row = invalidReadingIncident($connection, $recoveryDevice);
    check(AlertIncident::STATUS_RESOLVED === $row['status'], 'The third valid uplink must resolve the incident (recovery_count=3).');
    $notifications = notificationsFor($connection, $serializer, $recoverySubject);
    check(2 === count($notifications) && 'resolved' === $notifications[1]->event, 'Resolution must enqueue exactly one "resolved" notification.');
    echo "PASS consecutive-valid-uplinks: each uplink advances recovery at most once; three uplinks resolve\n";

    // -- Duplicate delivery: no extra recovery, no extra measurements -----
    $dupId = Uuid::v4()->toRfc4122();
    $handler($uplink('dup-device', ['BatV' => 3.7, 'water_SOIL' => 45.0], '2026-01-01T00:00:00Z', $dupId));
    $handler($uplink('dup-device', ['BatV' => 3.7, 'water_SOIL' => 45.0], '2026-01-01T00:00:00Z', $dupId));
    check(2 === measurementCount($connection, $dupId), 'A duplicate delivery must not double-store measurements.');
    check(null === invalidReadingIncident($connection, deviceId($connection, 'dup-device')), 'Two valid uplinks (one a duplicate) with no open incident must stay silent.');

    // Duplicate delivery DURING an open incident: recovery must not advance.
    $handler($uplink('dupopen-device', ['water_SOIL' => 150.0], '2026-01-01T00:00:00Z'));
    $dupOpenId = Uuid::v4()->toRfc4122();
    $dupOpenUplink = $uplink('dupopen-device', ['BatV' => 3.7, 'water_SOIL' => 45.0], '2026-01-01T00:10:00Z', $dupOpenId);
    $handler($dupOpenUplink);
    $handler($dupOpenUplink);
    $row = invalidReadingIncident($connection, deviceId($connection, 'dupopen-device'));
    check(1 === (int) $row['recovery_count'], 'A duplicate delivery must not advance recovery. Got: '.$row['recovery_count']);
    echo "PASS duplicate-delivery: replays store nothing extra and never advance recovery\n";

    // -- Empty and unmapped-only payloads: no signal ----------------------
    $handler($uplink('empty-device', [], '2026-01-01T00:00:00Z'));
    $handler($uplink('unmapped-device', ['Node_type' => 'S01', 'Mod' => 1], '2026-01-01T00:00:00Z'));
    check(null === invalidReadingIncident($connection, deviceId($connection, 'empty-device')), 'An empty payload must not emit any signal.');
    check(null === invalidReadingIncident($connection, deviceId($connection, 'unmapped-device')), 'An unmapped-only payload must not emit any signal.');
    echo "PASS empty-and-unmapped: no valid measurement persisted means no signal\n";

    // -- Non-numeric mapped field: breach, not recovery --------------------
    $handler($uplink('nonnum-device', ['BatV' => 3.7, 'water_SOIL' => 45.0], '2026-01-01T00:00:00Z'));
    $handler($uplink('nonnum-device', ['BatV' => 'n/a'], '2026-01-01T00:10:00Z'));
    $row = invalidReadingIncident($connection, deviceId($connection, 'nonnum-device'));
    check(null !== $row && AlertIncident::STATUS_ACTIVE === $row['status'] && 1 === (int) $row['confirmation_count'], 'A non-numeric mapped field must count as a breach, and the uplink must not advance recovery.');
    echo "PASS non-numeric: a mapped non-numeric field is an invalid reading and blocks recovery credit\n";

    // -- Correction-2 regression: A(T) -> B(T) -> replay A ----------------
    $aId = Uuid::v4()->toRfc4122();
    $bId = Uuid::v4()->toRfc4122();
    $handler($uplink('same-ts-device', ['water_SOIL' => 150.0], '2026-01-01T00:00:00Z'));
    $handler($uplink('same-ts-device', ['BatV' => 3.7], '2026-01-01T01:00:00Z', $aId));
    $handler($uplink('same-ts-device', ['BatV' => 3.7], '2026-01-01T01:00:00Z', $bId));
    $row = invalidReadingIncident($connection, deviceId($connection, 'same-ts-device'));
    check(2 === (int) $row['recovery_count'], 'Two distinct uplinks must advance recovery twice. Got: '.$row['recovery_count']);
    $handler($uplink('same-ts-device', ['BatV' => 3.7], '2026-01-01T01:00:00Z', $aId)); // replay A
    $row = invalidReadingIncident($connection, deviceId($connection, 'same-ts-device'));
    check(2 === (int) $row['recovery_count'], 'Replaying uplink A after same-measuredAt B must be a no-op. Got: '.$row['recovery_count']);
    echo "PASS same-measuredat-replay: identity-based dedup skips replay A even after a same-timestamp uplink B\n";

    // -- Replayed rejected uplink after resolution: nothing recreated -----
    $rejectedId = Uuid::v4()->toRfc4122();
    $rejectedUplink = $uplink('reject-device', ['water_SOIL' => 150.0], '2026-01-01T00:00:00Z', $rejectedId);
    $handler($rejectedUplink);
    $handler($rejectedUplink); // replayed invalid uplink: counted once
    $rejectDevice = deviceId($connection, 'reject-device');
    $row = invalidReadingIncident($connection, $rejectDevice);
    check(1 === (int) $row['confirmation_count'], 'A replayed rejected uplink must count as exactly one breach. Got: '.$row['confirmation_count']);

    for ($i = 1; $i <= 3; ++$i) {
        $handler($uplink('reject-device', ['BatV' => 3.7, 'water_SOIL' => 45.0], sprintf('2026-01-01T00:%02d:00Z', $i * 10)));
    }
    $row = invalidReadingIncident($connection, $rejectDevice);
    check(AlertIncident::STATUS_RESOLVED === $row['status'], 'Three valid uplinks must resolve the incident.');
    $notificationCount = count(notificationsFor($connection, $serializer, sprintf('device:%d', $rejectDevice)));

    $handler($rejectedUplink); // old rejected uplink replayed after resolution
    $row = invalidReadingIncident($connection, $rejectDevice);
    check(AlertIncident::STATUS_RESOLVED === $row['status'], 'Replaying the old rejected uplink must not recreate the incident.');
    check(1 === (int) $connection->fetchOne("SELECT COUNT(*) FROM alert_incident WHERE alert_type = 'invalid_reading' AND subject_key = ?", [sprintf('device:%d', $rejectDevice)]), 'No new incident row may be created.');
    check($notificationCount === count(notificationsFor($connection, $serializer, sprintf('device:%d', $rejectDevice))), 'No new notification may be enqueued.');
    echo "PASS rejected-replay-after-resolution: replayed invalid uplinks cannot recreate a resolved incident\n";

    echo "PASS invalid-reading aggregation: one signal per uplink, replays are inert, valid siblings persist\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
