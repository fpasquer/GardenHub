<?php

declare(strict_types=1);

/*
 * AlertLifecycleService + EvaluateAlertsHandler lifecycle coverage, against
 * a real MySQL schema built by Doctrine Migrations (never SchemaTool): the
 * alert_incident table's generated is_open column/unique index and the
 * messenger_messages table backing the telegram_notifications transport
 * only exist when built that way. Assertions read dispatched notifications
 * straight out of messenger_messages, proving the enqueue really commits
 * atomically with the incident write (no sync routing override involved).
 *
 * Not covered here (by design): RecordProcessingFailureAlertHandler and
 * ChirpStackUplinkHandler's auto-resolve call are thin wrappers around
 * openOrAdvance(), already exercised generically below.
 */

use App\Alert\AlertLifecycleService;
use App\Alert\AlertSignal;
use App\Alert\Message\EvaluateAlertsHandler;
use App\Alert\Message\EvaluateAlertsMessage;
use App\Alert\Message\SendTelegramNotification;
use App\Entity\AlertIncident;
use App\Kernel;
use App\Repository\AlertIncidentRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

require '/app/vendor/autoload.php';

const TEST_DATABASE = 'telegram_alerts';
const ALERT_TYPE = 'lifecycle_test';

final class AlertLifecycleKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-alert-lifecycle/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-alert-lifecycle/log';
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

function freshIncident(AlertIncidentRepository $repository, string $subjectKey): ?AlertIncident
{
    return $repository->findOpenIncident(ALERT_TYPE, $subjectKey);
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

function scenarioImmediateActivation(AlertIncidentRepository $repository, EntityManagerInterface $em, MessageBusInterface $bus, Connection $connection, SerializerInterface $serializer): void
{
    $clock = new MockClock('2026-01-01T00:00:00Z');
    $service = new AlertLifecycleService($repository, $em, $bus, $clock, reminderIntervalSeconds: 3600);

    $service->openOrAdvance(ALERT_TYPE, 'subject-immediate', new AlertSignal(breach: true, value: 12.5, unit: '%', measurementId: 1), confirmationThreshold: 1, recoveryThreshold: 1);

    $incident = freshIncident($repository, 'subject-immediate');
    check(null !== $incident, 'An incident must be created on the first breach.');
    check(AlertIncident::STATUS_ACTIVE === $incident->getStatus(), 'A confirmationThreshold of 1 must activate immediately.');
    check(1 === $incident->getConfirmationCount(), 'Confirmation count must be 1 after the first signal.');

    $notifications = notificationsFor($connection, $serializer, 'subject-immediate');
    check(1 === count($notifications), 'Exactly one notification must be enqueued for the immediate activation.');
    check('opened' === $notifications[0]->event, 'The enqueued notification must report the "opened" event.');
    check(ALERT_TYPE === $notifications[0]->alertType, 'The enqueued notification must carry the alert type.');

    echo "PASS immediate-activation: confirmationThreshold=1 opens and activates on the first breach, enqueuing exactly one transactional notification\n";
}

function scenarioStagedConfirmation(AlertIncidentRepository $repository, EntityManagerInterface $em, MessageBusInterface $bus, Connection $connection, SerializerInterface $serializer): void
{
    $clock = new MockClock('2026-01-01T00:00:00Z');
    $service = new AlertLifecycleService($repository, $em, $bus, $clock, reminderIntervalSeconds: 3600);
    $subjectKey = 'subject-staged';

    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 10), confirmationThreshold: 3, recoveryThreshold: 1);
    $incident = freshIncident($repository, $subjectKey);
    check(AlertIncident::STATUS_PENDING === $incident->getStatus(), 'The first of three signals must leave the incident PENDING.');
    check([] === notificationsFor($connection, $serializer, $subjectKey), 'No notification must be sent before the confirmation threshold is reached.');

    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 11), confirmationThreshold: 3, recoveryThreshold: 1);
    $em->refresh($incident);
    check(AlertIncident::STATUS_PENDING === $incident->getStatus(), 'The second of three signals must still leave the incident PENDING.');
    check(2 === $incident->getConfirmationCount(), 'Confirmation count must be 2 after the second signal.');
    check([] === notificationsFor($connection, $serializer, $subjectKey), 'No notification must be sent before the confirmation threshold is reached.');

    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 12), confirmationThreshold: 3, recoveryThreshold: 1);
    $em->refresh($incident);
    check(AlertIncident::STATUS_ACTIVE === $incident->getStatus(), 'The third signal must activate the incident.');
    $notifications = notificationsFor($connection, $serializer, $subjectKey);
    check(1 === count($notifications) && 'opened' === $notifications[0]->event, 'Exactly one "opened" notification must be sent once the threshold is reached.');

    echo "PASS staged-confirmation: confirmationThreshold=3 stays silent for two signals then activates and notifies on the third\n";
}

