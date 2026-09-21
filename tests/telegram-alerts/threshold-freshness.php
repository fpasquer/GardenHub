<?php

declare(strict_types=1);

/*
 * Measurement freshness gate for ThresholdRuleEvaluator (finding 5), with a
 * deterministic MockClock and a real MySQL schema.
 *
 * Boundary contract (max_measurement_age_seconds = M): age > M is stale and
 * skipped; age == M is still fresh (inclusive); future-dated readings
 * (measured_at > now) are excluded from alert evaluation entirely so they
 * cannot advance the watermark into the future. Storage is never affected.
 */

use App\Alert\AlertLifecycleService;
use App\Alert\Message\SendTelegramNotification;
use App\Alert\Rule\ThresholdRuleEvaluator;
use App\Alert\Rule\ThresholdRuleProvider;
use App\Entity\AlertEvaluationProgress;
use App\Entity\AlertIncident;
use App\Entity\Device;
use App\Entity\Measurement;
use App\Entity\Sensor;
use App\Kernel;
use App\Repository\AlertEvaluationProgressRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
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
const RULE_MAX_AGE = 7200; // 2 hours
const RULE_ALERT_TYPE = 'soil_too_dry';

final class ThresholdFreshnessKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-threshold-freshness/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-threshold-freshness/log';
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

/** Persists one soil_moisture measurement and returns it (with id). */
function persistMeasurement(EntityManagerInterface $em, Sensor $sensor, float $value, string $measuredAt): Measurement
{
    $measurement = (new Measurement())
        ->setSensor($sensor)
        ->setValue($value)
        ->setMeasuredAt(new \DateTimeImmutable($measuredAt))
        ->setDeduplicationId(Uuid::v4()->toRfc4122())
        ->setType('soil_moisture');
    $em->persist($measurement);
    $em->flush();

    return $measurement;
}

/** Builds the evaluator under test: one enabled soil_moisture rule, confirmation 2 / recovery 2, max age 2h. */
function evaluator(EntityManagerInterface $em, MessageBusInterface $bus, MockClock $clock): ThresholdRuleEvaluator
{
    $provider = new ThresholdRuleProvider(
        defaults: [
            RULE_ALERT_TYPE => [
                'enabled' => true,
                'measurement_type' => 'soil_moisture',
                'comparison' => 'below',
                'threshold' => 20.0,
                'confirmation_count' => 2,
                'recovery_count' => 2,
                'max_measurement_age_seconds' => RULE_MAX_AGE,
            ],
        ],
        overrides: [],
    );
    /** @var \App\Repository\AlertIncidentRepository $repository */
    $repository = $em->getRepository(AlertIncident::class);
    /** @var AlertEvaluationProgressRepository $progressRepository */
    $progressRepository = $em->getRepository(AlertEvaluationProgress::class);
    $lifecycle = new AlertLifecycleService($repository, $progressRepository, $em, $bus, $clock, reminderIntervalSeconds: 3600);

    return new ThresholdRuleEvaluator($provider, $lifecycle, $clock, new NullLogger());
}

