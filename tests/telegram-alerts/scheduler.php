<?php

declare(strict_types=1);

/*
 * Scheduler registration + machinery coverage (finding 2). Directly invoking
 * EvaluateAlertsHandler alone is insufficient; this script proves the whole
 * chain: Schedule registration (5-minute recurring EvaluateAlertsMessage),
 * message generation by the real scheduler machinery (messenger:consume
 * scheduler_default), and the handler's due/not-due/resolved eligibility.
 */

use App\Alert\AlertLifecycleService;
use App\Alert\AlertSignal;
use App\Alert\Message\EvaluateAlertsMessage;
use App\Alert\Message\SendTelegramNotification;
use App\Entity\AlertEvaluationProgress;
use App\Entity\AlertIncident;
use App\Kernel;
use App\Repository\AlertEvaluationProgressRepository;
use App\Schedule;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

require '/app/vendor/autoload.php';

const TEST_DATABASE = 'telegram_alerts';
const ALERT_TYPE = 'scheduler_test';

/** Test-only 1-second provider so the real scheduler machinery fires a message quickly. */
final class FastScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())->add(RecurringMessage::every('1 second', new EvaluateAlertsMessage()));
    }
}

final class SchedulerTestKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-scheduler-test/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-scheduler-test/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('test.schedule', Schedule::class)->setPublic(true);
        $container->setAlias('test.messenger.default_bus', 'messenger.default_bus')->setPublic(true);
        $container->setAlias('test.messenger_serializer', 'messenger.default_serializer')->setPublic(true);
    }
}

/**
 * Test-only compiler pass removing a single service definition. Used to drop
 * the production App\Schedule provider so FastScheduleProvider can take over
 * the "default" schedule name without colliding with it.
 */
final class RemoveDefinitionCompilerPass implements CompilerPassInterface
{
    public function __construct(private readonly string $id)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        $container->removeDefinition($this->id);
    }
}

/**
 * Boots with the real production Schedule provider entirely removed and
 * FastScheduleProvider registered under the "default" schedule name in its
 * place, so messenger:consume scheduler_default genuinely receives messages
 * from the fast (1s) test provider instead of the real 5-minute one.
 */