function scenarioIdempotentReplay(AlertIncidentRepository $repository, EntityManagerInterface $em, MessageBusInterface $bus, Connection $connection, SerializerInterface $serializer): void
{
    $clock = new MockClock('2026-01-01T00:00:00Z');
    $service = new AlertLifecycleService($repository, $em, $bus, $clock, reminderIntervalSeconds: 3600);
    $subjectKey = 'subject-replay';

    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 20), confirmationThreshold: 2, recoveryThreshold: 1);
    $incident = freshIncident($repository, $subjectKey);
    check(1 === $incident->getConfirmationCount(), 'The first signal must set confirmation count to 1.');

    // Replay the exact same measurementId: must be a no-op, not double-counted.
    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 20), confirmationThreshold: 2, recoveryThreshold: 1);
    $em->refresh($incident);
    check(1 === $incident->getConfirmationCount(), 'Replaying the same measurementId must not advance the confirmation count.');
    check(AlertIncident::STATUS_PENDING === $incident->getStatus(), 'A replayed signal must not cause activation.');
    check([] === notificationsFor($connection, $serializer, $subjectKey), 'A replayed signal must never enqueue a notification.');

    // A genuinely new measurement must still advance normally afterwards.
    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 21), confirmationThreshold: 2, recoveryThreshold: 1);
    $em->refresh($incident);
    check(AlertIncident::STATUS_ACTIVE === $incident->getStatus(), 'A genuinely new signal after a replay must still activate at the threshold.');
    check(1 === count(notificationsFor($connection, $serializer, $subjectKey)), 'Exactly one notification must be enqueued once the new signal reaches the threshold.');

    echo "PASS idempotent-replay: replaying the same measurementId is a no-op; a genuinely new one still advances normally\n";
}

function scenarioEarlyRecovery(AlertIncidentRepository $repository, EntityManagerInterface $em, MessageBusInterface $bus, Connection $connection, SerializerInterface $serializer): void
{
    $clock = new MockClock('2026-01-01T00:00:00Z');
    $service = new AlertLifecycleService($repository, $em, $bus, $clock, reminderIntervalSeconds: 3600);
    $subjectKey = 'subject-early-recovery';

    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 30), confirmationThreshold: 3, recoveryThreshold: 2);
    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: false, measurementId: 31), confirmationThreshold: 3, recoveryThreshold: 2);

    $incident = freshIncident($repository, $subjectKey);
    check(null === $incident, 'A recovery before the confirmation threshold must resolve the incident (no longer "open").');

    check([] === notificationsFor($connection, $serializer, $subjectKey), 'Recovering before confirmation was reached must be silent (no notification).');

    echo "PASS early-recovery: a recovery signal arriving before the confirmation threshold silently resolves the incident\n";
}

function scenarioRecoveryAfterActive(AlertIncidentRepository $repository, EntityManagerInterface $em, MessageBusInterface $bus, Connection $connection, SerializerInterface $serializer): void
{
    $clock = new MockClock('2026-01-01T00:00:00Z');
    $service = new AlertLifecycleService($repository, $em, $bus, $clock, reminderIntervalSeconds: 3600);
    $subjectKey = 'subject-recovery';

    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 40), confirmationThreshold: 1, recoveryThreshold: 2);
    $incident = freshIncident($repository, $subjectKey);
    check(AlertIncident::STATUS_ACTIVE === $incident->getStatus(), 'The incident must be ACTIVE before recovery signals arrive.');

    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: false, measurementId: 41), confirmationThreshold: 1, recoveryThreshold: 2);
    $em->refresh($incident);
    check(AlertIncident::STATUS_ACTIVE === $incident->getStatus(), 'The first of two recovery signals must not resolve the incident yet.');
    check(1 === count(notificationsFor($connection, $serializer, $subjectKey)), 'Only the original "opened" notification must exist so far.');

    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: false, measurementId: 42), confirmationThreshold: 1, recoveryThreshold: 2);
    check(null === freshIncident($repository, $subjectKey), 'The second recovery signal must resolve the incident.');
    $notifications = notificationsFor($connection, $serializer, $subjectKey);
    check(2 === count($notifications), 'Resolution must add exactly one more notification.');
    check('resolved' === $notifications[1]->event, 'The second notification must report the "resolved" event.');

    echo "PASS recovery-after-active: recoveryThreshold=2 stays ACTIVE for one recovery signal then resolves and notifies on the second\n";
}