/** @return array<string, mixed>|null */
function incidentRow(Connection $connection, int $sensorId): ?array
{
    $row = $connection->fetchAssociative(
        'SELECT * FROM alert_incident WHERE alert_type = ? AND subject_key = ? ORDER BY id DESC LIMIT 1',
        [RULE_ALERT_TYPE, sprintf('sensor:%d', $sensorId)],
    );

    return is_array($row) ? $row : null;
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');
    $kernel = new ThresholdFreshnessKernel('dev', true);
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

    $messageBus = $container->get('test.messenger.default_bus');
    $serializer = $container->get('test.messenger_serializer');

    $device = (new Device())->setName('Freshness device')->setDevEui('fresh-dev-1');
    $entityManager->persist($device);
    $sensor = (new Sensor())->setDevice($device)->setType('soil_moisture')->setUnit('%')->setLabel('water_SOIL');
    $entityManager->persist($sensor);
    $entityManager->flush();
    $sensorId = $sensor->getId();
    $subjectKey = sprintf('sensor:%d', $sensorId);

    // Evaluation clock sits at noon; the rule accepts measurements up to 2h old.
    $clock = new MockClock('2026-01-01T12:00:00Z');
    $evaluator = evaluator($entityManager, $messageBus, $clock);

    // -- Fresh (age M-1) then boundary (age == M) breaches -----------------
    $evaluator->evaluateMeasurement(persistMeasurement($entityManager, $sensor, 10.0, '2026-01-01T10:00:01Z')); // age 7199
    $row = incidentRow($connection, $sensorId);
    check(null !== $row && 1 === (int) $row['confirmation_count'], 'A fresh breach (age M-1) must be applied.');

    $evaluator->evaluateMeasurement(persistMeasurement($entityManager, $sensor, 10.0, '2026-01-01T10:00:00Z')); // age exactly 7200
    $row = incidentRow($connection, $sensorId);
    check(2 === (int) $row['confirmation_count'] && AlertIncident::STATUS_ACTIVE === $row['status'], 'A boundary-age breach (age == M) must still be fresh and reach the threshold.');
    check(1 === count(notificationsFor($connection, $serializer, $subjectKey)), 'Activation must enqueue exactly one "opened" notification.');
    echo "PASS fresh-and-boundary: age M-1 applies, age == M is still fresh and activates the incident\n";

    // -- Expired breach (age M+1): no state change -------------------------
    $before = incidentRow($connection, $sensorId);
    $expired = persistMeasurement($entityManager, $sensor, 5.0, '2026-01-01T09:59:59Z'); // age 7201
    $evaluator->evaluateMeasurement($expired);
    $row = incidentRow($connection, $sensorId);
    check($before['confirmation_count'] === $row['confirmation_count'] && $before['status'] === $row['status'], 'An expired breach must not change the incident.');
    check(null !== $expired->getId(), 'The expired measurement must still be stored.');
    $progress = $connection->fetchAssociative('SELECT * FROM alert_evaluation_progress WHERE alert_type = ? AND subject_key = ?', [RULE_ALERT_TYPE, $subjectKey]);
    check((int) $progress['last_considered_measurement_id'] < $expired->getId(), 'An expired measurement must not advance evaluation progress.');
    echo "PASS expired: an age M+1 measurement is stored but never evaluated; progress untouched\n";

    // -- Future-dated measurement: excluded from evaluation -----------------
    $before = incidentRow($connection, $sensorId);
    $future = persistMeasurement($entityManager, $sensor, 1.0, '2026-01-01T12:00:01Z'); // measuredAt > now
    $evaluator->evaluateMeasurement($future);
    $row = incidentRow($connection, $sensorId);
    check($before['confirmation_count'] === $row['confirmation_count'] && AlertIncident::STATUS_ACTIVE === $row['status'], 'A future-dated measurement must be excluded from evaluation.');
    $progress = $connection->fetchAssociative('SELECT * FROM alert_evaluation_progress WHERE alert_type = ? AND subject_key = ?', [RULE_ALERT_TYPE, $subjectKey]);
    check((int) $progress['last_considered_measurement_id'] < $future->getId(), 'A future-dated measurement must not advance evaluation progress.');

    // A subsequent real reading at "now" must still evaluate (not suppressed).
    $evaluator->evaluateMeasurement(persistMeasurement($entityManager, $sensor, 50.0, '2026-01-01T12:00:00Z')); // fresh recovery, age 0
    $row = incidentRow($connection, $sensorId);
    check(1 === (int) $row['recovery_count'] && AlertIncident::STATUS_ACTIVE === $row['status'], 'A fresh recovery after a skipped future-dated reading must still evaluate.');
    echo "PASS future-dated: skipped without advancing the watermark; a subsequent real reading still evaluates\n";

    // -- Expired recovery: no effect; fresh recovery resolves --------------
    $evaluator->evaluateMeasurement(persistMeasurement($entityManager, $sensor, 50.0, '2026-01-01T09:00:00Z')); // age 10800, stale
    $row = incidentRow($connection, $sensorId);
    check(1 === (int) $row['recovery_count'] && AlertIncident::STATUS_ACTIVE === $row['status'], 'An expired recovery must not advance recovery.');

    $evaluator->evaluateMeasurement(persistMeasurement($entityManager, $sensor, 50.0, '2026-01-01T12:00:00Z')); // fresh recovery #2
    $row = incidentRow($connection, $sensorId);
    check(AlertIncident::STATUS_RESOLVED === $row['status'], 'The second fresh recovery must resolve the incident.');
    $notifications = notificationsFor($connection, $serializer, $subjectKey);
    check(2 === count($notifications) && 'resolved' === $notifications[1]->event, 'Resolution must enqueue the "resolved" notification.');
    echo "PASS expired-vs-fresh-recovery: stale recovery is ignored; fresh recovery resolves and notifies\n";

    // -- Delayed queue: older readings cannot supersede newer evaluations ---
    $device2 = (new Device())->setName('Delay device')->setDevEui('fresh-dev-2');
    $entityManager->persist($device2);
    $sensor2 = (new Sensor())->setDevice($device2)->setType('soil_moisture')->setUnit('%')->setLabel('water_SOIL');
    $entityManager->persist($sensor2);
    $entityManager->flush();
    $sensor2Id = $sensor2->getId();

    $newer = persistMeasurement($entityManager, $sensor2, 50.0, '2026-01-01T12:00:00Z');
    $olderDelayed = persistMeasurement($entityManager, $sensor2, 5.0, '2026-01-01T11:00:00Z'); // older measuredAt, higher id

    $evaluator->evaluateMeasurement($newer); // fresh, healthy: watermark -> 12:00
    $evaluator->evaluateMeasurement($olderDelayed); // delayed breach: watermark (12:00) rejects it
    check(null === incidentRow($connection, $sensor2Id), 'An older-measured breach arriving after a newer healthy evaluation must not open an incident.');

    // A genuinely newer fresh breach still evaluates normally afterwards.
    $evaluator->evaluateMeasurement(persistMeasurement($entityManager, $sensor2, 5.0, '2026-01-01T12:00:00Z'));
    $evaluator->evaluateMeasurement(persistMeasurement($entityManager, $sensor2, 5.0, '2026-01-01T12:00:00Z'));
    $row = incidentRow($connection, $sensor2Id);
    check(null !== $row && AlertIncident::STATUS_ACTIVE === $row['status'], 'Newer fresh breaches must still open the incident after the delayed one was dropped.');
    echo "PASS delayed-queue: the watermark stops older queued messages from superseding newer evaluated readings\n";

    echo "PASS threshold freshness: stale and future-dated measurements never open or resolve incidents; storage intact\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