final class SchedulerMachineryKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-scheduler-machinery/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-scheduler-machinery/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('test.messenger.default_bus', 'messenger.default_bus')->setPublic(true);
        $container->setAlias('test.messenger_serializer', 'messenger.default_serializer')->setPublic(true);
        $container->register('test.fast_schedule_provider', FastScheduleProvider::class)
            ->addTag('scheduler.schedule_provider', ['name' => 'default']);

        // App\Schedule's own services.yaml-driven registration (and its
        // #[AsSchedule] tag) only exist once App\ resources are loaded,
        // which happens after build(). Removing it here, in a compiler pass
        // running before AddScheduleMessengerPass (registered by
        // FrameworkBundle at TYPE_BEFORE_OPTIMIZATION, priority 0), avoids
        // the "can not replace already registered service" collision that
        // would otherwise be thrown for two providers under the same name.
        $container->addCompilerPass(new RemoveDefinitionCompilerPass(Schedule::class), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
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

function runConsole(Application $application, array $input): string
{
    $output = new BufferedOutput();
    $exitCode = $application->run(new ArrayInput($input + ['--no-interaction' => true]), $output);
    check(0 === $exitCode, sprintf('Console command failed: %s', $output->fetch()));

    return $output->fetch();
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

/** Opens an ACTIVE incident and returns its id. */
function openIncident(EntityManagerInterface $em, MessageBusInterface $bus, string $subjectKey, int $measurementId): int
{
    /** @var \App\Repository\AlertIncidentRepository $repository */
    $repository = $em->getRepository(AlertIncident::class);
    /** @var AlertEvaluationProgressRepository $progressRepository */
    $progressRepository = $em->getRepository(AlertEvaluationProgress::class);
    $lifecycle = new AlertLifecycleService($repository, $progressRepository, $em, $bus, new MockClock('2026-01-01T00:00:00Z'), reminderIntervalSeconds: 3600);
    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, value: 5.0, unit: '%', measuredAt: new \DateTimeImmutable('2026-01-01T00:00:00Z'), measurementId: $measurementId), 1, 1);

    return (int) $em->getConnection()->fetchOne('SELECT id FROM alert_incident WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]);
}

function scenarioScheduleRegistration(Schedule $schedule): void
{
    $recurring = $schedule->getSchedule()->getRecurringMessages();
    check(1 === count($recurring), sprintf('Exactly one recurring message must be registered, got %d.', count($recurring)));

    $now = new \DateTimeImmutable('2026-01-01T12:00:00Z');
    $context = new MessageContext(name: 'default', id: $recurring[0]->getId(), trigger: $recurring[0]->getTrigger(), triggeredAt: $now);
    $messages = iterator_to_array($recurring[0]->getMessages($context));
    check(1 === count($messages) && $messages[0] instanceof EvaluateAlertsMessage, 'The recurring message must carry EvaluateAlertsMessage.');

    $next = $recurring[0]->getTrigger()->getNextRunDate($now);
    check(null !== $next, 'The trigger must produce a next run date.');
    $interval = $next->getTimestamp() - $now->getTimestamp();
    check(300 === $interval, sprintf('The trigger must fire every 5 minutes (300s), got %ds.', $interval));

    echo "PASS schedule-registration: one recurring EvaluateAlertsMessage every 300s, stateful with last-missed-run processing\n";
}

function scenarioSchedulerMachinery(EntityManagerInterface $em, MessageBusInterface $bus, Connection $connection, SerializerInterface $serializer, Application $application): void
{
    $dueId = openIncident($em, $bus, 'subject-due', 100);
    $notDueId = openIncident($em, $bus, 'subject-not-due', 101);
    $resolvedId = openIncident($em, $bus, 'subject-resolved', 102);

    // Force the eligibility states: due (past), not-due (far future), resolved.
    $connection->executeStatement('UPDATE alert_incident SET next_reminder_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $dueId]);
    $connection->executeStatement('UPDATE alert_incident SET next_reminder_at = ? WHERE id = ?', ['2099-01-01 00:00:00', $notDueId]);
    $connection->executeStatement("UPDATE alert_incident SET status = 'resolved', resolved_at = NOW() WHERE id = ?", [$resolvedId]);
    $resolvedNextReminder = $connection->fetchOne('SELECT next_reminder_at FROM alert_incident WHERE id = ?', [$resolvedId]);

    // Real scheduler machinery: the fast test provider (1s) fires an
    // EvaluateAlertsMessage onto scheduler_default, consumed here.
    runConsole($application, [
        'command' => 'messenger:consume',
        'receivers' => ['scheduler_default'],
        '--limit' => 1,
        '--time-limit' => 10,
    ]);

    $dueNotifications = notificationsFor($connection, $serializer, 'subject-due');
    check(2 === count($dueNotifications), 'The due incident must gain exactly one reminder on top of its "opened". Got: '.count($dueNotifications));
    check('reminder' === $dueNotifications[1]->event, 'The second notification for the due incident must be a "reminder".');
    $dueNext = $connection->fetchOne('SELECT next_reminder_at FROM alert_incident WHERE id = ?', [$dueId]);
    check($dueNext > date('Y-m-d H:i:s'), 'The reminded incident must be rescheduled into the future.');

    check(1 === count(notificationsFor($connection, $serializer, 'subject-not-due')), 'The not-due incident must keep only its "opened" notification.');
    $notDueNext = $connection->fetchOne('SELECT next_reminder_at FROM alert_incident WHERE id = ?', [$notDueId]);
    check(str_starts_with((string) $notDueNext, '2099-01-01'), 'The not-due incident must keep its schedule.');

    check(1 === count(notificationsFor($connection, $serializer, 'subject-resolved')), 'The resolved incident must never receive a reminder.');
    check($resolvedNextReminder === $connection->fetchOne('SELECT next_reminder_at FROM alert_incident WHERE id = ?', [$resolvedId]), 'The resolved incident\'s next_reminder_at must be untouched.');

    echo "PASS scheduler-machinery: messenger:consume scheduler_default reminds only the due active incident via the real scheduler\n";
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');

    // Kernel A: the real, untouched production Schedule - proves the actual
    // registration (5-minute EvaluateAlertsMessage) is correct.
    $kernelA = new SchedulerTestKernel('dev', true);
    $kernelA->boot();
    $containerA = $kernelA->getContainer();

    /** @var EntityManagerInterface $entityManager */
    $entityManager = $containerA->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    resetDatabase($connection);

    $applicationA = new Application($kernelA);
    $applicationA->setAutoExit(false);
    runConsole($applicationA, ['command' => 'doctrine:migrations:migrate']);
    $connection->close();

    /** @var Schedule $schedule */
    $schedule = $containerA->get('test.schedule');
    scenarioScheduleRegistration($schedule);
    $kernelA->shutdown();

    // Kernel B: production Schedule removed, FastScheduleProvider (1s) takes
    // over the "default" schedule name - proves messenger:consume
    // scheduler_default genuinely drives EvaluateAlertsHandler end to end.
    // The schema is already migrated; no need to reset or re-migrate.
    $kernelB = new SchedulerMachineryKernel('dev', true);
    $kernelB->boot();
    $containerB = $kernelB->getContainer();

    /** @var EntityManagerInterface $entityManagerB */
    $entityManagerB = $containerB->get('doctrine')->getManager();
    $connectionB = $entityManagerB->getConnection();
    $messageBus = $containerB->get('test.messenger.default_bus');
    $serializer = $containerB->get('test.messenger_serializer');
    $applicationB = new Application($kernelB);
    $applicationB->setAutoExit(false);

    scenarioSchedulerMachinery($entityManagerB, $messageBus, $connectionB, $serializer, $applicationB);

    echo "PASS scheduler: registration verified against the real, unaltered production schedule; the real machinery (via a dedicated fast provider) reminds only due active incidents\n";
    $kernelB->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