function scenarioReminderScheduling(AlertIncidentRepository $repository, EntityManagerInterface $em, MessageBusInterface $bus, Connection $connection, SerializerInterface $serializer): void
{
    $openClock = new MockClock('2026-01-01T00:00:00Z');
    // Different reminderIntervalSeconds per incident so their next_reminder_at
    // values genuinely diverge: 3600s (due at 01:00:00) vs. 7200s (due at
    // 02:00:00), evaluated from a clock that is past only the first one.
    $dueService = new AlertLifecycleService($repository, $em, $bus, $openClock, reminderIntervalSeconds: 3600);
    $notDueService = new AlertLifecycleService($repository, $em, $bus, $openClock, reminderIntervalSeconds: 7200);

    $dueService->openOrAdvance(ALERT_TYPE, 'subject-reminder-due', new AlertSignal(breach: true, measurementId: 60), confirmationThreshold: 1, recoveryThreshold: 1);
    $notDueService->openOrAdvance(ALERT_TYPE, 'subject-reminder-not-due', new AlertSignal(breach: true, measurementId: 70), confirmationThreshold: 1, recoveryThreshold: 1);

    $dueIncident = freshIncident($repository, 'subject-reminder-due');
    $notDueIncident = freshIncident($repository, 'subject-reminder-not-due');
    check(null !== $dueIncident && null !== $notDueIncident, 'Both reminder-scenario incidents must be created and activated.');
    $originalNextReminderAt = $notDueIncident->getNextReminderAt();

    // One second past the due incident's next_reminder_at, but well before the other's.
    $evalClock = new MockClock('2026-01-01T01:00:01Z');
    $handler = new EvaluateAlertsHandler($repository, $em, $bus, $evalClock, reminderIntervalSeconds: 3600);
    $handler(new EvaluateAlertsMessage());

    $em->refresh($dueIncident);
    $em->refresh($notDueIncident);

    $dueNotifications = notificationsFor($connection, $serializer, 'subject-reminder-due');
    check(2 === count($dueNotifications), 'A due incident must gain exactly one reminder on top of its original "opened" notification.');
    check('reminder' === $dueNotifications[1]->event, 'The second notification for the due incident must be a "reminder".');
    check($dueIncident->getNextReminderAt() > $evalClock->now(), 'A reminded incident must be rescheduled into the future.');

    check([] === array_diff(
        array_map(static fn (SendTelegramNotification $n): string => $n->event, notificationsFor($connection, $serializer, 'subject-reminder-not-due')),
        ['opened'],
    ), 'An incident whose reminder is not yet due must not receive a reminder notification.');
    check($originalNextReminderAt->getTimestamp() === $notDueIncident->getNextReminderAt()->getTimestamp(), 'An incident whose reminder is not yet due must keep its original schedule.');

    echo "PASS reminder-scheduling: EvaluateAlertsHandler reminds only incidents whose next_reminder_at is due, and reschedules them\n";
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');
    $kernel = new AlertLifecycleKernel('dev', true);
    $kernel->boot();
    $container = $kernel->getContainer();

    /** @var EntityManagerInterface $entityManager */
    $entityManager = $container->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    resetDatabase($connection);

    $application = new Application($kernel);
    $application->setAutoExit(false);
    runMigrations($application);
    // The migration's transactional DDL implicitly commits underneath DBAL,
    // leaving the shared connection's transaction-nesting tracking
    // inconsistent. Reconnect before using it for the actual scenarios.
    $connection->close();

    // Fetched via the EntityManager's own repository factory, not the DI
    // container: repositories are not public services in this app.
    $incidentRepository = $entityManager->getRepository(AlertIncident::class);
    $messageBus = $container->get('test.messenger.default_bus');
    $serializer = $container->get('test.messenger_serializer');

    scenarioImmediateActivation($incidentRepository, $entityManager, $messageBus, $connection, $serializer);
    scenarioStagedConfirmation($incidentRepository, $entityManager, $messageBus, $connection, $serializer);
    scenarioIdempotentReplay($incidentRepository, $entityManager, $messageBus, $connection, $serializer);
    scenarioEarlyRecovery($incidentRepository, $entityManager, $messageBus, $connection, $serializer);
    scenarioRecoveryAfterActive($incidentRepository, $entityManager, $messageBus, $connection, $serializer);
    scenarioReminderScheduling($incidentRepository, $entityManager, $messageBus, $connection, $serializer);

    echo "PASS alert lifecycle: activation staging, idempotent replay, early/threshold recovery, reminder scheduling\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
